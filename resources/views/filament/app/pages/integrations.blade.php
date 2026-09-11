{{--
    The integrations screen (PAY-4, PAY-11, TEN-8).

    One row per configured provider. The secret half of a credential set is not
    in this template in any form — the only thing derived from it is the last
    four characters, which is what lets an operator tell which key they pasted
    without the screen carrying anything usable.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    @php($credentials = $this->credentials())

    @if ($credentials->isEmpty())
        <section class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('integrations.help.never_shown') }}
            </p>
        </section>
    @else
        <div class="space-y-4">
            @foreach ($credentials as $credential)
                @php($status = $this->statusOf($credential))

                <section
                    class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                    aria-label="{{ $credential->provider->label() }}"
                >
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="space-y-2">
                            <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                                {{ $credential->provider->label() }}
                            </h2>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge color="gray">
                                    {{ $credential->environment->label() }}
                                </x-filament::badge>

                                {{-- The state is in the badge *text*, never only
                                     in its colour: "not working" must be readable
                                     without seeing the difference between red and
                                     green. --}}
                                <x-filament::badge :color="$status['color']">
                                    {{ $status['label'] }}
                                </x-filament::badge>

                                @if ($credential->is_default)
                                    <x-filament::badge color="info">
                                        {{ __('integrations.form.is_default.label') }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            <dl class="mt-2 space-y-1">
                                @foreach ($credential->provider->credentialFields() as $field)
                                    <div class="flex gap-2 text-sm">
                                        <dt class="text-gray-500 dark:text-gray-400">
                                            {{ __('integrations.fields.' . $field) }}
                                        </dt>
                                        <dd class="font-mono text-gray-700 dark:text-gray-300">
                                            {{ $credential->hint($field) }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>

                            @if ($credential->last_error !== null)
                                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">
                                    {{ $credential->last_error }}
                                </p>
                            @endif

                            @if ($credential->environment->isTest() === false)
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('integrations.help.live_warning') }}
                                </p>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            {{ ($this->saveCredentialsAction)(['credential' => $credential->getKey()]) }}
                            {{ ($this->verifyAction)(['credential' => $credential->getKey()]) }}

                            @if ($credential->is_active)
                                {{ ($this->deactivateAction)(['credential' => $credential->getKey()]) }}
                            @endif
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('integrations.help.never_shown') }}
        </p>
    @endif

    {{--
        Viva's Success and Failure URLs (2026-09-11).

        Shown whether or not Viva is configured yet, because the payment source
        is created in Viva's dashboard *before* its keys are pasted here, and
        these are two of the fields that form asks for. Built with `route()`, so
        they are this installation's own addresses rather than a string somebody
        has to keep in step with `routes/web.php`.
    --}}
    @php($vivaReturn = [
        'success' => route('pay.viva.success'),
        'failure' => route('pay.viva.failure'),
    ])

    <section
        class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        aria-label="{{ __('integrations.viva_return.heading') }}"
    >
        <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
            {{ __('integrations.viva_return.heading') }}
        </h2>

        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            {{ __('integrations.viva_return.help') }}
        </p>

        <dl class="mt-4 space-y-3">
            @foreach ($vivaReturn as $which => $url)
                <div class="space-y-1">
                    <dt class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('integrations.viva_return.' . $which) }}
                    </dt>
                    <dd class="flex flex-wrap items-center gap-2">
                        {{-- `select-all`, so one click selects the whole address
                             even where the clipboard API is refused. --}}
                        <code class="select-all break-all rounded bg-gray-50 px-2 py-1 font-mono text-sm text-gray-800 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">{{ $url }}</code>

                        <x-filament::button
                            size="xs"
                            color="gray"
                            icon="heroicon-m-clipboard"
                            x-data="{}"
                            x-on:click="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($url) }}); $tooltip({{ \Illuminate\Support\Js::from(__('integrations.viva_return.copied')) }}, { theme: $store.theme, timeout: 2000 })"
                        >
                            {{ __('integrations.viva_return.copy') }}
                        </x-filament::button>
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>
</x-filament-panels::page>
