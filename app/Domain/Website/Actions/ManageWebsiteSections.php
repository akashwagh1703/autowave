<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\SectionCatalog;
use App\Domain\Website\Support\SectionSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits the current tenant's website sections. The header stays first and the footer last;
 * neither can be removed. Removing a section deletes its uploaded files. Every change is audited.
 */
class ManageWebsiteSections
{
    public function __construct(
        private readonly SectionCatalog $catalog,
        private readonly SectionSchema $schema,
        private readonly AuditLogger $audit,
        private readonly ManageAttachments $attachments,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function update(WebsiteSection $section, array $input): WebsiteSection
    {
        $values = $this->schema->validate($section->type, $input);

        $section->update(['configuration' => [...($section->configuration ?? []), ...$values]]);
        $this->audit->log('website.section_updated', $section, ['type' => $section->type]);

        return $section;
    }

    public function add(string $type): WebsiteSection
    {
        if (! $this->catalog->isAvailable($type)) {
            throw ValidationException::withMessages(['type' => __('This section is not available for your business.')]);
        }

        if (WebsiteSection::query()->where('type', $type)->exists()) {
            throw ValidationException::withMessages(['type' => __('Your website already has this section.')]);
        }

        $section = WebsiteSection::query()->create([
            'type' => $type,
            'sort_order' => ((int) WebsiteSection::query()->max('sort_order')) + 10,
            'enabled' => true,
            'configuration' => [],
        ]);

        $this->audit->log('website.section_added', $section, ['type' => $type]);

        return $section;
    }

    public function remove(WebsiteSection $section): void
    {
        if (! SectionCatalog::isRemovable($section->type)) {
            throw ValidationException::withMessages(['section' => __('The :section cannot be removed. You can hide it instead.', ['section' => mb_strtolower(SectionCatalog::definition($section->type)['label'] ?? $section->type)])]);
        }

        $section->delete();
        $this->audit->log('website.section_removed', null, ['type' => $section->type, 'section_id' => $section->id]);
        $this->attachments->deleteAllFor($section);
    }

    public function toggle(WebsiteSection $section, bool $enabled): WebsiteSection
    {
        $section->update(['enabled' => $enabled]);
        $this->audit->log($enabled ? 'website.section_shown' : 'website.section_hidden', $section, ['type' => $section->type]);

        return $section;
    }

    /**
     * @param  list<int>  $ids  every section id of the tenant in the new order; the header and
     *                          footer keep their pinned positions whatever their place in the list
     */
    public function reorder(array $ids): void
    {
        $sections = WebsiteSection::query()->get()->keyBy('id');
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) !== $sections->count() || array_diff($ids, $sections->keys()->all()) !== []) {
            throw ValidationException::withMessages(['ids' => __('The section list is out of date. Reload the page and try again.')]);
        }

        DB::transaction(function () use ($ids, $sections) {
            foreach ($ids as $index => $id) {
                $sections[$id]->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        $this->audit->log('website.sections_reordered', null, ['order' => array_map(fn (int $id) => $sections[$id]->type, $ids)]);
    }
}
