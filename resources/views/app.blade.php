<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div id="root"></div>
    <noscript>This application needs JavaScript enabled.</noscript>
</body>
</html>
