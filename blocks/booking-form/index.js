/* Editor side of the Appointment booking block. No build step: written against the wp.* globals.
 * The block is dynamic (rendered in PHP), so markup changes never cause "invalid block" errors. */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	function field( props, key, label, help ) {
		return el( TextControl, {
			label: label,
			help: help,
			value: props.attributes[ key ],
			onChange: function ( v ) {
				var change = {};
				change[ key ] = v;
				props.setAttributes( change );
			},
		} );
	}

	wp.blocks.registerBlockType( 'appointment-booking/booking-form', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Booking settings', 'appointment-booking' ) },
						field( props, 'title', __( 'Heading', 'appointment-booking' ) ),
						field( props, 'service', __( 'Preselect a service (id)', 'appointment-booking' ), __( 'For example "treatment". Leave empty to let visitors choose.', 'appointment-booking' ) ),
						field( props, 'staff', __( 'Preselect a person (id)', 'appointment-booking' ), __( 'For example "alex". Leave empty for anyone.', 'appointment-booking' ) )
					)
				),
				el( ServerSideRender, { block: 'appointment-booking/booking-form', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
