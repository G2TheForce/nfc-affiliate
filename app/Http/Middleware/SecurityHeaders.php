<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (app()->isProduction()) {
            if ($request->isSecure()) {
                $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }
            // Vite's dev server needs inline/eval scripts locally, so the CSP
            // is only applied to production builds.
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                'form-action '.implode(' ', ["'self'", ...$this->paymentFormOrigins()]),
            ]));
        }

        return $response;
    }

    /**
     * eSewa takes the customer by a form POST from the order page, which
     * form-action would otherwise block (ADR 0011).
     *
     * @return list<string>
     */
    private function paymentFormOrigins(): array
    {
        $esewa = config('payments.providers.esewa');
        if (! ($esewa['enabled'] ?? false)) {
            return [];
        }
        $url = parse_url((string) ($esewa['urls'][$esewa['mode'] ?? 'production']['form'] ?? ''));

        return isset($url['scheme'], $url['host']) && $url['scheme'] === 'https' ? ['https://'.$url['host']] : [];
    }
}
