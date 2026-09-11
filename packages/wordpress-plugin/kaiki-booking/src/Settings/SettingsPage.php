<?php
/**
 * The one screen an operator fills in.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Settings;

use Kaiki\Booking\Seo\Sync;

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
	 * The admin page's hook suffix, as `add_options_page` returned it.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hook the screen up. Admin only; nothing here runs on a visitor's request.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_kaiki_test_connection', array( self::class, 'handle_test_connection' ) );
	}

	/**
	 * Under Settings, where a WordPress user looks for a plugin's settings.
	 */
	public static function add_page(): void {
		$hook = add_options_page(
			__( 'Kaiki Booking', 'kaiki-booking' ),
			__( 'Kaiki Booking', 'kaiki-booking' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);

		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * WordPress's own colour picker, on this screen and no other.
	 *
	 * The hook fires on every admin page, and a plugin that loaded its scripts
	 * on all of them is a plugin that breaks somebody else's screen one day.
	 * The picker is WordPress's rather than one of ours because it is the one
	 * an operator has already used in the Customizer, and it needs no build.
	 *
	 * @param string $hook_suffix The admin page being loaded.
	 */
	public static function enqueue_assets( $hook_suffix ): void {
		if ( '' === self::$hook || self::$hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', self::appearance_script() );
	}

	/**
	 * The few lines that make the «Appearance» section behave.
	 *
	 * Inline rather than a file, for the reason `Blocks.php` gives for having no
	 * build: it shows and hides some rows and repaints a preview, and a second
	 * asset to version and cache-bust would be more machinery than behaviour.
	 *
	 * The contrast rule is written again here, in JavaScript, so the preview's
	 * button text matches what `Appearance::on_primary()` will send. The
	 * fallbacks are Kaiki's default brand (hull teal on white) — the preview
	 * cannot know the operator's own Kaiki branding, so it says so underneath.
	 */
	private static function appearance_script(): string {
		return <<<'JS'
( function ( $ ) {
	'use strict';

	var fallback = { primary: '#0b4f4a', text: '#16211f', background: '#ffffff', radius: 8 };

	function luminance( hex ) {
		var channels = [ 1, 3, 5 ].map( function ( start ) {
			var value = parseInt( hex.substr( start, 2 ), 16 ) / 255;

			return value <= 0.03928 ? value / 12.92 : Math.pow( ( value + 0.055 ) / 1.055, 2.4 );
		} );

		return 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
	}

	function contrast( first, second ) {
		return ( Math.max( first, second ) + 0.05 ) / ( Math.min( first, second ) + 0.05 );
	}

	function onPrimary( hex ) {
		var own = luminance( hex );

		return contrast( own, luminance( '#ffffff' ) ) >= contrast( own, luminance( '#111111' ) ) ? '#ffffff' : '#111111';
	}

	function colour( field, otherwise ) {
		var value = String( $( field ).val() || '' ).trim().toLowerCase();

		return /^#[0-9a-f]{6}$/.test( value ) ? value : otherwise;
	}

	$( function () {
		var $section = $( '#kaiki-appearance' );
		var preview = document.getElementById( 'kaiki-appearance-preview' );

		if ( ! $section.length || ! preview ) {
			return;
		}

		function update() {
			var custom = $section.find( 'input[name$="[appearance]"]:checked' ).val() === 'custom';
			var fontMode = $section.find( 'input[name$="[font_mode]"]:checked' ).val();
			var fontName = String( $( '#kaiki_font_name' ).val() || '' ).replace( /[^A-Za-z0-9 \-]/g, '' ).trim();
			var radius = parseInt( $( '#kaiki_radius' ).val(), 10 );
			var primary = colour( '#kaiki_primary', fallback.primary );
			var font = 'Inter, system-ui, sans-serif';

			if ( fontMode === 'theme' ) {
				font = 'inherit';
			} else if ( fontMode === 'custom' && fontName ) {
				font = '"' + fontName + '", sans-serif';
			}

			$section.find( '.kaiki-appearance-custom' ).prop( 'hidden', ! custom );
			$( '#kaiki-font-name' ).prop( 'hidden', fontMode !== 'custom' );

			preview.style.setProperty( '--kaiki-primary', primary );
			preview.style.setProperty( '--kaiki-on-primary', onPrimary( primary ) );
			preview.style.setProperty( '--kaiki-text', colour( '#kaiki_text', fallback.text ) );
			preview.style.setProperty( '--kaiki-background', colour( '#kaiki_background', fallback.background ) );
			preview.style.setProperty( '--kaiki-radius', ( isNaN( radius ) ? fallback.radius : Math.max( 0, Math.min( 30, radius ) ) ) + 'px' );
			preview.style.setProperty( '--kaiki-font', font );
		}

		// The picker changes the field after it calls back, so the repaint waits
		// for the value to land.
		$section.find( '.kaiki-colour' ).wpColorPicker( {
			change: function () {
				window.setTimeout( update );
			},
			clear: function () {
				window.setTimeout( update );
			}
		} );

		$section.on( 'change input', update );

		update();
	} );
}( jQuery ) );
JS;
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
			Settings::WEBHOOK_SECRET_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
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
			// A permalink base, not free text: it becomes part of every trip's
			// URL, and a value with a slash or a space in it produces rewrite
			// rules that match nothing and a hundred 404s nobody can explain.
			'trip_base'       => self::clean_base( $input['trip_base'] ?? '' ),
		) + self::sanitize_appearance( $input );
	}

	/**
	 * The «Appearance» fields.
	 *
	 * Through the same validators `Settings::all()` reads them with, so there is
	 * one definition of "a colour" in the plugin rather than a save-side one and
	 * a read-side one that drift. The custom values are kept even while the
	 * choice is «As in Kaiki», so switching back to «My own» brings them back
	 * instead of making the operator pick their colours again.
	 *
	 * @param array<string, mixed> $input The submitted option.
	 * @return array<string, mixed>
	 */
	private static function sanitize_appearance( array $input ): array {
		$font_mode = Settings::font_mode( $input['font_mode'] ?? null );
		$font_name = Settings::font_name( $input['font_name'] ?? null );

		// «A font my theme loads» with no usable name is not a choice anybody
		// made; saving it as Kaiki's font makes the screen show what the widget
		// will actually do.
		if ( 'custom' === $font_mode && '' === $font_name ) {
			$font_mode = 'kaiki';
		}

		$radius = Settings::radius( $input['radius'] ?? null );

		return array(
			'appearance' => Settings::appearance_mode( $input['appearance'] ?? null ),
			'primary'    => Settings::colour( $input['primary'] ?? null ),
			'text'       => Settings::colour( $input['text'] ?? null ),
			'background' => Settings::colour( $input['background'] ?? null ),
			'font_mode'  => $font_mode,
			'font_name'  => $font_name,
			// Empty rather than null in the option, like the colours: "not set"
			// is one value in `wp_options`, whichever field it is.
			'radius'     => null === $radius ? '' : $radius,
		);
	}

	/**
	 * A permalink base, or the default.
	 *
	 * @param mixed $input The submitted value.
	 */
	private static function clean_base( $input ): string {
		$base = sanitize_title( (string) $input );

		return '' === $base ? 'tours' : $base;
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

		$settings       = Settings::all();
		$secret         = get_option( Settings::SECRET_OPTION, '' );
		$webhook_secret = get_option( Settings::WEBHOOK_SECRET_OPTION, '' );

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

				<?php self::render_appearance( $settings ); ?>

				<h2 class="title"><?php echo esc_html__( 'Live updates', 'kaiki-booking' ); ?></h2>

				<p class="description" style="max-width:44rem">
					<?php echo esc_html__( 'Kaiki tells this site the moment something changes — a new trip, a new price — so visitors never see an out-of-date catalogue. Paste the update secret from your Kaiki panel to switch it on.', 'kaiki-booking' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="kaiki_webhook_secret"><?php echo esc_html__( 'Update secret', 'kaiki-booking' ); ?></label>
						</th>
						<td>
							<input type="password" class="regular-text code" id="kaiki_webhook_secret"
								name="<?php echo esc_attr( Settings::WEBHOOK_SECRET_OPTION ); ?>"
								value="<?php echo esc_attr( is_string( $webhook_secret ) ? $webhook_secret : '' ); ?>"
								autocomplete="off">
							<p class="description">
								<?php echo esc_html__( 'Without it, your trips are refreshed on the timer above instead. Give Kaiki this address:', 'kaiki-booking' ); ?>
								<code><?php echo esc_html( rest_url( \Kaiki\Booking\Http\Webhook::NAMESPACE . \Kaiki\Booking\Http\Webhook::ROUTE ) ); ?></code>
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
								<label for="kaiki_trip_base"><?php echo esc_html__( 'Address for trip pages', 'kaiki-booking' ); ?></label>
							</th>
							<td>
								<code><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/</code>
								<input type="text" class="small-text code" id="kaiki_trip_base"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[trip_base]"
									value="<?php echo esc_attr( $settings['trip_base'] ); ?>">
								<code>/&hellip;</code>
								<p class="description">
									<?php echo esc_html__( 'Change this only if something on your site already uses that word — two things cannot share one address.', 'kaiki-booking' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__( 'Who owns the text', 'kaiki-booking' ); ?></th>
							<td>
								<p class="description" style="max-width:44rem">
									<strong><?php echo esc_html__( 'Kaiki owns the title and the body of a trip page.', 'kaiki-booking' ); ?></strong>
									<?php echo esc_html__( 'They are rewritten every time you change the trip in Kaiki, so editing them here will not last. Edit the trip in Kaiki instead.', 'kaiki-booking' ); ?>
								</p>
								<p class="description" style="max-width:44rem">
									<strong><?php echo esc_html__( 'You own the short summary, the featured image and the address.', 'kaiki-booking' ); ?></strong>
									<?php echo esc_html__( 'Once you change the summary on a trip page, this plugin never touches it again. The address of a page never changes after it is created, so links you have shared keep working.', 'kaiki-booking' ); ?>
								</p>
								<p class="description" style="max-width:44rem">
									<?php echo esc_html__( 'A trip you switch off in Kaiki is unpublished here, and one you delete is moved to the trash. Nothing is removed permanently.', 'kaiki-booking' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__( 'Last update', 'kaiki-booking' ); ?></th>
							<td><?php self::render_sync_status(); ?></td>
						</tr>

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
	 * The «Appearance» section: one site-wide look for every embed.
	 *
	 * Site-wide rather than per shortcode on purpose. An operator has one brand
	 * and one theme; a colour field on every block would be the same answer
	 * typed eight times and, by the ninth, typed differently.
	 *
	 * The rows start in the state the saved settings imply, and the inline
	 * script keeps them in step as the operator changes the choice — so the
	 * screen does not flash every field before hiding most of them.
	 *
	 * @param array<string, mixed> $settings What `Settings::all()` returned.
	 */
	private static function render_appearance( array $settings ): void {
		$name   = Settings::OPTION;
		$custom = 'custom' === $settings['appearance'];

		?>
		<h2 class="title"><?php echo esc_html__( 'Appearance', 'kaiki-booking' ); ?></h2>

		<p class="description" style="max-width:44rem">
			<?php echo esc_html__( 'How the booking form, the calendar and the trip list look on this site. «As in Kaiki» uses the colours and font from your Kaiki panel. Choose your own to match this site instead; anything you leave empty stays as in Kaiki.', 'kaiki-booking' ); ?>
		</p>

		<style>
			#kaiki-appearance-preview { --kaiki-primary: #0b4f4a; --kaiki-on-primary: #ffffff; --kaiki-text: #16211f; --kaiki-background: #ffffff; --kaiki-radius: 8px; --kaiki-font: Inter, system-ui, sans-serif; }
			#kaiki-appearance-preview .kaiki-preview-card { max-width: 20rem; padding: 16px; border: 1px solid #dcdcde; border-radius: var(--kaiki-radius); background: var(--kaiki-background); color: var(--kaiki-text); font-family: var(--kaiki-font); }
			#kaiki-appearance-preview .kaiki-preview-card p { margin: 4px 0 12px; color: inherit; }
			#kaiki-appearance-preview .kaiki-preview-button { padding: 8px 16px; border: 0; border-radius: var(--kaiki-radius); background: var(--kaiki-primary); color: var(--kaiki-on-primary); font: inherit; font-weight: 600; cursor: default; }
		</style>

		<table class="form-table" role="presentation" id="kaiki-appearance">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Style', 'kaiki-booking' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo esc_html__( 'Style', 'kaiki-booking' ); ?></span></legend>
						<label>
							<input type="radio" value="kaiki" name="<?php echo esc_attr( $name ); ?>[appearance]" <?php checked( ! $custom ); ?>>
							<?php echo esc_html__( 'As in Kaiki', 'kaiki-booking' ); ?>
						</label>
						<br>
						<label>
							<input type="radio" value="custom" name="<?php echo esc_attr( $name ); ?>[appearance]" <?php checked( $custom ); ?>>
							<?php echo esc_html__( 'My own', 'kaiki-booking' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>

			<tr class="kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<th scope="row">
					<label for="kaiki_primary"><?php echo esc_html__( 'Button colour', 'kaiki-booking' ); ?></label>
				</th>
				<td>
					<input type="text" class="kaiki-colour" id="kaiki_primary" maxlength="7"
						name="<?php echo esc_attr( $name ); ?>[primary]"
						value="<?php echo esc_attr( (string) $settings['primary'] ); ?>">
					<p class="description">
						<?php echo esc_html__( 'The text on the buttons turns white or black by itself, whichever is easier to read.', 'kaiki-booking' ); ?>
					</p>
				</td>
			</tr>

			<tr class="kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<th scope="row">
					<label for="kaiki_text"><?php echo esc_html__( 'Text colour', 'kaiki-booking' ); ?></label>
				</th>
				<td>
					<input type="text" class="kaiki-colour" id="kaiki_text" maxlength="7"
						name="<?php echo esc_attr( $name ); ?>[text]"
						value="<?php echo esc_attr( (string) $settings['text'] ); ?>">
				</td>
			</tr>

			<tr class="kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<th scope="row">
					<label for="kaiki_background"><?php echo esc_html__( 'Background colour', 'kaiki-booking' ); ?></label>
				</th>
				<td>
					<input type="text" class="kaiki-colour" id="kaiki_background" maxlength="7"
						name="<?php echo esc_attr( $name ); ?>[background]"
						value="<?php echo esc_attr( (string) $settings['background'] ); ?>">
				</td>
			</tr>

			<tr class="kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<th scope="row"><?php echo esc_html__( 'Font', 'kaiki-booking' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo esc_html__( 'Font', 'kaiki-booking' ); ?></span></legend>
						<label>
							<input type="radio" value="theme" name="<?php echo esc_attr( $name ); ?>[font_mode]" <?php checked( $settings['font_mode'], 'theme' ); ?>>
							<?php echo esc_html__( 'My theme\'s font', 'kaiki-booking' ); ?>
						</label>
						<br>
						<label>
							<input type="radio" value="kaiki" name="<?php echo esc_attr( $name ); ?>[font_mode]" <?php checked( $settings['font_mode'], 'kaiki' ); ?>>
							<?php echo esc_html__( 'Kaiki\'s font', 'kaiki-booking' ); ?>
						</label>
						<br>
						<label>
							<input type="radio" value="custom" name="<?php echo esc_attr( $name ); ?>[font_mode]" <?php checked( $settings['font_mode'], 'custom' ); ?>>
							<?php echo esc_html__( 'Another font my theme loads', 'kaiki-booking' ); ?>
						</label>
					</fieldset>

					<div id="kaiki-font-name"<?php self::hidden_unless( 'custom' === $settings['font_mode'] ); ?>>
						<p>
							<label for="kaiki_font_name"><?php echo esc_html__( 'Font name', 'kaiki-booking' ); ?></label>
							<input type="text" class="regular-text" id="kaiki_font_name" maxlength="60"
								name="<?php echo esc_attr( $name ); ?>[font_name]"
								value="<?php echo esc_attr( (string) $settings['font_name'] ); ?>"
								placeholder="Open Sans">
						</p>
						<p class="description">
							<?php echo esc_html__( 'Exactly as your theme names it. Latin letters, digits, spaces and hyphens only. A font your theme does not load will not appear.', 'kaiki-booking' ); ?>
						</p>
					</div>
				</td>
			</tr>

			<tr class="kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<th scope="row">
					<label for="kaiki_radius"><?php echo esc_html__( 'Corner roundness', 'kaiki-booking' ); ?></label>
				</th>
				<td>
					<input type="number" class="small-text" id="kaiki_radius" min="0" max="30" step="1"
						name="<?php echo esc_attr( $name ); ?>[radius]"
						value="<?php echo esc_attr( null === $settings['radius'] ? '' : (string) $settings['radius'] ); ?>">
					px
					<p class="description">
						<?php echo esc_html__( 'From 0 (square) to 30 (very round). Leave it empty to keep Kaiki\'s.', 'kaiki-booking' ); ?>
					</p>
				</td>
			</tr>

			<tr class="kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<th scope="row"><?php echo esc_html__( 'Preview', 'kaiki-booking' ); ?></th>
				<td>
					<div id="kaiki-appearance-preview" aria-hidden="true">
						<div class="kaiki-preview-card">
							<strong><?php echo esc_html__( 'Sunset cruise', 'kaiki-booking' ); ?></strong>
							<p><?php echo esc_html__( 'Three hours along the coast, with a stop for a swim.', 'kaiki-booking' ); ?></p>
							<button type="button" class="kaiki-preview-button" tabindex="-1"><?php echo esc_html__( 'Book now', 'kaiki-booking' ); ?></button>
						</div>
					</div>
					<p class="description">
						<?php echo esc_html__( 'A sketch, not the real form. Anything you leave empty is shown here in Kaiki\'s default colours, and your theme\'s font only appears on your site itself.', 'kaiki-booking' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * A ` hidden` attribute, unless the condition holds.
	 *
	 * @param bool $visible Whether the element should be shown.
	 */
	private static function hidden_unless( bool $visible ): void {
		if ( ! $visible ) {
			echo ' hidden';
		}
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

	/**
	 * When the trip pages last updated, and what happened.
	 *
	 * The one diagnosis most operators will ever need: *it says it ran at four
	 * this morning and wrote nothing*. A log file is not somewhere they will
	 * look, and "it isn't working" with no timestamp is a support conversation
	 * that starts from nothing.
	 */
	private static function render_sync_status(): void {
		$status = get_option( Sync::STATUS_OPTION );

		if ( ! is_array( $status ) || ! isset( $status['at'] ) ) {
			echo '<p class="description">' . esc_html__( 'It has not run yet. It runs by itself every hour, and whenever you change something in Kaiki.', 'kaiki-booking' ) . '</p>';

			return;
		}

		$when = wp_date( 'j M Y, H:i', (int) $status['at'] );

		$error = isset( $status['error'] ) ? (string) $status['error'] : '';

		if ( '' !== $error ) {
			echo '<p><strong>' . esc_html( self::sync_error( $error ) ) . '</strong></p>';
			echo '<p class="description">' . esc_html( (string) $when ) . '</p>';

			return;
		}

		$counts = isset( $status['counts'] ) && is_array( $status['counts'] ) ? $status['counts'] : array();

		$written = (int) ( $counts['created'] ?? 0 ) + (int) ( $counts['updated'] ?? 0 );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: a date and time. 2: a whole number of pages. */
				__( 'Last ran %1$s and updated %2$d page(s).', 'kaiki-booking' ),
				(string) $when,
				$written
			)
		) . '</p>';
	}

	/**
	 * A failure an operator can act on, rather than the word the code uses.
	 *
	 * @param string $error `refused`, `unreachable` or `unexpected`.
	 */
	private static function sync_error( string $error ): string {
		switch ( $error ) {
			case 'refused':
				return __( 'Kaiki refused the secret key. Check it here, or make a new one in your Kaiki panel.', 'kaiki-booking' );
			case 'unreachable':
				return __( 'This site could not reach Kaiki. If it keeps happening, your host may be blocking outgoing connections.', 'kaiki-booking' );
			default:
				return __( 'Kaiki answered in a way this plugin did not understand. It will try again on its own.', 'kaiki-booking' );
		}
	}
}
