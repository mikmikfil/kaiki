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

    @php($doneCount = count(array_filter($questions, fn ($step) => $states[$step] ?? false)))
    <div class="ka-setup" x-data="{ stepsOpen: false }">
        {{-- On a phone the step list does not fit on one line (it was cut at
             «Φ…»): one line says where you are, a thin bar how far along, and
             a tap opens the full list to jump elsewhere (phone audit,
             2026-09-23). --}}
        <button
            type="button"
            class="ka-setup-mini"
            x-on:click="stepsOpen = ! stepsOpen"
            x-bind:aria-expanded="stepsOpen ? 'true' : 'false'"
            aria-controls="ka-setup-steps"
        >
            <span class="line">
                @if ($isReady)
                    <b>{{ __('setup.steps.ready.label') }}</b>
                @else
                    <span>{{ __('setup.step_of', ['n' => (int) $position + 1, 'total' => count($questions)]) }}</span>
                    <b>{{ __('setup.steps.' . $current . '.label') }}</b>
                @endif
                <x-filament::icon icon="heroicon-m-chevron-down" class="chev h-5 w-5" />
            </span>
            <span class="bar" aria-hidden="true"><i style="width: {{ count($questions) > 0 ? round($doneCount / count($questions) * 100) : 0 }}%"></i></span>
        </button>

        <nav id="ka-setup-steps" class="ka-setup-steps" x-bind:class="stepsOpen && 'is-open'" aria-label="{{ __('setup.steps_label') }}">
            @foreach ($questions as $i => $step)
                @php($isDone = $states[$step] ?? false)
                @php($isSkipped = ! $isDone && $this->isSkipped($step))
                <button
                    type="button"
                    wire:click="goTo('{{ $step }}')"
                    x-on:click="stepsOpen = false"
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

        <section class="ka-setup-card @if ($isReady) is-ready @endif" aria-live="polite">
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
                        {{-- The platform's own menu, maintained in /admin
                             (2026-09-23). `selectedTemplate()` falls back to the
                             first one offered, so the ladder below is never
                             blank before the operator has clicked anything. --}}
                        @php($templates = $this->policyTemplates())
                        @php($selected = $this->selectedTemplate())

                        <div class="ka-setup-presets" role="radiogroup" aria-label="{{ __('setup.steps.cancellation.label') }}">
                            @foreach ($templates as $template)
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $selected?->code === $template->code ? 'true' : 'false' }}"
                                    wire:click="choosePreset('{{ $template->code }}')"
                                    @class(['ka-setup-preset', 'is-on' => $selected?->code === $template->code])
                                >
                                    <strong>{{ $template->name }}</strong>
                                    <span>{{ $template->summary }}</span>
                                </button>
                            @endforeach
                        </div>

                        @if ($selected)
                            {{-- Built from the same numbers the policy will be
                                 written with, so the lines an operator reads
                                 here cannot promise something else. --}}
                            <div class="ka-setup-ladder">
                                @foreach ($selected->ladder() as $line)
                                    <div><span>{{ $line['when'] }}</span><span class="pct">{{ $line['refund'] }}</span></div>
                                @endforeach
                                <div><span>{{ __('setup.policy.weather') }}</span><span class="pct">{{ __('setup.policy.weather_refund') }}</span></div>
                            </div>
                        @endif
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
        /*
         * Colours as variables on the two roots, redefined under `.dark`
         * (phone audit, 2026-09-23: 31 light-only colours, a white card on the
         * dark page and «Πίσω» white on white). Dark values come from
         * Filament's grey and the dark primary scale.
         */
        .ka-setup-exit, .ka-setup {
            --ks-card: #fff;
            --ks-line: #E1E8F2;
            --ks-hover: #F4F7FB;
            --ks-ring: #C7D3E3;
            --ks-ink: #15233A;
            --ks-muted: #5F6F86;
            --ks-faint: #64748B;
            --ks-strong: #0F2E57;
            --ks-on-ink: #fff;
            --ks-soft: #EAF1FA;
            --ks-ok: #1F7A4D;
            --ks-ok-fill: #1F7A4D;
            --ks-ring-on: rgba(15, 46, 87, .1);
            --ks-note: #FBF0DF;
            --ks-note-ink: #9A5B0C;
        }
        .dark .ka-setup-exit, .dark .ka-setup {
            --ks-card: rgb(var(--gray-900));
            --ks-line: rgba(255, 255, 255, .1);
            --ks-hover: rgba(255, 255, 255, .05);
            --ks-ring: rgb(var(--gray-600));
            --ks-ink: rgb(var(--gray-100));
            --ks-muted: rgb(var(--gray-400));
            --ks-faint: rgb(var(--gray-400));
            --ks-strong: rgb(var(--primary-400));
            --ks-on-ink: rgb(var(--primary-950));
            --ks-soft: rgba(var(--primary-400), .14);
            --ks-ok: rgb(var(--success-400));
            --ks-ok-fill: #1F7A4D;
            --ks-ring-on: rgba(var(--primary-400), .25);
            --ks-note: rgba(var(--warning-500), .14);
            --ks-note-ink: rgb(var(--warning-300));
        }
        /* The two exits, on one line above the guide and wrapping on a phone. */
        .ka-setup-exit { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; justify-content: flex-end; font-size: .85rem; color: var(--ks-muted); margin-bottom: .75rem; }
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
        .ka-setup-steps { display: grid; gap: 2px; background: var(--ks-card); border: 1px solid var(--ks-line); border-radius: .75rem; padding: .5rem; }
        .ka-setup-step { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr) auto; gap: .6rem; align-items: center; padding: .55rem .5rem; border-radius: .5rem; font-size: .9rem; color: var(--ks-muted); text-align: left; }
        .ka-setup-step:hover { background: var(--ks-hover); }
        .ka-setup-step .dot { width: 1.4rem; height: 1.4rem; border-radius: 999px; border: 1.5px solid var(--ks-ring); display: grid; place-items: center; font-size: .72rem; font-weight: 700; }
        .ka-setup-step.is-done { color: var(--ks-ink); }
        .ka-setup-step.is-done .dot { background: var(--ks-ok-fill); border-color: var(--ks-ok-fill); color: #fff; }
        .ka-setup-step.is-on { background: var(--ks-soft); color: var(--ks-strong); font-weight: 600; }
        .ka-setup-step.is-on .dot { background: var(--ks-strong); border-color: var(--ks-strong); color: var(--ks-on-ink); }
        .ka-setup-step .tag { font-size: .72rem; color: var(--ks-faint); font-weight: 500; }

        .ka-setup-card { background: var(--ks-card); border: 1px solid var(--ks-line); border-radius: .9rem; padding: 1.5rem; display: grid; gap: 1.1rem; min-width: 0; }
        .ka-setup-card .head { display: grid; gap: .35rem; }
        .ka-setup-card .count { font-size: .82rem; color: var(--ks-muted); }
        .ka-setup-card h2 { font-size: 1.35rem; font-weight: 700; color: var(--ks-strong); letter-spacing: -.01em; line-height: 1.25; }
        .ka-setup-card .head p { color: var(--ks-muted); font-size: .92rem; max-width: 62ch; }

        /*
         * **Το τελευταίο βήμα γράφει μέχρι την άκρη** (Mike, 23/9: *«στο είστε
         * έτοιμοι, το κείμενο κόβεται, δεν πάει μέχρι την άκρη του container»*).
         *
         * Τα υπόλοιπα βήματα έχουν μία γραμμή «γιατί», και το μέτρο των 62ch
         * είναι ακριβώς ό,τι χρειάζονται. Το «Είστε έτοιμοι» έχει τρεις με
         * τέσσερις γραμμές πραγματικού κειμένου, και το ίδιο μέτρο μέσα σε μια
         * κάρτα πιο φαρδιά από αυτό αφήνει κενό στα δεξιά που διαβάζεται ως
         * κομμένο κείμενο, όχι ως άνετη στοίχιση.
         */
        .ka-setup-card.is-ready .head p { max-width: none; }

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
        .ka-setup-preset { border: 1.5px solid var(--ks-line); border-radius: .75rem; padding: .8rem; display: grid; gap: .3rem; text-align: left; font-size: .84rem; color: var(--ks-muted); }
        .ka-setup-preset strong { color: var(--ks-ink); font-size: .95rem; }
        .ka-setup-preset.is-on { border-color: var(--ks-strong); box-shadow: 0 0 0 3px var(--ks-ring-on); }
        .ka-setup-ladder { border: 1px solid var(--ks-line); border-radius: .6rem; overflow: hidden; font-size: .88rem; }
        .ka-setup-ladder div { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem .75rem; border-top: 1px solid var(--ks-line); }
        .ka-setup-ladder div:first-child { border-top: 0; }
        .ka-setup-ladder .pct { font-variant-numeric: tabular-nums; font-weight: 600; }

        .ka-setup-done { display: flex; gap: .5rem; align-items: center; color: var(--ks-ok); font-weight: 600; font-size: .92rem; }
        .ka-setup-hint { color: var(--ks-muted); font-size: .85rem; }
        .ka-setup-note { background: var(--ks-note); color: var(--ks-note-ink); border-radius: .6rem; padding: .65rem .8rem; font-size: .9rem; }

        .ka-setup-actions { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; padding-top: .25rem; }
        .ka-setup-actions .right { display: flex; gap: .5rem; margin-left: auto; }

        /* Phone only: where you are, and how far along. */
        .ka-setup-mini {
            display: none; gap: .5rem; width: 100%; padding: .7rem .85rem; text-align: left;
            background: var(--ks-card); border: 1px solid var(--ks-line); border-radius: .75rem; color: var(--ks-ink);
        }
        .ka-setup-mini .line { display: flex; align-items: center; gap: .5rem; font-size: .9rem; min-width: 0; }
        .ka-setup-mini .line > span { color: var(--ks-muted); white-space: nowrap; }
        .ka-setup-mini .line b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
        .ka-setup-mini .chev { margin-left: auto; flex: none; color: var(--ks-muted); transition: transform .15s; }
        .ka-setup-mini[aria-expanded="true"] .chev { transform: rotate(180deg); }
        .ka-setup-mini .bar { display: block; height: .3rem; border-radius: 99px; background: var(--ks-hover); overflow: hidden; box-shadow: inset 0 0 0 1px var(--ks-line); }
        .ka-setup-mini .bar i { display: block; height: 100%; border-radius: inherit; background: var(--ks-ok-fill); }

        @media (max-width: 760px) {
            .ka-setup { grid-template-columns: 1fr; gap: .75rem; }
            .ka-setup-mini { display: grid; }
            .ka-setup-steps { display: none; }
            .ka-setup-steps.is-open { display: grid; }
            .ka-setup-presets { grid-template-columns: 1fr; }
            .ka-setup-card { padding: 1.1rem; }
            /* The line above already says «Βήμα 4 από 5». */
            .ka-setup-card .count { display: none; }
        }
        /* The two exits, one under the other and full width on a phone. */
        @media (max-width: 639px) {
            .ka-setup-exit { flex-direction: column; align-items: stretch; gap: .5rem; }
            .ka-setup-exit > span { margin-inline-end: 0; }
            .ka-setup-exit > .fi-btn, .ka-setup-exit > .fi-link { width: 100%; justify-content: center; min-height: 2.5rem; }
        }
    </style>
</x-filament-panels::page>
