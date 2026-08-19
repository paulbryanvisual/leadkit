<?php
/**
 * Front-end render for the lead form block.
 *
 * A thin wrapper over leadkit_form(), which is also what the shortcode and the
 * theme template tag call. Three entry points, one renderer — a block that drew
 * its own markup would drift from the other two the first time a field changed.
 *
 * @package LeadKit
 *
 * @var array $attributes Block attributes.
 */

if ( ! function_exists( 'leadkit_form' ) ) {
	return;
}

/*
 * Labels go in the `labels` array, not as top-level arguments — leadkit_form()
 * merges that array over its own defaults. Passing `message_label` was silently
 * ignored, which is the failure mode of any function that accepts an arbitrary
 * args array: a typo is not an error, it is a default.
 */
$leadkit_args = array(
	'submit_text' => $attributes['submitText'] ?: __( 'Send', 'leadkit' ),
	'labels'      => array_filter(
		array(
			'message' => $attributes['messageLabel'],
		)
	),
);

/*
 * The theme's own class prefix, when it has one. Empty means the plugin's
 * default styling, which is the right answer on a site with no opinion.
 */
if ( ! empty( $attributes['classPrefix'] ) ) {
	$leadkit_args['class_prefix'] = sanitize_key( $attributes['classPrefix'] );
	$leadkit_args['form_class']   = sanitize_key( $attributes['classPrefix'] );
}

/*
 * A dropdown of project types, one per line in the editor. Without any, the
 * form asks for a message and nothing else — which is the correct shape for a
 * site that does not sort enquiries by kind.
 */
if ( ! empty( $attributes['projectTypes'] ) ) {
	$leadkit_types = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $attributes['projectTypes'] ) ) ) );
	if ( $leadkit_types ) {
		$leadkit_args['project_types'] = array_combine( $leadkit_types, $leadkit_types );
	}
}

?>
<div <?= get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( ! empty( $attributes['heading'] ) ) : ?>
		<h2 class="leadkit-form__heading"><?= esc_html( $attributes['heading'] ) ?></h2>
	<?php endif; ?>
	<?php leadkit_form( $leadkit_args ); ?>
</div>
