<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Domain\Import\Actions\CommitImport;
use App\Domain\Import\Actions\SaveImportMapping;
use App\Domain\Import\Actions\StartImport;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportRowType;
use App\Enums\ImportStatus;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Events\BookingConfirmed;
use App\Filament\App\Resources\ImportJobResource\Pages\ReviewImport;
use App\Jobs\CommitImportJob;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WebhookDelivery;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| SAA-13 … SAA-15, BKG-34 — a WooCommerce / YITH shop, brought over
|--------------------------------------------------------------------------
|
| The fixture shop has three booking products, a cap, and seven bookings: two
| on the same upcoming sailing, one private charter, one with no email, and
| three that must not arrive — one in the past, one cancelled, one for a trip
| the export does not contain. Every test starts from the dry run the operator
| would see, because that is the only way into a commit.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-09-11 08:00:00');
    Storage::fake('local');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: User, 2: Vessel} */
function importOperator(): array
{
    $tenant = Tenant::factory()->create();
    $vessel = Tenancy::forTenant($tenant, static fn (): Vessel => Vessel::factory()->capacity(40)->create(['name' => 'Αγία Ειρήνη']));

    return [$tenant, OperatorUser::withRole(Role::Owner, $tenant), $vessel];
}

/** The dry run, exactly as the upload modal starts it. */
function analysedImport(Tenant $tenant, User $owner, bool $withCsv = true): ImportJob
{
    $dir = 'imports/' . $tenant->getKey();
    Storage::disk('local')->put("{$dir}/shop.xml", (string) file_get_contents(base_path('tests/Fixtures/import/yith-products.xml')));
    Storage::disk('local')->put("{$dir}/bookings.csv", (string) file_get_contents(base_path('tests/Fixtures/import/yith-bookings.csv')));

    return Tenancy::forTenant($tenant, static fn (): ImportJob => app(StartImport::class)(
        'local',
        "{$dir}/shop.xml",
        $withCsv ? "{$dir}/bookings.csv" : null,
        $owner->getKey(),
    )->refresh());
}

function importRow(ImportJob $job, ImportRowType $type, string $sourceId): ImportJobRow
{
    return ImportJobRow::query()->where('import_job_id', $job->getKey())
        ->where('source_type', $type->value)->where('source_id', $sourceId)->firstOrFail();
}

function commitImport(Tenant $tenant, ImportJob $job): ImportJob
{
    return Tenancy::forTenant($tenant, static function () use ($job): ImportJob {
        app(CommitImport::class)($job->refresh());

        return $job->refresh();
    });
}

it('reads the files, proposes a mapping and writes nothing else', function (): void {
    [$tenant, $owner] = importOperator();

    $job = analysedImport($tenant, $owner);

    Tenancy::forTenant($tenant, function () use ($job): void {
        expect($job->status)->toBe(ImportStatus::MappingReview)
            ->and($job->is_dry_run)->toBeTrue()
            // SAA-14: nothing in the catalogue or the bookings yet.
            ->and(Product::query()->count())->toBe(0)
            ->and(Booking::query()->count())->toBe(0)
            ->and(Departure::query()->count())->toBe(0)
            ->and($job->stats['product'] ?? [])->toBe(['mapped' => 3, 'skipped' => 1])
            ->and($job->stats['booking'] ?? [])->toBe(['mapped' => 4, 'skipped' => 3])
            ->and($job->mapping['people_types']['57']['age_band_code'])->toBe('infant')
            ->and($job->mapping['products']['1600']['mode'])->toBe(BookingMode::PerVessel->value);
    });
})->group('fast');

it('says why every skipped record is skipped', function (): void {
    [$tenant, $owner] = importOperator();

    $job = analysedImport($tenant, $owner);

    $reason = static fn (ImportRowType $type, string $id): string => (string) (importRow($job, $type, $id)->messages[0]['key'] ?? '');

    Tenancy::forTenant($tenant, function () use ($reason, $job): void {
        expect($reason(ImportRowType::Product, '1700'))->toBe('imports.reasons.not_booking')
            ->and($reason(ImportRowType::Booking, '5503'))->toBe('imports.reasons.past')
            ->and($reason(ImportRowType::Booking, '5504'))->toBe('imports.reasons.status')
            ->and($reason(ImportRowType::Booking, '5505'))->toBe('imports.reasons.unknown_product');

        // In the operator's language, never as the key.
        app()->setLocale('el');
        $sentence = importRow($job, ImportRowType::Booking, '5503')->renderedMessages()[0];

        expect($sentence)->toContain('2026-08-15')->not->toStartWith('imports.');
    });
})->group('fast');

it('imports trips as drafts and the upcoming bookings as confirmed', function (): void {
    [$tenant, $owner, $vessel] = importOperator();

    $job = commitImport($tenant, analysedImport($tenant, $owner));

    Tenancy::forTenant($tenant, function () use ($job, $vessel): void {
        expect($job->status)->toBe(ImportStatus::Completed)
            ->and($job->is_dry_run)->toBeFalse();

        $cruise = Product::query()->findOrFail(importRow($job, ImportRowType::Product, '1421')->target_id);

        expect($cruise->status)->toBe(ProductStatus::Draft)
            ->and($cruise->getTranslation('title', 'el'))->toBe('Κρουαζιέρα στην Αίγινα')
            ->and($cruise->vessel_id)->toBe($vessel->getKey())
            ->and($cruise->duration_minutes)->toBe(480)
            ->and($cruise->ageBands()->pluck('code')->all())->toBe(['adult', 'child', 'infant'])
            ->and((bool) $cruise->ageBands()->where('code', 'infant')->value('counts_toward_capacity'))->toBeFalse();

        $prices = RatePlan::query()->where('product_id', $cruise->getKey())->firstOrFail()
            ->prices()->with('ageBand')->get()
            ->mapWithKeys(static fn ($p): array => [$p->ageBand->code => $p->price_cents])->all();

        expect($prices)->toBe(['adult' => 6500, 'child' => 3500, 'infant' => 0]);

        $charter = Product::query()->findOrFail(importRow($job, ImportRowType::Product, '1600')->target_id);

        expect($charter->mode)->toBe(BookingMode::PerVessel)
            ->and(RatePlan::query()->where('product_id', $charter->getKey())->value('vessel_price_cents'))->toBe(90000);

        $bookings = Booking::query()->get();

        expect($bookings)->toHaveCount(4)
            ->and($bookings->every(static fn (Booking $b): bool => $b->source === BookingSource::Import && $b->status === BookingStatus::Confirmed))->toBeTrue();

        $paid = Booking::query()->findOrFail(importRow($job, ImportRowType::Booking, '5501')->target_id);
        $unpaid = Booking::query()->findOrFail(importRow($job, ImportRowType::Booking, '5502')->target_id);

        expect($paid->total_cents)->toBe(16500)->and($paid->paid_cents)->toBe(16500)
            ->and($unpaid->paid_cents)->toBe(0)->and($unpaid->balance_cents)->toBe(13000)
            // Both on the same sailing, created once, holding both parties' seats.
            ->and($paid->departure_id)->toBe($unpaid->departure_id)
            ->and(Departure::query()->findOrFail($paid->departure_id)->seats_sold)->toBe(5)
            ->and($paid->price_snapshot['imported_from']['booking_id'])->toBe('5501');

        // The charter, at 10:00 Athens — 07:00 UTC in September.
        $charterBooking = Booking::query()->findOrFail(importRow($job, ImportRowType::Booking, '5506')->target_id);

        expect($charterBooking->starts_at_utc->toIso8601ZuluString())->toBe('2026-09-28T07:00:00Z');
    });

    // The uploaded files held every past customer; once imported, they go.
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->group('fast');

it('dispatches no notification, invoice or webhook for an imported booking', function (): void {
    [$tenant, $owner] = importOperator();

    $job = analysedImport($tenant, $owner);

    Event::fake([BookingConfirmed::class]);
    Mail::fake();
    Queue::fake();

    commitImport($tenant, $job);

    // BKG-34: nothing *tries*. Not "no email reached anyone" — no event, no
    // queued job, no invoice row, no webhook delivery.
    Event::assertNotDispatched(BookingConfirmed::class);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();

    Tenancy::forTenant($tenant, function (): void {
        expect(Booking::query()->count())->toBe(4)
            ->and(Invoice::query()->count())->toBe(0)
            ->and(WebhookDelivery::query()->count())->toBe(0);
    });
})->group('fast');

it('imports nothing twice, within one import or across two', function (): void {
    [$tenant, $owner] = importOperator();

    $first = commitImport($tenant, analysedImport($tenant, $owner));
    commitImport($tenant, $first);

    // The same shop uploaded again, as a second import.
    $second = commitImport($tenant, analysedImport($tenant, $owner));

    Tenancy::forTenant($tenant, function () use ($second): void {
        expect(Product::query()->count())->toBe(3)
            ->and(Booking::query()->count())->toBe(4)
            ->and($second->status)->toBe(ImportStatus::Completed)
            ->and(importRow($second, ImportRowType::Booking, '5501')->messages[0]['key'])->toBe('imports.notes.already_imported');
    });
})->group('fast');

it('resumes a stopped import and writes only what is missing', function (): void {
    [$tenant, $owner, $vessel] = importOperator();

    // The operator already has the sunset trip in Kaiki, so the dry run links
    // WooCommerce's to it instead of making a second. On the same boat: with a
    // second vessel the dry run would, correctly, ask which boat each new trip
    // sails on rather than guess.
    $sunset = Tenancy::forTenant($tenant, static function () use ($vessel): Product {
        $product = Product::factory()->draft()->create([
            'vessel_id' => $vessel->getKey(),
            'title' => ['el' => 'Ηλιοβασίλεμα στο Σούνιο', 'en' => 'Sunset at Sounion'],
            'duration_minutes' => 180,
        ]);

        app(SaveAgeBands::class)($product, [
            ['code' => 'adult', 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'], 'min_age' => 12, 'max_age' => null, 'counts_toward_capacity' => true, 'pricing_mode' => 'fixed', 'is_base' => true],
            ['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11, 'counts_toward_capacity' => true, 'pricing_mode' => 'fixed', 'is_base' => false],
        ]);

        return $product;
    });

    $job = analysedImport($tenant, $owner);

    expect($job->mapping['products']['1508'])->toBe(['action' => 'link', 'target_product_id' => $sunset->getKey()]);

    // It disappears between the review and the run.
    Tenancy::forTenant($tenant, static fn () => $sunset->delete());

    $stopped = commitImport($tenant, $job);

    Tenancy::forTenant($tenant, function () use ($stopped): void {
        expect($stopped->status)->toBe(ImportStatus::Failed)
            ->and($stopped->error_message)->not->toBeNull()
            ->and(importRow($stopped, ImportRowType::Product, '1508')->status)->toBe(ImportRowStatus::Failed)
            ->and(importRow($stopped, ImportRowType::Booking, '5507')->status)->toBe(ImportRowStatus::Failed)
            // Everything else landed.
            ->and(Booking::query()->count())->toBe(3);
    });

    Tenancy::forTenant($tenant, static fn () => $sunset->restore());

    $resumed = commitImport($tenant, $stopped);

    Tenancy::forTenant($tenant, function () use ($resumed): void {
        expect($resumed->status)->toBe(ImportStatus::Completed)
            ->and(Booking::query()->count())->toBe(4)
            ->and(Product::query()->count())->toBe(3);
    });
})->group('fast');

it('re-evaluates when the operator changes the mapping, still writing nothing', function (): void {
    [$tenant, $owner] = importOperator();

    $job = analysedImport($tenant, $owner);

    Tenancy::forTenant($tenant, function () use ($job): void {
        $job = app(SaveImportMapping::class)($job, ['products' => ['1421' => ['action' => 'skip']]]);

        expect(importRow($job, ImportRowType::Booking, '5501')->status)->toBe(ImportRowStatus::Skipped)
            ->and(importRow($job, ImportRowType::Booking, '5501')->messages[0]['key'])->toBe('imports.reasons.product_skipped')
            ->and(Product::query()->count())->toBe(0);
    });
})->group('fast');

it('starts the import from the review screen, off the request', function (): void {
    [$tenant, $owner] = importOperator();

    $job = analysedImport($tenant, $owner);

    Queue::fake();
    tenancy()->initialize($tenant);

    // The screen renders every trip and person type as a field, then the
    // button saves what is on the form and queues the run — nothing is
    // written in the request itself.
    Livewire::actingAs($owner)
        ->test(ReviewImport::class, ['record' => $job->uuid])
        ->assertFormFieldExists('products.1421.action')
        ->assertFormFieldExists('people_types.57.age_band_code')
        ->callAction('commit')
        ->assertHasNoActionErrors();

    Queue::assertPushed(CommitImportJob::class);

    expect($job->refresh()->status)->toBe(ImportStatus::Running)
        ->and(Product::query()->count())->toBe(0);
})->group('fast');

it('keeps two operators imports apart', function (): void {
    [$aegean, $maria] = importOperator();
    [$ionian, $elena] = importOperator();

    $mine = analysedImport($aegean, $maria);
    commitImport($ionian, analysedImport($ionian, $elena));

    Tenancy::forTenant($aegean, function () use ($mine): void {
        // Their import and its products are not visible here, and nothing of
        // theirs was linked into ours.
        expect(ImportJob::query()->pluck('id')->all())->toBe([$mine->getKey()])
            ->and(Product::query()->count())->toBe(0)
            ->and(collect($mine->mapping['products'])->pluck('action')->all())->not->toContain('link');
    });
})->group('fast');
