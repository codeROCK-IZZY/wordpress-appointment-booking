<?php
/**
 * REST API for the booking widget: services, days with free times, times on a day, booking, calendar file, cancel.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Rest;

use AppointmentBooking\Core\Availability;
use AppointmentBooking\Core\BookingResult;
use AppointmentBooking\Core\SlotPolicy;
use AppointmentBooking\Data\WpdbStore;
use AppointmentBooking\Mail\Mailer;
use AppointmentBooking\Plugin;
use AppointmentBooking\Settings;

/**
 * Routes under /abk/v1. All are public: anyone may see free times and book; a booking can only be read or cancelled
 * with the signed token from its confirmation email.
 */
final class Controller {

	public const NS = 'abk/v1';

	/**
	 * Successful bookings allowed per visitor address per hour.
	 */
	public const PER_HOUR = 5;

	/**
	 * Register routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public static function routes(): void {
		$public = '__return_true';
		register_rest_route(
			self::NS,
			'/services',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'services' ),
				'permission_callback' => $public,
			)
		);
		$query = array(
			'service' => array(
				'type'     => 'string',
				'required' => true,
			),
			'staff'   => array(
				'type'    => 'string',
				'default' => '',
			),
		);
		register_rest_route(
			self::NS,
			'/days',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'days' ),
				'permission_callback' => $public,
				'args'                => $query,
			)
		);
		register_rest_route(
			self::NS,
			'/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'slots' ),
				'permission_callback' => $public,
				'args'                => $query + array(
					'date' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2}$',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'book' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::NS,
			'/bookings/ics',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'ics' ),
				'permission_callback' => $public,
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bookings/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'cancel' ),
				'permission_callback' => $public,
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Services and staff.
	 */
	public static function services(): \WP_REST_Response {
		$c = Settings::config();
		return new \WP_REST_Response(
			array(
				'timezone' => $c->timezone->getName(),
				'business' => (string) Settings::get()['business'],
				'horizon'  => $c->horizon,
				'services' => array_values(
					array_map(
						static fn ( $s ): array => array(
							'id'       => $s->id,
							'name'     => $s->name,
							'duration' => $s->duration,
							'price'    => $s->price / 100,
						),
						array_filter( $c->services, static fn ( $s ): bool => (bool) $c->staff_for( $s->id ) )
					)
				),
				'staff'    => array_values(
					array_map(
						static fn ( $p ): array => array(
							'id'       => $p->id,
							'name'     => $p->name,
							'services' => $p->services,
						),
						$c->staff
					)
				),
			)
		);
	}

	/**
	 * The next days, each with how many times are free.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $req Request.
	 */
	public static function days( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$c       = Settings::config();
		$service = $c->services[ (string) $req['service'] ] ?? null;
		if ( null === $service ) {
			return new \WP_Error( 'abk_service', __( 'Unknown service.', 'appointment-booking' ), array( 'status' => 404 ) );
		}
		$avail = new Availability( $c, Plugin::store(), new SlotPolicy( $c->policy ) );
		$now   = time();
		$day   = new \DateTimeImmutable( '@' . $now );
		$day   = $day->setTimezone( $c->timezone );
		$out   = array();
		$count = min( $c->horizon, 60 );
		for ( $i = 0; $i < $count; $i++ ) {
			$date  = $day->modify( "+{$i} days" )->format( 'Y-m-d' );
			$out[] = array(
				'date'  => $date,
				'label' => (string) wp_date( 'D j M', $day->modify( "+{$i} days" )->setTime( 12, 0 )->getTimestamp(), $c->timezone ),
				'free'  => count( $avail->slots( $service, $date, $now, '' === (string) $req['staff'] ? null : (string) $req['staff'] ) ),
			);
		}
		return new \WP_REST_Response(
			array(
				'timezone' => $c->timezone->getName(),
				'days'     => $out,
			)
		);
	}

	/**
	 * Free start times on a day.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $req Request.
	 */
	public static function slots( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$c       = Settings::config();
		$service = $c->services[ (string) $req['service'] ] ?? null;
		if ( null === $service ) {
			return new \WP_Error( 'abk_service', __( 'Unknown service.', 'appointment-booking' ), array( 'status' => 404 ) );
		}
		$avail = new Availability( $c, Plugin::store(), new SlotPolicy( $c->policy ) );
		$slots = array();
		foreach ( $avail->slots( $service, (string) $req['date'], time(), '' === (string) $req['staff'] ? null : (string) $req['staff'] ) as $t => $staff ) {
			$slots[] = array(
				'start' => $t,
				'iso'   => gmdate( 'Y-m-d\TH:i:s\Z', $t ),
				'label' => (string) wp_date( (string) get_option( 'time_format', 'g:i a' ), $t, $c->timezone ),
				'staff' => $staff,
			);
		}
		return new \WP_REST_Response(
			array(
				'date'     => (string) $req['date'],
				'timezone' => $c->timezone->getName(),
				'slots'    => $slots,
			)
		);
	}

	/**
	 * Book.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $req Request.
	 */
	public static function book( \WP_REST_Request $req ): \WP_REST_Response {
		if ( '' !== trim( (string) $req->get_param( 'abk_website' ) ) ) {
			// A honeypot field people never see: answer like a success, store nothing.
			return new \WP_REST_Response( array( 'ok' => true ), 201 );
		}
		$key   = 'abk_rate_' . md5( self::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= (int) apply_filters( 'appointment_booking_per_hour', self::PER_HOUR ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Too many bookings from this connection. Please call us instead.', 'appointment-booking' ),
				),
				429
			);
		}
		$input = array();
		foreach ( array( 'service', 'staff', 'start', 'name', 'email', 'phone', 'notes' ) as $field ) {
			$value           = $req->get_param( $field );
			$input[ $field ] = is_scalar( $value ) ? ( 'notes' === $field ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value ) ) : '';
		}
		$result = Plugin::booker()->book( $input, time() );
		if ( BookingResult::BOOKED !== $result->status || null === $result->booking ) {
			return new \WP_REST_Response(
				array(
					'ok'     => false,
					'status' => $result->status,
					'errors' => (object) $result->errors,
				),
				$result->http_status()
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$id   = $result->booking->id;
		$page = esc_url_raw( (string) $req->get_param( 'page_url' ) );
		if ( '' !== $page && str_starts_with( $page, home_url() ) ) {
			WpdbStore::set_page( $id, $page );
		}
		Mailer::confirmed( $id );
		$row = WpdbStore::find( $id );
		$d   = Mailer::details( (array) $row );
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'id'      => $id,
				'service' => $d['service'],
				'staff'   => $d['staff'],
				'when'    => $d['when'],
				'start'   => $result->booking->start,
				'manage'  => $d['manage'],
				'ics'     => add_query_arg( 'token', rawurlencode( Settings::tokens()->issue( $id, $result->booking->email ) ), rest_url( self::NS . '/bookings/ics' ) ),
			),
			201
		);
	}

	/**
	 * The booking a token belongs to.
	 *
	 * @param string $token Token.
	 * @return array<string, string>|null
	 */
	public static function from_token( string $token ): ?array {
		$id = Settings::tokens()->verify(
			$token,
			static function ( int $id ): ?string {
				$row = WpdbStore::find( $id );
				return null === $row ? null : $row['email'];
			}
		);
		return null === $id ? null : WpdbStore::find( $id );
	}

	/**
	 * Calendar file download.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $req Request.
	 */
	public static function ics( \WP_REST_Request $req ): \WP_Error|null {
		$row = self::from_token( (string) $req['token'] );
		if ( null === $row ) {
			return new \WP_Error( 'abk_token', __( 'That link is not valid.', 'appointment-booking' ), array( 'status' => 404 ) );
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="appointment.ics"' );
		echo Mailer::ics( $row, 'cancelled' === $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A calendar file, not HTML.
		exit;
	}

	/**
	 * Cancel with a token.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $req Request.
	 */
	public static function cancel( \WP_REST_Request $req ): \WP_REST_Response {
		$row = self::from_token( (string) $req['token'] );
		if ( null === $row ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'That link is not valid.', 'appointment-booking' ),
				),
				404
			);
		}
		$result = self::cancel_row( $row );
		return new \WP_REST_Response(
			array(
				'ok'      => true === $result,
				'message' => true === $result ? __( 'Your booking is cancelled.', 'appointment-booking' ) : $result,
			),
			true === $result ? 200 : 409
		);
	}

	/**
	 * Cancel a booking if the notice period allows. Returns true, or the reason it cannot be cancelled online.
	 *
	 * @param array<string, string> $row Booking row.
	 */
	public static function cancel_row( array $row ): bool|string {
		if ( 'confirmed' !== $row['status'] ) {
			return __( 'This booking is already cancelled.', 'appointment-booking' );
		}
		if ( (int) $row['start_at'] - time() < Settings::config()->notice * 60 ) {
			return __( 'It is too close to the appointment to cancel online. Please call us.', 'appointment-booking' );
		}
		if ( ! WpdbStore::cancel( (int) $row['id'] ) ) {
			return __( 'This booking is already cancelled.', 'appointment-booking' );
		}
		Mailer::cancelled( (int) $row['id'] );
		return true;
	}

	/**
	 * The visitor's address, for rate limiting only (never stored).
	 */
	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
