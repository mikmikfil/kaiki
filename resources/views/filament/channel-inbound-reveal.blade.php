{{--
    The one and only place the inbound Basic pair is ever rendered (ADR-0034).

    Only the sha256 of the password reaches the database, so this modal is the
    single moment in the system's life where it is readable. Nothing here is
    stored, flashed or logged — closing it is the end of it.

    Three values, not one, and all three are needed together: GetYourGuide's
    dashboard asks for an endpoint, a username and a password on the same form,
    and an operator who copied two of the three has to start again.
--}}
<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('integrations.inbound.intro') }}
    </p>

    <div
        x-data="{
            copiedField: null,
            copy(field, value) {
                window.navigator.clipboard.writeText(value).then(() => {
                    this.copiedField = field;
                    setTimeout(() => this.copiedField = null, 2000);
                });
            },
        }"
        class="space-y-3"
    >
        @foreach ([
            'endpoint' => $endpoint,
            'username' => $username,
            'password' => $password,
        ] as $field => $value)
            <div class="space-y-1">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('integrations.inbound.' . $field) }}
                </p>

                <div class="flex items-center gap-2">
                    <code
                        class="flex-1 select-all break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm text-gray-950 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                    >{{ $value }}</code>

                    <x-filament::button
                        type="button"
                        size="sm"
                        color="gray"
                        icon="heroicon-m-clipboard-document"
                        x-on:click="copy(@js($field), @js($value))"
                    >
                        <span x-show="copiedField !== @js($field)">{{ __('integrations.inbound.copy') }}</span>
                        <span x-show="copiedField === @js($field)" x-cloak>{{ __('integrations.inbound.copied') }}</span>
                    </x-filament::button>
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
        {{ __('integrations.inbound.cannot_show_again') }}
    </p>
</div>
