<?php

declare(strict_types=1);

use App\Domain\Operations\Support\Manifest;
use App\Enums\BookingStatus;
use App\Enums\ManifestColumn;
use App\Enums\Role;
use App\Enums\TripQuestionScope;
use App\Enums\TripQuestionType;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\QuestionsRelationManager;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TripQuestion;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| The operator's own questions, per trip (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| Yes/no, a choice or a line of text; per person or per booking; required or
| not. Answered at checkout, kept with a copy of the question, and shown on the
| booking, the manifest, the boarding list and the exports.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    WebhookScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A two-person draft on a trip with no documents, asking «Μεταφορά;» once and
 * «Μέγεθος στολής» of everybody.
 *
 * @return array{0: Tenant, 1: Booking, 2: TripQuestion, 3: TripQuestion}
 */
function questionDraft(): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    [$transfer, $size] = Tenancy::forTenant($tenant, static function () use ($booking): array {
        $booking->forceFill([
            'status' => BookingStatus::Draft,
            'paid_cents' => 0,
            'hold_expires_at' => now()->addMinutes(15),
        ])->save();

        return [
            TripQuestion::factory()->create(['product_id' => $booking->product_id]),
            TripQuestion::factory()->sizePerPerson()->create(['product_id' => $booking->product_id, 'sort_order' => 1]),
        ];
    });

    return [$tenant, $booking->refresh(), $transfer, $size];
}

it('lets the operator add a choice question on the trip, one choice per line', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $product = Product::factory()->create();

    Livewire::actingAs($owner)
        ->test(QuestionsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->callTableAction('create', data: [
            'label' => ['el' => 'Μέγεθος στολής', 'en' => 'Wetsuit size'],
            'type' => TripQuestionType::Choice->value,
            'options_el' => "Μικρό\nΜεγάλο",
            'options_en' => "Small\nLarge",
            'scope' => TripQuestionScope::PerPerson->value,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->assertHasNoTableActionErrors();

    $question = $product->questions()->firstOrFail();

    expect($question->options)->toBe([['el' => 'Μικρό', 'en' => 'Small'], ['el' => 'Μεγάλο', 'en' => 'Large']])
        ->and($question->scope)->toBe(TripQuestionScope::PerPerson)
        ->and($question->is_required)->toBeTrue();
})->group('fast');

it('asks the questions at checkout, per booking and in each passenger panel', function (): void {
    [, $booking, $transfer, $size] = questionDraft();

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('name="answers[booking][' . $transfer->uuid . ']"', escape: false)
        ->assertSee('name="answers[guests][0][' . $size->uuid . ']"', escape: false)
        ->assertSee('name="answers[guests][1][' . $size->uuid . ']"', escape: false)
        // No documents on this trip: the panels hold a name and the question.
        ->assertDontSee('name="guests[0][document_number]"', escape: false);
})->group('fast');

it('refuses a missing required answer and keeps the ones given, with the question as asked', function (): void {
    [$tenant, $booking, $transfer, $size] = questionDraft();

    $form = [
        'guest_name' => 'Μαρία Παπαδοπούλου',
        'guest_email' => 'maria@example.com',
        'terms' => '1',
        'guests' => [
            ['position' => 1, 'full_name' => 'Μαρία Παπαδοπούλου'],
            ['position' => 2, 'full_name' => 'Νίκος Παπαδόπουλος'],
        ],
        'answers' => [
            'booking' => [$transfer->uuid => 'yes'],
            'guests' => [[$size->uuid => '0'], [$size->uuid => '1']],
        ],
    ];

    $missing = $form;
    unset($missing['answers']['guests'][1]);

    post('/c/' . $booking->manage_token, $missing)->assertSessionHasErrors('answers.guests.1.' . $size->uuid);

    post('/c/' . $booking->manage_token, $form)->assertSessionHasNoErrors()->assertRedirect();

    Tenancy::forTenant($tenant, static function () use ($booking, $size): void {
        expect(BookingAnswer::query()->where('booking_id', $booking->getKey())->count())->toBe(3);

        // Renamed afterwards: the answer still reads as it was asked.
        $size->forceFill(['label' => ['el' => 'Νούμερο', 'en' => 'Size']])->save();

        $manifest = Manifest::forBooking($booking->refresh(), [ManifestColumn::FullName, ManifestColumn::Answers]);
        $cells = array_column($manifest->rows, 'answers');

        expect($cells[0])->toContain(__('questions.answer.yes', [], 'el'))
            ->and($cells[0])->toContain('Μέγεθος στολής: Μικρό')
            ->and($cells[1])->toBe('Μέγεθος στολής: Μεγάλο');
    });
})->group('fast');
