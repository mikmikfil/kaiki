<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;
use Tests\Support\Stress\ButtonSweep;
use Tests\Support\Stress\DirtyOperator;

/*
|--------------------------------------------------------------------------
| Every button, once, as every role, over the same half-built account
|--------------------------------------------------------------------------
|
| `PanelSweepTest` opens every screen. It cannot see what happens next, and on
| 2026-09-23 that is where the bug was: answering the last row of «Χρειάζονται
| προσοχή» made the dashboard's *next* Livewire request a 403 — Filament asks
| `canView()` again on every request, and the widget's answer depended on the
| row that had just gone. The browser showed a black error box.
|
| So this mounts every page, resource page, relation manager and widget the
| panel registers — discovered from the panel, so a new screen is covered the
| day it is added — and does what a person would: lets it poll, presses every
| button it renders (open, submit as it stands, cancel), and, for a screen
| whose visibility depends on data, empties that data under it and polls again.
| See {@see ButtonSweep} for the rules.
|
| Nothing leaves the process: mail, queues, HTTP and storage are faked, and
| every press is rolled back before the next.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-08 10:00:00');

    Mail::fake();
    Queue::fake();
    Http::fake();
    Storage::fake('local');
    Storage::fake('public');

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/** Sweep a list of components as one person and print what it did. */
function pressEverything(Tenant $tenant, Role $role, callable $each): ButtonSweep
{
    $user = OperatorUser::withRole($role, $tenant);

    actingAs($user);
    tenancy()->initialize($tenant);

    $sweep = new ButtonSweep($user, $role->value, $tenant, verbose: (bool) getenv('STRESS_VERBOSE'));
    $each($sweep);

    return $sweep;
}

function pressReport(string $label, ButtonSweep ...$sweeps): void
{
    $tally = [];
    $notes = [];
    $latent = [];
    foreach ($sweeps as $sweep) {
        foreach ($sweep->tally as $k => $n) {
            $tally[$k] = ($tally[$k] ?? 0) + $n;
        }
        $notes = [...$notes, ...$sweep->notes];
        $latent = [...$latent, ...$sweep->latentFindings];
    }

    fwrite(STDERR, sprintf(
        "\n%s: %s\n%s",
        $label,
        implode(', ', array_map(static fn (string $k, int $n): string => "{$k} {$n}", array_keys($tally), $tally)),
        $notes === [] ? '' : '  ' . implode("\n  ", array_unique($notes)) . "\n",
    ));

    if ($latent !== []) {
        fwrite(STDERR, "  Latent, not failed on:\n    " . implode("\n    ", $latent) . "\n");
    }
}

it('presses every button of every /app screen as every role without a 403 or a 500', function (): void {
    $dirty = DirtyOperator::create();
    $components = ButtonSweep::discover('app', $dirty);

    expect($components)->not->toBeEmpty();

    $sweeps = [];
    foreach ([Role::Owner, Role::Manager, Role::Crew] as $role) {
        $sweeps[] = pressEverything($dirty, $role, static function (ButtonSweep $sweep) use ($components): void {
            foreach ($components as $component) {
                $sweep->sweep($component);
            }
        });
    }

    pressReport('/app button sweep', ...$sweeps);

    $findings = array_merge(...array_map(static fn (ButtonSweep $s): array => $s->findings, $sweeps));

    expect($findings)->toBe([], "\n" . implode("\n", $findings));
})->group('stress');

it('keeps every open dashboard widget answering while the account fills up', function (): void {
    // The other direction: `FirstSteps` shows on an empty account and hides
    // once there is a booking. The poll after the first booking lands is a
    // request like any other.
    $empty = Tenant::factory()->create(['name' => 'Brand New']);
    $widgets = array_values(array_filter(
        ButtonSweep::discover('app', $empty),
        static fn (array $c): bool => $c['kind'] === 'widget',
    ));

    $sweep = pressEverything($empty, Role::Owner, static function (ButtonSweep $sweep) use ($widgets, $empty): void {
        foreach ($widgets as $widget) {
            DB::beginTransaction();

            try {
                $sweep->sweepWhileFilling($widget, static fn () => DirtyOperator::fill($empty));
            } finally {
                DB::rollBack();
            }
        }
    });

    pressReport('/app widgets while filling', $sweep);

    expect($sweep->findings)->toBe([], "\n" . implode("\n", $sweep->findings));
})->group('stress');

it('presses every button of every /admin screen as the platform without a 403 or a 500', function (): void {
    $dirty = DirtyOperator::create();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $components = ButtonSweep::discover('admin', $dirty);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin);

    $sweep = new ButtonSweep($admin, 'platform', $dirty, verbose: (bool) getenv('STRESS_VERBOSE'));
    foreach ($components as $component) {
        $sweep->sweep($component);
    }

    pressReport('/admin button sweep', $sweep);

    expect($sweep->findings)->toBe([], "\n" . implode("\n", $sweep->findings));
})->group('stress');
