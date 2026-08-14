<?php
/**
 * The lead form renderer.
 *
 * One source of markup, parameterised so a theme can adopt the form into its
 * own design system without forking it. This project renders it twice — as the
 * footer form (prefix `main-footer`) and as the contact page's larger form
 * (prefix `contact-form`, with a project-type select) — and both reproduce the
 * production markup exactly. A fresh install renders the `leadkit-form` prefix
 * and gets the plugin's minimal default styles.
 *
 * @package LeadKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a form was rendered on this request — gates the Turnstile mount
 * script in the footer.
 *
 * @param bool $mark Set the flag (internal).
 * @return bool
 */
function leadkit_form_was_rendered( $mark = false ) {
	static $rendered = false;

	if ( $mark ) {
		$rendered = true;
	}

	return $rendered;
}

/**
 * Render (or return) the lead form.
 *
 * @param array $args {
 *     Optional overrides.
 *
 *     @type string $class_prefix     BEM prefix for every element class. Default 'leadkit-form'.
 *     @type string $form_class       Class on the <form> itself. Default '{prefix}__form'.
 *     @type string $id_prefix        Prefix for input ids. Default 'f'.
 *     @type string $project_type     Hidden projectType value, used only when there is no select.
 *     @type string $submit_url       Override the configured endpoint.
 *     @type string $turnstile_action Turnstile data-action label. Site-verify
 *                                    implementations that pin the action (the
 *                                    turnstile-spin functions do) need this to
 *                                    match theirs. Default 'leadkit-form'.
 *     @type array  $labels           Field label overrides, keyed name/email/phone/message/project_type.
 *     @type array  $project_types    Options for a project-type select. Empty (default) renders
 *                                    the hidden projectType input instead.
 *     @type string $select_prompt    First, unselectable option of that select.
 *     @type string $textarea_class   Class suffix for the textarea. Default '__input'.
 *     @type string $select_class     Class suffix for the select. Default '__select'.
 *     @type int    $textarea_rows    Default 4.
 *     @type string $submit_class     Full class attribute for the submit button.
 *                                    Default '{prefix}__submit'.
 *     @type string $submit_text      Default 'SUBMIT REQUEST'.
 *     @type string $message_placeholder Textarea placeholder. Default none.
 * }
 * @param bool  $output Echo (true) or return (false).
 * @return string
 */
function leadkit_form( $args = array(), $output = true ) {
	$opts = leadkit_options();

	$args = wp_parse_args(
		$args,
		array(
			'class_prefix'        => 'leadkit-form',
			'form_class'          => '',
			'id_prefix'           => 'f',
			'project_type'        => 'Other',
			'submit_url'          => $opts['submit_url'],
			'turnstile_action'    => 'leadkit-form',
			'labels'              => array(),
			'project_types'       => array(),
			'select_prompt'       => __( 'Select a project type…', 'leadkit' ),
			'textarea_class'      => '__input',
			'select_class'        => '__select',
			'textarea_rows'       => 4,
			'submit_class'        => '',
			'submit_text'         => __( 'SUBMIT REQUEST', 'leadkit' ),
			'message_placeholder' => '',
		)
	);

	$p   = preg_replace( '/[^a-zA-Z0-9_-]/', '', $args['class_prefix'] );
	$idp = preg_replace( '/[^a-zA-Z0-9_-]/', '', $args['id_prefix'] );

	$labels = wp_parse_args(
		$args['labels'],
		array(
			'name'         => __( 'NAME', 'leadkit' ),
			'email'        => __( 'EMAIL', 'leadkit' ),
			'phone'        => __( 'PHONE', 'leadkit' ),
			'project_type' => __( 'PROJECT TYPE', 'leadkit' ),
			'message'      => __( 'PROJECT DESCRIPTION', 'leadkit' ),
		)
	);

	$submit_class = $args['submit_class'] ? $args['submit_class'] : $p . '__submit';
	/*
	 * Usually the <form> is {prefix}__form, as in the footer. The contact page
	 * names it with the bare prefix instead — and that is where its whole
	 * layout hangs, so getting it wrong costs the form every rule it has.
	 */
	$form_class = $args['form_class'] ? $args['form_class'] : $p . '__form';

	// The default prefix is the only case that needs the plugin's own CSS.
	if ( 'leadkit-form' === $p && wp_style_is( 'leadkit-form', 'registered' ) ) {
		wp_enqueue_style( 'leadkit-form' );
	}

	leadkit_form_was_rendered( true );

	$fields = array(
		array( 'name', 'text', $labels['name'], 'name' ),
		array( 'email', 'email', $labels['email'], 'email' ),
		array( 'phone', 'tel', $labels['phone'], 'tel' ),
	);

	ob_start();
	?>
<form class="<?= esc_attr( $form_class ) ?>" action="<?= esc_url( $args['submit_url'] ) ?>" method="POST" data-leadkit>
	<?php if ( ! $args['project_types'] ) : ?>
	<input type="hidden" name="projectType" value="<?= esc_attr( $args['project_type'] ) ?>">
	<?php endif; ?>
	<?php foreach ( $fields as $f ) : list( $name, $type, $label, $autocomplete ) = $f; ?>
	<div class="<?= esc_attr( $p ) ?>__group">
		<label for="<?= esc_attr( $idp . '-' . $name ) ?>" class="<?= esc_attr( $p ) ?>__label"><?= esc_html( $label ) ?></label>
		<input type="<?= esc_attr( $type ) ?>" id="<?= esc_attr( $idp . '-' . $name ) ?>" name="<?= esc_attr( $name ) ?>" class="<?= esc_attr( $p ) ?>__input" autocomplete="<?= esc_attr( $autocomplete ) ?>" required>
	</div>
	<?php endforeach; ?>
	<?php if ( $args['project_types'] ) : ?>
	<div class="<?= esc_attr( $p ) ?>__group">
		<label for="<?= esc_attr( $idp ) ?>-project-type" class="<?= esc_attr( $p ) ?>__label"><?= esc_html( $labels['project_type'] ) ?></label>
		<select id="<?= esc_attr( $idp ) ?>-project-type" name="projectType" class="<?= esc_attr( $p . $args['select_class'] ) ?>" required>
			<option value="" disabled selected><?= esc_html( $args['select_prompt'] ) ?></option>
			<?php foreach ( $args['project_types'] as $value => $label ) : ?>
				<?php $value = is_int( $value ) ? $label : $value; ?>
				<option value="<?= esc_attr( $value ) ?>"><?= esc_html( $label ) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php endif; ?>
	<div class="<?= esc_attr( $p ) ?>__group">
		<label for="<?= esc_attr( $idp ) ?>-project" class="<?= esc_attr( $p ) ?>__label"><?= esc_html( $labels['message'] ) ?></label>
		<textarea id="<?= esc_attr( $idp ) ?>-project" name="message" class="<?= esc_attr( $p . $args['textarea_class'] ) ?>" rows="<?= (int) $args['textarea_rows'] ?>"<?= $args['message_placeholder'] ? ' placeholder="' . esc_attr( $args['message_placeholder'] ) . '"' : '' ?> required></textarea>
	</div>
	<?php if ( $opts['turnstile_sitekey'] ) : ?>
		<?php
		/*
		 * Reserved box, sized to the rendered widget, so lazy-mounting it costs
		 * 0.00 CLS. The height is not decoration — without it the submit button
		 * jumps when the widget appears. The theme styles {prefix}__turnstile
		 * (65px min-height); the default stylesheet does the same.
		 */
		?>
	<div class="<?= esc_attr( $p ) ?>__group <?= esc_attr( $p ) ?>__turnstile" style="min-height: 65px;">
		<div id="leadkit-turnstile" class="cf-turnstile" data-sitekey="<?= esc_attr( $opts['turnstile_sitekey'] ) ?>" data-action="<?= esc_attr( $args['turnstile_action'] ) ?>"></div>
	</div>
	<?php endif; ?>
	<button type="submit" class="<?= esc_attr( $submit_class ) ?>"><?= esc_html( $args['submit_text'] ) ?></button>
</form>
	<?php
	$html = (string) ob_get_clean();

	if ( $output ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput — escaped field by field above.
		return '';
	}

	return $html;
}
