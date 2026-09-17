<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TripQuestionType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A guest's answer to a {@see TripQuestion} (2026-09-17).
 *
 * `question` is the question **as it was asked** — label, type, options — so
 * what the operator reads on the booking, the manifest and the exports is what
 * the guest answered, whatever the question says today.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $booking_guest_id null for a per-booking question
 * @property int|null $trip_question_id
 * @property array<string, mixed> $question
 * @property string $answer `yes`/`no`, a choice's index, or the text typed
 */
class BookingAnswer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingAnswerFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'question' => 'array',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<BookingGuest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(BookingGuest::class, 'booking_guest_id');
    }

    /** The question's label in `$locale`, from the copy. */
    public function label(?string $locale = null): string
    {
        return self::pick((array) ($this->question['label'] ?? []), $locale);
    }

    /** The answer as a person reads it: «Ναι», the choice's name, the text. */
    public function display(?string $locale = null): string
    {
        return match (TripQuestionType::tryFrom((string) ($this->question['type'] ?? ''))) {
            TripQuestionType::YesNo => __('questions.answer.' . ($this->answer === 'yes' ? 'yes' : 'no'), [], $locale),
            TripQuestionType::Choice => self::pick((array) (($this->question['options'] ?? [])[(int) $this->answer] ?? []), $locale),
            default => $this->answer,
        };
    }

    /** «Μεταφορά: Ναι» */
    public function line(?string $locale = null): string
    {
        return $this->label($locale) . ': ' . $this->display($locale);
    }

    /**
     * Several answers as one cell, for the manifest, the boarding list and
     * the exports.
     *
     * @param  iterable<self>  $answers
     */
    public static function joined(iterable $answers, ?string $locale = null): string
    {
        return (new Collection($answers))
            ->map(static fn (self $answer): string => $answer->line($locale))
            ->implode(' · ');
    }

    /** @param  array<mixed>  $translations */
    private static function pick(array $translations, ?string $locale): string
    {
        $locale ??= app()->getLocale();
        $text = $translations[$locale] ?? $translations['el'] ?? reset($translations);

        return is_string($text) ? $text : '';
    }
}
