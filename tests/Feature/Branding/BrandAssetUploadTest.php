<?php

declare(strict_types=1);

use App\Domain\Branding\Actions\UploadBrandAsset;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Enums\BrandAsset;
use App\Exceptions\UploadRefused;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

/*
|--------------------------------------------------------------------------
| Logo upload — spec BRD-7, SEC-13, ADR-0021
|--------------------------------------------------------------------------
|
| SVG, PNG and WebP up to 2 MB; EXIF stripped; SVG sanitised; resized variants
| generated synchronously. **Content type is decided from the bytes**, not from
| the extension and not from the browser's Content-Type header — both of which
| the uploader writes.
|
| Every file here is built rather than committed as a fixture, so what is being
| uploaded is visible in the test that uploads it.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
});

/*
 * State lives in functions rather than on `$this`.
 *
 * PHPStan types `$this` inside a Pest closure as `TestCall`, so `$this->tenant`
 * is an undefined property at level 6 — the same reason #15's smoke test
 * imports the `Pest\Laravel\get` helper instead of calling `$this->get()`.
 * Functions keep the suite analysable without a baseline.
 */

function brandImages(): ImageManagerInterface
{
    return new ImageManager(new Driver);
}

/** A real PNG of the given size, encoded by the same driver production uses. */
function pngBytes(int $width, int $height = 100): string
{
    return (string) brandImages()->createImage($width, $height)->encodeUsingMediaType('image/png');
}

function webpBytes(int $width, int $height = 100): string
{
    return (string) brandImages()->createImage($width, $height)->encodeUsingMediaType('image/webp');
}

function upload(string $contents, string $name): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kaiki');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

function brandedTenant(): Tenant
{
    return Tenant::factory()->create();
}

function profileOf(Tenant $tenant): BrandProfile
{
    return BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
}

it('accepts a PNG and writes the configured variants', function (): void {
    $stored = app(StoreUploadedImage::class)(
        upload(pngBytes(1000, 400), 'logo.png'),
        'brand/1/logo_light',
        [200, 400, 800],
    );

    Storage::disk('local')->assertExists($stored->path);

    expect($stored->mimeType)->toBe('image/png')
        ->and(array_keys($stored->variants))->toBe([200, 400, 800]);

    foreach ($stored->variants as $width => $path) {
        Storage::disk('local')->assertExists($path);

        expect(brandImages()->decodeBinary(Storage::disk('local')->get($path))->width())->toBe($width);
    }
})->group('fast');

it('accepts a WebP and keeps it a WebP', function (): void {
    // An operator's chosen format is not ours to change: a PNG that comes back
    // as a WebP is a support ticket from whoever downloads it.
    $stored = app(StoreUploadedImage::class)(upload(webpBytes(600), 'logo.webp'), 'brand/1/logo_light', [200]);

    expect($stored->mimeType)->toBe('image/webp')
        ->and(Storage::disk('local')->get($stored->path))->toStartWith('RIFF');
})->group('fast');

it('does not upscale a variant past the source width', function (): void {
    // A 120px favicon asked to become 180px is a bigger file with no more
    // detail in it. The width is skipped, so a variant on disk is always a real
    // resize and a consumer that finds none falls back to the original.
    $stored = app(StoreUploadedImage::class)(upload(pngBytes(120, 120), 'icon.png'), 'brand/1/favicon', [32, 180]);

    expect(array_keys($stored->variants))->toBe([32]);

    Storage::disk('local')->assertMissing(StoreUploadedImage::variantPath($stored->path, 180));
})->group('fast');

it('strips metadata by re-encoding rather than by editing chunks', function (): void {
    // BRD-7 requires metadata to be gone. A stripper that deletes the chunks it
    // recognises leaves the ones it does not, and what a camera or a design tool
    // can embed is open-ended. Decoding the pixels and encoding a new file keeps
    // exactly the pixels — a guarantee rather than a list.
    $withComment = pngBytes(300) . 'tEXtComment' . "\0" . 'GPS 37.9838 N 23.7275 E';

    $stored = app(StoreUploadedImage::class)(upload($withComment, 'logo.png'), 'brand/1/logo_light', []);

    $written = (string) Storage::disk('local')->get($stored->path);

    expect($written)->not->toContain('GPS 37.9838');
    expect($written)->not->toContain('tEXtComment');
})->group('fast');

it('refuses a file whose bytes are not one of the three formats', function (): void {
    // The extension and the client Content-Type both say PNG. The bytes do not.
    expect(fn () => app(StoreUploadedImage::class)(
        upload('%PDF-1.7 not really an image', 'logo.png'),
        'brand/1/logo_light',
    ))->toThrow(UploadRefused::class);
})->group('fast');

it('refuses a file over the configured size limit', function (): void {
    config()->set('kaiki.branding.uploads.max_kilobytes', 1);

    expect(fn () => app(StoreUploadedImage::class)(
        upload(pngBytes(2000, 2000), 'logo.png'),
        'brand/1/logo_light',
    ))->toThrow(UploadRefused::class);
})->group('fast');

it('refuses a PNG signature whose body no decoder can read', function (): void {
    // Either a truncated upload or a file built to trip the decoder. Neither is
    // something to store, and neither should be a 500.
    expect(fn () => app(StoreUploadedImage::class)(
        upload("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64), 'logo.png'),
        'brand/1/logo_light',
    ))->toThrow(UploadRefused::class);
})->group('fast');

it('sanitises an SVG and never rasterises it', function (): void {
    $hostile = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
        . '<script>alert(1)</script><path d="M0 0 L10 10" fill="#0F62FE"/></svg>';

    $stored = app(StoreUploadedImage::class)(upload($hostile, 'logo.svg'), 'brand/1/logo_light', [200, 400]);

    $written = Storage::disk('local')->get($stored->path);

    expect($stored->mimeType)->toBe('image/svg+xml')
        ->and($stored->variants)->toBe([])
        ->and($written)->not->toContain('script')
        ->and($written)->not->toContain('onload')
        ->and($written)->toContain('#0F62FE');
})->group('fast');

it('refuses an SVG the sanitiser cannot make safe', function (): void {
    expect(fn () => app(StoreUploadedImage::class)(
        upload('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg'),
        'brand/1/logo_light',
    ))->toThrow(UploadRefused::class);
})->group('fast');

it('stores under the tenant directory and writes the column', function (): void {
    $tenant = brandedTenant();
    $profile = profileOf($tenant);

    Tenancy::forTenant($tenant, function () use ($tenant, $profile): void {
        $path = app(UploadBrandAsset::class)($profile, BrandAsset::LogoLight, upload(pngBytes(1000), 'logo.png'));

        expect($path)->toStartWith("brand/{$tenant->getKey()}/logo_light/")
            ->and(profileOf($tenant)->logo_light_path)->toBe($path);
    });
})->group('fast');

it('does not name the stored file after the uploaded one', function (): void {
    // An uploaded name is attacker-controlled and ends up in a path; two
    // operators both uploading `logo.png` must not collide; and a replaced file
    // needs a new URL so a cached logo cannot linger.
    $stored = app(StoreUploadedImage::class)(
        upload(pngBytes(300), '../../../etc/passwd.png'),
        'brand/1/logo_light',
    );

    expect($stored->path)->not->toContain('passwd')
        ->and($stored->path)->not->toContain('..')
        ->and($stored->path)->toStartWith('brand/1/logo_light/');
})->group('fast');

it('deletes the replaced file and every variant of it', function (): void {
    // Deleting only the path the column stored would leave the resizes behind
    // forever — invisible, because nothing references them.
    $tenant = brandedTenant();
    $profile = profileOf($tenant);

    Tenancy::forTenant($tenant, function () use ($profile): void {
        $first = app(UploadBrandAsset::class)($profile, BrandAsset::LogoLight, upload(pngBytes(1000), 'a.png'));
        $firstVariant = StoreUploadedImage::variantPath($first, 200);

        Storage::disk('local')->assertExists($first);
        Storage::disk('local')->assertExists($firstVariant);

        app(UploadBrandAsset::class)($profile, BrandAsset::LogoLight, upload(pngBytes(1000), 'b.png'));

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertMissing($firstVariant);
    });
})->group('fast');

it('keeps the old file when the new one is refused', function (): void {
    // If the upload is refused the operator still has the logo they had this
    // morning. Deleting first would leave every booking page they own without
    // one, and the sentence they get back is about a file size.
    $tenant = brandedTenant();
    $profile = profileOf($tenant);

    Tenancy::forTenant($tenant, function () use ($tenant, $profile): void {
        $original = app(UploadBrandAsset::class)($profile, BrandAsset::LogoLight, upload(pngBytes(600), 'a.png'));

        expect(fn () => app(UploadBrandAsset::class)(
            $profile,
            BrandAsset::LogoLight,
            upload('not an image at all', 'b.png'),
        ))->toThrow(UploadRefused::class);

        Storage::disk('local')->assertExists($original);
        expect(profileOf($tenant)->logo_light_path)->toBe($original);
    });
})->group('fast');

it('clears the column and the files when an asset is removed', function (): void {
    $tenant = brandedTenant();
    $profile = profileOf($tenant);

    Tenancy::forTenant($tenant, function () use ($tenant, $profile): void {
        $path = app(UploadBrandAsset::class)($profile, BrandAsset::LogoLight, upload(pngBytes(1000), 'a.png'));

        app(UploadBrandAsset::class)->remove($profile, BrandAsset::LogoLight);

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing(StoreUploadedImage::variantPath($path, 400));
        expect(profileOf($tenant)->logo_light_path)->toBeNull();
    });
})->group('fast');

it('is stored outside the web root, on a private disk', function (): void {
    // SEC-13: uploads are stored outside the web root and served through a
    // signed URL. `local` is `storage/app/private`; the `public` disk would
    // satisfy the form and quietly fail the requirement.
    expect(config('kaiki.branding.uploads.disk'))->toBe('local')
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(config('filesystems.disks.local.serve'))->toBeTrue()
        ->and(config('filesystems.disks.local'))->not->toHaveKey('visibility');
})->group('fast');

it('maps each slot to its column and its variant widths', function (): void {
    expect(BrandAsset::LogoLight->column())->toBe('logo_light_path')
        ->and(BrandAsset::LogoDark->column())->toBe('logo_dark_path')
        ->and(BrandAsset::Favicon->column())->toBe('favicon_path')
        ->and(BrandAsset::EmailHeader->column())->toBe('email_header_image_path')
        // Both logos share the logo widths: the same image at the same sizes,
        // differing only in the background it is drawn on.
        ->and(BrandAsset::LogoDark->variantWidths())->toBe(BrandAsset::LogoLight->variantWidths())
        ->and(BrandAsset::Favicon->variantWidths())->toBe([32, 180]);

    // Every column named by the enum is a real column, or the whole mapping is
    // a set of strings that happen to look right.
    // Every column the enum names is a real column, or the mapping is a set of
    // strings that merely look right — which is exactly how `email_header_path`
    // came to be written for a column called `email_header_image_path`.
    foreach (BrandAsset::cases() as $asset) {
        expect(Schema::hasColumn('brand_profiles', $asset->column()))
            ->toBeTrue("{$asset->value} names a column that does not exist");
    }
})->group('fast');
