<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

use App\Domain\Branding\Support\SvgSanitizer;

/**
 * What a file **is**, decided from its bytes (spec SEC-13).
 *
 * ## Why not `$file->getMimeType()`
 *
 * `UploadedFile::getClientMimeType()` is a header the browser sent and an
 * attacker writes by hand. `getMimeType()` is better — it shells out to finfo —
 * but finfo answers a different question than the one that matters here: it is
 * a *guess*, tuned to be useful, and for SVG it commonly returns `text/plain`,
 * `text/xml` or `text/html` depending on what is in the file and which magic
 * database the machine has. A validator that accepts `image/svg+xml` from finfo
 * rejects perfectly ordinary logos on one server and accepts them on another,
 * which is the worst possible property for a security check.
 *
 * So the three formats BRD-7 allows are recognised here, by their signatures,
 * with no magic database involved and the same answer on every machine.
 *
 * ## SVG is decided by the parser, not by a signature
 *
 * An SVG has no magic bytes — it is XML, and it may open with a declaration, a
 * BOM, whitespace or comments. This class only reports it as a *candidate*;
 * {@see SvgSanitizer} is what actually decides,
 * because it has to parse the document anyway and a file it cannot parse into
 * an `<svg>` root is not one we would store whatever the signature said.
 */
final class ImageContentType
{
    public const PNG = 'image/png';

    public const WEBP = 'image/webp';

    public const SVG = 'image/svg+xml';

    /**
     * The media type of these bytes, or **null** when it is none of the three.
     *
     * Null is not "unknown, allow it": every caller treats it as a refusal.
     */
    public static function detect(string $contents): ?string
    {
        if (self::isPng($contents)) {
            return self::PNG;
        }

        if (self::isWebp($contents)) {
            return self::WEBP;
        }

        return self::looksLikeSvg($contents) ? self::SVG : null;
    }

    /** The eight-byte PNG signature, fixed by the format. */
    private static function isPng(string $contents): bool
    {
        return str_starts_with($contents, "\x89PNG\r\n\x1a\n");
    }

    /**
     * RIFF container, four bytes of length, then `WEBP`.
     *
     * The length is skipped rather than checked: a truncated file fails at the
     * decoder, which is the right place for it, and rejecting on a byte count
     * here would refuse files that decode perfectly well.
     */
    private static function isWebp(string $contents): bool
    {
        return strlen($contents) >= 12
            && str_starts_with($contents, 'RIFF')
            && substr($contents, 8, 4) === 'WEBP';
    }

    /**
     * Does this open like an XML document that could be an SVG?
     *
     * Only the opening is examined, and only enough of it to tell markup from a
     * PDF or a zip that was renamed. The BOM is stripped first because a file
     * saved by a Windows editor carries one and `<` is then the fourth byte.
     */
    private static function looksLikeSvg(string $contents): bool
    {
        $head = ltrim($contents, "\xEF\xBB\xBF \t\n\r\0\x0B");

        return str_starts_with($head, '<?xml')
            || str_starts_with($head, '<svg')
            || str_starts_with($head, '<!--')
            || str_starts_with($head, '<!DOCTYPE');
    }

    /** The file extension this project stores each type under. */
    public static function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            self::PNG => 'png',
            self::WEBP => 'webp',
            self::SVG => 'svg',
            default => 'bin',
        };
    }

    /** Is this a raster format, and therefore something to resize and re-encode? */
    public static function isRaster(string $mimeType): bool
    {
        return $mimeType === self::PNG || $mimeType === self::WEBP;
    }
}
