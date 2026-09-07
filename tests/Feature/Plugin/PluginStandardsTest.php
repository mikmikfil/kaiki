<?php

declare(strict_types=1);

use Tests\Support\Secrets\SecretKeyScanner;

/*
|--------------------------------------------------------------------------
| The WordPress plugin's shape — WPP-1, WPP-3, WPP-11, SEC-9
|--------------------------------------------------------------------------
|
| The plugin cannot be exercised from this suite: it needs WordPress, and
| WPP-15 puts that in a Playwright run against a real site rather than in a
| local harness (ADR-0015). So what belongs here is everything that is true of
| the plugin **as files** — the claims that do not need WordPress to be checked
| and would otherwise be checked by nobody until an operator's site was on the
| other end.
|
| Three of them are security claims, and one is the security claim of the whole
| milestone: **the secret key must never reach a browser.** It is enforced by
| reach rather than by value, because the danger is not somebody writing a key
| into a template — it is somebody passing the reader to one.
|
*/

function pluginPath(string $relative = ''): string
{
    return base_path('packages/wordpress-plugin/kaiki-booking' . ($relative === '' ? '' : '/' . $relative));
}

it('is a plugin WordPress can actually see', function (): void {
    // The header is not decoration: WordPress reads these lines to decide
    // whether the plugin appears in the list at all, and gets its name, its
    // version and its text domain from them.
    $header = (string) file_get_contents(pluginPath('kaiki-booking.php'));

    expect($header)->toContain('Plugin Name:')
        ->and($header)->toContain('Text Domain:       kaiki-booking')
        ->and($header)->toContain('Requires PHP:      8.1')
        ->and($header)->toContain('Requires at least: 6.4');
})->group('fast');

it('declares the PHP version operator hosting actually runs', function (): void {
    // WPP-1 and ARC-9. The platform is 8.4 (ADR-0014); this is not, because
    // Greek shared hosting is where these sites live and half of it is on 8.1.
    // A plugin that needs 8.3 is one those operators cannot install and will
    // not know why.
    /** @var array{require: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(pluginPath('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['php'])->toBe('^8.1');

    // And the check that catches an 8.4 habit before an operator's site does.
    $ruleset = (string) file_get_contents(pluginPath('phpcs.xml'));

    expect($ruleset)->toContain('PHPCompatibilityWP')
        ->and($ruleset)->toContain('name="testVersion" value="8.1-"');
})->group('fast');

it('is excluded from the platform PHPStan run rather than analysed at the wrong version', function (): void {
    expect((string) file_get_contents(base_path('phpstan.neon')))
        ->toContain('packages/wordpress-plugin/*');
})->group('fast');

it('checks the WordPress standard, and the prefixes WPP-11 requires', function (): void {
    $ruleset = (string) file_get_contents(pluginPath('phpcs.xml'));

    expect($ruleset)->toContain('ref="WordPress"')
        // Every global prefixed, checked rather than remembered.
        ->and($ruleset)->toContain('WordPress.NamingConventions.PrefixAllGlobals')
        ->and($ruleset)->toContain('<element value="kaiki"/>')
        // A warning nobody has to fix is a sniff that is not running.
        ->and($ruleset)->toContain('name="severity" value="1"');
})->group('fast');

it('cleans up after itself when it is deleted', function (): void {
    // WPP-11 asks for it, and there is a second reason: the support path for
    // almost everything is "delete it and install it again", and a second
    // installation that finds the first one's broken key stays broken through
    // the one remedy anybody knows.
    $uninstall = (string) file_get_contents(pluginPath('uninstall.php'));

    expect($uninstall)->toContain("defined( 'WP_UNINSTALL_PLUGIN' ) || exit");

    $cleanup = (string) file_get_contents(pluginPath('src/Uninstall.php'));

    expect($cleanup)->toContain('delete_option( Settings::OPTION )')
        // The one that matters most: an operator who removed the plugin has
        // withdrawn its access, and a secret left in `wp_options` afterwards is
        // a credential nobody is watching on a site nobody is thinking about.
        ->and($cleanup)->toContain('delete_option( Settings::SECRET_OPTION )')
        ->and($cleanup)->toContain('_transient_');
})->group('fast');

it('reads the secret key in three files and nowhere else', function (): void {
    // **The security claim of the milestone.** ADR-0013 Option A confines the
    // secret to WP-Cron and the inbound webhook; the settings screen stores it
    // and the uninstall removes it. Everything else — every shortcode, block,
    // Elementor widget and template — must not be able to name it.
    //
    // Enforced by reach rather than by value: nobody types a key into a
    // template, they pass the reader to one, and only this catches that.
    $offenders = SecretKeyScanner::unexpectedSecretReaders(pluginPath());

    expect($offenders)->toBe([], implode("\n", [
        'These plugin files name the secret key and are not allowed to:',
        ...$offenders,
        '',
        'If one of them genuinely runs server-side only — a cron task, the webhook handler —',
        'add it to SecretKeyScanner::ALLOWED with a line saying which.',
    ]));
})->group('fast');

it('ships no live secret in anything a browser downloads', function (): void {
    // SEC-9's grep, over the two artefacts a visitor actually receives. Crude,
    // and exactly right: there is no legitimate reason for a live secret to be
    // in a file the browser downloads.
    $carrying = SecretKeyScanner::artefactsCarryingASecret([
        base_path('packages/widget/dist/kaiki-widget.js'),
        public_path('widget/kaiki-widget.js'),
    ]);

    expect($carrying)->toBe([]);
})->group('fast');

it('keeps the plugin out of the browser entirely, by never enqueuing its settings', function (): void {
    // A softer version of the same rule, and the one that catches the obvious
    // convenience: `wp_localize_script` with "the settings" is how an option
    // array reaches page source, and the settings array is where a careless
    // change would put the secret.
    $offenders = [];

    foreach (glob(pluginPath('src/**/*.php')) ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        if (str_contains($contents, 'wp_localize_script') && str_contains($contents, 'Settings::all()')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
})->group('fast');

it('says everything it says in Greek as well', function (): void {
    // WPP-3 asks for the settings page in both locales, and the strings about
    // the secret key are why it is a requirement rather than a courtesy: an
    // operator who cannot read the warning is the operator who pastes the key
    // into a page.
    //
    // The same parity check the platform's own lang files get (I18N-3), applied
    // where WordPress keeps its translations.
    $template = (string) file_get_contents(pluginPath('languages/kaiki-booking.pot'));
    $greek = (string) file_get_contents(pluginPath('languages/kaiki-booking-el.po'));

    preg_match_all('/^msgid "(?P<id>.+)"$/m', $template, $wanted);
    // A `msgid` and the `msgstr` on the line under it. Written with an escaped
    // newline rather than a literal one so the pattern reads as a pattern.
    preg_match_all('/^msgid "(?P<id>.+)"
msgstr "(?P<text>.*)"$/m', $greek, $have);

    /** @var array<string, string> $translated */
    $translated = array_combine($have['id'], $have['text']);

    $missing = [];

    foreach ($wanted['id'] as $id) {
        if (! array_key_exists($id, $translated) || trim($translated[$id]) === '') {
            $missing[] = $id;
        }
    }

    expect($missing)->toBe([], implode('
', [
        'These strings have no Greek translation:',
        ...$missing,
        '',
        'Regenerate the template with `php packages/wordpress-plugin/tools/extract-strings.php`,',
        'add the Greek, then `php packages/wordpress-plugin/tools/build-translations.php`.',
    ]));
})->group('fast', 'i18n');

it('ships a compiled translation, because WordPress does not read a .po', function (): void {
    // The `.po` is the source and the `.mo` is what `load_plugin_textdomain`
    // actually reads. A commit that changed one and not the other would leave
    // the plugin in English with a Greek file sitting beside it, which is the
    // failure that looks like nothing being wrong.
    $mo = pluginPath('languages/kaiki-booking-el.mo');

    expect(is_file($mo))->toBeTrue();

    $bytes = (string) file_get_contents($mo);

    /** @var array{magic: int, count: int} $header */
    $header = unpack('Vmagic/Vrevision/Vcount', $bytes);

    // The little-endian magic number every `.mo` starts with.
    expect($header['magic'])->toBe(0x950412DE);

    // And it holds as many strings as the template asks for, so a stale `.mo`
    // is a failure rather than a quiet half-translation.
    preg_match_all('/^msgid ".+"$/m', (string) file_get_contents(pluginPath('languages/kaiki-booking.pot')), $wanted);

    expect($header['count'])->toBeGreaterThanOrEqual(count($wanted[0]));
})->group('fast', 'i18n');
