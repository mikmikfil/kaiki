<?php

declare(strict_types=1);

use App\Filament\Avatars\InitialsAvatarProvider;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The panel's avatar leaves nothing and asks nobody
|--------------------------------------------------------------------------
|
| Filament's default provider builds a `https://ui-avatars.com/api/?name=…`
| URL, so before this class every page of both panels sent a staff member's
| name to a third party. The first test here is the one that matters: it is
| about the absence of a request, which is not visible on a screen and cannot
| be noticed by looking at the panel.
|
*/

function avatarFor(string $name): string
{
    return (new InitialsAvatarProvider)->get(new User(['name' => $name]));
}

function avatarSvg(string $name): string
{
    return (string) base64_decode(
        str_replace('data:image/svg+xml;base64,', '', avatarFor($name)),
        true,
    );
}

it('never points at a host outside this application', function (): void {
    // Asserted against the returned bytes rather than against the class name,
    // the way `ExportRows` asserts document numbers: a provider that is correct
    // today and grows a fallback tomorrow fails here.
    $avatar = avatarFor('Μαρία Παπαδοπούλου');

    expect($avatar)->toStartWith('data:image/svg+xml;base64,')
        ->and($avatar)->not->toContain('ui-avatars')
        ->and($avatar)->not->toContain('http://')
        ->and($avatar)->not->toContain('https://');
});

it('carries no part of the name into the picture beyond its initials', function (): void {
    // The point of initials rather than a name: an avatar is rendered in a
    // screenshot, a shared screen and a printed manual, and «ΜΠ» identifies a
    // person to their colleagues without identifying them to a stranger.
    $svg = avatarSvg('Μαρία Παπαδοπούλου');

    expect($svg)->toContain('ΜΠ')
        ->and($svg)->not->toContain('Μαρία')
        ->and($svg)->not->toContain('Παπαδοπούλου');
});

it('upper-cases Greek without leaving the accent behind', function (): void {
    // `strtoupper` is byte-wise and would leave «ά» alone; `mb_strtoupper`
    // gives «Ά», which is wrong for an initial. Only `Str::upper` strips it.
    expect(avatarSvg('Άννα Ιωάννου'))->toContain('ΑΙ');
});

it('gives one letter to a one-word name rather than a letter and a gap', function (): void {
    expect(avatarSvg('Νίκος'))->toContain('>Ν<');
});

it('renders rather than throws when there is no name to read', function (): void {
    // A user row can reach the panel chrome mid-invitation, and a blank avatar
    // is a smaller problem than a five-hundred on every page.
    expect(avatarSvg(''))->toContain('<svg')
        ->and(avatarSvg('   '))->toContain('<svg');
});

it('is what both panels are configured to use', function (): void {
    // The class existing is not the fix; the panels using it is.
    foreach (['app', 'admin'] as $panel) {
        expect(filament()->getPanel($panel)->getDefaultAvatarProvider())
            ->toBe(InitialsAvatarProvider::class);
    }
});
