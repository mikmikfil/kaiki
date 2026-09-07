<?php

declare(strict_types=1);

namespace Tests\Support\Secrets;

use Symfony\Component\Finder\Finder;

/**
 * SEC-9's `sk_` grep, over the two artefacts a visitor's browser receives
 * (SEC-9, WPP-3 item 3).
 *
 * > *"Keys are stored hashed; the plaintext is shown once (TEN-3). SEC-9's CI
 * > grep for `sk_` covers both the built widget bundle and the WordPress
 * > plugin."*
 *
 * ## Two scans, because there are two ways to leak the same key
 *
 * **A literal in a built artefact.** Somebody pastes a real key into a fixture,
 * a default or a comment, and the bundler carries it to every operator's page.
 * A grep is a crude instrument and exactly right for this: there is no
 * legitimate reason for a live secret to be in a file the browser downloads.
 *
 * **A reader in the wrong file.** The plugin's secret is read by exactly one
 * method, and the danger is not that somebody writes `sk_` into a template —
 * it is that somebody passes `Settings::secret_key()` to one. That cannot be
 * grepped for by value, so it is grepped for by **reach**: the reader may be
 * named only in the files that are allowed to hold it, and a new caller has to
 * be added to the list by a person, in a commit, on purpose.
 *
 * The allow-list is the point. It is short, it names why each entry is on it,
 * and adding a template to it is a decision somebody has to defend.
 */
final class SecretKeyScanner
{
    /**
     * The files that may name the secret at all.
     *
     * WPP-3 item 2: *"that sync runs server-side in WP-Cron and in the inbound
     * webhook handler only"*. Everything here is one of those, or the settings
     * screen that stores it, or the uninstall that removes it.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        // Where it is defined and read.
        'src/Settings/Settings.php',
        // Where an operator types it in. Admin-only, capability-checked.
        'src/Settings/SettingsPage.php',
        // Where it is deleted, which is the opposite of a leak.
        'src/Uninstall.php',
        // The sync itself (#116) — WP-Cron and the verified webhook, which is
        // exactly the two places WPP-3 item 2 permits. It is a separate class
        // from `Api\Client` for this reason alone: that one is reached from
        // shortcodes, blocks and a REST route, and if the two shared a method
        // this list would have to allow the secret in a file every template
        // calls, which would make the list worth nothing.
        'src/Seo/SyncClient.php',
    ];

    /**
     * The plugin's **own** tests, which are not scanned by reach.
     *
     * The guard asks "can this file hand the secret to a template". A test can
     * hand it to nothing: it does not run in WordPress, it is not loaded by the
     * plugin, and no request reaches it. It is also where the one assertion that
     * matters lives — *that the sync sends the secret as a bearer token and no
     * `Origin`* — which cannot be written without naming the option.
     *
     * The value scan is deliberately **not** narrowed: a live `sk_` in a test
     * fixture is still a live key in the repository, and
     * {@see self::artefactsCarryingASecret()} keeps looking for one everywhere.
     */
    private const NOT_SCANNED_BY_REACH = 'tests/';

    /**
     * Files under the plugin that name the secret and are not allowed to.
     *
     * @return list<string>
     */
    public static function unexpectedSecretReaders(string $pluginPath): array
    {
        if (! is_dir($pluginPath)) {
            return [];
        }

        $files = Finder::create()
            ->files()
            ->in($pluginPath)
            ->name('*.php')
            ->exclude(['vendor', 'node_modules']);

        $found = [];

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            if (str_starts_with($relative, self::NOT_SCANNED_BY_REACH)) {
                continue;
            }

            $code = self::withoutComments((string) $file->getContents());

            if (str_contains($code, 'secret_key()') || str_contains($code, 'SECRET_OPTION')) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The same file with its comments removed.
     *
     * A docblock that *mentions* the reader — `{@see Settings::secret_key()}`,
     * explaining why a class does not call it — is not a leak, and a guard that
     * flagged it would earn an exemption. After a dozen exemptions a guard
     * enforces nothing, which is the posture `CredentialLeakScanner` already
     * takes: match the shape that actually leaks, and nothing that merely looks
     * like it.
     *
     * `token_get_all` rather than a regex, because a comment containing a quote
     * is where a regex gets this wrong.
     */
    private static function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $kept .= $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }

    /**
     * Built artefacts that carry something shaped like a live secret key.
     *
     * The pattern is the key's own shape and not the bare prefix, because
     * `sk_` on its own appears in prose, in a placeholder and in this file. A
     * key is `sk_live_` or `sk_test_` followed by the random part.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function artefactsCarryingASecret(array $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            if (preg_match('/sk_(live|test)_[A-Za-z0-9]{8,}/', (string) file_get_contents($path)) === 1) {
                $found[] = $path;
            }
        }

        return $found;
    }
}
