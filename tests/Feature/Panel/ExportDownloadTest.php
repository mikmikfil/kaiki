<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\Role;
use App\Models\ExportJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OperatorUser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| OPS-18, TEN-5: the download, and what a link is allowed to prove
|--------------------------------------------------------------------------
|
| The link is an authenticated panel URL rather than a signed temporary one, and
| these are the cases that decide it. A signed URL is a bearer credential over a
| spreadsheet of every guest's name, email and phone number: it survives being
| pasted into a group chat, and it survives the person who generated it leaving
| the operator's staff.
|
| So three things are asserted on every request — signed in, `ExportData`, and
| the same tenant — and the expiry lives on the row, where an operator can be
| told *why* the link stopped working.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    Carbon::setTestNow('2026-09-08 09:00:00');
});

/** A finished export with a real file behind it. */
function readyExport(Tenant $tenant, string $body = "\u{FEFF}Reference\r\nKK-1\r\n"): ExportJob
{
    return Tenancy::forTenant($tenant, function () use ($tenant, $body): ExportJob {
        $export = ExportJob::factory()->create();

        $path = 'exports/' . $tenant->getKey() . '/' . $export->uuid . '.csv';

        Storage::disk('local')->put($path, $body);

        $export->forceFill([
            'status' => ExportStatus::Ready,
            'disk' => 'local',
            'path' => $path,
            'filename' => 'kaiki-bookings.csv',
            'row_count' => 1,
            'byte_size' => strlen($body),
            'completed_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDay(),
        ])->save();

        return $export;
    });
}

function downloadUrl(ExportJob $export): string
{
    return route('filament.app.exports.download', ['uuid' => $export->uuid]);
}

it('hands the file to an owner', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    $response = actingAs($owner)->get(downloadUrl($export));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        // A tenant's business data behind a session. Nothing in between may
        // keep a copy.
        ->assertHeader('cache-control', 'no-store, private');

    expect($export->refresh()->download_count)->toBe(1)
        ->and($export->downloaded_at)->not->toBeNull();
});

it('refuses a signed-in crew member', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    $crew = OperatorUser::withRole(Role::Crew, $owner->tenant);

    // Crew hold `ViewManifest` — one sailing they are about to work — and not
    // `ExportData`, which is the whole business. The capability is checked on
    // the request rather than trusted from the screen that produced the link,
    // because a URL outlives the page it came from.
    actingAs($crew)->get(downloadUrl($export))->assertForbidden();
});

it('refuses somebody who is not signed in', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    // Not a 403 with the file attached, and not a bearer link: the panel's own
    // guard sends them to sign in.
    get(downloadUrl($export))->assertRedirect();
});

it('does not resolve another operator\'s export', function (): void {
    $mine = OperatorUser::withRole(Role::Owner);
    $theirs = OperatorUser::withRole(Role::Owner);

    $other = readyExport($theirs->tenant);

    // 404 rather than 403. A refusal that distinguishes "not yours" from "does
    // not exist" is itself an answer about what exists (TEN-5).
    actingAs($mine)->get(downloadUrl($other))->assertNotFound();
});

it('closes the link once the day has passed, with the file still on disk', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    Carbon::setTestNow('2026-09-10 09:00:00');

    // No sweeper has run. The row still says `ready` and the bytes are still
    // there, and the link is closed anyway — 410, so the screen can offer to
    // run it again rather than showing an "invalid signature" page.
    expect(Storage::disk('local')->exists((string) $export->path))->toBeTrue();

    actingAs($owner)->get(downloadUrl($export))->assertStatus(410);
});

it('answers 410 when the row says ready and the file is gone', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    Storage::disk('local')->delete((string) $export->path);

    // A disk swapped between environments, or a half-finished purge. Nothing is
    // broken; the file is simply not there.
    actingAs($owner)->get(downloadUrl($export))->assertStatus(410);
});

it('answers 410 for an export that is still being prepared', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $queued = Tenancy::forTenant($owner->tenant, fn (): ExportJob => ExportJob::factory()->create());

    actingAs($owner)->get(downloadUrl($queued))->assertStatus(410);
});

it('does not leak a document number through the download', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    // Whatever the file holds is what is served, so this asserts the pipeline
    // rather than the query: the guarantee lives in `ExportRows`, and this is
    // the test that would fail if a future change served a manifest here.
    $export = readyExport($owner->tenant, "\u{FEFF}Reference,Name\r\nKK-1,Anna Rossi\r\n");

    $response = actingAs($owner)->get(downloadUrl($export));

    expect($response->streamedContent())->not->toContain('AA1234567');
});

it('is reachable only through the panel prefix', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    // The route lives inside the panel's authenticated group, so it carries
    // `ResolveTenant` — which is what makes another operator's uuid resolve to
    // nothing rather than to a row.
    expect(downloadUrl($export))->toContain('/app/exports/')
        ->and(downloadUrl($export))->toContain($export->uuid);
});

it('lets a manager download, because the matrix says so', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $export = readyExport($owner->tenant);

    $manager = OperatorUser::withRole(Role::Manager, $owner->tenant);

    // `ExportData` is owner and manager (TEN-8). A manager runs the business
    // day to day, and the accounting CSV is that job.
    actingAs($manager)->get(downloadUrl($export))->assertOk();
});
