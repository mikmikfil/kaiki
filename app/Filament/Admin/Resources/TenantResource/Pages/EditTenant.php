<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Domain\Audit\Data\AuditEntryData;
use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Enums\AuditAction;
use App\Enums\Plan;
use App\Filament\Admin\Resources\TenantResource;
use App\Models\IcalSource;
use App\Models\Tenant;
use App\Support\Tenancy;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * What the platform owner may change about an operator (SAA-1, SEC-16).
 *
 * ## Each field is on this screen for a reason
 *
 * - **Plan** — so an upgrade can be honoured the day it is agreed, rather than
 *   the day the billing code lands.
 * - **Status** — suspension is the only real answer to somebody who has stopped
 *   paying, and it was unreachable through the product.
 * - **Access ends** — see `subscription_ends_at`'s migration: a paying operator
 *   had no date on their record at all, so "who lapses this week" had no answer.
 * - **Sandbox** — a new operator should be able to be put in test mode by the
 *   person setting them up.
 * - **Trade** — a label for the merchant list. It does not change the product.
 * - **QR boarding** — whether tickets carry a QR and the crew get a scanning
 *   page (BKG-20, amended 2026-09-11). A one-boat operator boards from the
 *   passenger list, and the platform decides this with them when they sign up.
 * - **Selling through GetYourGuide** — whether this operator's seats are offered
 *   on an OTA they signed with themselves (ADR-0034). On its own tab, with the
 *   other channels; see {@see self::form()} for why a channel is not a switch.
 *
 * The list has grown past the five it opened with, which is what the tabs of
 * 2026-09-21 are for.
 *
 * Everything else about an operator is theirs: their name, their address, their
 * VAT number, their colours. A platform screen that could rewrite those is a
 * platform screen somebody will use to "fix" a customer's data for them.
 *
 * ## SEC-16, in three parts, all of them here
 *
 * > confirmed and audit-logged with actor, timestamp and reason
 *
 * The **confirmation** is the save action's modal. The **reason** is a required
 * field on it — not on the form, so it belongs to the act rather than to the
 * record, and so it cannot be left over from a previous edit. The **audit row**
 * is written to the *operator's own* trail, because an operator asking "who put
 * us on read-only?" is asking about their account, and an answer they cannot
 * see is not an answer.
 *
 * `TenantPolicy` says who may do this; none of the three above is a policy's to
 * enforce, which is why they are asserted separately in `TenantResourceTest`.
 *
 * ## Only what actually moved is recorded
 *
 * The context carries the fields that changed and their before-and-after, and
 * nothing else. A row saying "plan, status, vertical, sandbox, ends_at" on an
 * edit that changed one of them is a row nobody can read a year later — and the
 * trail is kept for seven.
 *
 * No personal data reaches it (ADR-0025 §3): a plan, a status and a date are
 * facts about an account, not about a person.
 */
class EditTenant extends EditRecord
{
    use HasTenantAccountFields;

    protected static string $resource = TenantResource::class;

    /**
     * The fields whose movement is worth seven years of storage.
     *
     * Named once, so the snapshot and the diff cannot drift apart — which is
     * how an audit trail quietly stops recording one of them.
     */
    private const AUDITED = ['plan', 'status', 'vertical', 'is_sandbox', 'subscription_ends_at', 'check_in_enabled', 'qr_check_in_enabled', 'hosted_site_mode', 'extra_person_pricing_enabled', 'sms_enabled', 'setup_guide_enabled', 'getyourguide_enabled'];

    /** The operator's own words, captured by the confirmation and not by the form. */
    public ?string $auditReason = null;

    /**
     * Four tabs, and the fourth is why there are any (product owner, 2026-09-21).
     *
     * This was one column of nine controls: boarding, which is the crew's;
     * published pages, which are the guest's; extra-person pricing; text
     * messages, which cost money; the setup guide, which is temporary; and now
     * a sales channel, which is a commercial agreement. Nothing said which was
     * which, and three more channels are queued behind GetYourGuide.
     *
     * **A sales channel is not a toggle**, and that is the part grouping alone
     * could not fix. Each one wants to show whether credentials have been
     * entered, how many trips are mapped and when it last spoke — so they get a
     * tab with room, rather than three more rows in a list of switches.
     *
     * The same move the trip form made on 2026-09-18 and the operator's
     * settings made on 2026-09-11. `/admin` was the last screen still in one
     * column.
     *
     * ## What is deliberately not a tab
     *
     * **«Ιστορικό».** Every change here is audited with a typed reason, and the
     * trail is written to the *operator's* own panel — an operator asking who
     * put them on read-only is asking about their account. A platform-wide view
     * across every merchant is a real feature with its own estimate on the
     * roadmap, not something to improvise into a tab here.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('merchant')
                ->columnSpanFull()
                // So a save, or a reload after one, comes back to the tab the
                // change was made on instead of to the first one.
                ->persistTabInQueryString()
                ->tabs([
                    Tabs\Tab::make(__('tenants.edit.subscription'))->schema([
                        $this->subscriptionSection(),
                        $this->accountSection(),
                    ]),

                    Tabs\Tab::make(__('tenants.edit.features'))->schema([
                        $this->featuresSection(),
                    ]),

                    Tabs\Tab::make(__('tenants.edit.channels'))
                        // The count is the useful part of a tab label here: it
                        // answers "does this merchant sell anywhere else" from
                        // the tab bar, without opening it.
                        ->badge(fn (?Tenant $record): ?string => self::channelBadge($record))
                        ->schema([
                            $this->channelsSection(),
                        ]),
                ]),
        ]);
    }

    /**
     * Save, behind a confirmation that asks why.
     *
     * The default save button is replaced rather than added to: two ways to
     * save, one of which skips the reason, is the same as having no reason.
     *
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->requiresConfirmation()
                ->modalHeading(__('tenants.edit.confirm_heading'))
                ->modalDescription(__('tenants.edit.confirm_body'))
                ->form([
                    Textarea::make('auditReason')
                        ->label(__('tenants.edit.reason'))
                        ->helperText(__('tenants.edit.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->auditReason = (string) ($data['auditReason'] ?? '');

                    $this->save();
                }),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * The five values as they were, captured before the write.
     *
     * @var array<string, scalar|null>
     */
    private array $before = [];

    /**
     * Snapshot the old values while they are still the old values.
     *
     * `getOriginal()` in `afterSave()` does **not** work: Eloquent syncs a
     * model's originals to the saved attributes as part of `save()`, so by the
     * time the row is written the "before" and the "after" are the same thing
     * and the audit row comes out empty. That is not a theory — this method
     * exists because the test asserting the trail found exactly nothing.
     */
    protected function beforeSave(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        $this->before = $this->snapshot($tenant);
    }

    /**
     * The trail, written after the row is saved and against the operator.
     *
     * `afterSave` rather than `beforeSave`, so nothing is recorded for a write
     * that failed.
     */
    protected function afterSave(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        $changes = $this->changes($tenant);

        if ($changes === []) {
            // Nothing moved. A row saying an operator was "updated" with no
            // change in it is noise in a trail kept for seven years.
            return;
        }

        app(RecordAuditEntry::class)(
            new AuditEntryData(
                action: AuditAction::TenantUpdated,
                subjectType: 'Tenant',
                subjectId: (int) $tenant->getKey(),
                subjectLabel: $tenant->name,
                reason: $this->auditReason,
                context: $changes,
            ),
            $tenant,
            userId: auth()->id(),
            ipAddress: request()->ip(),
        );
    }

    /**
     * What moved, as `field_from` / `field_to` scalars.
     *
     * Flat rather than nested, because `AuditEntryData::$context` is typed
     * `array<string, scalar|null>` — and it is typed that way so the
     * personal-data scanner can reason about it.
     *
     * @return array<string, scalar|null>
     */
    private function changes(Tenant $tenant): array
    {
        $context = [];

        foreach ($this->snapshot($tenant) as $field => $after) {
            $before = $this->before[$field] ?? null;

            if ($before !== $after) {
                $context["{$field}_from"] = $before;
                $context["{$field}_to"] = $after;
            }
        }

        return $context;
    }

    /**
     * The five auditable fields, flattened to scalars.
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(Tenant $tenant): array
    {
        $values = [];

        foreach (self::AUDITED as $field) {
            $values[$field] = $this->scalar($tenant->getAttribute($field));
        }

        return $values;
    }

    /** A value the audit context can hold: a string, a bool, a null. */
    private function scalar(mixed $value): string|bool|null
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * «Επαναφορά οδηγού»: the guide starts over for this operator.
     *
     * Clears the finish mark and the skipped steps; what the operator has filled
     * in stays, because every step is read from the data. Audited with a reason,
     * like the switches. No delete: seven years of invoices and audit rows hang
     * off this row — see `TenantPolicy`.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetSetupGuide')
                ->label(__('tenants.edit.setup_reset'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('tenants.edit.setup_reset'))
                ->modalDescription(__('tenants.edit.setup_reset_body'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('tenants.edit.reason'))
                        ->helperText(__('tenants.edit.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    /** @var Tenant $tenant */
                    $tenant = $this->getRecord();

                    $tenant->forceFill([
                        'onboarding_completed_at' => null,
                        'onboarding_skipped_steps' => null,
                    ])->save();

                    app(RecordAuditEntry::class)(
                        new AuditEntryData(
                            action: AuditAction::TenantUpdated,
                            subjectType: 'Tenant',
                            subjectId: (int) $tenant->getKey(),
                            subjectLabel: $tenant->name,
                            reason: (string) ($data['reason'] ?? ''),
                            context: ['setup_guide_reset' => true],
                        ),
                        $tenant,
                        userId: auth()->id(),
                        ipAddress: request()->ip(),
                    );

                    Notification::make()->success()->title(__('tenants.edit.setup_reset_done'))->send();
                }),

            /*
             * «Διαγραφή διοργανωτή» (product owner, 2026-09-22).
             *
             * A **soft** delete, and the modal says what that buys: the pages
             * stop opening, the keys stop authenticating and the staff cannot
             * sign in — every resolver finds a tenant through the default scope
             * — while bookings, invoices and the trail stay exactly where they
             * are. `forceDelete` is still refused by the policy and still
             * belongs to the erasure tooling, because `tenant_id` cascades
             * across the whole schema.
             *
             * Two gates rather than one: the merchant's name typed out, because
             * a confirmation dialog is a thing people click through, and the
             * same required reason as every other platform write here (SEC-16).
             */
            Action::make('deleteMerchant')
                ->label(__('tenants.edit.delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => ! $this->tenant()->trashed()
                    && auth()->user()?->can('delete', $this->tenant()) === true)
                ->modalHeading(__('tenants.edit.delete'))
                ->modalSubmitActionLabel(__('tenants.edit.delete'))
                ->modalDescription(__('tenants.edit.delete_body'))
                ->form([
                    TextInput::make('confirmName')
                        ->label(__('tenants.edit.delete_confirm_label'))
                        ->helperText(__('tenants.edit.delete_confirm_help', ['name' => $this->tenant()->name]))
                        ->required()
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (trim((string) $value) !== $this->tenant()->name) {
                                $fail(__('tenants.edit.delete_confirm_mismatch'));
                            }
                        }),
                    Textarea::make('reason')
                        ->label(__('tenants.edit.reason'))
                        ->helperText(__('tenants.edit.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $tenant = $this->tenant();

                    // Written **before** the delete: the entry goes into this
                    // operator's own trail, and a tenant the default scope can
                    // no longer find is one the writer cannot resolve either.
                    $this->recordTenantChange($tenant, (string) ($data['reason'] ?? ''), ['deleted' => true]);

                    $tenant->delete();

                    Notification::make()->success()->title(__('tenants.edit.delete_done'))->send();

                    $this->redirect(static::getResource()::getUrl('index'));
                }),

            /** The way back, with the same reason and the same trail. */
            Action::make('restoreMerchant')
                ->label(__('tenants.edit.restore'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->tenant()->trashed()
                    && auth()->user()?->can('restore', $this->tenant()) === true)
                ->modalHeading(__('tenants.edit.restore'))
                ->modalSubmitActionLabel(__('tenants.edit.restore'))
                ->modalDescription(__('tenants.edit.restore_body'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('tenants.edit.reason'))
                        ->helperText(__('tenants.edit.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $tenant = $this->tenant();

                    $tenant->restore();

                    $this->recordTenantChange($tenant, (string) ($data['reason'] ?? ''), ['restored' => true]);

                    Notification::make()->success()->title(__('tenants.edit.restore_done'))->send();
                }),
        ];
    }

    /** This page's record, typed. */
    private function tenant(): Tenant
    {
        /** @var Tenant */
        return $this->getRecord();
    }

    /**
     * One entry in the operator's own trail, for a change the platform made.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordTenantChange(Tenant $tenant, string $reason, array $context): void
    {
        app(RecordAuditEntry::class)(
            new AuditEntryData(
                action: AuditAction::TenantUpdated,
                subjectType: 'Tenant',
                subjectId: (int) $tenant->getKey(),
                subjectLabel: $tenant->name,
                reason: $reason,
                context: $context,
            ),
            $tenant,
            userId: auth()->id(),
            ipAddress: request()->ip(),
        );
    }

    /**
     * The count on the «Κανάλια» tab, or nothing.
     *
     * Only live channels are counted, and iCal counts. A merchant pulling two
     * calendars is selling the same hulls somewhere else, which is the thing
     * the number is there to warn about — a tab that said «0» while two
     * calendars quietly blocked boats would be worse than no number.
     */
    private static function channelBadge(?Tenant $tenant): ?string
    {
        if (! $tenant instanceof Tenant || ! $tenant->exists) {
            return null;
        }

        $live = $tenant->usesGetYourGuide() && ChannelManagerFlag::isOpen() ? 1 : 0;

        $live += Tenancy::forTenant($tenant, static fn (): int => IcalSource::query()
            ->where('is_active', true)
            ->count());

        return $live > 0 ? (string) $live : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }
}
