<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Notifications\PaymentDetailsChanged;
use App\Domain\Billing\Support\BillingRecipients;
use App\Domain\Billing\Support\BillingSettings;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin → Settings → Billing. At least one way to pay stays on; online payment needs the gateway
 * keys in .env. Every platform admin is emailed when the UPI ID, QR image or bank details change.
 */
class UpdateBillingSettings
{
    public const UPI_PATTERN = '/^[A-Za-z0-9._-]{2,256}@[A-Za-z]{2,64}$/';

    private const QR_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PAYMENT_DETAILS = ['upi.id' => 'UPI ID', 'upi.payee' => 'UPI payee name', 'bank.account_name' => 'account name', 'bank.account_number' => 'account number', 'bank.ifsc' => 'IFSC', 'bank.bank_name' => 'bank name'];

    public function __construct(
        private readonly BillingSettings $settings,
        private readonly PaymentGateways $gateways,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(array $input, User $admin): void
    {
        $input['gst']['gstin'] = isset($input['gst']['gstin']) ? strtoupper(trim((string) $input['gst']['gstin'])) ?: null : null;
        $input['bank']['ifsc'] = isset($input['bank']['ifsc']) ? strtoupper(trim((string) $input['bank']['ifsc'])) ?: null : null;

        $data = Validator::make($input, [
            'enforce' => ['required', 'boolean'],
            'manual_enabled' => ['required', 'boolean'],
            'online_enabled' => ['required', 'boolean'],
            'seller.name' => ['required', 'string', 'max:120'],
            'seller.address' => ['nullable', 'string', 'max:500'],
            'seller.email' => ['nullable', 'email', 'max:190'],
            'seller.phone' => ['nullable', 'string', 'max:30'],
            'gst.enabled' => ['required', 'boolean'],
            'gst.gstin' => ['nullable', 'required_if:gst.enabled,true', 'regex:'.ManagePayments::GSTIN_PATTERN],
            'upi.id' => ['nullable', 'string', 'regex:'.self::UPI_PATTERN],
            'upi.payee' => ['nullable', 'string', 'max:100'],
            'bank.account_name' => ['nullable', 'string', 'max:120'],
            'bank.account_number' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'bank.ifsc' => ['nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank.bank_name' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ], [
            'gst.gstin.required_if' => __('Enter your GSTIN to switch GST on.'),
            'gst.gstin.regex' => __('Enter a valid 15-character GSTIN.'),
            'upi.id.regex' => __('Enter a UPI ID like name@bank.'),
            'bank.account_number.regex' => __('The account number should be 6 to 20 digits.'),
            'bank.ifsc.regex' => __('Enter an 11-character IFSC like SBIN0001234.'),
        ])->validate();

        $data = array_replace_recursive(['seller' => [], 'gst' => [], 'upi' => [], 'bank' => []], $data);

        if (! $data['manual_enabled'] && ! $data['online_enabled']) {
            throw ValidationException::withMessages(['manual_enabled' => __('Keep at least one way to pay switched on.')]);
        }

        if ($data['online_enabled'] && ! $this->gateways->configured()) {
            throw ValidationException::withMessages(['online_enabled' => __('Online payment needs BILLING_GATEWAY and the gateway keys in the server .env first.')]);
        }

        if ($data['manual_enabled'] && ! filled($data['upi']['id'] ?? null) && ! filled($data['bank']['account_number'] ?? null)) {
            throw ValidationException::withMessages(['upi.id' => __('Add a UPI ID or bank account so businesses know where to pay.')]);
        }

        $before = $this->settings->all();
        $values = [
            'enforce' => (bool) $data['enforce'],
            'manual_enabled' => (bool) $data['manual_enabled'],
            'online_enabled' => (bool) $data['online_enabled'],
            'seller' => Arr::only($data['seller'], ['name', 'address', 'email', 'phone']),
            'gst' => [
                'enabled' => (bool) $data['gst']['enabled'],
                'gstin' => $data['gst']['gstin'] ?? null,
                'state_code' => isset($data['gst']['gstin']) ? substr($data['gst']['gstin'], 0, 2) : null,
            ],
            'upi' => ['id' => $data['upi']['id'] ?? null, 'payee' => $data['upi']['payee'] ?? null],
            'bank' => Arr::only(array_replace(array_fill_keys(['account_name', 'account_number', 'ifsc', 'bank_name'], null), $data['bank']), ['account_name', 'account_number', 'ifsc', 'bank_name']),
            'instructions' => $data['instructions'] ?? null,
        ];

        $this->settings->update($values);
        $after = $this->settings->all();

        $changed = array_values(array_filter(
            array_keys(self::PAYMENT_DETAILS),
            fn (string $key) => (string) data_get($before, $key) !== (string) data_get($after, $key),
        ));

        $this->audit->log('billing.settings_updated', metadata: [
            'enforce' => $values['enforce'],
            'manual_enabled' => $values['manual_enabled'],
            'online_enabled' => $values['online_enabled'],
            'gst_enabled' => $values['gst']['enabled'],
            'payment_details_changed' => $changed,
        ]);

        if ($changed !== []) {
            $this->alert(array_map(fn (string $key) => self::PAYMENT_DETAILS[$key], $changed), $admin);
        }
    }

    /** @throws ValidationException */
    public function uploadQr(?UploadedFile $file, User $admin): void
    {
        $limits = config('billing.qr');

        Validator::make(['qr' => $file], [
            'qr' => [
                'required', 'file', 'max:'.$limits['max_kb'],
                'mimetypes:'.implode(',', array_keys(self::QR_EXTENSIONS)),
                'dimensions:min_width='.$limits['min_dimension'].',min_height='.$limits['min_dimension'].',max_width='.$limits['max_dimension'].',max_height='.$limits['max_dimension'],
            ],
        ], [
            'qr.required' => __('Choose the QR code image.'),
            'qr.max' => __('The QR image must be :max MB or smaller.', ['max' => $limits['max_kb'] / 1024]),
            'qr.mimetypes' => __('Upload the QR code as a JPG, PNG or WebP image.'),
            'qr.dimensions' => __('The QR image should be between :min and :max pixels wide and high.', ['min' => $limits['min_dimension'], 'max' => $limits['max_dimension']]),
        ])->validate();

        $mime = (string) $file->getMimeType();
        $disk = (string) config('files.disks.private');
        $path = Storage::disk($disk)->putFileAs('platform/billing', $file, 'upi-qr-'.Str::lower((string) Str::ulid()).'.'.self::QR_EXTENSIONS[$mime]);

        if ($path === false) {
            throw ValidationException::withMessages(['qr' => __('The QR image could not be saved. Try again.')]);
        }

        $old = $this->settings->qr();
        $this->settings->update(['qr' => ['disk' => $disk, 'path' => $path, 'mime' => $mime, 'updated_at' => now()->toIso8601String(), 'updated_by' => $admin->email]]);
        $this->deleteFile($old);

        $this->audit->log('billing.qr_uploaded', metadata: ['path' => $path]);
        $this->alert(['UPI QR image'], $admin);
    }

    public function deleteQr(User $admin): void
    {
        $old = $this->settings->qr();

        if (! $old) {
            return;
        }

        $this->settings->update(['qr' => null]);
        $this->deleteFile($old);

        $this->audit->log('billing.qr_removed');
        $this->alert(['UPI QR image (removed)'], $admin);
    }

    /** @param  array{disk: string, path: string}|null  $qr */
    private function deleteFile(?array $qr): void
    {
        if ($qr && in_array($qr['disk'], array_keys(config('filesystems.disks')), true)) {
            Storage::disk($qr['disk'])->delete($qr['path']);
        }
    }

    /** @param  list<string>  $fields */
    private function alert(array $fields, User $admin): void
    {
        Notification::send(
            BillingRecipients::platformAdmins(),
            new PaymentDetailsChanged($fields, $admin->email, now()->timezone('Asia/Kolkata')->format('j M Y, g:i A').' IST'),
        );
    }
}
