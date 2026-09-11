/**
 * The block editor's half of WPP-5.
 *
 * ## Plain JavaScript, and no build step
 *
 * WordPress already ships `wp.blocks`, `wp.element`, `wp.components` and
 * `wp.apiFetch` as globals in the editor. `@wordpress/scripts` would give JSX
 * and a bundler, and would add a Node build to a PHP plugin that has none — for
 * three blocks whose entire interface is a select and a text field. A build
 * nobody can run is a block nobody can fix, and the operator's web person is the
 * person who will need to fix it.
 *
 * ## The canvas shows a description, not the widget
 *
 * Every block is server-rendered from its shortcode (see `Blocks.php`), and the
 * editor deliberately draws a **static** preview: the trip's name and what the
 * block is. Mounting the real widget here would put a working booking form
 * inside a page editor, which is how somebody accidentally makes a real booking
 * while laying out a page.
 */
( function ( blocks, element, components, blockEditor, i18n, apiFetch ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	/**
	 * The operator's trips, fetched once per editor session.
	 *
	 * Shared across every block on the page: three Kaiki blocks in one post
	 * should not be three requests, and the list does not change while somebody
	 * is laying out a page.
	 */
	var tripsPromise = null;

	function loadTrips() {
		if ( tripsPromise === null ) {
			tripsPromise = apiFetch( { path: '/kaiki/v1/trips' } ).catch( function () {
				return { trips: [], error: __( 'Kaiki could not be reached.', 'kaiki-booking' ) };
			} );
		}

		return tripsPromise;
	}

	/**
	 * A trip picker, or a plain field when the trips cannot be listed.
	 *
	 * The fallback matters: an operator whose site cannot reach Kaiki must still
	 * be able to save the page they are working on (WPP-14), so the field
	 * degrades to a text input holding whatever it already had rather than
	 * disappearing and taking the value with it.
	 */
	function TripPicker( props ) {
		var state = element.useState( { trips: [], error: '', loading: true } );
		var value = state[ 0 ];
		var setValue = state[ 1 ];

		element.useEffect( function () {
			var live = true;

			loadTrips().then( function ( response ) {
				if ( live ) {
					setValue( {
						trips: response.trips || [],
						error: response.error || '',
						loading: false,
					} );
				}
			} );

			return function () {
				live = false;
			};
		}, [] );

		if ( value.loading ) {
			return el( components.Spinner, null );
		}

		if ( value.error || value.trips.length === 0 ) {
			return el( element.Fragment, null,
				el( components.Notice, { status: 'warning', isDismissible: false },
					value.error || __( 'No trips found. You can still paste a trip id.', 'kaiki-booking' )
				),
				el( components.TextControl, {
					label: __( 'Trip id', 'kaiki-booking' ),
					value: props.value,
					onChange: props.onChange,
				} )
			);
		}

		var options = [ { label: __( 'Choose a trip…', 'kaiki-booking' ), value: '' } ];

		value.trips.forEach( function ( trip ) {
			options.push( { label: trip.title, value: trip.uuid } );
		} );

		return el( components.SelectControl, {
			label: __( 'Trip', 'kaiki-booking' ),
			value: props.value,
			options: options,
			onChange: props.onChange,
		} );
	}

	/**
	 * One block.
	 *
	 * @param {string}  name        The block's short name.
	 * @param {string}  title       What the inserter calls it.
	 * @param {string}  description What it does, for the inserter and the canvas.
	 * @param {boolean} needsTrip   Whether it takes a trip.
	 * @param {boolean} takesCategory Whether it takes a category.
	 */
	function registerKaikiBlock( name, title, description, needsTrip, takesCategory ) {
		blocks.registerBlockType( 'kaiki/' + name, {
			apiVersion: 2,
			title: title,
			description: description,
			category: 'widgets',
			icon: 'tickets-alt',
			attributes: {
				product: { type: 'string', default: '' },
				category: { type: 'string', default: '' },
			},
			edit: function ( props ) {
				var blockProps = blockEditor.useBlockProps();

				return el( element.Fragment, null,
					el( blockEditor.InspectorControls, null,
						el( components.PanelBody, { title: __( 'Kaiki', 'kaiki-booking' ) },
							needsTrip
								? el( TripPicker, {
									value: props.attributes.product,
									onChange: function ( next ) {
										props.setAttributes( { product: next } );
									},
								} )
								: null,
							takesCategory
								? el( components.TextControl, {
									label: __( 'Category', 'kaiki-booking' ),
									help: __( 'Leave empty to show every trip.', 'kaiki-booking' ),
									value: props.attributes.category,
									onChange: function ( next ) {
										props.setAttributes( { category: next } );
									},
								} )
								: null
						)
					),
					el( 'div', blockProps,
						el( components.Placeholder, {
							icon: 'tickets-alt',
							label: title,
							instructions: description,
						},
							needsTrip && ! props.attributes.product
								? el( 'p', null, __( 'Choose a trip in the sidebar.', 'kaiki-booking' ) )
								: null
						)
					)
				);
			},
			// Server-rendered: the shortcode's own callback draws this on the
			// front end, so there is nothing to save into the post content.
			save: function () {
				return null;
			},
		} );
	}

	registerKaikiBlock(
		'booking',
		__( 'Kaiki booking form', 'kaiki-booking' ),
		__( 'A booking form for one trip: date, party, details, pay.', 'kaiki-booking' ),
		true,
		false
	);

	registerKaikiBlock(
		'list',
		__( 'Kaiki trips', 'kaiki-booking' ),
		__( 'Your trips as a grid, with a link to book each one.', 'kaiki-booking' ),
		false,
		true
	);

	registerKaikiBlock(
		'enquiry',
		__( 'Kaiki enquiry form', 'kaiki-booking' ),
		__( 'An enquiry form, for trips with no published price.', 'kaiki-booking' ),
		true,
		false
	);
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.i18n,
	window.wp.apiFetch
);
