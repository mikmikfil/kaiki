<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\GenerateETicket;
use App\Models\Product;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\CheckInScenario;

/*
|--------------------------------------------------------------------------
| The ticket in the guest's language, all of it (2026-09-23)
|--------------------------------------------------------------------------
|
| The labels followed the booking's locale, but the trip's title and the
| meeting point's name followed the app's — and the queued listener that
| renders the ticket runs in English. A Greek guest's ticket said «Morning
| swim cruise» under «Επιβάτης 1 από 2».
|
*/

it('renders translated names in the booking language, whatever the app locale', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Product::query()->findOrFail($booking->product_id)->forceFill([
            'title' => ['el' => 'Πρωινό κολυμβητικό', 'en' => 'Morning swim cruise'],
        ])->save();

        $booking->forceFill(['locale' => 'el'])->save();

        app()->setLocale('en');

        $html = app(GenerateETicket::class)->html($booking->refresh());

        expect($html)->toContain('Πρωινό κολυμβητικό')
            ->and($html)->not->toContain('Morning swim cruise')
            // And the app is left in the locale it was in.
            ->and(app()->getLocale())->toBe('en');
    });
})->group('fast');
