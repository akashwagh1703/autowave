<?php

namespace Tests\Feature\Files;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Models\Customer;
use App\Domain\Files\Models\Attachment;
use App\Domain\Files\Support\StorageAllowance;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;
use ZipArchive;

/** Customer and student documents: private storage, type sniffing, limits, permissions and isolation. */
class CustomerDocumentsTest extends TestCase
{
    use CreatesCrmRecords, CreatesEducationRecords, CreatesTenants, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['files.disks.private' => 'files']);
        Storage::fake('files');
    }

    private function pdf(string $name = 'id-proof.pdf', int $paddingKb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF.str_repeat('0', $paddingKb * 1024));
    }

    /** @param  array<string, string>  $entries */
    private function zip(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'awzip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }

        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function docx(): UploadedFile
    {
        return $this->zip('notes.docx', [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
        ]);
    }

    private function upload(Customer $customer, UploadedFile $file, array $data = [])
    {
        return $this->post($this->appUrl("/customers/{$customer->id}/attachments"), ['file' => $file, ...$data]);
    }

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create();
        $this->addMember($tenant, $user, $role);

        return $user;
    }

    public function test_the_owner_uploads_a_document_that_is_stored_privately_and_listed(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->upload($customer, $this->pdf(), ['title' => ' Aadhaar card '])->assertSessionHasNoErrors()->assertSessionHas('success');

        $attachment = Attachment::withoutTenantScope()->sole();
        $this->assertSame($tenant->id, $attachment->tenant_id);
        $this->assertSame('files', $attachment->disk);
        $this->assertSame(Attachment::PRIVATE, $attachment->visibility);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('Aadhaar card', $attachment->title);
        $this->assertStringStartsWith("tenant/{$tenant->id}/documents/customers/", $attachment->path);
        $this->assertStringEndsWith('.pdf', $attachment->path);
        Storage::disk('files')->assertExists($attachment->path);
        $this->assertTrue(AuditLog::query()->where('action', 'attachment.uploaded')->where('tenant_id', $tenant->id)->exists());

        $this->get($this->appUrl("/customers/{$customer->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/customers/Show')
            ->has('documents.items', 1)
            ->where('documents.items.0.name', 'Aadhaar card')
            ->where('documents.items.0.extension', 'PDF')
            ->where('documents.can_manage', true)
            ->where('documents.storage.used_bytes', $attachment->size_bytes)
            ->missing('documents.items.0.path'));

        $response = $this->get($this->appUrl("/attachments/{$attachment->id}"));
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::PDF, $response->streamedContent());
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Aadhaar card.pdf', $response->headers->get('Content-Disposition'));
        $this->assertTrue(AuditLog::query()->where('action', 'attachment.downloaded')->exists());

        $this->assertStringStartsWith('attachment', $this->get($this->appUrl("/attachments/{$attachment->id}?download=1"))->headers->get('Content-Disposition'));
    }

    public function test_word_files_are_recognised_by_their_layout_and_always_download(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->upload($customer, $this->docx())->assertSessionHasNoErrors();

        $attachment = Attachment::withoutTenantScope()->sole();
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $attachment->mime_type);
        $this->assertStringEndsWith('.docx', $attachment->path);

        $response = $this->get($this->appUrl("/attachments/{$attachment->id}"));
        $response->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_files_are_judged_by_content_not_by_name(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->upload($customer, UploadedFile::fake()->createWithContent('invoice.pdf', '<html><script>alert(1)</script></html>'))->assertSessionHasErrors('file');
        $this->upload($customer, $this->zip('report.docx', ['readme.txt' => 'not a word file']))->assertSessionHasErrors('file');
        $this->upload($customer, UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64)))->assertSessionHasErrors('file');
        $this->upload($customer, UploadedFile::fake()->image('photo.jpg', 600, 400))->assertSessionHasNoErrors();

        $this->assertSame(['image/jpeg'], Attachment::withoutTenantScope()->pluck('mime_type')->all());
    }

    public function test_size_count_and_storage_limits_are_enforced(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->upload($customer, $this->pdf('big.pdf', 10 * 1024 + 1))->assertSessionHasErrors('file');

        config(['files.owners.customer.max' => 1]);
        $this->upload($customer, $this->pdf())->assertSessionHasNoErrors();
        $this->upload($customer, $this->pdf())->assertSessionHasErrors(['file' => __('Up to :max files can be attached here. Delete one first.', ['max' => 1])]);

        config(['files.owners.customer.max' => 50]);
        app(StorageAllowance::class)->setCap($tenant, 0);
        $this->upload($customer, $this->pdf())->assertSessionHasErrors('file');
        $this->post($this->appUrl('/website/media'), ['collection' => 'gallery', 'file' => UploadedFile::fake()->image('room.jpg', 800, 600)])->assertSessionHasErrors('file');

        $this->assertSame(1, Attachment::withoutTenantScope()->count());
    }

    public function test_permissions_decide_who_sees_uploads_and_deletes(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));
        $this->upload($customer, $this->pdf())->assertSessionHasNoErrors();
        $attachment = Attachment::withoutTenantScope()->sole();

        $this->actingAs($this->member($tenant, 'staff'));
        $this->get($this->appUrl("/customers/{$customer->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents', null));
        $this->get($this->appUrl("/attachments/{$attachment->id}"))->assertForbidden();
        $this->upload($customer, $this->pdf())->assertForbidden();

        $this->actingAs($this->member($tenant, 'accountant'));
        $this->get($this->appUrl("/customers/{$customer->id}"))->assertInertia(fn (Assert $page) => $page->where('documents.can_manage', false));
        $this->get($this->appUrl("/attachments/{$attachment->id}"))->assertOk();
        $this->delete($this->appUrl("/attachments/{$attachment->id}"))->assertForbidden();

        $this->actingAs($this->member($tenant, 'receptionist'));
        $this->upload($customer, $this->pdf())->assertSessionHasNoErrors();
        $this->delete($this->appUrl("/attachments/{$attachment->id}"))->assertSessionHas('success');

        $this->assertNull(Attachment::withoutTenantScope()->find($attachment->id));
        Storage::disk('files')->assertMissing($attachment->path);
        $this->assertTrue(AuditLog::query()->where('action', 'attachment.deleted')->exists());
    }

    public function test_another_business_cannot_reach_the_files(): void
    {
        $first = $this->createTenant('First Salon');
        $customer = $this->makeCustomer($first);
        $this->actingAs($this->ownerOf($first));
        $this->upload($customer, $this->pdf())->assertSessionHasNoErrors();
        $attachment = Attachment::withoutTenantScope()->sole();

        $second = $this->createTenant('Second Salon');
        $this->actingAs($this->ownerOf($second));

        $this->get($this->appUrl("/attachments/{$attachment->id}"))->assertNotFound();
        $this->delete($this->appUrl("/attachments/{$attachment->id}"))->assertNotFound();
        $this->upload($customer, $this->pdf())->assertNotFound();
        $this->assertSame(1, Attachment::withoutTenantScope()->count());
    }

    public function test_a_student_page_shows_the_customers_documents(): void
    {
        $tenant = $this->createCoaching();
        $enrolment = $this->admit($tenant, $this->makeBatch($tenant));
        $this->actingAs($this->ownerOf($tenant));
        $this->upload($enrolment->customer, $this->pdf('marksheet.pdf'))->assertSessionHasNoErrors();

        $this->get($this->appUrl("/students/{$enrolment->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/students/Show')
            ->has('documents.items', 1)
            ->where('documents.items.0.name', 'marksheet.pdf')
            ->where('documents.upload_url', route('customers.attachments.store', $enrolment->customer)));
    }

    public function test_the_super_admin_sees_storage_and_changes_the_allowance(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));
        $this->upload($customer, $this->pdf())->assertSessionHasNoErrors();
        $size = Attachment::withoutTenantScope()->sole()->size_bytes;

        $this->actingAs(User::factory()->platformAdmin()->create());
        $this->get($this->adminUrl('/tenants'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tenants.data.0.storage.used_bytes', $size)
            ->where('tenants.data.0.storage.cap_mb', 1024)
            ->where('tenants.data.0.storage.custom', false)
            ->where('defaultStorageMb', 1024));

        $this->put($this->adminUrl("/tenants/{$tenant->id}/storage-limit"), ['mb' => 2048])->assertSessionHas('success');
        $this->assertSame(2048, app(StorageAllowance::class)->capMb($tenant));
        $this->assertTrue(AuditLog::query()->where('action', 'storage.limit_updated')->where('tenant_id', $tenant->id)->exists());

        $this->put($this->adminUrl("/tenants/{$tenant->id}/storage-limit"), ['mb' => -5])->assertSessionHasErrors('mb');
        $this->put($this->adminUrl("/tenants/{$tenant->id}/storage-limit"), ['mb' => null])->assertSessionHas('success');
        $this->assertFalse(app(StorageAllowance::class)->hasCustomCap($tenant));
    }

    public function test_the_health_check_covers_the_private_bucket(): void
    {
        $this->artisan('autowave:health')->expectsOutputToContain('bucket autowave-private')->assertSuccessful();

        config(['filesystems.disks.files.bucket' => config('filesystems.disks.media.bucket')]);

        $this->artisan('autowave:health')->assertFailed();
    }
}
