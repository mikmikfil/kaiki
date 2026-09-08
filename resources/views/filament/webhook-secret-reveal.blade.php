{{--
    The one and only place a signing secret is ever rendered (OPS-20).

    It is shown once, in the response to the create or the rotate. Nothing here is
    stored, flashed or logged — closing the modal is the end of it.
--}}
<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('webhooks.reveal.intro', ['name' => $name]) }}
    </p>

    <div
        x-data="{
            copied: false,
            copy() {
                window.navigator.clipboard.writeText(@js($secret)).then(() => {
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                });
            },
        }"
        class="space-y-2"
    >
        <div class="flex items-center gap-2">
            <code
                class="flex-1 select-all break-all rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm text-gray-950 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
            >{{ $secret }}</code>

            <x-filament::button
                type="button"
                size="sm"
                icon="heroicon-m-clipboard-document"
                x-on:click="copy()"
            >
                <span x-show="! copied">{{ __('webhooks.reveal.copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('webhooks.reveal.copied') }}</span>
            </x-filament::button>
        </div>
    </div>

    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
        {{ __('webhooks.reveal.cannot_show_again') }}
    </p>
</div>
