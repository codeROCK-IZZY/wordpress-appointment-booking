<?php
/**
 * The outcome of a booking attempt.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Booking result.
 */
final class BookingResult {

	public const BOOKED      = 'booked';
	public const INVALID     = 'invalid';
	public const UNAVAILABLE = 'unavailable';
	public const TAKEN       = 'taken';

	/**
	 * Constructor.
	 *
	 * @param string                $status  One of the constants.
	 * @param Booking|null          $booking The booking, when booked.
	 * @param array<string, string> $errors  Field => message.
	 */
	public function __construct( public readonly string $status, public readonly ?Booking $booking = null, public readonly array $errors = array() ) {}

	/**
	 * HTTP status for the REST API.
	 */
	public function http_status(): int {
		return match ( $this->status ) {
			self::BOOKED  => 201,
			self::INVALID => 422,
			default       => 409,
		};
	}
}
