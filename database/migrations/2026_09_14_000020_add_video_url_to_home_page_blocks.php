<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\VideoEmbed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hero video an operator did not have to upload.
 *
 * ## Why the file column was not enough
 *
 * `video_path` takes an MP4 of at most twenty megabytes, and the limit is the
 * point: a masthead loop is five seconds of water, and a two-minute clip off a
 * phone makes the operator's own page unusable on the connection their guests
 * are on. But an operator who already has a film — the one their nephew made,
 * the one already on their Facebook page — has it on YouTube or Vimeo, in
 * every resolution, on somebody else's bandwidth, and no amount of explaining
 * the upload limit turns it into an MP4 they can produce.
 *
 * ## A URL, not a provider and an id
 *
 * Two columns would be the normalised shape and would mean the operator's own
 * input is nowhere in the row: a link they pasted, parsed into parts, cannot be
 * shown back to them to check. {@see VideoEmbed} does
 * the parsing on the way out, so what is stored is what they typed and what is
 * rendered is never their string — the embed URL is built from a provider this
 * platform names and an id matched against a strict pattern.
 *
 * Nothing else on the page frames a third party except the meeting-point map,
 * and the policy that admits this one is built per response from the blocks the
 * page actually renders — see `HostedPageCsp`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            // Beside `video_path`, because it is the same decision made the
            // other way. 255 is the column width every other URL and path in
            // this schema uses; a watch link is far inside it.
            $table->string('video_url', 255)->nullable()->after('video_path');
        });
    }

    public function down(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            $table->dropColumn('video_url');
        });
    }
};
