{{--
    Two things the sidebar gets wrong on a browser that has been here before, or
    on a phone that has not.

    Both are the same shape: Filament stores a navigation preference in
    `localStorage` and then trusts it forever, so a default that was decided
    afterwards — or one that was never right for the viewport — can never take
    effect. Both are fixed once per browser and never touched again.

    In `<head>`, before `@filamentScripts`, because these have to win the race
    with Alpine reading the keys — not undo the sidebar after it has been
    painted.
--}}
<script>
    (function () {
        try {
            /*
             * 1. The drawer, on the first panel page opened on a phone.
             *
             * Filament seeds its sidebar store with `$persist(true)` and never
             * consults the viewport (`filament/filament/dist/index.js`:
             * `isOpen: window.Alpine.$persist(!0).as("isOpen")`). Above `lg`
             * that is right — the sidebar is the page's left column and is
             * always there. Below it, the same `true` means a 320px drawer and
             * a dark backdrop over a 390px screen, so an operator's *first*
             * arrival in the back office has nothing tappable on it and their
             * first act is dismissing a menu they did not open.
             *
             * It shows up once and never again, because the dismissal persists
             * `false` — which is why nobody sees it on a second look, and why
             * #129's Playwright run at 390px is what found it.
             *
             * Only when nothing has been stored yet: an operator who opened the
             * drawer keeps it open, on any width, because that was their choice
             * and not ours. Safe at desktop width either way — Filament's
             * closed state still carries `lg:translate-x-0`.
             */
            if (
                window.localStorage.getItem('isOpen') === null &&
                ! window.matchMedia('(min-width: 1024px)').matches
            ) {
                window.localStorage.setItem('isOpen', 'false');
            }

            /*
             * 2. «Ρυθμίσεις», which was supposed to start collapsed and does
             *    not — in any browser that had opened the panel before it was.
             *
             * `components/sidebar/index.blade.php` writes the panel's collapsed
             * groups into `localStorage` **only when the key is null**. Anyone
             * who used the panel before `->collapsed()` was added to
             * `AppPanelProvider` already has `[]` stored, so the setting is read
             * once, found to be a decision already made, and the group is open
             * for ever. The product owner asked for it collapsed and, in his own
             * browser, it was not.
             *
             * Clearing the key on every load would be worse than the bug: it
             * would throw away an operator's own collapse and expand every time
             * they load a page. So it is cleared **once**, against a marker, and
             * Filament reseeds from the panel config on that same load. A later
             * change to which groups start collapsed gets a new marker; today
             * there is one.
             */
            if (window.localStorage.getItem('kaiki:nav-groups') === null) {
                window.localStorage.removeItem('collapsedGroups');
                window.localStorage.setItem('kaiki:nav-groups', 'v1');
            }
        } catch (error) {
            // A browser refusing localStorage (private mode, blocked site data)
            // leaves Filament's own defaults in place. That is the behaviour of
            // today, which is worse but not broken, and it is not worth an
            // exception on the way into the panel.
        }
    })();
</script>
