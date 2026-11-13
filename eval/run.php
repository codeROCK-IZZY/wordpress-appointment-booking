<?php
/**
 * Offline evaluation: which start times to offer, and opening hours across daylight saving.
 * Writes eval/reports/report.{json,md,html}. With --gate, exits 1 if a regression limit is crossed.
 *
 * Usage: php eval/run.php [--gate]
 *
 * Banks of simulated businesses, two weeks each:
 * - tuning (seeds 1-20): the gap-aware factor (3) was chosen here;
 * - held-out (seeds 101-130), run after freezing;
 * - different (seeds 201-210): 5-7 staff, 10-minute granularity, heavier demand and narrower windows, held back.
 *
 * Double bookings under concurrent requests need a real database and web server; they are measured by the
 * integration suite (tests/integration/run.sh), not here.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Evaluation;

use AppointmentBooking\Core\SlotPolicy;

require dirname( __DIR__ ) . '/src/autoload.php';
require __DIR__ . '/Simulation.php';
require __DIR__ . '/Dst.php';

$gate = in_array( '--gate', $argv, true );

$banks = array(
	'tuning'    => array( range( 1, 20 ), false, 'Tuning businesses' ),
	'heldout'   => array( range( 101, 130 ), false, 'Held-out businesses' ),
	'different' => array( range( 201, 210 ), true, 'Different shape (held back)' ),
);
$arms  = array(
	'every'     => array( new SlotPolicy( 'every' ), false, 'Every start time that fits (common default)' ),
	'grid'      => array( new SlotPolicy( 'grid' ), false, 'Fixed grid the length of the service' ),
	'gap_first' => array( new SlotPolicy( 'gap_aware' ), false, 'Gap-aware, first free person (ablation)' ),
	'gap_aware' => array( new SlotPolicy( 'gap_aware' ), true, 'Gap-aware, best-fitting person (this plugin)' ),
);

/**
 * Results.
 *
 * @var array<string, mixed> $result
 */
$result = array( 'banks' => array() );
foreach ( $banks as $key => [ $seeds, $different, $label ] ) {
	$bank = array(
		'label'      => $label,
		'businesses' => count( $seeds ),
		'arms'       => array(),
	);
	$sims = array_map( static fn ( int $s ): Simulation => new Simulation( $s, $different ), $seeds );
	foreach ( $arms as $arm => [ $policy, $fit, $arm_label ] ) {
		$tot = array(
			'customers'      => 0,
			'booked'         => 0,
			'booked_minutes' => 0,
			'open_minutes'   => 0,
			'revenue'        => 0,
			'options'        => 0.0,
		);
		foreach ( $sims as $sim ) {
			$r = $sim->run( $policy, $fit );
			foreach ( array_keys( $tot ) as $k ) {
				$tot[ $k ] += $r[ $k ];
			}
		}
		$bank['arms'][ $arm ] = array(
			'label'       => $arm_label,
			'customers'   => $tot['customers'],
			'booked'      => $tot['booked'],
			'booked_rate' => round( $tot['booked'] / max( 1, $tot['customers'] ), 4 ),
			'utilisation' => round( $tot['booked_minutes'] / max( 1, $tot['open_minutes'] ), 4 ),
			'revenue'     => intdiv( $tot['revenue'], 100 ),
			'options'     => round( $tot['options'] / count( $sims ), 1 ),
		);
	}
	$result['banks'][ $key ] = $bank;
}
$result['dst'] = Dst::run();

/**
 * Arms of the held-out and different banks.
 *
 * @var array<string, array{booked_rate: float, revenue: int}> $h
 * @var array<string, array{booked_rate: float, revenue: int}> $d
 */
$h      = $result['banks']['heldout']['arms'];
$d      = $result['banks']['different']['arms'];
$wrong  = static fn ( string $k ): int => array_sum( array_column( $result['dst'], $k ) );
$checks = array(
	'heldout_booked_gap_minus_every_min'   => round( $h['gap_aware']['booked_rate'] - $h['every']['booked_rate'], 4 ),
	'heldout_revenue_gap_vs_every_min'     => round( $h['gap_aware']['revenue'] / max( 1, $h['every']['revenue'] ), 4 ),
	'different_booked_gap_minus_every_min' => round( $d['gap_aware']['booked_rate'] - $d['every']['booked_rate'], 4 ),
	'dst_plugin_wrong_days_max'            => $wrong( 'plugin_wrong' ),
);
$limits = array(
	'heldout_booked_gap_minus_every_min'   => 0.0,
	'heldout_revenue_gap_vs_every_min'     => 1.0,
	'different_booked_gap_minus_every_min' => -0.01,
	'dst_plugin_wrong_days_max'            => 0,
);
$passed = array();
foreach ( $checks as $k => $v ) {
	$passed[ $k ] = str_ends_with( $k, '_max' ) ? $v <= $limits[ $k ] : $v >= $limits[ $k ];
}
$result += array(
	'checks' => $checks,
	'limits' => $limits,
	'passed' => $passed,
	'ok'     => ! in_array( false, $passed, true ),
);

require __DIR__ . '/report.php';
$dir = __DIR__ . '/reports';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
file_put_contents( $dir . '/report.json', json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
file_put_contents( $dir . '/report.md', report_markdown( $result ) );
file_put_contents( $dir . '/report.html', report_html( $result ) );
echo report_markdown( $result );
exit( $gate && ! $result['ok'] ? 1 : 0 );
