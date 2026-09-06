{{--
    `/g/{guest_details_token}` — TOK-8, TOK-9, TOK-10, SEC-14.

    The explanation comes **before** the fields, not after them and not behind a
    link. TOK-9 asks for it in plain language, and the reason is practical
    rather than legal: a guest asked for a passport number by a website with no
    explanation closes the tab, and an operator who cannot file a manifest
    cannot sail.

    The rows are fixed by the pax breakdown (TOK-8) — there is no "add a
    passenger" control, because that would let somebody add a person to a boat
    with a capacity, after the seats were counted and the money taken.

    After the retention window the document fields are simply not rendered
    (TOK-10). That is a **rendering branch**, which is what the issue asked for:
    the alternative is a 500 in a year's time on a page a guest still has a link
    to.
--}}
@extends('guest.layout', ['title' => __('guest.details.title')])

@php
    use App\Enums\GuestDocumentType;
@endphp

@section('content')

    <div class="card">
        <h1>{{ __('guest.details.title') }}</h1>
        <p class="muted">{{ __('guest.common.reference') }}: <strong>{{ $booking->reference }}</strong></p>
        <p>{{ __('guest.details.intro') }}</p>
    </div>

    @if ($needsDocuments)
        {{-- TOK-9's three questions, in the order a person asks them. --}}
        <div class="notice">
            <h2>{{ __('guest.details.why.heading') }}</h2>
            <p>{{ __('guest.details.why.manifest') }}</p>
            <p>{{ __('guest.details.why.retention') }}</p>
            <p>{{ __('guest.details.why.who') }}</p>
        </div>
    @endif

    @if ($readOnly)
        <div class="notice">{{ __('guest.details.read_only') }}</div>
    @endif

    <form method="post" action="{{ route('guest.details.save', ['token' => $token]) }}">
        @csrf

        @foreach ($guests as $index => $guest)
            @php $purged = $guest->document_purged_at !== null; @endphp

            <div class="card">
                <h2>{{ __('guest.details.guest', ['position' => $guest->position]) }}</h2>

                {{-- The position travels with the row. `SaveGuestDetails`
                     writes back **by position** and creates nothing, so a
                     submission naming a position that does not exist is
                     ignored rather than refused. --}}
                <input type="hidden" name="guests[{{ $index }}][position]" value="{{ $guest->position }}">

                <label for="name-{{ $index }}">{{ __('guest.details.full_name') }}</label>
                <input id="name-{{ $index }}" name="guests[{{ $index }}][full_name]"
                       value="{{ $guest->full_name }}" @disabled($readOnly)>

                @if ($needsDocuments && $purged)
                    {{-- TOK-10 after the purge: the summary, without the fields.
                         Not an error, and not an empty form that invites a guest
                         to type a passport number nobody will keep. --}}
                    <p class="muted">{{ __('guest.details.purged') }}</p>
                @elseif ($needsDocuments)
                    <div class="field-pair">
                        <div>
                            <label for="dob-{{ $index }}">{{ __('guest.details.date_of_birth') }}</label>
                            <input id="dob-{{ $index }}" type="date" name="guests[{{ $index }}][date_of_birth]"
                                   value="{{ $guest->date_of_birth?->toDateString() }}" @disabled($readOnly)>
                        </div>
                        <div>
                            <label for="nat-{{ $index }}">{{ __('guest.details.nationality') }}</label>
                            <input id="nat-{{ $index }}" name="guests[{{ $index }}][nationality]"
                                   value="{{ $guest->nationality }}" @disabled($readOnly)>
                        </div>
                    </div>

                    <div class="field-pair">
                        <div>
                            <label for="dtype-{{ $index }}">{{ __('guest.details.document_type') }}</label>
                            <select id="dtype-{{ $index }}" name="guests[{{ $index }}][document_type]" @disabled($readOnly)>
                                <option value=""></option>
                                @foreach (GuestDocumentType::cases() as $type)
                                    <option value="{{ $type->value }}" @selected($guest->document_type === $type)>
                                        {{ $type->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="dnum-{{ $index }}">{{ __('guest.details.document_number') }}</label>
                            {{-- `autocomplete="off"`: a browser that remembered
                                 a passport number would keep it long after the
                                 retention window this page promises. --}}
                            <input id="dnum-{{ $index }}" name="guests[{{ $index }}][document_number]"
                                   value="{{ $guest->document_number }}" autocomplete="off" @disabled($readOnly)>
                        </div>
                    </div>

                    <label for="dexp-{{ $index }}">{{ __('guest.details.document_expires_on') }}</label>
                    <input id="dexp-{{ $index }}" type="date" name="guests[{{ $index }}][document_expires_on]"
                           value="{{ $guest->document_expires_on?->toDateString() }}" @disabled($readOnly)>
                @endif
            </div>
        @endforeach

        @if ($needsCharterAgreement)
            <div class="card">
                <h2>{{ __('guest.details.charter_agreement.heading') }}</h2>
                <p class="muted">{{ __('guest.details.charter_agreement.body') }}</p>
                <label>
                    {{-- TOK-8's checkbox. The document itself is M6's; the
                         evidence columns already exist, so M6 renders against
                         evidence collected here. --}}
                    <input type="checkbox" name="charter_agreement" value="1" style="width:auto" @disabled($readOnly)>
                    {{ __('guest.details.charter_agreement.accept') }}
                </label>
            </div>
        @elseif ($booking->terms_accepted_at)
            <div class="notice">
                {{ __('guest.details.charter_agreement.accepted', ['date' => $booking->terms_accepted_at->format('d/m/Y')]) }}
            </div>
        @endif

        @unless ($readOnly)
            <div class="card">
                <p class="muted">{{ __('guest.details.partial') }}</p>
                <button class="btn" type="submit">{{ __('guest.common.save') }}</button>
            </div>
        @endunless
    </form>

@endsection
