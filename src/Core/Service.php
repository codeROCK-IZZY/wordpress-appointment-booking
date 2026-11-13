<?php
/**
 * A bookable service.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Service value object. Times in minutes, price in cents.
 */
final class Service {

	/**
	 * Constructor.
	 *
	 * @param string $id       Id.
	 * @param string $name     Name.
	 * @param int    $duration Minutes with the customer.
	 * @param int    $buffer   Minutes after it (clean-up, notes) when nothing else can start.
	 * @param int    $price    Price in cents.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly int $duration,
		public readonly int $buffer = 0,
		public readonly int $price = 0
	) {}

	/**
	 * Minutes of the calendar this service takes.
	 */
	public function block(): int {
		return $this->duration + $this->buffer;
	}
}
