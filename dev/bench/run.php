<?php
/**
 * Benchmark runner. Requests each URL a few times to warm up, then N times,
 * and reports medians of server time, query count, query time, peak memory,
 * and time to first byte. Needs dev/bench/server-timing.php as a mu-plugin.
 *
 * Run inside the site's web container so the router and host network are not
 * measured:
 *
 *   php run.php urls.json [runs=15] [warmup=2] > results.json
 *
 * urls.json: [{"name": "Month view", "url": "/events/month/"}, ...]. Add
 * "method": "POST" and "body": {...} for form posts, and "cookie": "..." to
 * send one (e.g. a logged-in session).
 *
 * @package OpenEvents
 */

// phpcs:disable

$file   = $argv[1] ?? 'urls.json';
$runs   = (int) ( $argv[2] ?? 15 );
$warmup = (int) ( $argv[3] ?? 2 );
$base   = getenv( 'BENCH_BASE' ) ?: 'http://127.0.0.1';
$host   = getenv( 'BENCH_HOST' ) ?: '';
$urls   = json_decode( (string) file_get_contents( $file ), true );

function oe_bench_request( string $base, string $host, array $item ): array {
	$headers = array( 'X-OE-Bench: 1' );
	if ( $host ) {
		$headers[] = 'Host: ' . $host;
		// Behind the router the site sees HTTPS; keep that so it doesn't redirect.
		$headers[] = 'X-Forwarded-Proto: https';
	}
	if ( ! empty( $item['cookie'] ) ) {
		$headers[] = 'Cookie: ' . $item['cookie'];
	}
	$ch = curl_init( $base . $item['url'] );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 120,
		)
	);
	if ( 'POST' === ( $item['method'] ?? 'GET' ) ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $item['body'] ?? array() ) );
	}
	$response = (string) curl_exec( $ch );
	$info     = curl_getinfo( $ch );
	curl_close( $ch );

	$header = substr( $response, 0, (int) $info['header_size'] );
	$out    = array(
		'status' => (int) $info['http_code'],
		'ttfb'   => $info['starttransfer_time'] * 1000,
		'bytes'  => strlen( $response ) - (int) $info['header_size'],
	);
	if ( preg_match( '/^Server-Timing:\s*(.+)$/mi', $header, $m ) ) {
		preg_match( '/total;dur=([\d.]+)/', $m[1], $t );
		preg_match( '/db;dur=([\d.]+);desc="(\d+) queries"/', $m[1], $d );
		preg_match( '/mem;desc="([\d.]+) MB"/', $m[1], $mem );
		$out += array(
			'total'   => (float) ( $t[1] ?? 0 ),
			'db_ms'   => (float) ( $d[1] ?? 0 ),
			'queries' => (int) ( $d[2] ?? 0 ),
			'mem'     => (float) ( $mem[1] ?? 0 ),
		);
	}
	return $out;
}

function oe_bench_median( array $values ): float {
	sort( $values );
	$n = count( $values );
	if ( ! $n ) {
		return 0.0;
	}
	return $n % 2 ? (float) $values[ intdiv( $n, 2 ) ] : ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2;
}

$results = array();
foreach ( $urls as $item ) {
	for ( $i = 0; $i < $warmup; $i++ ) {
		oe_bench_request( $base, $host, $item );
	}
	$samples = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$samples[] = oe_bench_request( $base, $host, $item );
	}
	$row = array(
		'name'    => $item['name'],
		'url'     => $item['url'],
		'status'  => $samples[0]['status'],
		'runs'    => $runs,
		'ttfb'    => round( oe_bench_median( array_column( $samples, 'ttfb' ) ), 1 ),
		'total'   => round( oe_bench_median( array_column( $samples, 'total' ) ), 1 ),
		'db_ms'   => round( oe_bench_median( array_column( $samples, 'db_ms' ) ), 1 ),
		'queries' => (int) oe_bench_median( array_column( $samples, 'queries' ) ),
		'mem'     => round( oe_bench_median( array_column( $samples, 'mem' ) ), 1 ),
		'bytes'   => (int) oe_bench_median( array_column( $samples, 'bytes' ) ),
	);
	$results[] = $row;
	fwrite( STDERR, sprintf( "%-34s %3d  ttfb %7.1f ms  php %7.1f ms  %4d queries  db %6.1f ms  %5.1f MB\n", $row['name'], $row['status'], $row['ttfb'], $row['total'], $row['queries'], $row['db_ms'], $row['mem'] ) );
}

echo json_encode(
	array(
		'when'    => gmdate( 'c' ),
		'base'    => $base,
		'host'    => $host,
		'runs'    => $runs,
		'results' => $results,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
