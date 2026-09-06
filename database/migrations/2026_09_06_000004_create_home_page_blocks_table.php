<?php

declare(strict_types=1);

use App\Models\HomePageBlock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's home page, as an ordered list of typed blocks.
 *
 * Added by the design review of 4 September, which is why it is not in §7 of
 * the spec: HOS-1 says the hosted page is *"a branded landing page with the
 * product list"*, and a landing page nobody can put a sentence on is a landing
 * page every operator asks to change on their first day.
 *
 * ## No `content` blob
 *
 * The tempting shape is one `json content` column per row, and it is wrong for
 * the same reason ADR-0008 gives about JSON paths: `heading` is **translatable**
 * and therefore has to satisfy the `el`/`en` rule that `SearchIndexObserver`
 * enforces on columns, not on paths inside one. Splitting the translatable
 * fields out means they are ordinary translatable columns and inherit every
 * guarantee the rest of the schema already has.
 *
 * `settings` holds only the knobs that are never translated — which source the
 * trips block reads, whether the contact block shows a phone number — so a
 * missing translation is impossible to hide in there.
 *
 * ## Nullable heading and body, on purpose
 *
 * A gallery has no prose and a trips block often has no heading. Requiring both
 * would mean an operator typing a heading they do not want in order to save a
 * row of photographs. {@see HomePageBlock} states which fields each
 * type reads, and a field a type does not read is not rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_page_blocks', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `App\Enums\HomeBlockType`. A string rather than the database's own
            // enum type: MySQL's `ENUM` and SQLite's total absence of one is
            // exactly the engine-specific SQL ADR-0015 rule 3 forbids.
            $table->string('type', 32);

            $table->unsignedInteger('sort_order')->default(0);

            // Hidden rather than deleted. An operator taking the gallery down
            // for the winter should not have to retype it in the spring, and a
            // soft delete would mean the editor has to explain a third state.
            $table->boolean('is_visible')->default(true);

            // Translatable (§1.6), and both nullable — see the class docblock.
            $table->json('heading')->nullable();

            // **Plain text. Never markup.** Rendered only through
            // `App\Domain\Hosted\Support\BlockText`, which escapes first and
            // turns blank lines into paragraphs afterwards, so the operator gets
            // paragraph breaks and never gets an element.
            $table->json('body')->nullable();

            // ADR-0021 Option A: a path, not a media row.
            $table->string('image_path', 255)->nullable();

            // A list of `{path, alt: {el, en}}` for the gallery, and only the
            // gallery. Named for what it holds rather than for a generic
            // `items`, so a second type cannot quietly start meaning something
            // else by it.
            $table->json('images')->nullable();

            // The per-type knobs, none of them translated.
            $table->json('settings')->nullable();

            $table->timestamps();

            // The page is read on every hosted-page request and written almost
            // never, so the index is the read: one tenant's visible blocks in
            // their order, from the index alone.
            $table->index(['tenant_id', 'is_visible', 'sort_order'], 'home_page_blocks_render_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_page_blocks');
    }
};
