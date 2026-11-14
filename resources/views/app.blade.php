<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>{{ config('app.name', 'Agency OS') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|montserrat:600,700,800&display=swap" rel="stylesheet" />
    <link rel="icon" type="image/png" href="/brand/favicon.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Agency OS">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#162660">
    <meta name="color-scheme" content="light">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
