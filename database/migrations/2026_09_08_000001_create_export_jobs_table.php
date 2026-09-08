<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bookings and guests CSV exports, and the record of who took one
 * (spec OPS-17, OPS-18, OPS-10).
 *
 * ## A table the data model does not have, and why one is needed
 *
 * `docs/data-model.md` §6 lists `import_jobs` and `manifest_exports` and no
 * counterpart for the ordinary exports. OPS-18 asks for four properties —
 * *"run as queued jobs, are streamed to avoid memory pressure, are delivered as
 * a download link that expires after 24 hours, and are logged"* — and three of
 * them need a row. A queued job needs somewhere to report to; a link that
 * expires needs a recorded expiry; and *logged* is this table. The alternative,
 * a signed URL over a file on disk with nothing in the database, has no way to
 * answer "what happened to the export I asked for" and no way to find the file
 * again in order to delete it.
 *
 * The name mirrors `import_jobs` deliberately. They are the same shape of
 * thing — a long-running file operation an operator starts and comes back to —
 * and a reader who has met one should not have to learn a second vocabulary.
 *
 * ## `uuid`, because the download link is a shareable identifier
 *
 * §1 requires one on every entity *"exposed through the API or a shareable
 * link"*. `id` in a download URL would let an operator count the platform's
 * exports and walk another tenant's rows by guessing; the request would still
 * be refused, but a refusal that confirms a row exists is itself an answer.
 *
 * ## The file's location is a column, not a convention
 *
 * A path derived from the row's attributes cannot be found again once any part
 * of that derivation changes, and the purge job's whole job is finding files
 * again. `disk` is stored beside it for the same reason: local development
 * writes to `local` and production to `s3`, and a file written on one and
 * looked for on the other is a leak that no screen ever reveals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique('export_jobs_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Null when the person who asked has since left. The row outlives
            // them because "who exported the guest list in July" is a question
            // that survives an employment, and `manifest_exports` answers it
            // the same way.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // `bookings` | `guests` — PHP enum `ExportType`, never MySQL ENUM.
            $table->string('type', 16);

            // `booked` | `departure` | `paid` — PHP enum `ExportDateBasis`.
            //
            // Stored rather than inferred, because it is the single thing that
            // decides which rows are in the file. An accountant reconciling a
            // total against a bank statement needs to know which date the
            // window was measured on, and a CSV cannot carry a comment line
            // without breaking every parser that reads it.
            $table->string('date_basis', 16);

            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            // The rest of what was asked for — statuses, products, vessels —
            // kept so a re-run can be reproduced, exactly as
            // `manifest_exports.columns` is (§2.6).
            $table->json('filters');

            // `queued` | `processing` | `ready` | `failed` | `expired`.
            $table->string('status', 16)->default('queued');

            $table->string('disk', 32)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('filename', 160)->nullable();

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedBigInteger('byte_size')->default(0);

            // Already translated when it is written: NFR-8 requires the
            // operator to read a human-readable Greek message, and the job that
            // failed is the only place that knows what went wrong.
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Set on completion, never on request (OPS-18). Twenty-four hours
            // measured from the moment the operator asked would silently become
            // twenty-one for an export that waited three hours behind a
            // catalogue import.
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);

            $table->timestamps();

            // The panel's list: this tenant's exports, newest first.
            $table->index(['tenant_id', 'created_at'], 'export_jobs_tenant_created_idx');

            // **Cross-tenant.** The purge sweeper visits every operator's
            // expired rows in one pass, so this index deliberately does not
            // lead with `tenant_id` — the same shape as
            // `wh_deliveries_retry_idx` (§2.9).
            $table->index(['status', 'expires_at'], 'export_jobs_purge_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
