<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Hosted\Actions\BuildFaqList;
use App\Domain\Hosted\Support\BlockItems;
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

    /*
     * The five of 16 September, brought over from the design of the operator's
     * WordPress site. Each is a short **list of structured entries** kept in
     * `items` and shaped by {@see BlockItems} — still no free markup anywhere:
     * a number is a string, a review is a string, and a button points at one of
     * a fixed set of places on the operator's own site.
     */

    /** Up to four figures — «30+ / χρόνια στη θάλασσα» — in a card under the hero. */
    case Stats = 'stats';

    /** Three numbered steps, from choosing a trip to stepping aboard. */
    case Steps = 'steps';

    /** Up to four reasons to choose the operator, each behind an icon. */
    case Features = 'features';

    /** Up to three guest reviews, each with a star rating. */
    case Testimonials = 'testimonials';

    /** A photographed band with a heading, a sentence and one or two buttons. */
    case Cta = 'cta';

    /*
     * The five of 24 September, for «Σχετικά με εμάς» (on either page, though).
     *
     * The timeline is written by the operator. The other four are **mounts**,
     * like the FAQ: they carry a heading and a line of their own, and the rest
     * comes from what Kaiki already holds — so a boat added in «Σκάφη» is on
     * the page the same minute, and a licence number is never typed twice.
     */

    /** Up to eight years, each with a title and a line: «1968 — Η πρώτη βάρκα». */
    case Timeline = 'timeline';

    /** The boats, from «Σκάφη»: photograph, capacity, length, licence. */
    case Fleet = 'fleet';

    /** The people, from «Ομάδα»: captains and deckhands, with photograph and «Λίγα λόγια». */
    case Crew = 'crew';

    /** Licences and business details: the tenant's legal name, ΑΦΜ, ΓΕΜΗ, the boats' licences. */
    case Credentials = 'credentials';

    /** One port from «Λιμάνια», with its photograph, address and directions. */
    case MeetingPoint = 'meeting_point';

    /**
     * Does this type fill itself from Kaiki's own records?
     *
     * The editor says so under the type, so nobody hunts for the field where
     * the boats are typed in.
     */
    public function isMount(): bool
    {
        return in_array($this, [self::Trips, self::Faq, self::Fleet, self::Crew, self::Credentials, self::MeetingPoint], true);
    }

    /**
     * Does this type render the operator's prose?
     *
     * These are the types whose escaping matters, and {@see BlockText} is the
     * only thing in the product allowed to turn any of them into markup.
     */
    public function hasProse(): bool
    {
        // The four list-shaped sections take a lead paragraph under their
        // heading; the numbers card is figures and nothing else.
        return in_array($this, [
            self::Hero, self::Story, self::Contact,
            self::Steps, self::Features, self::Testimonials, self::Cta,
            self::Fleet, self::Crew, self::Credentials, self::MeetingPoint,
        ], true);
    }

    /** Does this type carry a single image of its own? */
    public function hasImage(): bool
    {
        // Contact joined the list when the block became a banner: a photograph
        // of the quay behind a telephone number is the last thing a guest sees
        // before they call, and an operator who cannot put one there is left
        // with a grey box at the bottom of an otherwise photographed page.
        //
        // The call-to-action band is a photograph with words on it, so its
        // image is required by the editor. The steps and the reasons take an
        // optional one, shown beside the section.
        return in_array($this, [self::Hero, self::Story, self::Contact, self::Cta, self::Steps, self::Features], true);
    }

    /**
     * Does this type show the short line above its heading (`eyebrow`)?
     *
     * Not the gallery, the questions or the contact panel, whose markup is
     * shared with pages that have no such line.
     */
    public function hasEyebrow(): bool
    {
        return in_array($this, [
            self::Hero, self::Trips, self::Story,
            self::Stats, self::Steps, self::Features, self::Testimonials, self::Cta,
            self::Timeline, self::Fleet, self::Crew, self::Credentials, self::MeetingPoint,
        ], true);
    }

    /**
     * How many entries this type keeps in `items`; zero for a type with none.
     *
     * The hero's entries are its row of small trust badges.
     */
    public function maxItems(): int
    {
        return match ($this) {
            self::Hero, self::Steps, self::Testimonials => 3,
            self::Stats, self::Features => 4,
            self::Timeline => 8,
            default => 0,
        };
    }

    public function hasItems(): bool
    {
        return $this->maxItems() > 0;
    }

    /** How many buttons this type keeps in `buttons`; zero for a type with none. */
    public function maxButtons(): int
    {
        return in_array($this, [self::Hero, self::Cta], true) ? 2 : 0;
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

    /**
     * What «Σχετικά με εμάς» opens with in the editor, before its first save.
     *
     * Never served: an operator's about page is live, and in the menu, only once
     * they have saved it — a page of someone else's words would be worse than
     * none.
     *
     * @return list<self>
     */
    public static function aboutLayout(): array
    {
        return [self::Hero, self::Stats, self::Story, self::Timeline, self::Fleet, self::Crew, self::Testimonials, self::Credentials, self::MeetingPoint, self::Cta];
    }
}
