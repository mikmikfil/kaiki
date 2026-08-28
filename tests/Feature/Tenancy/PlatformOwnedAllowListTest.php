<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/**
 * Spec TEN-5: `BelongsToTenant` is mandatory, and there is no implicit
 * exemption. A model is either tenant-owned or named in the allow-list.
 *
 * Without this test the allow-list is a comment. The failure it prevents is the
 * quiet one: someone adds a model in M2, forgets the trait, and every query on
 * it returns every operator's rows — with no error, no warning, and nothing in
 * review to notice, because the omission looks exactly like the code that was
 * never written.
 *
 * #8 builds the per-model isolation suite on top of this. This is the cheap
 * structural half.
 */
it('scopes every model that is not explicitly platform-owned', function (): void {
    $allowed = config('tenancy.platform_owned_models');

    $unscoped = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
        $class = 'App\\Models\\' . str_replace(
            ['/', '.php'],
            ['\\', ''],
            $file->getRelativePathname(),
        );

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $usesTrait = in_array(BelongsToTenant::class, class_uses_recursive($class), strict: true);

        if (! $usesTrait && ! in_array($class, $allowed, strict: true)) {
            $unscoped[] = $class;
        }
    }

    expect($unscoped)->toBe([], sprintf(
        "These models are neither tenant-scoped nor listed as platform-owned:\n  %s\n" .
        'Add BelongsToTenant, or add the model to platform_owned_models in config/tenancy.php ' .
        'with a comment saying why it belongs to the platform rather than to one operator.',
        implode("\n  ", $unscoped),
    ));
})->group('fast');

it('lists only real models as platform-owned', function (): void {
    // A typo or a renamed class would silently widen the exemption: the entry
    // would match nothing, and the model it was meant to cover would fall back
    // to being unscoped without failing the test above.
    foreach (config('tenancy.platform_owned_models') as $class) {
        expect(class_exists($class))->toBeTrue("platform_owned_models names a class that does not exist: {$class}")
            ->and(is_subclass_of($class, Model::class))->toBeTrue("platform_owned_models names a non-model: {$class}");
    }
})->group('fast');
