<?php
/**
 * Simulated businesses taking bookings for two weeks, to compare which start times to offer.
 *
 * Each business has staff with opening hours, a service menu and more demand than it can serve. Customers arrive in
 * random order. Each wants one service, on a preferred day or up to a few days later, at any time or within a window
 * of two to four hours, and either with anyone or with one named person. A customer looks at the times on offer and
 * takes the earliest one that suits (half of them) or one at random among those that suit (the other half); with no
 * suitable time, they leave.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Evaluation;

use AppointmentBooking\Core\Booker;
use AppointmentBooking\Core\BookingResult;
use AppointmentBooking\Core\Config;
use AppointmentBooking\Core\Hours;
use AppointmentBooking\Core\MemoryStore;
use AppointmentBooking\Core\Service;
use AppointmentBooking\Core\SlotPolicy;
use AppointmentBooking\Core\Staff;

/**
 * One simulated business.
 */
final class Simulation {

	public const DAYS = 14;

	private const MENUS = array(
		'salon'  => array( array( 'Cut', 30, 0, 35 ), array( 'Cut and finish', 45, 0, 50 ), array( 'Colour', 60, 15, 80 ), array( 'Colour and cut', 90, 15, 120 ) ),
		'clinic' => array( array( 'Check-up', 15, 0, 40 ), array( 'Consultation', 30, 0, 70 ), array( 'Treatment', 45, 0, 95 ), array( 'Long treatment', 60, 0, 120 ) ),
		'spa'    => array( array( 'Express facial', 30, 0, 45 ), array( 'Massage', 60, 15, 85 ), array( 'Long massage', 90, 15, 120 ) ),
		'tutor'  => array( array( 'Trial lesson', 20, 10, 0 ), array( 'Lesson', 50, 10, 60 ), array( 'Double lesson', 100, 10, 110 ) ),
	);

	private const ZONES = array( 'Europe/London', 'America/New_York', 'Australia/Sydney', 'Europe/Berlin', 'America/Chicago', 'America/Los_Angeles' );

	private const STARTS = array( '2026-03-02', '2026-03-23', '2026-05-11', '2026-09-28', '2026-10-19', '2026-11-16' );

	public Config $config;

	/**
	 * Customers.
	 *
	 * @var list<array{service: string, staff: string, days: list<string>, from: int, to: int, earliest: bool, seed: int}>
	 */
	public array $customers = array();

	public int $open_minutes = 0;

	public int $now;

	/**
	 * Build a business.
	 *
	 * @param int  $seed      Seed.
	 * @param bool $different The held-back shape: more staff, 10-minute granularity, heavier demand, narrower windows.
	 */
	public function __construct( public readonly int $seed, bool $different = false ) {
		mt_srand( $seed );
		$menu_key = array_keys( self::MENUS )[ mt_rand( 0, count( self::MENUS ) - 1 ) ];
		$tz       = new \DateTimeZone( self::ZONES[ mt_rand( 0, count( self::ZONES ) - 1 ) ] );
		$services = array();
		foreach ( self::MENUS[ $menu_key ] as [ $name, $duration, $buffer, $price ] ) {
			$s                  = new Service( Config::slug( $name ), $name, $duration, $buffer, $price * 100 );
			$services[ $s->id ] = $s;
		}
		$hours_options = array(
			'Mon-Fri 09:00-17:00',
			'Mon-Fri 09:00-12:30, 13:30-17:30; Sat 10:00-14:00',
			'Tue-Sat 10:00-18:00',
			'Mon-Thu 08:00-16:00; Fri 08:00-13:00',
			'Mon-Fri 12:00-20:00; Sat 09:00-15:00',
		);
		$n_staff       = $different ? mt_rand( 5, 7 ) : mt_rand( 2, 4 );
		$staff         = array();
		$ids           = array_keys( $services );
		for ( $i = 0; $i < $n_staff; $i++ ) {
			$offers = $ids;
			if ( count( $ids ) > 2 && mt_rand( 0, 2 ) === 0 ) {
				array_pop( $offers ); // Not everyone offers the longest service.
			}
			$p               = new Staff( 'staff-' . $i, 'Staff ' . ( $i + 1 ), Hours::parse( $hours_options[ mt_rand( 0, count( $hours_options ) - 1 ) ] ), $offers );
			$staff[ $p->id ] = $p;
		}
		$granularity  = $different ? 10 : 15;
		$this->config = new Config( $tz, $services, $staff, $granularity, 0, 60 );
		$start_date   = self::STARTS[ mt_rand( 0, count( self::STARTS ) - 1 ) ];
		$first        = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $tz );
		assert( false !== $first );
		$this->now = $first->getTimestamp() - 86400;
		$dates     = array();
		for ( $d = 0; $d < self::DAYS; $d++ ) {
			$dates[] = $first->modify( "+{$d} days" )->format( 'Y-m-d' );
		}
		$mean_block = 0;
		foreach ( $services as $s ) {
			$mean_block += $s->block() / count( $services );
		}
		foreach ( $staff as $p ) {
			foreach ( $dates as $date ) {
				foreach ( \AppointmentBooking\Core\Availability::open_ranges( $this->config, $p, $date ) as [ $a, $b ] ) {
					$this->open_minutes += intdiv( $b - $a, 60 );
				}
			}
		}
		$load  = $different ? mt_rand( 160, 200 ) / 100 : mt_rand( 110, 150 ) / 100;
		$count = (int) round( $this->open_minutes * $load / $mean_block );
		$any   = $different ? 0.15 : 0.35;
		for ( $i = 0; $i < $count; $i++ ) {
			$day  = mt_rand( 0, self::DAYS - 1 );
			$flex = array( 0, 0, 1, 1, 2, 3 )[ mt_rand( 0, 5 ) ];
			$days = array_slice( $dates, $day, $flex + 1 );
			if ( mt_rand( 0, 999 ) / 1000 < $any ) {
				$from = 0;
				$to   = 1440;
			} else {
				$from = mt_rand( 8 * 4, 18 * 4 ) * 15;
				$to   = $from + mt_rand( 2, 4 ) * 60;
			}
			$this->customers[] = array(
				'service'  => $ids[ mt_rand( 0, count( $ids ) - 1 ) ],
				'staff'    => mt_rand( 0, 4 ) === 0 ? array_keys( $staff )[ mt_rand( 0, $n_staff - 1 ) ] : '',
				'days'     => $days,
				'from'     => $from,
				'to'       => $to,
				'earliest' => mt_rand( 0, 1 ) === 0,
				'seed'     => mt_rand(),
			);
		}
	}

	/**
	 * Run the two weeks with one way of offering times.
	 *
	 * @param SlotPolicy $policy Slot policy.
	 * @param bool       $fit    Give "anyone" bookings to the person they strand the least time for.
	 * @return array{customers: int, booked: int, turned_away: int, booked_minutes: int, open_minutes: int, revenue: int, options: float}
	 */
	public function run( SlotPolicy $policy, bool $fit ): array {
		$store   = new MemoryStore();
		$booker  = new Booker( $this->config, $store, $policy, $fit );
		$avail   = $booker->availability();
		$booked  = 0;
		$minutes = 0;
		$revenue = 0;
		$shown   = 0;
		foreach ( $this->customers as $c ) {
			$service = $this->config->services[ $c['service'] ];
			if ( '' !== $c['staff'] && ! in_array( $c['service'], $this->config->staff[ $c['staff'] ]->services, true ) ) {
				$c['staff'] = '';
			}
			$chosen = null;
			foreach ( $c['days'] as $date ) {
				$slots = $avail->slots( $service, $date, $this->now, '' === $c['staff'] ? null : $c['staff'] );
				$day   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $this->config->timezone );
				assert( false !== $day );
				$midnight = $day->getTimestamp();
				$suits    = array();
				foreach ( array_keys( $slots ) as $t ) {
					$local = (int) ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $this->config->timezone )->format( 'G' ) * 60 + (int) ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $this->config->timezone )->format( 'i' );
					if ( $local >= $c['from'] && $local + $service->duration <= $c['to'] && $t >= $midnight ) {
						$suits[] = $t;
					}
				}
				$shown += count( $slots );
				if ( $suits ) {
					$chosen = $c['earliest'] ? $suits[0] : $suits[ $c['seed'] % count( $suits ) ];
					break;
				}
			}
			if ( null === $chosen ) {
				continue;
			}
			$result = $booker->book(
				array(
					'service' => $c['service'],
					'staff'   => $c['staff'],
					'start'   => $chosen,
					'name'    => 'Customer',
					'email'   => 'customer@example.com',
				),
				$this->now
			);
			if ( BookingResult::BOOKED === $result->status ) {
				++$booked;
				$minutes += $service->duration;
				$revenue += $service->price;
			}
		}
		$n = count( $this->customers );
		return array(
			'customers'      => $n,
			'booked'         => $booked,
			'turned_away'    => $n - $booked,
			'booked_minutes' => $minutes,
			'open_minutes'   => $this->open_minutes,
			'revenue'        => $revenue,
			'options'        => $n ? round( $shown / $n, 1 ) : 0.0,
		);
	}
}
