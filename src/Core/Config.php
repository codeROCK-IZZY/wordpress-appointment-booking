<?php
/**
 * The business: its timezone, services, staff and booking rules.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Validated configuration. Built from the settings option (an array) so the core never calls WordPress.
 */
final class Config {

	public const POLICIES = array( 'gap_aware', 'every', 'grid' );

	/**
	 * Constructor.
	 *
	 * @param \DateTimeZone               $timezone    Business timezone.
	 * @param array<string, Service>      $services    Services by id.
	 * @param array<string, Staff>        $staff       Staff by id.
	 * @param int                         $granularity Minutes between possible start times.
	 * @param int                         $notice      Minimum notice in minutes.
	 * @param int                         $horizon     Days ahead that can be booked.
	 * @param list<string>                $closures    Local dates (Y-m-d) the business is closed.
	 * @param string                      $policy      Slot policy (see SlotPolicy).
	 */
	public function __construct(
		public readonly \DateTimeZone $timezone,
		public readonly array $services,
		public readonly array $staff,
		public readonly int $granularity = 15,
		public readonly int $notice = 120,
		public readonly int $horizon = 30,
		public readonly array $closures = array(),
		public readonly string $policy = 'gap_aware'
	) {}

	/**
	 * Build from the stored settings. Invalid rows are skipped and reported in $errors.
	 *
	 * @param array<string, mixed> $s      Settings.
	 * @param list<string>         $errors Problems found (by reference).
	 */
	public static function from_array( array $s, array &$errors = array() ): self {
		try {
			$tz = new \DateTimeZone( is_string( $s['timezone'] ?? null ) && '' !== $s['timezone'] ? $s['timezone'] : 'UTC' );
		} catch ( \Exception $e ) {
			$errors[] = 'Unknown timezone; using UTC.';
			$tz       = new \DateTimeZone( 'UTC' );
		}
		$services = array();
		foreach ( is_array( $s['services'] ?? null ) ? $s['services'] : array() as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			$duration = (int) ( $row['duration'] ?? 0 );
			if ( $duration < 5 || $duration > 720 ) {
				$errors[] = sprintf( '%s: the duration must be 5 to 720 minutes.', $row['name'] );
				continue;
			}
			$svc                  = new Service( self::slug( (string) $row['name'] ), trim( (string) $row['name'] ), $duration, max( 0, (int) ( $row['buffer'] ?? 0 ) ), max( 0, (int) round( (float) ( $row['price'] ?? 0 ) * 100 ) ) );
			$services[ $svc->id ] = $svc;
		}
		$staff = array();
		foreach ( is_array( $s['staff'] ?? null ) ? $s['staff'] : array() as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			try {
				$hours = Hours::parse( (string) ( $row['hours'] ?? '' ) );
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = $row['name'] . ': ' . $e->getMessage();
				continue;
			}
			$offers = trim( (string) ( $row['services'] ?? 'all' ) );
			$ids    = '' === $offers || 'all' === strtolower( $offers ) ? array_keys( $services ) : array_values( array_filter( array_map( static fn ( string $n ): string => self::slug( $n ), explode( ',', $offers ) ), static fn ( string $id ): bool => isset( $services[ $id ] ) ) );
			$person = new Staff( self::slug( (string) $row['name'] ), trim( (string) $row['name'] ), $hours, $ids );

			$staff[ $person->id ] = $person;
		}
		$closures = array_values( array_filter( array_map( 'trim', is_array( $s['closures'] ?? null ) ? array_map( 'strval', $s['closures'] ) : explode( "\n", (string) ( $s['closures'] ?? '' ) ) ), static fn ( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) );
		$policy   = in_array( $s['policy'] ?? '', self::POLICIES, true ) ? (string) $s['policy'] : 'gap_aware';
		return new self( $tz, $services, $staff, max( 5, min( 60, (int) ( $s['granularity'] ?? 15 ) ) ), max( 0, (int) ( $s['notice'] ?? 120 ) ), max( 1, min( 365, (int) ( $s['horizon'] ?? 30 ) ) ), $closures, $policy );
	}

	/**
	 * Staff who offer a service.
	 *
	 * @param string $service Service id.
	 * @return list<Staff>
	 */
	public function staff_for( string $service ): array {
		return array_values( array_filter( $this->staff, static fn ( Staff $p ): bool => in_array( $service, $p->services, true ) ) );
	}

	/**
	 * The shortest block (duration + buffer) a person offers: gaps shorter than this can never be sold.
	 *
	 * @param Staff $person Staff member.
	 */
	public function shortest_block( Staff $person ): int {
		$blocks = array_map( fn ( string $id ): int => $this->services[ $id ]->block(), array_filter( $person->services, fn ( string $id ): bool => isset( $this->services[ $id ] ) ) );
		return $blocks ? min( $blocks ) : 0;
	}

	/**
	 * A URL- and key-safe id from a name.
	 *
	 * @param string $name Name.
	 */
	public static function slug( string $name ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( trim( $name ) ) ), '-' );
	}
}
