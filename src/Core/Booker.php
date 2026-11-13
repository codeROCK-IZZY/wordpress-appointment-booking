<?php
/**
 * Taking a booking: validate it, check the time is still offered, choose the staff member, and claim the time.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Booking flow, independent of WordPress.
 */
final class Booker {

	private Availability $availability;

	/**
	 * Constructor.
	 *
	 * @param Config       $config Business.
	 * @param BookingStore $store  Storage.
	 * @param SlotPolicy   $policy Slot policy.
	 * @param bool         $fit    For "anyone": give the booking to the person it strands the least time for (true),
	 *                             or the first person listed (false, used as a baseline in the evaluation).
	 */
	public function __construct( private Config $config, private BookingStore $store, SlotPolicy $policy, private bool $fit = true ) {
		$this->availability = new Availability( $config, $store, $policy );
	}

	/**
	 * The availability this booker checks against.
	 */
	public function availability(): Availability {
		return $this->availability;
	}

	/**
	 * Book.
	 *
	 * @param array<string, mixed> $input service, staff ('' for anyone), start (Unix or ISO 8601), name, email, phone, notes.
	 * @param int                  $now   Current time.
	 */
	public function book( array $input, int $now ): BookingResult {
		$errors  = array();
		$service = $this->config->services[ (string) ( $input['service'] ?? '' ) ] ?? null;
		if ( null === $service ) {
			$errors['service'] = 'Choose a service.';
		}
		$staff = (string) ( $input['staff'] ?? '' );
		if ( 'any' === $staff ) {
			$staff = '';
		}
		if ( '' !== $staff && ( ! isset( $this->config->staff[ $staff ] ) || ( null !== $service && ! in_array( $service->id, $this->config->staff[ $staff ]->services, true ) ) ) ) {
			$errors['staff'] = 'That person does not offer this service.';
		}
		$start = self::instant( $input['start'] ?? null );
		if ( null === $start ) {
			$errors['start'] = 'Choose a time.';
		}
		$name  = trim( (string) ( $input['name'] ?? '' ) );
		$email = trim( (string) ( $input['email'] ?? '' ) );
		$phone = trim( (string) ( $input['phone'] ?? '' ) );
		$notes = trim( (string) ( $input['notes'] ?? '' ) );
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			$errors['name'] = 'Enter your name (up to 100 characters).';
		}
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $email ) > 190 ) {
			$errors['email'] = 'Enter a valid email address.';
		}
		if ( '' !== $phone && ! preg_match( '/^[0-9+()\-. ]{5,40}$/', $phone ) ) {
			$errors['phone'] = 'Enter a phone number using digits, spaces and + ( ) - only.';
		}
		if ( mb_strlen( $notes ) > 1000 ) {
			$errors['notes'] = 'Notes can be up to 1,000 characters.';
		}
		if ( $errors || null === $service || null === $start ) {
			return new BookingResult( BookingResult::INVALID, null, $errors );
		}

		$latest = $now + $this->config->horizon * 86400;
		$slots  = $start <= $latest ? $this->availability->slots( $service, $this->availability->local_date( $start ), $now, '' === $staff ? null : $staff ) : array();
		if ( ! isset( $slots[ $start ] ) ) {
			return new BookingResult( BookingResult::UNAVAILABLE, null, array( 'start' => 'That time is not available. Please choose another.' ) );
		}
		$candidates = $slots[ $start ];
		if ( $this->fit && count( $candidates ) > 1 ) {
			$waste = array();
			foreach ( $candidates as $id ) {
				$waste[ $id ] = $this->availability->stranded( $this->config->staff[ $id ], $service, $start, $now );
			}
			usort( $candidates, static fn ( string $a, string $b ): int => $waste[ $a ] <=> $waste[ $b ] );
		}
		foreach ( $candidates as $id ) {
			$booking = new Booking( $service->id, $id, $start, $start + $service->duration * 60, $start + $service->block() * 60, $name, $email, $phone, $notes );
			$new_id  = $this->store->claim( $booking );
			if ( null !== $new_id ) {
				return new BookingResult( BookingResult::BOOKED, $booking->with_id( $new_id ) );
			}
		}
		return new BookingResult( BookingResult::TAKEN, null, array( 'start' => 'Someone has just booked that time. Please choose another.' ) );
	}

	/**
	 * An instant from Unix seconds or an ISO 8601 string with an offset.
	 *
	 * @param mixed $value Value.
	 */
	public static function instant( mixed $value ): ?int {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return (int) $value;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
