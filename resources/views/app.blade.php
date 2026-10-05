<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @php
            // Public tenant websites and marketing pages: title and meta tags are rendered here so crawlers and link previews see them without JavaScript.
            $site = str_starts_with($page['component'], 'website/') ? ($page['props']['seo'] ?? null) : null;
            $siteColor = $site ? ($page['props']['business']['primary_color'] ?? null) : null;
            $marketing = $site ? null : ($page['props']['meta'] ?? null);
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
                <meta name="twitter:card" content="summary_large_image">
            @endif
            @if (! empty($page['props']['preview']))
                <meta name="robots" content="noindex, nofollow">
            @else
                <link rel="canonical" href="{{ request()->getSchemeAndHttpHost() }}/">
            @endif
            <link rel="icon" href="{{ $page['props']['business']['logo'] ?? '/favicon.svg' }}">
        @elseif ($marketing)
            @php($fullTitle = $marketing['title'].' · '.config('app.name'))
            <title inertia>{{ $fullTitle }}</title>
            <meta name="description" content="{{ $marketing['description'] }}">
            <link rel="canonical" href="{{ request()->url() }}">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ $fullTitle }}">
            <meta property="og:description" content="{{ $marketing['description'] }}">
            <meta property="og:type" content="website">
            <meta property="og:url" content="{{ request()->url() }}">
            <meta property="og:image" content="{{ request()->getSchemeAndHttpHost() }}/images/og-autowave.png">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta name="twitter:card" content="summary_large_image">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @else
            <title inertia>{{ config('app.name', 'AutoWave') }}</title>
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif

        @isset($schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
        @endisset

        <link rel="preconnect" href="https://fonts.bunny.net">
        @if ($marketing)
            <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800" rel="stylesheet" />
        @else
            <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
        @endif

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx', "resources/js/pages/{$page['component']}.jsx"])
        <x-inertia::head />
    </head>
    <body class="antialiased" @if ($site) data-site="tenant" @endif>
        <x-inertia::app />
    </body>
</html>
