{{--
    One of the operator's checkout questions (2026-09-17).

    Yes/no as two radios, a choice as a select, text as one line. `$given` is
    the answer already stored, for a guest back from a failed payment.

    @var \App\Models\TripQuestion $question
    @var string $name   the input name
    @var string $key    the validation key
    @var string $id     the DOM id
    @var string|null $given
--}}
@php
    use App\Enums\TripQuestionType;

    $value = old($key, $given);
    $locale = app()->getLocale();
@endphp

<div class="trip-question">
    @if ($question->type === TripQuestionType::YesNo)
        <p class="question-label" id="{{ $id }}">{{ $question->label }}</p>
        <div class="choice-row" role="radiogroup" aria-labelledby="{{ $id }}">
            @foreach (['yes', 'no'] as $option)
                <label class="inline">
                    <input type="radio" name="{{ $name }}" value="{{ $option }}" @checked($value === $option) @required($question->is_required)>
                    {{ __('questions.answer.' . $option) }}
                </label>
            @endforeach
        </div>
    @elseif ($question->type === TripQuestionType::Choice)
        <label for="{{ $id }}">{{ $question->label }}</label>
        <select id="{{ $id }}" name="{{ $name }}" @required($question->is_required)>
            <option value="">{{ __('questions.choose') }}</option>
            @foreach ($question->options ?? [] as $index => $option)
                <option value="{{ $index }}" @selected((string) $value === (string) $index)>{{ $option[$locale] ?? $option['el'] ?? '' }}</option>
            @endforeach
        </select>
    @else
        <label for="{{ $id }}">{{ $question->label }}</label>
        <input id="{{ $id }}" name="{{ $name }}" type="text" maxlength="500" value="{{ $value }}" @required($question->is_required)>
    @endif

    @error($key) <p class="field-error">{{ $message }}</p> @enderror
</div>
