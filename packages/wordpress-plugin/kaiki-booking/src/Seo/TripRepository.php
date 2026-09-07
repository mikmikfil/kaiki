<?php
/**
 * Where a synced trip meets `wp_posts`.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The writes, and the rules about whose text wins (WPP-6).
 *
 * ## What the sync owns, and what the operator owns
 *
 * This is the support question the feature generates — *"why did my text change
 * back"* — so the answer is written down here, said again on the settings
 * screen, and enforced rather than intended:
 *
 * - **The sync owns** the title, the body, the slug, the status and its own
 *   meta. They come from Kaiki, they are overwritten on every run where the
 *   content hash moved, and an edit to them in WordPress is lost. That is what
 *   a mirror is.
 * - **The operator owns the excerpt**, from the moment they change it. The sync
 *   writes it once, remembers what it wrote, and never touches it again unless
 *   it still matches — so an operator who rewrote the summary for their own
 *   audience keeps it, and one who never touched it keeps getting Kaiki's.
 * - **The operator owns the featured image, the comments and anything a theme or
 *   another plugin attached.** Nothing here writes them.
 *
 * ## Unpublished, trashed, never deleted
 *
 * A trip switched off for the season becomes a draft: the URL stops answering,
 * which is what unpublishing means, and everything about the post survives. A
 * product deleted on the platform is trashed, which is recoverable and keeps the
 * mapping — `wp_delete_post` would lose it, so a restore on the platform would
 * create a second post at `slug-2` and every inbound link to the first would
 * stay broken.
 *
 * ## Identity is uuid *and* language
 *
 * One product becomes one post per language, so a lookup by uuid alone finds
 * whichever of them the database happens to return first — and then the English
 * sync overwrites the Greek post, in English, at the Greek URL.
 */
final class TripRepository {

	/**
	 * What the sync last wrote as the excerpt, so an operator's edit is visible.
	 */
	public const META_EXCERPT = '_kaiki_excerpt_synced';

	/**
	 * Apply one row of the feed, in one language.
	 *
	 * @param  array<string, mixed> $row    One row of `data`.
	 * @param  string               $locale `el` or `en`.
	 * @return string `created`, `updated`, `unchanged`, `unpublished`, `trashed` or `skipped`.
	 */
	public static function apply( array $row, string $locale ): string {
		$uuid = isset( $row['uuid'] ) ? (string) $row['uuid'] : '';

		if ( '' === $uuid ) {
			return 'skipped';
		}

		$existing = self::find( $uuid, $locale );

		if ( ! empty( $row['tombstone'] ) ) {
			return self::trash( $existing );
		}

		if ( ! TripContent::renderable( $row, $locale ) ) {
			// A translation the operator has not written yet. An existing post
			// in this language is unpublished rather than left saying whatever
			// it said before the translation was cleared.
			return self::unpublish( $existing );
		}

		$fields = TripContent::fields( $row, $locale );
		$hash   = isset( $row['content_hash'] ) ? (string) $row['content_hash'] : '';

		if ( null !== $existing ) {
			return self::update( $existing, $fields, $row, $locale, $hash );
		}

		return self::create( $fields, $row, $locale, $hash );
	}

	/**
	 * The post for this product in this language, if we have made one.
	 *
	 * `post_status => 'any'` matters: a trip unpublished last season is exactly
	 * the post this must find when it comes back, and the default query would
	 * skip it and create a duplicate at `slug-2`.
	 *
	 * @param string $uuid   The product's uuid.
	 * @param string $locale `el` or `en`.
	 */
	public static function find( string $uuid, string $locale ): ?int {
		$found = get_posts(
			array(
				'post_type'        => TripPostType::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Two indexed meta keys, once per product per run, in cron. There is no other way to find a post by the id of the thing it mirrors.
				'meta_query'       => array(
					array(
						'key'   => TripPostType::META_UUID,
						'value' => $uuid,
					),
					array(
						'key'   => TripPostType::META_LANG,
						'value' => $locale,
					),
				),
			)
		);

		if ( ! is_array( $found ) || array() === $found ) {
			return null;
		}

		return (int) $found[0];
	}

	/**
	 * A post this site has never had for this product.
	 *
	 * @param  array{post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string} $fields The post fields.
	 * @param  array<string, mixed>                                                                                          $row    One row of `data`.
	 * @param  string                                                                                                        $locale `el` or `en`.
	 * @param  string                                                                                                        $hash   The feed's content hash.
	 * @return string
	 */
	private static function create( array $fields, array $row, string $locale, string $hash ): string {
		$id = wp_insert_post(
			array_merge(
				$fields,
				array(
					'post_type'   => TripPostType::POST_TYPE,
					'post_author' => 0,
				)
			),
			true
		);

		if ( is_wp_error( $id ) || 0 === (int) $id ) {
			return 'skipped';
		}

		self::write_meta( (int) $id, $row, $locale, $hash, $fields['post_excerpt'] );

		return 'created';
	}

	/**
	 * A post we have already made, brought back into line with the platform.
	 *
	 * @param  int                                                                                                           $id     The existing post.
	 * @param  array{post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string} $fields The post fields.
	 * @param  array<string, mixed>                                                                                          $row    One row of `data`.
	 * @param  string                                                                                                        $locale `el` or `en`.
	 * @param  string                                                                                                        $hash   The feed's content hash.
	 * @return string
	 */
	private static function update( int $id, array $fields, array $row, string $locale, string $hash ): string {
		$stored  = (string) get_post_meta( $id, TripPostType::META_HASH, true );
		$current = get_post_status( $id );

		// The hash covers the prose and nothing else, so a status change with
		// unchanged text still has to be applied — that is the whole of what
		// unpublishing a trip for the season looks like from here.
		if ( '' !== $hash && $stored === $hash && $current === $fields['post_status'] ) {
			return 'unchanged';
		}

		// Asked **before** the write, because the write is what would make the
		// answer wrong: comparing afterwards compares the excerpt we just saved
		// against the one we saved last time, which is never equal, and the
		// record of what we wrote would never be updated again.
		$ours = self::excerpt_is_ours( $id );

		// The slug is not in this list, and that is deliberate. Changing it
		// silently breaks every link anybody has ever shared and WordPress
		// leaves no redirect behind — a trip renamed on the platform keeps the
		// URL it was indexed at, which costs nobody anything.
		$update = array(
			'ID'           => $id,
			'post_title'   => $fields['post_title'],
			'post_content' => $fields['post_content'],
			'post_status'  => $fields['post_status'],
		);

		if ( $ours ) {
			$update['post_excerpt'] = $fields['post_excerpt'];
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			return 'skipped';
		}

		self::write_meta( $id, $row, $locale, $hash, $ours ? $fields['post_excerpt'] : null );

		return 'publish' === $fields['post_status'] ? 'updated' : 'unpublished';
	}

	/**
	 * Has the operator rewritten the summary?
	 *
	 * Compared against what the sync last wrote rather than against what the
	 * feed says now: an operator who edited the excerpt owns it from then on,
	 * including through a change on the platform. A post from before this meta
	 * existed has no record, and is treated as the operator's — the safe way
	 * round, because overwriting text somebody wrote is the failure worth
	 * avoiding and re-writing text nobody reads is not.
	 *
	 * @param int $id The post.
	 */
	private static function excerpt_is_ours( int $id ): bool {
		$written = get_post_meta( $id, self::META_EXCERPT, true );

		if ( ! is_string( $written ) || '' === $written ) {
			return false;
		}

		return trim( (string) get_post_field( 'post_excerpt', $id ) ) === trim( $written );
	}

	/**
	 * Unpublish, keeping everything else.
	 *
	 * @param int|null $id The post, if there is one.
	 */
	private static function unpublish( ?int $id ): string {
		if ( null === $id ) {
			return 'skipped';
		}

		if ( 'draft' === get_post_status( $id ) ) {
			return 'unchanged';
		}

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);

		return 'unpublished';
	}

	/**
	 * Trash, which is recoverable and keeps the mapping.
	 *
	 * @param int|null $id The post, if there is one.
	 */
	private static function trash( ?int $id ): string {
		if ( null === $id ) {
			// A tombstone for a product this site never had a page for. Nothing
			// to do, and not a failure — a feed replayed from the beginning is
			// full of them.
			return 'skipped';
		}

		if ( 'trash' === get_post_status( $id ) ) {
			return 'unchanged';
		}

		wp_trash_post( $id );

		return 'trashed';
	}

	/**
	 * The meta the next run recognises this post by.
	 *
	 * @param int                  $id      The post.
	 * @param array<string, mixed> $row     One row of `data`.
	 * @param string               $locale  `el` or `en`.
	 * @param string               $hash    The feed's content hash.
	 * @param string|null          $excerpt What was written as the excerpt, or null if it was left alone.
	 */
	private static function write_meta( int $id, array $row, string $locale, string $hash, ?string $excerpt ): void {
		update_post_meta( $id, TripPostType::META_UUID, isset( $row['uuid'] ) ? (string) $row['uuid'] : '' );
		update_post_meta( $id, TripPostType::META_LANG, $locale );
		update_post_meta( $id, TripPostType::META_HASH, $hash );

		$canonical = $row['product']['seo']['canonical_url'] ?? null;

		update_post_meta( $id, TripPostType::META_CANONICAL, is_string( $canonical ) ? $canonical : '' );

		$meta = TripContent::meta( $row, $locale );

		update_post_meta( $id, '_kaiki_meta_title', $meta['title'] );
		update_post_meta( $id, '_kaiki_meta_description', $meta['description'] );

		if ( null !== $excerpt ) {
			update_post_meta( $id, self::META_EXCERPT, $excerpt );
		}
	}
}
