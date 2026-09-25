<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Hosted\Support\HostedAsset;
use App\Filament\App\Auth\EditProfile;
use App\Filament\App\Auth\Login;
use App\Filament\App\Auth\RequestPasswordReset;
use App\Filament\App\Auth\ResetPassword;
use App\Filament\App\Pages\CheckIn;
use App\Filament\App\Pages\Settings;
use App\Filament\Avatars\InitialsAvatarProvider;
use App\Http\Controllers\App\BoardingController;
use App\Http\Controllers\App\BoardingServiceWorkerController;
use App\Http\Controllers\App\DismissAnnouncementController;
use App\Http\Controllers\App\PanelManifestController;
use App\Http\Controllers\App\PanelOfflineController;
use App\Http\Controllers\App\PanelServiceWorkerController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EndExpiredImpersonation;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\RequireSetupFirst;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use App\Models\PlatformBrand;
use App\Policies\TenantOwnedPolicy;
use App\Support\Tenancy;
use Filament\FontProviders\LocalFontProvider;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Infolist;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use League\Flysystem\UnableToCheckFileExistence;
use Throwable;

/**
 * The operator back-office at `/app` (spec SCP-1, ARC-4).
 *
 * Tenant context comes from the signed-in user via `ResolveTenant`, which is
 * strategy (4) of TEN-4. There is **no tenant switcher**: ADR-0020 Option C
 * gives each user exactly one tenant, and a super-admin reaches an operator
 * through impersonation (TEN-7, M7) rather than a dropdown. A dropdown would
 * make "which tenant am I acting as" a piece of session state, which is exactly
 * where a cross-tenant mistake becomes possible.
 *
 * `EnsureTenantIsWritable` sits in the panel middleware, but **it is not what
 * blocks a panel write** — see #43. Livewire hands it a synthesized request
 * carrying the original page-load method, so its safe-method check short
 * circuits on every Filament action. TEN-9 is enforced in
 * {@see TenantOwnedPolicy}, which every resource inherits. The
 * middleware stays for the non-Livewire POSTs, where the method is honest.
 * Reads keep working either way, because the operator still needs to see their
 * own bookings while sorting out a payment.
 */
class AppPanelProvider extends PanelProvider
{
    /**
     * CNV-2: stored in UTC, **displayed in the tenant timezone**.
     *
     * Registered once here rather than field by field, because the alternative
     * is every resource in M1 remembering a `->timezone()` call and one of them
     * not. `config('app.timezone')` is UTC and tenants default to
     * `Europe/Athens`, so the default is silently wrong by two or three hours —
     * survivable on an API key's expiry, not survivable on a departure time,
     * where CNV-3 requires `local_time` and `starts_at_utc` to agree.
     *
     * This is global to both panels by nature. That is correct: `/admin` has no
     * tenant to resolve, so a super-admin falls back to UTC, which is the right
     * frame for someone looking across every operator at once.
     */
    public function boot(): void
    {
        $timezone = static function (): string {
            $tenant = Tenancy::current();

            return $tenant === null
                ? (string) config('app.timezone')
                : $tenant->timezone;
        };

        DateTimePicker::configureUsing(
            static fn (DateTimePicker $component): DateTimePicker => $component->timezone($timezone),
        );

        /*
         * **A date on its own is a day on a calendar, never converted**
         * (2026-09-22) — the same rule as a time on its own, and found the same
         * way: a schedule rule whose window was typed as 1/6–30/9 was stored as
         * **31/5–29/9**.
         *
         * `DatePicker` extends `DateTimePicker`, so the configuration above
         * already reaches it, and the conversion it does is right for an
         * instant and wrong for a date. Athens is two or three hours ahead of
         * UTC, so midnight on the 1st is 21:00 on the 31st, and the column —
         * `date`, with no time in it — keeps the 31st. Every date field in the
         * panel is a calendar day: a rule's window, a period's range, a
         * coupon's validity, a report's from and to. None of them is an
         * instant, and all of them were a day early for an operator east of
         * Greenwich.
         *
         * Registered after the parent's so it wins (`ComponentManager` applies
         * the configurations in registration order, and a `DatePicker` matches
         * both). `ClockTimePickerTest` walks the panel's forms and fails on any
         * date or time picker that is not pinned to UTC.
         */
        DatePicker::configureUsing(
            static fn (DatePicker $component): DatePicker => $component->timezone('UTC'),
        );

        TextColumn::configureUsing(
            static fn (TextColumn $column): TextColumn => $column->timezone($timezone),
        );

        /*
         * **Dates the way a Greek reads them** (2026-09-23): 16/09/2026,
         * 16/09/2026 09:00, 09:00.
         *
         * Filament's defaults are American — «Σεπ 16, 2026», and a time with
         * seconds nobody asked for, «09:00:00» — and they apply wherever a
         * column, an entry or a picker says `->date()` without a format, which
         * was thirty-one places. These are only the defaults: a call that
         * names its own format keeps it.
         */
        foreach ([Table::class, Infolist::class, DateTimePicker::class] as $owner) {
            $owner::$defaultDateDisplayFormat = 'd/m/Y';
            $owner::$defaultDateTimeDisplayFormat = 'd/m/Y H:i';
            $owner::$defaultTimeDisplayFormat = 'H:i';
        }

        DateTimePicker::$defaultDateTimeWithSecondsDisplayFormat = 'd/m/Y H:i:s';

        // Greek does not capitalise every word of a label: «Πολιτική ακύρωσης»,
        // not the «Πολιτική Ακύρωσης» Filament makes of it for headings and
        // breadcrumbs by default. One switch on the base class covers every
        // resource in both panels.
        Resource::titleCaseModelLabel(false);

        /*
         * A saved file is handed to FilePond as a root-relative URL.
         *
         * Filament's own callback returns `Storage::url()`, which is absolute
         * on `APP_URL`. When the panel is opened on any other host (127.0.0.1
         * while APP_URL is the LAN address, or a second domain) FilePond's
         * fetch is cross-origin, the panel CSP's `connect-src 'self'` refuses
         * it, and the field shows «Φόρτωση σε εξέλιξη» for ever. Same body as
         * Filament's default otherwise; see HostedAsset::relative for the rule.
         */
        FileUpload::configureUsing(
            static fn (FileUpload $component): FileUpload => $component->getUploadedFileUsing(
                static function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                    /** @var FilesystemAdapter $storage */
                    $storage = $component->getDisk();
                    $fetchInfo = $component->shouldFetchFileInformation();

                    try {
                        if ($fetchInfo && ! $storage->exists($file)) {
                            return null;
                        }
                    } catch (UnableToCheckFileExistence) {
                        return null;
                    }

                    $url = null;

                    if ($component->getVisibility() === 'private') {
                        try {
                            $url = $storage->temporaryUrl($file, now()->addMinutes(5));
                        } catch (Throwable) {
                            // The driver cannot sign URLs; fall back to a plain one.
                        }
                    }

                    $name = is_array($storedFileNames) ? ($storedFileNames[$file] ?? null) : $storedFileNames;

                    return [
                        'name' => $name ?? basename($file),
                        'size' => $fetchInfo ? $storage->size($file) : 0,
                        'type' => $fetchInfo ? $storage->mimeType($file) : null,
                        'url' => HostedAsset::relative($url ?? $storage->url($file)),
                    ];
                },
            ),
            // Important: plain configurations run before setUp(), which would
            // put Filament's absolute-URL callback straight back.
            isImportant: true,
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            /*
             * Initials drawn locally, rather than Filament's default.
             *
             * The default is `UiAvatarsProvider`, which puts the signed-in
             * person's name in a `https://ui-avatars.com/api/?name=…` URL — so
             * every page of this panel was sending a staff member's name to a
             * third party nobody had chosen, on every load. See
             * {@see InitialsAvatarProvider}.
             */
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->path('app')
            // Filament's CSS plus every utility our own views use, both panels
            // alike (2026-09-23). See {@see PanelTheme}.
            ->theme(PanelTheme::url())
            // Ours, for the phone (direction Α1, product owner, 2026-09-23): the
            // right keyboard and autofill on each field, the error said once
            // above the form, «Να με θυμάσαι» on by default on a phone. See
            // {@see Login}.
            ->login(Login::class)
            /*
             * Password reset, which the panel did not have.
             *
             * Two things needed it. A colleague invited through
             * {@see \App\Domain\Tenancy\Actions\InviteStaffMember} sets their
             * own password through this flow, so without the routes registered
             * the invitation link 404s — worse than no invitation, because the
             * owner believes they sent one. And an operator who forgot their
             * password had no way back in at all: there was no reset route on
             * either panel, so the only recovery was somebody editing the
             * database.
             *
             * The request page is ours (2026-09-23) so it arrives with the
             * email the sign-in form already had. See {@see RequestPasswordReset}.
             */
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class)
            // «Το προφίλ μου» in the user menu (product owner, 2026-09-17): every
            // person can set their own name, an optional «Προσφώνηση» the home
            // page greets them by, and their own password. Not a simple page, so
            // it keeps the panel's sidebar and menu around it. Kept outside
            // `Pages/`, which is auto-discovered.
            ->profile(EditProfile::class, isSimple: false)
            // No font from a third-party host. Filament's default loads Inter
            // from fonts.bunny.net, which the panel's own CSP (SEC-10) blocks —
            // so it never loaded, and every page logged the refusal. Local
            // provider with no URL: the stack falls back to the system font the
            // panel was already showing, and no operator's IP leaves for a font.
            ->font('Inter', provider: LocalFontProvider::class)
            /*
             * The platform's own logo, set on `/admin` → Εμφάνιση.
             *
             * A closure, so the database is read while the panel renders
             * rather than while it is registered — this provider also boots
             * during `artisan migrate` on a database that has no tables yet.
             * Null falls back to `brandName()`, which is what both panels
             * showed before there was anywhere to upload a logo.
             */
            ->brandLogo(fn (): ?string => PlatformBrand::logoUrl())
            ->darkModeBrandLogo(fn (): ?string => PlatformBrand::logoUrl(dark: true))
            ->favicon(fn (): string => PlatformBrand::faviconUrl() ?? asset('favicon.svg'))
            ->brandName(config('app.name'))
            // No global search. Filament switches it on by itself the moment a
            // resource names its records (`$recordTitleAttribute`, 2026-09-24),
            // and on a phone the box sat on top of «Μενού» (Mike, same day).
            // Nobody asked for it; each list has its own search.
            ->globalSearch(false)
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            /*
             * Two groups, and «Ρυθμίσεις» is a page rather than a third.
             *
             * What an operator does every morning is at the top: boarding, the
             * calendar, bookings, quotes, enquiries, vouchers. Underneath is the
             * catalogue they touch once a season.
             *
             * Everything else is something you go looking for: the
             * notification log, the exports your accountant asked for, what went
             * wrong, the calendar sync, the keys and the domain. Until
             * 2026-09-11 that was a third, collapsed group; now it is one item
             * leading to a page of cards ({@see Settings}), each with an icon
             * and a line saying what it is for (product owner). Fifteen screens
             * you rarely need read better as a page you can scan than as a list
             * you first have to open.
             */
            /*
             * The order the sidebar reads in, and it is an argument rather than
             * a list: what is happening today, then what you sell, then when it
             * sails and with what.
             *
             * «Κατάλογος» and «Πρόγραμμα & στόλος» were one group called
             * «Εκδρομές & σκάφη» until 14 September. Ten entries covering three
             * unrelated ideas — the thing sold, the rule that says when it runs,
             * and the boats — and the product owner asked, in as many words,
             * what the difference between a δρομολόγιο and an εκδρομή was. A
             * menu that has to be explained is a menu that is wrong, so the two
             * ideas are two groups and the group names answer the question.
             *
             * Αναχωρήσεις moved out of it into «Λειτουργία»: it is the screen
             * an operator opens every morning, and it was filed with the
             * once-a-season ones.
             *
             * Menu 1 (product owner, 2026-09-16): four short groups named for the
             * job — «Σήμερα», «Πωλήσεις», «Κατάλογος», «Στόλος» — and 19 entries
             * down to 13. Screens that are really a second view of another one
             * (ερωτήματα of προσφορές, περίοδοι and πολιτικές ακύρωσης of τιμές,
             * δεσμεύσεις of σκάφη) left the sidebar for tabs on that screen; see
             * `filament.app.sibling-tabs`. «Στατιστικά» sits at the foot, above
             * «Ρυθμίσεις». Every address stayed the same.
             */
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('panel.groups.today')),
                NavigationGroup::make()->label(fn (): string => __('panel.groups.sales')),
                NavigationGroup::make()->label(fn (): string => __('panel.groups.catalogue')),
                NavigationGroup::make()->label(fn (): string => __('panel.groups.fleet')),
            ])
            /*
             * «Σάρωση εισιτηρίων» alone at the top of the menu, above every
             * group (Mike, 2026-09-24): it is the one thing done forty times a
             * morning on the quay. The same place the home page's button goes —
             * the camera on the boarding page — and only where there is
             * scanning at all: boarding on, QR on, and somebody allowed to board.
             */
            ->navigationItems([
                NavigationItem::make('scan')
                    ->label(fn (): string => __('panel.nav.scan'))
                    ->icon('heroicon-o-qr-code')
                    ->url(fn (): string => route('filament.app.boarding', ['camera' => 1]))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.app.boarding'))
                    ->sort(-100)
                    ->visible(fn (): bool => CheckIn::canAccess() && CheckIn::qrEnabled()),
            ])
            /*
             * The panel as an app on a phone (PWA, 2026-09-23): its manifest,
             * its service worker and the page that worker shows with no
             * network. `routes` rather than `authenticatedRoutes`: the sign-in
             * page links the manifest and registers the worker too, and none of
             * the three is about anybody. The worker at `/app/sw.js` is allowed
             * the scope `/app`; see {@see PanelServiceWorkerController}.
             */
            ->routes(function (): void {
                Route::get('manifest.webmanifest', PanelManifestController::class)
                    ->name('manifest');

                Route::get('sw.js', PanelServiceWorkerController::class)
                    ->name('sw');

                Route::get('offline', PanelOfflineController::class)
                    ->name('offline');
            })
            /*
             * The export download (OPS-18).
             *
             * `authenticatedRoutes` rather than `routes` or an entry in
             * `routes/web.php`, so the link inherits this panel's whole stack —
             * the session guard, `ResolveTenant`, and therefore the tenant
             * scope that makes another operator's uuid resolve to nothing.
             *
             * A signed temporary URL was the alternative and is a bearer
             * credential over a spreadsheet of guest names, emails and phone
             * numbers; see {@see ExportDownloadController}.
             */
            ->authenticatedRoutes(function (): void {
                Route::get('exports/{uuid}/download', ExportDownloadController::class)
                    ->name('exports.download');

                /*
                 * The offline boarding page (OPS-12).
                 *
                 * Inside `authenticatedRoutes` for the same reason the export
                 * download is: it inherits the panel's session guard and
                 * `ResolveTenant`, so a crew member's phone is scoped to their
                 * own operator without this controller having to arrange it.
                 *
                 * The service worker is served from **inside** the boarding
                 * path, because a worker's scope is the directory it comes
                 * from. At `/app/boarding/sw.js` it can only ever control
                 * `/app/boarding/…`; served from the root it would cache
                 * authenticated panel HTML, and a phone handed on after
                 * somebody signed out would still render the last operator's
                 * screens.
                 */
                Route::get('boarding', [BoardingController::class, 'show'])
                    ->name('boarding');

                Route::get('boarding/sw.js', BoardingServiceWorkerController::class)
                    ->name('boarding.sw');

                Route::post('boarding/scan', [BoardingController::class, 'scan'])
                    ->name('boarding.scan');

                // The platform announcement's close button (SAA-1). Here for
                // the same reason as the two above: the panel's session, CSRF
                // and `ResolveTenant`, with nothing for the controller to set up.
                Route::post('announcements/{announcement}/dismiss', DismissAnnouncementController::class)
                    ->whereNumber('announcement')
                    ->name('announcements.dismiss');
            })
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                AddSecurityHeaders::class,
                // Listed here rather than in `authMiddleware` so the login page
                // is localised too — an operator who cannot read the sign-in
                // form is the last person able to fix their own locale. It
                // still runs *after* `ResolveTenant`, which lives in the auth
                // stack: the middleware priority list in `bootstrap/app.php`
                // guarantees the order, because listing it twice would be
                // silently deduplicated. See `SetLocale`.
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // The sixty minutes, enforced before anything reads the session
                // as live (TEN-7, SAA-2). Straight after `Authenticate` and
                // before the tenant is resolved: an expired impersonation has
                // to end rather than go on to resolve an operator and render
                // their panel to somebody whose hour is up.
                EndExpiredImpersonation::class,
                // After authentication, so the session user exists to resolve from.
                ResolveTenant::class,
                EnsureTenantIsWritable::class,
                // After the tenant is resolved, because what it decides is a
                // question about the tenant. Last in the stack, so it never
                // stands between a request and the guard that would refuse it
                // (#51, SAA-10) — a request that should 403 must 403 rather
                // than be redirected to a setup guide.
                RequireSetupFirst::class,
                // isPersistent, or none of this runs on `POST /livewire/update`
                // — which is every button in the panel. Filament only forwards
                // auth middleware to Livewire's persistent list when asked, and
                // without it `Tenancy::current()` is null on each action: the
                // tenant scope throws and the operator gets a 500. A component
                // test cannot catch this, because it never reaches that route.
            ], isPersistent: true);
    }
}
