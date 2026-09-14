<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Branding\Actions\UploadPlatformAsset;
use App\Domain\Branding\Support\ContrastChecker;
use App\Enums\BrandAsset;
use App\Exceptions\UploadRefused;
use App\Filament\App\Pages\Branding;
use App\Models\PlatformBrand;
use App\Rules\HexColor;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Kaiki's own logo and colours, on `/admin`.
 *
 * ## A Page, like the operator's own branding screen
 *
 * There is exactly one platform brand and it always exists, so a resource would
 * give a list with one row, a create button that must be disabled and a delete
 * button that must be refused. A page edits *the* brand, which is what the
 * person opening it thinks they are doing — the same reasoning as
 * {@see Branding}.
 *
 * ## What it deliberately does not have
 *
 * No fonts, radii, social links, widget theme or custom CSS. Those exist on an
 * operator's profile because they shape *their public pages*. This shapes the
 * chrome of two admin panels and a sign-in screen, where a font choice is a
 * liability and a custom CSS box is an invitation.
 *
 * ## Thin (CNV-5)
 *
 * What an upload does to the disk is {@see UploadPlatformAsset}; whether two
 * colours can be read against each other is {@see ContrastChecker}. Nothing
 * here decides either.
 */
class PlatformBranding extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $slug = 'branding';

    protected static string $view = 'filament.admin.pages.platform-branding';

    protected static ?int $navigationSort = 80;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('platform_branding.nav');
    }

    public function getTitle(): string
    {
        return __('platform_branding.title');
    }

    public function getSubheading(): ?string
    {
        return __('platform_branding.subtitle');
    }

    public function mount(): void
    {
        // `getForm('form')` rather than `$this->form`: the property comes from
        // a Livewire trait and only exists at runtime, which static analysis is
        // right to object to. The operator's own branding screen resolves it
        // the same way.
        $this->getForm('form')?->fill(PlatformBrand::current()->only([
            'logo_light_path',
            'logo_dark_path',
            'favicon_path',
            'primary_color',
            'accent_color',
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make(__('platform_branding.sections.logo.heading'))
                    ->description(__('platform_branding.sections.logo.description'))
                    ->schema([
                        $this->upload(BrandAsset::LogoLight, 'logo_light'),
                        $this->upload(BrandAsset::LogoDark, 'logo_dark'),
                        $this->upload(BrandAsset::Favicon, 'favicon'),
                    ])
                    ->columns(3),

                Section::make(__('platform_branding.sections.colors.heading'))
                    ->description(__('platform_branding.sections.colors.description'))
                    ->schema([
                        $this->color('primary_color'),
                        $this->color('accent_color'),
                    ])
                    ->columns(2),
            ]);
    }

    /** One colour field, with the same hex shape the operator's screen enforces. */
    private function color(string $name): Component
    {
        return ColorPicker::make($name)
            ->label(__("platform_branding.form.{$name}.label"))
            ->helperText(__("platform_branding.form.{$name}.help"))
            ->required()
            ->rules([new HexColor]);
    }

    /**
     * One upload slot.
     *
     * `saveUploadedFileUsing` is the whole point, exactly as on the operator's
     * screen: Filament would otherwise write the file itself and skip the
     * magic-byte check, the SVG sanitiser, the EXIF strip and the variants. The
     * file goes to {@see UploadPlatformAsset} instead.
     *
     * `acceptedFileTypes` and `maxSize` are affordances, not the check — they
     * stop a large file before it is uploaded, which is a kindness. The decision
     * is made from the bytes on the server.
     */
    private function upload(BrandAsset $asset, string $translationKey): Component
    {
        return FileUpload::make($asset->column())
            ->label(__("platform_branding.form.{$translationKey}.label"))
            ->helperText(__("platform_branding.form.{$translationKey}.help"))
            ->disk((string) config('kaiki.branding.uploads.disk'))
            ->visibility('private')
            ->acceptedFileTypes((array) config('kaiki.branding.uploads.mime_types'))
            ->maxSize((int) config('kaiki.branding.uploads.max_kilobytes'))
            ->image()
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) use ($asset): ?string {
                try {
                    return app(UploadPlatformAsset::class)(PlatformBrand::current(), $asset, $file);
                } catch (UploadRefused $refused) {
                    // A refusal is a sentence, not a 500: the message carries
                    // the size, the format, or what was wrong with the SVG,
                    // because "upload failed" is not something anyone can act on.
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return null;
                }
            })
            ->deleteUploadedFileUsing(function () use ($asset): void {
                app(UploadPlatformAsset::class)->remove(PlatformBrand::current(), $asset);
            });
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('platform_branding.actions.save'))
                ->action('save'),

            Action::make('reset')
                ->label(__('platform_branding.actions.reset'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('platform_branding.actions.reset_heading'))
                ->modalDescription(__('platform_branding.actions.reset_description'))
                ->modalSubmitActionLabel(__('platform_branding.actions.reset_confirm'))
                ->action('resetToDefaults'),
        ];
    }

    public function save(): void
    {
        $state = $this->getForm('form')?->getState() ?? [];

        PlatformBrand::current()->update([
            'logo_light_path' => $state['logo_light_path'] ?? null,
            'logo_dark_path' => $state['logo_dark_path'] ?? null,
            'favicon_path' => $state['favicon_path'] ?? null,
            'primary_color' => $state['primary_color'],
            'accent_color' => $state['accent_color'],
        ]);

        Notification::make()->title(__('platform_branding.saved'))->success()->send();

        // The panels read these colours when they boot, so a saved change is
        // not on screen until the page is fetched again. Saying so is better
        // than the admin reloading twice to check whether it took.
        $this->redirect(static::getUrl());
    }

    /**
     * Back to the palette every operator starts from.
     *
     * The colours only. Deleting the logo is the upload slot's own button, and
     * a "reset" that silently threw away an uploaded file would be a
     * destructive action hiding inside a cosmetic one.
     */
    public function resetToDefaults(): void
    {
        $colors = (array) config('kaiki.branding.defaults.colors', []);

        PlatformBrand::current()->update([
            'primary_color' => (string) ($colors['primary'] ?? '#123A5E'),
            'accent_color' => (string) ($colors['accent'] ?? '#B5511F'),
        ]);

        Notification::make()->title(__('platform_branding.reset_done'))->success()->send();

        $this->redirect(static::getUrl());
    }

    /**
     * Whether the accent can be read against the primary.
     *
     * A warning and not a refusal, as on the operator's screen: the person
     * choosing is looking at it, and a palette this screen refused would be one
     * they could set from a database instead.
     */
    public function contrastWarning(): ?string
    {
        $brand = PlatformBrand::current();

        $ratio = ContrastChecker::ratio($brand->accent_color, $brand->primary_color);
        $threshold = (float) config('kaiki.branding.contrast.ui_component_ratio');

        return $ratio >= $threshold ? null : __('platform_branding.contrast_warning', [
            'ratio' => number_format($ratio, 2),
            'threshold' => number_format($threshold, 1),
        ]);
    }
}
