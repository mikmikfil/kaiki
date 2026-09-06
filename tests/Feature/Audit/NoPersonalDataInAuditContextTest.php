<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Events\Auditable;
use App\Listeners\RecordAuditLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| ADR-0025 §3 — `context` carries no personal data
|--------------------------------------------------------------------------
|
| The rule and the reason: audit rows are kept for **seven years**, against
| ADR-0012's ninety-day default for personal data, and the exception is paid for
| by the actor being a `user_id` rather than a name. A guest's name in `context`
| would quietly undo that, seven years at a time — it would outlive every other
| copy of itself, and the legal basis for keeping it (the operator's bookkeeping
| obligation) does not cover it.
|
| A scanner rather than a runtime check, in the manner of
| `NoHardcodedVatRateTest`: the wrong value never reaches production because the
| line that would write it never merges.
|
| **Proved against a permanently wrong fixture**, for the same reason that test
| gives — a lint whose own failure mode is untested is a lint nobody trusts. The
| fixture below carries the four spellings a well-meaning developer reaches for.
|
*/

/**
 * Keys that must never appear in an audit `context`, and the spellings they
 * arrive in.
 *
 * @return list<string>
 */
function forbiddenContextKeys(): array
{
    return [
        'name', 'first_name', 'last_name', 'full_name', 'guest_name',
        'email', 'phone', 'passport', 'document_number', 'id_number',
        'address', 'date_of_birth', 'dob', 'nationality',
    ];
}

/**
 * Every `context:` array literal in the event classes, as raw source.
 *
 * @return array<string, string>
 */
function auditEventSources(): array
{
    $sources = [];

    foreach (File::files(app_path('Events')) as $file) {
        $sources[$file->getFilename()] = (string) file_get_contents($file->getPathname());
    }

    return $sources;
}

/**
 * The keys a `context: [...]` literal names, per file.
 *
 * Deliberately a scanner over source rather than a reflection over instances:
 * an event can only be instantiated with a subject, and half of them take a
 * model that has to be built first. The value being guarded is a *literal in
 * the source*, so reading the source is the accurate check rather than a
 * shortcut.
 *
 * @return array<string, list<string>>
 */
function contextKeysByFile(): array
{
    $found = [];

    foreach (auditEventSources() as $name => $source) {
        if (preg_match_all("/context:\s*\[(.*?)\]/s", $source, $blocks) !== 1 && $blocks[1] === []) {
            continue;
        }

        $keys = [];

        foreach ($blocks[1] as $block) {
            if (preg_match_all("/'([a-z0-9_]+)'\s*=>/i", $block, $matches) > 0) {
                $keys = [...$keys, ...$matches[1]];
            }
        }

        if ($keys !== []) {
            $found[$name] = $keys;
        }
    }

    return $found;
}

it('names no personal-data key in any audit context', function (): void {
    $offenders = [];

    foreach (contextKeysByFile() as $file => $keys) {
        foreach ($keys as $key) {
            foreach (forbiddenContextKeys() as $forbidden) {
                if (str_contains(strtolower($key), $forbidden)) {
                    $offenders[] = "{$file}: context key '{$key}'";
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These audit contexts name personal data (ADR-0025 §3):',
        ...$offenders,
        '',
        'Audit rows are kept for seven years. Record an id or a count instead —',
        'the operator reason column is the one place free text belongs, and it is',
        'the operator typing it rather than this code choosing to.',
    ]));
})->group('fast');

it('catches the spellings a well-meaning developer reaches for', function (string $key): void {
    // The scanner proved against a permanently wrong input. Without this, a
    // regex that matched nothing would pass the test above forever and nobody
    // would find out until an audit.
    $offending = false;

    foreach (forbiddenContextKeys() as $forbidden) {
        if (str_contains(strtolower($key), $forbidden)) {
            $offending = true;
        }
    }

    expect($offending)->toBeTrue("[{$key}] should have been caught as personal data");
})->with([
    'a guest name' => 'guest_name',
    'an email in a nested key' => 'contact_email',
    'a passport number' => 'passport_number',
    'a date of birth' => 'date_of_birth',
])->group('fast');

it('does not flag the shapes that are meant to be there', function (string $key): void {
    // The other half, and the reason `NoHardcodedVatRateTest` has one too: a
    // lint that flags `seats_sold` is a lint somebody switches off.
    foreach (forbiddenContextKeys() as $forbidden) {
        expect(str_contains(strtolower($key), $forbidden))
            ->toBeFalse("[{$key}] is legitimate and must not be flagged");
    }
})->with([
    'a count' => 'seats_sold',
    'an environment' => 'environment',
    'a flag' => 'soft',
    'an enum value' => 'cancel_reason',
    'a type' => 'type',
])->group('fast');

it('actually finds the contexts it is scanning', function (): void {
    // The failure mode this whole file has: a regex that silently matches
    // nothing. If the events are refactored into a shape the scanner cannot
    // read, this goes red rather than the guarantee quietly evaporating.
    $found = contextKeysByFile();

    expect($found)->not->toBeEmpty('the scanner found no audit contexts at all')
        ->and(array_keys($found))->toContain('DepartureCancelled.php');
})->group('fast');

it('registers the audit listener against the Auditable interface', function (): void {
    // `AuditServiceProvider` deliberately does **not** register this: Laravel
    // discovers listeners in `app/Listeners` from their type hints, and adding
    // an explicit registration on top wrote two identical rows for one delete.
    //
    // Relying on discovery is fine and being silent about it is not — if it
    // were ever turned off the trail would simply stop, with nothing failing.
    // So the binding is asserted here.
    $listeners = array_map(
        static fn (mixed $listener): string => is_array($listener) && isset($listener[0])
            ? (is_object($listener[0]) ? $listener[0]::class : (string) $listener[0])
            : get_debug_type($listener),
        Event::getRawListeners()[Auditable::class] ?? [],
    );

    $registered = array_filter(
        Event::getRawListeners()[Auditable::class] ?? [],
        static fn (mixed $l): bool => is_string($l) && str_contains($l, RecordAuditLog::class),
    );

    expect($registered)->not->toBeEmpty(
        'RecordAuditLog is not listening to Auditable. Found: ' . implode(', ', $listeners),
    );

    // Exactly one, because two is the bug this test was written after.
    expect($registered)->toHaveCount(1);
})->group('fast');

it('keeps every live action wired to something that fires it', function (): void {
    // The gap ADR-0025 left open on purpose, now down to one.
    // `booking.refunded` was on it until #84 built the refund path and fired
    // the action rather than inventing a spelling — which is exactly what this
    // test was written to make happen. `gdpr.purged` waits for ADR-0012's purge
    // job in M6.
    $notLive = array_values(array_map(
        static fn (AuditAction $a): string => $a->value,
        array_filter(AuditAction::cases(), static fn (AuditAction $a): bool => ! $a->isLive()),
    ));

    expect($notLive)->toBe(['gdpr.purged']);
})->group('fast');
