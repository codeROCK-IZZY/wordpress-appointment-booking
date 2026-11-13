<?php
/**
 * In-memory bookings, for tests and the offline evaluation.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Memory store with the same unit-claim semantics as the database store.
 */
final class MemoryStore implements BookingStore {

	/**
	 * Bookings by id.
	 *
	 * @var array<int, Booking>
	 */
	public array $bookings = array();

	/**
	 * Claimed units: "staff|unit" => booking id.
	 *
	 * @var array<string, int>
	 */
	private array $units = array();

	/**
	 * Last id issued.
	 *
	 * @var int
	 */
	private int $last = 0;

	/**
	 * Busy intervals.
	 *
	 * @param string $staff Staff id.
	 * @param int    $from  From.
	 * @param int    $to    To.
	 * @return list<array{0: int, 1: int}>
	 */
	public function busy( string $staff, int $from, int $to ): array {
		$out = array();
		foreach ( $this->bookings as $b ) {
			if ( $b->staff === $staff && $b->start < $to && $b->block_end > $from ) {
				$out[] = array( $b->start, $b->block_end );
			}
		}
		usort( $out, static fn ( array $x, array $y ): int => $x[0] <=> $y[0] );
		return $out;
	}

	/**
	 * Claim every unit or none.
	 *
	 * @param Booking $booking Booking.
	 */
	public function claim( Booking $booking ): ?int {
		foreach ( $booking->units() as $u ) {
			if ( isset( $this->units[ $booking->staff . '|' . $u ] ) ) {
				return null;
			}
		}
		$id = ++$this->last;
		foreach ( $booking->units() as $u ) {
			$this->units[ $booking->staff . '|' . $u ] = $id;
		}
		$this->bookings[ $id ] = $booking->with_id( $id );
		return $id;
	}

	/**
	 * Cancel a booking and free its units.
	 *
	 * @param int $id Id.
	 */
	public function cancel( int $id ): void {
		$this->units = array_filter( $this->units, static fn ( int $b ): bool => $b !== $id );
		unset( $this->bookings[ $id ] );
	}
}
