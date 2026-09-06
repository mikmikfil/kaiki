<?php

declare(strict_types=1);

namespace Tests\Support\Secrets;

use Symfony\Component\Finder\Finder;
use Tests\Support\I18n\LiteralScanner;
use Tests\Support\Vat\VatRateScanner;

/**
 * Finds code that could put an operator's credentials somewhere they can be read
 * (spec SEC-9, MYD-15, PAY-3).
 *
 * SEC-9 and MYD-15 both say credentials are never logged. Both are sentences in
 * a document, and a sentence has never stopped anybody adding a `Log::debug`
 * while chasing a failing checkout. This is the same posture as
 * {@see VatRateScanner}: turn the rule into a
 * test that fails on the shape rather than on the consequence.
 *
 * ## Three shapes, because they leak differently
 *
 * **A logged secret** — `$credential->credentials` or `->webhook_secret` inside
 * a `Log::`, `report()`, `dump()`, `dd()` or an exception constructor. This is
 * the one that actually happens, and the destination is a log aggregator that
 * outlives the incident.
 *
 * **A rendered secret** — a Filament `->default()`, `->formatStateUsing()`,
 * `->content()` or a Blade echo fed from either column. A credential on screen
 * is a credential in a screenshot in a support ticket, which is how the widest
 * distribution happens.
 *
 * **A revealable secret field** — Filament's `->revealable()` on a password
 * input bound to a credential path. It is one word, it looks like a kindness,
 * and it puts a live gateway key on a monitor in an office with a window.
 *
 * ## It errs towards false negatives, deliberately
 *
 * The same reasoning {@see VatRateScanner} gives: a lint that fires on innocent
 * code earns an exemption, and after a dozen of those it enforces nothing. It
 * matches the two column names next to a sink, not "anything that looks
 * secret" — so `$data->publicConfig` in a log line is not a finding, because it
 * is not one.
 */
final class CredentialLeakScanner
{
    /**
     * The two columns that are ciphertext at rest and must stay unread.
     *
     * `public_config` is deliberately absent: it is the half an operator reads
     * back on screen, and flagging it would make this lint fire on the feature.
     *
     * @var list<string>
     */
    private const SECRET_ACCESSORS = ['credentials', 'webhook_secret', 'webhookSecret'];

    /**
     * Calls that publish whatever they are handed.
     *
     * @var list<string>
     */
    private const SINKS = [
        'log::', 'logger(', 'report(', 'dump(', 'dd(', 'var_dump(', 'print_r(', 'ray(',
        'info(', 'throw new', 'sprintf(',
    ];

    /**
     * Form and table calls that put a value in front of somebody.
     *
     * @var list<string>
     */
    private const RENDERERS = [
        '->default(', '->formatstateusing(', '->content(', '->helpertext(', '->placeholder(',
        '->label(', '->tooltip(', '->description(',
    ];

    /**
     * @param  list<string>  $paths  relative to the project root
     * @return list<array{file: string, line: int, snippet: string, why: string}>
     */
    public static function scan(array $paths): array
    {
        $findings = [];

        foreach ($paths as $path) {
            $absolute = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

            if (! is_dir($absolute)) {
                continue;
            }

            foreach (Finder::create()->files()->in($absolute)->name(['*.php', '*.blade.php']) as $file) {
                $relative = $path . '/' . str_replace('\\', '/', $file->getRelativePathname());

                if (self::isExempt($relative)) {
                    continue;
                }

                foreach (self::findingsIn((string) file_get_contents($file->getRealPath())) as $finding) {
                    $findings[] = ['file' => $relative] + $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{line: int, snippet: string, why: string}>
     */
    public static function findingsIn(string $source): array
    {
        $findings = [];

        foreach (explode("\n", $source) as $index => $line) {
            $lower = mb_strtolower($line);
            $trimmed = ltrim($lower);

            // A docblock describing the rule is not a breach of it. This file
            // and every model docblock in the project name these columns.
            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $reason = self::reasonFor($lower);

            if ($reason !== null) {
                $findings[] = ['line' => $index + 1, 'snippet' => trim($line), 'why' => $reason];
            }
        }

        foreach (self::revealableFindings($source) as $finding) {
            $findings[] = $finding;
        }

        usort($findings, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $findings;
    }

    /**
     * `->revealable()` on a field bound to a credential — found per *statement*,
     * not per line.
     *
     * The lesson {@see LiteralScanner} already learned and
     * wrote down: Pint wraps a fluent chain the moment it passes the line limit,
     * so the realistic shape is a `TextInput::make()` on one line, `->password()`
     * on the next and `->revealable()` on a third — and a per-line check sees
     * three lines, none of which contains both halves. A rule that only fires on
     * unwrapped code is a rule that stops working the first time somebody runs
     * the formatter, which is on every commit.
     *
     * The field is its own sink here, so unlike the checks above this one needs
     * no `Log::` or `->default()` nearby: revealing is the leak.
     *
     * @return list<array{line: int, snippet: string, why: string}>
     */
    private static function revealableFindings(string $source): array
    {
        $findings = [];
        $offset = 0;

        // Comments first, and this is not fastidiousness — the very first thing
        // this rule flagged was the comment in `Integrations::providerFields()`
        // saying *never* to use `->revealable()` here. A lint that fires on the
        // note explaining the lint is a lint somebody deletes.
        $source = self::withoutComments($source);

        foreach (explode(';', $source) as $statement) {
            $lower = mb_strtolower($statement);

            if (str_contains($lower, '->revealable(') && self::mentionsSecret($lower)) {
                $position = (int) mb_strpos($lower, '->revealable(');

                $findings[] = [
                    'line' => mb_substr_count(mb_substr($source, 0, $offset + $position), "\n") + 1,
                    'snippet' => trim(preg_replace('/\s+/u', ' ', $statement) ?? $statement),
                    'why' => 'a revealable form field bound to a credential',
                ];
            }

            // +1 for the `;` that `explode` consumed, so line numbers stay true.
            $offset += mb_strlen($statement) + 1;
        }

        return $findings;
    }

    /**
     * Blank out comments, preserving byte offsets and newlines.
     *
     * Replaced with spaces rather than removed, so a finding after a long
     * docblock still reports the line it is actually on — the same approach
     * {@see LiteralScanner} takes, for the same reason.
     */
    private static function withoutComments(string $source): string
    {
        return (string) preg_replace_callback(
            '~/\*.*?\*/|//[^\n]*~s',
            static fn (array $match): string => (string) preg_replace('/[^\n]/u', ' ', $match[0]),
            $source,
        );
    }

    private static function reasonFor(string $lowerLine): ?string
    {
        if (! self::mentionsSecret($lowerLine)) {
            return null;
        }

        // A redaction is the fix, not the breach — a line that names a secret
        // column *and* the redaction constant is doing the right thing.
        if (str_contains($lowerLine, 'redacted')) {
            return null;
        }

        foreach (self::SINKS as $sink) {
            if (str_contains($lowerLine, $sink)) {
                return "a credential passed to a sink ({$sink})";
            }
        }

        foreach (self::RENDERERS as $renderer) {
            if (str_contains($lowerLine, $renderer)) {
                return "a credential rendered to the operator ({$renderer})";
            }
        }

        // A Blade echo of either column.
        if (preg_match('/\{\{.*?(credentials|webhook_secret).*?\}\}/u', $lowerLine) === 1) {
            return 'a credential echoed from Blade';
        }

        return null;
    }

    /**
     * Does this line read one of the two encrypted columns?
     *
     * Property or array access only — `->credentials`, `['credentials']`,
     * `'credentials'` as a key. The bare word appears in prose and in class
     * names (`IntegrationCredential`) constantly, and matching it would make
     * every finding noise.
     */
    private static function mentionsSecret(string $lowerLine): bool
    {
        foreach (self::SECRET_ACCESSORS as $accessor) {
            $quoted = preg_quote($accessor, '/');

            // The fourth alternative is the Filament field path — a quoted
            // `credentials.secret_key` — which is neither a property read nor an
            // array key, and is exactly what a revealable input is bound to.
            $pattern = '/->' . $quoted . '\b'
                . '|\[[\'"]' . $quoted . '[\'"]\]'
                . '|[\'"]' . $quoted . '[\'"]\s*=>'
                . '|[\'"]' . $quoted . '\./u';

            if (preg_match($pattern, $lowerLine) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Files that legitimately name these columns beside something that looks
     * like a sink.
     *
     * Only this scanner, its fixture, and the two places the columns are
     * *defined* — the cast list and the migration, neither of which reads a
     * value. An exemption anywhere else is a request to log a credential.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'tests/Support/Secrets/CredentialLeakScanner.php',
        'tests/Support/Secrets/Fixtures/LeakedCredentials.php',
    ];

    private static function isExempt(string $relative): bool
    {
        return in_array($relative, self::EXEMPT, true);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
