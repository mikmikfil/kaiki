{{--
    The operator's public site: which pages, and at what address (#109, HOS-3,
    ADR-0029).

    The domain half is mostly explanation, because the work happens at a
    registrar we cannot reach: the exact CNAME, where it goes, and the truth
    about how long it takes. The mode half is read-only: the platform sets it on
    /admin (ADR-0029 as amended 2026-09-11), and this says which it is and who
    to ask. It sits first because it says which pages a domain will point at.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold">{{ __('domains.mode.heading') }}</h3>

            @if ($label = $this->siteModeLabel())
                <p class="mt-3 text-sm font-medium">{{ $label }}</p>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->siteModeHelp() }}
                </p>
            @endif

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('domains.mode.managed') }}
            </p>
        </div>

        {{-- SAA-8: on a plan without domains the setup instructions and the form
             give way to what the plan includes and the way to a bigger one.
             Domains the operator already has are still listed below. --}}
        @unless ($this->allowsCustomDomain())
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold">{{ __('plans.pro_only') }}</h3>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('plans.domains.body') }}
                </p>

                <div class="mt-4">
                    <x-filament::button tag="a" :href="$this->upgradeUrl()" target="_blank" icon="heroicon-o-arrow-up-circle">
                        {{ __('plans.upgrade') }}
                    </x-filament::button>
                </div>
            </div>
        @endunless

        @if ($this->allowsCustomDomain())
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
        @endif

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
