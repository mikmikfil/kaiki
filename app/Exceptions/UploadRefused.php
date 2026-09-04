<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Branding\Support\SvgSanitizer;
use App\Domain\Media\Actions\StoreUploadedImage;
use RuntimeException;

/**
 * An uploaded file was refused before anything was written to disk
 * (spec BRD-7, SEC-13).
 *
 * Thrown from {@see StoreUploadedImage}, which is the only writer — so a file
 * arriving through the panel, the API or an import is judged by the same code
 * and refused for the same reasons. The panel catches this and puts the
 * sentence beside the field; nothing else has to.
 *
 * Each named constructor is one of the three refusals, kept separate because
 * "your logo was rejected" is not a message anybody can act on: an operator
 * whose 4 MB PNG was refused needs to be told about the 2 MB limit, and one
 * whose SVG carried a `<script>` needs to be told to export it again from their
 * design tool rather than to shrink it.
 *
 * Messages come from `lang/*\/branding.php` (CNV-11) — never from a literal
 * here.
 */
final class UploadRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** Over `config('kaiki.branding.uploads.max_kilobytes')`. */
    public static function tooLarge(int $kilobytes, int $limitKilobytes): self
    {
        return new self(
            (string) trans('branding.upload.refused.too_large', [
                'size' => $kilobytes,
                'limit' => $limitKilobytes,
            ]),
            'too_large',
        );
    }

    /**
     * The bytes are not PNG, WebP or SVG — whatever the extension or the
     * browser's Content-Type header claimed.
     */
    public static function unsupportedType(): self
    {
        return new self((string) trans('branding.upload.refused.unsupported_type'), 'unsupported_type');
    }

    /**
     * An SVG that {@see SvgSanitizer} could not
     * make safe: an unparseable document, a DOCTYPE, or a root element that is
     * not `<svg>`.
     */
    public static function unsafeSvg(): self
    {
        return new self((string) trans('branding.upload.refused.unsafe_svg'), 'unsafe_svg');
    }

    /** A raster file whose bytes pass the signature check but which no decoder can read. */
    public static function undecodable(): self
    {
        return new self((string) trans('branding.upload.refused.undecodable'), 'undecodable');
    }
}
