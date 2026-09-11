<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One import an operator started from WooCommerce / YITH Booking (spec SAA-13,
 * SAA-14, SAA-15; `docs/data-model.md` §2.7).
 *
 * The shape is the data model's, column for column. Two notes on how it is
 * built rather than what it holds:
 *
 * - **`mapping` and `stats` are NOT NULL with no database default.** MySQL 8
 *   refuses a literal DEFAULT on JSON and the expression form has no SQLite
 *   equivalent (ENV-12), so the `{}` lives on the model — the same answer
 *   `products.images` gives.
 * - **`connection` is encrypted text, not JSON.** It holds either a WooCommerce
 *   consumer key and secret or the private paths of the uploaded files, and
 *   the `encrypted:array` cast is what keeps a database dump from carrying
 *   either in the clear.
 *
 * A new table, so its foreign keys are declared here rather than added to an
 * existing table later — which SQLite cannot do (data-model §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique('import_jobs_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `woocommerce_yith` | `csv` | `wxr` — PHP enum `ImportSource`.
            $table->string('source', 32);

            // `pending` | `analysing` | `mapping_review` | `running` |
            // `completed` | `failed` | `cancelled` — PHP enum `ImportStatus`.
            $table->string('status', 16)->default('pending');

            // SAA-14: the dry run is the first pass, always. It flips once, when
            // the operator confirms the reviewed mapping.
            $table->boolean('is_dry_run')->default(true);

            $table->text('connection')->nullable();
            $table->json('mapping');
            $table->json('stats');

            // Human-readable, truncated to the last 64 KB by the model.
            $table->text('log')->nullable();
            $table->string('error_message', 1000)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'import_jobs_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
