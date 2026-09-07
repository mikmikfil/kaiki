<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serving the widget, with the two cache lives ADR-0011 fixes (WGT-4).
 *
 * ## Two paths, two completely different cache policies
 *
 * | Path | Life | Why |
 * |---|---|---|
 * | `/widget/v1.4.2/kaiki-widget.js` | `immutable`, one year | the bytes at that path can never change |
 * | `/widget/kaiki-widget.js` | five minutes, revalidated | it is repointed on release, and a release has to reach operators the same day |
 *
 * An operator's `<script src>` names the **alias**, because their snippet lives
 * in a WordPress theme nobody is going to edit. So the alias must not be cached
 * for a year, and the versioned file must not be re-fetched — the whole point of
 * the split is that the second and later page views cost nothing.
 *
 * ## Why a controller rather than a static file
 *
 * The headers are the feature. A file served by the web server carries whatever
 * that server was configured with, which is an M8 decision made in a different
 * repository — and "the alias was cached for a year by a proxy" is a bug that
 * takes a week to notice and a year to clear. Setting them here makes the policy
 * part of the application, and testable.
 *
 * `Access-Control-Allow-Origin: *` is correct here and only here: the bundle is
 * public JavaScript loaded by every operator's site, and a browser needs no
 * credential to read it. The **API** is where the origin allow-list lives
 * (SEC-7).
 */
final class WidgetBundleController
{
    /** One year, which is what `immutable` means in practice. */
    private const IMMUTABLE_SECONDS = 31_536_000;

    /** Short enough that a release reaches operators the same day. */
    private const ALIAS_SECONDS = 300;

    public function versioned(Request $request, string $version): Response
    {
        // The route constrains this too; belt and braces, because the value
        // becomes a path segment and a traversal here would serve any file the
        // process can read.
        if (preg_match('/^v[0-9A-Za-z.\-]{1,32}$/', $version) !== 1) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->serve(public_path("widget/{$version}/kaiki-widget.js"), self::IMMUTABLE_SECONDS, true);
    }

    public function alias(Request $request): Response
    {
        return $this->serve(public_path('widget/kaiki-widget.js'), self::ALIAS_SECONDS, false);
    }

    /** What the alias currently points at — the question a deploy log answers badly. */
    public function manifest(Request $request): Response
    {
        $path = public_path('widget/manifest.json');

        abort_unless(File::exists($path), Response::HTTP_NOT_FOUND);

        return response(File::get($path), Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=' . self::ALIAS_SECONDS,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function serve(string $path, int $seconds, bool $immutable): BinaryFileResponse
    {
        abort_unless(File::exists($path), Response::HTTP_NOT_FOUND);

        $response = response()->file($path, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => sprintf('public, max-age=%d%s', $seconds, $immutable ? ', immutable' : ', must-revalidate'),
            // Public JavaScript on somebody else's page. The credential is the
            // publishable key inside the request the widget then makes, not the
            // right to read the file.
            'Access-Control-Allow-Origin' => '*',
            // The bundle is a script; nothing about it should ever be sniffed
            // into another type.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // A weak ETag on the alias so a revalidation is a 304 rather than 60 KB.
        if (! $immutable) {
            $response->setAutoEtag();
        }

        return $response;
    }
}
