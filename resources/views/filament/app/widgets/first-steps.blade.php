{{--
    The four steps before an operator's first booking (spec OPS-1).

    A list rather than a set of cards, and the next step is the only one with a
    button on it — the panel's whole job is to remove the decision about where to
    start. The steps behind it stay visible with a tick, because seeing what you
    have already done is most of what makes a list like this worth reading.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('dashboard.first_steps.heading') }}</x-slot>
        <x-slot name="description">{{ __('dashboard.first_steps.description') }}</x-slot>

        <ol class="kaiki-first-steps">
            @foreach ($this->getSteps() as $step => $done)
                @php
                    $isNext = $step === $this->getNextStep();
                    $isOptional = in_array($step, $this->getOptional(), true);
                @endphp
                <li @class(['is-done' => $done, 'is-next' => $isNext])>
                    <span class="mark" aria-hidden="true">{{ $done ? '✓' : $loop->iteration }}</span>

                    <span class="body">
                        <strong>{{ __('dashboard.first_steps.' . $step . '.title') }}</strong>
                        <span class="why">{{ __('dashboard.first_steps.' . $step . '.why') }}</span>
                    </span>

                    {{-- Every button in one column of its own, the same width
                         in every row (Mike, 25/9: «στη σειρά του, όχι έτσι
                         χύμα»). Each was as wide as its own label and pushed
                         against the right edge, so two buttons in a list never
                         lined up, and on a phone they squeezed the text beside
                         them into three lines. --}}
                    @if ($isNext)
                        <span class="act">
                            <x-filament::button tag="a" size="sm" :href="$this->getLinks()[$step]">
                                {{ __('dashboard.first_steps.' . $step . '.action') }}
                            </x-filament::button>
                        </span>
                    @elseif (! $done && $isOptional)
                        {{-- An optional step is never "next", so it had no way in
                             at all (Mike, 25/9). Its own button, quieter than the
                             one that says what to do now. --}}
                        <span class="act">
                            <x-filament::button tag="a" size="sm" color="gray" outlined :href="$this->getLinks()[$step]">
                                {{ __('dashboard.first_steps.' . $step . '.action') }}
                            </x-filament::button>
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>

        <style>
            .kaiki-first-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: .75rem; }

            /* One grid per row, the same three columns in every row: the
               mark, the words, the button. A row with no button leaves its
               column empty, so the words never run under the buttons above
               or below them. On a phone the button drops under the words,
               the full width of them. */
            .kaiki-first-steps li {
                display: grid;
                grid-template-columns: 1.6rem minmax(0, 1fr);
                column-gap: .85rem;
                row-gap: .5rem;
                align-items: start;
            }
            .kaiki-first-steps .act { grid-column: 2; display: grid; }
            .kaiki-first-steps .act > * { width: 100%; }

            @media (min-width: 640px) {
                .kaiki-first-steps li {
                    grid-template-columns: 1.6rem minmax(0, 1fr) 11rem;
                    align-items: center;
                }
                .kaiki-first-steps .act { grid-column: 3; }
            }

            .kaiki-first-steps .mark {
                width: 1.6rem; height: 1.6rem; border-radius: 999px;
                display: grid; place-items: center; font-size: .8rem; font-weight: 600;
                background: rgb(var(--gray-100)); color: rgb(var(--gray-500));
            }
            .kaiki-first-steps .is-done .mark { background: rgb(var(--primary-500)); color: #fff; }
            .kaiki-first-steps .body { display: grid; gap: .1rem; min-width: 0; }
            .kaiki-first-steps .why { font-size: .82rem; color: rgb(var(--gray-500)); }
            .kaiki-first-steps .is-done .body strong { color: rgb(var(--gray-500)); }

            /* --- dark ----------------------------------------------------
               Same marks, same reasoning as the setup widget: the not-yet disc
               was a white coin. The done one keeps its primary fill, which is
               what makes the list readable at a glance. */
            .dark .kaiki-first-steps .mark {
                background: rgb(var(--gray-800));
                color: rgb(var(--gray-400));
            }

            /* The rule above is as specific as the light «done» one and comes
               later, so it was greying the done discs too. Restated here; the
               tick is dark because the dark primary is a light blue (white
               on it was ~2:1). */
            .dark .kaiki-first-steps .is-done .mark {
                background: rgb(var(--primary-500));
                color: rgb(var(--primary-950));
            }

            .dark .kaiki-first-steps .why,
            .dark .kaiki-first-steps .is-done .body strong { color: rgb(var(--gray-400)); }
        </style>
    </x-filament::section>
</x-filament-widgets::widget>
