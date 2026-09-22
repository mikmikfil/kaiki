{{--
    `/c/{manage_token}` — the checkout page (amends WGT-18).

    The widget answers the two questions a guest can answer while still
    browsing — which day, how many people — creates the draft, and sends them
    here. Everything that follows is one page: what it costs, who is coming, and
    the button that goes to the gateway.

    ## The price is rendered, not fetched

    Every figure below comes from `bookings.price_snapshot`, frozen when the
    draft was created (§3.4) and the same figure the gateway will be asked for.
    That is deliberate and it is the fix for a real defect: the widget's old
    review step waited on a quote nobody requested and showed «Υπολογίζουμε την
    τιμή σας…» for ever, so a guest pressed pay having never seen a total. A
    page that renders a stored snapshot has no request to forget to make.

    ## The hold is stated, not counted down

    `hold_expires_at` is printed as a time, not as a ticking clock: a countdown
    needs a script, this page has none, and a wrong countdown is worse than an
    honest sentence. WGT-19's live timer belongs to the widget, which is where
    the seconds actually matter.
--}}
@extends('guest.layout', ['title' => __('guest.checkout.title'), 'wide' => true])

@php
    use App\Support\Format\MoneyFormatter;

    $locale = app()->getLocale();
    $money = static fn (int $cents): string => MoneyFormatter::format($cents, $locale, MoneyFormatter::currency());

    $snapshot = is_array($booking->price_snapshot) ? $booking->price_snapshot : [];
    $lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
    $deposit = is_array($snapshot['deposit'] ?? null) ? $snapshot['deposit'] : null;

    // What the button is about to charge — the deposit when there is one, the
    // whole total otherwise. The same test the controller makes, so the label
    // and the charge are one decision rather than two that can drift.
    $depositCents = (int) ($deposit['amount_cents'] ?? 0);
    $takesDeposit = $depositCents > 0 && $depositCents < $booking->total_cents;
    $dueNow = $takesDeposit ? $depositCents : $booking->total_cents;

    // The label in each line is stored per locale, exactly as the catalogue
    // stores every other translatable string.
    $labelOf = static function (array $line) use ($locale): string {
        $label = $line['label'] ?? null;

        return is_array($label)
            ? (string) ($label[$locale] ?? reset($label))
            : (string) ($label ?? '');
    };
@endphp

@section('content')

{{-- Decided by the controller (`backToSiteUrl()`): the page the guest came
     from, else the operator's hosted home, else nothing. --}}
@if ($backUrl)
    <p class="back-to-site"><a href="{{ $backUrl }}">{{ __('guest.back_to_site') }}</a></p>
@endif

<div class="checkout-grid">

    <aside class="checkout-side">

    <div class="card">
        {{--
            **The trip's own photograph** (product owner, 2026-09-22). The
            summary listed a title, a date and a number of people — the shape
            of a receipt, on the page where somebody decides whether to pay. The
            picture is the thing they chose, and it is already on the trip page
            they came from.

            `ImagePayload` hands the gallery back in the operator's order, so
            `[0]` is the one that leads the card everywhere else too. Absent
            rather than a grey box when the trip has no photograph yet.
        --}}
        @php
            $cover = \App\Domain\Media\Support\ImagePayload::collection(
                $booking->product?->images,
                $locale,
            )[0] ?? null;
        @endphp

        @if ($cover !== null)
            <div class="trip-photo">
                <img src="{{ $cover['url'] }}" alt="{{ $cover['alt'] ?? '' }}" loading="lazy">
            </div>
        @endif

        <h1>{{ __('guest.checkout.title') }}</h1>
        {{-- `dl.rows` is the layout's own two-column list, used by every other
             guest page. A second pattern here would be a second thing to keep
             in step.

             The reference is the first row of it rather than a sentence above
             it, which is where it used to be: on a phone that put one value
             hard left while every value under it was right-aligned, and the
             column of figures read as though the code had been left out of
             it. --}}
        <dl class="rows">
            <dt>{{ __('guest.common.reference') }}</dt>
            <dd><strong>{{ $booking->reference }}</strong></dd>

            <dt>{{ __('guest.checkout.trip') }}</dt>
            <dd>{{ $booking->product?->title }}</dd>

            <dt>{{ __('guest.checkout.when') }}</dt>
            <dd>{{ $booking->local_date->format('d/m/Y') }} · {{ substr($booking->local_time, 0, 5) }}</dd>

            <dt>{{ __('guest.checkout.party') }}</dt>
            <dd>{{ trans_choice('guest.checkout.people', $booking->pax_total, ['count' => $booking->pax_total]) }}</dd>
        </dl>

        @if ($booking->hold_expires_at)
            {{-- ADR-0005's hold, in the unit the question is asked in.

                 «Μέχρι τις 13:43» made a guest read a clock and subtract. It
                 now leads with the minutes — and keeps the time, because this
                 page has no script and the minutes are therefore frozen at the
                 moment it rendered. Either alone can mislead somebody who left
                 the tab open; together they cannot, because the time is what
                 stays true and the minutes are what is immediately legible. --}}
            @php
                $expires = $booking->hold_expires_at->timezone($timezone);
                $secondsLeft = now()->diffInSeconds($booking->hold_expires_at, false);
                $minutes = (int) ceil($secondsLeft / 60);
            @endphp

            {{-- An expired hold says so.

                 It used to read `max(1, …)`, so a hold that ran out twenty
                 minutes ago printed «άλλο ένα λεπτό» — the page telling a guest
                 their seats are held when they are not, which is the one thing
                 this sentence must never do. The floor was there to keep the
                 plural rule fed; the honest branch is a different sentence. --}}
            <p class="muted">
                @if ($secondsLeft <= 0)
                    {{ __('guest.checkout.hold_expired') }}
                @else
                    {{ __(
                        $minutes === 1 ? 'guest.checkout.hold_minutes_one' : 'guest.checkout.hold_minutes_many',
                        ['count' => $minutes, 'time' => $expires->format('H:i')],
                    ) }}
                @endif
            </p>
        @endif
    </div>

    {{-- The breakdown. Every line the snapshot holds, then the total the button
         below is going to charge — the two cannot disagree, because they are
         read from the same array. --}}
    <div class="card">
        <h2>{{ __('guest.checkout.price') }}</h2>

        <dl class="rows">
            @foreach ($lines as $line)
                <dt>
                    {{ $labelOf($line) }}
                    @if (($line['qty'] ?? 1) > 1)
                        × {{ $line['qty'] }}
                    @endif
                </dt>
                <dd>{{ $money((int) ($line['total_cents'] ?? 0)) }}</dd>
            @endforeach

            @if ((int) ($snapshot['discount_cents'] ?? 0) > 0)
                <dt>
                    {{ __('guest.checkout.discount') }}
                    @if (is_array($discountCode ?? null))
                        <span class="muted">({{ $discountCode['code'] }})</span>
                    @endif
                </dt>
                <dd>−{{ $money((int) $snapshot['discount_cents']) }}</dd>
            @endif

            <dt class="total">{{ __('guest.checkout.total') }}</dt>
            <dd class="total">{{ $money($booking->total_cents) }}</dd>
        </dl>

        <p class="muted">{{ __('guest.checkout.vat_included') }}</p>

        {{-- «Κουπόνι» (2026-09-17). Its own form, outside the payment form:
             applying a code shows the new total, it does not pay. --}}
        <form method="post" action="{{ route('guest.checkout.code', ['token' => $token]) }}" class="discount-form" novalidate>
            @csrf
            @if (is_array($discountCode ?? null))
                <p class="muted">{{ __('discount_codes.checkout.using', ['code' => $discountCode['code']]) }}</p>
                <button type="submit" name="remove" value="1" class="btn btn-quiet">{{ __('discount_codes.checkout.remove') }}</button>
            @else
                <label for="discount_code">{{ __('discount_codes.checkout.label') }}</label>
                <div class="discount-row">
                    <input id="discount_code" name="discount_code" type="text" maxlength="32" autocomplete="off"
                           autocapitalize="characters" value="{{ old('discount_code') }}">
                    <button type="submit" class="btn btn-quiet">{{ __('discount_codes.checkout.apply') }}</button>
                </div>
            @endif
            @error('discount_code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            @if (session('discount_status'))
                <p class="muted" role="status">{{ session('discount_status') }}</p>
            @endif
        </form>

        @if ($takesDeposit)
            <p class="muted">{{ __('guest.checkout.deposit_note', [
                'deposit' => $money($depositCents),
                'balance' => $money($booking->total_cents - $depositCents),
            ]) }}</p>
        @endif

        {{-- What happens if they cannot come, said before they pay rather than
             after.

             It is the sentence out of `policy_snapshot` — the same frozen copy
             a refund is computed from (§5.9) — so this cannot promise one thing
             and the refund do another. On a day trip that a north wind can
             cancel, it is the question a guest actually has at this button, and
             the page answered it nowhere: the consent line named a policy and
             did not show it. --}}
        @if ($policySummary !== null || ($policyLines ?? []) !== [])
            <div class="policy">
                <h3>{{ __('guest.checkout.policy_heading') }}</h3>
                @if ($policySummary !== null)
                    <p class="muted">{{ $policySummary }}</p>
                @endif

                {{-- The full policy, a press away and without leaving the page
                     (2026-09-17). A `:target` window rather than a script: the
                     guest page's policy allows no inline script, and a link to
                     `#policy-full` opens it with none. --}}
                @if (($policyLines ?? []) !== [])
                    <p><a class="policy-link" href="#policy-full">{{ __('guest.policy.link') }}</a></p>
                @endif
            </div>
        @endif

        @if (($policyLines ?? []) !== [])
            <div id="policy-full" class="policy-window" role="dialog" aria-modal="true" aria-labelledby="policy-full-heading">
                <div class="policy-window-box">
                    <h2 id="policy-full-heading">{{ __('guest.policy.heading') }}</h2>
                    <ul class="policy-lines">
                        @foreach ($policyLines as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                    <a class="policy-close" href="#">{{ __('guest.policy.close') }}</a>
                </div>
            </div>
        @endif

        {{-- The button lives here, under the price it is about to charge, and
             submits the form in the other column through `form=` — which is
             what that attribute is for and avoids a second copy of the markup
             or a script to bridge the two.

             The Viva line sits with it rather than at the end of the form:
             pressing pay leaves this site, and somebody about to type a card
             number should know whose page they are about to land on. ADR-0004 —
             the card is entered on Viva's own checkout and neither Kaiki nor the
             operator ever sees it. --}}
        <button type="submit" form="checkout-form" class="btn pay">{{ __('guest.checkout.pay', ['amount' => $money($dueNow)]) }}</button>

        {{-- The trust block. A page that asks for money and says nothing about
             where it goes reads as a scam, which is what this is here to fix.

             The mark is the operator's real one when it exists: drop Viva's
             official SVG at `public/hosted/viva.svg` and it is used instead of
             the wordmark below, with no further change. It is deliberately not
             drawn by hand — approximating another company's logo is worse than
             printing their name in this page's own type, and Viva's brand kit
             is where the file has to come from. --}}
        <div class="pay-secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <rect x="4" y="10.4" width="16" height="10.2" rx="2.4"/>
                <path d="M7.8 10.4V7.6a4.2 4.2 0 0 1 8.4 0v2.8"/>
                <path d="M12 14.6v2"/>
            </svg>

            <div>
                @if (file_exists(public_path('hosted/viva.svg')))
                    <img class="pay-logo" src="{{ url('/hosted/viva.svg') }}" alt="Viva Wallet" height="18">
                @else
                    <p class="pay-brand">Viva Wallet</p>
                @endif

                <p class="muted secure">{{ __('guest.checkout.secure') }}</p>
            </div>
        </div>
    </div>

    </aside>

    <div class="checkout-main">

    @error('checkout')
        <div class="notice notice-error" role="alert">
            <p>{{ $message }}</p>
        </div>
    @enderror

    {{-- `novalidate`, and the server does the checking.

         The browser's own validation looked free and was not. It speaks the
         *device's* language, so a Greek page on a phone set to English refuses
         with «Please fill out this field»; it reports one field at a time, so a
         guest fixes four things in four round trips; and its bubble vanishes on
         the next tap, leaving somebody staring at a form that will not submit
         and no longer says why.

         Laravel already validates every one of these rules and the template
         already renders `@error` beside each field in the guest's own language.
         Turning the native layer off is what lets those be *seen* — before,
         the browser refused first and the server's Greek messages were
         unreachable for anyone the browser could catch.

         Nothing is weakened by this: `required` stays on the inputs for the
         accessibility tree, and the server was always the thing that decided. --}}
    <form id="checkout-form" method="post" action="{{ route('guest.checkout.pay', ['token' => $token]) }}" class="card" novalidate>
        @csrf

        {{-- Every failure at once, at the top, each one a link to the field it
             came from. A phone shows one field at a time; without this, «what
             is still wrong» is a question only scrolling can answer. --}}
        @if ($errors->any())
            <div class="notice notice-error" role="alert" tabindex="-1" id="form-errors">
                <p><strong>{{ __('guest.checkout.errors_heading') }}</strong></p>
                <ul class="error-list">
                    @foreach ($errors->keys() as $field)
                        @continue($field === 'checkout')
                        <li><a href="#{{ \App\Support\Format\FieldAnchor::for($field) }}">{{ $errors->first($field) }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <h2>{{ __('guest.checkout.your_details') }}</h2>

        <label for="guest_name">{{ __('guest.checkout.name') }}</label>
        <input id="guest_name" name="guest_name" type="text" required autocomplete="name"
               value="{{ old('guest_name', $booking->guest_name) }}">
        @error('guest_name') <p class="field-error">{{ $message }}</p> @enderror

        <label for="guest_email">{{ __('guest.checkout.email') }}</label>
        <input id="guest_email" name="guest_email" type="email" required autocomplete="email"
               value="{{ old('guest_email', $booking->guest_email) }}">
        @error('guest_email') <p class="field-error">{{ $message }}</p> @enderror

        <label for="guest_phone">{{ __('guest.checkout.phone') }}</label>
        <input id="guest_phone" name="guest_phone" type="tel" autocomplete="tel"
               value="{{ old('guest_phone', $booking->guest_phone) }}">
        @error('guest_phone') <p class="field-error">{{ $message }}</p> @enderror

        <label for="special_requests">{{ __('guest.checkout.special_requests') }}</label>
        <textarea id="special_requests" name="special_requests" rows="3">{{ old('special_requests', $booking->special_requests) }}</textarea>

        {{-- The operator's own questions, once for the booking (2026-09-17). --}}
        @if ($bookingQuestions->isNotEmpty())
            <h2>{{ __('questions.heading') }}</h2>
            @foreach ($bookingQuestions as $question)
                @include('guest.partials.trip-question', [
                    'question' => $question,
                    'name' => "answers[booking][{$question->uuid}]",
                    'key' => "answers.booking.{$question->uuid}",
                    'id' => 'q_' . $question->uuid,
                    'given' => $givenAnswers['booking']->firstWhere('trip_question_id', $question->getKey())?->answer,
                ])
            @endforeach
        @endif

        @if ($passengers !== [])
            {{-- Only for trips whose `guest_details_required` is set. The
                 explanation comes before the fields for the same reason `/g/`
                 gives it: a guest asked for a document number with no reason
                 given closes the tab. --}}
            <h2>{{ __('guest.checkout.passengers') }}</h2>
            @if ($needsGuestDetails)
                <p class="muted">{{ __('guest.checkout.passengers_why') }}</p>
            @endif

            {{-- One `<details>` per passenger, the first open.

                 Eight passengers laid out flat is twenty-four fields and a page
                 a thumb scrolls past rather than fills in. Folded, the list is
                 as long as the party and a person works down it one at a time.

                 `<details>` rather than tabs, and not only because it needs no
                 script: a tab strip hides how many are left, and these are a
                 checklist — somebody has to know they still owe two.

                 A row whose fields failed validation is forced open, or the
                 error is announced on a panel nobody can see. --}}
            {{-- One row per **person**, from `PassengerForm::rows()` — the
                 booking's own `booking_guests` rows, created from
                 `pax_breakdown` band by band, so the row says «Επιβάτης 2 ·
                 Παιδί» and a parent filling in three of these knows which child
                 they are on. Every person, infants included: the manifest counts
                 people on the boat, not seats.

                 Each row posts its `position`, which is the key the manifest is
                 written by. It posted none before, and every passenger typed
                 here was dropped on the floor.

                 Passport or identity card, and nothing else (2026-09-17). The
                 expiry date belongs to a passport only; it hides itself when
                 «Ταυτότητα» is chosen, with CSS `:has()` and no script, and a
                 browser without `:has()` simply shows it. A «Χωρίς έγγραφο»
                 band gets no document fields at all. --}}
            @foreach ($passengers as $i => $row)
                @php
                    $guest = $row['guest'];
                    $hasError = collect(['full_name', 'nationality', 'date_of_birth', 'document_type', 'document_number', 'document_expires_on'])
                        ->contains(static fn (string $field): bool => $errors->has("guests.$i.$field"))
                        || $errors->has("answers.guests.$i.*");
                    $typed = old("guests.$i.full_name", $guest->full_name);
                @endphp

                <details class="passenger" @if ($i === 0 || $hasError) open @endif>
                    <summary>
                        {{ __('guest.checkout.passenger_n', ['n' => $i + 1]) }}
                        @if ($row['label'] !== '')
                            <span class="passenger-band">{{ $row['label'] }}</span>
                        @endif
                        @if ($typed)
                            <span class="passenger-name">{{ $typed }}</span>
                        @endif
                    </summary>

                    <div class="passenger-body">
                        <input type="hidden" name="guests[{{ $i }}][position]" value="{{ $row['position'] }}">

                        <label for="g{{ $i }}_name">{{ __('guest.checkout.full_name') }}</label>
                        <input id="g{{ $i }}_name" name="guests[{{ $i }}][full_name]" type="text" required
                               value="{{ old("guests.$i.full_name", $guest->full_name) }}">
                        @error("guests.$i.full_name") <p class="field-error">{{ $message }}</p> @enderror

                        @if ($needsGuestDetails)
                        <label for="g{{ $i }}_nat">{{ __('guest.checkout.nationality') }}</label>
                        @php $chosenNationality = old("guests.$i.nationality", $guest->nationality); @endphp
                        <select id="g{{ $i }}_nat" name="guests[{{ $i }}][nationality]" required autocomplete="country">
                            <option value=""></option>
                            @foreach (\App\Support\Countries::options(app()->getLocale()) as $code => $country)
                                <option value="{{ $code }}" @selected($chosenNationality === $code)>{{ $country }}</option>
                            @endforeach
                        </select>
                        @error("guests.$i.nationality") <p class="field-error">{{ $message }}</p> @enderror

                        <label for="g{{ $i }}_dob">{{ __('guest.checkout.date_of_birth') }}</label>
                        <input id="g{{ $i }}_dob" name="guests[{{ $i }}][date_of_birth]" type="date" required
                               value="{{ old("guests.$i.date_of_birth", $guest->date_of_birth?->toDateString()) }}">
                        @error("guests.$i.date_of_birth") <p class="field-error">{{ $message }}</p> @enderror
                        @endif

                        @if (! $needsGuestDetails)
                            {{-- Only a name: the panel is here for the questions. --}}
                        @elseif ($row['no_document'])
                            <p class="muted">{{ __('guest.checkout.no_document') }}</p>
                        @else
                            @php $chosen = old("guests.$i.document_type", $guest->document_type?->value); @endphp

                            <label for="g{{ $i }}_dtype">{{ __('guest.checkout.document_type') }}</label>
                            <select id="g{{ $i }}_dtype" name="guests[{{ $i }}][document_type]" required>
                                <option value=""></option>
                                @foreach (\App\Enums\GuestDocumentType::cases() as $type)
                                    <option value="{{ $type->value }}" @selected($chosen === $type->value)>{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            @error("guests.$i.document_type") <p class="field-error">{{ $message }}</p> @enderror

                            {{-- `autocomplete="off"`: a browser that remembered a
                                 passport number would keep it long after the
                                 retention window the page promises. --}}
                            <label for="g{{ $i }}_doc">{{ __('guest.checkout.document') }}</label>
                            <input id="g{{ $i }}_doc" name="guests[{{ $i }}][document_number]" type="text" required
                                   autocomplete="off" value="{{ old("guests.$i.document_number") }}">
                            @error("guests.$i.document_number") <p class="field-error">{{ $message }}</p> @enderror

                            <div class="passport-expiry">
                                <label for="g{{ $i }}_dexp">{{ __('guest.checkout.document_expires_on') }}</label>
                                <input id="g{{ $i }}_dexp" name="guests[{{ $i }}][document_expires_on]" type="date"
                                       value="{{ old("guests.$i.document_expires_on", $guest->document_expires_on?->toDateString()) }}">
                                @error("guests.$i.document_expires_on") <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @foreach ($personQuestions as $question)
                            @include('guest.partials.trip-question', [
                                'question' => $question,
                                'name' => "answers[guests][{$i}][{$question->uuid}]",
                                'key' => "answers.guests.{$i}.{$question->uuid}",
                                'id' => "g{$i}_q_" . $question->uuid,
                                'given' => ($givenAnswers['guests'][$guest->getKey()] ?? collect())->firstWhere('trip_question_id', $question->getKey())?->answer,
                            ])
                        @endforeach
                    </div>
                </details>
            @endforeach
        @endif

        {{-- The sentence is the lang file's; only the anchor is built here, so
             no translation carries markup. `{!! !!}` because that anchor has to
             survive — the one value that is not a literal is the URL, and it is
             escaped before it goes in. --}}
        <p class="consent">
            <label>
                <input id="terms" type="checkbox" name="terms" value="1" required @checked(old('terms'))>
                {{-- **One span around the whole sentence.** The label is a flex
                     row, so every node inside it was an item of that row: the
                     words before the link, the link, and the full stop after it
                     became three columns, and on a phone they wrapped into a
                     ragged block with a lone «.» floating at the end of it
                     (2026-09-22). --}}
                <span>{!! __('guest.checkout.terms', [
                    'link' => '<a href="' . e($legalUrl) . '" target="_blank" rel="noopener">'
                        . e(__('guest.checkout.terms_link')) . '</a>',
                ]) !!}</span>
            </label>
            @error('terms') <span class="field-error">{{ $message }}</span> @enderror
        </p>

    </form>

    </div>

</div>

@endsection
