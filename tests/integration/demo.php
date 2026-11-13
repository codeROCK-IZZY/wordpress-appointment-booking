<?php
/**
 * Seeds the local site with a booking page and a busy day (run with: wp eval-file demo.php).
 * Used for the screenshots in docs/images. All names are invented. Prints the seeded date.
 *
 * @package AppointmentBooking
 */

use AppointmentBooking\Plugin;
use AppointmentBooking\Settings;

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}abk_claims" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}abk_bookings" );
update_option(
	Settings::OPTION,
	array(
		'business'    => 'Northside Physio',
		'location'    => '12 High Street',
		'timezone'    => 'Europe/London',
		'policy'      => 'gap_aware',
		'granularity' => 15,
		'notice'      => 0,
		'horizon'     => 30,
		'closures'    => array(),
		'services'    => array(
			array(
				'name'     => 'Assessment',
				'duration' => 45,
				'buffer'   => 15,
				'price'    => 65,
			),
			array(
				'name'     => 'Treatment',
				'duration' => 30,
				'buffer'   => 0,
				'price'    => 45,
			),
			array(
				'name'     => 'Sports massage',
				'duration' => 60,
				'buffer'   => 15,
				'price'    => 70,
			),
		),
		'staff'       => array(
			array(
				'name'     => 'Alex Morgan',
				'hours'    => 'Mon-Fri 08:00-12:30, 13:30-17:00',
				'services' => 'all',
			),
			array(
				'name'     => 'Priya Shah',
				'hours'    => 'Mon-Fri 10:00-19:00; Sat 09:00-13:00',
				'services' => 'Assessment, Treatment',
			),
			array(
				'name'     => 'Sam Okafor',
				'hours'    => 'Tue-Sat 09:00-15:00',
				'services' => 'Treatment, Sports massage',
			),
		),
	)
);

if ( ! get_page_by_path( 'book' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Book an appointment',
			'post_name'    => 'book',
			'post_content' => "<!-- wp:paragraph -->\n<p>Choose a treatment and a time that suits you. You'll get a confirmation email with a calendar invite.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:appointment-booking/booking-form /-->",
		)
	);
}

$tz  = Settings::config()->timezone;
$day = new DateTimeImmutable( 'tomorrow', $tz );
while ( in_array( (int) $day->format( 'N' ), array( 1, 6, 7 ), true ) ) { // A Tuesday to Friday, when all three work.
	$day = $day->modify( '+1 day' );
}
$date   = $day->format( 'Y-m-d' );
$booker = Plugin::booker();
$now    = time();
$list   = array(
	array( 'assessment', 'alex-morgan', '08:00', 'Maria Lopez' ),
	array( 'treatment', 'alex-morgan', '09:00', 'James O\'Brien' ),
	array( 'sports-massage', 'alex-morgan', '09:30', 'Aisha Bello' ),
	array( 'treatment', 'alex-morgan', '13:30', 'Wei Chen' ),
	array( 'assessment', 'alex-morgan', '14:00', 'Sofia Rossi' ),
	array( 'assessment', 'priya-shah', '10:00', 'Kwame Mensah' ),
	array( 'treatment', 'priya-shah', '11:00', 'Hannah Becker' ),
	array( 'treatment', 'priya-shah', '11:30', 'Tomás Silva' ),
	array( 'assessment', 'priya-shah', '14:00', 'Grace Kim' ),
	array( 'treatment', 'priya-shah', '18:30', 'Oliver Grant' ),
	array( 'sports-massage', 'sam-okafor', '09:00', 'Leila Haddad' ),
	array( 'treatment', 'sam-okafor', '10:15', 'Daniel Novak' ),
	array( 'sports-massage', 'sam-okafor', '13:45', 'Chloe Martin' ),
);
foreach ( $list as $i => [ $service, $staff, $time, $name ] ) {
	$start = ( new DateTimeImmutable( $date . ' ' . $time, $tz ) )->getTimestamp();
	$r     = $booker->book(
		array(
			'service' => $service,
			'staff'   => $staff,
			'start'   => $start,
			'name'    => $name,
			'email'   => strtolower( (string) preg_replace( '/[^a-z]+/i', '.', $name ) ) . '@example.com',
			'phone'   => 0 === $i % 3 ? '+44 20 7946 0' . str_pad( (string) ( 100 + $i ), 3, '0', STR_PAD_LEFT ) : '',
			'notes'   => 0 === $i % 4 ? 'Lower back pain since last month.' : '',
		),
		$now
	);
	if ( 'booked' !== $r->status ) {
		echo "not booked: {$name} {$time} {$r->status} " . wp_json_encode( $r->errors ) . "\n";
	}
}
echo $date . "\n";
