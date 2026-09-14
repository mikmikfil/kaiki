<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

/**
 * A YouTube or Vimeo link, turned into something a hosted page may frame.
 *
 * ## The operator's string never reaches the markup
 *
 * What an operator pastes is stored as they typed it, so the editor can show it
 * back to them. What the page renders is built here from two things: a provider
 * this class names, and an id matched against a pattern narrow enough that
 * nothing else survives it — eleven URL-safe characters for YouTube, digits for
 * Vimeo. A link that does not parse produces `null` and the hero falls back to
 * its photograph, which is what an empty field does too. There is no third
 * outcome in which an operator's text becomes an attribute.
 *
 * That is also what makes the Content-Security-Policy honest: the page's
 * `frame-src` names {@see self::origin()}, a constant per provider, and never a
 * host taken from the link.
 *
 * ## Why the embed is a background and not a player
 *
 * A hero video is decoration standing behind a heading — the same as the MP4
 * beside it in the schema: muted, looping, no controls, no related videos at
 * the end, and not in the tab order. A visitor who wants to watch the film goes
 * to the operator's channel; a visitor who wants to book a boat trip is reading
 * the words in front of it.
 *
 * `youtube-nocookie.com` rather than `youtube.com` — the same player, which
 * stores nothing until somebody presses play, and on a background nobody can
 * press play on it therefore stores nothing at all. That is the difference
 * between a decorative loop and a tracker on every operator's front page.
 */
final class VideoEmbed
{
    public const YOUTUBE = 'youtube';

    public const VIMEO = 'vimeo';

    /** The origins a hosted page may frame a video from, by provider. */
    private const ORIGINS = [
        self::YOUTUBE => 'https://www.youtube-nocookie.com',
        self::VIMEO => 'https://player.vimeo.com',
    ];

    /**
     * @param  string  $provider  one of the class constants
     * @param  string  $id  already matched against this provider's pattern
     * @param  string|null  $hash  Vimeo's unlisted-video key, when the link carried one
     */
    private function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $hash = null,
    ) {}

    /**
     * The link an operator pasted, or null when it is not one we can frame.
     *
     * Deliberately generous about the *shape* of the link and strict about what
     * comes out of it: a share link, a watch link, a short link, an embed link
     * and a mobile link are all things an operator will paste, and refusing
     * four of the five teaches them the field is broken.
     */
    public static function parse(?string $url): ?self
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');

        parse_str((string) ($parts['query'] ?? ''), $query);

        return match (true) {
            self::hostIs($host, ['youtube.com', 'youtube-nocookie.com']) => self::youtube($path, $query),
            self::hostIs($host, ['youtu.be']) => self::youtubeId(self::segment($path, 0)),
            self::hostIs($host, ['vimeo.com']) => self::vimeo($path),
            default => null,
        };
    }

    /** The one origin this embed needs in the page's `frame-src`. */
    public function origin(): string
    {
        return self::ORIGINS[$this->provider];
    }

    /**
     * The `src` of the hero's iframe.
     *
     * The parameters are what turn a player into a background. YouTube's `loop`
     * needs `playlist` set to the same id — a single video cannot loop without
     * one. That is a documented quirk of the player rather than a mistake here.
     */
    public function embedUrl(): string
    {
        $query = match ($this->provider) {
            self::YOUTUBE => [
                'autoplay' => 1,
                'mute' => 1,
                'loop' => 1,
                'playlist' => $this->id,
                'controls' => 0,
                'disablekb' => 1,
                'modestbranding' => 1,
                'playsinline' => 1,
                'rel' => 0,
                'iv_load_policy' => 3,
            ],
            default => array_filter([
                // Vimeo's own word for exactly this: no controls, no title, no
                // byline, muted and looping.
                'background' => 1,
                'autoplay' => 1,
                'muted' => 1,
                'loop' => 1,
                // Without it, a second video anywhere on the page stops this one.
                'autopause' => 0,
                'h' => $this->hash,
            ], static fn (mixed $value): bool => $value !== null),
        };

        $path = $this->provider === self::YOUTUBE
            ? '/embed/' . $this->id
            : '/video/' . $this->id;

        return $this->origin() . $path . '?' . http_build_query($query);
    }

    /** Where the video lives, for an operator checking what they pasted. */
    public function watchUrl(): string
    {
        return $this->provider === self::YOUTUBE
            ? 'https://www.youtube.com/watch?v=' . $this->id
            : 'https://vimeo.com/' . $this->id;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function hostIs(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            // The domain itself or a subdomain of it — `www.`, `m.` and
            // `player.` are all links an operator will paste. Matched on a dot
            // boundary, so `notyoutube.com` is not a subdomain of `youtube.com`
            // and neither is `youtube.com.example.net`.
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private static function youtube(string $path, array $query): ?self
    {
        // `/watch?v=…`, plus the path forms the same video has when it is
        // shared from a phone, from the player, or as a live stream.
        return match (self::segment($path, 0)) {
            'watch' => self::youtubeId(is_string($query['v'] ?? null) ? $query['v'] : null),
            'embed', 'shorts', 'live', 'v' => self::youtubeId(self::segment($path, 1)),
            default => null,
        };
    }

    private static function youtubeId(?string $id): ?self
    {
        return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1
            ? new self(self::YOUTUBE, $id)
            : null;
    }

    private static function vimeo(string $path): ?self
    {
        $segments = self::segments($path);

        // The id is the first all-digits segment: `/123456789`,
        // `/video/123456789`, `/channels/staffpicks/123456789` and
        // `/groups/x/videos/123456789` are the same video.
        foreach ($segments as $index => $segment) {
            if (preg_match('/^\d{6,12}$/', $segment) !== 1) {
                continue;
            }

            // An unlisted video carries a key in the next segment, and the
            // embed is refused without it — so a link that has one keeps it.
            $next = $segments[$index + 1] ?? null;
            $hash = $next !== null && preg_match('/^[a-f0-9]{6,16}$/', $next) === 1 ? $next : null;

            return new self(self::VIMEO, $segment, $hash);
        }

        return null;
    }

    private static function segment(string $path, int $index): ?string
    {
        return self::segments($path)[$index] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '',
        ));
    }
}
