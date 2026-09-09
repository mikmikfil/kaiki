<?php

declare(strict_types=1);

namespace App\Filament\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Normalizer;

/**
 * The signed-in person's initials, drawn locally.
 *
 * ## Why this exists at all
 *
 * Filament's default is {@see UiAvatarsProvider},
 * which builds `https://ui-avatars.com/api/?name=…`. Nothing in this project
 * had replaced it, so **every page of both panels sent the signed-in person's
 * name to a third party** — on every load, for every operator, for every member
 * of their crew. It was found while collecting screenshots for the manual, where
 * it showed up as a broken image in the top-right corner of all thirty-eight of
 * them: the request is already refused here, which was the tell.
 *
 * A name is personal data, the request carries a `Referer`, and there is no
 * agreement with that service. It is also a page-load dependency on a host
 * nobody chose: when it is slow, the panel is slow, and the panel is what an
 * operator uses on a pontoon on a phone with one bar.
 *
 * Nothing is lost by drawing it here. The picture is two letters on a coloured
 * disc, and there is no version of that worth a network request.
 *
 * ## An inline SVG rather than a route
 *
 * A `data:` URI needs no controller, no cache headers, no signed URL and no
 * authorisation check — the alternative is an endpoint that renders a person's
 * initials, which is a small screen of code guarding an image of two letters.
 * It is also why this works with no storage and no `storage:link`.
 *
 * The colour is Kaiki's hull teal rather than Filament's gray-950, because this
 * is the one avatar in the product and it may as well be the product's.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    /**
     * Hull teal, from the branding decisions of 2026-09-04.
     *
     * Not read from the tenant's brand profile on purpose: this is panel chrome,
     * the panel is Kaiki's, and an operator who has set their brand to a pale
     * yellow would get white initials on it.
     */
    private const BACKGROUND = '#123A5E';

    public function get(Model|Authenticatable $record): string
    {
        $initials = $this->initials(Filament::getNameForDefaultAvatar($record));

        // `viewBox` and no width or height, so the disc takes whatever size the
        // element around it has — Filament renders this at three different sizes.
        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
            <rect width="100" height="100" rx="50" fill="{$this->background()}"/>
            <text x="50" y="50" fill="#ffffff" font-family="Inter, system-ui, sans-serif"
                  font-size="42" font-weight="600" text-anchor="middle"
                  dominant-baseline="central">{$initials}</text>
            </svg>
            SVG;

        // Base64 rather than percent-encoding: the payload is Greek more often
        // than not, and a `data:` URI carrying raw UTF-8 is read differently by
        // different browsers.
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function background(): string
    {
        return self::BACKGROUND;
    }

    /**
     * Two letters, upper-cased and unaccented, from the first and last word.
     *
     * ## The accent comes off, and that is Greek typography rather than laziness
     *
     * «Άννα» upper-cases to «Ά», and `Str::upper` is right to keep the accent —
     * it is upper-casing a word. A standing initial is not a word: Greek drops
     * the tone mark on a capital letter used alone, so «Άννα Ιωάννου» is «ΑΙ»
     * and «Ά.Ι.» is wrong. The same pass gives «É» as «E», which is what a set
     * of initials wants for a Latin name too.
     *
     * Decomposed and stripped of combining marks rather than mapped through a
     * table of Greek vowels: a table would be seven entries that are right and
     * every other alphabet silently wrong.
     *
     * A one-word name gives one letter rather than a letter and a space, and a
     * name that is entirely punctuation gives nothing at all rather than an
     * exception.
     */
    private function initials(string $name): string
    {
        $words = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->filter(static fn (string $word): bool => $word !== '')
            ->values();

        if ($words->isEmpty()) {
            return '';
        }

        $letters = $words->count() === 1
            ? [$words->first()]
            : [$words->first(), $words->last()];

        return collect($letters)
            ->map(static fn (string $word): string => self::unaccented(Str::upper(mb_substr($word, 0, 1))))
            ->join('');
    }

    /** Strip the combining marks a decomposition exposes, and nothing else. */
    private static function unaccented(string $letter): string
    {
        $decomposed = Normalizer::normalize($letter, Normalizer::FORM_D);

        if ($decomposed === false) {
            return $letter;
        }

        return preg_replace('/\p{Mn}/u', '', $decomposed) ?? $letter;
    }
}
