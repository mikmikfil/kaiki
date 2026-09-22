{{--
    The first-run setup guide, one step at a time (#51, SAA-9, SAA-10;
    direction B of the onboarding mockups, product owner 2026-09-17).

    The list of steps on the left, the one step being answered on the right.
    Every string comes from `lang/*/setup.php`; `NoHardcodedStringsTest` scans
    this directory (I18N-1).
--}}
@php
    $current = $this->currentStep();
    $states = $this->stepStates();
    $questions = $this->questions();
    $position = array_search($current, $questions, true);
    $isReady = $current === \App\Domain\Tenancy\Support\SetupChecklist::READY;
    $handOff = $this->handOffUrl($current);
    $done = $isReady ? false : ($states[$current] ?? false);
@endphp

<x-filament-panels::page>
    {{--
        The two ways out of the gate (product owner, 2026-09-22). Above the
        guide rather than buried at the foot of it: an operator who does not
        want to do this now should not have to read the whole thing to find
        that out. «Αργότερα» keeps the guide in the menu; «Δεν το χρειάζομαι»
        retires it, and Ρυθμίσεις keeps a way back either way.
    --}}
    {{--
        One row across the top: the subtitle on the left, the two exits on the
        right. It was three stacked blocks — logo, heading, exits — and on a
        1080p screen that pushed the seven fields of the first step below the
        fold (product owner, 2026-09-22: *«έχει scroll η σελίδα και είμαι σε
        fullhd»*). The page's own heading is gone with them: the card already
        says «Βήμα 1 από 7» and asks the question, so the title above it was
        saying the same thing twice.
    --}}
    <div class="ka-setup-exit">
        <span>{{ __('setup.subtitle') }}</span>
        <x-filament::button color="gray" size="sm" wire:click="deferSetup">
            {{ __('setup.exit.later') }}
        </x-filament::button>
        <x-filament::link
            tag="button"
            color="gray"
            wire:click="dismissSetup"
            wire:confirm="{{ __('setup.exit.dismiss_confirm') }}"
        >
            {{ __('setup.exit.dismiss') }}
        </x-filament::link>
    </div>

    <div class="ka-setup">
        <nav class="ka-setup-steps" aria-label="{{ __('setup.steps_label') }}">
            @foreach ($questions as $i => $step)
                @php($isDone = $states[$step] ?? false)
                @php($isSkipped = ! $isDone && $this->isSkipped($step))
                <button
                    type="button"
                    wire:click="goTo('{{ $step }}')"
                    @class(['ka-setup-step', 'is-done' => $isDone, 'is-on' => $step === $current, 'is-skipped' => $isSkipped])
                    @if ($step === $current) aria-current="step" @endif
                >
                    <span class="dot" aria-hidden="true">
                        @if ($isDone)
                            <x-filament::icon icon="heroicon-m-check" class="h-3.5 w-3.5" />
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <span class="name">{{ __('setup.steps.' . $step . '.label') }}</span>
                    @if ($isSkipped)
                        <span class="tag">{{ __('setup.later_tag') }}</span>
                    @endif
                </button>
            @endforeach
        </nav>

        <section class="ka-setup-card" aria-live="polite">
            @if ($isReady)
                <div class="head">
                    <h2>{{ __('setup.steps.ready.question') }}</h2>
                    {{-- An operator on «μόνο σελίδες κρατήσεων» has no home
                         page from us, so «η ιστοσελίδα σας» would be the wrong
                         word for what they just finished setting up. --}}
                    <p>{{ __('setup.steps.ready.' . ($this->servesHomePage() ? 'body' : 'body_bookings_only')) }}</p>
                </div>

                @if ($this->outstanding() !== '')
                    <p class="ka-setup-note">{{ $this->outstanding() }}</p>
                @endif

                <div class="ka-setup-actions">
                    <x-filament::button color="gray" wire:click="back">{{ __('setup.back') }}</x-filament::button>
                    <x-filament::button wire:click="finish" icon="heroicon-m-check">{{ __('setup.finish') }}</x-filament::button>
                </div>
            @else
                <div class="head">
                    <span class="count">{{ __('setup.step_of', ['n' => (int) $position + 1, 'total' => count($questions)]) }}</span>
                    <h2>{{ __('setup.steps.' . $current . '.question') }}</h2>
                    <p>{{ __('setup.steps.' . $current . '.why') }}</p>
                </div>

                @if ($current === \App\Domain\Tenancy\Support\SetupChecklist::CANCELLATION)
                    @if ($done)
                        <p class="ka-setup-done">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5" />
                            {{ __('setup.policy.existing', ['name' => $this->existingPolicyName()]) }}
                        </p>
                    @else
                        <div class="ka-setup-presets" role="radiogroup" aria-label="{{ __('setup.steps.cancellation.label') }}">
                            @foreach (\App\Filament\App\Pages\Setup::PRESETS as $preset)
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $policyPreset === $preset ? 'true' : 'false' }}"
                                    wire:click="choosePreset('{{ $preset }}')"
                                    @class(['ka-setup-preset', 'is-on' => $policyPreset === $preset])
                                >
                                    <strong>{{ __('setup.policy.' . $preset . '.name') }}</strong>
                                    <span>{{ __('setup.policy.' . $preset . '.summary') }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="ka-setup-ladder">
                            @foreach (__('setup.policy.' . $policyPreset . '.ladder') as $line)
                                <div><span>{{ $line['when'] }}</span><span class="pct">{{ $line['refund'] }}</span></div>
                            @endforeach
                            <div><span>{{ __('setup.policy.weather') }}</span><span class="pct">{{ __('setup.policy.weather_refund') }}</span></div>
                        </div>
                        <p class="ka-setup-hint">{{ __('setup.policy.later') }}</p>
                    @endif
                @elseif ($handOff !== null)
                    @if ($done)
                        <p class="ka-setup-done">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5" />
                            {{ __('setup.steps.' . $current . '.done') }}
                        </p>
                    @else
                        <div>
                            <x-filament::button tag="a" :href="$handOff" color="gray" icon="heroicon-m-arrow-top-right-on-square">
                                {{ __('setup.steps.' . $current . '.action') }}
                            </x-filament::button>
                        </div>
                        {{-- «Περίοδοι» is the one hand-off step an operator is
                             expected to skip — one price all year needs none —
                             so it says that before it says «come back». --}}
                        @if ($current === \App\Domain\Tenancy\Support\SetupChecklist::SEASON)
                            <p class="ka-setup-hint">{{ __('setup.steps.season.caveat') }}</p>
                        @endif
                        <p class="ka-setup-hint">{{ __('setup.come_back') }}</p>
                    @endif
                @else
                    {{ $this->form }}
                    @if ($current === \App\Domain\Tenancy\Support\SetupChecklist::VAT)
                        <p class="ka-setup-hint">{{ __('setup.steps.vat.caveat') }}</p>
                    @endif
                @endif

                <div class="ka-setup-actions">
                    <x-filament::link tag="button" color="gray" wire:click="later">{{ __('setup.later') }}</x-filament::link>
                    <div class="right">
                        @if ((int) $position > 0)
                            <x-filament::button color="gray" wire:click="back">{{ __('setup.back') }}</x-filament::button>
                        @endif
                        <x-filament::button wire:click="continue" icon="heroicon-m-arrow-right" icon-position="after">
                            {{ __('setup.continue') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </section>
    </div>

    <style>
        /* The two exits, on one line above the guide and wrapping on a phone. */
        .ka-setup-exit { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; justify-content: flex-end; font-size: .85rem; color: #5F6F86; margin-bottom: .75rem; }
        .ka-setup-exit > span { margin-inline-end: auto; }
        /*
         * **Τέρμα πλάτος** (product owner, 2026-09-22, direction Α of
         * `docs/mockups/setup-width.html`).
         *
         * The guide was held to 60rem inside a sheet that spans the screen, so
         * at 1080p half the sheet was empty and the exits row above it — which
         * does span the full width — made the mismatch obvious. With four
         * steps instead of eight the left column is shorter and it read worse
         * still.
         */
        .ka-setup { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
        .ka-setup-steps { display: grid; gap: 2px; background: #fff; border: 1px solid #E1E8F2; border-radius: .75rem; padding: .5rem; }
        .ka-setup-step { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr) auto; gap: .6rem; align-items: center; padding: .55rem .5rem; border-radius: .5rem; font-size: .9rem; color: #5F6F86; text-align: left; }
        .ka-setup-step:hover { background: #F4F7FB; }
        .ka-setup-step .dot { width: 1.4rem; height: 1.4rem; border-radius: 999px; border: 1.5px solid #C7D3E3; display: grid; place-items: center; font-size: .72rem; font-weight: 700; }
        .ka-setup-step.is-done { color: #15233A; }
        .ka-setup-step.is-done .dot { background: #1F7A4D; border-color: #1F7A4D; color: #fff; }
        .ka-setup-step.is-on { background: #EAF1FA; color: #0F2E57; font-weight: 600; }
        .ka-setup-step.is-on .dot { background: #0F2E57; border-color: #0F2E57; color: #fff; }
        .ka-setup-step .tag { font-size: .72rem; color: #94A3B8; font-weight: 500; }

        .ka-setup-card { background: #fff; border: 1px solid #E1E8F2; border-radius: .9rem; padding: 1.5rem; display: grid; gap: 1.1rem; min-width: 0; }
        .ka-setup-card .head { display: grid; gap: .35rem; }
        .ka-setup-card .count { font-size: .82rem; color: #5F6F86; }
        .ka-setup-card h2 { font-size: 1.35rem; font-weight: 700; color: #0F2E57; letter-spacing: -.01em; line-height: 1.25; }
        .ka-setup-card .head p { color: #5F6F86; font-size: .92rem; max-width: 62ch; }

        /*
         * **Equal boxes in a row** (same note: *«πρόσεχε τα ύψη των κουτιών
         * της φόρμας να είναι ίσα»*).
         *
         * Filament's grid stretches its items, so the boxes were already the
         * same height — what was uneven was what sat inside them. «ΑΦΜ» carries
         * a helper line and «ΔΟΥ» does not, so one input had text under it and
         * its neighbour had nothing, and the pair read as two different
         * shapes. Wider rows made it worse, because there is more of the row
         * to notice.
         *
         * Each field becomes a column: the input where it always was, and the
         * helper line pinned to the bottom of the box. A field with no helper
         * text leaves the space empty rather than closing up, so every row
         * lands on the same two lines.
         */
        .ka-setup-card .fi-fo-field-wrp { display: flex; flex-direction: column; height: 100%; }
        /* The block that holds the input and, under it, the helper line. */
        .ka-setup-card .fi-fo-field-wrp > :last-child { flex: 1; display: flex; flex-direction: column; }
        /* Only the helper moves. Pinning whatever happens to be last would
           push the **input** of a field that has no helper text to the bottom
           of its box, which is the opposite of lining them up. */
        .ka-setup-card .fi-fo-field-wrp-helper-text { margin-top: auto; padding-top: .35rem; }

        .ka-setup-presets { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .65rem; }
        .ka-setup-preset { border: 1.5px solid #E1E8F2; border-radius: .75rem; padding: .8rem; display: grid; gap: .3rem; text-align: left; font-size: .84rem; color: #5F6F86; }
        .ka-setup-preset strong { color: #15233A; font-size: .95rem; }
        .ka-setup-preset.is-on { border-color: #0F2E57; box-shadow: 0 0 0 3px rgba(15, 46, 87, .1); }
        .ka-setup-ladder { border: 1px solid #E1E8F2; border-radius: .6rem; overflow: hidden; font-size: .88rem; }
        .ka-setup-ladder div { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem .75rem; border-top: 1px solid #E1E8F2; }
        .ka-setup-ladder div:first-child { border-top: 0; }
        .ka-setup-ladder .pct { font-variant-numeric: tabular-nums; font-weight: 600; }

        .ka-setup-done { display: flex; gap: .5rem; align-items: center; color: #1F7A4D; font-weight: 600; font-size: .92rem; }
        .ka-setup-hint { color: #5F6F86; font-size: .85rem; }
        .ka-setup-note { background: #FBF0DF; color: #9A5B0C; border-radius: .6rem; padding: .65rem .8rem; font-size: .9rem; }

        .ka-setup-actions { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; padding-top: .25rem; }
        .ka-setup-actions .right { display: flex; gap: .5rem; margin-left: auto; }

        @media (max-width: 760px) {
            .ka-setup { grid-template-columns: 1fr; }
            .ka-setup-steps { grid-auto-flow: column; grid-auto-columns: max-content; overflow-x: auto; }
            .ka-setup-step .name { white-space: nowrap; }
            .ka-setup-presets { grid-template-columns: 1fr; }
            .ka-setup-card { padding: 1.1rem; }
        }
    </style>
</x-filament-panels::page>
