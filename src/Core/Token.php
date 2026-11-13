<?php
/**
 * Signed links that let a customer view or cancel their own booking without an account.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Manage-link token: "<booking id>.<HMAC>".
 */
final class Token {

	/**
	 * Constructor.
	 *
	 * @param string $secret Site secret.
	 */
	public function __construct( private string $secret ) {}

	/**
	 * Token for a booking.
	 *
	 * @param int    $id    Booking id.
	 * @param string $email Customer email (so a token cannot be reused if ids are ever recycled).
	 */
	public function issue( int $id, string $email ): string {
		return $id . '.' . hash_hmac( 'sha256', 'booking:' . $id . ':' . strtolower( $email ), $this->secret );
	}

	/**
	 * The booking id a token was issued for, if it is genuine for that email.
	 *
	 * @param string   $token Token.
	 * @param callable $email Booking id => that booking's email, or null if there is no such booking.
	 */
	public function verify( string $token, callable $email ): ?int {
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{64})$/', $token, $m ) ) {
			return null;
		}
		$id      = (int) $m[1];
		$address = $email( $id );
		if ( ! is_string( $address ) ) {
			return null;
		}
		return hash_equals( $this->issue( $id, $address ), $token ) ? $id : null;
	}
}
