<?php

namespace Tests\Feature\Store;

use Tests\TestCase;

class AppShellTest extends TestCase
{
    public function test_manifest_uses_the_app_name_and_sets_no_session_cookie(): void
    {
        config(['app.name' => 'Himal Tap Cards']);

        $response = $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Himal Tap Cards')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('display', 'standalone');

        $this->assertLessThanOrEqual(12, mb_strlen($response->json('short_name')));
        $this->assertSame([], $response->headers->getCookies());
        foreach ($response->json('icons') as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    public function test_spa_shell_links_the_manifest_icons_and_share_preview(): void
    {
        $this->withoutVite();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
        $this->assertStringContainsString('<script src="/theme-init.js"></script>', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        foreach (['theme-init.js', 'sw.js', 'offline.html', 'og-image.jpg', 'icons/icon.svg', 'icons/apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_spa_shell_has_no_inline_scripts_for_the_production_csp(): void
    {
        $this->withoutVite();

        $html = $this->get('/')->getContent();

        // script-src 'self' blocks inline scripts, so every script tag must load a file.
        preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
        foreach ($tags[1] as $attributes) {
            $this->assertStringContainsString('src=', $attributes);
        }
    }
}
