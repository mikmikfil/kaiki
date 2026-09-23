<?php

declare(strict_types=1);

use App\Filament\App\Auth\Login;
use App\Filament\App\Auth\RequestPasswordReset;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The operator's sign-in on a phone (product owner, 2026-09-23, Α1)
|--------------------------------------------------------------------------
|
| Direction Α, variant Α1 of docs/mockups/login-mobile-directions.html. The
| band, the 48 px fields and the tablet column are layout and
| were checked in a browser; what the server decides is here: the attributes
| that pick the phone's keyboard and autofill, the words, the error said once
| above the fields with the password emptied, and the email carried to
| «Ξέχασα τον κωδικό».
|
*/

it('serves /app/login from our page', function (): void {
    expect(Filament::getPanel('app')->getLoginRouteAction())->toBe(Login::class)
        ->and(Filament::getPanel('app')->getRequestPasswordResetRouteAction())->toBe(RequestPasswordReset::class);
})->group('fast');

it('asks the phone for the email keyboard and the saved account', function (): void {
    $html = (string) get('/app/login?lang=el')->assertOk()->getContent();

    expect($html)
        ->toMatch('/<input[^>]*autocomplete="username"[^>]*inputmode="email"[^>]*type="email"[^>]*enterkeyhint="next"/s')
        ->toMatch('/<input[^>]*autocomplete="current-password"[^>]*enterkeyhint="go"/s');
})->group('fast');

it('uses the short, sentence-case words of the mockup', function (): void {
    $html = (string) get('/app/login?lang=el')->assertOk()->getContent();

    expect($html)
        ->toContain('Σύνδεση')
        ->toContain('Κωδικός')
        ->toContain('Να με θυμάσαι')
        ->toContain('Ξέχασα τον κωδικό')
        ->toContain('Εμφάνιση')
        ->toContain('Κρατήσεις για εκδρομές με σκάφος.')
        // Filament's own Greek, which said «στο λογαριασμό».
        ->not->toContain('Συνδεθείτε στο λογαριασμό σας')
        ->not->toContain('Θυμήσου με');
})->group('fast');

it('has «Ξέχασα τον κωδικό» for each layout, and the phone script to tick «Να με θυμάσαι»', function (): void {
    $html = (string) get('/app/login?lang=el')->assertOk()->getContent();

    expect($html)
        ->toContain('kaiki-login-forgot-desktop')
        ->toContain('kaiki-login-forgot-phone')
        ->toContain('data-kaiki-remember="phone"')
        ->toContain("component.\$set('data.remember', true, false)")
        ->toContain("field.scrollIntoView({ block: 'center' })")
        // The band no longer folds while the keyboard is up (2026-09-23, second round).
        ->not->toContain('kaiki-auth-compact');
})->group('fast');

it('leaves «Να με θυμάσαι» unticked on the server, for the desktop', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::test(Login::class)->assertSet('data.remember', false);
})->group('fast');

it('says a wrong password once, above the fields, keeps the email and empties the password', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    app()->setLocale('el');

    $tenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenant->getKey(), 'email' => 'nikos@example.test']);

    Livewire::test(Login::class)
        ->set('data.email', 'nikos@example.test')
        ->set('data.password', 'not-the-password')
        ->call('authenticate')
        ->assertHasErrors(['data.password'])
        ->assertHasNoErrors(['data.email'])
        ->assertSet('credentialsRejected', true)
        ->assertSet('data.email', 'nikos@example.test')
        ->assertSet('data.password', null)
        ->assertSeeHtml('class="kaiki-login-alert" role="alert"')
        ->assertSee('Λάθος email ή κωδικός.')
        ->assertSee('Έλεγξε αν είναι ανοιχτά τα κεφαλαία και ξαναδοκίμασε.');
})->group('fast');

it('writes the email in on «Ξέχασα τον κωδικό» when the sign-in had one', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::withQueryParams(['email' => 'nikos@example.test'])
        ->test(RequestPasswordReset::class)
        ->assertSet('data.email', 'nikos@example.test');

    Livewire::withQueryParams(['email' => 'not an email <script>'])
        ->test(RequestPasswordReset::class)
        ->assertSet('data.email', null);
})->group('fast');

it('gives «Ξέχασα τον κωδικό» the mockup\'s heading and a way back', function (): void {
    $html = (string) get('/app/password-reset/request?lang=el')->assertOk()->getContent();

    expect($html)
        ->toContain('Νέος κωδικός')
        ->toContain('Θα σου στείλουμε σύνδεσμο για να βάλεις καινούργιο. Ισχύει 60 λεπτά.')
        ->toContain('Στείλε μου σύνδεσμο')
        ->toContain('kaiki-auth-back')
        ->not->toContain('back to login');
})->group('fast');
