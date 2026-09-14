<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\VideoEmbed;

/*
|--------------------------------------------------------------------------
| The hero's video link (HOS-1)
|--------------------------------------------------------------------------
|
| An operator pastes whatever their browser gave them. That is a share link, a
| watch link, a mobile link, a short link or the thing the "embed" button copied
| — and all five are the same video. Accepting one of them and refusing the rest
| teaches an operator that the field is broken, so the shapes are asserted here
| rather than left to whichever one the developer happened to test with.
|
| The other half is what must never happen: nothing an operator types may reach
| an attribute. The id is matched against a pattern and the origin is a constant
| per provider, so a link carrying a quote, a script, or somebody else's host
| produces `null` — which renders exactly as an empty field does, as the
| photograph.
|
*/

it('reads a video id out of every shape of YouTube link', function (string $url): void {
    $embed = VideoEmbed::parse($url);

    expect($embed)->not->toBeNull()
        ->and($embed->provider)->toBe(VideoEmbed::YOUTUBE)
        ->and($embed->id)->toBe('dQw4w9WgXcQ');
})->with([
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
    'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?si=abcdef',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'https://www.youtube.com/live/dQw4w9WgXcQ',
])->group('fast');

it('reads a video id out of every shape of Vimeo link', function (string $url): void {
    $embed = VideoEmbed::parse($url);

    expect($embed)->not->toBeNull()
        ->and($embed->provider)->toBe(VideoEmbed::VIMEO)
        ->and($embed->id)->toBe('347119375');
})->with([
    'https://vimeo.com/347119375',
    'https://www.vimeo.com/347119375',
    'https://player.vimeo.com/video/347119375',
    'https://vimeo.com/channels/staffpicks/347119375',
    'https://vimeo.com/groups/motion/videos/347119375',
])->group('fast');

it('keeps the key an unlisted Vimeo video needs, because the embed is refused without it', function (): void {
    $embed = VideoEmbed::parse('https://vimeo.com/347119375/a1b2c3d4e5');

    expect($embed?->hash)->toBe('a1b2c3d4e5')
        ->and($embed?->embedUrl())->toContain('h=a1b2c3d4e5');
})->group('fast');

it('refuses anything that is not one of the two providers', function (mixed $url): void {
    expect(VideoEmbed::parse($url))->toBeNull();
})->with([
    'nothing at all' => null,
    'empty' => '',
    'not a url' => 'my holiday video',
    // The one that matters: a host ending in the right letters is not the
    // right host, and a policy built from the link rather than from a constant
    // is how that becomes somebody else's frame on an operator's front page.
    'a look-alike host' => 'https://notyoutube.com/watch?v=dQw4w9WgXcQ',
    'a host with ours as a prefix' => 'https://youtube.com.example.net/watch?v=dQw4w9WgXcQ',
    'another platform' => 'https://www.facebook.com/watch?v=1234567890',
    'a page rather than a video' => 'https://www.youtube.com/@aegeanblue',
    'a YouTube id of the wrong length' => 'https://www.youtube.com/watch?v=tooshort',
    'a Vimeo url with no id' => 'https://vimeo.com/staffpicks',
    'an id that is trying to break out' => 'https://www.youtube.com/watch?v="onload=alert(1)',
])->group('fast');

it('builds a background player, not a video somebody has to press play on', function (): void {
    $youtube = VideoEmbed::parse('https://youtu.be/dQw4w9WgXcQ');
    $vimeo = VideoEmbed::parse('https://vimeo.com/347119375');

    // Muted and looping, or it is not a background; `playlist` is what makes a
    // single YouTube video loop at all.
    expect($youtube?->embedUrl())->toStartWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?')
        ->and($youtube?->embedUrl())->toContain('autoplay=1')
        ->and($youtube?->embedUrl())->toContain('mute=1')
        ->and($youtube?->embedUrl())->toContain('loop=1')
        ->and($youtube?->embedUrl())->toContain('playlist=dQw4w9WgXcQ')
        ->and($youtube?->embedUrl())->toContain('controls=0')
        // The privacy-enhanced host, which stores nothing until somebody
        // presses play — and nothing at all on a player with no controls.
        ->and($youtube?->origin())->toBe('https://www.youtube-nocookie.com');

    expect($vimeo?->embedUrl())->toStartWith('https://player.vimeo.com/video/347119375?')
        ->and($vimeo?->embedUrl())->toContain('background=1')
        ->and($vimeo?->origin())->toBe('https://player.vimeo.com');
})->group('fast');
