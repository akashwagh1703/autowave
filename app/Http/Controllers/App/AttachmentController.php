<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Education\Models\Course;
use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Files\Models\Attachment;
use App\Domain\Service\Models\Service;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\SectionCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uploads, opens and deletes record attachments. Private files are only ever streamed from here, after the
 * permission the owning record type requires (config('files.owners.*.view|manage')).
 */
class AttachmentController extends Controller
{
    /** Shown or played in the browser rather than downloaded; Word and Excel files always download. */
    private const INLINE = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac',
    ];

    public function __construct(
        private readonly ManageAttachments $attachments,
        private readonly AuditLogger $audit,
    ) {}

    public function storeForCustomer(Request $request, Customer $customer): RedirectResponse
    {
        return $this->store($request, $customer, 'customer');
    }

    public function storeForProduct(Request $request, Product $product): RedirectResponse
    {
        return $this->store($request, $product, 'product');
    }

    public function storeForService(Request $request, Service $service): RedirectResponse
    {
        return $this->store($request, $service, 'service');
    }

    public function storeForCourse(Request $request, Course $course): RedirectResponse
    {
        return $this->store($request, $course, 'course');
    }

    /** Videos of the Video section, documents of the Downloads section (config('website.sections.*.files')). */
    public function storeForWebsiteSection(Request $request, WebsiteSection $section): RedirectResponse
    {
        $ownerKey = SectionCatalog::definition($section->type)['files'] ?? abort(404);

        return $this->store($request, $section, $ownerKey);
    }

    public function show(Request $request, Attachment $attachment): Response
    {
        $this->authorizeFor($request, $attachment, 'view');
        $disk = Storage::disk($attachment->disk);

        if (! $attachment->isPrivate() && ! $request->boolean('download')) {
            return redirect()->away($attachment->publicUrl());
        }

        $inline = in_array($attachment->mime_type, self::INLINE, true) && ! $request->boolean('download');
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            // Browsers refuse to show a PDF in a sandboxed page, so PDFs only get the resource restrictions.
            'Content-Security-Policy' => ($attachment->mime_type === 'application/pdf' ? '' : 'sandbox; ')."default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
        ];

        try {
            $response = $disk->response($attachment->path, $attachment->downloadName(), $headers, $inline ? 'inline' : 'attachment');
        } catch (FilesystemException $exception) {
            report($exception);
            abort(404, __('This file is not available right now.'));
        }

        $this->audit->log('attachment.downloaded', $attachment, ['inline' => $inline]);

        return $response;
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->authorizeFor($request, $attachment, 'manage');
        $this->attachments->delete($attachment);

        return back()->with('success', __('File deleted.'));
    }

    private function store(Request $request, Model $owner, string $ownerKey): RedirectResponse
    {
        $this->attachments->upload($owner, $ownerKey, $request->file('file'), $request->user(), $request->input('title'));

        return back()->with('success', __('File uploaded.'));
    }

    private function authorizeFor(Request $request, Attachment $attachment, string $ability): void
    {
        $owner = collect(config('files.owners'))->first(fn (array $definition) => $definition['model'] === $attachment->attachable_type);

        abort_unless($owner && $request->user()->can($owner[$ability]), 403);
    }
}
