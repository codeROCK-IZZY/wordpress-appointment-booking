<?php
/**
 * A booking.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Booking value object. Instants are Unix seconds; `block_end` includes the service's buffer.
 */
final class Booking {

	/**
	 * Five-minute units on an absolute grid: two bookings that overlap always share at least one unit, whatever the
	 * opening hours or granularity were when each was made.
	 */
	public const UNIT = 300;

	/**
	 * Constructor.
	 *
	 * @param string $service   Service id.
	 * @param string $staff     Staff id.
	 * @param int    $start     Start.
	 * @param int    $end       End of the appointment.
	 * @param int    $block_end End including the buffer.
	 * @param string $name      Customer name.
	 * @param string $email     Customer email.
	 * @param string $phone     Customer phone.
	 * @param string $notes     Notes.
	 * @param int    $id        Id once stored.
	 */
	public function __construct(
		public readonly string $service,
		public readonly string $staff,
		public readonly int $start,
		public readonly int $end,
		public readonly int $block_end,
		public readonly string $name = '',
		public readonly string $email = '',
		public readonly string $phone = '',
		public readonly string $notes = '',
		public readonly int $id = 0
	) {}

	/**
	 * The units this booking occupies.
	 *
	 * @return list<int>
	 */
	public function units(): array {
		$out = array();
		for ( $u = intdiv( $this->start, self::UNIT ) * self::UNIT; $u < $this->block_end; $u += self::UNIT ) {
			$out[] = $u;
		}
		return $out;
	}

	/**
	 * A copy with an id.
	 *
	 * @param int $id Id.
	 */
	public function with_id( int $id ): self {
		return new self( $this->service, $this->staff, $this->start, $this->end, $this->block_end, $this->name, $this->email, $this->phone, $this->notes, $id );
	}
}
