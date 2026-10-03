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
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** The website's Video and Downloads sections: uploads on the section page, the public site and removal. */
class WebsiteFilesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function mp4(string $name = 'studio.mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 256));
    }

    private function pdf(string $name = 'prices.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    private function addSection(Tenant $tenant, string $type): WebsiteSection
    {
        $this->post($this->appUrl('/website/sections'), ['type' => $type])->assertSessionHasNoErrors();

        return WebsiteSection::withoutTenantScope()->where('tenant_id', $tenant->id)->where('type', $type)->sole();
    }

    /** @return array<string, array<string, mixed>> */
    private function siteSections(): array
    {
        $sections = [];
        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertOk()->assertInertia(function (Assert $page) use (&$sections) {
            $sections = collect($page->toArray()['props']['sections'])->keyBy('type')->all();
        });

        return $sections;
    }

    public function test_the_video_section_plays_uploaded_videos_on_the_website(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $section = $this->addSection($tenant, 'video');

        $this->assertArrayNotHasKey('video', $this->siteSections());

        $this->post($this->appUrl("/website/sections/{$section->id}/attachments"), ['file' => $this->mp4(), 'title' => 'Our studio'])->assertSessionHasNoErrors();
        $this->post($this->appUrl("/website/sections/{$section->id}/attachments"), ['file' => $this->pdf()])->assertSessionHasErrors('file');

        $video = Attachment::withoutTenantScope()->sole();
        $this->assertSame(Attachment::PUBLIC, $video->visibility);
        $this->assertStringStartsWith("tenant/{$tenant->id}/website/videos/", $video->path);
        Storage::disk('public')->assertExists($video->path);

        $this->get($this->appUrl("/website/sections/{$section->id}/edit"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('section.has_files', true)
            ->has('files.items', 1)
            ->where('files.kinds.0.kind', 'video')
            ->where('files.max_files', 3)
            ->where('files.upload_url', route('website.sections.attachments.store', $section)));

        $this->assertSame([['url' => '/storage/'.$video->path, 'type' => 'video/mp4', 'title' => 'Our studio']], $this->siteSections()['video']['data']);
    }

    public function test_the_downloads_section_lists_files_and_removing_it_deletes_them(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $section = $this->addSection($tenant, 'downloads');

        $this->post($this->appUrl("/website/sections/{$section->id}/attachments"), ['file' => $this->pdf(), 'title' => 'Price list'])->assertSessionHasNoErrors();
        $path = Attachment::withoutTenantScope()->sole()->path;

        $this->assertSame([[
            'name' => 'Price list',
            'url' => '/storage/'.$path,
            'extension' => 'PDF',
            'size_bytes' => strlen(self::PDF),
        ]], $this->siteSections()['downloads']['data']);

        $this->delete($this->appUrl("/website/sections/{$section->id}"))->assertRedirect();

        $this->assertSame(0, Attachment::withoutTenantScope()->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_other_sections_take_no_files_and_staff_cannot_upload(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $section = $this->addSection($tenant, 'video');
        $about = WebsiteSection::withoutTenantScope()->where('tenant_id', $tenant->id)->where('type', 'about')->sole();

        $this->post($this->appUrl("/website/sections/{$about->id}/attachments"), ['file' => $this->pdf()])->assertNotFound();

        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->post($this->appUrl("/website/sections/{$section->id}/attachments"), ['file' => $this->mp4()])->assertForbidden();
        $this->assertSame(0, Attachment::withoutTenantScope()->count());
    }
}
