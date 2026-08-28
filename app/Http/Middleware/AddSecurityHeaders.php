<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every panel response (spec SEC-10).
 *
 * These are cheap and they are the kind of thing nobody remembers to add later,
 * so they go on at the panel boundary rather than per route.
 *
 * The CSP here is for the **panel**, which is first-party and knows what it
 * loads. Hosted pages get their own, looser policy in M3: they render operator
 * branding and an operator-configured Google Font, so the two cannot share one
 * value.
 */
final class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Filament uses Alpine, which needs inline handlers, so 'unsafe-inline'
        // is unavoidable in the script policy for now. It is scoped to the
        // panel — the widget and hosted pages must never inherit it.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        // HSTS only over HTTPS. Sending it on a plain-HTTP local response is
        // meaningless at best, and pins `localhost` to HTTPS in the developer's
        // browser at worst — which is a genuinely annoying afternoon.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
