<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExportJob;
use App\Models\User;
use App\Support\Authorization\Capability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over a finished export (spec OPS-18, TEN-5).
 *
 * ## Authenticated, not signed — and that is the security decision here
 *
 * OPS-18 asks for *"a download link that expires after 24 hours"*, and Laravel's
 * signed temporary URL is the obvious way to build one. It is the wrong one.
 *
 * A signed URL is a **bearer credential**: whoever holds the string gets the
 * file. It survives being pasted into a group chat, it survives the browser
 * history of a shared office machine, and — the case that matters — it survives
 * the person who generated it being removed from the operator's staff. A
 * spreadsheet of every guest's name, email and phone number is exactly the
 * payload that must not be reachable by a link alone.
 *
 * So the link is an ordinary panel URL behind the panel's own session, and
 * three things are asserted on every request:
 *
 * 1. **Signed in**, through the `/app` guard.
 * 2. **`ExportData`**, so a crew member with a session cannot fetch one — the
 *    capability is checked here rather than trusted from the screen that
 *    produced the link, because a URL outlives the page it came from.
 * 3. **The same tenant**, by the model's own global scope. A uuid from another
 *    operator's row does not resolve, so the response is a 404 rather than a
 *    403 — a refusal that distinguishes "not yours" from "does not exist" is
 *    itself an answer about what exists.
 *
 * The 24 hours then live on the row rather than in a signature, which is what
 * lets an operator see *why* a link stopped working instead of meeting an
 * "invalid signature" page.
 */
final class ExportDownloadController extends Controller
{
    public function __invoke(string $uuid): StreamedResponse
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->hasCapability(Capability::ExportData), 403);

        // The global scope makes this the current tenant's row or nothing.
        $export = ExportJob::query()->where('uuid', $uuid)->first();

        abort_unless($export instanceof ExportJob, 404);

        // Expiry is answered by the row, not by the sweeper having run. A link
        // that keeps working past its day whenever the scheduler is unhealthy
        // is a link nobody can reason about.
        abort_unless($export->isDownloadable(), 410);

        $disk = $export->disk;
        $path = $export->path;

        abort_unless(is_string($disk) && is_string($path) && $path !== '', 410);

        $storage = Storage::disk($disk);

        // The row says `ready` and the file is not there — a disk swapped
        // between environments, or a purge that half-finished. 410 rather than
        // 500: nothing is broken, the file is simply gone, and the screen's
        // offer to run it again is the right next step.
        abort_unless($storage->exists($path), 410);

        $export->forceFill([
            'downloaded_at' => Carbon::now(),
            'download_count' => $export->download_count + 1,
        ])->save();

        return $storage->download(
            $path,
            $export->filename ?? ($export->uuid . '.csv'),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // The file is one tenant's business data behind a session.
                // Nothing between here and the browser may keep a copy.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
