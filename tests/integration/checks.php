<?php
/**
 * Integration checks inside a real WordPress (run with: wp eval-file checks.php).
 * Exercises the REST routes, storage, emails with calendar files, cancelling, reminders and privacy tools end to end.
 *
 * @package AppointmentBooking
 */

use AppointmentBooking\Core\Availability;
use AppointmentBooking\Data\Schema;
use AppointmentBooking\Data\WpdbStore;
use AppointmentBooking\Mail\Mailer;
use AppointmentBooking\Privacy\Privacy;
use AppointmentBooking\Settings;

$failures = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $name . ( $ok || '' === $detail ? '' : ' (' . $detail . ')' ) . "\n";
	$failures += $ok ? 0 : 1;
};
$rest     = static function ( string $method, string $route, array $params = array() ): WP_REST_Response {
	$req = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $params );
	} else {
		$req->set_body_params( $params );
	}
	$res = rest_do_request( $req );
	return $res instanceof WP_REST_Response ? $res : new WP_REST_Response( null, 500 );
};
global $wpdb;
$tz       = Settings::config()->timezone;
$tomorrow = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+3 days' )->format( 'Y-m-d' );
$at       = static fn ( string $hm, string $date = '' ): int => ( new DateTimeImmutable( ( '' !== $date ? $date : $tomorrow ) . ' ' . $hm, $tz ) )->getTimestamp();
delete_option( 'abk_test_mail' );

$check( 'tables created on activation', (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::bookings() ) ) && (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::claims() ) ) );
$check( 'REST routes registered', isset( rest_get_server()->get_routes()['/abk/v1/bookings'], rest_get_server()->get_routes()['/abk/v1/slots'] ) );
$check( 'block registered', WP_Block_Type_Registry::get_instance()->is_registered( 'appointment-booking/booking-form' ) );
$html = do_shortcode( '[appointment_booking title="Book a visit"]' );
$check( 'shortcode renders the widget with its settings and a honeypot', str_contains( $html, 'data-abk=' ) && str_contains( $html, 'Book a visit' ) && str_contains( $html, 'name="abk_website"' ) );
$check( 'block renders through its PHP callback', str_contains( do_blocks( '<!-- wp:appointment-booking/booking-form {"title":"Hi"} /-->' ), 'abk-pick' ) );

$services = $rest( 'GET', '/abk/v1/services' )->get_data();
$check( 'services endpoint lists services and staff', 2 === count( $services['services'] ) && 'alex' === $services['staff'][0]['id'] && 'Europe/London' === $services['timezone'] );
$slots = $rest(
	'GET',
	'/abk/v1/slots',
	array(
		'service' => 'consultation',
		'date'    => $tomorrow,
	)
)->get_data();
$check( 'slots endpoint returns times in the business timezone', count( $slots['slots'] ) > 10 && $slots['slots'][0]['start'] === $at( '08:00' ) && str_ends_with( $slots['slots'][0]['iso'], 'Z' ) );
$days = $rest( 'GET', '/abk/v1/days', array( 'service' => 'consultation' ) )->get_data();
$check( 'days endpoint counts free times per day', count( $days['days'] ) === 30 && $days['days'][3]['free'] > 0 );

$book = static fn ( array $over = array() ): WP_REST_Response => $rest(
	'POST',
	'/abk/v1/bookings',
	array_merge(
		array(
			'service'  => 'treatment',
			'staff'    => 'alex',
			'start'    => (string) $at( '10:00' ),
			'name'     => 'Maria Lopez',
			'email'    => 'maria.integration@example.com',
			'phone'    => '+44 20 7946 0100',
			'notes'    => 'First visit.',
			'page_url' => home_url( '/book/' ),
		),
		$over
	)
);
$res  = $book();
$id   = (int) ( $res->get_data()['id'] ?? 0 );
$row  = WpdbStore::find( $id );
$check( 'a booking is stored and confirmed', 201 === $res->get_status() && 'confirmed' === ( $row['status'] ?? '' ), wp_json_encode( $res->get_data() ) );
$claims = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id = %d', Schema::claims(), $id ) );
$check( 'it claims every five minutes of its time and buffer (60 + 15 minutes = 15 units)', 15 === $claims, (string) $claims );

$again = $book( array( 'email' => 'second@example.com' ) );
$check( 'the same time cannot be booked again (409)', 409 === $again->get_status() && 'unavailable' === $again->get_data()['status'] );
$overlap = $book(
	array(
		'service' => 'consultation',
		'start'   => (string) $at( '11:00' ),
		'email'   => 'buffer@example.com',
	)
);
$check( 'a time inside the buffer after it is refused too', 409 === $overlap->get_status() );
$invalid = $book(
	array(
		'email' => 'nope',
		'start' => (string) $at( '15:00' ),
	)
);
$check( 'invalid input returns 422 with field errors', 422 === $invalid->get_status() && isset( $invalid->get_data()['errors']->email ) );
$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::bookings() ) );
$bot   = $book(
	array(
		'abk_website' => 'http://spam.example',
		'start'       => (string) $at( '16:00' ),
	)
);
$check( 'a bot filling the honeypot gets a success reply but nothing is stored', 201 === $bot->get_status() && $count === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::bookings() ) ) );

$mail     = (array) get_option( 'abk_test_mail', array() );
$customer = null;
$owner    = null;
foreach ( $mail as $m ) {
	if ( 'maria.integration@example.com' === $m['to'] ) {
		$customer = $m;
	} elseif ( get_option( 'admin_email' ) === $m['to'] ) {
		$owner = $m;
	}
}
$ics = is_array( $customer ) ? (string) ( $customer['attachments'][0] ?? '' ) : '';
$check( 'the customer is emailed a calendar file in UTC with the right start', str_contains( $ics, 'DTSTART:' . gmdate( 'Ymd\THis\Z', $at( '10:00' ) ) ) && str_contains( $ics, 'BEGIN:VEVENT' ) );
$check( 'the confirmation has a manage link back to the booking page', is_array( $customer ) && str_contains( (string) $customer['message'], home_url( '/book/?abk_booking=' ) ) );
$check( 'the site owner is emailed with Reply-To set to the customer', is_array( $owner ) && str_contains( implode( ' ', (array) $owner['headers'] ), 'Reply-To: maria.integration@example.com' ) );

$token               = Settings::tokens()->issue( $id, 'maria.integration@example.com' );
$_GET['abk_booking'] = $token;
$manage              = do_shortcode( '[appointment_booking]' );
unset( $_GET['abk_booking'] );
$check( 'the manage link shows the booking with a cancel button', str_contains( $manage, 'Maria Lopez' ) && str_contains( $manage, 'abk_cancel' ) );
$bad = $rest( 'POST', '/abk/v1/bookings/cancel', array( 'token' => $id . '.' . str_repeat( 'a', 64 ) ) );
$check( 'a forged token cannot cancel', 404 === $bad->get_status() && 'confirmed' === WpdbStore::find( $id )['status'] );
$cancel = $rest( 'POST', '/abk/v1/bookings/cancel', array( 'token' => $token ) );
$check( 'the real token cancels the booking and frees its time', 200 === $cancel->get_status() && 'cancelled' === WpdbStore::find( $id )['status'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id = %d', Schema::claims(), $id ) ) );
$check( 'the cancellation email carries a calendar cancellation', str_contains( (string) wp_json_encode( get_option( 'abk_test_mail', array() ) ), 'METHOD:CANCEL' ) );
$check( 'cancelling twice is refused politely', 409 === $rest( 'POST', '/abk/v1/bookings/cancel', array( 'token' => $token ) )->get_status() );
$rebook = $book( array( 'email' => 'second@example.com' ) );
$check( 'the freed time can be booked again', 201 === $rebook->get_status() );

// A booking about 24.5 hours away, stored directly (whether that hour is open depends on when the suite runs).
$soon = time() + DAY_IN_SECONDS + 1800;
( new WpdbStore() )->claim( new \AppointmentBooking\Core\Booking( 'consultation', 'alex', $soon, $soon + 1800, $soon + 1800, 'Tomorrow Person', 'tomorrow@example.com' ) );
delete_option( 'abk_test_mail' );
$sent = Mailer::send_reminders();
$check( 'a reminder goes out about a day before, once', $sent >= 1 && 0 === Mailer::send_reminders() && str_contains( (string) wp_json_encode( get_option( 'abk_test_mail' ) ), 'Reminder' ) );

$export = Privacy::export( 'second@example.com' );
$check( 'privacy export finds the booking', 1 === count( $export['data'] ) && 'appointment-booking' === $export['data'][0]['group_id'] );
$erase = Privacy::erase( 'second@example.com' );
$check( 'privacy erase removes the personal details but keeps the time booked', $erase['items_removed'] && 0 === count( WpdbStore::by_email( 'second@example.com' ) ) && 'confirmed' === WpdbStore::find( (int) $rebook->get_data()['id'] )['status'] );

$config = Settings::config();
$alex   = $config->staff['alex'];
$label  = static fn ( int $t ): string => (string) wp_date( 'H:i', $t, $config->timezone );
$check( 'opening hours stay 08:00 local on both sides of a clock change', '08:00' === $label( Availability::open_ranges( $config, $alex, '2027-03-27' )[0][0] ) && '08:00' === $label( Availability::open_ranges( $config, $alex, '2027-03-29' )[0][0] ) && 2 * DAY_IN_SECONDS - HOUR_IN_SECONDS === Availability::open_ranges( $config, $alex, '2027-03-29' )[0][0] - Availability::open_ranges( $config, $alex, '2027-03-27' )[0][0] );
$check( 'a fixed UTC offset is recognised (the settings page warns about it)', Settings::is_fixed_offset( '+01:00' ) && ! Settings::is_fixed_offset( 'Europe/London' ) && ! Settings::is_fixed_offset( 'UTC' ) );
$clean = \AppointmentBooking\Admin\SettingsPage::clean(
	array(
		'timezone' => 'UTC+5.5',
		'policy'   => 'nonsense',
		'services' => array(
			array(
				'name'     => ' Massage <b>',
				'duration' => '60',
				'buffer'   => '-5',
				'price'    => '49.50',
			),
			array( 'name' => '' ),
		),
		'closures' => "2026-12-25\nChristmas",
	)
);
$check( 'saved settings are sanitized: offsets normalised, blank rows dropped, bad values defaulted', '+05:30' === $clean['timezone'] && 'gap_aware' === $clean['policy'] && 1 === count( $clean['services'] ) && 'Massage' === $clean['services'][0]['name'] && 5 === $clean['services'][0]['buffer'] && array( '2026-12-25' ) === $clean['closures'] );
$check( 'reminder cron scheduled', (bool) wp_next_scheduled( 'abk_send_reminders' ) );

echo $failures ? "\n{$failures} integration check(s) failed\n" : "\nall integration checks passed\n";
if ( $failures ) {
	WP_CLI::halt( 1 );
}
