<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Domain\Branding\Actions\UpdateBrandProfile;
use App\Domain\Branding\Actions\UploadBrandAsset;
use App\Enums\BrandAsset;
use App\Exceptions\UploadRefused;
use App\Filament\App\Pages\Branding;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Rules\HexColor;
use App\Support\Tenancy;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;

/**
 * The operator's logo and colours, set by the platform (Mike, 2026-09-25).
 *
 * *«Την επιλογή χρωμάτων και logo για τον κάθε merchant την θέλω στο admin.
 * Αφαίρεσέ την από το first time conf. Και άσ' την και στα settings του
 * operator. Αλλά την αρχικοποίηση θέλω να την κάνω από το admin.»*
 *
 * So Create and Edit Merchant both ask, and the operator's own
 * {@see Branding} screen keeps asking too. **The same row, the same Actions**:
 * colours go through {@see UpdateBrandProfile}, which recomputes the contrast
 * warnings and clears the cached brand; logos go through
 * {@see UploadBrandAsset}, with the magic-byte check, the SVG sanitiser and
 * the variants (BRD-7, SEC-13), onto the same disk and into the same
 * `brand/{tenant}/…` directory. A logo set here is indistinguishable from one
 * the operator uploaded.
 *
 * ## Nothing is written until the save
 *
 * The uploads keep their temporary file (`storeFiles(false)`) and are handed to
 * the Action from {@see applyBranding()}. Two reasons. On Create there is no
 * brand profile to store into until the tenant exists. On Edit the save sits
 * behind a confirmation that asks for a reason (SEC-16), and a logo written the
 * moment it was dropped — or deleted the moment it was cleared, which is what
 * the operator's screen does — would be a platform change with no reason and
 * no audit row.
 *
 * ## Outside the tenant, on purpose
 *
 * `/admin` has no tenant context, and `BrandProfile` is tenant-scoped: a read
 * without one throws. Every read and write here runs inside
 * {@see Tenancy::forTenant()} for the merchant on screen, which is also what
 * makes it impossible to reach another merchant's row.
 */
trait HasTenantBrandingFields
{
    /**
     * The five colours of the operator's screen, in its order.
     *
     * @return list<string>
     */
    private static function brandColours(): array
    {
        return ['color_primary', 'color_secondary', 'color_accent', 'color_background', 'color_text'];
    }

    /**
     * The two logos. Not the favicon or the email header: those are details
     * the operator can add on their own screen, not what makes a page theirs.
     *
     * @return list<BrandAsset>
     */
    private static function brandLogos(): array
    {
        return [BrandAsset::LogoLight, BrandAsset::LogoDark];
    }

    /**
     * The section, under `branding.*` so none of its fields can be mistaken
     * for a column on `tenants`.
     */
    private function brandingSection(): Section
    {
        $colours = array_map(
            static fn (string $name): ColorPicker => ColorPicker::make($name)
                ->label(__("branding.form.{$name}.label"))
                ->helperText(__("branding.form.{$name}.help"))
                // The platform's palette for a new merchant; the edit page
                // fills the merchant's own over it.
                ->default((string) config('kaiki.branding.defaults.colors.' . substr($name, strlen('color_'))))
                ->required()
                ->rules([new HexColor]),
            self::brandColours(),
        );

        $logos = array_map(
            static fn (BrandAsset $asset): FileUpload => FileUpload::make($asset->column())
                ->label(__("branding.form.{$asset->value}.label"))
                ->helperText(__("branding.form.{$asset->value}.help"))
                // The operator screen's own settings, so the preview reads the
                // same disk and the same limits refuse the same files.
                ->disk((string) config('kaiki.branding.uploads.disk'))
                ->visibility('private')
                ->acceptedFileTypes((array) config('kaiki.branding.uploads.mime_types'))
                ->maxSize((int) config('kaiki.branding.uploads.max_kilobytes'))
                ->image()
                // Kept as the temporary file until the save. See the class
                // docblock.
                ->storeFiles(false),
            self::brandLogos(),
        );

        return Section::make(__('tenants.edit.branding'))
            ->description(__('tenants.edit.branding_help'))
            ->statePath('branding')
            ->schema([...$logos, ...$colours])
            ->columns(2);
    }

    /**
     * What the section opens on: the merchant's own logos and colours.
     *
     * @return array<string, string|null>
     */
    private static function brandingFormState(Tenant $tenant): array
    {
        return Tenancy::forTenant($tenant, static function () use ($tenant): array {
            $profile = self::brandProfileFor($tenant);

            $state = [];

            foreach (self::brandLogos() as $asset) {
                $state[$asset->column()] = $profile->getAttribute($asset->column());
            }

            foreach (self::brandColours() as $colour) {
                $state[$colour] = $profile->getAttribute($colour);
            }

            return $state;
        });
    }

    /**
     * Write what the section holds onto this merchant's brand profile.
     *
     * Returns what moved, as `brand_*_from` / `brand_*_to` scalars for the
     * audit context — the same flat shape `EditTenant::changes()` uses. A logo
     * is recorded by its stored path: a fact about the account, not about a
     * person (ADR-0025 §3).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, scalar|null>
     */
    private function applyBranding(Tenant $tenant, array $state): array
    {
        return Tenancy::forTenant($tenant, function () use ($tenant, $state): array {
            $profile = self::brandProfileFor($tenant);
            $before = self::brandSnapshot($profile);

            // Colours: only those that differ, so an untouched section on the
            // create page leaves the platform defaults exactly as the observer
            // wrote them.
            $colours = [];

            foreach (self::brandColours() as $colour) {
                $value = $state[$colour] ?? null;

                if (is_string($value) && trim($value) !== '' && strcasecmp(trim($value), (string) $profile->getAttribute($colour)) !== 0) {
                    $colours[$colour] = trim($value);
                }
            }

            if ($colours !== []) {
                app(UpdateBrandProfile::class)($profile, $colours);
            }

            foreach (self::brandLogos() as $asset) {
                $value = $state[$asset->column()] ?? null;
                $stored = $profile->getAttribute($asset->column());

                if ($value instanceof UploadedFile) {
                    try {
                        app(UploadBrandAsset::class)($profile, $asset, $value);
                    } catch (UploadRefused $refused) {
                        // The rest of the save stands; this one file does not,
                        // and the reason is a sentence somebody can act on.
                        Notification::make()->title($refused->getMessage())->danger()->send();
                    }

                    continue;
                }

                // Cleared in the form: remove the file and its variants, the
                // way the operator's own «remove» does.
                if (($value === null || $value === '') && is_string($stored) && $stored !== '') {
                    app(UploadBrandAsset::class)->remove($profile, $asset);
                }
            }

            $after = self::brandSnapshot($profile->refresh());
            $changes = [];

            foreach ($after as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $changes["brand_{$field}_from"] = $before[$field] ?? null;
                    $changes["brand_{$field}_to"] = $value;
                }
            }

            return $changes;
        });
    }

    /**
     * The profile, made if a tenant somehow has none — the same safety net as
     * `Branding::currentProfile()`. Called inside the tenant's context.
     */
    private static function brandProfileFor(Tenant $tenant): BrandProfile
    {
        return BrandProfile::query()->firstOrCreate(
            ['tenant_id' => $tenant->getKey()],
            BrandProfile::platformDefaults(),
        );
    }

    /**
     * The fields this section edits, as the audit wants them.
     *
     * @return array<string, string|null>
     */
    private static function brandSnapshot(BrandProfile $profile): array
    {
        $values = [];

        foreach ([...array_map(static fn (BrandAsset $asset): string => $asset->column(), self::brandLogos()), ...self::brandColours()] as $field) {
            $value = $profile->getAttribute($field);
            $values[$field] = is_string($value) && $value !== '' ? $value : null;
        }

        return $values;
    }
}
