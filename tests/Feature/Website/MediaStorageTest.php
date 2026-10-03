<?php

namespace Tests\Feature\Website;

use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Uploads on object storage (the `media` disk, MinIO on production) and moving existing files there. */
class MediaStorageTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const MEDIA_URL = 'https://media.autowave.test';

    private function useObjectStorage(): void
    {
        config(['website.media.disk' => 'media', 'filesystems.disks.media.url' => self::MEDIA_URL]);
        Storage::fake('media', ['url' => self::MEDIA_URL]);
    }

    private function uploadLogo(Tenant $tenant)
    {
        $this->actingAs($this->ownerOf($tenant));

        return $this->post($this->appUrl('/website/media'), ['collection' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 400, 200)]);
    }

    public function test_uploads_go_to_object_storage_and_load_from_the_public_media_url(): void
    {
        $this->useObjectStorage();
        $tenant = $this->createTenant();

        $this->uploadLogo($tenant)->assertSessionHasNoErrors();

        $media = $this->inTenant($tenant, fn () => Media::query()->inCollection('logo')->sole());
        $this->assertSame('media', $media->disk);
        $this->assertSame(self::MEDIA_URL.'/'.$media->path, $media->url());
        Storage::disk('media')->assertExists($media->path);
    }

    public function test_a_storage_outage_is_shown_as_a_form_error_and_saves_nothing(): void
    {
        config(['website.media.disk' => 'media']);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(UnableToWriteFile::atLocation('tenant/1/logo/x.png', 'connection refused'));
        Storage::shouldReceive('disk')->with('media')->andReturn($disk);
        $tenant = $this->createTenant();

        $this->uploadLogo($tenant)->assertSessionHasErrors(['file' => __('The image could not be saved. Please try again in a minute.')]);

        $this->assertSame(0, Media::withoutTenantScope()->count());
    }

    public function test_existing_images_can_be_moved_to_object_storage(): void
    {
        Storage::fake('public');
        $tenant = $this->createTenant();
        $this->uploadLogo($tenant)->assertSessionHasNoErrors();
        $media = Media::withoutTenantScope()->sole();
        $missing = $this->inTenant($tenant, fn () => Media::query()->create([
            'collection' => 'gallery', 'disk' => 'public', 'path' => "tenant/{$tenant->id}/website/gone.jpg",
            'original_name' => 'gone.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'width' => 100, 'height' => 100,
        ]));
        $this->useObjectStorage();

        $this->artisan('autowave:media-move', ['disk' => 'media', '--dry-run' => true])->assertSuccessful();
        $this->assertSame('public', $media->fresh()->disk);
        Storage::disk('media')->assertMissing($media->path);

        $this->artisan('autowave:media-move', ['disk' => 'media'])
            ->expectsOutputToContain("Media #{$missing->id}: file not found")
            ->assertSuccessful();

        $this->assertSame('media', $media->fresh()->disk);
        $this->assertSame('public', $missing->fresh()->disk);
        Storage::disk('media')->assertExists($media->path);
        Storage::disk('public')->assertExists($media->path);

        $this->artisan('autowave:media-move', ['disk' => 'public', '--delete-source' => true])->assertSuccessful();

        $this->assertSame('public', $media->fresh()->disk);
        Storage::disk('media')->assertMissing($media->path);
    }

    public function test_the_move_command_rejects_unknown_disks(): void
    {
        $this->artisan('autowave:media-move', ['disk' => 'nowhere'])->assertFailed();
    }

    public function test_the_health_check_covers_object_storage(): void
    {
        $this->useObjectStorage();

        $this->artisan('autowave:health')->expectsOutputToContain('bucket autowave-public')->assertSuccessful();

        config(['filesystems.disks.media.url' => null]);

        $this->artisan('autowave:health')->assertFailed();
    }
}
