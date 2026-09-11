<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Branding\Actions\ResetBrandProfile;
use App\Domain\Branding\Actions\UpdateBrandProfile;
use App\Domain\Branding\Actions\UploadBrandAsset;
use App\Domain\Branding\Support\ContrastChecker;
use App\Enums\BrandAsset;
use App\Enums\FontSource;
use App\Enums\NotificationTemplate;
use App\Enums\WidgetTheme;
use App\Exceptions\UploadRefused;
use App\Filament\Forms\TranslatableInput;
use App\Models\Booking;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\Tenant;
use App\Observers\TenantObserver;
use App\Rules\HexColor;
use App\Rules\SocialLinks;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The branding screen on `/app` (spec BRD-1 … BRD-7, SEC-3, I18N-1).
 *
 * ## A Page and not a Resource
 *
 * There is exactly one brand profile per tenant and it always exists (BRD-3),
 * so a resource would give an operator a list with one row in it, a create
 * button that must be disabled and a delete button that must be refused —
 * three affordances that all have to be taken away again. A page edits *the*
 * profile, which is what the operator thinks they are doing.
 *
 * ## Thin, like every other Filament class here (CNV-5)
 *
 * The three things that are not form affordances — the contrast recomputation,
 * the CSS sanitisation, and what an upload does to the disk — are in
 * {@see UpdateBrandProfile}, {@see BrandProfile::customCss()} and
 * {@see UploadBrandAsset}. `PATCH /api/v1/branding` will call the same Actions
 * and cannot reach a different conclusion.
 *
 * ## The preview, deferred from #17 and landed in #110
 *
 * BRD-4's **live widget and email preview** needed a widget to preview, so #17
 * shipped everything else — the contrast warning, the logo upload with its
 * automatic resize, reset to defaults — and left this. The widget exists now,
 * so {@see self::previewState()} embeds the real one.
 */
class Branding extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.app.pages.branding';

    /**
     * The form state.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?BrandProfile $profile = null;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('branding.nav');
    }

    public function getTitle(): string
    {
        return __('branding.title');
    }

    public function getSubheading(): ?string
    {
        return __('branding.subtitle');
    }

    /**
     * SEC-3: crew never reach this page, and Filament asks *before* rendering it.
     *
     * A page has no model to hang a policy on, so Filament falls back to
     * allowing it — the same silent default `PolicyCoverageTest` exists to catch
     * for resources. Both hooks are needed: `canAccess` keeps the item out of
     * the navigation and refuses the URL, and {@see mount()} refuses again for
     * the case where a link was already open when a role changed.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', BrandProfile::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->profile = static::currentProfile();

        $this->getForm('form')?->fill([
            ...$this->profile->only([
                'color_primary', 'color_secondary', 'color_accent', 'color_background', 'color_text',
                'font_family', 'font_source', 'button_radius_px', 'widget_theme',
                'logo_light_path', 'logo_dark_path', 'favicon_path', 'email_header_image_path',
            ]),
            // The **raw** column, not the accessor. An operator editing their
            // stylesheet has to see what they wrote: handing them the sanitised
            // version would silently rewrite their file the next time they
            // pressed save, and they would have no way to tell what changed.
            'custom_css' => $this->profile->rawCustomCss(),
            'email_footer_text' => $this->profile->getTranslations('email_footer_text'),
            'social_links' => $this->profile->social_links,
        ]);
    }

    /**
     * The tenant's profile, guaranteed to exist.
     *
     * `firstOrCreate` is a safety net for a tenant that predates
     * {@see TenantObserver} — there are none in production, but a
     * page whose entire premise is "this row always exists" should not be the
     * thing that 500s if it ever does not.
     */
    public static function currentProfile(): BrandProfile
    {
        return BrandProfile::query()->firstOrCreate(
            ['tenant_id' => Tenancy::id()],
            BrandProfile::platformDefaults(),
        );
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->formSchema())
            ->statePath('data');
    }

    /** @return array<int, Component> */
    protected function formSchema(): array
    {
        return [
            Section::make(__('branding.sections.colors'))
                ->schema([
                    $this->color('color_primary'),
                    $this->color('color_secondary'),
                    $this->color('color_accent'),
                    $this->color('color_background'),
                    $this->color('color_text'),
                ])
                ->columns(2),

            Section::make(__('branding.sections.typography'))
                ->schema([
                    TextInput::make('font_family')
                        ->label(__('branding.form.font_family.label'))
                        ->helperText(__('branding.form.font_family.help'))
                        ->required()
                        ->maxLength(80),

                    Select::make('font_source')
                        ->label(__('branding.form.font_source.label'))
                        ->helperText(__('branding.form.font_source.help'))
                        ->options(FontSource::options())
                        ->required()
                        ->native(false),

                    TextInput::make('button_radius_px')
                        ->label(__('branding.form.button_radius_px.label'))
                        ->helperText(__('branding.form.button_radius_px.help'))
                        ->integer()
                        ->required()
                        // 0-32 from §2.2. The column is a tinyint and tops out
                        // at 255, so the range is enforced here rather than
                        // there — a column that cannot express a rejected value
                        // is a column that turns a typo into a truncation.
                        ->minValue(0)
                        ->maxValue(32)
                        ->suffix('px')
                        ->live(debounce: 400)
                        ->afterStateUpdated(fn () => $this->previewChanged()),

                    Select::make('widget_theme')
                        ->label(__('branding.form.widget_theme.label'))
                        ->helperText(__('branding.form.widget_theme.help'))
                        ->options(WidgetTheme::options())
                        ->required()
                        ->native(false),
                ])
                ->columns(2),

            Section::make(__('branding.sections.logos'))
                ->schema([
                    $this->upload(BrandAsset::LogoLight, 'logo_light'),
                    $this->upload(BrandAsset::LogoDark, 'logo_dark'),
                    $this->upload(BrandAsset::Favicon, 'favicon'),
                    $this->upload(BrandAsset::EmailHeader, 'email_header'),
                ])
                ->columns(2),

            Section::make(__('branding.sections.contact'))
                ->schema([
                    ...array_map(
                        fn (string $key): Component => TextInput::make("social_links.{$key}")
                            ->label(__("branding.form.social.{$key}"))
                            ->helperText($key === SocialLinks::PHONE_KEY ? __('branding.form.social.help') : null)
                            ->maxLength(255)
                            // Per key, so a malformed WhatsApp number marks the
                            // WhatsApp box rather than the first one on screen.
                            // The whole-set form of the rule is what the Action
                            // and the API use: only it can see an unknown key,
                            // and the form has no way to produce one.
                            ->rules([SocialLinks::forKey($key)]),
                        SocialLinks::KEYS,
                    ),

                    TranslatableInput::textarea(
                        'email_footer_text',
                        __('branding.form.email_footer_text.label'),
                        __('branding.form.email_footer_text.help'),
                        rows: 3,
                    ),
                ])
                ->columns(2),

            Section::make(__('branding.sections.advanced'))
                ->schema([
                    Textarea::make('custom_css')
                        ->label(__('branding.form.custom_css.label'))
                        ->helperText(__('branding.form.custom_css.help'))
                        ->rows(8)
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ];
    }

    /** One colour field, with the §2.2 shape enforced in the operator's language. */
    private function color(string $name): Component
    {
        return ColorPicker::make($name)
            ->label(__("branding.form.{$name}.label"))
            ->helperText(__("branding.form.{$name}.help"))
            ->required()
            ->rules([new HexColor])
            // BRD-4's "as they type", debounced: a colour picker being dragged
            // emits a value per frame, and a round trip per frame would make the
            // preview slower than saving.
            ->live(debounce: 400)
            ->afterStateUpdated(fn () => $this->previewChanged());
    }

    /**
     * One upload slot.
     *
     * `saveUploadedFileUsing` is the whole point: Filament would otherwise
     * write the file itself, which would skip the magic-byte check, the SVG
     * sanitiser, the EXIF strip and the variants — every part of BRD-7 and
     * SEC-13. The file goes to {@see UploadBrandAsset} instead, and what comes
     * back is the path the column stores.
     *
     * `acceptedFileTypes` and `maxSize` are affordances, not the check. They
     * stop a 40 MB video before it is uploaded, which is a kindness; the
     * decision is made from the bytes on the server, where an attacker cannot
     * reach it.
     */
    private function upload(BrandAsset $asset, string $translationKey): Component
    {
        return FileUpload::make($asset->column())
            ->label(__("branding.form.{$translationKey}.label"))
            ->helperText(__("branding.form.{$translationKey}.help"))
            ->disk((string) config('kaiki.branding.uploads.disk'))
            ->visibility('private')
            ->acceptedFileTypes((array) config('kaiki.branding.uploads.mime_types'))
            ->maxSize((int) config('kaiki.branding.uploads.max_kilobytes'))
            ->image()
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) use ($asset): ?string {
                try {
                    return app(UploadBrandAsset::class)($this->requireProfile(), $asset, $file);
                } catch (UploadRefused $refused) {
                    // A refusal is a sentence for the operator, not a 500. The
                    // notification carries the reason — the size, the format,
                    // or what was wrong with the SVG — because "upload failed"
                    // is not something anyone can act on.
                    Notification::make()
                        ->title($refused->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }
            })
            ->deleteUploadedFileUsing(function () use ($asset): void {
                app(UploadBrandAsset::class)->remove($this->requireProfile(), $asset);
            });
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('branding.actions.save'))
                ->action('save'),

            Action::make('reset')
                ->label(__('branding.actions.reset'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('branding.actions.reset_heading'))
                ->modalDescription(__('branding.actions.reset_description'))
                ->modalSubmitActionLabel(__('branding.actions.reset_confirm'))
                ->action('resetToDefaults'),
        ];
    }

    public function save(): void
    {
        $profile = $this->requireProfile();

        abort_unless(Gate::allows('update', $profile), 403);

        $state = (array) $this->getForm('form')?->getState();

        // `SocialLinks::clean()` before the Action, not inside it: an operator
        // clearing a box means "I no longer have one", and the column should
        // hold links that exist rather than a map of empty strings the hosted
        // footer has to test for. The per-key rules on the inputs have already
        // refused anything malformed by the time this runs.
        app(UpdateBrandProfile::class)($profile, [
            ...$state,
            'social_links' => SocialLinks::clean((array) ($state['social_links'] ?? [])),
        ]);

        Notification::make()
            ->title(__('branding.actions.saved'))
            ->success()
            ->send();
    }

    public function resetToDefaults(): void
    {
        $profile = $this->requireProfile();

        abort_unless(Gate::allows('update', $profile), 403);

        app(ResetBrandProfile::class)($profile);

        // Re-read, because reset writes columns the form is showing.
        $this->profile = $profile->refresh();
        $this->mount();

        // And the preview moves with it. The widget is behind `wire:ignore`, so
        // a re-render leaves its shadow root holding the colours the operator
        // just discarded unless it is told.
        $this->previewChanged();

        Notification::make()
            ->title(__('branding.actions.reset_done'))
            ->success()
            ->send();
    }

    /**
     * Everything BRD-4's live preview needs, resolved server-side (#110).
     *
     * ## The real widget, and no credential to draw it with
     *
     * The preview embeds the actual bundle rather than a picture of one — a
     * hand-made preview is a second implementation of the widget's appearance
     * and is wrong the first time either changes. But a plaintext publishable
     * key is never stored (only its hash, prefix and last four), so there is
     * nothing here to authenticate a fetch with, and minting a real key to look
     * at a colour would be creating a credential for a decoration.
     *
     * So the widget's **preview transport** answers from this payload instead:
     * the operator's own branding and a couple of their own trips, already on
     * the page. Same bundle, same components, same shadow root.
     *
     * @return array{properties: array<string, string>, bundle: string, key: string, email: string, payload: array{branding: array<string, mixed>, products: list<array<string, mixed>>}}
     */
    public function previewState(): array
    {
        $state = $this->previewFormState();
        $profile = $this->requireProfile();
        $properties = $this->previewProperties($state, $profile);

        return [
            'properties' => $properties,
            // The **alias**, not a versioned path: the panel should show what
            // operators are actually running (ADR-0011).
            'bundle' => url('/widget/kaiki-widget.js'),
            // The bundle wants a `data-key` to consider itself configured at
            // all. This one is never sent anywhere — the preview transport
            // answers before a request is made — and it is not a key shape the
            // API would accept if it were.
            'key' => 'pk_preview',
            'email' => $this->emailPreview($properties),
            'payload' => [
                'branding' => $this->previewBranding($state, $profile->font_family),
                'products' => $this->previewProducts(),
            ],
        ];
    }

    /**
     * The unsaved form state, or nothing if the form has not been built yet.
     *
     * `getState()` validates, and a half-typed hex colour is invalid by
     * construction — an operator is *always* mid-edit while a live preview is
     * the point. So this reads the raw state and lets the fallbacks below
     * handle whatever is not yet a colour.
     *
     * @return array<string, mixed>
     */
    protected function previewFormState(): array
    {
        return $this->data;
    }

    /**
     * The operator's unsaved choices as the custom properties WGT-9 fixes.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    protected function previewProperties(array $state, BrandProfile $profile): array
    {
        $defaults = (array) config('kaiki.branding.defaults.colors', []);

        // A field the operator has cleared, or is halfway through typing, falls
        // back to what is saved rather than to nothing: a preview that flashes
        // black every time somebody selects the text in a colour field is a
        // preview they will turn off.
        $colour = static function (string $field, string $fallback) use ($state, $profile): string {
            $typed = $state[$field] ?? null;

            if (is_string($typed) && preg_match('/^#[0-9A-Fa-f]{6}$/', $typed) === 1) {
                return $typed;
            }

            $saved = $profile->{$field};

            return is_string($saved) && $saved !== '' ? $saved : $fallback;
        };

        $radius = $state['button_radius_px'] ?? null;

        return [
            '--kaiki-primary' => $colour('color_primary', (string) ($defaults['primary'] ?? '')),
            '--kaiki-secondary' => $colour('color_secondary', (string) ($defaults['secondary'] ?? '')),
            '--kaiki-accent' => $colour('color_accent', (string) ($defaults['accent'] ?? '')),
            '--kaiki-background' => $colour('color_background', (string) ($defaults['background'] ?? '')),
            '--kaiki-text' => $colour('color_text', (string) ($defaults['text'] ?? '')),
            '--kaiki-radius' => (is_numeric($radius) ? (int) $radius : $profile->button_radius_px) . 'px',
        ];
    }

    /**
     * Tell the already-mounted widget about a colour that changed.
     *
     * Only the properties travel. Re-rendering the page would tear the widget's
     * shadow root down and build it again on every keystroke — which is why the
     * embed sits behind `wire:ignore` — so the live half of BRD-4 is six CSS
     * variables on the host element and nothing else.
     */
    public function previewChanged(): void
    {
        $this->dispatch(
            'branding-changed',
            properties: $this->previewProperties($this->previewFormState(), $this->requireProfile()),
        );
    }

    /**
     * A couple of the operator's own trips, for the list mount to draw.
     *
     * Their own rather than invented ones: a preview showing "Sample trip
     * &euro;99" tells an operator nothing about how their catalogue will look,
     * and the first thing they check is whether their longest title fits.
     *
     * @return list<array<string, mixed>>
     */
    protected function previewProducts(): array
    {
        return Product::query()
            ->sellable()
            ->with('meetingPoint')
            ->orderBy('sort_order')
            ->limit(2)
            ->get()
            ->map(static fn (Product $product): array => [
                'uuid' => $product->uuid,
                'slug' => $product->slug,
                'title' => (string) $product->title,
                'summary' => $product->summary,
                'category' => $product->category->value,
                'mode' => $product->mode->value,
                'duration_minutes' => $product->duration_minutes,
                'from_price_cents' => $product->price_from_cents,
                'from_price_formatted' => $product->price_from_cents === null
                    ? null
                    : MoneyFormatter::format($product->price_from_cents, app()->getLocale(), MoneyFormatter::currency()),
                'booking_url' => null,
                'meeting_point' => $product->meetingPoint === null ? null : ['name' => (string) $product->meetingPoint->name],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function previewBranding(array $state, ?string $storedFont): array
    {
        return [
            // Deliberately empty. The widget writes what this returns into
            // `:host` inside the shadow root, and the panel writes the
            // operator's *unsaved* choices onto the host element itself — where
            // an inline declaration outranks a `:host` rule. Sending saved
            // colours here as well would mean the preview races itself.
            'colors' => [],
            'font' => [
                'family' => is_string($state['font_family'] ?? null) ? $state['font_family'] : $storedFont,
                // Never a third-party request from inside the panel (WGT-10).
                'css_url' => null,
            ],
        ];
    }

    /**
     * The email half of BRD-4 — the real template, with the unsaved colours.
     *
     * Same argument as the widget: `mail.booking.html` is what a guest receives,
     * so it is what an operator should be shown. A second template built to look
     * like the first is a promise that they stay in step, and they will not.
     *
     * The booking is constructed and never saved. It exists to give the template
     * the four values it prints — a reference, a name, a date and a balance —
     * and writing a row into the database to render a picture of one would be
     * worse than inventing the values.
     *
     * Rendered into an `srcdoc` iframe by the view, because this is a whole
     * document: a doctype, a table layout and inline styles, all of which would
     * fight the panel's stylesheet if they were inlined into the page.
     *
     * @param  array<string, string>  $properties
     */
    protected function emailPreview(array $properties): string
    {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            return '';
        }

        $brand = app(GetBrandPayload::class)($tenant, app()->getLocale());
        // The one value the template actually paints with, replaced by what the
        // operator has typed but has not yet saved.
        $brand['colors']['primary'] = $properties['--kaiki-primary'];

        $booking = new Booking([
            'reference' => 'KAI-2026-0001',
            'guest_name' => __('branding.preview.guest'),
            'local_date' => now()->addDays(9)->startOfDay(),
            'local_time' => '10:00:00',
            'balance_cents' => 12_000,
            'manage_token' => str_repeat('0', 32),
        ]);

        return view('mail.booking.html', [
            'booking' => $booking,
            'template' => NotificationTemplate::BookingConfirmed,
            'brand' => $brand,
            'extra' => [],
        ])->render();
    }

    /**
     * The stored contrast results, rendered for the badge (BRD-5).
     *
     * Read from the column rather than recomputed, so what an operator sees is
     * the number that was true when they last saved — the same number the API
     * would report, and the same number a support ticket would quote.
     *
     * @return list<array{label: string, message: string, passes: bool}>
     */
    public function contrastResults(): array
    {
        $labels = [
            ContrastChecker::BODY_TEXT => __('branding.contrast.body_text'),
            ContrastChecker::BUTTON_TEXT => __('branding.contrast.button_text'),
        ];

        $rows = [];

        foreach ($this->requireProfile()->contrast_warnings as $key => $result) {
            $rows[] = [
                'label' => $labels[$key] ?? $key,
                'message' => __($result['passes'] ? 'branding.contrast.pass' : 'branding.contrast.fail', [
                    'ratio' => number_format($result['ratio'], 2),
                    'threshold' => number_format($result['threshold'], 1),
                ]),
                'passes' => $result['passes'],
            ];
        }

        return $rows;
    }

    private function requireProfile(): BrandProfile
    {
        return $this->profile ??= static::currentProfile();
    }
}
