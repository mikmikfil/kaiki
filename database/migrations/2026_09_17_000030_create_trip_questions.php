<?php

declare(strict_types=1);

use App\Models\BookingAnswer;
use App\Models\TripQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions the operator asks at checkout, per trip (product owner, 2026-09-17).
 *
 * ## Why
 *
 * «Χρειάζεστε μεταφορά από το ξενοδοχείο;», «Αλλεργίες;», «Μέγεθος στολής».
 * Every operator has two or three, and they were asked by telephone after the
 * booking — or in «Κάτι που πρέπει να ξέρουμε», where nobody answers them.
 *
 * ## Two tables
 *
 * {@see TripQuestion} is the question, on one trip: a label in both languages,
 * a type (yes/no, a choice, short text), per person or per booking, required
 * or not.
 *
 * {@see BookingAnswer} is an answer, on one booking and, for a per-person
 * question, one passenger. It carries **a copy of the question** as it was
 * when answered, the same way a booking freezes its price and policy: an
 * operator who renames a question or removes a choice in August must not
 * change what a guest answered in June.
 *
 * ## Foreign keys only on the new tables
 *
 * `docs/data-model.md` §6: nothing is added to an existing table. A question
 * removed from a trip is soft-deleted, so its answers keep their link; the
 * answer's copy of the question is what is displayed either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_questions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // {el, en}, like every translatable column (§1.6).
            $table->json('label');

            // `App\Enums\TripQuestionType` and `App\Enums\TripQuestionScope`,
            // string columns backed by PHP enums (ADR-0015).
            $table->string('type', 16);
            $table->string('scope', 16);

            // For a choice: a list of {el, en}, in the operator's order. The
            // answer stores the index.
            $table->json('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'product_id'], 'trip_questions_product_idx');
        });

        Schema::create('booking_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            // Null for a per-booking question.
            $table->foreignId('booking_guest_id')->nullable()->constrained('booking_guests')->cascadeOnDelete();

            $table->foreignId('trip_question_id')->nullable()->constrained('trip_questions')->nullOnDelete();

            // The question as it was asked: label, type, scope, options.
            $table->json('question');

            // `yes`/`no`, a choice's index, or the text typed.
            $table->text('answer');

            $table->timestamps();

            $table->index(['tenant_id', 'booking_id'], 'booking_answers_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_answers');
        Schema::dropIfExists('trip_questions');
    }
};
