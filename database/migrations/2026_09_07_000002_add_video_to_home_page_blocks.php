<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hero can be a video, not only a photograph.
 *
 * ## Its own column rather than a second use of `image_path`
 *
 * The hero needs **both**: the video plays, and the image is its poster — the
 * frame a visitor sees before the video has loaded, on a connection too slow to
 * play it, and when the browser refuses to autoplay at all. One column holding
 * either would mean a hero that is blank for the first second on every phone on
 * a quay, which is exactly where these pages are read.
 *
 * ## Nothing here decides whether it plays
 *
 * That is `hero.blade.php`: muted, looping, `playsinline`, and **not autoplayed
 * for a visitor who asked for reduced motion**. A looping video behind text is a
 * vestibular trigger, and the operating system already knows the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            // Same length and same nullability as `image_path` beside it: this
            // is the same kind of thing, stored the same way, on the same disk.
            $table->string('video_path', 255)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            $table->dropColumn('video_path');
        });
    }
};
