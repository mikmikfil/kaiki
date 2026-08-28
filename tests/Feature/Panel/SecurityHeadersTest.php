<?php

declare(strict_types=1);

use function Pest\Laravel\get;

/*
 * Spec SEC-10. Cheap headers that nobody remembers to add later, applied at the
 * panel boundary rather than per route.
 */

it('sends the security headers on the operator panel', function (): void {
    $response = get('/app/login')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('same-origin')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
})->group('fast');

it('sends them on the super-admin panel too', function (): void {
    $response = get('/admin/login')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->not->toBeNull();
})->group('fast');

it('does not send HSTS over plain HTTP', function (): void {
    // Sending it on a plain-HTTP local response is meaningless at best, and at
    // worst pins `localhost` to HTTPS in the developer's browser — a genuinely
    // annoying afternoon for whoever hits it.
    $response = get('http://localhost/app/login');

    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
})->group('fast');

it('sends HSTS over HTTPS', function (): void {
    $response = get('https://localhost/app/login');

    expect($response->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=31536000');
})->group('fast');

it('does not let the panel be framed', function (): void {
    // Clickjacking a back office is how someone gets an operator to cancel a
    // departure without realising it. Both headers, because older browsers
    // honour only one of them.
    $response = get('/app/login');

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
})->group('fast');
