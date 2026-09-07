<?php
/**
 * The one screen an operator fills in.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The settings screen (WPP-3, WPP-11, WPP-14).
 *
 * ## What it says about the secret key is a requirement, not a courtesy
 *
 * WPP-3 item 4: the page must state, in the operator's own language, that the
 * secret is optional, what it unlocks, and that it must never be pasted into a
 * page or a theme template. So the field is **hidden entirely until the SEO
 * toggle is on** — the best way to stop somebody pasting a secret into a page is
 * for them never to have been shown a box asking for one.
 *
 * ## Nonces and capabilities on everything
 *
 * WPP-11. `register_setting` gives the nonce for the main form; the connection
 * test is a separate POST and carries its own. Both check
 * `manage_options`, because an editor who can publish a page is not somebody who
 * should be able to repoint the site's booking key.
 */
final class SettingsPage {

	private const SLUG = 'kaiki-booking';

	private const GROUP = 'kaiki_booking_settings';

	/**
	 * Hook the screen up. Admin only; nothing here runs on a visitor's request.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_fields' ) );
		add_action( 'admin_post_kaiki_test_connection', array( self::class, 'handle_test_connection' ) );
	}

	/**
	 * Under Settings, where a WordPress user looks for a plugin's settings.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Kaiki Booking', 'kaiki-booking' ),
			__( 'Kaiki Booking', 'kaiki-booking' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * The two options, each with its own sanitiser.
	 */
	public static function register_fields(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::GROUP,
			Settings::SECRET_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_secret' ),
				'default'           => '',
				// **Never in the REST API.** `register_setting` exposes a
				// setting at `/wp/v2/settings` when `show_in_rest` is true, and
				// the default is false — this line is here so that nobody adds
				// it later without reading why it is absent.
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * The settings option, field by field, with nothing carried through unread.
	 *
	 * @param mixed $input The submitted option.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		return array(
			'publishable_key' => self::clean_key( $input['publishable_key'] ?? '', 'pk_' ),
			'api_base'        => esc_url_raw( trim( (string) ( $input['api_base'] ?? '' ) ) ),
			'locale_mode'     => in_array( $input['locale_mode'] ?? '', array( 'auto', 'el', 'en' ), true )
				? (string) $input['locale_mode']
				: 'auto',
			'cache_ttl'       => (int) ( $input['cache_ttl'] ?? 300 ),
			'seo_pages'       => ! empty( $input['seo_pages'] ),
		);
	}

	/**
	 * The secret key, which must look like one or be discarded.
	 *
	 * @param mixed $input The submitted secret.
	 */
	public static function sanitize_secret( $input ): string {
		return self::clean_key( $input, 'sk_' );
	}

	/**
	 * A key, or nothing, with a message when it is the wrong sort.
	 *
	 * The prefix check is the useful half: an operator who pastes their secret
	 * into the publishable field has made a serious mistake quietly, and telling
	 * them so is the whole value of validating a field whose contents we cannot
	 * otherwise judge.
	 *
	 * @param mixed  $input  The submitted value.
	 * @param string $prefix The prefix this field accepts.
	 */
	private static function clean_key( $input, string $prefix ): string {
		$value = sanitize_text_field( (string) $input );

		if ( '' === $value ) {
			return '';
		}

		if ( ! str_starts_with( $value, $prefix ) ) {
			add_settings_error(
				Settings::OPTION,
				'kaiki_wrong_key_type',
				'pk_' === $prefix
					? __( 'That does not look like a publishable key. A publishable key starts with pk_. Never paste a secret key here — it would be sent to every visitor.', 'kaiki-booking' )
					: __( 'That does not look like a secret key. A secret key starts with sk_.', 'kaiki-booking' ),
				'error'
			);

			return '';
		}

		return $value;
	}

	/**
	 * The screen itself.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'kaiki-booking' ) );
		}

		$settings = Settings::all();
		$secret   = get_option( Settings::SECRET_OPTION, '' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Kaiki Booking', 'kaiki-booking' ); ?></h1>

			<p class="description" style="max-width:44rem">
				<?php echo esc_html__( 'Connect this site to your Kaiki account, then put a booking form on any page with a shortcode, a block or an Elementor widget.', 'kaiki-booking' ); ?>
			</p>

			<?php settings_errors( Settings::OPTION ); ?>

			<?php
			// Shown once and then forgotten: a connection result from an hour
			// ago is a statement about an hour ago, and an operator reading it
			// as current would chase a problem that is already fixed.
			$result = get_transient( 'kaiki_connection_result' );

			if ( is_array( $result ) ) {
				delete_transient( 'kaiki_connection_result' );

				printf(
					'<div class="notice notice-%s"><p>%s</p></div>',
					esc_attr( empty( $result['ok'] ) ? 'error' : 'success' ),
					esc_html( (string) ( $result['message'] ?? '' ) )
				);
			}
			?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="kaiki_publishable_key"><?php echo esc_html__( 'Publishable key', 'kaiki-booking' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text code" id="kaiki_publishable_key"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[publishable_key]"
								value="<?php echo esc_attr( $settings['publishable_key'] ); ?>"
								placeholder="pk_live_…">
							<p class="description">
								<?php echo esc_html__( 'From your Kaiki panel, under API keys. This one is safe on a public page: it can read your catalogue and start a booking, and nothing else.', 'kaiki-booking' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kaiki_api_base"><?php echo esc_html__( 'Kaiki address', 'kaiki-booking' ); ?></label>
						</th>
						<td>
							<input type="url" class="regular-text code" id="kaiki_api_base"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[api_base]"
								value="<?php echo esc_attr( $settings['api_base'] ); ?>"
								placeholder="https://book.kaiki.gr">
							<p class="description">
								<?php echo esc_html__( 'Leave this as it is unless you were told otherwise.', 'kaiki-booking' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kaiki_locale_mode"><?php echo esc_html__( 'Language', 'kaiki-booking' ); ?></label>
						</th>
						<td>
							<select id="kaiki_locale_mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[locale_mode]">
								<option value="auto" <?php selected( $settings['locale_mode'], 'auto' ); ?>>
									<?php echo esc_html__( 'Follow the page (recommended)', 'kaiki-booking' ); ?>
								</option>
								<option value="el" <?php selected( $settings['locale_mode'], 'el' ); ?>>
									<?php echo esc_html__( 'Always Greek', 'kaiki-booking' ); ?>
								</option>
								<option value="en" <?php selected( $settings['locale_mode'], 'en' ); ?>>
									<?php echo esc_html__( 'Always English', 'kaiki-booking' ); ?>
								</option>
							</select>
							<p class="description">
								<?php echo esc_html__( 'Following the page uses WPML or Polylang if you have one, and your site language if you do not.', 'kaiki-booking' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kaiki_cache_ttl"><?php echo esc_html__( 'Cache, in seconds', 'kaiki-booking' ); ?></label>
						</th>
						<td>
							<input type="number" class="small-text" id="kaiki_cache_ttl" min="60" max="86400" step="60"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[cache_ttl]"
								value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>">
							<p class="description">
								<?php echo esc_html__( 'How long your trips are remembered on this site. Changes you make in Kaiki appear immediately anyway — Kaiki tells this site when something changed.', 'kaiki-booking' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php echo esc_html__( 'Trip pages for search engines', 'kaiki-booking' ); ?></h2>

				<p class="description" style="max-width:44rem">
					<?php echo esc_html__( 'Off by default, and the plugin is complete without it. Switched on, each of your trips also becomes a page on this site, so Google finds it here as well as on Kaiki.', 'kaiki-booking' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Trip pages', 'kaiki-booking' ); ?></th>
						<td>
							<label>
								<input type="checkbox" value="1"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[seo_pages]"
									<?php checked( $settings['seo_pages'] ); ?>>
								<?php echo esc_html__( 'Create a page on this site for each trip', 'kaiki-booking' ); ?>
							</label>
						</td>
					</tr>

					<?php if ( $settings['seo_pages'] ) : ?>
						<tr>
							<th scope="row">
								<label for="kaiki_secret_key"><?php echo esc_html__( 'Secret key', 'kaiki-booking' ); ?></label>
							</th>
							<td>
								<input type="password" class="regular-text code" id="kaiki_secret_key"
									name="<?php echo esc_attr( Settings::SECRET_OPTION ); ?>"
									value="<?php echo esc_attr( is_string( $secret ) ? $secret : '' ); ?>"
									autocomplete="off"
									placeholder="sk_live_…">
								<p class="description">
									<strong><?php echo esc_html__( 'Only the trip pages need this, and only on this screen.', 'kaiki-booking' ); ?></strong>
									<?php echo esc_html__( 'It is used by this site\'s own scheduled task to read your full catalogue, including trips you have not published. It is never sent to a visitor.', 'kaiki-booking' ); ?>
								</p>
								<p class="description">
									<strong><?php echo esc_html__( 'Never paste this key into a page, a post, a widget or a theme file.', 'kaiki-booking' ); ?></strong>
									<?php echo esc_html__( 'Anything on a page is public. If it ever appears on one, delete the key in your Kaiki panel and make a new one.', 'kaiki-booking' ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php echo esc_html__( 'Is it connected?', 'kaiki-booking' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kaiki_test_connection">
				<?php wp_nonce_field( 'kaiki_test_connection' ); ?>

				<p>
					<button type="submit" class="button">
						<?php echo esc_html__( 'Test the connection', 'kaiki-booking' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * The connection test.
	 *
	 * It asks the one endpoint every installation can reach with a publishable
	 * key, and reports what happened in words rather than a status code. The
	 * three failures are genuinely different problems with genuinely different
	 * fixes, and an operator told only "it did not work" will change the wrong
	 * one.
	 */
	public static function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'kaiki-booking' ) );
		}

		check_admin_referer( 'kaiki_test_connection' );

		set_transient( 'kaiki_connection_result', self::probe(), MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );

		exit;
	}

	/**
	 * Ask the API whether this key works from this site, and say so in words.
	 *
	 * @return array{ok: bool, message: string}
	 */
	private static function probe(): array {
		if ( ! Settings::is_configured() ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no key saved yet.', 'kaiki-booking' ),
			);
		}

		$response = wp_remote_get(
			Settings::api_base() . '/api/v1/branding',
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . Settings::publishable_key(),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This site could not reach Kaiki at all. Check the address above, or try again in a minute.', 'kaiki-booking' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'ok'      => false,
				/* translators: the site's own address, which has to be on the key's allowed list. */
				'message' => sprintf(
					// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- The comment is on the line above.
					__( 'Kaiki refused the key. Either it is wrong, or this site (%s) is not on the key\'s list of allowed addresses — you can add it in your Kaiki panel.', 'kaiki-booking' ),
					home_url()
				),
			);
		}

		if ( 200 !== $code ) {
			return array(
				'ok'      => false,
				'message' => __( 'Kaiki answered, but not in a way this plugin understands. Try again shortly.', 'kaiki-booking' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connected. This site can read your trips.', 'kaiki-booking' ),
		);
	}
}
