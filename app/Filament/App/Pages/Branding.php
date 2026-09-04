<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Branding\Actions\ResetBrandProfile;
use App\Domain\Branding\Actions\UpdateBrandProfile;
use App\Domain\Branding\Actions\UploadBrandAsset;
use App\Domain\Branding\Support\ContrastChecker;
use App\Enums\BrandAsset;
use App\Enums\FontSource;
use App\Enums\WidgetTheme;
use App\Exceptions\UploadRefused;
use App\Filament\Forms\TranslatableInput;
use App\Models\BrandProfile;
use App\Observers\TenantObserver;
use App\Rules\HexColor;
use App\Rules\SocialLinks;
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
 * ## Out of scope here, on purpose
 *
 * BRD-4's **live widget and email preview** needs the widget, so it lands in M3
 * with it (#17 says so). What BRD-4 asks for that does *not* need the widget —
 * the contrast warning, logo upload with automatic resize, and reset to
 * defaults — is here.
 */
class Branding extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

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
                        ->suffix('px'),

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
            ->rules([new HexColor]);
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

        Notification::make()
            ->title(__('branding.actions.reset_done'))
            ->success()
            ->send();
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
