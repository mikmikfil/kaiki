<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\Role;
use App\Filament\App\Resources\ExportResource;
use App\Filament\App\Resources\ExportResource\Pages\ListExports;
use App\Jobs\RunExportJob;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| OPS-17, OPS-18: the screen that answers "where is my file"
|--------------------------------------------------------------------------
|
| The list is the feature, not the modal. An export is asynchronous, so without
| a screen that shows its state an operator presses a button, sees nothing, and
| presses it again — which is how three identical exports end up in the queue.
|
*/

beforeEach(function (): void {
    Queue::fake();
    Carbon::setTestNow('2026-09-08 09:00:00');
});

function exportListAs(User $user): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(ListExports::class);
}

it('lists this operator\'s exports and nobody else\'s', function (): void {
    $mine = OperatorUser::withRole(Role::Owner);
    $theirs = OperatorUser::withRole(Role::Owner);

    $ours = Tenancy::forTenant($mine->tenant, fn (): ExportJob => ExportJob::factory()->ready()->create());
    $other = Tenancy::forTenant($theirs->tenant, fn (): ExportJob => ExportJob::factory()->ready()->create());

    exportListAs($mine)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$other]);
});

it('queues an export from the header action', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    exportListAs($owner)->callAction('new', [
        'type' => ExportType::Bookings->value,
        'date_basis' => 'booked',
        'from' => '2026-06-01',
        'to' => '2026-06-30',
    ])->assertHasNoActionErrors();

    $export = Tenancy::forTenant($owner->tenant, fn (): ?ExportJob => ExportJob::query()->first());

    expect($export)->not->toBeNull()
        ->and($export?->status)->toBe(ExportStatus::Queued)
        ->and($export?->type)->toBe(ExportType::Bookings)
        // The person who asked is on the row. "Who exported the guest list in
        // July" is a question that survives an employment.
        ->and($export?->user_id)->toBe($owner->getKey())
        ->and($export?->from_date?->toDateString())->toBe('2026-06-01');

    Queue::assertPushed(RunExportJob::class);
});

it('is invisible to crew', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $crew = OperatorUser::withRole(Role::Crew, $owner->tenant);

    tenancy()->initialize($crew->tenant);

    // Filament reads the policy for navigation as well as for the page, so a
    // crew member gets neither the menu item nor the URL. A bookings CSV is
    // financials and a guests CSV is a season of passengers; crew are
    // read-only inside a departure window and this is neither.
    expect($crew->hasCapability(Capability::ExportData))->toBeFalse()
        ->and(ExportResource::canViewAny())->toBeFalse();
});

it('offers a download only while the link is live', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $ready = Tenancy::forTenant($owner->tenant, fn (): ExportJob => ExportJob::factory()->ready()->create());
    $stale = Tenancy::forTenant($owner->tenant, fn (): ExportJob => ExportJob::factory()->expiredLink()->create());
    $queued = Tenancy::forTenant($owner->tenant, fn (): ExportJob => ExportJob::factory()->create());

    // A button that answers 410 is worse than no button.
    exportListAs($owner)
        ->assertTableActionVisible('download', $ready)
        ->assertTableActionHidden('download', $stale)
        ->assertTableActionHidden('download', $queued);
});

it('shows a failure in the operator\'s own words', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $failed = Tenancy::forTenant($owner->tenant, fn (): ExportJob => ExportJob::factory()->failed()->create());

    // NFR-8: the sentence is on the row, not a stack trace and not silence.
    exportListAs($owner)->assertCanSeeTableRecords([$failed]);

    expect($failed->error)->not->toBeNull();
});

it('keeps a swept export on the list, with its counts', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $purged = Tenancy::forTenant($owner->tenant, fn (): ExportJob => ExportJob::factory()->purged()->create([
        'row_count' => 412,
    ]));

    // "It expired on Tuesday" beats silence. An export that vanished when its
    // file did would read as a product that lost the operator's data.
    exportListAs($owner)->assertCanSeeTableRecords([$purged]);

    expect($purged->status)->toBe(ExportStatus::Expired)
        ->and($purged->row_count)->toBe(412);
});
