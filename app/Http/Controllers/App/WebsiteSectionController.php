<?php

namespace App\Http\Controllers\App;

use App\Domain\Media\Models\Media;
use App\Domain\Website\Actions\ManageWebsiteSections;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\SectionCatalog;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WebsitePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Website sections: add, edit, show/hide, reorder and remove. */
class WebsiteSectionController extends Controller
{
    public function store(Request $request, ManageWebsiteSections $sections): RedirectResponse
    {
        $type = (string) $request->validate(['type' => ['required', 'string', 'max:40']])['type'];
        $section = $sections->add($type);

        return redirect()->route('website.sections.edit', $section)->with('success', __('Section added. It is shown on your website once it has content.'));
    }

    public function edit(Request $request, WebsiteSection $section, SectionCatalog $catalog): Response
    {
        abort_unless($catalog->isAvailable($section->type), 404);

        $definition = SectionCatalog::definition($section->type);
        $collection = $definition['media'] ?? null;

        return Inertia::render('business/website/SectionEdit', [
            'section' => [
                ...WebsitePresenter::section($section, $catalog, false),
                'config' => $catalog->resolve($section->type, $section->configuration),
            ],
            'fields' => collect($catalog->fields($section->type))
                ->map(fn (array $field, string $key) => ['key' => $key, ...self::publicField($field)])
                ->values()
                ->all(),
            'media' => $collection ? [
                'rules' => WebsitePresenter::mediaRules($collection),
                'items' => Media::query()->inCollection($collection)->get()->map(fn (Media $media) => WebsitePresenter::media($media))->all(),
            ] : null,
            'canManage' => $request->user()->can('website.manage'),
        ]);
    }

    public function update(Request $request, WebsiteSection $section, ManageWebsiteSections $sections): RedirectResponse
    {
        $config = $request->input('config');
        $sections->update($section, is_array($config) ? $config : []);

        return back()->with('success', __('Section saved.'));
    }

    public function toggle(Request $request, WebsiteSection $section, ManageWebsiteSections $sections): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $sections->toggle($section, $enabled);

        return back()->with('success', $enabled ? __('Section shown.') : __('Section hidden.'));
    }

    public function reorder(Request $request, ManageWebsiteSections $sections): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer']])['ids'];
        $sections->reorder($ids);

        return back()->with('success', __('Section order saved.'));
    }

    public function destroy(WebsiteSection $section, ManageWebsiteSections $sections): RedirectResponse
    {
        $sections->remove($section);

        return redirect()->route('website.index')->with('success', __('Section removed.'));
    }

    /**
     * A field definition as the editor needs it (engine / module requirements already applied).
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function publicField(array $field): array
    {
        $public = array_intersect_key($field, array_flip(['type', 'label', 'help', 'max', 'rows', 'required', 'default', 'item_label']));

        if (isset($field['options'])) {
            $public['options'] = collect($field['options'])->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
        }

        if (isset($field['fields'])) {
            $public['fields'] = collect($field['fields'])->map(fn (array $sub, string $key) => ['key' => $key, ...self::publicField($sub)])->values()->all();
        }

        return $public;
    }
}
