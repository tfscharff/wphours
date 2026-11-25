<?php
/**
 * Shared resolver fixtures. Each case: name, schedule, date, expected blocks and note.
 * tests/cases.php checks the PHP resolver against them; tests/js/resolver.test.mjs checks the
 * plugin's embedded JS resolver against them and against what PHP returns (via tests/js-bridge.php).
 * Keep to inputs both resolvers must treat alike (single-block exceptions, no empty-array exception).
 */

function wphf_wk( $days = array() ) {
	$w = array();
	for ( $d = 0; $d < 7; $d++ ) { $w[ (string) $d ] = isset( $days[ $d ] ) ? $days[ $d ] : array(); }
	return $w;
}
function wphf_blk( $o, $c ) { return array( 'open' => $o, 'close' => $c ); }

function wphf_cases() {
	$b8_17  = wphf_blk( '08:00', '17:00' );
	$split  = array( wphf_blk( '09:00', '12:00' ), wphf_blk( '13:00', '17:00' ) );
	// 2026-10-28 is a Wednesday (3), 2026-10-29 Thursday (4), 2026-11-01 Sunday (0).
	$base = array(
		'weekly'     => wphf_wk( array( 3 => array( $b8_17 ), 4 => $split ) ),
		'exceptions' => array(),
		'ranges'     => array(),
	);
	$fall = array( 'start' => '2026-10-01', 'end' => '2026-10-31', 'label' => 'Fall', 'weekly' => wphf_wk( array( 3 => array( wphf_blk( '09:00', '21:00' ) ) ) ) );
	$late = array( 'start' => '2026-10-25', 'end' => '2026-11-10', 'label' => 'Late', 'weekly' => wphf_wk( array( 3 => array( wphf_blk( '10:00', '16:00' ) ) ) ) );
	$edge = array( 'weekly' => wphf_wk( array( 4 => array( wphf_blk( '08:00', '09:00' ) ), 5 => array( wphf_blk( '08:00', '09:00' ) ), 6 => array( wphf_blk( '08:00', '09:00' ) ) ) ),
		'exceptions' => array(),
		'ranges' => array( array( 'start' => '2026-10-30', 'end' => '2026-10-31', 'weekly' => wphf_wk( array( 5 => array( wphf_blk( '11:00', '12:00' ) ), 6 => array( wphf_blk( '13:00', '14:00' ) ) ) ) ) ) );
	$with = function ( $over ) use ( $base ) { return array_merge( $base, $over ); };

	return array(
		array( 'weekly default', $base, '2026-10-28', array( $b8_17 ), '' ),
		array( 'weekly two blocks', $base, '2026-10-29', $split, '' ),
		array( 'weekly day with no blocks', $base, '2026-10-30', array(), '' ),
		array( 'Sunday uses day 0', $base, '2026-11-01', array(), '' ),
		array( 'range beats weekly', $with( array( 'ranges' => array( $fall ) ) ), '2026-10-28', array( wphf_blk( '09:00', '21:00' ) ), '' ),
		array( 'range day with no blocks does not fall back', $with( array( 'ranges' => array( $fall ) ) ), '2026-10-29', array(), '' ),
		array( 'outside range uses weekly', $with( array( 'ranges' => array( $fall ) ) ), '2026-11-04', array( $b8_17 ), '' ),
		array( 'range start inclusive', $edge, '2026-10-30', array( wphf_blk( '11:00', '12:00' ) ), '' ),
		array( 'range end inclusive', $edge, '2026-10-31', array( wphf_blk( '13:00', '14:00' ) ), '' ),
		array( 'day before range', $edge, '2026-10-29', array( wphf_blk( '08:00', '09:00' ) ), '' ),
		array( 'day after range', $edge, '2026-11-01', array(), '' ),
		array( 'last matching range wins', $with( array( 'ranges' => array( $fall, $late ) ) ), '2026-10-28', array( wphf_blk( '10:00', '16:00' ) ), '' ),
		array( 'only the first range covers the date', $with( array( 'ranges' => array( $fall, $late ) ) ), '2026-10-21', array( wphf_blk( '09:00', '21:00' ) ), '' ),
		array( 'order decides, not dates', $with( array( 'ranges' => array( $late, $fall ) ) ), '2026-10-28', array( wphf_blk( '09:00', '21:00' ) ), '' ),
		array( 'malformed ranges ignored', $with( array( 'ranges' => array( 'junk', array( 'start' => '2026-10-01' ), $fall ) ) ), '2026-10-28', array( wphf_blk( '09:00', '21:00' ) ), '' ),
		array( 'hours exception beats range', $with( array( 'ranges' => array( $fall ), 'exceptions' => array( '2026-10-28' => array( wphf_blk( '12:00', '13:00' ) ) ) ) ), '2026-10-28', array( wphf_blk( '12:00', '13:00' ) ), '' ),
		array( 'closed exception with note beats range', $with( array( 'ranges' => array( $fall ), 'exceptions' => array( '2026-10-28' => array( 'closed' => true, 'note' => 'Power outage' ) ) ) ), '2026-10-28', array(), 'Power outage' ),
		array( 'closed exception without note', $with( array( 'exceptions' => array( '2026-10-28' => array( 'closed' => true ) ) ) ), '2026-10-28', array(), '' ),
		array( 'exception only affects its own date', $with( array( 'exceptions' => array( '2026-10-28' => array( 'closed' => true ) ) ) ), '2026-10-29', $split, '' ),
		array( 'malformed exception falls through', $with( array( 'ranges' => array( $fall ), 'exceptions' => array( '2026-10-28' => 'junk' ) ) ), '2026-10-28', array( wphf_blk( '09:00', '21:00' ) ), '' ),
		array( 'closed=false exception falls through', $with( array( 'exceptions' => array( '2026-10-28' => array( 'closed' => false ) ) ) ), '2026-10-28', array( $b8_17 ), '' ),
		array( 'empty schedule is closed', array(), '2026-10-28', array(), '' ),
		array( 'junk sections are closed', array( 'weekly' => 'junk', 'exceptions' => 'junk', 'ranges' => 'junk' ), '2026-10-28', array(), '' ),
		array( 'string weekly is closed', array( 'weekly' => 'junk' ), '2026-10-28', array(), '' ),
		array( 'non-array day value is closed', array( 'weekly' => array( '3' => 'junk' ) ), '2026-10-28', array(), '' ),
		array( 'numeric day value is closed', array( 'weekly' => array( '3' => 5 ) ), '2026-10-28', array(), '' ),
		array( 'range with string weekly is closed', array( 'weekly' => wphf_wk( array( 3 => array( $b8_17 ) ) ), 'ranges' => array( array( 'start' => '2026-10-01', 'end' => '2026-10-31', 'weekly' => 'junk' ) ) ), '2026-10-28', array(), '' ),
		array( 'range with non-array day value is closed', array( 'ranges' => array( array( 'start' => '2026-10-01', 'end' => '2026-10-31', 'weekly' => array( '3' => 'junk' ) ) ) ), '2026-10-28', array(), '' ),
		array( 'range without weekly is closed', array( 'ranges' => array( array( 'start' => '2026-10-01', 'end' => '2026-10-31' ) ) ), '2026-10-28', array(), '' ),
	);
}
