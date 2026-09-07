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
                @php($isNext = $step === $this->getNextStep())
                <li @class(['is-done' => $done, 'is-next' => $isNext])>
                    <span class="mark" aria-hidden="true">{{ $done ? '✓' : $loop->iteration }}</span>

                    <span class="body">
                        <strong>{{ __('dashboard.first_steps.' . $step . '.title') }}</strong>
                        <span class="why">{{ __('dashboard.first_steps.' . $step . '.why') }}</span>
                    </span>

                    @if ($isNext)
                        <x-filament::button tag="a" size="sm" :href="$this->getLinks()[$step]">
                            {{ __('dashboard.first_steps.' . $step . '.action') }}
                        </x-filament::button>
                    @endif
                </li>
            @endforeach
        </ol>

        <style>
            .kaiki-first-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: .75rem; }
            .kaiki-first-steps li { display: flex; align-items: center; gap: .85rem; }
            .kaiki-first-steps .mark {
                flex: 0 0 1.6rem; height: 1.6rem; border-radius: 999px;
                display: grid; place-items: center; font-size: .8rem; font-weight: 600;
                background: rgb(var(--gray-100)); color: rgb(var(--gray-500));
            }
            .kaiki-first-steps .is-done .mark { background: rgb(var(--primary-500)); color: #fff; }
            .kaiki-first-steps .body { display: grid; gap: .1rem; min-width: 0; flex: 1 1 auto; }
            .kaiki-first-steps .why { font-size: .82rem; color: rgb(var(--gray-500)); }
            .kaiki-first-steps .is-done .body strong { color: rgb(var(--gray-500)); }
        </style>
    </x-filament::section>
</x-filament-widgets::widget>
