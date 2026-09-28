<?php

namespace App\Http\Controllers\App;

use App\Domain\Media\Actions\ManageMedia;
use App\Domain\Media\Models\Media;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Website images: logo, hero image and gallery. */
class WebsiteMediaController extends Controller
{
    public function store(Request $request, ManageMedia $manager): RedirectResponse
    {
        $collection = (string) $request->validate([
            'collection' => ['required', 'string', Rule::in(array_keys(config('website.media.collections')))],
        ])['collection'];

        $manager->upload($request->file('file'), $collection, $request->user(), $request->input('alt'));

        return back()->with('success', __('Image uploaded.'));
    }

    public function update(Request $request, Media $media, ManageMedia $manager): RedirectResponse
    {
        $manager->updateAlt($media, $request->input('alt'));

        return back()->with('success', __('Image description saved.'));
    }

    public function reorder(Request $request, ManageMedia $manager): RedirectResponse
    {
        $validated = $request->validate([
            'collection' => ['required', 'string', Rule::in(array_keys(config('website.media.collections')))],
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $manager->reorder($validated['collection'], $validated['ids']);

        return back()->with('success', __('Image order saved.'));
    }

    public function destroy(Media $media, ManageMedia $manager): RedirectResponse
    {
        $manager->delete($media);

        return back()->with('success', __('Image removed.'));
    }
}
