{{--
    «Χρειάζονται προσοχή» (#130, OPS-1).

    Decisions, not failures. OPS-21's feed collects what the system could not do
    and every row there ends in a retry; nothing here can be retried, because
    nothing here is broken — each row is a question only the operator can answer.

    Ordered by deadline and never by severity: a red badge above a boat leaving
    in three hours makes a list that has to be read in full to be used.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('attention.heading') }}</x-slot>

        <x-slot name="description">{{ __('attention.subheading') }}</x-slot>

        <ul class="ka-list">
            @foreach ($this->getItems() as $item)
                <li class="ka-item">
                    <x-filament::badge :color="$item->severity->color()" class="ka-badge">
                        {{ __('attention.severity.' . $item->severity->value) }}
                    </x-filament::badge>

                    <div class="ka-body">
                        <p class="ka-title">{{ $item->title }}</p>
                        <p class="ka-detail">{{ $item->detail }}</p>
                    </div>

                    <div class="ka-when">
                        @if ($item->deadline)
                            {{-- Relative, because "in 3 hours" is the thing that
                                 decides whether this is read now, and a
                                 timestamp makes the reader do that subtraction
                                 themselves. The absolute time is on hover for
                                 when it matters. --}}
                            <span title="{{ $item->deadline->toDayDateTimeString() }}"
                                  @class(['is-overdue' => $item->isOverdue()])>
                                {{ $item->deadline->diffForHumans() }}
                            </span>
                        @else
                            <span class="is-undated">{{ __('attention.no_deadline') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <style>
        .ka-list { display: grid; gap: .1rem; }

        .ka-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: start; gap: .75rem;
            padding: .6rem 0;
            border-bottom: 1px solid rgb(var(--gray-100));
        }

        .ka-item:last-child { border-bottom: 0; }

        .ka-badge { margin-top: .1rem; }

        .ka-title { font-size: .875rem; font-weight: 600; color: rgb(var(--gray-800)); }
        .ka-detail { font-size: .8rem; color: rgb(var(--gray-500)); margin-top: .1rem; }

        .ka-when { font-size: .78rem; color: rgb(var(--gray-500)); white-space: nowrap; text-align: right; }
        .ka-when .is-overdue { color: rgb(var(--danger-600)); font-weight: 600; }
        .ka-when .is-undated { color: rgb(var(--gray-400)); }

        @media (max-width: 40rem) {
            .ka-item { grid-template-columns: auto minmax(0, 1fr); }
            .ka-when { grid-column: 2; text-align: left; }
        }
    </style>
</x-filament-widgets::widget>
