<?php
/**
 * Stored settings.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking;

use AppointmentBooking\Core\Config;
use AppointmentBooking\Core\Token;

/**
 * Settings access.
 */
final class Settings {

	public const OPTION = 'abk_settings';

	/**
	 * Defaults for a new site: one person, two services, the site's timezone.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'business'    => get_bloginfo( 'name' ),
			'location'    => '',
			'timezone'    => self::site_timezone(),
			'policy'      => 'gap_aware',
			'granularity' => 15,
			'notice'      => 120,
			'horizon'     => 30,
			'closures'    => array(),
			'services'    => array(
				array(
					'name'     => 'Consultation',
					'duration' => 30,
					'buffer'   => 0,
					'price'    => 40,
				),
				array(
					'name'     => 'Treatment',
					'duration' => 60,
					'buffer'   => 15,
					'price'    => 85,
				),
			),
			'staff'       => array(
				array(
					'name'     => 'Alex',
					'hours'    => 'Mon-Fri 09:00-17:00',
					'services' => 'all',
				),
			),
		);
	}

	/**
	 * The stored settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * The validated configuration.
	 *
	 * @param list<string> $errors Problems (by reference).
	 */
	public static function config( array &$errors = array() ): Config {
		return Config::from_array( self::get(), $errors );
	}

	/**
	 * The site's timezone as a name WordPress or PHP understands ("Europe/London", or "+01:00" for a fixed offset).
	 */
	public static function site_timezone(): string {
		return wp_timezone_string();
	}

	/**
	 * Whether a timezone is a fixed UTC offset (which never changes for daylight saving).
	 *
	 * @param string $tz Timezone.
	 */
	public static function is_fixed_offset( string $tz ): bool {
		return (bool) preg_match( '/^(UTC)?[+-]\d{1,2}(:?\d{2})?$/i', $tz ) && ! in_array( strtoupper( $tz ), array( 'UTC', '+00:00' ), true );
	}

	/**
	 * Manage-link tokens.
	 */
	public static function tokens(): Token {
		return new Token( wp_salt( 'auth' ) . 'abk' );
	}
}
