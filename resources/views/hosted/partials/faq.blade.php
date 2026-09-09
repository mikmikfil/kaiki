{{--
    The operator's answers, wherever they are shown (#103).

    One partial for both surfaces. The home page passes the tenant-wide entries;
    the product page of #104 passes that product's entries plus the tenant-wide
    ones — the difference is decided in `App\Domain\Hosted\Actions\BuildFaqList`
    and not here, so the two pages cannot come to disagree about what
    "published" means.

    **Renders nothing at all when there are no entries.** Not an empty heading
    and not "no questions yet": an operator who has not written an FAQ has a page
    with no FAQ section, which is what they would have had before the block
    existed.

    ## `<details>`, not JavaScript

    HOS-4: everything works without scripts. A native disclosure element opens
    and closes on its own, is reachable from the keyboard, and announces its
    state to a screen reader without a line of code — and the answers are in the
    markup whether they are open or shut, which is what the crawler reading the
    `FAQPage` block below needs.

    ## The cancellation policy is one more question, and it is not in the schema

    The trip page passes `$policy`, and it renders as a final disclosure after
    the operator's own. It is deliberately **absent from the `FAQPage` block**
    below: that markup is a claim to a search engine about questions this
    operator answered, and the cancellation answer is generated from a policy
    row. A rich result quoting a sentence nobody wrote is the kind of thing that
    is right until the policy changes and Google is still showing last season's.

    Expects: $entries (Collection<Faq>), $heading (?string), $anchor (?string),
    $policy (?CancellationPolicy), and $nonce from the layout.
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Faq> $entries */
    $schema = \App\Domain\Hosted\Support\FaqSchema::json($entries);

    // The home page's FAQ block has no product and therefore no policy.
    $policy = $policy ?? null;
@endphp

@if ($entries->isNotEmpty() || $policy)
    <section class="block faq" @if ($anchor ?? null) id="{{ $anchor }}" @endif>
        @if ($heading ?? null)
            <h2>{{ $heading }}</h2>
        @endif

        <div class="faq-list">
            @foreach ($entries as $entry)
                <details class="faq-item">
                    <summary>{{ $entry->question }}</summary>
                    {{-- Through `BlockText`, like every other piece of operator
                         prose on these pages: escaped first, paragraphs after. --}}
                    <div class="prose">{{ $entry->prose() }}</div>
                </details>
            @endforeach

            @if ($policy)
                <details class="faq-item">
                    <summary>{{ __('hosted.product.cancellation_question') }}</summary>
                    <div class="prose">
                        <p><strong>{{ $policy->name }}</strong></p>
                        @if ($policy->summary)
                            <p>{{ $policy->summary }}</p>
                        @endif
                        @if ($policy->free_cancellation_hours)
                            <p>{{ __('hosted.product.free_cancellation', ['hours' => $policy->free_cancellation_hours]) }}</p>
                        @endif
                        @if ($policy->tiers->isNotEmpty())
                            <ul class="tiers">
                                @foreach ($policy->tiers as $tier)
                                    <li>{{ __('hosted.product.tier', ['days' => $tier->days_before, 'percent' => $tier->refund_percent]) }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="muted">{{ __('hosted.product.weather_refund', ['percent' => $policy->weather_refund_percent]) }}</p>
                    </div>
                </details>
            @endif
        </div>

        @if ($schema)
            {{-- HOS-8's policy has no `unsafe-inline`, and a browser applies
                 `script-src` to a script element whatever its type — without the
                 nonce this block is dropped silently, which is the same outcome
                 as not writing it. The JSON is escaped by `FaqSchema`, which is
                 why this is `{{ }}` and not `{!! !!}`. --}}
            <script type="application/ld+json" nonce="{{ $nonce }}">{{ $schema }}</script>
        @endif
    </section>
@endif
