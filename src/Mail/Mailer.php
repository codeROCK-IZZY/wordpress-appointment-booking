<?php
/**
 * Emails: confirmation (with a calendar file), cancellation, a reminder the day before, and a note to the business.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Mail;

use AppointmentBooking\Core\Ics;
use AppointmentBooking\Data\WpdbStore;
use AppointmentBooking\Settings;

/**
 * Mailer.
 */
final class Mailer {

	/**
	 * A booking's details for messages.
	 *
	 * @param array<string, string> $row Booking row.
	 * @return array{service: string, staff: string, when: string, business: string, location: string, manage: string}
	 */
	public static function details( array $row ): array {
		$config = Settings::config();
		$s      = Settings::get();
		$token  = Settings::tokens()->issue( (int) $row['id'], $row['email'] );
		$page   = '' !== $row['page_url'] ? $row['page_url'] : home_url( '/' );
		return array(
			'service'  => isset( $config->services[ $row['service'] ] ) ? $config->services[ $row['service'] ]->name : $row['service'],
			'staff'    => isset( $config->staff[ $row['staff'] ] ) ? $config->staff[ $row['staff'] ]->name : $row['staff'],
			'when'     => self::when( (int) $row['start_at'] ),
			'business' => (string) $s['business'],
			'location' => (string) $s['location'],
			'manage'   => add_query_arg( 'abk_booking', rawurlencode( $token ), $page ),
		);
	}

	/**
	 * A time in the business timezone, in the site's formats, with the zone abbreviation.
	 *
	 * @param int $t Instant.
	 */
	public static function when( int $t ): string {
		$tz = Settings::config()->timezone;
		return (string) wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ) . ' T', $t, $tz );
	}

	/**
	 * The calendar file for a booking.
	 *
	 * @param array<string, string> $row       Booking row.
	 * @param bool                  $cancelled Cancellation.
	 */
	public static function ics( array $row, bool $cancelled = false ): string {
		$d = self::details( $row );
		return Ics::event(
			'abk-' . $row['id'] . '@' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			(int) $row['start_at'],
			(int) $row['end_at'],
			$d['service'] . ' with ' . $d['staff'] . ( '' !== $d['business'] ? ' (' . $d['business'] . ')' : '' ),
			'Change or cancel: ' . $d['manage'],
			$d['location'],
			$cancelled,
			time()
		);
	}

	/**
	 * Confirmation to the customer and a note to the site owner.
	 *
	 * @param int $id Booking id.
	 */
	public static function confirmed( int $id ): void {
		$row = WpdbStore::find( $id );
		if ( null === $row ) {
			return;
		}
		$d = self::details( $row );
		/* translators: 1: service, 2: date and time. */
		$subject = sprintf( __( 'Booked: %1$s on %2$s', 'appointment-booking' ), $d['service'], $d['when'] );
		$body    = sprintf(
			/* translators: 1: name, 2: service, 3: staff, 4: date and time, 5: manage link. */
			__( "Hi %1\$s,\n\nYou're booked for %2\$s with %3\$s on %4\$s.\n\nThe calendar file attached adds it to your calendar.\n\nNeed to change or cancel? %5\$s\n", 'appointment-booking' ),
			$row['name'],
			$d['service'],
			$d['staff'],
			$d['when'],
			$d['manage']
		);
		self::send( $row['email'], $subject, $body, self::ics( $row ) );
		$admin = sprintf(
			/* translators: 1: name, 2: email, 3: phone, 4: service, 5: staff, 6: date and time, 7: notes. */
			__( "New booking\n\n%1\$s <%2\$s> %3\$s\n%4\$s with %5\$s\n%6\$s\n\nNotes: %7\$s\n", 'appointment-booking' ),
			$row['name'],
			$row['email'],
			$row['phone'],
			$d['service'],
			$d['staff'],
			$d['when'],
			'' !== $row['notes'] ? $row['notes'] : '-'
		);
		/* translators: 1: name, 2: date and time. */
		self::send( (string) get_option( 'admin_email' ), sprintf( __( 'New booking: %1$s, %2$s', 'appointment-booking' ), $row['name'], $d['when'] ), $admin, '', $row['email'] );
	}

	/**
	 * Cancellation to the customer and the site owner.
	 *
	 * @param int $id Booking id.
	 */
	public static function cancelled( int $id ): void {
		$row = WpdbStore::find( $id );
		if ( null === $row ) {
			return;
		}
		$d = self::details( $row );
		/* translators: 1: service, 2: date and time. */
		$subject = sprintf( __( 'Cancelled: %1$s on %2$s', 'appointment-booking' ), $d['service'], $d['when'] );
		/* translators: 1: name, 2: service, 3: date and time. */
		$body = sprintf( __( "Hi %1\$s,\n\nYour %2\$s on %3\$s is cancelled. The attached file removes it from your calendar.\n", 'appointment-booking' ), $row['name'], $d['service'], $d['when'] );
		self::send( $row['email'], $subject, $body, self::ics( $row, true ) );
		self::send( (string) get_option( 'admin_email' ), $subject . ' (' . $row['name'] . ')', $body );
	}

	/**
	 * Reminders for bookings starting in 23 to 25 hours (hourly cron, so each booking gets one).
	 */
	public static function send_reminders(): int {
		$now  = time();
		$sent = 0;
		foreach ( WpdbStore::due_reminders( $now + 23 * HOUR_IN_SECONDS, $now + 25 * HOUR_IN_SECONDS ) as $row ) {
			$d = self::details( $row );
			/* translators: 1: service, 2: date and time. */
			$subject = sprintf( __( 'Reminder: %1$s tomorrow, %2$s', 'appointment-booking' ), $d['service'], $d['when'] );
			/* translators: 1: name, 2: service, 3: staff, 4: date and time, 5: manage link. */
			$body = sprintf( __( "Hi %1\$s,\n\nA reminder of your %2\$s with %3\$s on %4\$s.\n\nCan't make it? Please cancel so someone else can have the time: %5\$s\n", 'appointment-booking' ), $row['name'], $d['service'], $d['staff'], $d['when'], $d['manage'] );
			self::send( $row['email'], $subject, $body );
			WpdbStore::mark_reminded( (int) $row['id'] );
			++$sent;
		}
		return $sent;
	}

	/**
	 * Send a plain-text email, optionally with a calendar attachment.
	 *
	 * @param string $to       Recipient.
	 * @param string $subject  Subject.
	 * @param string $body     Body.
	 * @param string $ics      Calendar file contents, or ''.
	 * @param string $reply_to Reply-To address, or ''.
	 */
	private static function send( string $to, string $subject, string $body, string $ics = '', string $reply_to = '' ): void {
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$attachments = array();
		$file        = '';
		if ( '' !== $ics ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = wp_tempnam( 'appointment.ics' );
			if ( '' !== $tmp ) {
				$file = substr( $tmp, 0, -4 ) . '.ics';
				if ( false !== file_put_contents( $file, $ics ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary attachment.
					$attachments[] = $file;
				}
				wp_delete_file( $tmp );
			}
		}
		wp_mail( $to, $subject, $body, $headers, $attachments );
		if ( '' !== $file ) {
			wp_delete_file( $file );
		}
	}
}
