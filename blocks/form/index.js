/**
 * Editor UI for the lead form block.
 *
 * ServerSideRender, deliberately. The form is rendered by PHP — the same
 * function the shortcode and the theme template tag call — so the only way the
 * canvas can show what the page will show is to render that PHP. A hand-built
 * editor preview is a second implementation of the form, and the two diverge
 * the first time a field changes.
 *
 * Plain wp.element rather than JSX, so the plugin has no build step. Something
 * that travels between projects should not need `npm install` before it works.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;

	blocks.registerBlockType( 'leadkit/form', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Form', 'leadkit' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Heading', 'leadkit' ),
							help: __( 'Optional. Leave empty for no heading.', 'leadkit' ),
							value: a.heading,
							onChange: function ( v ) { set( { heading: v } ); },
						} ),
						el( TextareaControl, {
							label: __( 'Project types', 'leadkit' ),
							help: __( 'One per line. Adds a dropdown so enquiries arrive sorted. Leave empty to omit it.', 'leadkit' ),
							value: a.projectTypes,
							rows: 6,
							onChange: function ( v ) { set( { projectTypes: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Message field label', 'leadkit' ),
							value: a.messageLabel,
							onChange: function ( v ) { set( { messageLabel: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Button text', 'leadkit' ),
							value: a.submitText,
							onChange: function ( v ) { set( { submitText: v } ); },
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Theme integration', 'leadkit' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Class prefix', 'leadkit' ),
							help: __( 'Only if your theme styles the form itself — e.g. "contact-form". Leave empty to use the plugin’s own styling.', 'leadkit' ),
							value: a.classPrefix,
							onChange: function ( v ) { set( { classPrefix: v } ); },
						} )
					)
				),
				el(
					'div',
					useBlockProps(),
					el( serverSideRender, {
						block: 'leadkit/form',
						attributes: a,
						/*
						 * The canvas must not be interactive: a form you can type
						 * into in the editor invites someone to fill it in and
						 * press send, and it would submit for real.
						 */
						EmptyResponsePlaceholder: function () {
							return el( 'p', null, __( 'The form will appear here.', 'leadkit' ) );
						},
					} )
				)
			);
		},

		// Dynamic block: the server renders it, so nothing is stored in the post.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
