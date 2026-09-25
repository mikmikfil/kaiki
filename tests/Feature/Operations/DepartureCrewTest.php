<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\AssignDepartureCrew;
use App\Domain\Availability\Actions\AssignScheduleCrew;
use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Actions\SendCrewReminders;
use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\Manifest;
use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Enums\ManifestColumn;
use App\Enums\Role;
use App\Filament\App\Pages\Calendar;
use App\Mail\CrewAssignedMail;
use App\Mail\CrewReminderMail;
use App\Mail\CrewScheduleMail;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Captain and crew per departure — the 24/9 list, #3
|--------------------------------------------------------------------------
|
| The boat's `captain_name` is its usual skipper; today's may be someone else,
| with or without an account. The passenger list for the Λιμεναρχείο names
| today's captain and crew, and falls back to the boat's when nobody is set.
|
*/

it('prints the departure\'s captain and crew, and the boat\'s captain when none is set', function (): void {
    $captain = OperatorUser::withRole(Role::Crew);
    $deckhand = OperatorUser::withRole(Role::Crew, $captain->tenant);
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    Tenancy::forTenant($tenant, function () use ($captain, $deckhand): void {
        $vessel = Vessel::factory()->create(['captain_name' => 'Συνήθης Κυβερνήτης']);
        $departure = Departure::factory()->for($vessel)->at('2026-07-09', '09:00')->create();

        expect($departure->captainName())->toBe('Συνήθης Κυβερνήτης');

        app(AssignDepartureCrew::class)($departure, $captain->getKey(), 'Αγνοείται', [$deckhand->getKey(), $captain->getKey()]);

        $departure->refresh();
        $manifest = Manifest::forDeparture($departure, [ManifestColumn::FullName]);

        expect($departure->captain_name)->toBeNull()
            // The captain is not listed twice.
            ->and($departure->crew_user_ids)->toBe([(int) $deckhand->getKey()])
            ->and($manifest->header['captain'])->toBe($captain->name)
            ->and($manifest->header['crew'])->toBe($deckhand->name);

        // A freelance skipper with no account: the typed name.
        app(AssignDepartureCrew::class)($departure, null, 'Νίκος Ελεύθερος', []);

        expect($departure->refresh()->captainName())->toBe('Νίκος Ελεύθερος')
            ->and($departure->crew_user_ids)->toBeNull();
    });
})->group('fast');

it('refuses somebody from another operator', function (): void {
    $ours = OperatorUser::withRole(Role::Crew);
    $theirs = OperatorUser::withRole(Role::Crew);
    $tenant = Tenant::query()->findOrFail($ours->tenant_id);

    Tenancy::forTenant($tenant, function () use ($theirs): void {
        $departure = Departure::factory()->at('2026-07-09', '09:00')->create();

        expect(fn () => app(AssignDepartureCrew::class)($departure, $theirs->getKey(), null, []))
            ->toThrow(ValidationException::class);

        expect($departure->refresh()->captain_user_id)->toBeNull();
    });
})->group('fast');

it('refuses the same person on two departures at the same time', function (): void {
    // Mike, 2026-09-24: «not allowed».
    $captain = OperatorUser::withRole(Role::Crew);
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    Tenancy::forTenant($tenant, function () use ($captain): void {
        $morning = Departure::factory()->at('2026-07-09', '09:00', 240)->create();
        $clash = Departure::factory()->at('2026-07-09', '11:00', 120)->create();
        $later = Departure::factory()->at('2026-07-09', '15:00', 120)->create();

        app(AssignDepartureCrew::class)($morning, $captain->getKey(), null, []);

        expect(fn () => app(AssignDepartureCrew::class)($clash, null, null, [$captain->getKey()]))
            ->toThrow(ValidationException::class);

        app(AssignDepartureCrew::class)($later, $captain->getKey(), null, []);

        expect($later->refresh()->captain_user_id)->toBe((int) $captain->getKey());
    });
})->group('fast');

it('emails whoever is newly added, once, and nobody without an email', function (): void {
    Mail::fake();

    $captain = OperatorUser::withRole(Role::Crew);
    $offline = OperatorUser::withRole(Role::Crew, $captain->tenant);
    $offline->forceFill(['email' => null])->save();
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    Tenancy::forTenant($tenant, function () use ($captain, $offline): void {
        $departure = Departure::factory()->at('2026-07-09', '09:00')->create();

        app(AssignDepartureCrew::class)($departure, $captain->getKey(), null, [$offline->getKey()]);
        app(AssignDepartureCrew::class)($departure->refresh(), $captain->getKey(), null, [$offline->getKey()]);
    });

    Mail::assertQueued(CrewAssignedMail::class, 1);
    Mail::assertQueued(CrewAssignedMail::class, fn ($mail): bool => $mail->asCaptain && $mail->member->is($captain));
})->group('fast');

it('adds a person with no email to the team without inviting them', function (): void {
    // «Not everyone uses email» (Mike, 2026-09-24).
    Mail::fake();

    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), function () use ($owner): void {
        $person = app(InviteStaffMember::class)('Σταύρος Ναύτης', null, [Role::Crew], $owner);

        expect($person->email)->toBeNull()
            ->and($person->hasRole(Role::Crew))->toBeTrue();
    });

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->group('fast');

it('sets the crew once on the schedule, for its future departures, but not a day changed by hand', function (): void {
    // Mike, 2026-09-24: a schedule that makes 122 departures is where you say
    // who sails them. One email per person, not one per departure.
    Mail::fake();
    Carbon::setTestNow('2026-07-01 08:00:00');

    $captain = OperatorUser::withRole(Role::Crew);
    $deckhand = OperatorUser::withRole(Role::Crew, $captain->tenant);
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    Tenancy::forTenant($tenant, function () use ($captain, $deckhand): void {
        $rule = ScheduleRule::factory()->create();
        $first = Departure::factory()->at('2026-07-09', '09:00', 120)->create(['schedule_rule_id' => $rule->getKey()]);
        $second = Departure::factory()->at('2026-07-10', '09:00', 120)->create(['schedule_rule_id' => $rule->getKey()]);
        $byHand = Departure::factory()->at('2026-07-11', '09:00', 120)->create(['schedule_rule_id' => $rule->getKey(), 'crew_from_rule' => false, 'captain_name' => 'Άλλος']);

        app(AssignScheduleCrew::class)($rule, $captain->getKey(), null, [$deckhand->getKey()]);

        expect($first->refresh()->captain_user_id)->toBe((int) $captain->getKey())
            ->and($second->refresh()->crew_user_ids)->toBe([(int) $deckhand->getKey()])
            ->and($byHand->refresh()->captain_user_id)->toBeNull()
            ->and($byHand->captain_name)->toBe('Άλλος');
    });

    Mail::assertQueued(CrewScheduleMail::class, 2);
    Carbon::setTestNow();
})->group('fast');

it('reminds the crew once, 24 hours before, and nobody without an email', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-07-08 10:00:00');

    $captain = OperatorUser::withRole(Role::Crew);
    $offline = OperatorUser::withRole(Role::Crew, $captain->tenant);
    $offline->forceFill(['email' => null])->save();
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    $tomorrow = Tenancy::forTenant($tenant, function () use ($captain, $offline): Departure {
        $soon = Departure::factory()->at('2026-07-09', '09:00')->create(['captain_user_id' => $captain->getKey(), 'crew_user_ids' => [(int) $offline->getKey()]]);
        Departure::factory()->at('2026-07-12', '09:00')->create(['captain_user_id' => $captain->getKey()]);

        return $soon;
    });

    $reminders = app(SendCrewReminders::class);

    expect($reminders())->toBe(1)
        ->and($reminders())->toBe(0);

    Mail::assertQueued(CrewReminderMail::class, 1);
    expect(Tenancy::forTenant($tenant, fn () => $tomorrow->refresh()->crew_reminded_at))->not->toBeNull();

    Carbon::setTestNow();
})->group('fast');

it('lets an owner assign from the calendar, and gives crew no button for it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $captain = OperatorUser::withRole(Role::Crew, $owner->tenant);
    $departure = Tenancy::forTenant($owner->tenant, fn (): Departure => Departure::factory()->at(Carbon::now('Europe/Athens')->toDateString(), '18:00')->create());

    tenancy()->initialize($owner->tenant);
    Livewire\Livewire::actingAs($owner)->test(Calendar::class)
        ->assertSeeHtml('class="cal-assign"')
        ->callAction('assign', ['captain_user_id' => $captain->getKey(), 'crew_user_ids' => []], ['departure' => $departure->uuid])
        ->assertHasNoActionErrors();

    expect(Tenancy::forTenant($owner->tenant, fn (): ?int => Departure::query()->find($departure->getKey())?->captain_user_id))
        ->toBe((int) $captain->getKey());

    Livewire\Livewire::actingAs($captain)->test(Calendar::class)
        ->assertDontSeeHtml('class="cal-assign"');
})->group('fast');

it('asks for a captain once per schedule, and not when the boat has a usual one', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $items = Tenancy::forTenant($tenant, function () use ($tenant): array {
        $bare = Vessel::factory()->create(['captain_name' => null]);
        $skippered = Vessel::factory()->create(['captain_name' => 'Γιώργος']);
        $rule = ScheduleRule::factory()->create();
        $day = Carbon::now('Europe/Athens')->addDay();

        foreach ([0, 1, 2] as $offset) {
            Departure::factory()->for($bare)->at($day->copy()->addDays($offset)->toDateString(), '10:00')->create(['schedule_rule_id' => $rule->getKey()]);
        }
        Departure::factory()->for($skippered)->at($day->toDateString(), '12:00')->create();

        return array_values(array_filter(
            (new AttentionItems($tenant->timezone))->everything(),
            static fn ($item): bool => str_starts_with($item->key, 'captain:'),
        ));
    });

    expect($items)->toHaveCount(1)
        ->and($items[0]->detail)->toContain('2');
})->group('fast');

it('never asks an operator who does not record captains at all', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $items = Tenancy::forTenant($tenant, function () use ($tenant): array {
        Departure::factory()->for(Vessel::factory()->create(['captain_name' => null]))
            ->at(Carbon::now('Europe/Athens')->addDay()->toDateString(), '10:00')->create();

        return (new AttentionItems($tenant->timezone))->everything();
    });

    expect(array_filter($items, static fn ($item): bool => str_starts_with($item->key, 'captain:')))->toBe([]);
})->group('fast');

/*
| Crew typed by name (Mike, 2026-09-25: «στο πλήρωμα να μπορώ να βάλω και
| χειροκίνητα ονοματεπώνυμα») — the crew's `captain_name`.
*/

it('prints typed crew names after the staff crew, cleaned, and emails none of them', function (): void {
    Mail::fake();

    $captain = OperatorUser::withRole(Role::Crew);
    $deckhand = OperatorUser::withRole(Role::Crew, $captain->tenant);
    $tenant = Tenant::query()->findOrFail($captain->tenant_id);

    Tenancy::forTenant($tenant, function () use ($captain, $deckhand): void {
        $departure = Departure::factory()->at('2026-07-09', '09:00')->create();

        app(AssignDepartureCrew::class)(
            $departure,
            $captain->getKey(),
            null,
            [$deckhand->getKey()],
            ['  Σπύρος   Ναύτης ', '', 'Μαρία Κουκ', 'σπύρος ναύτης', str_repeat('Α', 200)],
        );

        $departure->refresh();
        $manifest = Manifest::forDeparture($departure, [ManifestColumn::FullName]);

        expect($departure->crew_names)->toBe(['Σπύρος Ναύτης', 'Μαρία Κουκ', str_repeat('Α', 120)])
            ->and($departure->crewNames())->toBe([$deckhand->name, 'Σπύρος Ναύτης', 'Μαρία Κουκ', str_repeat('Α', 120)])
            ->and($manifest->header['crew'])->toBe($deckhand->name . ', Σπύρος Ναύτης, Μαρία Κουκ, ' . str_repeat('Α', 120));

        // Typed names alone, with nobody chosen from the team.
        app(AssignDepartureCrew::class)($departure, null, 'Νίκος Ελεύθερος', [], ['Γιάννης']);

        expect(Manifest::forDeparture($departure->refresh(), [ManifestColumn::FullName])->header['crew'])->toBe('Γιάννης');

        // Null leaves them; an empty list clears them.
        app(AssignDepartureCrew::class)($departure, null, 'Νίκος Ελεύθερος', []);
        expect($departure->refresh()->crew_names)->toBe(['Γιάννης']);

        app(AssignDepartureCrew::class)($departure, null, 'Νίκος Ελεύθερος', [], []);
        expect($departure->refresh()->crew_names)->toBeNull()
            ->and(Manifest::forDeparture($departure, [ManifestColumn::FullName])->header['crew'])->toBeNull();
    });

    // The captain and the deckhand, once each — nobody typed.
    Mail::assertQueued(CrewAssignedMail::class, 2);
})->group('fast');

it('copies typed crew names from the schedule to its departures, but not to a day changed by hand', function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-07-01 08:00:00');

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    Tenancy::forTenant($tenant, function (): void {
        $rule = ScheduleRule::factory()->create();
        $follows = Departure::factory()->at('2026-07-09', '09:00', 120)->create(['schedule_rule_id' => $rule->getKey()]);
        $byHand = Departure::factory()->at('2026-07-10', '09:00', 120)->create(['schedule_rule_id' => $rule->getKey()]);

        // One day changed by hand: only that day.
        app(AssignDepartureCrew::class)($byHand, null, null, [], ['Μόνο Σήμερα']);

        app(AssignScheduleCrew::class)($rule, null, 'Κυβερνήτης Χωρίς Λογαριασμό', [], ['Σπύρος', ' Μαρία ']);

        expect($rule->refresh()->crew_names)->toBe(['Σπύρος', 'Μαρία'])
            ->and($follows->refresh()->crew_names)->toBe(['Σπύρος', 'Μαρία'])
            ->and($follows->crewNames())->toBe(['Σπύρος', 'Μαρία'])
            ->and($byHand->refresh()->crew_names)->toBe(['Μόνο Σήμερα'])
            ->and($byHand->crew_from_rule)->toBeFalse();

        // Saving the rule again without the field leaves the names as they are.
        app(AssignScheduleCrew::class)($rule, null, 'Κυβερνήτης Χωρίς Λογαριασμό', []);

        expect($rule->refresh()->crew_names)->toBe(['Σπύρος', 'Μαρία']);
    });

    Mail::assertNothingQueued();
    Carbon::setTestNow();
})->group('fast');

it('gives the departures a schedule makes its typed crew names', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $made = Tenancy::forTenant($tenant, function (): array {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'max_pax' => 12, 'min_pax' => 1, 'duration_minutes' => 120]);
        $rule = ScheduleRule::factory()->create([
            'product_id' => $product->getKey(),
            'weekday_mask' => WeekdayMask::fromDays([1]),
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-14',
            'captain_name' => 'Νίκος Ελεύθερος',
            'crew_names' => ['Σπύρος Ναύτης'],
        ]);

        app(GenerateDepartures::class)($rule, Carbon::parse('2026-05-01', 'Europe/Athens'));

        return Departure::query()->where('schedule_rule_id', $rule->getKey())->get()
            ->map(static fn (Departure $departure): array => [$departure->captainName(), $departure->crew_names, $departure->crew_from_rule])
            ->all();
    });

    expect($made)->toHaveCount(2)
        ->and($made[0])->toBe(['Νίκος Ελεύθερος', ['Σπύρος Ναύτης'], true]);
})->group('fast');

it('takes typed crew names in the calendar\'s «Ανάθεση»', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $departure = Tenancy::forTenant($owner->tenant, fn (): Departure => Departure::factory()->at(Carbon::now('Europe/Athens')->toDateString(), '18:00')->create());

    tenancy()->initialize($owner->tenant);
    Livewire\Livewire::actingAs($owner)->test(Calendar::class)
        ->callAction('assign', ['captain_user_id' => null, 'captain_name' => 'Νίκος', 'crew_user_ids' => [], 'crew_names' => ['Σπύρος Ναύτης']], ['departure' => $departure->uuid])
        ->assertHasNoActionErrors();

    expect(Tenancy::forTenant($owner->tenant, fn (): ?array => Departure::query()->find($departure->getKey())?->crew_names))
        ->toBe(['Σπύρος Ναύτης']);
})->group('fast');
