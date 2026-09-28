<?php

namespace Tests\Feature\Website;

use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WebsiteMediaTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function upload(string $collection, UploadedFile $file, array $extra = [])
    {
        return $this->post($this->appUrl('/website/media'), ['collection' => $collection, 'file' => $file, ...$extra]);
    }

    private function media(Tenant $tenant, string $collection)
    {
        return $this->inTenant($tenant, fn () => Media::query()->inCollection($collection)->get());
    }

    public function test_a_logo_is_stored_in_the_tenant_folder_and_replaced_on_reupload(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);

        $this->upload('logo', UploadedFile::fake()->image('Our Logo.png', 400, 200), ['alt' => 'ABC Salon logo'])->assertSessionHasNoErrors()->assertRedirect();

        $first = $this->media($tenant, 'logo')->sole();
        $this->assertMatchesRegularExpression("#^tenant/{$tenant->id}/logo/logo-[0-9a-z]{26}\.png$#", $first->path);
        $this->assertSame('Our Logo.png', $first->original_name);
        $this->assertSame([400, 200], [$first->width, $first->height]);
        $this->assertSame('ABC Salon logo', $first->alt);
        $this->assertSame($owner->id, $first->uploaded_by_user_id);
        $this->assertSame('/storage/'.$first->path, $first->url());
        Storage::disk('public')->assertExists($first->path);

        $this->get($this->appUrl('/website/design'))->assertInertia(fn (Assert $page) => $page->where('logo.id', $first->id));

        $this->upload('logo', UploadedFile::fake()->image('new.jpg', 300, 300))->assertSessionHasNoErrors();

        $second = $this->media($tenant, 'logo')->sole();
        $this->assertNotSame($first->id, $second->id);
        $this->assertStringEndsWith('.jpg', $second->path);
        Storage::disk('public')->assertMissing($first->path);
        Storage::disk('public')->assertExists($second->path);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'media.uploaded']);
    }

    public function test_only_real_images_within_the_limits_are_accepted(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->upload('gallery', UploadedFile::fake()->create('notes.jpg', 10, 'text/plain'))->assertSessionHasErrors('file');
        $this->upload('gallery', UploadedFile::fake()->create('icon.svg', 5, 'image/svg+xml'))->assertSessionHasErrors('file');
        $this->upload('gallery', UploadedFile::fake()->image('big.jpg', 800, 800)->size(config('website.media.max_kb') + 1))->assertSessionHasErrors('file');
        $this->upload('gallery', UploadedFile::fake()->image('tiny.png', 50, 50))->assertSessionHasErrors('file');
        $this->upload('banner', UploadedFile::fake()->image('ok.png', 400, 400))->assertSessionHasErrors('collection');
        $this->post($this->appUrl('/website/media'), ['collection' => 'gallery'])->assertSessionHasErrors('file');

        $this->assertCount(0, $this->media($tenant, 'gallery'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_gallery_images_are_limited_ordered_described_and_removed(): void
    {
        config(['website.media.collections.gallery.max' => 2]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->upload('gallery', UploadedFile::fake()->image('one.jpg', 800, 600))->assertSessionHasNoErrors();
        $this->upload('gallery', UploadedFile::fake()->image('two.webp', 800, 600))->assertSessionHasNoErrors();
        $this->upload('gallery', UploadedFile::fake()->image('three.jpg', 800, 600))->assertSessionHasErrors('file');

        [$one, $two] = $this->media($tenant, 'gallery')->all();
        $this->assertStringEndsWith('.webp', $two->path);

        $this->put($this->appUrl('/website/media/order'), ['collection' => 'gallery', 'ids' => [$two->id, $one->id]])->assertSessionHasNoErrors();
        $this->assertSame([$two->id, $one->id], $this->media($tenant, 'gallery')->pluck('id')->all());
        $this->put($this->appUrl('/website/media/order'), ['collection' => 'gallery', 'ids' => [$two->id]])->assertSessionHasErrors('ids');

        $this->patch($this->appUrl("/website/media/{$one->id}"), ['alt' => '  Styling station  '])->assertSessionHasNoErrors();
        $this->assertSame('Styling station', $this->media($tenant, 'gallery')->firstWhere('id', $one->id)->alt);

        $this->delete($this->appUrl("/website/media/{$one->id}"))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($one->path);
        $this->assertSame([$two->id], $this->media($tenant, 'gallery')->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'media.deleted']);
    }

    public function test_images_of_another_business_are_out_of_reach(): void
    {
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->actingAs($this->ownerOf($turf));
        $this->upload('gallery', UploadedFile::fake()->image('pitch.jpg', 800, 600))->assertSessionHasNoErrors();
        $pitch = $this->media($turf, 'gallery')->sole();
        $this->assertStringStartsWith("tenant/{$turf->id}/", $pitch->path);

        $this->actingAs($this->ownerOf($salon));
        $this->patch($this->appUrl("/website/media/{$pitch->id}"), ['alt' => 'mine'])->assertNotFound();
        $this->delete($this->appUrl("/website/media/{$pitch->id}"))->assertNotFound();
        $this->put($this->appUrl('/website/media/order'), ['collection' => 'gallery', 'ids' => [$pitch->id]])->assertSessionHasErrors('ids');

        $this->assertCount(1, $this->media($turf, 'gallery'));
        $this->assertCount(0, $this->media($salon, 'gallery'));
        Storage::disk('public')->assertExists($pitch->path);
    }

    public function test_only_website_managers_can_upload(): void
    {
        $tenant = $this->createTenant();
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');

        $this->actingAs($receptionist);
        $this->upload('logo', UploadedFile::fake()->image('logo.png', 400, 200))->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
