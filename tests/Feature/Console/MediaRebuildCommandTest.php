<?php

declare(strict_types=1);

use App\Domain\Branding\Actions\UploadBrandAsset;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Enums\BrandAsset;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| media:rebuild — ADR-0021, Option A
|--------------------------------------------------------------------------
|
| ADR-0021 accepted synchronous conversions at *fixed documented sizes*. The
| obvious question about that is what happens when a size changes, and this
| command is the answer — it is the reason the widths can live in config at all.
|
| It runs across tenants by design, because a size change is a platform decision.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
});

/*
 * `artisan()` and the image manager come from function helpers rather than
 * `$this`, because PHPStan types `$this` inside a Pest closure as `TestCall` —
 * the same reason #15's smoke test imports `Pest\Laravel\get`.
 */

function rebuildImages(): ImageManagerInterface
{
    return new ImageManager(new Driver);
}

function brandProfileOf(Tenant $tenant): BrandProfile
{
    return BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();
}

/** @return array{Tenant, string} the tenant and the stored logo path */
function tenantWithLogo(int $width = 1000): array
{
    $tenant = Tenant::factory()->create();
    $profile = brandProfileOf($tenant);

    $bytes = (string) rebuildImages()->createImage($width, 400)->encodeUsingMediaType('image/png');

    $file = (string) tempnam(sys_get_temp_dir(), 'kaiki');
    file_put_contents($file, $bytes);

    $path = Tenancy::forTenant($tenant, fn (): string => app(UploadBrandAsset::class)(
        $profile,
        BrandAsset::LogoLight,
        new UploadedFile($file, 'logo.png', null, null, true),
    ));

    return [$tenant, $path];
}

it('writes a variant that a width change added', function (): void {
    [, $path] = tenantWithLogo();

    // The 600px variant does not exist: it was not configured when the file was
    // uploaded. Changing the config alone changes nothing already on disk —
    // which is the property that makes the widths safe to edit.
    config()->set('kaiki.branding.uploads.variants.logo', [200, 400, 600, 800]);

    $added = StoreUploadedImage::variantPath($path, 600);

    Storage::disk('local')->assertMissing($added);

    artisan('media:rebuild')->assertSuccessful();

    Storage::disk('local')->assertExists($added);

    expect(rebuildImages()->decodeBinary(Storage::disk('local')->get($added))->width())->toBe(600);
})->group('fast');

it('leaves the original untouched', function (): void {
    // Re-encoding the original on every rebuild would degrade a lossy format a
    // little more each time for no gain, and would make running the command
    // twice by accident a real cost.
    [, $path] = tenantWithLogo();

    $before = Storage::disk('local')->get($path);

    artisan('media:rebuild')->assertSuccessful();

    expect(Storage::disk('local')->get($path))->toBe($before);
})->group('fast');

it('does not delete a stale variant unless asked', function (): void {
    // The one destructive thing this command can do, and the widths live in a
    // config file a deploy can change by accident. A rebuild that quietly
    // deleted the 400px logo because a bad merge dropped a line would be
    // discovered from a broken page, not from the command's output.
    [, $path] = tenantWithLogo();

    $stale = StoreUploadedImage::variantPath($path, 400);

    Storage::disk('local')->assertExists($stale);

    config()->set('kaiki.branding.uploads.variants.logo', [200]);

    artisan('media:rebuild')->assertSuccessful();
    Storage::disk('local')->assertExists($stale);

    artisan('media:rebuild', ['--prune' => true])->assertSuccessful();
    Storage::disk('local')->assertMissing($stale);

    // The width that is still configured survives the prune.
    Storage::disk('local')->assertExists(StoreUploadedImage::variantPath($path, 200));
})->group('fast');

it('never rasterises an SVG', function (): void {
    // An SVG is resolution-independent, and rasterising it here would invent
    // files the uploader deliberately did not create.
    $tenant = Tenant::factory()->create();
    $profile = brandProfileOf($tenant);

    $file = (string) tempnam(sys_get_temp_dir(), 'kaiki');
    file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');

    $path = Tenancy::forTenant($tenant, fn (): string => app(UploadBrandAsset::class)(
        $profile,
        BrandAsset::LogoLight,
        new UploadedFile($file, 'logo.svg', null, null, true),
    ));

    artisan('media:rebuild')->assertSuccessful();

    foreach ([200, 400, 800] as $width) {
        Storage::disk('local')->assertMissing(StoreUploadedImage::variantPath($path, $width));
    }
})->group('fast');

it('reports a column pointing at a file that is not on the disk', function (): void {
    // A half-finished restore, or a disk swapped without its contents. Silently
    // skipping it is how it stays unnoticed until a guest sees a broken image.
    [, $path] = tenantWithLogo();

    Storage::disk('local')->delete($path);

    artisan('media:rebuild')
        ->expectsOutputToContain($path)
        ->assertSuccessful();
})->group('fast');

it('runs across every tenant, and can be narrowed to one', function (): void {
    // A size change is a platform decision. The `--tenant` option exists for
    // the support case — one operator, one broken logo — not as the default.
    [$first, $firstPath] = tenantWithLogo();
    [, $secondPath] = tenantWithLogo();

    config()->set('kaiki.branding.uploads.variants.logo', [200, 400, 600, 800]);

    artisan('media:rebuild', ['--tenant' => $first->getKey()])->assertSuccessful();

    Storage::disk('local')->assertExists(StoreUploadedImage::variantPath($firstPath, 600));
    Storage::disk('local')->assertMissing(StoreUploadedImage::variantPath($secondPath, 600));

    artisan('media:rebuild')->assertSuccessful();

    Storage::disk('local')->assertExists(StoreUploadedImage::variantPath($secondPath, 600));
})->group('fast');

it('needs no tenant context of its own', function (): void {
    // It is a console command operating across operators, which is one of the
    // documented `withoutTenancy()` opt-outs. Without it the tenant scope throws
    // on the very first query.
    [, $path] = tenantWithLogo();

    expect(Tenancy::check())->toBeFalse();

    artisan('media:rebuild')->assertSuccessful();

    Storage::disk('local')->assertExists($path);
})->group('fast');
