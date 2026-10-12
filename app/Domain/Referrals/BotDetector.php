<?php

namespace App\Domain\Referrals;

use Illuminate\Http\Request;

/**
 * Filters obvious non-human traffic before a click is recorded: crawlers,
 * link-preview fetchers (WhatsApp, Facebook, Viber and so on), scripts and
 * browser prefetches. Deliberately simple.
 */
class BotDetector
{
    private const PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|curl|wget|python|httpclient|okhttp|java\/|go-http|headless|lighthouse|monitor|scan/i';

    /**
     * Messaging apps both fetch link previews (a bot) and open links in their
     * own in-app browser (a real customer, e.g. "... Mobile Safari/537.36
     * Telegram-Android/11.2"). Their name only marks a bot when the agent is
     * not a full browser engine.
     */
    private const MESSENGER = '/whatsapp|viber|telegram|skype|discord|slack/i';

    private const BROWSER_ENGINE = '/AppleWebKit\/|Gecko\//';

    public function isBot(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::PATTERN, $agent)) {
            return true;
        }
        if (preg_match(self::MESSENGER, $agent) && ! preg_match(self::BROWSER_ENGINE, $agent)) {
            return true;
        }

        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose')));

        return str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender');
    }
}
