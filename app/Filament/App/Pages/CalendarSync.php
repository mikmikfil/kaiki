<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Jobs\SyncIcalSourceJob;
use App\Models\IcalFeed;
use App\Models\IcalSource;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Authorization\Capability;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * One screen for both directions of the calendar (spec OPS-13, OPS-14, OPS-15).
 *
 * ## Out and in belong together, even though they share no code
 *
 * The export publishes this operator's boats; the import pulls other people's
 * bookings in. Technically they have nothing in common — one renders a file,
 * the other polls a URL — but to an operator they are one question: *how does
 * my boat's calendar talk to the outside world?* Splitting them across two
 * screens means the person who set up half of it never finds the other half.
 *
 * ## The export URL is shown, and its danger is stated beside it
 *
 * The token in that address is the entire authentication (OPS-14), so the
 * screen has to say what the address exposes — busy periods and nothing else —
 * and offer to replace it. The replace action is destructive in a way that is
 * invisible: every subscriber breaks silently and immediately, and nothing on
 * anybody's screen says why. Hence the confirmation, which names that
 * consequence rather than asking "are you sure?".
 *
 * ## `ManageCatalogue`, not a capability of its own
 *
 * A calendar feed is a property of a vessel, and vessels are the catalogue.
 * Crew have `ViewDepartures` and not this — they read the calendar, they do not
 * decide who else may. A page has no model to hang a policy on and Filament
 * allows what nothing forbids, so access is asserted explicitly here and again
 * in every action, because a Livewire method is reachable without the page's
 * own render ever having been authorized.
 */
class CalendarSync extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.app.pages.calendar-sync';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('ical.nav');
    }

    public function getTitle(): string
    {
        return __('ical.title');
    }

    public function getSubheading(): ?string
    {
        return __('ical.subheading');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability(Capability::ManageCatalogue);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->getForm('form')?->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('vessel_id')
                    ->label(__('ical.import.name'))
                    ->options(fn (): array => $this->vessels()
                        ->mapWithKeys(static fn (Vessel $v): array => [$v->getKey() => $v->name])
                        ->all())
                    ->required(),

                TextInput::make('name')
                    ->label(__('ical.import.name'))
                    ->placeholder(__('ical.import.name_placeholder'))
                    ->maxLength(120)
                    ->required(),

                TextInput::make('url')
                    ->label(__('ical.import.url'))
                    ->helperText(__('ical.import.url_help'))
                    // `url` rather than `text`: the browser's own validation
                    // catches the paste that lost its scheme before it becomes
                    // a failed sync fifteen minutes later.
                    ->url()
                    ->maxLength(1000)
                    ->required(),
            ])
            ->statePath('data');
    }

    /**
     * The vessels this operator can publish, each with its feed row.
     *
     * @return Collection<int, Vessel>
     */
    public function vessels(): Collection
    {
        return Vessel::query()->orderBy('name')->get();
    }

    /**
     * The export feed for a vessel, created on first sight.
     *
     * Lazily rather than by an observer on `vessels`, because a feed row is a
     * publishing decision and most operators never make it — minting one for
     * every boat at creation time fills the table with credentials nobody asked
     * for. It arrives inactive: a row exists so the screen has something to
     * show and rotate, and nothing is published until the operator says so.
     */
    public function feedFor(Vessel $vessel): IcalFeed
    {
        $feed = IcalFeed::query()->where('vessel_id', $vessel->getKey())->first();

        if ($feed instanceof IcalFeed) {
            return $feed;
        }

        return IcalFeed::query()->create([
            'vessel_id' => $vessel->getKey(),
            'token' => IcalFeed::generateToken(),
            'include_departures' => true,
            'include_blocks' => true,
            'include_guest_names' => false,
            'is_active' => false,
        ]);
    }

    public function feedUrl(IcalFeed $feed): string
    {
        return route('ical.feed', ['token' => $feed->token]);
    }

    /**
     * @return Collection<int, IcalSource>
     */
    public function sources(): Collection
    {
        return IcalSource::query()->with('vessel')->orderBy('id')->get();
    }

    public function togglePublish(int $feedId): void
    {
        abort_unless(static::canAccess(), 403);

        $feed = IcalFeed::query()->findOrFail($feedId);

        $feed->forceFill(['is_active' => ! $feed->is_active])->save();
    }

    public function toggleDepartures(int $feedId): void
    {
        abort_unless(static::canAccess(), 403);

        $feed = IcalFeed::query()->findOrFail($feedId);

        $feed->forceFill(['include_departures' => ! $feed->include_departures])->save();
    }

    public function toggleBlocks(int $feedId): void
    {
        abort_unless(static::canAccess(), 403);

        $feed = IcalFeed::query()->findOrFail($feedId);

        $feed->forceFill(['include_blocks' => ! $feed->include_blocks])->save();
    }

    /**
     * Mint a new token, breaking every existing subscriber.
     *
     * The whole point of the action, and the reason the confirmation says so in
     * words rather than asking whether the operator is sure.
     */
    public function rotate(int $feedId): void
    {
        abort_unless(static::canAccess(), 403);

        IcalFeed::query()->findOrFail($feedId)->rotateToken();

        Notification::make()->title(__('ical.export.rotated'))->success()->send();
    }

    public function addSource(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = (array) $this->getForm('form')?->getState();

        $url = trim((string) ($state['url'] ?? ''));

        try {
            $this->guardSource($url);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        IcalSource::query()->create([
            'vessel_id' => (int) ($state['vessel_id'] ?? 0),
            'name' => (string) ($state['name'] ?? ''),
            // The mutator writes `url_hash` alongside; see the model. A caller
            // that set the URL without it would write a row the unique index
            // cannot protect.
            'url' => $url,
            'is_active' => true,
            'sync_interval_minutes' => 15,
            'consecutive_failures' => 0,
            'events_imported' => 0,
        ]);

        $this->getForm('form')?->fill();

        Notification::make()->title(__('ical.import.added'))->success()->send();
    }

    /**
     * "Check now", for the operator who is standing there.
     *
     * Queued rather than run inline: the fetch reaches somebody else's server
     * with a fifteen-second timeout, and a panel request that waits on it is a
     * spinner the operator watches. The page polls, so the result arrives on
     * its own.
     */
    public function syncNow(int $sourceId): void
    {
        abort_unless(static::canAccess(), 403);

        $source = IcalSource::query()->findOrFail($sourceId);

        SyncIcalSourceJob::dispatch($source->getKey());

        Notification::make()->title(__('ical.import.queued'))->success()->send();
    }

    /**
     * Remove a source, and the blocks it created.
     *
     * The blocks go by the migration's `cascadeOnDelete` on `ical_source_id`
     * — **except** any that have been converted into a booking, which are
     * detached instead. OPS-15 protects those from a *sync*; deleting the
     * source is a different action, and destroying a block somebody has paid
     * against would free a boat that is not free.
     */
    public function removeSource(int $sourceId): void
    {
        abort_unless(static::canAccess(), 403);

        $source = IcalSource::query()->findOrFail($sourceId);

        VesselBlock::query()
            ->where('ical_source_id', $source->getKey())
            ->whereNotNull('booking_id')
            ->update(['ical_source_id' => null, 'external_uid' => null]);

        $source->delete();

        Notification::make()->title(__('ical.import.removed'))->success()->send();
    }

    /**
     * A URL we are willing to poll every fifteen minutes, for ever.
     *
     * The duplicate check is what `url_hash` exists for: the column is
     * encrypted and an encrypted column cannot be compared, so without the hash
     * the same feed added twice would double every imported event and make the
     * boat look busy for occupations that do not exist.
     *
     * @throws ValidationException
     */
    protected function guardSource(string $url): void
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['url' => __('ical.errors.invalid_url')]);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // `webcal://` is what several services hand you when you press
        // "subscribe", and it is just HTTPS wearing a hat — but our HTTP client
        // cannot speak it, so it is refused here with a message rather than
        // accepted and failed against fifteen minutes later.
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages(['url' => __('ical.errors.invalid_url')]);
        }

        $existing = IcalSource::query()
            ->where('url_hash', IcalSource::hashUrl($url))
            ->first();

        if ($existing instanceof IcalSource) {
            throw ValidationException::withMessages(['url' => __('ical.import.duplicate')]);
        }
    }
}
