<?php
/**
 * Bridge for the JS tests: loads the plugin with minimal stubs and prints JSON with
 *   js     the real resolver source from mcw_hours_resolve_js()
 *   cases  every shared fixture with what the PHP resolver returns for it
 * Run: php tests/js-bridge.php (tests/js/resolver.test.mjs calls it itself).
 */

define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_shortcode() {}

require dirname( __DIR__ ) . '/mcw-library-hours.php';
require __DIR__ . '/fixtures.php';

$cases = array();
foreach ( wphf_cases() as $c ) {
	$cases[] = array(
		'name'     => $c[0],
		'schedule' => $c[1],
		'date'     => $c[2],
		'expected' => array( 'blocks' => $c[3], 'note' => $c[4] ),
		'php'      => mcw_hours_resolve( $c[1], $c[2] ),
	);
}
echo json_encode( array( 'js' => mcw_hours_resolve_js(), 'cases' => $cases ) );
