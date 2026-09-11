<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedUrl;

/*
 * http or https on the links Kaiki hands out — checkout, trip pages, the widget's
 * calendar links.
 *
 * Found on 2026-09-11 by pressing «Συνέχεια στην κράτηση»: the hosted host was
 * the developer machine's LAN address, so the phone could open the pages, and
 * the checkout link came back as `https://192.168.1.43:8001/c/…` — which the
 * local server does not speak. Only `127.0.0.1` and `::1` counted as local.
 */

it('speaks plain http to a machine on the local network', function (string $host): void {
    config(['kaiki.tenancy.hosted_host' => $host]);

    expect(HostedUrl::checkout('token'))->toBe("http://{$host}/c/token");
})->with(['192.168.1.43:8001', '10.0.0.5', '172.16.4.2:8001', '127.0.0.1:8001', 'book.kaiki.test'])->group('fast');

it('keeps https for a real domain and a public address', function (string $host): void {
    config(['kaiki.tenancy.hosted_host' => $host]);

    expect(HostedUrl::checkout('token'))->toBe("https://{$host}/c/token");
})->with(['book.kaiki.gr', '8.8.8.8'])->group('fast');
