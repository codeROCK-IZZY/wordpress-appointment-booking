<?php
/**
 * Markdown and HTML versions of the evaluation.
 *
 * @package AppointmentBooking
 */

namespace AppointmentBooking\Evaluation;

/**
 * Percentage.
 *
 * @param float $x Fraction.
 */
function pct( float $x ): string {
	return number_format( 100 * $x, 1 ) . '%';
}

/**
 * Markdown report.
 *
 * @param array<string, mixed> $r Result.
 */
function report_markdown( array $r ): string {
	$out = array( '# Appointment booking: evaluation', '', 'Simulated businesses taking bookings for two weeks with more demand than they can serve, and opening hours checked on every day of 2026 in twelve timezones.', '' );
	foreach ( $r['banks'] as $bank ) {
		$any   = reset( $bank['arms'] );
		$out[] = sprintf( '## %s: %d businesses, %s customers', $bank['label'], $bank['businesses'], number_format( $any['customers'] ) );
		$out[] = '';
		$out[] = '| Times offered | Customers booked | Staff time booked | Revenue | Times shown per customer |';
		$out[] = '|---|---:|---:|---:|---:|';
		foreach ( $bank['arms'] as $a ) {
			$out[] = sprintf( '| %s | %s (%s) | %s | $%s | %s |', $a['label'], number_format( $a['booked'] ), pct( $a['booked_rate'] ), pct( $a['utilisation'] ), number_format( $a['revenue'] ), $a['options'] );
		}
		$out[] = '';
	}
	$out[] = '## Opening hours across daylight saving (every day of 2026, 09:00-17:00 local)';
	$out[] = '';
	$out[] = '| Timezone | Changes clocks | Days wrong: this plugin | Days wrong: offset worked out once |';
	$out[] = '|---|---|---:|---:|';
	foreach ( $r['dst'] as $zone => $row ) {
		$out[] = sprintf( '| %s | %s | %d | %d |', $zone, $row['dst'] ? 'yes' : 'no', $row['plugin_wrong'], $row['fixed_offset_wrong'] );
	}
	$out[] = '';
	$out[] = '## Gate (regression limits, not targets)';
	$out[] = '';
	foreach ( $r['checks'] as $k => $v ) {
		$out[] = sprintf( '- %s %s: %s (limit %s)', $r['passed'][ $k ] ? 'PASS' : 'FAIL', $k, $v, $r['limits'][ $k ] );
	}
	return implode( "\n", $out ) . "\n";
}

/**
 * HTML report.
 *
 * @param array<string, mixed> $r Result.
 */
function report_html( array $r ): string {
	$e     = static fn ( mixed $s ): string => htmlspecialchars( (string) $s, ENT_QUOTES );
	$h     = $r['banks']['heldout']['arms'];
	$dst   = $r['dst'];
	$days  = array_sum( array_column( $dst, 'days' ) );
	$tiles = array(
		array( pct( $h['gap_aware']['booked_rate'] - $h['every']['booked_rate'] ) . ' pts', 'more customers booked than offering every start time (held-out)' ),
		array( pct( $h['gap_aware']['revenue'] / max( 1, $h['every']['revenue'] ) - 1 ), 'more revenue from the same staff hours' ),
		array( $h['gap_aware']['options'] . ' vs ' . $h['every']['options'], 'times shown per customer: fewer, better-placed choices' ),
		array( array_sum( array_column( $dst, 'plugin_wrong' ) ) . ' of ' . number_format( $days ), 'days with wrong opening times across DST (offset worked out once: ' . number_format( array_sum( array_column( $dst, 'fixed_offset_wrong' ) ) ) . ')' ),
	);
	$css   = ':root{--bg:#0d1016;--panel:#151b24;--line:#263042;--ink:#e8edf4;--ink2:#97a3b6;--ok:#3ccf8e;--bad:#ff6b6b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 Inter,system-ui,sans-serif}main{max-width:1180px;margin:0 auto;padding:34px 28px 56px}h1{font-size:28px;margin:0 0 4px}h2{font-size:18px;margin:28px 0 12px}.sub{color:var(--ink2);margin:0 0 20px}.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.tile{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px 18px}.tile b{display:block;font-size:26px}.tile span{color:var(--ink2);font-size:13px}table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden;font-size:13.5px}th,td{padding:8px 12px;border-bottom:1px solid var(--line);text-align:right}th:first-child,td:first-child{text-align:left}th{color:var(--ink2);font-weight:500;font-size:12px}.good{color:var(--ok);font-weight:600}.bad{color:var(--bad);font-weight:600}@media(max-width:800px){.tiles{grid-template-columns:1fr 1fr}}';
	$out   = array( "<!doctype html><html lang='en'><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Appointment booking evaluation</title><style>{$css}</style><main><h1>Appointment booking: evaluation</h1><p class='sub'>Which start times to offer, on simulated businesses with more demand than capacity, and opening hours on every day of 2026 in twelve timezones.</p><div class='tiles'>" );
	foreach ( $tiles as [ $v, $t ] ) {
		$out[] = "<div class='tile'><b>{$e($v)}</b><span>{$e($t)}</span></div>";
	}
	$out[] = '</div>';
	foreach ( $r['banks'] as $key => $bank ) {
		if ( 'tuning' === $key ) {
			continue;
		}
		$best  = max( array_merge( array( 0 ), array_column( $bank['arms'], 'booked' ) ) );
		$out[] = "<h2>{$e($bank['label'])}</h2><table><tr><th>Times offered</th><th>Customers booked</th><th>Staff time booked</th><th>Revenue</th><th>Times shown</th></tr>";
		foreach ( $bank['arms'] as $a ) {
			$cls   = $a['booked'] === $best ? 'good' : '';
			$out[] = "<tr><td>{$e($a['label'])}</td><td class='{$cls}'>" . number_format( $a['booked'] ) . ' (' . pct( $a['booked_rate'] ) . ')</td><td>' . pct( $a['utilisation'] ) . '</td><td>$' . number_format( $a['revenue'] ) . "</td><td>{$a['options']}</td></tr>";
		}
		$out[] = '</table>';
	}
	$out[] = '<h2>Opening hours across daylight saving</h2><table><tr><th>Timezone</th><th>Changes clocks</th><th>Days wrong: this plugin</th><th>Days wrong: offset worked out once</th></tr>';
	foreach ( $dst as $zone => $row ) {
		$out[] = "<tr><td>{$e($zone)}</td><td>" . ( $row['dst'] ? 'yes' : 'no' ) . "</td><td class='good'>{$row['plugin_wrong']}</td><td class='" . ( $row['fixed_offset_wrong'] ? 'bad' : '' ) . "'>{$row['fixed_offset_wrong']}</td></tr>";
	}
	$out[] = '</table><h2>Gate (regression limits)</h2><table><tr><th>Check</th><th>Value</th><th>Limit</th><th>Result</th></tr>';
	foreach ( $r['checks'] as $k => $v ) {
		$ok    = $r['passed'][ $k ];
		$out[] = "<tr><td>{$e($k)}</td><td>{$e($v)}</td><td>{$e($r['limits'][$k])}</td><td class='" . ( $ok ? 'good' : 'bad' ) . "'>" . ( $ok ? 'pass' : 'fail' ) . '</td></tr>';
	}
	$out[] = '</table></main></html>';
	return implode( '', $out ) . "\n";
}
