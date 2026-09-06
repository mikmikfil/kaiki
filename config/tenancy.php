<?php

declare(strict_types=1);

use App\Models\GatewayWebhookEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;

/*
|--------------------------------------------------------------------------
| Tenancy — single database (ADR-0001, Option A)
|--------------------------------------------------------------------------
|
| Kaiki runs one database with a `tenant_id` on every tenant-owned table. That
| keeps cross-tenant reporting, the super-admin panel and the hosted pages
| simple, at the cost of leaning entirely on the global scope for isolation —
| a trade paid for by the isolation test suite that #8 makes a required CI gate.
|
| stancl/tenancy ships a config aimed at database-per-tenant. Most of it —
| database managers, per-tenant filesystem roots, Redis key prefixing, tenant
| migration paths — describes machinery this application does not use, and
| leaving it in place would tell the next reader that per-tenant databases
| exist somewhere. It has been removed rather than left commented out.
|
| The one thing kept from that file is `bootstrappers`, deliberately empty:
| bootstrappers are what swap connections, cache prefixes and filesystem roots
| when a tenant is initialised. In single-database mode none of that should
| happen. An entry appearing here would be a silent move to per-tenant
| infrastructure.
|
*/

return [

    'tenant_model' => Tenant::class,

    /*
     * `id` on `tenants` is a normal auto-incrementing bigint, so there is no
     * generator. stancl's UUID generator is for string-keyed tenant models.
     * Our public identifier is the separate `uuid` column (data-model §1.1).
     */
    'id_generator' => null,

    /*
     * Hosts that are never an operator. Requests to these resolve no tenant,
     * which — per spec TEN-4 — means any query on a tenant-owned model throws
     * rather than falling back to some default. Tenant resolution itself is
     * issue #7.
     */
    'central_domains' => [
        'localhost',
        '127.0.0.1',
    ],

    /*
     * Empty on purpose. See the header note.
     */
    'bootstrappers' => [],

    'features' => [],

    /*
    |--------------------------------------------------------------------------
    | Platform-owned models (spec TEN-5)
    |--------------------------------------------------------------------------
    |
    | `BelongsToTenant` is mandatory on every tenant-owned model. There is no
    | implicit exemption: a model that genuinely belongs to the platform rather
    | than to one operator must be named here, so "what is not scoped" is a
    | single list a reviewer can read in ten seconds, rather than a property of
    | whichever class someone remembered to add a trait to.
    |
    | The isolation suite in #8 reads this list: every Eloquent model under
    | `app/Models` that is not named here must use the trait, or the build
    | fails. Adding a model to this list is therefore a deliberate, reviewable
    | act — which is the whole point.
    |
    */
    'platform_owned_models' => [
        // The tenant itself.
        Tenant::class,

        // Operator staff *and* platform super-admins share this table:
        // `tenant_id` is nullable and null means super-admin, so a global
        // scope here would make super-admins invisible to their own panel.
        // Access control for users is a policy question (#9).
        User::class,

        // The first genuinely platform-owned reference table: VAT rates are
        // set by Greek tax law, not by operators (#47, ADR-0002 Option A). A
        // super-admin maintains the rows; an operator only *selects* one per
        // product or extra, so scoping this per tenant would mean every
        // operator maintaining their own copy of the tax code.
        VatRate::class,

        /*
         * **The third case this list has met, and the only one of its kind.**
         *
         * `Tenant` and `User` are platform-owned because they *are* the
         * platform; `VatRate` because Greek tax law is not an operator's to
         * maintain. All three are platform-owned forever.
         *
         * `GatewayWebhookEvent` is not. It is written **before the tenant is
         * known** — a payment provider calls with no key, no session and no
         * subdomain, and the row has to exist before any of that is resolved
         * (§2.7) — and then *acquires* a tenant once the payment is matched.
         * `tenant_id` is nullable for exactly that window, and it is the only
         * nullable-and-later-filled tenant column in the schema.
         *
         * It is listed here rather than given `BelongsToTenant` because a
         * global scope would make the row invisible to the very job whose task
         * is to work out which tenant it belongs to. Access is a policy
         * question instead: the platform failure feed reads it, an operator
         * never does.
         */
        GatewayWebhookEvent::class,
    ],

];
