<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\TripQuestionScope;
use App\Enums\TripQuestionType;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\BookingGuest;
use App\Models\TripQuestion;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The operator's own questions, at checkout (product owner, 2026-09-17).
 *
 * ## The form
 *
 * Per-booking questions come once, under the lead booker's details, posted as
 * `answers[booking][{uuid}]`. Per-person questions come inside each
 * passenger's panel, posted as `answers[guests][{i}][{uuid}]` beside that
 * panel's `guests[{i}][position]` — so a trip with a per-person question shows
 * the passenger panels even when «Στοιχεία επιβατών» is off, with a name and
 * the questions and nothing else.
 *
 * ## What is kept
 *
 * A {@see BookingAnswer} per answered question, carrying a copy of the
 * question. Saving replaces the booking's answers: a guest who goes back from
 * the gateway and changes «Ναι» to «Όχι» has one answer, not two. An optional
 * question left blank keeps no row.
 */
final class TripQuestionForm
{
    /**
     * The trip's live questions, in the operator's order.
     *
     * @return Collection<int, TripQuestion>
     */
    public static function questionsFor(Booking $booking): Collection
    {
        return TripQuestion::query()
            ->where('product_id', $booking->product_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, TripQuestion>  $questions
     * @return Collection<int, TripQuestion>
     */
    public static function scoped(Collection $questions, TripQuestionScope $scope): Collection
    {
        return $questions->filter(static fn (TripQuestion $q): bool => $q->scope === $scope)->values();
    }

    /**
     * Rules and field names for every question, for the passenger rows posted.
     *
     * @param  Collection<int, TripQuestion>  $questions
     * @param  list<int|string>  $guestIndices  the keys of the posted `guests`
     * @return array{0: array<string, list<mixed>>, 1: array<string, string>}
     */
    public static function rules(Collection $questions, array $guestIndices): array
    {
        $rules = [];
        $names = [];

        foreach (self::scoped($questions, TripQuestionScope::PerBooking) as $question) {
            $key = "answers.booking.{$question->uuid}";
            $rules[$key] = self::ruleFor($question);
            $names[$key] = (string) $question->label;
        }

        foreach ($guestIndices as $i) {
            foreach (self::scoped($questions, TripQuestionScope::PerPerson) as $question) {
                $key = "answers.guests.{$i}.{$question->uuid}";
                $rules[$key] = self::ruleFor($question);
                $names[$key] = __('guest.checkout.passenger_field', ['n' => (int) $i + 1, 'field' => (string) $question->label]);
            }
        }

        return [$rules, $names];
    }

    /**
     * Replace the booking's answers with the ones posted.
     *
     * @param  array<string, mixed>  $answers  `answers` from the request
     * @param  array<int|string, array<string, mixed>>  $guests  `guests` from the request, for positions
     */
    public static function save(Booking $booking, array $answers, array $guests): void
    {
        $questions = self::questionsFor($booking)->keyBy('uuid');

        $guestIds = BookingGuest::query()
            ->where('booking_id', $booking->getKey())
            ->pluck('id', 'position');

        DB::transaction(static function () use ($booking, $answers, $guests, $questions, $guestIds): void {
            BookingAnswer::query()->where('booking_id', $booking->getKey())->delete();

            foreach ((array) ($answers['booking'] ?? []) as $uuid => $value) {
                self::store($booking, $questions->get((string) $uuid), TripQuestionScope::PerBooking, null, $value);
            }

            foreach ((array) ($answers['guests'] ?? []) as $i => $values) {
                $position = (int) ($guests[$i]['position'] ?? 0);
                $guestId = $guestIds->get($position);

                if ($guestId === null) {
                    continue;
                }

                foreach ((array) $values as $uuid => $value) {
                    self::store($booking, $questions->get((string) $uuid), TripQuestionScope::PerPerson, (int) $guestId, $value);
                }
            }
        });
    }

    /**
     * Every answer on a booking, grouped: `booking` for the per-booking ones,
     * and by `booking_guest_id` for the rest.
     *
     * @return array{booking: EloquentCollection<int, BookingAnswer>, guests: array<int|string, EloquentCollection<int, BookingAnswer>>}
     */
    public static function answersOf(Booking $booking): array
    {
        $all = BookingAnswer::query()->where('booking_id', $booking->getKey())->orderBy('id')->get();

        return [
            'booking' => $all->whereNull('booking_guest_id')->values(),
            'guests' => $all->whereNotNull('booking_guest_id')->groupBy('booking_guest_id')->all(),
        ];
    }

    /** @return list<mixed> */
    private static function ruleFor(TripQuestion $question): array
    {
        $presence = $question->is_required ? 'required' : 'nullable';

        return match ($question->type) {
            TripQuestionType::YesNo => [$presence, 'in:yes,no'],
            TripQuestionType::Choice => [$presence, 'in:' . implode(',', array_keys($question->options ?? []))],
            TripQuestionType::Text => [$presence, 'string', 'max:500'],
        };
    }

    private static function store(Booking $booking, ?TripQuestion $question, TripQuestionScope $scope, ?int $guestId, mixed $value): void
    {
        if (! $question instanceof TripQuestion || $question->scope !== $scope) {
            return;
        }

        $answer = is_scalar($value) ? trim((string) $value) : '';

        if ($answer === '') {
            return;
        }

        BookingAnswer::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->getKey(),
            'booking_guest_id' => $guestId,
            'trip_question_id' => $question->getKey(),
            'question' => $question->snapshot(),
            'answer' => $answer,
        ]);
    }
}
