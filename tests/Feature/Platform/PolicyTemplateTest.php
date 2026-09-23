<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\Role;
use App\Filament\App\Pages\Setup;
use App\Models\CancellationPolicy;
use App\Models\PolicyTemplate;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The cancellation ladders offered at setup (Mike, 2026-09-23)
|--------------------------------------------------------------------------
|
| *«να μπορώ να τις επεξεργαστώ ως admin τις επιλογές που τους δίνω»*. They were
| three hard-coded presets: the numbers in `Setup::presetLadder()`, the names and
| the printed ladder in `lang/{el,en}/setup.php`, the list itself in a `const`.
|
| Two properties carry the design, and both are asserted here.
|
| **Platform-owned.** An operator never reads the table, only the cards drawn
| from it — so the screen is super-admin's and `/app` cannot reach it.
|
| **Choosing copies.** The operator's policy is a row of their own from that
| moment. Editing a template afterwards must not reach back into terms already
| shown to a guest and emailed to them, and that is the assertion that would
| matter most if this were ever refactored into a foreign key.
|
*/

/** A super-admin, as the platform panel resolves one. */
function platformAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('lets a super-admin maintain the ladders and keeps operators out', function (): void {
    PolicyTemplate::factory()->create(['code' => 'flexible']);

    actingAs(platformAdmin())->get('/admin/policy-templates')->assertSuccessful();

    // TEN-8 by way of the policy: an operator has no business in the platform's
    // menu, and `viewAny` returning false is what keeps the resource out of
    // `/app`'s routes as well as its navigation.
    actingAs(OperatorUser::withRole(Role::Owner))->get('/admin/policy-templates')->assertForbidden();
})->group('fast');

it('offers the ladders in the order the platform arranged, and hides a retired one', function (): void {
    PolicyTemplate::factory()->create(['code' => 'second', 'sort_order' => 20]);
    PolicyTemplate::factory()->create(['code' => 'first', 'sort_order' => 10]);
    PolicyTemplate::factory()->retired()->create(['code' => 'withdrawn', 'sort_order' => 5]);

    $owner = OperatorUser::withRole(Role::Owner);
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        $offered = Livewire::test(Setup::class)->instance()->policyTemplates();

        // The retired one is gone even though its sort order puts it first —
        // an operator who took it keeps their copy, nobody new is offered it.
        expect($offered->pluck('code')->all())->toBe(['first', 'second']);
    });
})->group('fast');

it('writes the ladder as a copy, so editing the template later changes nothing', function (): void {
    $template = PolicyTemplate::factory()->create([
        'code' => 'standard',
        'name' => ['el' => 'Κανονική', 'en' => 'Standard'],
        'free_cancellation_hours' => null,
        'tiers' => [['days_before' => 7, 'refund_percent' => 100]],
    ]);

    $owner = OperatorUser::withRole(Role::Owner);
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($template): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::CANCELLATION)
            ->call('choosePreset', 'standard')
            ->call('continue');

        // The platform changes its mind about what it offers…
        $template->update([
            'name' => ['el' => 'Αλλαγμένη', 'en' => 'Changed'],
            'tiers' => [['days_before' => 1, 'refund_percent' => 10]],
        ]);

        // …and the operator's own terms do not move. Their guests have already
        // been sent these in a confirmation email.
        $policy = CancellationPolicy::query()->with('tiers')->sole();

        expect($policy->getTranslation('name', 'el'))->toBe('Κανονική')
            ->and($policy->tiers->map(fn ($tier): array => [$tier->days_before, $tier->refund_percent])->all())
            ->toBe([[7, 100]]);
    });
})->group('fast');

it('prints the ladder from the numbers, so the card cannot promise something else', function (): void {
    // The failure this replaces: the numbers lived in `Setup::presetLadder()`
    // and the words in a lang file, and nothing held them together. A card
    // could say «full refund at 7 days» while the policy written behind it said
    // 14, and no test would have noticed.
    $template = PolicyTemplate::factory()->create([
        'free_cancellation_hours' => 48,
        // Deliberately entered out of order: the admin repeater lets rows be
        // dragged, and a ladder typed bottom-up must still read top-down.
        'tiers' => [
            ['days_before' => 2, 'refund_percent' => 25],
            ['days_before' => 7, 'refund_percent' => 50],
        ],
    ]);

    $lines = $template->ladder('en');

    expect(array_column($lines, 'refund'))->toBe(['100%', '50%', '25%', '0%'])
        ->and($lines[0]['when'])->toContain('48')
        ->and($lines[1]['when'])->toContain('7')
        ->and($lines[2]['when'])->toContain('2')
        // Always a last line: "and nothing after that" is the half of a
        // cancellation policy people actually get wrong.
        ->and($lines[3]['when'])->toBe(__('setup.policy.ladder.after', [], 'en'));
})->group('fast');

it('writes nothing when the platform has retired every ladder', function (): void {
    // Not a hypothetical: `is_active` is one toggle. Inventing a fallback
    // policy here would put terms on an operator's trips that the platform
    // deliberately stopped standing behind — better a step with nothing in it.
    PolicyTemplate::factory()->retired()->create();

    $owner = OperatorUser::withRole(Role::Owner);
    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Livewire::test(Setup::class)
            ->set('step', SetupChecklist::CANCELLATION)
            ->call('continue');

        expect(CancellationPolicy::query()->count())->toBe(0);
    });
})->group('fast');
