<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Support\PanelApp;
use Illuminate\Http\Response;

/**
 * «Χωρίς σύνδεση»: what the installed panel shows when a screen cannot be
 * loaded (PWA, 2026-09-23).
 *
 * Kept by {@see PanelServiceWorkerController} and shown in place of any panel
 * screen the network could not deliver. Public and about nobody, since it is
 * the one page the phone keeps: no name, no tenant, nothing from the session
 * but the language. Everything it needs is inside it — styles, the mark —
 * because with no network there is nothing else to fetch.
 *
 * `Content-Language` says which language the kept copy is in, so the worker
 * can fetch it again when the operator switches.
 */
class PanelOfflineController
{
    public function __invoke(): Response
    {
        $locale = app()->getLocale();

        return response()
            ->view('app.offline', [
                'boardingUrl' => PanelApp::path(route('filament.app.boarding')),
                'boardingScope' => BoardingServiceWorkerController::scope(),
                'navy' => PanelApp::NAVY,
            ])
            ->header('Content-Language', $locale)
            ->header('Cache-Control', 'no-store');
    }
}
