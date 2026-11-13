<?php
/**
 * Which start times to offer for a service on a day.
 *
 * Opening hours are wall-clock times in the business timezone and are turned into instants for each date separately,
 * so a day that changes to or from daylight saving time still opens at 09:00 local. Everything after that works in
 * Unix seconds.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Core;

/**
 * Availability for one business.
 */
final class Availability {

	/**
	 * Constructor.
	 *
	 * @param Config       $config Business.
	 * @param BookingStore $store  Existing bookings.
	 * @param SlotPolicy   $policy Which feasible starts to offer.
	 */
	public function __construct( private Config $config, private BookingStore $store, private SlotPolicy $policy ) {}

	/**
	 * Opening ranges for a person on a local date, as [start, end) instants.
	 *
	 * @param Config $config Business.
	 * @param Staff  $person Staff member.
	 * @param string $date   Local date, Y-m-d.
	 * @return list<array{0: int, 1: int}>
	 */
	public static function open_ranges( Config $config, Staff $person, string $date ): array {
		if ( in_array( $date, $config->closures, true ) ) {
			return array();
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $config->timezone );
		if ( false === $day ) {
			return array();
		}
		$out = array();
		foreach ( $person->hours->on( (int) $day->format( 'N' ) ) as [ $open, $close ] ) {
			$out[] = array( self::at( $day, $open ), self::at( $day, $close ) );
		}
		return $out;
	}

	/**
	 * The instant of a wall-clock minute on a local day (24:00 is the next midnight).
	 *
	 * @param \DateTimeImmutable $day    Local midnight.
	 * @param int                $minute Minutes after midnight.
	 */
	private static function at( \DateTimeImmutable $day, int $minute ): int {
		if ( $minute >= 1440 ) {
			return $day->modify( '+1 day' )->getTimestamp();
		}
		return $day->setTime( intdiv( $minute, 60 ), $minute % 60 )->getTimestamp();
	}

	/**
	 * Free intervals for a person on a date: opening ranges minus bookings and the minimum-notice window.
	 *
	 * @param Staff  $person Staff member.
	 * @param string $date   Local date.
	 * @param int    $now    Current time.
	 * @return list<array{0: int, 1: int, 2: int}> [start, end, opening time of the range it is in].
	 */
	public function free( Staff $person, string $date, int $now ): array {
		$ranges = self::open_ranges( $this->config, $person, $date );
		if ( ! $ranges ) {
			return array();
		}
		$busy     = $this->store->busy( $person->id, $ranges[0][0], $ranges[ count( $ranges ) - 1 ][1] );
		$earliest = $now + $this->config->notice * 60;
		$out      = array();
		foreach ( $ranges as [ $open, $close ] ) {
			$cursor = $open;
			foreach ( $busy as [ $bs, $be ] ) {
				if ( $be <= $cursor || $bs >= $close ) {
					continue;
				}
				if ( $bs > $cursor ) {
					$out[] = array( $cursor, $bs, $open );
				}
				$cursor = max( $cursor, $be );
			}
			if ( $cursor < $close ) {
				$out[] = array( $cursor, $close, $open );
			}
		}
		$clipped = array();
		foreach ( $out as [ $a, $b, $anchor ] ) {
			$a = max( $a, $earliest );
			if ( $a < $b ) {
				$clipped[] = array( $a, $b, $anchor );
			}
		}
		return $clipped;
	}

	/**
	 * Start times to offer, each with the staff who could take it, in time order.
	 *
	 * @param Service     $service Service.
	 * @param string      $date    Local date.
	 * @param int         $now     Current time.
	 * @param string|null $staff   One person's id, or null for anyone who offers the service.
	 * @return array<int, list<string>> Start instant => staff ids.
	 */
	public function slots( Service $service, string $date, int $now, ?string $staff = null ): array {
		$out = array();
		foreach ( $this->config->staff_for( $service->id ) as $person ) {
			if ( null !== $staff && $person->id !== $staff ) {
				continue;
			}
			foreach ( $this->starts_for( $person, $service, $date, $now ) as $t ) {
				$out[ $t ][] = $person->id;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Starts one person could take.
	 *
	 * @param Staff   $person  Staff member.
	 * @param Service $service Service.
	 * @param string  $date    Local date.
	 * @param int     $now     Current time.
	 * @return list<int>
	 */
	public function starts_for( Staff $person, Service $service, string $date, int $now ): array {
		$step  = $this->config->granularity * 60;
		$block = $service->block() * 60;
		$min   = $this->config->shortest_block( $person ) * 60;
		$out   = array();
		foreach ( $this->free( $person, $date, $now ) as [ $a, $b, $anchor ] ) {
			$first    = $anchor + (int) ceil( ( $a - $anchor ) / $step ) * $step;
			$feasible = array();
			for ( $t = $first; $t + $block <= $b; $t += $step ) {
				$feasible[] = $t;
			}
			foreach ( $this->policy->choose( $feasible, $a, $b, $block, $min, $anchor ) as $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Minutes of free time a booking would strand: gaps on either side that are too short for any service.
	 *
	 * @param Staff   $person  Staff member.
	 * @param Service $service Service.
	 * @param int     $start   Start.
	 * @param int     $now     Current time.
	 */
	public function stranded( Staff $person, Service $service, int $start, int $now ): int {
		$date = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $this->config->timezone )->format( 'Y-m-d' );
		$min  = $this->config->shortest_block( $person ) * 60;
		$end  = $start + $service->block() * 60;
		foreach ( $this->free( $person, $date, $now ) as [ $a, $b ] ) {
			if ( $start >= $a && $end <= $b ) {
				$left  = $start - $a;
				$right = $b - $end;
				return intdiv( ( $left > 0 && $left < $min ? $left : 0 ) + ( $right > 0 && $right < $min ? $right : 0 ), 60 );
			}
		}
		return 0;
	}

	/**
	 * The local date of an instant.
	 *
	 * @param int $t Instant.
	 */
	public function local_date( int $t ): string {
		return ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $this->config->timezone )->format( 'Y-m-d' );
	}
}
