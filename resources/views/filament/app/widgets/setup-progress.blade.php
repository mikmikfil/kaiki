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
        </style>
    </x-filament::section>
</x-filament-widgets::widget>
