<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own logo and colours — Kaiki's, not an operator's.
 *
 * ## Why a table and not config
 *
 * Everything here is changed by a person through a screen, at run time, on a
 * deployed installation. Config is changed by a deploy. The distinction is the
 * whole reason this exists: the platform admin asked to set the logo without
 * one.
 *
 * ## One row, enforced
 *
 * `singleton` is a tiny column that may only hold `1`, with a unique index on
 * it. There is exactly one platform, so a second row is not a state the rest of
 * the code should have to consider — and an application-level "always take the
 * first" quietly becomes "whichever the database felt like" the moment two rows
 * exist. The constraint refuses instead.
 *
 * The row is inserted here rather than by a seeder, so that a fresh migrate on
 * an empty database already has the defaults and no screen has to handle the
 * absent case.
 *
 * ## The colours default to the same values operators get
 *
 * `config('kaiki.branding.defaults.colors')` is what a new BrandProfile is
 * created with, and the platform starts from the same palette — the navy and
 * the rust of the design review of 2026-09-04. Read at migration time so that
 * the two cannot drift here; `PlatformBrandDefaultsTest` asserts they agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_brand', function (Blueprint $table): void {
            $table->id();

            // Only ever 1. The unique index is the one-row rule.
            $table->unsignedTinyInteger('singleton')->default(1)->unique();

            /*
             * Two logos, because a panel has a light and a dark theme and one
             * image cannot be legible on both. Either may be null: the panels
             * fall back to the brand name as text, which is what they rendered
             * before this table existed.
             */
            $table->string('logo_light_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('favicon_path')->nullable();

            $defaults = (array) config('kaiki.branding.defaults.colors', []);

            $table->string('primary_color', 7)->default($defaults['primary'] ?? '#123A5E');
            $table->string('accent_color', 7)->default($defaults['accent'] ?? '#B5511F');

            $table->timestamps();
        });

        DB::table('platform_brand')->insert([
            'singleton' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_brand');
    }
};
