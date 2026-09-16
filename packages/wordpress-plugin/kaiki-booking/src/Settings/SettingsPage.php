<?php
/**
 * The one screen an operator fills in.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Settings;

use Kaiki\Booking\Http\Webhook;
use Kaiki\Booking\Seo\Sync;

use const Kaiki\Booking\FILE;
use const Kaiki\Booking\VERSION;

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
 * ## One form, several tabs
 *
 * The tabs are a way of looking at the form, not separate forms. Every field
 * of every tab is inside the one `options.php` form, because the sanitiser
 * rebuilds `kaiki_settings` from what was submitted rather than merging into
 * what was stored: a field left out of the post would be saved as its default.
 * A hidden tab is only `hidden`, and it is still submitted.
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
	 * The last connection check, remembered for a few minutes.
	 */
	private const STATUS_TRANSIENT = 'kaiki_connection_result';

	/**
	 * The tabs, in order. The first is the one a bare URL opens.
	 */
	private const TABS = array( 'connection', 'appearance', 'trip-pages', 'live-updates', 'shortcodes' );

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

		// A new key or a new address makes the last check a statement about
		// something that is no longer configured.
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'forget_connection_status' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'forget_connection_status' ) );
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
	 * The screen's stylesheet and WordPress's own colour picker, on this screen
	 * and no other.
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

		$css = dirname( FILE ) . '/assets/admin.css';

		wp_enqueue_style(
			'kaiki-booking-admin',
			plugins_url( 'assets/admin.css', FILE ),
			array( 'wp-color-picker' ),
			is_readable( $css ) ? (string) filemtime( $css ) : VERSION
		);

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', self::appearance_script() );
		wp_add_inline_script( 'wp-color-picker', self::screen_script() );
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
	 * Tabs, the reveal buttons on the secrets, and the copy buttons.
	 *
	 * The tabs work without it: each is a link to `?tab=…`, and every panel is
	 * still in the form. With it they switch in place, and the form's referer
	 * is pointed at the open tab so that saving comes back to it.
	 */
	private static function screen_script(): string {
		return <<<'JS'
( function ( $ ) {
	'use strict';

	$( function () {
		var root = document.querySelector( '.kaiki-admin' );

		if ( ! root ) {
			return;
		}

		var form = root.querySelector( '.kaiki-admin__form' );
		var panels = root.querySelectorAll( '[data-kaiki-panel]' );
		var savebar = root.querySelector( '.kaiki-admin__savebar' );

		function each( list, callback ) {
			Array.prototype.forEach.call( list, callback );
		}

		function withTab( address, slug ) {
			var url = new URL( address, window.location.href );

			url.searchParams.set( 'tab', slug );
			url.searchParams.delete( 'settings-updated' );

			return url;
		}

		function show( slug ) {
			var found = false;

			each( panels, function ( panel ) {
				var active = panel.getAttribute( 'data-kaiki-panel' ) === slug;

				panel.hidden = ! active;
				found = found || active;
			} );

			if ( ! found ) {
				return;
			}

			each( root.querySelectorAll( '.kaiki-admin__tab' ), function ( tab ) {
				var active = tab.getAttribute( 'data-kaiki-tab' ) === slug;

				tab.classList.toggle( 'is-active', active );

				if ( active ) {
					tab.setAttribute( 'aria-current', 'page' );
				} else {
					tab.removeAttribute( 'aria-current' );
				}
			} );

			if ( savebar ) {
				savebar.hidden = slug === 'shortcodes';
			}

			try {
				window.history.replaceState( null, '', withTab( window.location.href, slug ) );

				var referer = form && form.querySelector( 'input[name="_wp_http_referer"]' );

				if ( referer ) {
					var back = withTab( referer.value, slug );

					referer.value = back.pathname + back.search;
				}
			} catch ( error ) {
				// An old browser keeps the tab until the page reloads, which is all.
			}
		}

		function copy( text ) {
			if ( navigator.clipboard && window.isSecureContext ) {
				return navigator.clipboard.writeText( text );
			}

			return new Promise( function ( resolve, reject ) {
				var area = document.createElement( 'textarea' );

				area.value = text;
				area.setAttribute( 'readonly', '' );
				area.style.position = 'fixed';
				area.style.opacity = '0';
				document.body.appendChild( area );
				area.select();

				try {
					document.execCommand( 'copy' ) ? resolve() : reject();
				} catch ( error ) {
					reject( error );
				}

				document.body.removeChild( area );
			} );
		}

		root.addEventListener( 'click', function ( event ) {
			var reveal = event.target.closest( '[data-kaiki-reveal]' );
			var copier = event.target.closest( '[data-kaiki-copy]' );
			var tab = event.target.closest( '[data-kaiki-tab]' );

			if ( reveal ) {
				var input = document.getElementById( reveal.getAttribute( 'data-kaiki-reveal' ) );

				if ( ! input ) {
					return;
				}

				var showing = input.type === 'password';
				var icon = reveal.querySelector( '.dashicons' );

				input.type = showing ? 'text' : 'password';
				reveal.setAttribute( 'aria-pressed', showing ? 'true' : 'false' );
				reveal.querySelector( '.kaiki-admin__reveal-label' ).textContent = reveal.getAttribute( showing ? 'data-hide' : 'data-show' );

				if ( icon ) {
					icon.classList.toggle( 'dashicons-visibility', ! showing );
					icon.classList.toggle( 'dashicons-hidden', showing );
				}

				return;
			}

			if ( copier ) {
				event.preventDefault();

				copy( copier.getAttribute( 'data-kaiki-copy' ) ).then( function () {
					var label = copier.querySelector( '.kaiki-admin__copy-label' );
					var status = root.querySelector( '.kaiki-admin__announce' );
					var done = copier.getAttribute( 'data-copied' );

					copier.classList.add( 'is-copied' );

					if ( label ) {
						label.textContent = done;
					}

					if ( status ) {
						status.textContent = done;
					}

					window.setTimeout( function () {
						copier.classList.remove( 'is-copied' );

						if ( label ) {
							label.textContent = copier.getAttribute( 'data-copy' );
						}

						if ( status ) {
							status.textContent = '';
						}
					}, 2000 );
				} ).catch( function () {} );

				return;
			}

			if ( tab ) {
				event.preventDefault();
				show( tab.getAttribute( 'data-kaiki-tab' ) );

				if ( tab.classList.contains( 'kaiki-admin__pill' ) ) {
					root.querySelector( '.kaiki-admin__tabs' ).scrollIntoView( { block: 'nearest' } );
				}
			}
		} );

		// A field the browser refuses on a tab nobody is looking at would stop
		// the save with a message pointing at nothing. Open its tab first.
		if ( form ) {
			form.addEventListener( 'invalid', function ( event ) {
				var panel = event.target.closest( '[data-kaiki-panel]' );

				if ( panel && panel.hidden ) {
					show( panel.getAttribute( 'data-kaiki-panel' ) );
				}
			}, true );
		}
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
			// A checkbox, so absent from the form means unticked. Only an option
			// saved before the setting existed reads absent as on, and that is
			// `Settings::all()`'s job, not this one's.
			'show_vessel'     => ! empty( $input['show_vessel'] ),
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
	 * The tab names, in order.
	 *
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return array_combine(
			self::TABS,
			array(
				__( 'Connection', 'kaiki-booking' ),
				__( 'Appearance', 'kaiki-booking' ),
				__( 'Trip pages', 'kaiki-booking' ),
				__( 'Live updates', 'kaiki-booking' ),
				__( 'Shortcodes', 'kaiki-booking' ),
			)
		);
	}

	/**
	 * The tab the URL asks for, or the first.
	 */
	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses which tab is open; nothing is changed.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $tab, self::TABS, true ) ? $tab : self::TABS[0];
	}

	/**
	 * The screen itself.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'kaiki-booking' ) );
		}

		$settings = Settings::all();
		$status   = self::connection_status();
		$current  = self::current_tab();

		?>
		<div class="wrap kaiki-admin">
			<header class="kaiki-admin__header">
				<div class="kaiki-admin__heading">
					<h1 class="kaiki-admin__title"><?php echo esc_html__( 'Kaiki Booking', 'kaiki-booking' ); ?></h1>
					<p class="kaiki-admin__lead">
						<?php echo esc_html__( 'Connect this site to your Kaiki account, then put a booking form on any page with a shortcode, a block or an Elementor widget.', 'kaiki-booking' ); ?>
					</p>
				</div>

				<a class="kaiki-admin__pill <?php echo esc_attr( $status['ok'] ? 'is-ok' : 'is-off' ); ?>"
					href="<?php echo esc_url( self::tab_url( 'connection' ) ); ?>" data-kaiki-tab="connection">
					<span class="kaiki-admin__dot" aria-hidden="true"></span>
					<?php echo esc_html( $status['ok'] ? __( 'Connected', 'kaiki-booking' ) : __( 'Not connected', 'kaiki-booking' ) ); ?>
				</a>
			</header>

			<hr class="wp-header-end">

			<?php settings_errors( Settings::OPTION ); ?>

			<span class="screen-reader-text kaiki-admin__announce" role="status" aria-live="polite"></span>

			<?php // The connection test posts elsewhere, so its button (inside the settings form) points here with `form=`. ?>
			<form id="kaiki-test-connection" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kaiki_test_connection">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'kaiki_test_connection' ) ); ?>">
			</form>

			<nav class="kaiki-admin__tabs" aria-label="<?php echo esc_attr__( 'Settings sections', 'kaiki-booking' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a class="kaiki-admin__tab<?php echo $slug === $current ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"
						data-kaiki-tab="<?php echo esc_attr( $slug ); ?>"
						<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="kaiki-admin__form">
				<?php settings_fields( self::GROUP ); ?>

				<section class="kaiki-admin__panel" data-kaiki-panel="connection"<?php self::hidden_unless( 'connection' === $current ); ?>>
					<?php self::render_connection( $settings, $status ); ?>
				</section>

				<section class="kaiki-admin__panel" data-kaiki-panel="appearance"<?php self::hidden_unless( 'appearance' === $current ); ?>>
					<?php self::render_appearance( $settings ); ?>
				</section>

				<section class="kaiki-admin__panel" data-kaiki-panel="trip-pages"<?php self::hidden_unless( 'trip-pages' === $current ); ?>>
					<?php self::render_trip_pages( $settings ); ?>
				</section>

				<section class="kaiki-admin__panel" data-kaiki-panel="live-updates"<?php self::hidden_unless( 'live-updates' === $current ); ?>>
					<?php self::render_live_updates( $settings ); ?>
				</section>

				<section class="kaiki-admin__panel" data-kaiki-panel="shortcodes"<?php self::hidden_unless( 'shortcodes' === $current ); ?>>
					<?php self::render_shortcodes(); ?>
				</section>

				<div class="kaiki-admin__savebar"<?php self::hidden_unless( 'shortcodes' !== $current ); ?>>
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * This screen, on one tab.
	 *
	 * @param string $slug One of {@see self::TABS}.
	 */
	private static function tab_url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $slug,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * «Connection»: whether it works, the two fields that make it work, and the
	 * language.
	 *
	 * @param array<string, mixed> $settings What `Settings::all()` returned.
	 * @param array<string, mixed> $status   The last check, as {@see self::connection_status()} returned it.
	 */
	private static function render_connection( array $settings, array $status ): void {
		$name  = Settings::OPTION;
		$state = $status['ok'] ? 'is-ok' : ( Settings::is_configured() ? 'is-error' : 'is-off' );

		?>
		<div class="kaiki-admin__card kaiki-admin__card--connection">
			<div class="kaiki-admin__status <?php echo esc_attr( $state ); ?>">
				<span class="kaiki-admin__status-icon dashicons <?php echo esc_attr( $status['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>

				<div class="kaiki-admin__status-text">
					<h2 class="kaiki-admin__status-title">
						<?php echo esc_html( $status['ok'] ? __( 'Connected', 'kaiki-booking' ) : __( 'Not connected', 'kaiki-booking' ) ); ?>
					</h2>
					<p class="kaiki-admin__status-message"><?php echo esc_html( $status['message'] ); ?></p>

					<?php if ( $status['at'] > 0 ) : ?>
						<p class="kaiki-admin__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: a date and time. */
									__( 'Checked %s', 'kaiki-booking' ),
									(string) wp_date( 'j M Y, H:i', $status['at'] )
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( $status['ok'] && null !== $status['trips'] ) : ?>
					<div class="kaiki-admin__stat">
						<span class="kaiki-admin__stat-value"><?php echo esc_html( $status['trips'] . ( $status['more'] ? '+' : '' ) ); ?></span>
						<span class="kaiki-admin__stat-label"><?php echo esc_html__( 'Trips visible', 'kaiki-booking' ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( Settings::is_configured() ) : ?>
					<button type="submit" form="kaiki-test-connection" class="button kaiki-admin__status-action">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php echo esc_html( $status['at'] > 0 ? __( 'Check again', 'kaiki-booking' ) : __( 'Test the connection', 'kaiki-booking' ) ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div class="kaiki-admin__card-body kaiki-admin__grid">
				<div class="kaiki-admin__field">
					<label class="kaiki-admin__label" for="kaiki_publishable_key"><?php echo esc_html__( 'Publishable key', 'kaiki-booking' ); ?></label>
					<input type="text" class="kaiki-admin__input code" id="kaiki_publishable_key" spellcheck="false"
						name="<?php echo esc_attr( $name ); ?>[publishable_key]"
						value="<?php echo esc_attr( $settings['publishable_key'] ); ?>"
						placeholder="pk_live_…">
					<p class="kaiki-admin__help">
						<?php echo esc_html__( 'From your Kaiki panel, under API keys. This one is safe on a public page: it can read your catalogue and start a booking, and nothing else.', 'kaiki-booking' ); ?>
					</p>
				</div>

				<div class="kaiki-admin__field">
					<label class="kaiki-admin__label" for="kaiki_api_base"><?php echo esc_html__( 'Kaiki address', 'kaiki-booking' ); ?></label>
					<input type="url" class="kaiki-admin__input code" id="kaiki_api_base" spellcheck="false"
						name="<?php echo esc_attr( $name ); ?>[api_base]"
						value="<?php echo esc_attr( $settings['api_base'] ); ?>"
						placeholder="https://book.kaiki.gr">
					<p class="kaiki-admin__help">
						<?php echo esc_html__( 'Leave this as it is unless you were told otherwise.', 'kaiki-booking' ); ?>
					</p>
				</div>
			</div>

			<p class="kaiki-admin__card-foot">
				<?php echo esc_html__( 'The check uses the saved key. Save your changes first.', 'kaiki-booking' ); ?>
			</p>
		</div>

		<div class="kaiki-admin__card">
			<div class="kaiki-admin__card-head">
				<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Language', 'kaiki-booking' ); ?></h2>
			</div>

			<div class="kaiki-admin__field">
				<label class="screen-reader-text" for="kaiki_locale_mode"><?php echo esc_html__( 'Language', 'kaiki-booking' ); ?></label>
				<select class="kaiki-admin__select" id="kaiki_locale_mode" name="<?php echo esc_attr( $name ); ?>[locale_mode]">
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
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'Following the page uses WPML or Polylang if you have one, and your site language if you do not.', 'kaiki-booking' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * «Appearance»: one site-wide look for every embed.
	 *
	 * Site-wide rather than per shortcode on purpose. An operator has one brand
	 * and one theme; a colour field on every block would be the same answer
	 * typed eight times and, by the ninth, typed differently.
	 *
	 * The fields start in the state the saved settings imply, and the inline
	 * script keeps them in step as the operator changes the choice — so the
	 * screen does not flash every field before hiding most of them.
	 *
	 * @param array<string, mixed> $settings What `Settings::all()` returned.
	 */
	private static function render_appearance( array $settings ): void {
		$name   = Settings::OPTION;
		$custom = 'custom' === $settings['appearance'];

		?>
		<div class="kaiki-admin__card" id="kaiki-appearance">
			<div class="kaiki-admin__card-head">
				<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Appearance', 'kaiki-booking' ); ?></h2>
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'How the booking form and the trip list look on this site. «As in Kaiki» uses the colours and font from your Kaiki panel. Choose your own to match this site instead; anything you leave empty stays as in Kaiki.', 'kaiki-booking' ); ?>
				</p>
			</div>

			<fieldset class="kaiki-admin__field">
				<legend class="kaiki-admin__label"><?php echo esc_html__( 'Style', 'kaiki-booking' ); ?></legend>
				<div class="kaiki-admin__choices">
					<label class="kaiki-admin__choice">
						<input type="radio" value="kaiki" name="<?php echo esc_attr( $name ); ?>[appearance]" <?php checked( ! $custom ); ?>>
						<span><?php echo esc_html__( 'As in Kaiki', 'kaiki-booking' ); ?></span>
					</label>
					<label class="kaiki-admin__choice">
						<input type="radio" value="custom" name="<?php echo esc_attr( $name ); ?>[appearance]" <?php checked( $custom ); ?>>
						<span><?php echo esc_html__( 'My own', 'kaiki-booking' ); ?></span>
					</label>
				</div>
			</fieldset>

			<?php // Not a «My own» field: it applies under either choice, so it is never hidden. ?>
			<div class="kaiki-admin__field">
				<span class="kaiki-admin__label"><?php echo esc_html__( 'Boat', 'kaiki-booking' ); ?></span>
				<label class="kaiki-admin__check">
					<input type="checkbox" value="1"
						name="<?php echo esc_attr( $name ); ?>[show_vessel]"
						<?php checked( (bool) $settings['show_vessel'] ); ?>>
					<?php echo esc_html__( 'Show the boat\'s name', 'kaiki-booking' ); ?>
				</label>
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'Hide it if the same boat does every trip, or if you would rather not name it.', 'kaiki-booking' ); ?>
				</p>
			</div>

			<div class="kaiki-admin__custom kaiki-appearance-custom"<?php self::hidden_unless( $custom ); ?>>
				<div class="kaiki-admin__split">
					<div class="kaiki-admin__split-main">
						<div class="kaiki-admin__colours">
							<div class="kaiki-admin__field">
								<label class="kaiki-admin__label" for="kaiki_primary"><?php echo esc_html__( 'Button colour', 'kaiki-booking' ); ?></label>
								<input type="text" class="kaiki-colour" id="kaiki_primary" maxlength="7"
									name="<?php echo esc_attr( $name ); ?>[primary]"
									value="<?php echo esc_attr( (string) $settings['primary'] ); ?>">
							</div>

							<div class="kaiki-admin__field">
								<label class="kaiki-admin__label" for="kaiki_text"><?php echo esc_html__( 'Text colour', 'kaiki-booking' ); ?></label>
								<input type="text" class="kaiki-colour" id="kaiki_text" maxlength="7"
									name="<?php echo esc_attr( $name ); ?>[text]"
									value="<?php echo esc_attr( (string) $settings['text'] ); ?>">
							</div>

							<div class="kaiki-admin__field">
								<label class="kaiki-admin__label" for="kaiki_background"><?php echo esc_html__( 'Background colour', 'kaiki-booking' ); ?></label>
								<input type="text" class="kaiki-colour" id="kaiki_background" maxlength="7"
									name="<?php echo esc_attr( $name ); ?>[background]"
									value="<?php echo esc_attr( (string) $settings['background'] ); ?>">
							</div>
						</div>

						<p class="kaiki-admin__help">
							<?php echo esc_html__( 'The text on the buttons turns white or black by itself, whichever is easier to read.', 'kaiki-booking' ); ?>
						</p>

						<fieldset class="kaiki-admin__field">
							<legend class="kaiki-admin__label"><?php echo esc_html__( 'Font', 'kaiki-booking' ); ?></legend>
							<div class="kaiki-admin__choices">
								<label class="kaiki-admin__choice">
									<input type="radio" value="theme" name="<?php echo esc_attr( $name ); ?>[font_mode]" <?php checked( $settings['font_mode'], 'theme' ); ?>>
									<span><?php echo esc_html__( 'My theme\'s font', 'kaiki-booking' ); ?></span>
								</label>
								<label class="kaiki-admin__choice">
									<input type="radio" value="kaiki" name="<?php echo esc_attr( $name ); ?>[font_mode]" <?php checked( $settings['font_mode'], 'kaiki' ); ?>>
									<span><?php echo esc_html__( 'Kaiki\'s font', 'kaiki-booking' ); ?></span>
								</label>
								<label class="kaiki-admin__choice">
									<input type="radio" value="custom" name="<?php echo esc_attr( $name ); ?>[font_mode]" <?php checked( $settings['font_mode'], 'custom' ); ?>>
									<span><?php echo esc_html__( 'Another font my theme loads', 'kaiki-booking' ); ?></span>
								</label>
							</div>

							<div class="kaiki-admin__subfield" id="kaiki-font-name"<?php self::hidden_unless( 'custom' === $settings['font_mode'] ); ?>>
								<label class="kaiki-admin__label" for="kaiki_font_name"><?php echo esc_html__( 'Font name', 'kaiki-booking' ); ?></label>
								<input type="text" class="kaiki-admin__input" id="kaiki_font_name" maxlength="60"
									name="<?php echo esc_attr( $name ); ?>[font_name]"
									value="<?php echo esc_attr( (string) $settings['font_name'] ); ?>"
									placeholder="Open Sans">
								<p class="kaiki-admin__help">
									<?php echo esc_html__( 'Exactly as your theme names it. Latin letters, digits, spaces and hyphens only. A font your theme does not load will not appear.', 'kaiki-booking' ); ?>
								</p>
							</div>
						</fieldset>

						<div class="kaiki-admin__field">
							<label class="kaiki-admin__label" for="kaiki_radius"><?php echo esc_html__( 'Corner roundness', 'kaiki-booking' ); ?></label>
							<div class="kaiki-admin__affix">
								<input type="number" class="kaiki-admin__input kaiki-admin__input--short" id="kaiki_radius" min="0" max="30" step="1"
									name="<?php echo esc_attr( $name ); ?>[radius]"
									value="<?php echo esc_attr( null === $settings['radius'] ? '' : (string) $settings['radius'] ); ?>">
								<span class="kaiki-admin__affix-text">px</span>
							</div>
							<p class="kaiki-admin__help">
								<?php echo esc_html__( 'From 0 (square) to 30 (very round). Leave it empty to keep Kaiki\'s.', 'kaiki-booking' ); ?>
							</p>
						</div>
					</div>

					<div class="kaiki-admin__split-aside">
						<span class="kaiki-admin__label"><?php echo esc_html__( 'Preview', 'kaiki-booking' ); ?></span>
						<div id="kaiki-appearance-preview" class="kaiki-admin__preview" aria-hidden="true">
							<div class="kaiki-preview-card">
								<strong><?php echo esc_html__( 'Sunset cruise', 'kaiki-booking' ); ?></strong>
								<p><?php echo esc_html__( 'Three hours along the coast, with a stop for a swim.', 'kaiki-booking' ); ?></p>
								<button type="button" class="kaiki-preview-button" tabindex="-1"><?php echo esc_html__( 'Book now', 'kaiki-booking' ); ?></button>
							</div>
						</div>
						<p class="kaiki-admin__help">
							<?php echo esc_html__( 'A sketch, not the real form. Anything you leave empty is shown here in Kaiki\'s default colours, and your theme\'s font only appears on your site itself.', 'kaiki-booking' ); ?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * «Trip pages»: the SEO sync, and the secret key it needs.
	 *
	 * @param array<string, mixed> $settings What `Settings::all()` returned.
	 */
	private static function render_trip_pages( array $settings ): void {
		$secret = get_option( Settings::SECRET_OPTION, '' );

		?>
		<div class="kaiki-admin__card">
			<div class="kaiki-admin__card-head">
				<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Trip pages for search engines', 'kaiki-booking' ); ?></h2>
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'Off by default, and the plugin is complete without it. Switched on, each of your trips also becomes a page on this site, so Google finds it here as well as on Kaiki.', 'kaiki-booking' ); ?>
				</p>
			</div>

			<div class="kaiki-admin__field">
				<span class="kaiki-admin__label"><?php echo esc_html__( 'Trip pages', 'kaiki-booking' ); ?></span>
				<label class="kaiki-admin__switch">
					<input type="checkbox" value="1"
						name="<?php echo esc_attr( Settings::OPTION ); ?>[seo_pages]"
						<?php checked( $settings['seo_pages'] ); ?>>
					<span class="kaiki-admin__switch-track" aria-hidden="true"></span>
					<span><?php echo esc_html__( 'Create a page on this site for each trip', 'kaiki-booking' ); ?></span>
				</label>

				<?php if ( ! $settings['seo_pages'] ) : ?>
					<p class="kaiki-admin__help">
						<?php echo esc_html__( 'Switch it on and save to choose the address of the trip pages and add the secret key.', 'kaiki-booking' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $settings['seo_pages'] ) : ?>
				<div class="kaiki-admin__field">
					<label class="kaiki-admin__label" for="kaiki_trip_base"><?php echo esc_html__( 'Address for trip pages', 'kaiki-booking' ); ?></label>
					<div class="kaiki-admin__affix">
						<span class="kaiki-admin__affix-text code"><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/</span>
						<input type="text" class="kaiki-admin__input kaiki-admin__input--base code" id="kaiki_trip_base" spellcheck="false"
							name="<?php echo esc_attr( Settings::OPTION ); ?>[trip_base]"
							value="<?php echo esc_attr( $settings['trip_base'] ); ?>">
						<span class="kaiki-admin__affix-text code">/&hellip;</span>
					</div>
					<p class="kaiki-admin__help">
						<?php echo esc_html__( 'Change this only if something on your site already uses that word — two things cannot share one address.', 'kaiki-booking' ); ?>
					</p>
				</div>

				<div class="kaiki-admin__field">
					<span class="kaiki-admin__label"><?php echo esc_html__( 'Last update', 'kaiki-booking' ); ?></span>
					<div class="kaiki-admin__sync"><?php self::render_sync_status(); ?></div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $settings['seo_pages'] ) : ?>
			<div class="kaiki-admin__card">
				<div class="kaiki-admin__card-head">
					<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Secret key', 'kaiki-booking' ); ?></h2>
				</div>

				<div class="kaiki-admin__field">
					<label class="screen-reader-text" for="kaiki_secret_key"><?php echo esc_html__( 'Secret key', 'kaiki-booking' ); ?></label>
					<?php self::secret_input( 'kaiki_secret_key', Settings::SECRET_OPTION, is_string( $secret ) ? $secret : '', 'sk_live_…' ); ?>
				</div>

				<div class="kaiki-admin__notice is-info">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<p>
						<strong><?php echo esc_html__( 'Only the trip pages need this, and only on this screen.', 'kaiki-booking' ); ?></strong>
						<?php echo esc_html__( 'It is used by this site\'s own scheduled task to read your full catalogue, including trips you have not published. It is never sent to a visitor.', 'kaiki-booking' ); ?>
					</p>
				</div>

				<div class="kaiki-admin__notice is-warning">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<p>
						<strong><?php echo esc_html__( 'Never paste this key into a page, a post, a widget or a theme file.', 'kaiki-booking' ); ?></strong>
						<?php echo esc_html__( 'Anything on a page is public. If it ever appears on one, delete the key in your Kaiki panel and make a new one.', 'kaiki-booking' ); ?>
					</p>
				</div>
			</div>

			<div class="kaiki-admin__card">
				<div class="kaiki-admin__card-head">
					<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Who owns the text', 'kaiki-booking' ); ?></h2>
				</div>

				<ul class="kaiki-admin__points">
					<li>
						<strong><?php echo esc_html__( 'Kaiki owns the title and the body of a trip page.', 'kaiki-booking' ); ?></strong>
						<?php echo esc_html__( 'They are rewritten every time you change the trip in Kaiki, so editing them here will not last. Edit the trip in Kaiki instead.', 'kaiki-booking' ); ?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'You own the short summary, the featured image and the address.', 'kaiki-booking' ); ?></strong>
						<?php echo esc_html__( 'Once you change the summary on a trip page, this plugin never touches it again. The address of a page never changes after it is created, so links you have shared keep working.', 'kaiki-booking' ); ?>
					</li>
					<li>
						<?php echo esc_html__( 'A trip you switch off in Kaiki is unpublished here, and one you delete is moved to the trash. Nothing is removed permanently.', 'kaiki-booking' ); ?>
					</li>
				</ul>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * «Live updates»: the webhook, and the cache it makes safe to lengthen.
	 *
	 * @param array<string, mixed> $settings What `Settings::all()` returned.
	 */
	private static function render_live_updates( array $settings ): void {
		$webhook_secret = get_option( Settings::WEBHOOK_SECRET_OPTION, '' );
		$webhook_url    = rest_url( Webhook::NAMESPACE . Webhook::ROUTE );

		?>
		<div class="kaiki-admin__card">
			<div class="kaiki-admin__card-head">
				<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Live updates', 'kaiki-booking' ); ?></h2>
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'Kaiki tells this site the moment a trip changes, so visitors never see an out-of-date catalogue. In your Kaiki panel, open Settings → Webhooks, add a webhook with the address below, tick the three trip events, and paste the secret it shows you here.', 'kaiki-booking' ); ?>
				</p>
			</div>

			<div class="kaiki-admin__field">
				<label class="kaiki-admin__label" for="kaiki_webhook_url"><?php echo esc_html__( 'Address to give Kaiki', 'kaiki-booking' ); ?></label>
				<div class="kaiki-admin__secret">
					<input type="text" class="kaiki-admin__input code" id="kaiki_webhook_url" readonly
						value="<?php echo esc_attr( $webhook_url ); ?>">
					<?php self::copy_button( $webhook_url ); ?>
				</div>
			</div>

			<div class="kaiki-admin__field">
				<label class="kaiki-admin__label" for="kaiki_webhook_secret"><?php echo esc_html__( 'Update secret', 'kaiki-booking' ); ?></label>
				<?php self::secret_input( 'kaiki_webhook_secret', Settings::WEBHOOK_SECRET_OPTION, is_string( $webhook_secret ) ? $webhook_secret : '' ); ?>
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'Without it, your trips are refreshed on the cache timer below instead.', 'kaiki-booking' ); ?>
				</p>
			</div>
		</div>

		<div class="kaiki-admin__card">
			<div class="kaiki-admin__card-head">
				<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Cache', 'kaiki-booking' ); ?></h2>
			</div>

			<div class="kaiki-admin__field">
				<label class="kaiki-admin__label" for="kaiki_cache_ttl"><?php echo esc_html__( 'Cache, in seconds', 'kaiki-booking' ); ?></label>
				<input type="number" class="kaiki-admin__input kaiki-admin__input--short" id="kaiki_cache_ttl" min="60" max="86400" step="60"
					name="<?php echo esc_attr( Settings::OPTION ); ?>[cache_ttl]"
					value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>">
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'How long your trips are remembered on this site. Changes you make in Kaiki appear immediately anyway — Kaiki tells this site when something changed.', 'kaiki-booking' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * «Shortcodes»: every one the plugin has, with something to copy.
	 *
	 * A reference rather than settings, so it has no fields. The list is
	 * written out here rather than read from the classes that register the
	 * shortcodes, because what an operator needs is the one-line description
	 * and those classes have docblocks, not sentences in Greek.
	 */
	private static function render_shortcodes(): void {
		?>
		<div class="kaiki-admin__card">
			<div class="kaiki-admin__card-head">
				<h2 class="kaiki-admin__card-title"><?php echo esc_html__( 'Shortcodes', 'kaiki-booking' ); ?></h2>
				<p class="kaiki-admin__help">
					<?php echo esc_html__( 'Paste any of these into a page, a post or a text widget. Click an example to copy it.', 'kaiki-booking' ); ?>
				</p>
			</div>

			<div class="kaiki-admin__notice is-info">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				<p>
					<?php echo esc_html__( 'On a trip page, or in a template for trip pages, leave product empty: the shortcode shows the trip being viewed. Anywhere else, put the trip id from your Kaiki panel in it.', 'kaiki-booking' ); ?>
				</p>
			</div>

			<?php foreach ( self::shortcode_groups() as $group ) : ?>
				<h3 class="kaiki-admin__group-title"><?php echo esc_html( $group['title'] ); ?></h3>

				<ul class="kaiki-admin__shortcodes">
					<?php foreach ( $group['items'] as $tag => $item ) : ?>
						<li class="kaiki-admin__shortcode">
							<div class="kaiki-admin__shortcode-head">
								<code class="kaiki-admin__shortcode-tag">[<?php echo esc_html( $tag ); ?>]</code>
								<span class="kaiki-admin__shortcode-text"><?php echo esc_html( $item['text'] ); ?></span>
							</div>

							<?php if ( $item['atts'] ) : ?>
								<dl class="kaiki-admin__atts">
									<?php foreach ( $item['atts'] as $att => $about ) : ?>
										<dt><code><?php echo esc_html( $att ); ?></code></dt>
										<dd><?php echo esc_html( $about ); ?></dd>
									<?php endforeach; ?>
								</dl>
							<?php endif; ?>

							<?php self::copy_button( $item['example'], true ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The shortcodes, in two groups.
	 *
	 * @return list<array{title: string, items: array<string, array{text: string, atts: array<string, string>, example: string}>}>
	 */
	private static function shortcode_groups(): array {
		$product = __( 'The trip id. Leave it empty on a trip page.', 'kaiki-booking' );
		$summary = __( 'yes or no: show the short summary.', 'kaiki-booking' );

		return array(
			array(
				'title' => __( 'Booking and lists', 'kaiki-booking' ),
				'items' => array(
					'kaiki_booking' => array(
						'text'    => __( 'The booking form for one trip.', 'kaiki-booking' ),
						'atts'    => array( 'product' => $product ),
						'example' => '[kaiki_booking product=""]',
					),
					'kaiki_list'    => array(
						'text'    => __( 'Your trips as a grid. Each card opens the booking on Kaiki.', 'kaiki-booking' ),
						'atts'    => array( 'category' => __( 'Only trips in this category. Leave it empty for all of them.', 'kaiki-booking' ) ),
						'example' => '[kaiki_list category=""]',
					),
					'kaiki_enquiry' => array(
						'text'    => __( 'An enquiry form, for a trip with no published price or a general question.', 'kaiki-booking' ),
						'atts'    => array( 'product' => __( 'Optional. The trip the enquiry is about.', 'kaiki-booking' ) ),
						'example' => '[kaiki_enquiry product=""]',
					),
				),
			),
			array(
				'title' => __( 'Pieces of a trip page', 'kaiki-booking' ),
				'items' => array(
					'kaiki_trip_title'         => array(
						'text'    => __( 'The trip\'s title, with its summary and a link back if you want one.', 'kaiki-booking' ),
						'atts'    => array(
							'product'   => $product,
							'tag'       => __( 'h1, h2, h3, h4, p or div.', 'kaiki-booking' ),
							'summary'   => $summary,
							'back'      => __( 'yes or no: show a link back.', 'kaiki-booking' ),
							'back_text' => __( 'The text of the link back.', 'kaiki-booking' ),
							'back_url'  => __( 'Where the link back goes. Your home page if empty.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_title tag="h1" back="yes"]',
					),
					'kaiki_trip_facts'         => array(
						'text'    => __( 'Short facts with icons: duration, departure, meeting point, boat, capacity.', 'kaiki-booking' ),
						'atts'    => array(
							'product' => $product,
							'show'    => __( 'Which facts, in order, separated by commas: category, duration, departure, meeting_point, vessel, capacity.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_facts show="duration,departure,meeting_point"]',
					),
					'kaiki_trip_gallery'       => array(
						'text'    => __( 'The trip\'s photos.', 'kaiki-booking' ),
						'atts'    => array(
							'product' => $product,
							'layout'  => __( 'mosaic, grid or single.', 'kaiki-booking' ),
							'max'     => __( 'The most photos to show.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_gallery layout="mosaic" max="5"]',
					),
					'kaiki_trip_description'   => array(
						'text'    => __( 'The trip\'s description from Kaiki.', 'kaiki-booking' ),
						'atts'    => array( 'product' => $product ),
						'example' => '[kaiki_trip_description]',
					),
					'kaiki_trip_price'         => array(
						'text'    => __( 'The starting price.', 'kaiki-booking' ),
						'atts'    => array(
							'product' => $product,
							'prefix'  => __( 'The word before the price.', 'kaiki-booking' ),
							'unit'    => __( 'yes or no: show per person or per boat.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_price unit="yes"]',
					),
					'kaiki_trip_list'          => array(
						'text'    => __( 'A list: what is included, what is not, what to bring, or the highlights.', 'kaiki-booking' ),
						'atts'    => array(
							'product' => $product,
							'source'  => __( 'includes, excludes, bring or highlights.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_list source="includes"]',
					),
					'kaiki_trip_itinerary'     => array(
						'text'    => __( 'The day, stop by stop.', 'kaiki-booking' ),
						'atts'    => array( 'product' => $product ),
						'example' => '[kaiki_trip_itinerary]',
					),
					'kaiki_trip_meeting_point' => array(
						'text'    => __( 'Where guests meet, with directions and a map link.', 'kaiki-booking' ),
						'atts'    => array(
							'product'      => $product,
							'instructions' => __( 'yes or no: show the directions.', 'kaiki-booking' ),
							'map'          => __( 'yes or no: show the map link.', 'kaiki-booking' ),
							'map_text'     => __( 'The text of the map link.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_meeting_point map="yes"]',
					),
					'kaiki_trip_cancellation'  => array(
						'text'    => __( 'The cancellation policy.', 'kaiki-booking' ),
						'atts'    => array(
							'product' => $product,
							'tiers'   => __( 'yes or no: show the refund for each period.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_cancellation tiers="yes"]',
					),
					'kaiki_trips'              => array(
						'text'    => __( 'Trip cards that open each trip\'s page on this site.', 'kaiki-booking' ),
						'atts'    => array(
							'limit'           => __( 'How many cards. 0 for all.', 'kaiki-booking' ),
							'category'        => __( 'Only these categories, separated by commas.', 'kaiki-booking' ),
							'exclude_current' => __( 'yes: leave out the trip being viewed.', 'kaiki-booking' ),
							'cta'             => __( 'The text on each card\'s button.', 'kaiki-booking' ),
							'summary'         => $summary,
							'facts'           => __( 'yes or no: show the facts.', 'kaiki-booking' ),
							'layout'          => __( 'cards, horizontal, overlay or minimal.', 'kaiki-booking' ),
							'photo'           => __( 'yes or no: show the photo.', 'kaiki-booking' ),
							'badge'           => __( 'yes or no: show the label on the photo.', 'kaiki-booking' ),
							'price'           => __( 'yes or no: show the price.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trips limit="3" exclude_current="yes" layout="cards"]',
					),
					'kaiki_fleet'              => array(
						'text'    => __( 'Every boat that runs a published trip, with its numbers, amenities and trips.', 'kaiki-booking' ),
						'atts'    => array(
							'layout'    => __( 'rows, rows-left, grid, overlay or compact.', 'kaiki-booking' ),
							'photos'    => __( 'yes or no: show the photo.', 'kaiki-booking' ),
							'specs'     => __( 'yes or no: show the numbers.', 'kaiki-booking' ),
							'amenities' => __( 'yes or no: show the amenities.', 'kaiki-booking' ),
							'trips'     => __( 'yes or no: show the trips it runs.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_fleet layout="rows"]',
					),
					'kaiki_trip_field'         => array(
						'text'    => __( 'Any detail Kaiki has about the trip, by its path.', 'kaiki-booking' ),
						'atts'    => array(
							'product' => $product,
							'key'     => __( 'The path, e.g. vessel.name or meeting_point.instructions.', 'kaiki-booking' ),
							'format'  => __( 'text, list, html, url or image.', 'kaiki-booking' ),
							'before'  => __( 'Text before the value.', 'kaiki-booking' ),
							'after'   => __( 'Text after the value.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_trip_field key="vessel.name"]',
					),
					'kaiki_search'             => array(
						'text'    => __( 'The search bar: date, guests, and type or port when switched on in Kaiki.', 'kaiki-booking' ),
						'atts'    => array(
							'results' => __( 'The address of the results page. Empty: the same page.', 'kaiki-booking' ),
							'layout'  => __( 'inline or stacked.', 'kaiki-booking' ),
							'button'  => __( 'The button text.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_search results="/trips/"]',
					),
					'kaiki_search_results'     => array(
						'text'    => __( 'The trips for the searched date and party, with the price for the party. Every trip before a search.', 'kaiki-booking' ),
						'atts'    => array(
							'layout' => __( 'cards, horizontal, overlay or minimal.', 'kaiki-booking' ),
						),
						'example' => '[kaiki_search_results]',
					),
				),
			),
		);
	}

	/**
	 * A password field with a button that shows what is in it.
	 *
	 * `new-password` rather than `off`: browsers ignore `off` on a password
	 * field and will happily fill in the administrator's own login password,
	 * which the next save would store as the secret.
	 *
	 * @param string $id          The input's id.
	 * @param string $name        The option it saves to.
	 * @param string $value       The stored value.
	 * @param string $placeholder A hint of the format.
	 */
	private static function secret_input( string $id, string $name, string $value, string $placeholder = '' ): void {
		?>
		<div class="kaiki-admin__secret">
			<input type="password" class="kaiki-admin__input code" id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				autocomplete="new-password" spellcheck="false"
				<?php echo '' !== $placeholder ? 'placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?>>
			<button type="button" class="button kaiki-admin__reveal" aria-controls="<?php echo esc_attr( $id ); ?>" aria-pressed="false"
				data-kaiki-reveal="<?php echo esc_attr( $id ); ?>"
				data-show="<?php echo esc_attr__( 'Show', 'kaiki-booking' ); ?>"
				data-hide="<?php echo esc_attr__( 'Hide', 'kaiki-booking' ); ?>">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				<span class="kaiki-admin__reveal-label"><?php echo esc_html__( 'Show', 'kaiki-booking' ); ?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * A button that copies some text.
	 *
	 * @param string $text    What to copy.
	 * @param bool   $as_code Show the text itself on the button, rather than «Copy».
	 */
	private static function copy_button( string $text, bool $as_code = false ): void {
		?>
		<button type="button" class="button kaiki-admin__copy<?php echo $as_code ? ' kaiki-admin__copy--code' : ''; ?>"
			data-kaiki-copy="<?php echo esc_attr( $text ); ?>"
			data-copy="<?php echo esc_attr__( 'Copy', 'kaiki-booking' ); ?>"
			data-copied="<?php echo esc_attr__( 'Copied', 'kaiki-booking' ); ?>"
			<?php echo $as_code ? 'title="' . esc_attr__( 'Copy', 'kaiki-booking' ) . '"' : ''; ?>>
			<?php if ( $as_code ) : ?>
				<code><?php echo esc_html( $text ); ?></code>
			<?php endif; ?>
			<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
			<span class="kaiki-admin__copy-label"><?php echo esc_html__( 'Copy', 'kaiki-booking' ); ?></span>
		</button>
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

		set_transient( self::STATUS_TRANSIENT, self::probe(), 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::tab_url( 'connection' ) );

		exit;
	}

	/**
	 * Drop the remembered check, so the screen checks the new settings.
	 */
	public static function forget_connection_status(): void {
		delete_transient( self::STATUS_TRANSIENT );
	}

	/**
	 * The last check, or a fresh one.
	 *
	 * Remembered for ten minutes and shown with the time it was made: a result
	 * from an hour ago is a statement about an hour ago, and one that did not
	 * say so would have an operator chasing a problem that is already fixed.
	 * Saving the settings forgets it, so a new key is checked as soon as the
	 * screen comes back.
	 *
	 * @return array{ok: bool, message: string, trips: int|null, more: bool, at: int}
	 */
	private static function connection_status(): array {
		if ( ! Settings::is_configured() ) {
			return self::probe();
		}

		$status = get_transient( self::STATUS_TRANSIENT );

		if ( is_array( $status ) && isset( $status['ok'], $status['message'], $status['at'] ) ) {
			return array(
				'ok'      => (bool) $status['ok'],
				'message' => (string) $status['message'],
				'trips'   => isset( $status['trips'] ) ? (int) $status['trips'] : null,
				'more'    => ! empty( $status['more'] ),
				'at'      => (int) $status['at'],
			);
		}

		$status = self::probe();

		set_transient( self::STATUS_TRANSIENT, $status, 10 * MINUTE_IN_SECONDS );

		return $status;
	}

	/**
	 * Ask the API whether this key works from this site, and say so in words.
	 *
	 * @return array{ok: bool, message: string, trips: int|null, more: bool, at: int}
	 */
	private static function probe(): array {
		$result = array(
			'ok'      => false,
			'message' => '',
			'trips'   => null,
			'more'    => false,
			'at'      => time(),
		);

		if ( ! Settings::is_configured() ) {
			return array(
				'message' => __( 'There is no key saved yet.', 'kaiki-booking' ),
				'at'      => 0,
			) + $result;
		}

		$response = wp_remote_get(
			Settings::api_base() . '/api/v1/branding',
			array(
				'timeout' => 8,
				'headers' => self::probe_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'message' => __( 'This site could not reach Kaiki at all. Check the address above, or try again in a minute.', 'kaiki-booking' ),
			) + $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return array(
				/* translators: the site's own address, which has to be on the key's allowed list. */
				'message' => sprintf(
					// phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- The comment is on the line above.
					__( 'Kaiki refused the key. Either it is wrong, or this site (%s) is not on the key\'s list of allowed addresses — you can add it in your Kaiki panel.', 'kaiki-booking' ),
					home_url()
				),
			) + $result;
		}

		if ( 200 !== $code ) {
			return array(
				'message' => __( 'Kaiki answered, but not in a way this plugin understands. Try again shortly.', 'kaiki-booking' ),
			) + $result;
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connected. This site can read your trips.', 'kaiki-booking' ),
		) + self::count_trips() + $result;
	}

	/**
	 * How many published trips this key can see: one page of up to a hundred,
	 * and whether there are more.
	 *
	 * Not cached and not through `Client`, because a count from the catalogue
	 * cache would be a count from before whatever the operator just changed.
	 * A failure here is not a failed connection — the check above already
	 * passed — so it leaves the count out rather than saying anything.
	 *
	 * @return array{trips?: int, more?: bool}
	 */
	private static function count_trips(): array {
		$response = wp_remote_get(
			add_query_arg( 'per_page', 100, Settings::api_base() . '/api/v1/products' ),
			array(
				'timeout' => 8,
				'headers' => self::probe_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return array();
		}

		return array(
			'trips' => count( array_filter( $decoded['data'], 'is_array' ) ),
			'more'  => ! empty( $decoded['pagination']['has_more'] ),
		);
	}

	/**
	 * The headers a check sends.
	 *
	 * @return array<string, string>
	 */
	private static function probe_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . Settings::publishable_key(),
			'Accept'        => 'application/json',
			'Origin'        => \Kaiki\Booking\Api\Client::site_origin(),
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
			echo '<p class="kaiki-admin__help">' . esc_html__( 'It has not run yet. It runs by itself every hour, and whenever you change something in Kaiki.', 'kaiki-booking' ) . '</p>';

			return;
		}

		$when = wp_date( 'j M Y, H:i', (int) $status['at'] );

		$error = isset( $status['error'] ) ? (string) $status['error'] : '';

		if ( '' !== $error ) {
			echo '<p class="kaiki-admin__sync-line is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong>' . esc_html( self::sync_error( $error ) ) . '</strong></p>';
			echo '<p class="kaiki-admin__meta">' . esc_html( (string) $when ) . '</p>';

			return;
		}

		$counts = isset( $status['counts'] ) && is_array( $status['counts'] ) ? $status['counts'] : array();

		$written = (int) ( $counts['created'] ?? 0 ) + (int) ( $counts['updated'] ?? 0 );

		echo '<p class="kaiki-admin__sync-line is-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html(
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
