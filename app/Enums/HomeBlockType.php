<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Hosted\Actions\BuildFaqList;
use App\Domain\Hosted\Support\BlockText;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The six things an operator may put on their home page.
 *
 * ## Five types, and no sixth called "HTML"
 *
 * The obvious way to build an editable page is a rich-text field, and it is the
 * one thing this enum exists to prevent. HOS-8's policy has no `unsafe-inline`,
 * so a `<script>` an operator pasted would be blocked at render — but "blocked
 * by a header" is one configuration mistake away from "executed", and the
 * header is not the only consumer: the same text goes into `og:description`,
 * into the WordPress SEO sync (WPP-6) and into an email. A block is **structured
 * data**, so the markup is ours in every one of those places and the operator's
 * text is a string in all of them.
 *
 * The cost is real and is accepted: an operator cannot centre one word or link
 * a phrase mid-sentence. In exchange, no operator can break their own page's
 * layout, and BRD-2's CSS sanitiser is not the only thing standing between a
 * pasted snippet and a guest's browser.
 *
 * ## Why these six
 *
 * The first five are the page a boat operator actually writes, in the order they
 * write it: who we are with a photograph, what we sell, the paragraph about the
 * family and the boat, the pictures, and how to reach us.
 *
 * The sixth is the FAQ, added by #103, and it is what "a type has to earn its
 * place" was written to mean. It is not a variation on `story` — it is a
 * different table, with its own rows, its own ordering, its own
 * published/unpublished state and a `FAQPage` block of structured data that
 * puts the answers into the search result. The block is a **mount**: it renders
 * `faqs` rows and carries no content of its own beyond its heading, which is why
 * an FAQ block on a page with no published entries renders nothing at all
 * rather than an empty heading.
 */
enum HomeBlockType: string
{
    use HasTranslatedLabel;

    /** The masthead: one image, a heading, a standfirst and one call to action. */
    case Hero = 'hero';

    /** The catalogue, or a slice of it. The only block that reads other tables. */
    case Trips = 'trips';

    /** Heading and prose, with an optional image beside it. The "about us". */
    case Story = 'story';

    /** A row of photographs, each with its own alt text in both locales. */
    case Gallery = 'gallery';

    /** Contact details and the meeting point, from the tenant's own record. */
    case Contact = 'contact';

    /**
     * The operator's tenant-wide FAQ entries (#103).
     *
     * Tenant-wide only, deliberately: a product-specific answer on the home page
     * has no product beside it, and "yes, we stop for a swim" is true of one
     * trip and false of the next. {@see BuildFaqList}
     * is where that rule lives.
     */
    case Faq = 'faq';

    /**
     * Does this type render the operator's prose?
     *
     * These are the types whose escaping matters, and {@see BlockText} is the
     * only thing in the product allowed to turn any of them into markup.
     */
    public function hasProse(): bool
    {
        return in_array($this, [self::Hero, self::Story, self::Contact], true);
    }

    /** Does this type carry a single image of its own? */
    public function hasImage(): bool
    {
        // Contact joined the list when the block became a banner: a photograph
        // of the quay behind a telephone number is the last thing a guest sees
        // before they call, and an operator who cannot put one there is left
        // with a grey box at the bottom of an otherwise photographed page.
        return in_array($this, [self::Hero, self::Story, self::Contact], true);
    }

    /**
     * The default set for an operator who has never opened the editor.
     *
     * Not an empty page. #101 shipped a fallback that showed the operator's
     * name and their trips, and this is that fallback expressed in the block
     * vocabulary — so "start from the default" in the editor produces exactly
     * the page the operator was already serving, rather than a blank one they
     * have to rebuild before they can improve it.
     *
     * ## The FAQ is in the default, and costs nothing when it is empty
     *
     * An operator who writes FAQ entries and has never opened the page editor
     * would otherwise see their answers appear nowhere, and the fix — "add an
     * FAQ block first" — is a step nobody would guess at. The block renders
     * nothing at all when there is nothing published, so a page belonging to an
     * operator who has written no entries is byte for byte the page it was.
     *
     * @return list<self>
     */
    public static function defaultLayout(): array
    {
        return [self::Hero, self::Trips, self::Faq, self::Contact];
    }
}
