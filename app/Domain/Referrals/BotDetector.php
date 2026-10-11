<?php

namespace App\Domain\Referrals;

use Illuminate\Http\Request;

/**
 * Filters obvious non-human traffic before a click is recorded: crawlers,
 * link-preview fetchers (WhatsApp, Facebook, Viber and so on), scripts and
 * browser prefetches. Deliberately simple; fraud review comes in Phase 8.
 */
class BotDetector
{
    private const PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|viber|telegram|skype|discord|slack|embedly|curl|wget|python|httpclient|okhttp|java\/|go-http|headless|lighthouse|monitor|scan/i';

    public function isBot(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::PATTERN, $agent)) {
            return true;
        }

        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose')));

        return str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender');
    }
}
