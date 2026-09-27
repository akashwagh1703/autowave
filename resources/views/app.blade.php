<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#4338ca">

        {{-- Cascade layer order must be declared before MUI injects global styles (they go right above the insertion point). --}}
        <style>@layer theme, base, mui, components, utilities;</style>
        <meta name="emotion-insertion-point" content="">

        <title inertia>{{ config('app.name', 'AutoWave') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx', "resources/js/pages/{$page['component']}.jsx"])
        <x-inertia::head />
    </head>
    <body class="antialiased">
        <x-inertia::app />
    </body>
</html>
