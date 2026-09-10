{{--
    The first-run setup guide (#51, SAA-9, SAA-10).

    The wizard supplies its own step navigation and its own final button — see
    `Setup::finishAction()` — so this template holds the form and nothing else.
    A second submit button underneath would be a second way to finish, on a page
    whose whole point is that there is one thing to do next.

    Nothing here is a hardcoded string; `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    <form wire:submit="finish">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
