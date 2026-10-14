<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($meta) ? $meta['title'].' · '.config('app.name') : config('app.name') }}</title>
    <meta name="description" content="{{ $meta['description'] ?? 'Smart NFC business cards for Nepal. Share your contact with one tap. Pay with eSewa, Khalti or cash on delivery.' }}">
    <meta name="theme-color" content="#4f46e5">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $meta['title'] ?? config('app.name').': one tap, every connection' }}">
    <meta property="og:description" content="{{ $meta['description'] ?? 'Smart NFC business cards for Nepal. Share your contact with one tap.' }}">
    <meta property="og:image" content="{{ url('/og-image.jpg') }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <script src="/theme-init.js"></script>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
    <div id="root"></div>
    <noscript>This application needs JavaScript enabled.</noscript>
</body>
</html>
