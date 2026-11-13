<?php
/**
 * Bookings in the WordPress database.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Data;

use AppointmentBooking\Core\Booking;
use AppointmentBooking\Core\BookingStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; results change on every booking, so nothing is cached.

/**
 * Database store.
 *
 * claim() inserts the booking as pending, then claims all of its five-minute units in ONE multi-row INSERT. InnoDB
 * makes that statement atomic, and the claims table's primary key (staff, unit) means that of two overlapping
 * bookings arriving at the same moment, exactly one statement succeeds. The loser's pending row is deleted.
 */
final class WpdbStore implements BookingStore {

	/**
	 * Busy intervals (confirmed bookings, including buffers). A pending row is not busy yet: it either wins its claim
	 * in the next statement or is deleted, and the claims table settles any race.
	 *
	 * @param string $staff Staff id.
	 * @param int    $from  From.
	 * @param int    $to    To.
	 * @return list<array{0: int, 1: int}>
	 */
	public function busy( string $staff, int $from, int $to ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT start_at, block_end FROM %i WHERE staff = %s AND status = \'confirmed\' AND start_at < %d AND block_end > %d ORDER BY start_at',
				Schema::bookings(),
				$staff,
				$to,
				$from
			),
			ARRAY_A
		);
		return array_values( array_map( static fn ( array $r ): array => array( (int) $r['start_at'], (int) $r['block_end'] ), is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Claim atomically.
	 *
	 * @param Booking $booking Booking.
	 */
	public function claim( Booking $booking ): ?int {
		global $wpdb;
		$ok = $wpdb->insert(
			Schema::bookings(),
			array(
				'service'    => $booking->service,
				'staff'      => $booking->staff,
				'start_at'   => $booking->start,
				'end_at'     => $booking->end,
				'block_end'  => $booking->block_end,
				'name'       => $booking->name,
				'email'      => $booking->email,
				'phone'      => $booking->phone,
				'notes'      => $booking->notes,
				'status'     => 'pending',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return null;
		}
		$id     = (int) $wpdb->insert_id;
		$units  = $booking->units();
		$values = implode( ', ', array_fill( 0, count( $units ), '(%s, %d, %d)' ) );
		$args   = array( Schema::claims() );
		foreach ( $units as $u ) {
			array_push( $args, $booking->staff, $u, $id );
		}
		$suppress = $wpdb->suppress_errors( true ); // A duplicate key is the expected way to lose a race; don't print it.
		$claimed  = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (staff, unit, booking_id) VALUES {$values}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $values holds only placeholders.
		$wpdb->suppress_errors( $suppress );
		if ( false === $claimed ) {
			$wpdb->delete( Schema::bookings(), array( 'id' => $id ), array( '%d' ) );
			return null;
		}
		$wpdb->update( Schema::bookings(), array( 'status' => 'confirmed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		return $id;
	}

	/**
	 * Cancel a booking and release its time.
	 *
	 * @param int $id Booking id.
	 */
	public static function cancel( int $id ): bool {
		global $wpdb;
		$changed = $wpdb->update(
			Schema::bookings(),
			array( 'status' => 'cancelled' ),
			array(
				'id'     => $id,
				'status' => 'confirmed',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		$wpdb->delete( Schema::claims(), array( 'booking_id' => $id ), array( '%d' ) );
		return (bool) $changed;
	}

	/**
	 * Set where the booking was made, for the manage link.
	 *
	 * @param int    $id  Booking id.
	 * @param string $url Page URL.
	 */
	public static function set_page( int $id, string $url ): void {
		global $wpdb;
		$wpdb->update( Schema::bookings(), array( 'page_url' => $url ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * One booking row.
	 *
	 * @param int $id Id.
	 * @return array<string, string>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::bookings(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Bookings starting in [from, to), optionally only confirmed ones.
	 *
	 * @param int  $from           From.
	 * @param int  $to             To.
	 * @param bool $confirmed_only Only confirmed.
	 * @return list<array<string, string>>
	 */
	public static function between( int $from, int $to, bool $confirmed_only = true ): array {
		global $wpdb;
		$rows = $confirmed_only
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = \'confirmed\' AND start_at >= %d AND start_at < %d ORDER BY start_at', Schema::bookings(), $from, $to ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE start_at >= %d AND start_at < %d ORDER BY start_at', Schema::bookings(), $from, $to ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Bookings for an email address.
	 *
	 * @param string $email Email.
	 * @return list<array<string, string>>
	 */
	public static function by_email( string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY start_at', Schema::bookings(), $email ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Confirmed bookings that start within a window and have had no reminder.
	 *
	 * @param int $from From.
	 * @param int $to   To.
	 * @return list<array<string, string>>
	 */
	public static function due_reminders( int $from, int $to ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = \'confirmed\' AND reminded = 0 AND start_at >= %d AND start_at < %d', Schema::bookings(), $from, $to ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Mark a reminder sent.
	 *
	 * @param int $id Id.
	 */
	public static function mark_reminded( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::bookings(), array( 'reminded' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Remove a person's details from a booking, keeping the time slot history (privacy erasure).
	 *
	 * @param int $id Id.
	 */
	public static function anonymise( int $id ): void {
		global $wpdb;
		$wpdb->update(
			Schema::bookings(),
			array(
				'name'  => 'Deleted',
				'email' => 'deleted-' . $id . '@invalid.example',
				'phone' => '',
				'notes' => '',
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Schema::bookings() ), ARRAY_A );
		$out  = array(
			'confirmed' => 0,
			'cancelled' => 0,
			'pending'   => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}
}
