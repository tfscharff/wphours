<?php
/**
 * Stub harness: mocks the WordPress functions the plugin uses, then runs tests/cases.php.
 * Run: php tests/run.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['wph_options']    = array();
$GLOBALS['wph_actions']    = array();  // hook => list of callbacks
$GLOBALS['wph_shortcodes'] = array();  // tag => callback
$GLOBALS['wph_routes']     = array();  // list of [namespace, route, args]
$GLOBALS['wph_menus']      = array();
$GLOBALS['wph_caps']       = array( 'edit_pages' => true );

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}

class WP_REST_Request {
	private $json;
	public function __construct( $json = null ) { $this->json = $json; }
	public function get_json_params() { return $this->json; }
}

function add_action( $hook, $cb ) { $GLOBALS['wph_actions'][ $hook ][] = $cb; }
function add_shortcode( $tag, $cb ) { $GLOBALS['wph_shortcodes'][ $tag ] = $cb; }
function add_menu_page() { $GLOBALS['wph_menus'][] = func_get_args(); }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['wph_routes'][] = array( $ns, $route, $args ); }
function rest_ensure_response( $d ) { return $d; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function wp_create_nonce( $a ) { return 'nonce123'; }
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['wph_options'] ) ? $GLOBALS['wph_options'][ $k ] : $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['wph_options'][ $k ] = $v; return true; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['wph_caps'][ $cap ] ); }
function __return_true() { return true; }
function shortcode_atts( $pairs, $atts, $tag = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $k => $v ) { $out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v; }
	return $out;
}
function wp_json_encode( $d ) { return json_encode( $d ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url_raw( $s, $protocols = null ) { return preg_match( '#^https?://#i', $s ) ? $s : ''; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }

require dirname( __DIR__ ) . '/mcw-library-hours.php';

require __DIR__ . '/cases.php';
