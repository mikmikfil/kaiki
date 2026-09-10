<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Filament\Admin\Resources\TenantResource;
use App\Models\Tenant;
use BackedEnum;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The five things the platform owner may change about an operator (SAA-1, SEC-16).
 *
 * ## Five fields, and each is on this screen for a reason
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
 * enforce, which is why they are asserted separately in `AdminTenantEditTest`.
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
    protected static string $resource = TenantResource::class;

    /**
     * The fields whose movement is worth seven years of storage.
     *
     * Named once, so the snapshot and the diff cannot drift apart — which is
     * how an audit trail quietly stops recording one of them.
     */
    private const AUDITED = ['plan', 'status', 'vertical', 'is_sandbox', 'subscription_ends_at'];

    /** The operator's own words, captured by the confirmation and not by the form. */
    public ?string $auditReason = null;

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('tenants.edit.subscription'))
                ->description(__('tenants.edit.subscription_help'))
                ->schema([
                    Select::make('plan')
                        ->label(__('tenants.columns.plan'))
                        ->options(Plan::options())
                        ->required(),

                    Select::make('status')
                        ->label(__('tenants.columns.status'))
                        ->options(TenantStatus::options())
                        ->required(),

                    DatePicker::make('subscription_ends_at')
                        ->label(__('tenants.columns.access_ends'))
                        ->helperText(__('tenants.edit.access_ends_help'))
                        ->native(false),
                ])
                ->columns(3),

            Section::make(__('tenants.edit.account'))
                ->schema([
                    Select::make('vertical')
                        ->label(__('tenants.columns.vertical'))
                        ->options(TenantVertical::options())
                        ->required(),

                    Toggle::make('is_sandbox')
                        ->label(__('tenants.columns.sandbox'))
                        ->helperText(__('tenants.edit.sandbox_help')),
                ])
                ->columns(2),
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

    /** @return array<int, never> */
    protected function getHeaderActions(): array
    {
        // No delete. Seven years of invoices and audit rows hang off this row,
        // and removing an operator is a retention decision rather than a button
        // — see `TenantPolicy`.
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    protected function resolveRecord(int|string $key): Model
    {
        return TenantResource::getEloquentQuery()->findOrFail($key);
    }
}
