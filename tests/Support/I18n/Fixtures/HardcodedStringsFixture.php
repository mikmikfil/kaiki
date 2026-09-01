<?php

declare(strict_types=1);

namespace Tests\Support\I18n\Fixtures;

/**
 * A deliberately wrong file, so the i18n lint has something to catch.
 *
 * The issue's Notes are explicit: *"Before closing, deliberately introduce one
 * hardcoded string and one orphaned key, confirm each check goes red, and
 * revert. A lint that has never failed is not a lint."* Sabotaging the working
 * tree proves it once; this proves it on every run, and nobody has to remember
 * to revert anything.
 *
 * **Nothing here is real code.** It is never instantiated, and it is outside
 * every path in `scannedPaths()` so the production scan does not see it.
 *
 * Half the value is the second half: the identifiers below — an icon name, a
 * column, a locale code, a Tailwind class list — are the shapes that appear on
 * every Filament resource in the codebase. If loosening the scanner to stop
 * flagging them also stops it flagging the labels, the scanner is worthless,
 * and `NoHardcodedStringsTest` asserts both directions.
 */
final class HardcodedStringsFixture
{
    /** Should be flagged: Filament renders this directly. */
    protected static string $navigationLabel = 'Departures';

    /** Should NOT be flagged: an icon name is not read by anyone. */
    protected static string $navigationIcon = 'heroicon-o-key';

    public function form(FluentStub $component): FluentStub
    {
        return $component
            // Flagged — a label a person reads.
            ->label('Vessels')
            ->emptyStateHeading('No trips yet')
            // Not flagged — already translated.
            ->helperText(__('api_keys.form.name.help'))
            // Not flagged — identifiers, not prose.
            ->tooltip('created_at')
            ->placeholder('el')
            // Flagged, and only a whole-file scanner can see it: Pint wraps a
            // fluent chain exactly like this once it passes the line limit, so
            // this is what a real M1 resource looks like.
            ->modalHeading(
                'Delete this vessel',
            );
    }
}

/**
 * A fluent no-op, so the fixture reads like the real resources it stands in for
 * without dragging Filament into a scanner test.
 *
 * @method self label(string $value)
 * @method self emptyStateHeading(string $value)
 * @method self helperText(string $value)
 * @method self tooltip(string $value)
 * @method self placeholder(string $value)
 * @method self modalHeading(string $value)
 */
final class FluentStub
{
    /** @param  list<mixed>  $arguments */
    public function __call(string $method, array $arguments): self
    {
        return $this;
    }
}
