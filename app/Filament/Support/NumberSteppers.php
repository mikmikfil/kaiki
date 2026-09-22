<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Support;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\TextInput;

/**
 * − and + on either side of every number field in the panel.
 *
 * Product owner, 2026-09-22: *«όπου στο διαχειριστικό έχει βελάκια πάνω κάτω
 * για αύξηση αριθμού, τα θέλω οριζόντια + και −»*.
 *
 * The browser's own spinner is two arrows stacked inside a 15-pixel column at
 * the right-hand edge of the field. It is invisible until the pointer is over
 * it, it does not exist at all on a phone, and the two halves are eight pixels
 * tall each — under a third of the 24 pixels WCAG 2.5.8 asks of a control, and
 * a quarter of what {@see Support} already gives a row action on
 * a boat. Two buttons either side of the value are the same two decisions at a
 * size a thumb can hit.
 *
 * ## Registered once, for every number field there is
 *
 * `TextInput::configureUsing()` runs for each field as it is made, so this
 * reaches the 79 numeric inputs in this panel and every one added after,
 * including the ones inside repeaters — and the fields have no idea it
 * happened. The alternative was a stylesheet plus a script that walked the DOM
 * looking for `input[type=number]`, which has to run again after every Livewire
 * render and fights morphdom over elements the server never sent.
 *
 * ## Why the buttons do not go to the server
 *
 * `alpineClickHandler` means the press is handled in the browser: the value
 * changes and an `input` event tells Livewire, exactly as typing does. A
 * Livewire action would be a round trip per press — and this is the control
 * somebody holds down to get from 1 to 12.
 */
final class NumberSteppers
{
    public static function register(): void
    {
        TextInput::configureUsing(static function (TextInput $input): void {
            $input->prefixAction(
                self::button($input, 'decrease', 'heroicon-m-minus', -1),
                // Inline only on a number field with no prefix of its own.
                //
                // **The `getType()` test is not redundant with the action's own
                // `visible()`**, and leaving it out broke every text field in
                // both panels for an hour: Filament decides the input's own
                // padding from `count($prefixActions)` *before* it filters the
                // invisible ones out, so an always-present hidden action told
                // every field it had an inline prefix — and an inline prefix
                // that is not drawn is a field whose value starts hard against
                // its left edge, with no room at all.
                fn (): bool => $input->getType() === 'number' && blank($input->getPrefixLabel()),
            );

            $input->suffixAction(
                self::button($input, 'increase', 'heroicon-m-plus', 1),
                fn (): bool => $input->getType() === 'number' && blank($input->getSuffixLabel()),
            );
        });
    }

    /**
     * One of the two buttons.
     *
     * Hidden on everything that is not a number field, which is what makes this
     * safe to hang on every `TextInput` there is: an email, a name or a masked
     * money field renders exactly as it did. Hidden on a disabled field too —
     * a control that cannot change a value it is attached to is a lie the
     * keyboard also has to step past.
     */
    private static function button(TextInput $input, string $name, string $icon, int $direction): Action
    {
        return Action::make($name)
            ->label(__('panel.number.' . $name))
            ->icon($icon)
            ->iconButton()
            ->color('gray')
            ->extraAttributes(['class' => 'ka-step', 'tabindex' => '-1'])
            ->visible(fn (): bool => $input->getType() === 'number' && ! $input->isDisabled())
            // `$el` is the button; the helper finds the field it belongs to.
            // A guard rather than a bare call: the script is injected by a
            // render hook, and a field that renders before it is parsed should
            // do nothing rather than throw on every press.
            ->alpineClickHandler("window.kaikiStepNumber && window.kaikiStepNumber(\$el, {$direction})");
    }
}
