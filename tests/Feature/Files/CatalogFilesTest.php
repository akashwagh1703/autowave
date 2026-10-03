<?php

namespace Tests\Feature\Files;

use App\Domain\Files\Models\Attachment;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Models\WebsiteSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesEducationRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Videos and brochures on products, services and courses: public storage, limits, permissions and the website. */
class CatalogFilesTest extends TestCase
{
    use CreatesBookingRecords, CreatesCommerceRecords, CreatesCrmRecords, CreatesEducationRecords, CreatesTenants, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('files');
        config(['files.disks.private' => 'files']);
    }

    private function mp4(string $name = 'demo.mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 256));
    }

    private function pdf(string $name = 'brochure.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    private function sections(Tenant $tenant, string $host): array
    {
        $sections = [];
        $this->get($this->siteUrl($host))->assertOk()->assertInertia(function (Assert $page) use (&$sections) {
            $sections = collect($page->toArray()['props']['sections'])->keyBy('type')->all();
        });

        return $sections;
    }

    private function enableSection(Tenant $tenant, string $type): void
    {
        WebsiteSection::withoutTenantScope()->where('tenant_id', $tenant->id)->where('type', $type)->update(['enabled' => true]);
    }

    public function test_a_product_gets_one_video_and_brochures_that_show_on_the_website(): void
    {
        $tenant = $this->createTenant('ABC Store', 'local_store');
        $product = $this->makeProduct($tenant, ['name' => 'Ceiling fan']);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl("/products/{$product->id}/attachments"), ['file' => $this->mp4()])->assertSessionHasNoErrors();
        $this->post($this->appUrl("/products/{$product->id}/attachments"), ['file' => $this->pdf(), 'title' => 'Spec sheet'])->assertSessionHasNoErrors();
        $this->post($this->appUrl("/products/{$product->id}/attachments"), ['file' => $this->mp4('second.mp4')])
            ->assertSessionHasErrors(['file' => 'Only one video can be added here. Delete the current one first.']);

        $video = Attachment::withoutTenantScope()->where('kind', 'video')->sole();
        $this->assertSame('public', $video->disk);
        $this->assertSame(Attachment::PUBLIC, $video->visibility);
        $this->assertSame('video/mp4', $video->mime_type);
        $this->assertStringStartsWith("tenant/{$tenant->id}/catalog/products/", $video->path);
        Storage::disk('public')->assertExists($video->path);

        $this->get($this->appUrl("/products/{$product->id}/edit"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('files.items', 2)
            ->where('files.public', true)
            ->where('files.kinds.0.kind', 'video')
            ->where('files.kinds.0.max_files', 1)
            ->where('files.items.1.url', '/storage/'.$video->path));

        $products = $this->sections($tenant, 'abc-store.autowave.test')['products']['data'][0]['products'];
        $this->assertSame(['url' => '/storage/'.$video->path, 'type' => 'video/mp4', 'title' => null], $products[0]['video']);
        $this->assertSame('Spec sheet', $products[0]['brochures'][0]['name']);
        $this->assertSame('PDF', $products[0]['brochures'][0]['extension']);

        $this->get($this->appUrl("/attachments/{$video->id}"))->assertRedirect('/storage/'.$video->path);
    }

    public function test_only_people_who_can_edit_the_record_upload(): void
    {
        $tenant = $this->createTenant('ABC Store', 'local_store');
        $product = $this->makeProduct($tenant);
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->post($this->appUrl("/products/{$product->id}/attachments"), ['file' => $this->pdf()])->assertForbidden();
        $this->assertSame(0, Attachment::withoutTenantScope()->count());
    }

    public function test_service_and_course_files_appear_on_their_pages_and_the_website(): void
    {
        $salon = $this->createTenant();
        $service = $this->makeService($salon, ['name' => 'Bridal makeup']);
        $this->actingAs($this->ownerOf($salon));
        $this->post($this->appUrl("/services/{$service->id}/attachments"), ['file' => $this->pdf('packages.pdf')])->assertSessionHasNoErrors();

        $this->get($this->appUrl("/services/{$service->id}/edit"))->assertInertia(fn (Assert $page) => $page->has('files.items', 1));
        $services = $this->sections($salon, 'abc-salon.autowave.test')['services']['data'][0]['services'];
        $this->assertSame('packages', $services[0]['brochures'][0]['name']);
        $this->assertNull($services[0]['video']);

        $coaching = $this->createCoaching();
        $course = $this->makeCourse($coaching, ['name' => 'Class 10 Maths']);
        $this->enableSection($coaching, 'courses');
        $this->actingAs($this->ownerOf($coaching));
        $this->post($this->appUrl("/courses/{$course->id}/attachments"), ['file' => $this->mp4('intro.mp4'), 'title' => 'Meet the teacher'])->assertSessionHasNoErrors();

        $this->get($this->appUrl('/courses'))->assertInertia(fn (Assert $page) => $page
            ->has("files.{$course->id}.items", 1)
            ->where("files.{$course->id}.upload_url", route('courses.attachments.store', $course)));
        $courses = $this->sections($coaching, 'bright-classes.autowave.test')['courses']['data'];
        $this->assertSame('Meet the teacher', $courses[0]['video']['title']);
    }

    public function test_deleting_a_record_deletes_its_files(): void
    {
        $tenant = $this->createTenant('ABC Store', 'local_store');
        $product = $this->makeProduct($tenant);
        $this->actingAs($this->ownerOf($tenant));
        $this->post($this->appUrl("/products/{$product->id}/attachments"), ['file' => $this->pdf()])->assertSessionHasNoErrors();
        $path = Attachment::withoutTenantScope()->sole()->path;

        $this->delete($this->appUrl("/products/{$product->id}"))->assertSessionHas('success');

        $this->assertSame(0, Attachment::withoutTenantScope()->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_private_customer_documents_never_reach_the_website(): void
    {
        $tenant = $this->createTenant();
        $service = $this->makeService($tenant, ['name' => 'Haircut']);
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));
        $this->post($this->appUrl("/customers/{$customer->id}/attachments"), ['file' => $this->pdf('aadhaar.pdf')])->assertSessionHasNoErrors();
        $this->inTenant($tenant, fn () => Attachment::query()->sole()->update(['attachable_type' => $service->getMorphClass(), 'attachable_id' => $service->id]));

        $services = $this->sections($tenant, 'abc-salon.autowave.test')['services']['data'][0]['services'];
        $this->assertSame([], $services[0]['brochures']);
    }
}
