<?php
/**
 * Admin: the day's agenda per person, and the upcoming bookings with a cancel action.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Admin;

use AppointmentBooking\Core\Availability;
use AppointmentBooking\Data\WpdbStore;
use AppointmentBooking\Mail\Mailer;
use AppointmentBooking\Settings;

/**
 * Bookings page.
 */
final class BookingsPage {

	public const SLUG = 'abk-bookings';

	/**
	 * Register the menu, assets and actions.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_abk_admin_cancel', array( self::class, 'handle_cancel' ) );
	}

	/**
	 * Menu entries.
	 */
	public static function menu(): void {
		add_menu_page( __( 'Bookings', 'appointment-booking' ), __( 'Bookings', 'appointment-booking' ), 'edit_others_posts', self::SLUG, array( self::class, 'render' ), 'dashicons-calendar-alt', 26 );
	}

	/**
	 * Styles on the plugin's own pages only.
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( string $hook ): void {
		if ( str_contains( $hook, self::SLUG ) || str_contains( $hook, SettingsPage::SLUG ) ) {
			wp_enqueue_style( 'abk-admin', APPOINTMENT_BOOKING_URL . 'assets/admin.css', array(), APPOINTMENT_BOOKING_VERSION );
		}
	}

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$config = Settings::config();
		$tz     = $config->timezone;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( false === $day ) {
			$day = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
		}
		$from = $day->getTimestamp();
		$to   = $day->modify( '+1 day' )->getTimestamp();
		$rows = WpdbStore::between( $from, $to );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['abk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['abk_msg'] ) ) : '';
		$url    = static fn ( \DateTimeImmutable $d ): string => add_query_arg(
			array(
				'page' => self::SLUG,
				'date' => $d->format( 'Y-m-d' ),
			),
			admin_url( 'admin.php' )
		);
		$counts = WpdbStore::counts();
		echo '<div class="wrap abk-admin"><h1>' . esc_html__( 'Bookings', 'appointment-booking' ) . '</h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		echo '<p class="abk-nav"><a class="button" href="' . esc_url( $url( $day->modify( '-1 day' ) ) ) . '">&larr;</a> <strong>' . esc_html( (string) wp_date( 'l j F Y', $from + 43200, $tz ) ) . '</strong> <a class="button" href="' . esc_url( $url( $day->modify( '+1 day' ) ) ) . '">&rarr;</a> <a href="' . esc_url( $url( ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 ) ) ) . '">' . esc_html__( 'Today', 'appointment-booking' ) . '</a>';
		/* translators: 1: confirmed bookings, 2: cancelled bookings, 3: timezone. */
		echo ' <span class="abk-muted">' . esc_html( sprintf( __( '%1$d confirmed, %2$d cancelled in total · times in %3$s', 'appointment-booking' ), $counts['confirmed'], $counts['cancelled'], $tz->getName() ) ) . '</span></p>';

		self::agenda( $day->format( 'Y-m-d' ), $rows );

		echo '<h2>' . esc_html__( 'This day', 'appointment-booking' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No bookings.', 'appointment-booking' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Service', 'appointment-booking' ) . '</th><th>' . esc_html__( 'With', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Customer', 'appointment-booking' ) . '</th><th>' . esc_html__( 'Notes', 'appointment-booking' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$d = Mailer::details( $r );
				echo '<tr><td>' . esc_html( (string) wp_date( (string) get_option( 'time_format', 'g:i a' ), (int) $r['start_at'], $tz ) . ' - ' . (string) wp_date( (string) get_option( 'time_format', 'g:i a' ), (int) $r['end_at'], $tz ) ) . '</td><td>' . esc_html( $d['service'] ) . '</td><td>' . esc_html( $d['staff'] ) . '</td><td>' . esc_html( $r['name'] ) . '<br><a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a> ' . esc_html( $r['phone'] ) . '</td><td>' . esc_html( $r['notes'] ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Cancel this booking and email the customer?', 'appointment-booking' ) ) . '\')"><input type="hidden" name="action" value="abk_admin_cancel"><input type="hidden" name="id" value="' . esc_attr( $r['id'] ) . '">';
				wp_nonce_field( 'abk_admin_cancel_' . $r['id'] );
				echo '<button class="button-link-delete" type="submit">' . esc_html__( 'Cancel', 'appointment-booking' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * A timeline per person: opening hours shaded, bookings as blocks (buffers hatched).
	 *
	 * @param string                      $date Local date.
	 * @param list<array<string, string>> $rows Bookings that day.
	 */
	private static function agenda( string $date, array $rows ): void {
		$config = Settings::config();
		$ranges = array();
		foreach ( $config->staff as $p ) {
			$ranges[ $p->id ] = Availability::open_ranges( $config, $p, $date );
		}
		$all = array_merge( ...array_values( array_map( static fn ( array $r ): array => $r ? $r : array(), $ranges ) ) );
		if ( ! $all ) {
			echo '<p class="abk-muted">' . esc_html__( 'Closed on this day.', 'appointment-booking' ) . '</p>';
			return;
		}
		$start = (int) min( array_column( $all, 0 ) );
		$end   = (int) max( array_column( $all, 1 ) );
		$span  = max( 1, $end - $start );
		$pos   = static fn ( int $t ): string => number_format( 100 * ( $t - $start ) / $span, 3, '.', '' ) . '%';
		$wid   = static fn ( int $a, int $b ): string => number_format( 100 * ( $b - $a ) / $span, 3, '.', '' ) . '%';
		echo '<div class="abk-agenda"><div class="abk-hours">';
		for ( $t = (int) ( ceil( $start / 3600 ) * 3600 ); $t < $end; $t += 3600 ) {
			echo '<span style="left:' . esc_attr( $pos( $t ) ) . '">' . esc_html( (string) wp_date( 'H:i', $t, $config->timezone ) ) . '</span>';
		}
		echo '</div>';
		foreach ( $config->staff as $p ) {
			echo '<div class="abk-row"><div class="abk-who">' . esc_html( $p->name ) . '</div><div class="abk-track">';
			foreach ( $ranges[ $p->id ] as [ $a, $b ] ) {
				echo '<div class="abk-open" style="left:' . esc_attr( $pos( $a ) ) . ';width:' . esc_attr( $wid( $a, $b ) ) . '"></div>';
			}
			foreach ( $rows as $r ) {
				if ( $r['staff'] !== $p->id ) {
					continue;
				}
				$d = Mailer::details( $r );
				if ( (int) $r['block_end'] > (int) $r['end_at'] ) {
					echo '<div class="abk-buffer" style="left:' . esc_attr( $pos( (int) $r['end_at'] ) ) . ';width:' . esc_attr( $wid( (int) $r['end_at'], (int) $r['block_end'] ) ) . '"></div>';
				}
				echo '<div class="abk-booking" style="left:' . esc_attr( $pos( (int) $r['start_at'] ) ) . ';width:' . esc_attr( $wid( (int) $r['start_at'], (int) $r['end_at'] ) ) . '" title="' . esc_attr( $d['service'] . ' · ' . $r['name'] . ' · ' . (string) wp_date( 'H:i', (int) $r['start_at'], $config->timezone ) ) . '"><b>' . esc_html( (string) wp_date( 'H:i', (int) $r['start_at'], $config->timezone ) ) . '</b> ' . esc_html( $r['name'] ) . '</div>';
			}
			echo '</div></div>';
		}
		echo '</div><p class="abk-muted abk-legend"><i class="abk-k-open"></i>' . esc_html__( 'open', 'appointment-booking' ) . ' <i class="abk-k-booking"></i>' . esc_html__( 'booked', 'appointment-booking' ) . ' <i class="abk-k-buffer"></i>' . esc_html__( 'buffer after a service', 'appointment-booking' ) . '</p>';
	}

	/**
	 * Cancel from the admin.
	 */
	public static function handle_cancel(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'abk_admin_cancel_' . $id );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'appointment-booking' ), '', array( 'response' => 403 ) );
		}
		$row = WpdbStore::find( $id );
		$msg = __( 'Booking not found.', 'appointment-booking' );
		if ( null !== $row ) {
			if ( 'confirmed' === $row['status'] && WpdbStore::cancel( $id ) ) {
				Mailer::cancelled( $id );
				$msg = __( 'Booking cancelled and the customer emailed.', 'appointment-booking' );
			} else {
				$msg = __( 'That booking was already cancelled.', 'appointment-booking' );
			}
		}
		$date = null !== $row ? (string) wp_date( 'Y-m-d', (int) $row['start_at'], Settings::config()->timezone ) : '';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'date'    => $date,
					'abk_msg' => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
