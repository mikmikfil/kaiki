{{--
    The operator's public site: which pages, and at what address (#109, HOS-3,
    ADR-0029).

    The domain half is mostly explanation, because the work happens at a
    registrar we cannot reach: the exact CNAME, where it goes, and the truth
    about how long it takes. The mode half is one question with three answers,
    and it sits first because it is the larger one — there is no point pointing
    a domain at pages nobody is serving.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold">{{ __('domains.mode.heading') }}</h3>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('domains.mode.help') }}
            </p>

            <form wire:submit="saveMode" class="mt-4">
                {{ $this->modeForm }}

                <div class="mt-4 flex justify-end">
                    <x-filament::button type="submit">
                        {{ __('domains.mode.save') }}
                    </x-filament::button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold">{{ __('domains.cname.heading') }}</h3>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('domains.cname.body', ['target' => $this->target()]) }}
            </p>

            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('domains.cname.type') }}</dt>
                    <dd class="font-mono">CNAME</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('domains.cname.name') }}</dt>
                    <dd class="font-mono">book</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('domains.cname.value') }}</dt>
                    <dd class="font-mono">{{ $this->target() }}</dd>
                </div>
            </dl>
        </div>

        <form wire:submit="add" class="space-y-4">
            {{ $this->form }}

            <x-filament::button type="submit">
                {{ __('domains.actions.add') }}
            </x-filament::button>
        </form>

        @if ($this->domains()->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('domains.empty') }}</p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($this->domains() as $domain)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="font-mono text-sm">{{ $domain->hostname }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $domain->status->label() }}
                                @if ($domain->last_checked_at)
                                    · {{ __('domains.last_checked', ['when' => $domain->last_checked_at->diffForHumans()]) }}
                                @endif
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <x-filament::button size="sm" color="gray" wire:click="verify({{ $domain->id }})">
                                {{ __('domains.actions.verify') }}
                            </x-filament::button>

                            <x-filament::button size="sm" color="danger" wire:click="remove({{ $domain->id }})">
                                {{ __('domains.actions.remove') }}
                            </x-filament::button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
