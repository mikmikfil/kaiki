<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Actions\IssueInvoice;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Filament\App\Resources\InvoiceResource;
use App\Filament\App\Resources\InvoiceResource\Pages\ListInvoices;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The invoices screen — spec MYD-5, MYD-10, MYD-11, TEN-8
|--------------------------------------------------------------------------
|
| Read-only, and more strictly than the vouchers screen: an invoice is a
| document in a state tax register, `InvoicePolicy::delete()` returns false for
| everyone including the owner, and there is no form and no create page.
|
| The one screen behaviour worth a test of its own is **MYD-11's warning**. An
| operator who believes they are filing documents and is not finds out from an
| accountant months later, and the cost of that discovery is measured in years
| of trading.
|
*/

/** @return array{0: Tenant, 1: User} */
function invoiceScreenFixture(): array
{
    $owner = OperatorUser::withRole(Role::Owner);

    return [Tenant::query()->findOrFail($owner->tenant_id), $owner];
}

function invoiceScreen(User $user): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(ListInvoices::class);
}

function invoiceFor(Tenant $tenant, string $environment = 'dev', ?InvoiceStatus $status = null): Invoice
{
    return Tenancy::forTenant($tenant, function () use ($environment, $status): Invoice {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 10_000,
            'price_snapshot' => ['vat' => ['rate_bp' => 1_300, 'vat_category' => 'VAT_2', 'net_cents' => 8_850, 'vat_cents' => 1_150]],
        ]);

        $invoice = app(IssueInvoice::class)($booking);
        $invoice->forceFill(['environment' => $environment])->save();

        if ($status !== null && $status !== InvoiceStatus::Pending) {
            app(AllocateInvoiceNumber::class)($invoice);
            $invoice->forceFill(['status' => $status])->save();
        }

        return $invoice->refresh();
    });
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
    Queue::fake();
});

it('opens for an owner and is closed to crew', function (): void {
    // Money, so `ViewFinancials` — the same gate as the dashboard's two figures.
    [$tenant, $owner] = invoiceScreenFixture();
    $crew = OperatorUser::withRole(Role::Crew);

    $access = static function (User $user): bool {
        tenancy()->initialize($user->tenant);
        Auth::login($user);

        return InvoiceResource::canAccess();
    };

    expect($access($owner))->toBeTrue()
        ->and($access($crew))->toBeFalse();
})->group('fast');

it('warns when a document went to the test endpoint', function (): void {
    // MYD-11. The warning appears only when it is true, because a permanent
    // banner is furniture and one that appears is a fact.
    [$tenant, $owner] = invoiceScreenFixture();

    invoiceFor($tenant, 'dev', InvoiceStatus::Sent);

    invoiceScreen($owner)->assertSee(__('mydata.environment.warning'));
})->group('fast');

it('says nothing when everything went to the real endpoint', function (): void {
    [$tenant, $owner] = invoiceScreenFixture();

    invoiceFor($tenant, 'live', InvoiceStatus::Sent);

    invoiceScreen($owner)->assertDontSee(__('mydata.environment.warning'));
})->group('fast');

it('does not warn about a document that has not gone anywhere yet', function (): void {
    // A pending document is about to be sent, correctly, to whichever endpoint
    // is configured. Warning about it would be noise.
    [$tenant, $owner] = invoiceScreenFixture();

    invoiceFor($tenant, 'dev', InvoiceStatus::Pending);

    invoiceScreen($owner)->assertDontSee(__('mydata.environment.warning'));
})->group('fast');

it('does not warn one operator about another’s sandbox', function (): void {
    [$tenant, $owner] = invoiceScreenFixture();
    [$other] = invoiceScreenFixture();

    invoiceFor($other, 'dev', InvoiceStatus::Sent);

    invoiceScreen($owner)->assertDontSee(__('mydata.environment.warning'));
})->group('fast');

it('offers a retry only on a document that gave up', function (): void {
    // A `pending` one is coming back on its own, and a second job for it would
    // take a second number.
    [$tenant, $owner] = invoiceScreenFixture();

    $failed = invoiceFor($tenant, 'dev', InvoiceStatus::Failed);
    $pending = invoiceFor($tenant, 'dev', InvoiceStatus::Pending);
    $sent = invoiceFor($tenant, 'dev', InvoiceStatus::Sent);

    invoiceScreen($owner)
        ->assertTableActionVisible('retry', $failed)
        ->assertTableActionHidden('retry', $pending)
        ->assertTableActionHidden('retry', $sent);
})->group('fast');

it('puts a failed document back in the queue and back to pending', function (): void {
    // Back to `pending` because the sender ignores anything else — which is
    // what stops a registered document being sent twice.
    [$tenant, $owner] = invoiceScreenFixture();

    $failed = invoiceFor($tenant, 'dev', InvoiceStatus::Failed);

    invoiceScreen($owner)->callTableAction('retry', $failed);

    expect(Tenancy::forTenant($tenant, fn (): InvoiceStatus => $failed->refresh()->status))
        ->toBe(InvoiceStatus::Pending);

    Queue::assertPushed(SubmitInvoiceToMyData::class);
})->group('fast');

it('counts the documents that gave up on the navigation item', function (): void {
    // The one thing on this screen that needs noticing without opening it: an
    // invoice AADE refused is money the operator's books do not yet reflect.
    [$tenant, $owner] = invoiceScreenFixture();

    tenancy()->initialize($tenant);
    Auth::login($owner);

    expect(InvoiceResource::getNavigationBadge())->toBeNull();

    invoiceFor($tenant, 'dev', InvoiceStatus::Failed);

    expect(InvoiceResource::getNavigationBadge())->toBe('1');
})->group('fast');

it('shows a pending document as having no number rather than as a blank', function (): void {
    // An empty cell in a numbered series reads as data loss.
    [$tenant, $owner] = invoiceScreenFixture();

    invoiceFor($tenant, 'dev', InvoiceStatus::Pending);

    invoiceScreen($owner)->assertSee(__('mydata.table.no_number'));
})->group('fast');
