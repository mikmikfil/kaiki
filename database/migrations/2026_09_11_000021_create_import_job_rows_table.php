<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One source record in an import, and what became of it (SAA-14, SAA-15;
 * `docs/data-model.md` §2.7).
 *
 * This table is the review screen and the audit trail at once: every product,
 * booking, category and person type the files contained, the Kaiki row it
 * became (`target_type`, `target_id`), and — for everything that did not make
 * it — the reason, in `messages`, as translation keys rendered in whichever
 * language the operator is reading.
 *
 * ## Two unique-ish questions, two indexes
 *
 * - `import_rows_job_src_uq` is the data model's: within one job, a source
 *   record appears once, so re-analysing the same files updates rows instead
 *   of doubling them.
 * - `import_rows_tenant_src_idx` is added here. SAA-15 asks for idempotency
 *   "by source identifier", and an operator who uploads the same export twice
 *   starts **two** jobs — so the committer also asks "did an earlier job of
 *   this operator already import WooCommerce booking 5512?", which needs the
 *   tenant, the type and the id without the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_job_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();

            // `product` | `booking` | `customer` | `category` | `people_type`.
            $table->string('source_type', 32);
            $table->string('source_id', 64);

            // The raw record, as parsed. NOT NULL, default on the model (ENV-12).
            $table->json('source_payload');

            $table->string('target_type', 48)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            // `pending` | `mapped` | `skipped` | `imported` | `failed`.
            $table->string('status', 16)->default('pending');

            // `[{"key": "imports.reasons.past", "params": {...}}]`.
            $table->json('messages');

            $table->timestamps();

            $table->unique(['import_job_id', 'source_type', 'source_id'], 'import_rows_job_src_uq');
            $table->index(['tenant_id', 'import_job_id', 'status'], 'import_rows_tenant_job_status_idx');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'import_rows_tenant_src_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_rows');
    }
};
