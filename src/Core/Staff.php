<?php
/**
 * A person (or room, or chair) that appointments are booked with.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Staff value object.
 */
final class Staff {

	/**
	 * Constructor.
	 *
	 * @param string       $id       Id.
	 * @param string       $name     Name.
	 * @param Hours        $hours    Weekly hours in the business timezone.
	 * @param list<string> $services Service ids this person offers.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly Hours $hours,
		public readonly array $services
	) {}
}
