# ADR-0021: Image and file storage strategy

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §7 (Branding admin) of `docs/BRIEF.md`; `docs/data-model.md` §2.2, §8 item 4; requirements BRD-6, CAT-6

## Context
Several entities carry images: `brand_profiles` (logo light/dark, favicon, email header), `vessels` (gallery), `products` (route map, gallery), `ports` (photo), `extras` (image). §7 additionally requires "logo upload with auto-resize". §3 names no package for this, so under the §15 rule the first upload feature stalls. The choice determines whether images are polymorphic rows with derived conversions or plain path columns, and it interacts with tenancy: a media table needs its own `tenant_id` scoping and its own isolation test. `docs/data-model.md` provisionally uses plain path columns so M1 is not blocked. Blocks **M1** (issues #16, #17).

## Options

### Option A — Plain path columns plus an upload Action, `intervention/image` for resizing
`logo_light_path`, `photo_path`, `route_map_image_path`, and a JSON `images` array where the brief calls for a gallery. One `App\Domain\Media\Actions\StoreUploadedImage` handles validation, resize and disk write.
Pros
- No new table, no polymorphic relation, no second tenancy scoping story to test.
- The column *is* the value: no join to render a product card, which matters for the availability and catalog endpoints' query-count budget.
- Trivially portable between SQLite and MySQL.
Cons
- Conversions (thumbnail, retina, webp) must be written by hand, and re-deriving them after a size change means a backfill command.
- Galleries in a JSON array cannot be queried or reordered relationally; reordering is a rewrite of the array.

### Option B — `spatie/laravel-medialibrary`
Pros
- Conversions, responsive images, ordering and a single uniform API for every entity; the ecosystem-standard answer, and Filament has first-class integration.
- Gallery reordering and per-image metadata come free.
Cons
- Package outside §3 (needs ADR-0019 or this one to approve it).
- One polymorphic `media` table shared by every tenant — it needs `tenant_id` added and its own global scope, and it is exactly the kind of table where a missing scope leaks one operator's files to another. That is a real cost against ADR-0001's isolation gate.
- Pulls in queued conversions, which means Horizon involvement for something as mundane as a logo.

### Option C — Plain path columns now, medialibrary only for galleries later if reordering proves painful
Pros
- Ships M1 with no new dependency; revisits with evidence.
Cons
- Two mechanisms coexisting is worse than either alone; the migration is per-entity and fiddly.

## Recommendation
**Option A.** The MVP's image needs are a logo, a favicon, a handful of gallery shots and a route map — none of which justify a polymorphic media table whose main risk is the one risk this product cannot afford. `intervention/image` (or `spatie/image`) covers the §7 auto-resize requirement and is already on ADR-0019's shortlist. Revisit only if gallery management becomes an operator complaint.

## Consequences if accepted
- `docs/data-model.md` §2.2 path columns stand as written; no `media` table.
- `intervention/image` is added to the approved list (see ADR-0019) and installed at first use in M1.
- Conversions are generated synchronously on upload at fixed sizes, documented in the spec; a `media:rebuild` Artisan command handles size changes.
- Galleries are ordered JSON arrays; the Filament form owns reordering.
