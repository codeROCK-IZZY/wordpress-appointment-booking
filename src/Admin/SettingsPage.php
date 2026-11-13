<?php
/**
 * Admin: business details, timezone, services, staff and their hours, and booking rules.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Admin;

use AppointmentBooking\Core\Config;
use AppointmentBooking\Settings;

/**
 * Settings page. Rows for services and staff are plain form rows (no JavaScript); two empty rows are always shown
 * for adding more, and a row with no name is removed on save.
 */
final class SettingsPage {

	public const SLUG = 'abk-settings';

	/**
	 * Register.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_abk_save_settings', array( self::class, 'save' ) );
	}

	/**
	 * Menu entry under Bookings.
	 */
	public static function menu(): void {
		add_submenu_page( BookingsPage::SLUG, __( 'Booking settings', 'appointment-booking' ), __( 'Settings', 'appointment-booking' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Settings::get();
		$errors = array();
		Settings::config( $errors );
		echo '<div class="wrap abk-admin"><h1>' . esc_html__( 'Booking settings', 'appointment-booking' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'appointment-booking' ) . '</p></div>';
		}
		foreach ( $errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( Settings::is_fixed_offset( (string) $s['timezone'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The timezone is a fixed UTC offset, which never changes for daylight saving: opening hours will be an hour out for part of the year. Choose a city instead.', 'appointment-booking' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="abk_save_settings">';
		wp_nonce_field( 'abk_save_settings' );
		echo '<table class="form-table" role="presentation">';
		self::text_row( 'business', __( 'Business name', 'appointment-booking' ), (string) $s['business'] );
		self::text_row( 'location', __( 'Address (for calendar entries)', 'appointment-booking' ), (string) $s['location'] );
		echo '<tr><th scope="row"><label for="abk-timezone">' . esc_html__( 'Timezone', 'appointment-booking' ) . '</label></th><td><select id="abk-timezone" name="timezone">' . wp_timezone_choice( (string) $s['timezone'], get_user_locale() ) . '</select><p class="description">' . esc_html__( 'Opening hours are in this timezone, and follow its daylight saving changes.', 'appointment-booking' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_timezone_choice() returns escaped options.
		echo '<tr><th scope="row">' . esc_html__( 'Times to offer', 'appointment-booking' ) . '</th><td><fieldset>';
		$labels = array(
			'gap_aware' => __( 'Gap-aware (recommended): times that do not leave gaps too short to book', 'appointment-booking' ),
			'every'     => __( 'Every time that fits', 'appointment-booking' ),
			'grid'      => __( 'A fixed grid the length of each service', 'appointment-booking' ),
		);
		foreach ( Config::POLICIES as $policy ) {
			echo '<label><input type="radio" name="policy" value="' . esc_attr( $policy ) . '" ' . checked( $s['policy'], $policy, false ) . '> ' . esc_html( $labels[ $policy ] ) . '</label><br>';
		}
		echo '</fieldset></td></tr>';
		self::number_row( 'granularity', __( 'Minutes between start times', 'appointment-booking' ), (int) $s['granularity'], 5, 60 );
		self::number_row( 'notice', __( 'Minimum notice (minutes) for booking and cancelling online', 'appointment-booking' ), (int) $s['notice'], 0, 10080 );
		self::number_row( 'horizon', __( 'Days ahead that can be booked', 'appointment-booking' ), (int) $s['horizon'], 1, 365 );
		$closures = is_array( $s['closures'] ) ? implode( "\n", array_map( 'strval', $s['closures'] ) ) : (string) $s['closures'];
		echo '<tr><th scope="row"><label for="abk-closures">' . esc_html__( 'Closed on (one date per line, YYYY-MM-DD)', 'appointment-booking' ) . '</label></th><td><textarea id="abk-closures" name="closures" rows="4" class="regular-text">' . esc_textarea( $closures ) . '</textarea></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Services', 'appointment-booking' ) . '</h2><table class="widefat abk-rows"><thead><tr><th>' . esc_html__( 'Name', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Minutes', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Buffer after (minutes)', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Price', 'appointment-booking' ) . '</th></tr></thead><tbody>';
		$services = array_merge( is_array( $s['services'] ) ? $s['services'] : array(), array( array(), array() ) );
		foreach ( $services as $i => $row ) {
			$row = is_array( $row ) ? $row : array();
			echo '<tr><td><input name="services[' . (int) $i . '][name]" value="' . esc_attr( (string) ( $row['name'] ?? '' ) ) . '"></td><td><input type="number" min="5" max="720" name="services[' . (int) $i . '][duration]" value="' . esc_attr( (string) ( $row['duration'] ?? '' ) ) . '"></td><td><input type="number" min="0" max="240" name="services[' . (int) $i . '][buffer]" value="' . esc_attr( (string) ( $row['buffer'] ?? '' ) ) . '"></td><td><input type="number" min="0" step="0.01" name="services[' . (int) $i . '][price]" value="' . esc_attr( (string) ( $row['price'] ?? '' ) ) . '"></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Staff', 'appointment-booking' ) . '</h2><p class="description">' . esc_html__( 'Hours like "Mon-Fri 09:00-12:30, 13:30-17:00; Sat 10:00-14:00". Services: "all", or names separated by commas.', 'appointment-booking' ) . '</p><table class="widefat abk-rows"><thead><tr><th>' . esc_html__( 'Name', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Hours', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Services', 'appointment-booking' ) . '</th></tr></thead><tbody>';
		$staff = array_merge( is_array( $s['staff'] ) ? $s['staff'] : array(), array( array(), array() ) );
		foreach ( $staff as $i => $row ) {
			$row = is_array( $row ) ? $row : array();
			echo '<tr><td><input name="staff[' . (int) $i . '][name]" value="' . esc_attr( (string) ( $row['name'] ?? '' ) ) . '"></td><td><input class="abk-wide" name="staff[' . (int) $i . '][hours]" value="' . esc_attr( (string) ( $row['hours'] ?? '' ) ) . '"></td><td><input name="staff[' . (int) $i . '][services]" value="' . esc_attr( (string) ( $row['services'] ?? 'all' ) ) . '"></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Add the booking widget to any page with the "Appointment booking" block or the [appointment_booking] shortcode.', 'appointment-booking' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * A text row.
	 *
	 * @param string $key   Field.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function text_row( string $key, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="abk-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="abk-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	/**
	 * A number row.
	 *
	 * @param string $key   Field.
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 */
	private static function number_row( string $key, string $label, int $value, int $min, int $max ): void {
		echo '<tr><th scope="row"><label for="abk-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" id="abk-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '"></td></tr>';
	}

	/**
	 * Save.
	 */
	public static function save(): void {
		check_admin_referer( 'abk_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'appointment-booking' ), '', array( 'response' => 403 ) );
		}
		$post = wp_unslash( $_POST );
		update_option( Settings::OPTION, self::clean( is_array( $post ) ? $post : array() ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::SLUG,
					'saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize submitted settings. Rows without a name are dropped.
	 *
	 * @param array<mixed> $post Unslashed POST.
	 * @return array<string, mixed>
	 */
	public static function clean( array $post ): array {
		$rows = static function ( mixed $items, array $fields ): array {
			$out = array();
			foreach ( is_array( $items ) ? $items : array() as $row ) {
				if ( ! is_array( $row ) || '' === trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) ) ) {
					continue;
				}
				$clean = array();
				foreach ( $fields as $f => $type ) {
					$v           = $row[ $f ] ?? '';
					$clean[ $f ] = 'int' === $type ? absint( $v ) : ( 'float' === $type ? max( 0.0, (float) $v ) : sanitize_text_field( (string) $v ) );
				}
				$out[] = $clean;
			}
			return $out;
		};
		$tz   = sanitize_text_field( (string) ( $post['timezone'] ?? '' ) );
		if ( preg_match( '/^UTC([+-]\d+(\.\d+)?)$/', $tz, $m ) ) {
			// wp_timezone_choice() offers manual offsets as "UTC+1"; store them the way PHP reads them.
			$hours   = (float) $m[1];
			$minutes = (int) round( abs( $hours ) * 60 );
			$tz      = sprintf( '%s%02d:%02d', $hours < 0 ? '-' : '+', intdiv( $minutes, 60 ), $minutes % 60 );
		}
		return array(
			'business'    => sanitize_text_field( (string) ( $post['business'] ?? '' ) ),
			'location'    => sanitize_text_field( (string) ( $post['location'] ?? '' ) ),
			'timezone'    => '' !== $tz ? $tz : 'UTC',
			'policy'      => in_array( $post['policy'] ?? '', Config::POLICIES, true ) ? (string) $post['policy'] : 'gap_aware',
			'granularity' => max( 5, min( 60, absint( $post['granularity'] ?? 15 ) ) ),
			'notice'      => min( 10080, absint( $post['notice'] ?? 120 ) ),
			'horizon'     => max( 1, min( 365, absint( $post['horizon'] ?? 30 ) ) ),
			'closures'    => array_values( array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( (string) ( $post['closures'] ?? '' ) ) ) ), static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) ),
			'services'    => $rows(
				$post['services'] ?? array(),
				array(
					'name'     => 'text',
					'duration' => 'int',
					'buffer'   => 'int',
					'price'    => 'float',
				)
			),
			'staff'       => $rows(
				$post['staff'] ?? array(),
				array(
					'name'     => 'text',
					'hours'    => 'text',
					'services' => 'text',
				)
			),
		);
	}
}
