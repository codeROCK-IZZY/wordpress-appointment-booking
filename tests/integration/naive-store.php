<?php
/**
 * Test-only must-use plugin: the "check, then insert" storage most booking code uses, for comparison.
 * Active only while the option abk_test_naive is set.
 *
 * It looks for an overlapping confirmed booking and inserts if there is none. Two requests that check at the same
 * moment both see the time as free, and both insert.
 *
 * @package AppointmentBooking
 */

use AppointmentBooking\Core\Booking;
use AppointmentBooking\Core\BookingStore;
use AppointmentBooking\Data\Schema;
use AppointmentBooking\Data\WpdbStore;

add_filter(
	'appointment_booking_store',
	static function ( $store ) {
		if ( ! get_option( 'abk_test_naive' ) ) {
			return $store;
		}
		return new class() implements BookingStore {
			/**
			 * Busy intervals, as the real store reads them.
			 *
			 * @param string $staff Staff.
			 * @param int    $from  From.
			 * @param int    $to    To.
			 */
			public function busy( string $staff, int $from, int $to ): array {
				return ( new WpdbStore() )->busy( $staff, $from, $to );
			}

			/**
			 * Check, then insert.
			 *
			 * @param Booking $b Booking.
			 */
			public function claim( Booking $b ): ?int {
				global $wpdb;
				$clash = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE staff = %s AND status = 'confirmed' AND start_at < %d AND block_end > %d", Schema::bookings(), $b->staff, $b->block_end, $b->start ) );
				if ( $clash ) {
					return null;
				}
				$wpdb->insert(
					Schema::bookings(),
					array(
						'service'    => $b->service,
						'staff'      => $b->staff,
						'start_at'   => $b->start,
						'end_at'     => $b->end,
						'block_end'  => $b->block_end,
						'name'       => $b->name,
						'email'      => $b->email,
						'phone'      => $b->phone,
						'notes'      => $b->notes,
						'status'     => 'confirmed',
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				return (int) $wpdb->insert_id;
			}
		};
	}
);

// Concurrency tests send many bookings from one address.
add_filter( 'appointment_booking_per_hour', static fn () => 1000 );
