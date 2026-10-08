<?php
/**
 * class-forms.php — front-end forms (booking enquiries, newsletter) written
 * back to the Drift: Surface Hub, the one place Drift: Surface writes rather
 * than reads.
 *
 * The theme renders the form markup and posts to admin-ajax.php with
 * action=drift_surface_form, form=<form key from the map>, a nonce (all three
 * from drift_surface_form_hidden_fields()), and the field names the map lists. This class
 * validates, rate-limits, creates the hub record (1 request) and emails a
 * copy to the address in the map's 'notify' setting — so an enquiry is
 * never lost if the hub is unreachable.
 *
 * Map shape (maps/surface.php → 'forms'):
 *   'enquiry' => [
 *       'table'    => 'Enquiries',
 *       'fields'   => [ 'name' => 'Name', 'email' => 'Email', … ], // form key => hub field
 *       'required' => [ 'name', 'email', 'message' ],
 *       'email'    => [ 'email' ],                                   // validated as email
 *       'notify'   => 'booking_email',                               // settings key holding the address; false = only if the hub fails
 *       'subject'  => 'New enquiry from the website',
 *   ]
 *
 * @package Drift_Surface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Drift_Surface_Forms {

	const ACTION    = 'drift_surface_form';
	const HONEYPOT  = 'drift_surface_hp';
	const RATE_MAX  = 5;  // Submissions…
	const RATE_SPAN = 600; // …per IP per 10 minutes.

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle_ajax' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'handle_ajax' ] );
	}

	public static function handle_ajax(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		$form = sanitize_key( (string) wp_unslash( $_POST['form'] ?? '' ) );
		$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised per field in submit().

		// Honeypot: pretend it worked.
		if ( ! empty( $data[ self::HONEYPOT ] ) ) {
			wp_send_json_success( [ 'message' => __( 'Thanks — we\'ll be in touch.', 'drift-surface' ) ] );
		}

		if ( ! self::rate_ok() ) {
			wp_send_json_error( [ 'message' => __( 'Too many messages in a short time. Please try again in a few minutes.', 'drift-surface' ) ], 429 );
		}

		$result = self::submit( $form, is_array( $data ) ? $data : [] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message(), 'fields' => $result->get_error_data()['fields'] ?? [] ], 422 );
		}

		wp_send_json_success( [ 'message' => __( 'Thanks — we\'ll be in touch.', 'drift-surface' ) ] );
	}

	/**
	 * Validates and stores one submission. Usable directly from PHP too.
	 *
	 * @param string $form Form key in the map.
	 * @param array  $data Raw (unslashed) input.
	 * @return true|WP_Error
	 */
	public static function submit( string $form, array $data ) {
		$spec = Drift_Surface_Map::current()['forms'][ $form ] ?? null;
		if ( ! is_array( $spec ) || empty( $spec['table'] ) || empty( $spec['fields'] ) ) {
			return new WP_Error( 'drift_surface_form_unknown', __( 'This form isn\'t set up.', 'drift-surface' ) );
		}

		$values  = [];
		$invalid = [];

		foreach ( (array) $spec['fields'] as $key => $hub_field ) {
			$raw   = $data[ $key ] ?? '';
			$value = is_array( $raw ) ? array_map( 'sanitize_text_field', $raw ) : sanitize_textarea_field( (string) $raw );

			if ( in_array( $key, (array) ( $spec['email'] ?? [] ), true ) && '' !== $value ) {
				$value = sanitize_email( (string) $value );
				if ( ! is_email( $value ) ) {
					$invalid[] = $key;
				}
			}

			if ( in_array( $key, (array) ( $spec['required'] ?? [] ), true ) && ( '' === $value || [] === $value ) ) {
				$invalid[] = $key;
			}

			if ( '' !== $value && [] !== $value ) {
				$values[ (string) $hub_field ] = is_string( $value ) ? mb_substr( $value, 0, 5000 ) : $value;
			}
		}

		if ( $invalid ) {
			return new WP_Error( 'drift_surface_form_invalid', __( 'Please check the highlighted fields.', 'drift-surface' ), [ 'fields' => array_values( array_unique( $invalid ) ) ] );
		}

		/**
		 * Filters the hub fields before a form record is created.
		 *
		 * @param array  $values Hub field => value.
		 * @param string $form   Form key.
		 * @param array  $data   Raw input.
		 */
		$values = (array) apply_filters( 'drift_surface_form_values', $values, $form, $data );

		$created = Drift_Surface_Hub_Client::create_record( (string) $spec['table'], $values );
		if ( is_wp_error( $created ) ) {
			Drift_Surface_Log::error( sprintf( 'Form "%s" could not be saved to the hub — %s. Email copy sent instead.', $form, $created->get_error_message() ), 'forms' );
		}

		self::notify( $spec, $values, is_wp_error( $created ) );

		do_action( 'drift_surface_form_submitted', $form, $values, $created );

		return true;
	}

	/** Email copy to the address held in the synced settings (e.g. booking_email). */
	private static function notify( array $spec, array $values, bool $hub_failed ): void {
		if ( array_key_exists( 'notify', $spec ) && false === $spec['notify'] && ! $hub_failed ) {
			return; // e.g. newsletter signups: the hub is the record, no email needed.
		}

		$map      = Drift_Surface_Map::current();
		$settings = $map['settings'] ? get_option( $map['settings']['option'], [] ) : [];
		$to       = '';

		if ( ! empty( $spec['notify'] ) && is_array( $settings ) ) {
			$to = sanitize_email( (string) ( $settings[ $spec['notify'] ] ?? '' ) );
		}
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$lines = [];
		foreach ( $values as $field => $value ) {
			$lines[] = $field . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : $value );
		}
		if ( $hub_failed ) {
			$lines[] = '';
			$lines[] = __( '(This message could not be saved to the hub — please add it there by hand.)', 'drift-surface' );
		}

		$headers = [];
		foreach ( $values as $field => $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				$headers[] = 'Reply-To: ' . $value;
				break;
			}
		}

		wp_mail(
			$to,
			(string) ( $spec['subject'] ?? __( 'New message from the website', 'drift-surface' ) ),
			implode( "\n", $lines ),
			$headers
		);
	}

	private static function rate_ok(): bool {
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'drift_surface_form_rate_' . md5( $ip );
		$n   = (int) get_transient( $key );

		if ( $n >= self::RATE_MAX ) {
			return false;
		}
		set_transient( $key, $n + 1, self::RATE_SPAN );
		return true;
	}
}
