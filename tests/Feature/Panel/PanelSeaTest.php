<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The operator panel's blue sidebar and light waves (2026-09-16)
|--------------------------------------------------------------------------
|
| `/app` only. The super-admin keeps Filament's plain look, which is also how
| somebody with both panels open can tell which one they are in.
|
*/

it('paints the operator panel blue, with the waves', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee('--ka-sea-deep: #0F2E57', escape: false)
        ->assertSee('@keyframes ka-sea-drift', escape: false)
        ->assertSee('prefers-reduced-motion', escape: false);
})->group('fast');

it('leaves the super-admin panel plain', function (): void {
    actingAs(User::factory()->superAdmin()->create())->get('/admin')
        ->assertSuccessful()
        ->assertDontSee('--ka-sea-deep', escape: false);
})->group('fast');
