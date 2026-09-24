<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Σχετικά με εμάς» (Mike, 2026-09-24).
 *
 * The about page is built from the same sections as the home page, so the
 * sections learn which page they are on rather than a second table repeating
 * every column of the first. Every row that exists today is the home page's.
 *
 * And the people on it get what a guest wants to see of them: a photograph and
 * a line or two, in both languages. Both optional — a captain with neither is
 * shown by name and initials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            $table->string('page', 16)->default('home')->after('tenant_id');
            $table->index(['tenant_id', 'page', 'sort_order']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('specialty');
            $table->json('bio')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'page', 'sort_order']);
            $table->dropColumn('page');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'bio']);
        });
    }
};
