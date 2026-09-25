<?php

declare(strict_types=1);

use App\Enums\HostedSiteMode;
use App\Models\Tenant;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;

/*
|--------------------------------------------------------------------------
| A page that does not exist, in Greek — the 24/9 list
|--------------------------------------------------------------------------
|
| Laravel's error view answered before the locale was set, so a guest on a
| Greek operator's site read «Not Found». With the operator known the 404 is
| theirs, in their language; without one it is the plain page, in Greek.
|
*/

it('answers a missing trip with the operator\'s own 404, in their language', function (): void {
    Tenant::factory()->create(['slug' => 'greek-first', 'name' => 'Γαλάζιο', 'hosted_site_mode' => HostedSiteMode::Full, 'default_locale' => 'el']);

    get(HostedRequest::url('/greek-first/den-yparxei'))
        ->assertNotFound()
        ->assertSee('lang="el"', escape: false)
        ->assertSee(__('hosted.not_found.title', [], 'el'))
        ->assertSee('Γαλάζιο');

    get(HostedRequest::url('/greek-first/den-yparxei?lang=en'))
        ->assertNotFound()
        ->assertSee(__('hosted.not_found.title', [], 'en'));
})->group('fast');

it('answers an unknown operator in Greek, not «Not Found»', function (): void {
    get(HostedRequest::url('/kanenas-edo/tipota'))
        ->assertNotFound()
        ->assertSee('Αυτή η σελίδα δεν υπάρχει')
        ->assertDontSee('Not Found');
})->group('fast');
