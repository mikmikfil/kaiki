<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\DepartureResource\Actions;

use App\Domain\Operations\Actions\GenerateManifest;
use App\Domain\Operations\Support\Manifest;
use App\Enums\ManifestColumn;
use App\Models\Departure;
use App\Models\User;
use App\Support\Authorization\Capability;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The passenger list, downloaded (spec OPS-8, OPS-10, GDR-6).
 *
 * ## Two capabilities, and they are not the same question
 *
 * `ViewManifest` says whether this operator may have a passenger list at all —
 * crew have it, because standing on the quay with one is their job.
 * `ViewGuestDocuments` says whether they may have the *numbers*, which is a
 * separate and much narrower permission. So the column is not merely absent
 * from the output for somebody without it: it is absent from the form, because
 * a disabled checkbox still tells them the data is there.
 *
 * ## The warning is on the form, before the download
 *
 * OPS-10 makes this a logged action. Telling somebody afterwards that their
 * name is now attached to a list of passport numbers is not consent; telling
 * them on the form is.
 */
final class ManifestAction
{
    public static function make(): Action
    {
        return Action::make('manifest')
            ->label(__('manifest.action.label'))
            ->icon('heroicon-o-clipboard-document-list')
            ->visible(static fn (): bool => self::userCan(Capability::ViewManifest))
            ->form([
                CheckboxList::make('columns')
                    ->label(__('manifest.action.columns'))
                    ->options(self::columnOptions())
                    ->default(array_map(
                        static fn (ManifestColumn $column): string => $column->value,
                        self::defaultColumns(),
                    ))
                    ->columns(2)
                    ->required(),
                Radio::make('layout')
                    ->label(__('manifest.action.layout'))
                    ->options([
                        'standard' => __('manifest.action.layout_standard'),
                        'harbour' => __('manifest.action.layout_harbour'),
                    ])
                    ->default('standard')
                    ->inline(),
                Radio::make('format')
                    ->label(__('manifest.action.format'))
                    ->options(['pdf' => 'PDF', 'csv' => 'CSV'])
                    ->default('pdf')
                    ->inline()
                    // Said before the download rather than after it. Telling
                    // somebody afterwards that their name is now attached to a
                    // list of passport numbers is not consent.
                    ->helperText(self::userCan(Capability::ViewGuestDocuments)
                        ? __('manifest.action.sensitive')
                        : null),
            ])
            ->action(static function (Departure $record, array $data): StreamedResponse {
                $columns = ManifestColumn::fromValues(
                    array_values(array_intersect(
                        array_map('strval', (array) $data['columns']),
                        array_keys(self::columnOptions()),
                    )),
                );

                $generate = app(GenerateManifest::class);

                $manifest = $generate->forDeparture($record, $columns, Auth::id());

                return ($data['format'] ?? 'pdf') === 'csv'
                    ? self::csv($generate, $manifest)
                    : self::pdf($generate, $manifest, (string) ($data['layout'] ?? 'standard'));
            });
    }

    /**
     * The columns this operator may even ask for.
     *
     * @return array<string, string>
     */
    private static function columnOptions(): array
    {
        $options = ManifestColumn::options();

        if (! self::userCan(Capability::ViewGuestDocuments)) {
            unset($options[ManifestColumn::DocumentNumber->value]);
        }

        return $options;
    }

    /**
     * @return list<ManifestColumn>
     */
    private static function defaultColumns(): array
    {
        $defaults = ManifestColumn::defaults();

        if (self::userCan(Capability::ViewGuestDocuments)) {
            return $defaults;
        }

        return array_values(array_filter(
            $defaults,
            static fn (ManifestColumn $column): bool => ! $column->isSensitive(),
        ));
    }

    private static function csv(GenerateManifest $generate, Manifest $manifest): StreamedResponse
    {
        $csv = $generate->csv($manifest);

        return Response::streamDownload(
            static function () use ($csv): void {
                echo $csv;
            },
            $generate->filename($manifest, 'csv'),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Blade through Chromium (ARC-10, which forbids dompdf).
     *
     * `setHtml` rather than a URL: the renderer has no session, so pointing it
     * at a panel page would render a login screen — and pointing it at an
     * unauthenticated route would mean an unauthenticated route that renders
     * passport numbers.
     */
    private static function pdf(GenerateManifest $generate, Manifest $manifest, string $layout): StreamedResponse
    {
        $html = view(
            $layout === 'harbour' ? 'manifests.harbour' : 'manifests.standard',
            ['manifest' => $manifest],
        )->render();

        $pdf = Browsershot::html($html)
            ->format('A4')
            ->showBackground()
            ->pdf();

        return Response::streamDownload(
            static function () use ($pdf): void {
                echo $pdf;
            },
            $generate->filename($manifest, 'pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private static function userCan(Capability $capability): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasCapability($capability);
    }
}
