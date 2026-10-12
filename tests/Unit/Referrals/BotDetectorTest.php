<?php

namespace Tests\Unit\Referrals;

use App\Domain\Referrals\BotDetector;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BotDetectorTest extends TestCase
{
    public static function agents(): array
    {
        return [
            // Link-preview fetchers and scripts: not customers.
            'whatsapp preview' => ['WhatsApp/2.23.20.0 A', true],
            'telegram preview' => ['TelegramBot (like TwitterBot)', true],
            'viber preview' => ['Viber/21.5', true],
            'facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true],
            'slack preview' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', true],
            'curl' => ['curl/8.5.0', true],
            'empty' => ['', true],
            // Real people, including messaging apps' in-app browsers.
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36', false],
            'telegram in-app browser' => ['Mozilla/5.0 (Linux; Android 13; SM-A145F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0 Mobile Safari/537.36 Telegram-Android/11.2.3', false],
            'viber in-app browser' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Viber/24.6', false],
            'facebook in-app browser' => ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/480.0]', false],
            'firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0', false],
        ];
    }

    #[DataProvider('agents')]
    public function test_it_tells_previews_and_scripts_from_people(string $agent, bool $bot): void
    {
        $this->assertSame($bot, (new BotDetector)->isBot(Request::create('/ref/X', 'GET', server: ['HTTP_USER_AGENT' => $agent])));
    }

    public function test_browser_prefetches_are_not_clicks(): void
    {
        $request = Request::create('/ref/X', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 AppleWebKit/537.36 Chrome/128.0', 'HTTP_SEC_PURPOSE' => 'prefetch']);
        $this->assertTrue((new BotDetector)->isBot($request));
    }
}
