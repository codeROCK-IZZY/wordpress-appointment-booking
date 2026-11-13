<?php
/**
 * Where bookings live.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Storage contract. claim() must be atomic: of two overlapping claims for the same person, at most one succeeds, even
 * when they arrive at the same moment.
 */
interface BookingStore {

	/**
	 * Busy intervals (bookings including their buffers) for a person that overlap [from, to), sorted.
	 *
	 * @param string $staff Staff id.
	 * @param int    $from  From.
	 * @param int    $to    To.
	 * @return list<array{0: int, 1: int}>
	 */
	public function busy( string $staff, int $from, int $to ): array;

	/**
	 * Store the booking if, and only if, its whole block is still free. Returns the new id, or null if taken.
	 *
	 * @param Booking $booking Booking (without id).
	 */
	public function claim( Booking $booking ): ?int;
}
