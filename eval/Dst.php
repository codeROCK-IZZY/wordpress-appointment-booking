<?php
/**
 * Are opening hours honoured on every day of the year, including the days the clocks change?
 *
 * A business in each timezone opens 09:00-17:00 local every day of 2026 and offers 30-minute appointments. For each
 * day, the plugin's start times are converted back to local wall-clock time with PHP's timezone database and checked:
 * exactly the sixteen starts from 09:00 to 16:30. The baseline is the shortcut many booking scripts take: work out
 * the UTC offset once (here on 1 January) and add it to every date.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Evaluation;

use AppointmentBooking\Core\Availability;
use AppointmentBooking\Core\Config;
use AppointmentBooking\Core\Hours;
use AppointmentBooking\Core\MemoryStore;
use AppointmentBooking\Core\Service;
use AppointmentBooking\Core\SlotPolicy;
use AppointmentBooking\Core\Staff;

/**
 * Daylight-saving check.
 */
final class Dst {

	public const ZONES = array( 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'America/Sao_Paulo', 'America/Santiago', 'Australia/Sydney', 'Pacific/Auckland', 'Asia/Kolkata', 'Africa/Lagos', 'Asia/Tokyo' );

	/**
	 * Days with wrong start times, per timezone, for the plugin and the fixed-offset shortcut.
	 *
	 * @return array<string, array{days: int, dst: bool, plugin_wrong: int, fixed_offset_wrong: int}>
	 */
	public static function run(): array {
		$expected = array();
		for ( $m = 9 * 60; $m + 30 <= 17 * 60; $m += 30 ) {
			$expected[] = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
		}
		$out = array();
		foreach ( self::ZONES as $zone ) {
			$tz      = new \DateTimeZone( $zone );
			$service = new Service( 'visit', 'Visit', 30 );
			$person  = new Staff( 'p', 'P', Hours::parse( 'Mon-Sun 09:00-17:00' ), array( 'visit' ) );
			$config  = new Config( $tz, array( 'visit' => $service ), array( 'p' => $person ), 30, 0, 400 );
			$avail   = new Availability( $config, new MemoryStore(), new SlotPolicy( 'every' ) );
			$jan     = new \DateTimeImmutable( '2026-01-01 12:00', $tz );
			$offset  = $tz->getOffset( $jan );
			$row     = array(
				'days'               => 0,
				'dst'                => false,
				'plugin_wrong'       => 0,
				'fixed_offset_wrong' => 0,
			);
			$offsets = array();
			$day     = new \DateTimeImmutable( '2026-01-01', $tz );
			for ( $i = 0; $i < 365; $i++, $day = $day->modify( '+1 day' ) ) {
				$date = $day->format( 'Y-m-d' );
				++$row['days'];
				$offsets[ $tz->getOffset( $day->setTime( 12, 0 ) ) ] = true;
				$local  = static fn ( int $t ): string => ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->format( 'Y-m-d H:i' );
				$plugin = array_map( $local, $avail->starts_for( $person, $service, $date, 0 ) );
				$naive  = array();
				$utc0   = ( new \DateTimeImmutable( $date . ' 00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() - $offset;
				foreach ( $expected as $k => $unused ) {
					$naive[] = $local( $utc0 + ( 9 * 60 + 30 * $k ) * 60 );
				}
				$want                       = array_map( static fn ( string $hm ): string => $date . ' ' . $hm, $expected );
				$row['plugin_wrong']       += $plugin === $want ? 0 : 1;
				$row['fixed_offset_wrong'] += $naive === $want ? 0 : 1;
			}
			$row['dst']   = count( $offsets ) > 1;
			$out[ $zone ] = $row;
		}
		return $out;
	}
}
