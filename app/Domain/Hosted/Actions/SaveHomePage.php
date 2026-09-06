<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Actions;

use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;
use Illuminate\Support\Facades\DB;

/**
 * Replace an operator's home page with the blocks they just submitted.
 *
 * ## Why the whole page is replaced rather than diffed
 *
 * The editor is a reorderable list, so a save can create, update, delete and
 * move in one submission. Diffing that means matching rows to form entries by
 * an id the form has to carry, and a mismatched id writes one block's text over
 * another's — the failure mode is silent and the operator's own page is the
 * evidence. Replacing is one `delete` and one `insert` inside a transaction,
 * costs nothing at this row count, and cannot mix two blocks up.
 *
 * The visible cost is that `uuid` changes on every save. Nothing points at a
 * block by uuid: there is no per-block URL, no anchor an operator can share and
 * no API. If any of those arrive, this becomes a diff and the uuid becomes the
 * key it matches on — that is a deliberate future change, written down here so
 * it is not discovered by breaking a link.
 *
 * ## Settings are normalised here, not trusted
 *
 * The form cannot produce an unknown key, and this Action is not only called by
 * the form: a seeder, an import and a future API all land here.
 * {@see BlockSettings::normalise()} is what makes "settings holds exactly the
 * documented keys" true of every row rather than of the rows one screen wrote.
 */
class SaveHomePage
{
    /**
     * @param  list<array<string, mixed>>  $blocks  the form's own state, in display order
     * @return int the number of blocks stored
     */
    public function __invoke(array $blocks): int
    {
        return DB::transaction(function () use ($blocks): int {
            // Tenant-scoped by `BelongsToTenant`, so this deletes one
            // operator's page and cannot reach another's.
            HomePageBlock::query()->delete();

            $order = 0;

            foreach ($blocks as $input) {
                $type = HomeBlockType::tryFrom((string) ($input['type'] ?? ''));

                // A block whose type is not one of the five is dropped rather
                // than stored with a null type. There is no sixth type and a
                // row that claimed one would break the render of a page a
                // guest is looking at, in a template chosen by a string.
                if ($type === null) {
                    continue;
                }

                $block = new HomePageBlock([
                    'type' => $type,
                    'sort_order' => $order++,
                    'is_visible' => (bool) ($input['is_visible'] ?? true),
                    'image_path' => $type->hasImage() ? $this->path($input['image_path'] ?? null) : null,
                    'images' => $type === HomeBlockType::Gallery ? $this->images($input['images'] ?? null) : null,
                    'settings' => BlockSettings::normalise($type, is_array($input['settings'] ?? null) ? $input['settings'] : []),
                ]);

                // Assigned only when there is something to assign. Passing null
                // to a translatable attribute does **not** null the column:
                // `spatie/laravel-translatable` reads it as "this locale's
                // translation is null" and stores `{"en":null}`, which is a
                // blank heading wearing a JSON object. Leaving the attribute
                // untouched lets the nullable column keep its own null, which
                // is what "there is no heading" should look like in the row as
                // well as on the page.
                $this->translate($block, 'heading', $input['heading'] ?? null);

                if ($type->hasProse()) {
                    $this->translate($block, 'body', $input['body'] ?? null);
                }

                $block->save();
            }

            return $order;
        });
    }

    /** Set a translatable attribute, or leave it alone when it is blank everywhere. */
    protected function translate(HomePageBlock $block, string $key, mixed $value): void
    {
        $translations = $this->translations($value);

        if ($translations !== null) {
            $block->setTranslations($key, $translations);
        }
    }

    /**
     * A translatable value, or null when it is blank in every locale.
     *
     * Storing `{"el": "", "en": ""}` would make `$block->heading` an empty
     * string rather than null, and every `@if ($block->heading)` in the
     * templates would render an empty `<h2>`. Null is the value that means
     * "there is no heading", so a blank set becomes one.
     *
     * @return array<string, string>|null
     */
    protected function translations(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $translations = [];

        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $translations[$locale] = trim($text);
            }
        }

        return $translations === [] ? null : $translations;
    }

    protected function path(mixed $value): ?string
    {
        // Filament's file upload hands back a single-entry array when the
        // component is not `multiple()`, and a bare string once the state has
        // been rehydrated from the database. Both are ordinary.
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The gallery's images, each keeping its own alt text.
     *
     * An image with no path is dropped; an image with no alt is **kept**, with
     * an empty alt set. A11Y wants alt text on every image and the form asks
     * for it, but refusing the save would mean an operator loses eight uploaded
     * photographs because they have not described the ninth yet.
     *
     * @return list<array{path: string, alt: array<string, string>}>|null
     */
    protected function images(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $images = [];

        foreach ($value as $image) {
            $path = $this->path(is_array($image) ? ($image['path'] ?? null) : $image);

            if ($path === null) {
                continue;
            }

            $images[] = [
                'path' => $path,
                'alt' => $this->translations(is_array($image) ? ($image['alt'] ?? null) : null) ?? [],
            ];
        }

        return $images === [] ? null : $images;
    }
}
