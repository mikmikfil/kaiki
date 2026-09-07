<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's brand, exactly one row per tenant (`docs/data-model.md` §2.2,
 * spec BRD-1, BRD-3).
 *
 * Item **9** in the §6 migration order — the first M1 table, ahead of `ports`
 * and `vessels`. Its filename carries `000009` for that reason: the numeric
 * suffix in this project's M1 migrations *is* the §6 item number, so the file
 * listing and the document read in the same order. It lands after 10 and 11 in
 * commit time rather than before them, which is harmless here and only here —
 * `brand_profiles` holds a foreign key to `tenants` and to nothing else, so a
 * database that already ran 10 and 11 runs this next with nothing to resolve.
 *
 * **No `uuid`.** Every other tenant-owned table has one because something
 * outside the panel addresses the row; nothing ever addresses a brand profile,
 * because there is one and it is reached through its tenant. `GET
 * /api/v1/branding` (BRD-6, issue #35) is authenticated as the operator and
 * returns *the* profile — it takes no identifier at all.
 *
 * **No soft deletes**, per §2.2: reset-to-defaults overwrites the row rather
 * than deleting it, because BRD-3's guarantee is that a profile always exists.
 * A deleted-then-restored brand profile would be a tenant rendering unbranded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table): void {
            $table->id();

            // Unique rather than merely indexed: this is what makes the 1:1 in
            // BRD-1 a database fact instead of a convention. It also serves
            // `GET /api/v1/branding`, which is the widget's first call on every
            // page load and must be a single indexed row read (§2.2).
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();

            // Paths on the storage disk, not rows in a media table
            // (ADR-0021, Option A). Light and dark are separate columns because
            // an operator's logo often needs a different file rather than a
            // filter — a dark wordmark on a dark widget is invisible, and CSS
            // cannot invent the light version.
            $table->string('logo_light_path', 255)->nullable();
            $table->string('logo_dark_path', 255)->nullable();
            $table->string('favicon_path', 255)->nullable();
            $table->string('email_header_image_path', 255)->nullable();

            // `#RRGGBB`, validated by regex on the way in (§2.2). Stored as hex
            // strings rather than parsed components because the only two
            // consumers are CSS custom properties and the email templates, and
            // both want the string back.
            //
            // The literals below are the platform defaults from §2.2. They are
            // also in `config('kaiki.branding.defaults')`, which is what the
            // observer writes, and `BrandProfileDefaultsTest` asserts the two
            // agree by reading the schema — a column default and a config value
            // that quietly disagree is a tenant whose brand depends on which
            // code path created it. The column default is not redundant: it is
            // what protects a row written around the observer by an import or a
            // raw insert from rendering as an empty string in someone's CSS.
            $table->char('color_primary', 7)->default('#0F62FE');
            $table->char('color_secondary', 7)->default('#0B3D91');
            $table->char('color_accent', 7)->default('#FFB000');
            $table->char('color_background', 7)->default('#FFFFFF');
            $table->char('color_text', 7)->default('#101828');

            // A curated list *or* a Google Fonts family name, so this is a free
            // string rather than an enum — the curated list is a form concern
            // and a new Google font must not need a migration.
            $table->string('font_family', 80)->default('Inter');

            // PHP enum `FontSource`. Decides whether the widget requests
            // fonts.googleapis.com at all, which is a GDPR question about the
            // visitor's IP before it is a typography one.
            $table->string('font_source', 16)->default('system');

            // 0-32, enforced in validation. tinyint unsigned tops out at 255,
            // so the column cannot express a value the form would reject —
            // which is the point of the range living in validation and not here.
            $table->unsignedTinyInteger('button_radius_px')->default(8);

            // PHP enum `WidgetTheme`. `auto` means the *visitor's*
            // prefers-color-scheme, not the operator's — which is why the
            // default moved to `light` in #106: the design review of 4 September
            // settled that guest surfaces are light, and an operator's colours
            // are chosen against white. The value here and
            // `config('kaiki.branding.defaults.widget_theme')` are compared by
            // `BrandProfileDefaultsTest`; they move together or the build fails.
            $table->string('widget_theme', 8)->default('light');

            // Translatable (§1.6), and nullable: an operator with nothing to say
            // in a mail footer is ordinary. Because it is nullable it is not in
            // the model's `requiredTranslations` — the both-locales rule applies
            // to a field that has been filled in, not to one left empty.
            $table->json('email_footer_text')->nullable();

            // §3.10 — a fixed key set (`website`, `instagram`, `facebook`,
            // `tripadvisor`, `whatsapp`), each validated as a URL or E.164.
            // Unknown keys are rejected, so the hosted-page footer cannot be
            // turned into an open redirect list.
            //
            // NOT NULL with no database default, deliberately: **MySQL 8 refuses
            // a literal DEFAULT on a JSON column**, and a `DEFAULT (JSON_OBJECT())`
            // expression has no SQLite equivalent (ENV-12 forbids branching on
            // the driver). The default lives on the model instead, the same
            // choice `vessels.specs` and `vessels.images` made in #16.
            $table->json('social_links');

            // Stored raw and sanitised on **read** as well as write (§2.2), so
            // tightening the sanitiser later is a one-file change rather than a
            // data migration over every operator's CSS.
            $table->text('custom_css')->nullable();

            // The last computed WCAG results (BRD-5), so the panel can show the
            // badge without recomputing four contrast ratios on every render.
            // NOT NULL with no database default, for the JSON reason above.
            $table->json('contrast_warnings');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
