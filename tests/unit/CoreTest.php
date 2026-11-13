<?php
/**
 * Hours, configuration, availability across daylight saving, slot policies and the booking flow.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Tests;

use AppointmentBooking\Core\Availability;
use AppointmentBooking\Core\Booker;
use AppointmentBooking\Core\Booking;
use AppointmentBooking\Core\BookingResult;
use AppointmentBooking\Core\Config;
use AppointmentBooking\Core\Hours;
use AppointmentBooking\Core\MemoryStore;
use AppointmentBooking\Core\SlotPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Core tests.
 */
final class CoreTest extends TestCase {

	/**
	 * A small business in London: two people, three services.
	 *
	 * @param array<string, mixed> $over Overrides.
	 */
	private function config( array $over = array() ): Config {
		return Config::from_array(
			array_merge(
				array(
					'timezone'    => 'Europe/London',
					'granularity' => 15,
					'notice'      => 0,
					'horizon'     => 400,
					'services'    => array(
						array(
							'name'     => 'Cut',
							'duration' => 30,
						),
						array(
							'name'     => 'Colour',
							'duration' => 60,
							'buffer'   => 15,
							'price'    => 80,
						),
						array(
							'name'     => 'Long',
							'duration' => 90,
						),
					),
					'staff'       => array(
						array(
							'name'  => 'Ana',
							'hours' => 'Mon-Fri 09:00-12:00, 13:00-17:00',
						),
						array(
							'name'     => 'Ben',
							'hours'    => 'Mon-Sat 10:00-14:00',
							'services' => 'Cut, Colour',
						),
					),
				),
				$over
			)
		);
	}

	/**
	 * "Now" for booking tests: the day before the bookings.
	 */
	private function now(): int {
		return $this->at( '2026-06-01 00:00' );
	}

	/**
	 * A local time as an instant.
	 *
	 * @param string $local "Y-m-d H:i" in London.
	 */
	private function at( string $local ): int {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( 'Europe/London' ) ) )->getTimestamp();
	}

	/**
	 * Local "H:i" labels.
	 *
	 * @param list<int> $ts Instants.
	 * @return list<string>
	 */
	private function labels( array $ts ): array {
		return array_map( static fn ( int $t ): string => ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( new \DateTimeZone( 'Europe/London' ) )->format( 'H:i' ), $ts );
	}

	public function test_hours_parse_ranges_and_round_trip(): void {
		$h = Hours::parse( 'Mon-Fri 09:00-12:30, 13:30-17:00; Sat 10:00-14:00' );
		$this->assertSame( array( array( 540, 750 ), array( 810, 1020 ) ), $h->on( 3 ) );
		$this->assertSame( array( array( 600, 840 ) ), $h->on( 6 ) );
		$this->assertSame( array(), $h->on( 7 ) );
		$this->assertSame( $h->ranges, Hours::parse( $h->text() )->ranges );
		$this->assertSame( array( array( 540, 1020 ) ), Hours::parse( 'Mon 09:00-13:00, 12:00-17:00' )->on( 1 ), 'overlaps merge' );
	}

	public function test_hours_reject_what_they_cannot_read(): void {
		foreach ( array( 'Funday 09:00-17:00', 'Mon 9-5', 'Mon 17:00-09:00', 'Fri-Mon 09:00-10:00', 'Mon 09:00-25:00' ) as $bad ) {
			try {
				Hours::parse( $bad );
				$this->fail( 'accepted ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_config_skips_bad_rows_and_reports_them(): void {
		$errors = array();
		$c      = Config::from_array(
			array(
				'timezone' => 'Mars/Olympus',
				'services' => array(
					array(
						'name'     => 'Ok',
						'duration' => 30,
					),
					array(
						'name'     => 'Zero',
						'duration' => 0,
					),
				),
				'staff'    => array(
					array(
						'name'  => 'Good',
						'hours' => 'Mon 09:00-10:00',
					),
					array(
						'name'  => 'Bad',
						'hours' => 'whenever',
					),
				),
			),
			$errors
		);
		$this->assertSame( 'UTC', $c->timezone->getName() );
		$this->assertSame( array( 'ok' ), array_keys( $c->services ) );
		$this->assertSame( array( 'good' ), array_keys( $c->staff ) );
		$this->assertCount( 3, $errors );
		$this->assertSame( 'gap_aware', $c->policy );
	}

	public function test_opening_hours_follow_daylight_saving(): void {
		$c   = $this->config();
		$ana = $c->staff['ana'];
		foreach ( array( '2026-03-27', '2026-03-30', '2026-10-23', '2026-10-26' ) as $date ) { // Fridays and Mondays either side of both changes.
			$ranges = Availability::open_ranges( $c, $ana, $date );
			$this->assertSame( array( '09:00', '12:00' ), $this->labels( $ranges[0] ), $date );
			$this->assertSame( $this->at( $date . ' 09:00' ), $ranges[0][0] );
		}
		$this->assertSame( 3 * 86400 - 3600, $this->at( '2026-03-30 09:00' ) - $this->at( '2026-03-27 09:00' ), 'the clocks went forward: 09:00 Monday is 71 hours after 09:00 Friday' );
	}

	public function test_closures_and_days_off(): void {
		$c = $this->config( array( 'closures' => "2026-06-01\nnot a date" ) );
		$this->assertSame( array( '2026-06-01' ), $c->closures );
		$this->assertSame( array(), Availability::open_ranges( $c, $c->staff['ana'], '2026-06-01' ) );
		$this->assertSame( array(), Availability::open_ranges( $c, $c->staff['ana'], '2026-06-06' ), 'Saturday' );
		$this->assertCount( 1, Availability::open_ranges( $c, $c->staff['ben'], '2026-06-06' ) );
	}

	public function test_every_policy_offers_every_fitting_start(): void {
		$c     = $this->config();
		$avail = new Availability( $c, new MemoryStore(), new SlotPolicy( 'every' ) );
		$cut   = $this->labels( $avail->starts_for( $c->staff['ana'], $c->services['cut'], '2026-06-02', 0 ) );
		$this->assertSame( '09:00', $cut[0] );
		$this->assertSame( '11:30', $cut[10] );
		$this->assertContains( '13:00', $cut );
		$this->assertSame( '16:30', end( $cut ) );
		$this->assertCount( 11 + 15, $cut );
		$colour = $this->labels( $avail->starts_for( $c->staff['ana'], $c->services['colour'], '2026-06-02', 0 ) );
		$this->assertSame( '10:45', $colour[7], 'a 75-minute block must end by 12:00' );
		$this->assertNotContains( '11:00', $colour );
	}

	public function test_bookings_and_notice_remove_times(): void {
		$c     = $this->config( array( 'notice' => 60 ) );
		$store = new MemoryStore();
		$store->claim( new Booking( 'colour', 'ana', $this->at( '2026-06-02 10:00' ), $this->at( '2026-06-02 11:00' ), $this->at( '2026-06-02 11:15' ) ) );
		$avail = new Availability( $c, $store, new SlotPolicy( 'every' ) );
		$cut   = $this->labels( $avail->starts_for( $c->staff['ana'], $c->services['cut'], '2026-06-02', $this->at( '2026-06-02 08:15' ) ) );
		$this->assertSame( '09:15', $cut[0], 'an hour of notice' );
		$this->assertContains( '09:30', $cut );
		$this->assertNotContains( '09:45', $cut, 'would run into the booking' );
		$this->assertNotContains( '11:00', $cut, 'the buffer is busy' );
		$this->assertContains( '11:15', $cut );
	}

	public function test_gap_aware_skips_starts_that_strand_short_gaps(): void {
		$c     = $this->config();
		$store = new MemoryStore();
		// Ben's day: 10:00-14:00. Book 10:00-10:30, leaving 10:30-14:00 free.
		$store->claim( new Booking( 'cut', 'ben', $this->at( '2026-06-02 10:00' ), $this->at( '2026-06-02 10:30' ), $this->at( '2026-06-02 10:30' ) ) );
		$every = $this->labels( ( new Availability( $c, $store, new SlotPolicy( 'every' ) ) )->starts_for( $c->staff['ben'], $c->services['colour'], '2026-06-02', 0 ) );
		$gap   = $this->labels( ( new Availability( $c, $store, new SlotPolicy( 'gap_aware', 1.0 ) ) )->starts_for( $c->staff['ben'], $c->services['colour'], '2026-06-02', 0 ) );
		$this->assertContains( '10:45', $every );
		$this->assertNotContains( '10:45', $gap, 'would strand 15 minutes before it; Ben\'s shortest service is 30' );
		$this->assertContains( '10:30', $gap, 'the earliest start is always offered' );
		$this->assertContains( '12:45', $gap, 'the latest start is always offered' );
		$this->assertContains( '11:00', $gap, 'leaves 30 minutes before: enough for a cut' );
		$grid = $this->labels( ( new Availability( $c, $store, new SlotPolicy( 'grid' ) ) )->starts_for( $c->staff['ben'], $c->services['colour'], '2026-06-02', 0 ) );
		$this->assertSame( array( '11:15', '12:30' ), $grid, 'a 75-minute grid from 10:00' );
	}

	public function test_slots_merge_staff_and_book_rejects_times_not_offered(): void {
		$c      = $this->config();
		$booker = new Booker( $c, new MemoryStore(), new SlotPolicy( 'every' ) );
		$slots  = $booker->availability()->slots( $c->services['cut'], '2026-06-02', 0 );
		$this->assertSame( array( 'ana', 'ben' ), $slots[ $this->at( '2026-06-02 10:00' ) ] );
		$this->assertSame( array( 'ana' ), $slots[ $this->at( '2026-06-02 09:00' ) ] );
		$this->assertSame( array( 'ben' ), $slots[ $this->at( '2026-06-02 12:15' ) ], 'Ana is at lunch, Ben is not' );
		$this->assertArrayNotHasKey( $this->at( '2026-06-02 16:45' ), $slots, 'too late for a 30-minute cut' );
		$base = array(
			'service' => 'cut',
			'name'    => 'Jo',
			'email'   => 'jo@example.com',
		);
		$this->assertSame( BookingResult::UNAVAILABLE, $booker->book( $base + array( 'start' => $this->at( '2026-06-02 16:45' ) ), $this->now() )->status, 'nobody can fit it' );
		$this->assertSame( BookingResult::UNAVAILABLE, $booker->book( $base + array( 'start' => $this->at( '2026-06-02 09:07' ) ), $this->now() )->status, 'off the grid' );
		$this->assertSame( BookingResult::UNAVAILABLE, $booker->book( $base + array( 'start' => $this->at( '2026-06-02 10:00' ) ), $this->at( '2026-06-02 10:30' ) )->status, 'in the past' );
		$this->assertSame( BookingResult::UNAVAILABLE, $booker->book( $base + array( 'start' => $this->at( '2028-06-02 10:00' ) ), $this->now() )->status, 'beyond the horizon' );
		$ok = $booker->book( $base + array( 'start' => '2026-06-02T09:00:00+01:00' ), $this->now() );
		$this->assertSame( BookingResult::BOOKED, $ok->status );
		$this->assertSame( 201, $ok->http_status() );
		$this->assertSame( 'ana', $ok->booking?->staff );
	}

	public function test_invalid_input_is_reported_per_field(): void {
		$c = $this->config();
		$r = ( new Booker( $c, new MemoryStore(), new SlotPolicy() ) )->book(
			array(
				'service' => 'long',
				'staff'   => 'ben',
				'start'   => 'tomorrow',
				'name'    => '',
				'email'   => 'nope',
				'phone'   => 'call me',
			),
			$this->now()
		);
		$this->assertSame( BookingResult::INVALID, $r->status );
		$this->assertSame( 422, $r->http_status() );
		$this->assertSame( array( 'staff', 'start', 'name', 'email', 'phone' ), array_keys( $r->errors ) );
	}

	public function test_the_same_time_cannot_be_booked_twice_and_anyone_falls_back(): void {
		$c      = $this->config();
		$store  = new MemoryStore();
		$booker = new Booker( $c, $store, new SlotPolicy( 'every' ) );
		$req    = array(
			'service' => 'cut',
			'staff'   => 'ben',
			'start'   => $this->at( '2026-06-02 11:00' ),
			'name'    => 'A',
			'email'   => 'a@example.com',
		);
		$this->assertSame( BookingResult::BOOKED, $booker->book( $req, $this->now() )->status );
		$this->assertSame( BookingResult::UNAVAILABLE, $booker->book( $req, $this->now() )->status );
		$any = $booker->book( array_merge( $req, array( 'staff' => 'any' ) ), $this->now() );
		$this->assertSame( 'ana', $any->booking?->staff, 'anyone: the other person takes it' );
		$this->assertSame( BookingResult::UNAVAILABLE, $booker->book( array_merge( $req, array( 'staff' => '' ) ), $this->now() )->status );
	}

	public function test_a_lost_race_is_reported_as_taken(): void {
		$c = $this->config();
		// A store whose busy() is stale (as when another request commits between our check and our claim).
		$inner  = new MemoryStore();
		$stale  = new class( $inner ) implements \AppointmentBooking\Core\BookingStore {
			/**
			 * Constructor.
			 *
			 * @param MemoryStore $inner Real store.
			 */
			public function __construct( private MemoryStore $inner ) {}

			/**
			 * Always looks free.
			 *
			 * @param string $staff Staff.
			 * @param int    $from  From.
			 * @param int    $to    To.
			 * @return list<array{0: int, 1: int}>
			 */
			public function busy( string $staff, int $from, int $to ): array {
				return array();
			}

			/**
			 * Real claim.
			 *
			 * @param Booking $booking Booking.
			 */
			public function claim( Booking $booking ): ?int {
				return $this->inner->claim( $booking );
			}
		};
		$booker = new Booker( $c, $stale, new SlotPolicy( 'every' ) );
		$req    = array(
			'service' => 'colour',
			'staff'   => 'ana',
			'start'   => $this->at( '2026-06-02 09:00' ),
			'name'    => 'A',
			'email'   => 'a@example.com',
		);
		$this->assertSame( BookingResult::BOOKED, $booker->book( $req, $this->now() )->status );
		$overlap = $booker->book(
			array_merge(
				$req,
				array(
					'service' => 'cut',
					'start'   => $this->at( '2026-06-02 10:00' ),
				)
			),
			$this->now()
		);
		$this->assertSame( BookingResult::TAKEN, $overlap->status, 'overlaps only the buffer, still refused' );
		$this->assertSame( 409, $overlap->http_status() );
		$this->assertCount( 1, $inner->bookings );
	}

	public function test_units_make_any_overlap_collide(): void {
		$a = new Booking( 's', 'p', 1000, 1600, 1700 );
		$b = new Booking( 's', 'p', 1650, 2000, 2000 );
		$c = new Booking( 's', 'p', 1800, 2000, 2000 );
		$this->assertNotEmpty( array_intersect( $a->units(), $b->units() ), 'overlapping intervals share a unit' );
		$this->assertEmpty( array_intersect( $a->units(), $c->units() ), 'aligned, back-to-back intervals do not' );
	}

	public function test_best_fit_gives_anyone_bookings_to_the_tighter_gap(): void {
		$c     = $this->config();
		$store = new MemoryStore();
		// Ana is free 13:00-17:00; Ben is booked until 13:15 then free to 14:00.
		$store->claim( new Booking( 'cut', 'ben', $this->at( '2026-06-02 10:00' ), $this->at( '2026-06-02 13:15' ), $this->at( '2026-06-02 13:15' ) ) );
		$fit = ( new Booker( $c, $store, new SlotPolicy( 'every' ) ) )->book(
			array(
				'service' => 'cut',
				'start'   => $this->at( '2026-06-02 13:30' ),
				'name'    => 'A',
				'email'   => 'a@example.com',
			),
			$this->now()
		);
		$this->assertSame( 'ana', $fit->booking?->staff, 'with Ben it would strand 15 minutes before and after' );
	}
}
