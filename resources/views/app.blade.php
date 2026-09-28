<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @php
            // Public tenant websites: title and meta tags are rendered here so crawlers and link previews see them without JavaScript.
            $site = str_starts_with($page['component'], 'website/') ? ($page['props']['seo'] ?? null) : null;
            $siteColor = $site ? ($page['props']['business']['primary_color'] ?? null) : null;
        @endphp
        <meta name="theme-color" content="{{ $siteColor ?: '#4338ca' }}">

        {{-- Cascade layer order must be declared before MUI injects global styles (they go right above the insertion point). --}}
        <style>@layer theme, base, mui, components, utilities;</style>
        <meta name="emotion-insertion-point" content="">

        @if ($site)
            <title inertia>{{ $site['title'] }}</title>
            @if (! empty($site['description']))
                <meta name="description" content="{{ $site['description'] }}" inertia="description">
                <meta property="og:description" content="{{ $site['description'] }}" inertia="og:description">
            @endif
            <meta property="og:title" content="{{ $site['title'] }}" inertia="og:title">
            <meta property="og:type" content="website" inertia="og:type">
            <meta property="og:url" content="{{ request()->url() }}" inertia="og:url">
            @if (! empty($site['image']))
                <meta property="og:image" content="{{ request()->getSchemeAndHttpHost().$site['image'] }}" inertia="og:image">
            @endif
            @if (! empty($page['props']['preview']))
                <meta name="robots" content="noindex, nofollow">
            @endif
        @else
            <title inertia>{{ config('app.name', 'AutoWave') }}</title>
        @endif

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx', "resources/js/pages/{$page['component']}.jsx"])
        <x-inertia::head />
    </head>
    <body class="antialiased" @if ($site) data-site="tenant" @endif>
        <x-inertia::app />
    </body>
</html>
