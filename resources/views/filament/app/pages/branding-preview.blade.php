{{--
    BRD-4's live preview (#110), deferred from #17 because there was no widget
    to preview.

    ## It is the real widget, not a picture of one

    The issue is explicit about this and it is the whole reason the panel embeds
    a `<script>` tag rather than drawing a styled `div`: a hand-made preview is a
    second implementation of the widget's appearance, and it is wrong the first
    time either changes. The Shadow DOM boundary that makes the widget safe on a
    stranger's page is exactly what makes it safe inside Filament — their CSS
    cannot reach in and the widget's cannot leak out into the panel.

    ## No key, because a plaintext key does not exist to be had

    Only a key's hash, prefix and last four are stored (SEC-3), so the panel has
    nothing to authenticate a fetch with, and minting a real credential in order
    to look at a colour would be absurd. Instead the operator's own branding and
    a couple of their own trips go on the page as `__kaikiPreview`, and the
    bundle's preview transport answers from that. Same bundle, same components,
    same shadow root; only the transport differs, and the transport is the one
    part of the widget a preview could never exercise anyway.

    ## Live means the custom properties, not a re-fetch

    The widget reads branding from `GET /api/v1/branding`, which returns what is
    **saved**. An operator dragging a colour picker has not saved anything, so a
    preview that re-fetched would lag by one round trip and one save.

    Instead the panel writes the operator's unsaved choices onto the host element
    as `--kaiki-*` custom properties. They inherit through the shadow boundary —
    that is what custom properties do, and it is why WGT-9 uses them — so the
    real widget repaints as the operator types, with no request at all.

    Nothing here is a hardcoded string (I18N-1).
--}}
@php($preview = $this->previewState())

<section
    class="fi-section rounded-xl bg-white p-4 shadow-sm sm:p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    aria-labelledby="branding-preview-heading"
    x-data="{
        apply(properties) {
            const host = this.$refs.widget;

            if (! host) {
                return;
            }

            // The same names WGT-9 fixes. Written on the host element,
            // inherited into the shadow root.
            Object.entries(properties).forEach(([name, value]) => {
                if (value) {
                    host.style.setProperty(name, value);
                }
            });
        },
    }"
    x-init="apply(@js($preview['properties']))"
    {{-- Livewire wraps a dispatched event's parameters in `detail`. --}}
    @branding-changed.window="apply($event.detail.properties)"
>
    <style>
        .ka-preview-frame { position: relative; padding: 1.5rem .5rem .5rem; border-radius: .75rem; border: 1px dashed rgb(var(--gray-300)); background: rgb(var(--gray-50)); }
        .dark .ka-preview-frame { border-color: rgb(var(--gray-500)); background: rgba(255, 255, 255, .04); }
        .ka-preview-tag { position: absolute; top: .3rem; left: .6rem; font-size: .6875rem; font-weight: 600; line-height: 1rem; color: rgb(var(--gray-600)); }
        .dark .ka-preview-tag { color: rgb(var(--gray-300)); }
    </style>

    <h2 id="branding-preview-heading" class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
        {{ __('branding.preview.heading') }}
    </h2>

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ __('branding.preview.body') }}
    </p>

    <div class="mt-4 grid gap-6 lg:grid-cols-2">
        <div>
            <h3 class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                {{ __('branding.preview.widget') }}
            </h3>

            {{-- The preview stays light in a dark panel on purpose: it is
                 the guest's page, drawn in the operator's own colours, and a
                 guest's phone decides its own theme (2026-09-23). The frame and
                 the «Προεπισκόπηση» tag say so, so a white box in a dark panel
                 reads as a picture of something else rather than a mistake. --}}
            <div class="ka-preview-frame mt-2">
                <span class="ka-preview-tag">{{ __('branding.preview.tag') }}</span>

                {{-- `wire:ignore` because the widget owns this subtree: Livewire
                     re-rendering the page around a mounted shadow root would tear it
                     down on every keystroke, which is the opposite of live. --}}
                <div x-ref="widget" wire:ignore>
                    {{-- Before the bundle, because the bundle reads it on load. --}}
                    <script>window.__kaikiPreview = @json($preview['payload']);</script>

                    {{-- The alias, not a versioned path: the panel should show what
                         operators are actually running (ADR-0011). --}}
                    <script
                        src="{{ $preview['bundle'] }}"
                        data-key="{{ $preview['key'] }}"
                        data-mount="list"
                        data-locale="{{ app()->getLocale() }}"
                        data-analytics="false"
                    ></script>
                </div>
            </div>
        </div>

        <div>
            <h3 class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                {{ __('branding.preview.email') }}
            </h3>

            {{-- An iframe, because an email template carries its own document —
                 table layouts and inline styles that would fight the panel's
                 stylesheet if they were inlined into this page. --}}
            <div class="ka-preview-frame mt-2">
                <span class="ka-preview-tag">{{ __('branding.preview.tag') }}</span>

                <iframe
                    title="{{ __('branding.preview.email') }}"
                    class="block h-96 w-full rounded-md"
                    srcdoc="{{ $preview['email'] }}"
                ></iframe>
            </div>
        </div>
    </div>
</section>
