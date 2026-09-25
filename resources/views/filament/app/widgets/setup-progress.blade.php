{{--
    The persistent setup checklist (SAA-10).

    Deliberately quieter than `first-steps`: this one sits *above* a working
    dashboard rather than in place of one, so it is a strip with a count and one
    button, not a card competing with the figures underneath it.

    A skipped step keeps its row and says so. Removing it would make the list
    shorter and the operator's own decision invisible — the point of showing it
    is that they can put it back.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('setup.widget.heading') }}</x-slot>
        <x-slot name="description">{{ __('setup.widget.description') }}</x-slot>

        @php($progress = $this->getProgress())
        @php($skipped = $this->getSkipped())
        @php($next = $this->getNextStep())

        <div class="kaiki-setup">
            <ol class="kaiki-setup-steps">
                @foreach ($this->getSteps() as $step => $done)
                    @php($isSkipped = in_array($step, $skipped, true))
                    <li @class(['is-done' => $done, 'is-skipped' => ! $done && $isSkipped, 'is-next' => $step === $next])>
                        <span class="mark" aria-hidden="true">{{ $done ? '✓' : ($isSkipped ? '–' : $loop->iteration) }}</span>
                        <span class="label">{{ __('setup.steps.' . $step . '.label') }}</span>
                        @if (! $done && $isSkipped)
                            <span class="tag">{{ __('setup.widget.skipped') }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>

            <div class="kaiki-setup-cta">
                <span class="count">{{ __('setup.widget.progress', ['done' => $progress['done'], 'total' => $progress['total']]) }}</span>

                <x-filament::button tag="a" size="sm" :href="$this->getSetupUrl()">
                    {{ __('setup.widget.continue') }}
                </x-filament::button>
            </div>
        </div>

        {{--
            And the catalogue, which the guide stopped asking for (product
            owner, 2026-09-22). A quieter line of its own rather than four more
            numbered steps: these are not questions with one right answer, they
            are screens the operator goes to and comes back from. Each is a link
            to the real one — the guide never had a shortened copy worth keeping.
        --}}
        @php($catalogue = $this->getCatalogue())
        @php($links = $this->getCatalogueLinks())

        <div class="kaiki-setup-next">
            <span class="lead">{{ __('setup.widget.catalogue') }}</span>

            <ul>
                @foreach ($catalogue as $step => $done)
                    <li @class(['is-done' => $done])>
                        <span class="mark" aria-hidden="true">{{ $done ? '✓' : '·' }}</span>
                        @if ($done)
                            <span class="label">{{ __('setup.steps.' . $step . '.label') }}</span>
                        @else
                            <a href="{{ $links[$step] ?? '#' }}" class="label">{{ __('setup.steps.' . $step . '.label') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <style>
            .kaiki-setup { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem 1.5rem; justify-content: space-between; }
            .kaiki-setup-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem 1.1rem; min-width: 0; }
            .kaiki-setup-steps li { display: flex; align-items: center; gap: .45rem; font-size: .85rem; }
            .kaiki-setup-steps .mark {
                flex: 0 0 1.25rem; height: 1.25rem; border-radius: 999px;
                display: grid; place-items: center; font-size: .7rem; font-weight: 600;
                background: rgb(var(--gray-100)); color: rgb(var(--gray-500));
            }
            .kaiki-setup-steps .is-done .mark { background: rgb(var(--primary-500)); color: #fff; }
            .kaiki-setup-steps .is-done .label { color: rgb(var(--gray-500)); }
            .kaiki-setup-steps .is-next .label { font-weight: 600; }
            .kaiki-setup-steps .is-skipped .label { color: rgb(var(--gray-400)); }
            .kaiki-setup-steps .tag { font-size: .7rem; color: rgb(var(--gray-400)); }
            .kaiki-setup-cta { display: flex; align-items: center; gap: .75rem; margin-left: auto; }
            .kaiki-setup-cta .count { font-size: .8rem; color: rgb(var(--gray-500)); white-space: nowrap; }

            .kaiki-setup-next { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem 1.1rem; margin-top: .9rem; padding-top: .8rem; border-top: 1px solid rgb(var(--gray-200)); }
            .kaiki-setup-next .lead { font-size: .8rem; color: rgb(var(--gray-500)); }
            .kaiki-setup-next ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .4rem 1.1rem; }
            .kaiki-setup-next li { display: flex; align-items: center; gap: .4rem; font-size: .85rem; }
            .kaiki-setup-next .mark { color: rgb(var(--gray-400)); font-size: .8rem; }
            .kaiki-setup-next .is-done .mark { color: rgb(var(--primary-500)); }
            .kaiki-setup-next .is-done .label { color: rgb(var(--gray-500)); }
            .kaiki-setup-next a.label { color: rgb(var(--primary-600)); font-weight: 600; }

            .dark .kaiki-setup-next { border-color: rgb(var(--gray-700)); }
            .dark .kaiki-setup-next .lead, .dark .kaiki-setup-next .is-done .label { color: rgb(var(--gray-400)); }
            .dark .kaiki-setup-next a.label { color: rgb(var(--primary-400)); }

            /* --- dark ----------------------------------------------------
               The numbered marks are the widget: a `--gray-100` disc is a white
               coin on a dark page, and every step still to do shouted louder
               than the one already done in brand colour. The done mark keeps its
               primary fill — that contrast is the progress — and only the
               not-yet mark moves. */
            .dark .kaiki-setup-steps .mark {
                background: rgb(var(--gray-800));
                color: rgb(var(--gray-400));
            }

            .dark .kaiki-setup-steps .is-done .label,
            .dark .kaiki-setup-cta .count { color: rgb(var(--gray-400)); }

            .dark .kaiki-setup-steps .is-skipped .label,
            .dark .kaiki-setup-steps .tag { color: rgb(var(--gray-500)); }
        </style>
    </x-filament::section>
</x-filament-widgets::widget>
