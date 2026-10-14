<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The install manifest for the storefront as a phone app. Served from
 * Laravel so the app name follows APP_NAME.
 */
class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_strimwidth($name, 0, 12),
            'description' => 'Smart NFC business cards, with an affiliate programme.',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#080b19',
            'theme_color' => '#4f46e5',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
            'shortcuts' => [
                ['name' => 'Shop', 'url' => '/shop'],
                ['name' => 'My account', 'url' => '/account'],
                ['name' => 'Affiliate dashboard', 'url' => '/account/affiliate/dashboard'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
