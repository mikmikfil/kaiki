# ADR-0008: Translatable field storage — JSON columns vs a translations table

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §3 (Conventions), §4 (Catalog), §12 (i18n) of `docs/BRIEF.md`; requirements I18N-4 … I18N-11, CAT-6, NFR-3

## Context
§12 names `spatie/laravel-translatable` for translatable model fields, which stores translations as JSON in the column itself (`{"el": "...", "en": "..."}`). Products, Vessels, MeetingPoints, Extras and CancellationPolicies all carry translatable text, and some of it must be searched (operator panel search, hosted-page product listing) and sorted (product title ordering, category tabs in the widget `list` mount). MySQL 8 and SQLite both have JSON support but their functions and collations differ, and the environment split in ADR-0015 means every query must run on both. This decision shapes `docs/data-model.md` and every Filament resource; it blocks **M1**.

## Options

### Option A — `spatie/laravel-translatable` JSON columns, with a generated/denormalised sort key per locale where needed
Translatable text lives in a JSON column. Where sorting or searching matters, add a plain indexed column populated from the JSON on save (for example `products.title_sort_el`, `title_sort_en`, or a single `search_blob` maintained by an observer).
Pros
- The package named in §3 and §12; zero deviation, zero ADR debt for new packages.
- One row per product; no joins, no N+1 when listing 40 products with 2 locales.
- Adding a third locale is a data change, not a migration.
- Filament v3 has first-class support for translatable JSON fields.
Cons
- Naive `LIKE` search over a JSON column matches locale keys as well as values; must search the denormalised blob instead.
- Ordering by a JSON path is not portable: MySQL `JSON_UNQUOTE(JSON_EXTRACT(...))` with a locale-aware collation and SQLite `json_extract` sort differently, especially for Greek accented characters. The denormalised sort column is what makes this portable, and it must be kept in sync.
- No per-translation metadata (translator, reviewed_at, published) if that is ever wanted.

### Option B — A polymorphic `translations` table (`translatable_type`, `translatable_id`, `locale`, `field`, `value`)
Pros
- Indexable and sortable with ordinary SQL on both engines; search is a normal `LIKE` on `value`.
- Per-translation metadata and partial translations are natural.
- Missing-translation reporting is a query.
Cons
- Introduces a package outside §3 (or a hand-rolled trait) and contradicts an explicit §12 instruction.
- Every catalogue read needs an eager load; the widget `list` mount and the hosted landing page become join-heavy exactly where the 150 ms p95 budget lives.
- Filament forms need custom repeaters; more code to maintain for a two-person team.

### Option C — JSON columns for content, a real column for the canonical locale
Store `title` (canonical, tenant default locale) as a normal column and `title_translations` JSON for the rest.
Pros
- Sorting and searching on the canonical column are trivially portable.
Cons
- Two ways to express one field; "which one is authoritative" bugs are guaranteed.
- Falls apart for tenants whose default locale changes.

## Recommendation
**Option A**, with an explicit rule that *no query may sort or filter on a JSON path*. Any field that needs search or ordering gets a plain, indexed, observer-maintained companion column (`*_sort_{locale}` or a single per-row `search_index` text column containing all locales concatenated and accent-folded). This stays inside §3, keeps reads single-row, and pushes the MySQL/SQLite divergence into one small, well-tested observer rather than across the query layer.

## Consequences if accepted
- `docs/data-model.md` marks translatable columns as JSON and lists the companion sort/search columns and the observer that maintains them.
- A `HasTranslatableSearch` concern rebuilds `search_index` on save; a backfill command exists for imports.
- Accent folding (Greek tonos and final sigma normalisation) is a shared helper, used by both the observer and the query builder, so it behaves identically on both engines.
- A PHPStan or architecture test forbids `json_extract` / `->>` inside `orderBy` and `where` on translatable columns.
- Locale fallback order is fixed: requested locale, then tenant `default_locale`, then `en`; missing translations surface in a Filament "incomplete translations" widget.
