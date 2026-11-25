<?php
/** Test cases. Run through tests/run.php, which loads the WordPress stubs and the plugin. */

$fails = 0; $passes = 0;
function ok( $cond, $msg ) {
	global $fails, $passes;
	if ( $cond ) { $passes++; echo "PASS: $msg\n"; } else { $fails++; echo "FAIL: $msg\n"; }
}

/** A weekly map with the given day => blocks, every other day empty. */
function wk( $days = array() ) {
	$w = array();
	for ( $d = 0; $d < 7; $d++ ) { $w[ (string) $d ] = isset( $days[ $d ] ) ? $days[ $d ] : array(); }
	return $w;
}
function blk( $o, $c ) { return array( 'open' => $o, 'close' => $c ); }

/* ---------------- load-time registration ---------------- */

ok( defined( 'MCW_HOURS_OPTION' ) && 'mcw_library_hours' === MCW_HOURS_OPTION, 'option name constant' );
ok( isset( $GLOBALS['wph_actions']['rest_api_init'] ) && isset( $GLOBALS['wph_actions']['admin_menu'] ), 'rest_api_init and admin_menu hooks registered' );
ok( 'mcw_hours_shortcode' === $GLOBALS['wph_shortcodes']['library_hours'] && 'mcw_hours_full_shortcode' === $GLOBALS['wph_shortcodes']['library_hours_full'], 'both shortcodes registered' );

/* ---------------- mcw_hours_valid_time ---------------- */

foreach ( array( '00:00', '09:00', '12:30', '23:59', '24:00' ) as $t ) {
	ok( true === mcw_hours_valid_time( $t ), "valid time $t" );
}
ok( true === mcw_hours_valid_time( '24:00' ), '24:00 end-of-day sentinel accepted' );
foreach ( array( '25:00', '12:60', '9:00', '09:0', '0900', '09:00:00', '', ' 09:00', 'ab:cd', '-1:00' ) as $t ) {
	ok( false === (bool) mcw_hours_valid_time( $t ), 'invalid time rejected: "' . $t . '"' );
}
ok( false === (bool) mcw_hours_valid_time( null ) && false === (bool) mcw_hours_valid_time( 900 ) && false === (bool) mcw_hours_valid_time( true ), 'non-string times rejected' );
ok( false === (bool) mcw_hours_valid_time( array( '09:00' ) ) && false === (bool) mcw_hours_valid_time( array() ), 'array times rejected' );
// Current behaviour, suspected loose spots: 24:30 passes (regex allows any minute after hour 24)
// and a trailing newline passes (PCRE "$" matches before a final newline).
ok( true === (bool) mcw_hours_valid_time( '24:30' ), 'CURRENT BEHAVIOUR (suspected bug): 24:30 is accepted' );
ok( true === (bool) mcw_hours_valid_time( "09:00\n" ), 'CURRENT BEHAVIOUR (suspected bug): trailing newline is accepted' );

/* ---------------- mcw_hours_valid_date ---------------- */

ok( true === (bool) mcw_hours_valid_date( '2026-10-28' ), 'valid date' );
foreach ( array( '2026-1-5', '26-10-28', '2026/10/28', '', 'tomorrow', '2026-10-28 ' ) as $d ) {
	ok( false === (bool) mcw_hours_valid_date( $d ), 'invalid date rejected: "' . $d . '"' );
}
ok( false === (bool) mcw_hours_valid_date( null ) && false === (bool) mcw_hours_valid_date( 20261028 ) && false === (bool) mcw_hours_valid_date( array( '2026-10-28' ) ), 'non-string dates rejected' );
ok( true === (bool) mcw_hours_valid_date( '2026-13-45' ), 'CURRENT BEHAVIOUR (suspected bug): shape-only check, 2026-13-45 accepted' );

/* ---------------- default and get ---------------- */

$def = mcw_hours_default();
ok( array( 'libraryName', 'timezone', 'weekly', 'exceptions' ) === array_keys( $def ), 'default keys' );
ok( 'America/New_York' === $def['timezone'] && array() === $def['exceptions'], 'default timezone and no exceptions' );
ok( array( 0, 1, 2, 3, 4, 5, 6 ) === array_keys( $def['weekly'] ), 'default weekly has days 0-6 (PHP stores "0".."6" keys as ints)' );
ok( $def['weekly'] === mcw_hours_sanitize_weekly( $def['weekly'] ), 'default weekly survives its own sanitizer' );
ok( array( blk( '12:00', '24:00' ) ) === $def['weekly']['0'] && array( blk( '10:00', '18:00' ) ) === $def['weekly']['6'], 'default Sunday and Saturday' );

$GLOBALS['wph_options'] = array();
$g = mcw_hours_get();
ok( $def['weekly'] === $g['weekly'] && array() === $g['exceptions'] && array() === $g['ranges'], 'missing option: defaults plus empty ranges' );
$GLOBALS['wph_options'][ MCW_HOURS_OPTION ] = 'junk';
ok( $def['weekly'] === mcw_hours_get()['weekly'], 'string option falls back to defaults' );
$GLOBALS['wph_options'][ MCW_HOURS_OPTION ] = array();
ok( $def['weekly'] === mcw_hours_get()['weekly'], 'empty array option falls back to defaults' );
$GLOBALS['wph_options'][ MCW_HOURS_OPTION ] = 42;
ok( $def['weekly'] === mcw_hours_get()['weekly'], 'int option falls back to defaults' );
$GLOBALS['wph_options'][ MCW_HOURS_OPTION ] = array( 'libraryName' => 'X' );
$g = mcw_hours_get();
ok( 'X' === $g['libraryName'] && array() === $g['weekly'] && array() === $g['exceptions'] && array() === $g['ranges'], 'stored option missing keys: filled with empty arrays' );
$GLOBALS['wph_options'][ MCW_HOURS_OPTION ] = array( 'weekly' => wk( array( 1 => array( blk( '09:00', '10:00' ) ) ) ), 'guest' => array( 'weekly' => wk() ) );
$g = mcw_hours_get();
ok( array( blk( '09:00', '10:00' ) ) === $g['weekly']['1'] && isset( $g['guest'] ), 'stored option returned as is, guest kept' );

/* ---------------- sanitize_weekly ---------------- */

$w = mcw_hours_sanitize_weekly( 'junk' );
ok( wk() === $w, 'weekly non-array: seven empty days' );
ok( wk() === mcw_hours_sanitize_weekly( null ) && wk() === mcw_hours_sanitize_weekly( array() ), 'weekly null/empty: seven empty days' );
$w = mcw_hours_sanitize_weekly( array(
	'1' => array( blk( '08:00', '12:00' ), blk( '13:00', '24:00' ) ),
	'2' => array( blk( '25:00', '26:00' ), blk( '09:00', '10:00' ), 'junk', array( 'open' => '09:00' ), array(), null, array( 'open' => array( '09:00' ), 'close' => '10:00' ) ),
	'3' => 'junk',
	'7' => array( blk( '08:00', '09:00' ) ),
	'x' => array( blk( '08:00', '09:00' ) ),
	0   => array( blk( '10:00', '11:00' ) ),
) );
ok( array( 0, 1, 2, 3, 4, 5, 6 ) === array_keys( $w ), 'weekly: exactly days 0-6, unknown keys dropped' );
ok( array( blk( '08:00', '12:00' ), blk( '13:00', '24:00' ) ) === $w['1'], 'weekly: multiple blocks kept in order' );
ok( array( blk( '09:00', '10:00' ) ) === $w['2'], 'weekly: bad blocks, junk and missing keys dropped, good block kept' );
ok( array() === $w['3'], 'weekly: non-array day becomes empty' );
ok( array( blk( '10:00', '11:00' ) ) === $w['0'], 'weekly: integer key 0 accepted as day "0"' );
$w = mcw_hours_sanitize_weekly( array( '1' => array( array( 'open' => '08:00', 'close' => '17:00', 'evil' => '<b>x</b>' ) ) ) );
ok( array( blk( '08:00', '17:00' ) ) === $w['1'], 'weekly: extra block fields dropped' );
$w = mcw_hours_sanitize_weekly( array( '1' => array( blk( '18:00', '09:00' ) ) ) );
ok( array( blk( '18:00', '09:00' ) ) === $w['1'], 'CURRENT BEHAVIOUR: reversed block (close before open) is kept, not validated' );

/* ---------------- sanitize_exceptions ---------------- */

$e = mcw_hours_sanitize_exceptions( array(
	'2026-12-25' => array( 'closed' => true, 'note' => '<b>Christmas</b> Day' ),
	'2026-12-24' => array( blk( '09:00', '13:00' ) ),
	'2026-12-31' => array( 'closed' => true ),
	'2026-11-26' => array( 'closed' => 1, 'note' => '' ),
	'bad-date'   => array( 'closed' => true ),
	'2026-1-1'   => array( 'closed' => true ),
	'2026-07-04' => array( blk( '25:00', '26:00' ) ),
	'2026-07-05' => array(),
	'2026-07-06' => 'junk',
	'2026-07-07' => null,
	'2026-07-08' => array( 'closed' => false ),
	'2026-07-09' => array( 'closed' => '0' ),
) );
ok( array( '2026-12-25', '2026-12-24', '2026-12-31', '2026-11-26' ) === array_keys( $e ), 'exceptions: only valid dates with valid content kept' );
ok( array( 'closed' => true, 'note' => 'Christmas Day' ) === $e['2026-12-25'], 'exceptions: closed with note, HTML stripped' );
ok( array( array( 'open' => '09:00', 'close' => '13:00' ) ) === $e['2026-12-24'], 'exceptions: blocks form kept' );
ok( array( 'closed' => true ) === $e['2026-12-31'] && array( 'closed' => true ) === $e['2026-11-26'], 'exceptions: closed without note has no note key; truthy closed accepted' );
$e = mcw_hours_sanitize_exceptions( array( '2026-12-24' => array( blk( '09:00', '13:00' ), blk( '14:00', '16:00' ) ) ) );
ok( 1 === count( $e['2026-12-24'] ), 'exceptions: only the first block of a blocks-form exception is kept' );
ok( array() === mcw_hours_sanitize_exceptions( 'junk' ) && array() === mcw_hours_sanitize_exceptions( null ) && array() === mcw_hours_sanitize_exceptions( array() ), 'exceptions: non-array input' );
$e = mcw_hours_sanitize_exceptions( array( '2026-12-25' => array( 'closed' => true, 'note' => 'Closed', 'extra' => 'dropped' ) ) );
ok( array( 'closed' => true, 'note' => 'Closed' ) === $e['2026-12-25'], 'exceptions: extra keys dropped' );

/* ---------------- sanitize_ranges ---------------- */

$good = array( 'start' => '2026-09-01', 'end' => '2026-12-18', 'label' => 'Fall <i>term</i>', 'weekly' => wk( array( 1 => array( blk( '08:00', '24:00' ) ) ) ) );
$r = mcw_hours_sanitize_ranges( array(
	$good,
	array( 'start' => '2026-12-19', 'end' => '2026-01-01', 'label' => 'reversed', 'weekly' => wk() ),
	array( 'start' => 'x', 'end' => '2026-01-01' ),
	array( 'start' => '2026-01-01' ),
	array( 'end' => '2026-01-01' ),
	'junk',
	null,
	array( 'start' => '2026-06-01', 'end' => '2026-06-01' ),
) );
ok( 2 === count( $r ), 'ranges: good and same-day rows kept (reversed, bad, missing, junk dropped)' );
ok( 'Fall term' === $r[0]['label'] && '2026-09-01' === $r[0]['start'] && '2026-12-18' === $r[0]['end'], 'ranges: label HTML stripped, dates kept' );
ok( array( blk( '08:00', '24:00' ) ) === $r[0]['weekly']['1'], 'ranges: weekly sanitized and kept' );
ok( '2026-06-01' === $r[1]['start'] && '2026-06-01' === $r[1]['end'] && '' === $r[1]['label'] && wk() === $r[1]['weekly'], 'ranges: one-day range allowed; missing label and weekly default to empty' );
ok( array() === mcw_hours_sanitize_ranges( 'junk' ) && array() === mcw_hours_sanitize_ranges( null ) && array() === mcw_hours_sanitize_ranges( array() ), 'ranges: non-array input' );
$r = mcw_hours_sanitize_ranges( array( array( 'start' => '2026-01-01', 'end' => '2026-01-31', 'weekly' => array( '1' => array( blk( '99:00', '10:00' ) ), '9' => array( blk( '08:00', '09:00' ) ) ), 'extra' => 'x' ) ) );
ok( array( 'start', 'end', 'label', 'weekly' ) === array_keys( $r[0] ) && array() === $r[0]['weekly']['1'] && ! isset( $r[0]['weekly']['9'] ), 'ranges: keys fixed, bad weekly blocks and unknown days dropped' );
$r = mcw_hours_sanitize_ranges( array( array( 'start' => '2026-01-01', 'end' => '2026-01-31', 'weekly' => 'junk' ) ) );
ok( wk() === $r[0]['weekly'], 'ranges: junk weekly becomes seven empty days' );

/* ---------------- sanitize_schedule ---------------- */

ok( array( 'weekly' => wk(), 'exceptions' => array(), 'ranges' => array() ) === mcw_hours_sanitize_schedule( 'junk' ), 'schedule: junk gives empty schedule' );
ok( array( 'weekly' => wk(), 'exceptions' => array(), 'ranges' => array() ) === mcw_hours_sanitize_schedule( null ), 'schedule: null gives empty schedule' );
$s = mcw_hours_sanitize_schedule( array( 'weekly' => wk( array( 2 => array( blk( '09:00', '17:00' ) ) ) ), 'extra' => 'x', 'libraryName' => 'nope' ) );
ok( array( 'weekly', 'exceptions', 'ranges' ) === array_keys( $s ) && array( blk( '09:00', '17:00' ) ) === $s['weekly']['2'], 'schedule: unknown keys dropped, missing keys filled' );

/* ---------------- sanitize (whole payload) ---------------- */

$s = mcw_hours_sanitize( 'junk' );
ok( 'Library' === $s['libraryName'] && 'America/New_York' === $s['timezone'] && wk() === $s['weekly'] && array() === $s['exceptions'] && array() === $s['ranges'] && ! isset( $s['guest'] ), 'sanitize: junk string gives empty config, no guest' );
$s = mcw_hours_sanitize( null );
ok( wk() === $s['weekly'] && ! isset( $s['guest'] ), 'sanitize: null gives empty config' );
$s = mcw_hours_sanitize( array() );
ok( 'Library' === $s['libraryName'] && wk() === $s['weekly'], 'sanitize: missing keys use fallbacks' );
ok( array( 'libraryName', 'timezone', 'weekly', 'exceptions', 'ranges' ) === array_keys( $s ), 'sanitize: key set without guest' );

$in = array(
	'libraryName' => '<script>alert(1)</script>Wallace <b>Library</b>',
	'timezone'    => 'Europe/London',
	'weekly'      => wk( array( 1 => array( blk( '08:00', '24:00' ) ) ) ),
	'exceptions'  => array( '2026-12-25' => array( 'closed' => true, 'note' => 'Closed' ), 'nope' => array( 'closed' => true ) ),
	'ranges'      => array( $good ),
	'evil'        => 'dropped',
);
$s = mcw_hours_sanitize( $in );
ok( 'alert(1)Wallace Library' === $s['libraryName'], 'sanitize: libraryName HTML tags stripped' );
ok( 'Europe/London' === $s['timezone'], 'sanitize: valid timezone kept' );
ok( ! isset( $s['evil'] ), 'sanitize: unknown top-level key dropped' );
ok( array( '2026-12-25' ) === array_keys( $s['exceptions'] ) && 1 === count( $s['ranges'] ), 'sanitize: exceptions and ranges cleaned' );
ok( 'Mars/Olympus' !== mcw_hours_sanitize( array( 'timezone' => 'Mars/Olympus' ) )['timezone'] && 'America/New_York' === mcw_hours_sanitize( array( 'timezone' => 'Mars/Olympus' ) )['timezone'], 'sanitize: bad timezone falls back to America/New_York' );
ok( 'America/New_York' === mcw_hours_sanitize( array( 'timezone' => array( 'UTC' ) ) )['timezone'], 'sanitize: array timezone falls back' );
ok( 'America/New_York' === mcw_hours_sanitize( array( 'timezone' => 'utc' ) )['timezone'], 'sanitize: timezone match is case-sensitive' );
ok( '' === mcw_hours_sanitize( array( 'libraryName' => '' ) )['libraryName'], 'sanitize: empty libraryName stays empty' );

// Guest schedule: only persisted when set and an array.
ok( ! array_key_exists( 'guest', mcw_hours_sanitize( array( 'weekly' => wk() ) ) ), 'guest: absent input, no guest key' );
ok( ! array_key_exists( 'guest', mcw_hours_sanitize( array( 'guest' => null ) ) ), 'guest: null, no guest key' );
ok( ! array_key_exists( 'guest', mcw_hours_sanitize( array( 'guest' => 'junk' ) ) ), 'guest: string, no guest key' );
ok( ! array_key_exists( 'guest', mcw_hours_sanitize( array( 'guest' => false ) ) ), 'guest: false, no guest key' );
$s = mcw_hours_sanitize( array( 'guest' => array() ) );
ok( array_key_exists( 'guest', $s ) && array( 'weekly' => wk(), 'exceptions' => array(), 'ranges' => array() ) === $s['guest'], 'guest: empty array is persisted as an empty schedule' );
$s = mcw_hours_sanitize( array(
	'weekly' => wk( array( 1 => array( blk( '08:00', '24:00' ) ) ) ),
	'guest'  => array(
		'weekly'     => wk( array( 2 => array( blk( '10:00', '14:00' ), blk( '99:00', '14:00' ) ) ) ),
		'exceptions' => array( '2026-12-25' => array( 'closed' => true, 'note' => '<b>x</b>' ), 'bad' => array( 'closed' => true ) ),
		'ranges'     => array( $good, 'junk' ),
		'extra'      => 'dropped',
	),
) );
ok( array( 'weekly', 'exceptions', 'ranges' ) === array_keys( $s['guest'] ), 'guest: keys fixed, unknown dropped' );
ok( array( blk( '10:00', '14:00' ) ) === $s['guest']['weekly']['2'] && array() === $s['guest']['weekly']['1'], 'guest: weekly sanitized and independent of library weekly' );
ok( array( 'closed' => true, 'note' => 'x' ) === $s['guest']['exceptions']['2026-12-25'] && 1 === count( $s['guest']['exceptions'] ) && 1 === count( $s['guest']['ranges'] ), 'guest: exceptions and ranges sanitized' );
ok( array( blk( '08:00', '24:00' ) ) === $s['weekly']['1'], 'guest: library weekly untouched' );

// Idempotent: sanitizing clean output changes nothing.
ok( mcw_hours_sanitize( $s ) === $s, 'sanitize is idempotent' );

/* ---------------- regular and guest accessors ---------------- */

$opt = array( 'weekly' => wk(), 'exceptions' => array(), 'ranges' => array( $good ), 'guest' => array( 'weekly' => wk() ) );
ok( array( 'weekly', 'exceptions', 'ranges' ) === array_keys( mcw_hours_regular( $opt ) ) && array( $good ) === mcw_hours_regular( $opt )['ranges'], 'regular: top-level schedule only' );
ok( array( 'weekly' => array(), 'exceptions' => array(), 'ranges' => array() ) === mcw_hours_regular( array() ), 'regular: empty option' );
ok( array( 'weekly' => wk() ) === mcw_hours_guest( $opt ), 'guest accessor: returns guest schedule' );
ok( null === mcw_hours_guest( array() ) && null === mcw_hours_guest( array( 'guest' => 'x' ) ) && null === mcw_hours_guest( array( 'guest' => null ) ), 'guest accessor: null when not an array' );

/* ---------------- resolve ---------------- */

// 2026-10-28 is a Wednesday (day 3).
$fall = array( 'start' => '2026-10-01', 'end' => '2026-10-31', 'label' => 'Fall', 'weekly' => wk( array( 3 => array( blk( '09:00', '21:00' ) ) ) ) );
$late = array( 'start' => '2026-10-25', 'end' => '2026-11-05', 'label' => 'Late', 'weekly' => wk( array( 3 => array( blk( '10:00', '16:00' ) ) ) ) );
$sch  = array(
	'weekly'     => wk( array( 3 => array( blk( '08:00', '24:00' ) ), 4 => array( blk( '08:00', '12:00' ), blk( '13:00', '17:00' ) ) ) ),
	'exceptions' => array(),
	'ranges'     => array(),
);
$r = mcw_hours_resolve( $sch, '2026-10-28' );
ok( array( 'blocks' => array( blk( '08:00', '24:00' ) ), 'note' => '', 'closed' => false ) === $r, 'resolve: weekly default' );
$r = mcw_hours_resolve( $sch, '2026-10-29' );
ok( 2 === count( $r['blocks'] ) && false === $r['closed'], 'resolve: weekly with two blocks' );
$r = mcw_hours_resolve( $sch, '2026-10-30' );
ok( array( 'blocks' => array(), 'note' => '', 'closed' => true ) === $r, 'resolve: weekly day with no blocks is closed' );
$r = mcw_hours_resolve( $sch, '2026-11-01' );
ok( true === $r['closed'], 'resolve: Sunday (day 0) uses day "0"' );

$sch['ranges'] = array( $fall );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: range beats weekly' );
ok( true === mcw_hours_resolve( $sch, '2026-10-29' )['closed'] && array() === mcw_hours_resolve( $sch, '2026-10-29' )['blocks'], 'resolve: range day with no blocks is closed (does not fall back to weekly)' );
ok( array( blk( '08:00', '24:00' ) ) === mcw_hours_resolve( $sch, '2026-11-04' )['blocks'], 'resolve: outside the range, weekly again' );
// Boundaries are inclusive on both ends.
$edge = array( 'weekly' => wk( array( 4 => array( blk( '08:00', '09:00' ) ), 5 => array( blk( '08:00', '09:00' ) ), 6 => array( blk( '08:00', '09:00' ) ) ) ),
	'ranges' => array( array( 'start' => '2026-10-30', 'end' => '2026-10-31', 'weekly' => wk( array( 5 => array( blk( '11:00', '12:00' ) ), 6 => array( blk( '13:00', '14:00' ) ) ) ) ) ) );
ok( array( blk( '11:00', '12:00' ) ) === mcw_hours_resolve( $edge, '2026-10-30' )['blocks'], 'resolve: range start date is inclusive' );
ok( array( blk( '13:00', '14:00' ) ) === mcw_hours_resolve( $edge, '2026-10-31' )['blocks'], 'resolve: range end date is inclusive' );
ok( array( blk( '08:00', '09:00' ) ) === mcw_hours_resolve( $edge, '2026-10-29' )['blocks'], 'resolve: day before range start uses weekly' );
ok( true === mcw_hours_resolve( $edge, '2026-11-01' )['closed'], 'resolve: day after range end uses weekly (Sunday closed here)' );

// Overlapping ranges: the LAST matching range wins (changelog 1.1.13; CLAUDE.md still says "first").
$sch['ranges'] = array( $fall, $late );
ok( array( blk( '10:00', '16:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: overlapping ranges, last one wins' );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-21' )['blocks'], 'resolve: only the first range covers 10-21, so it applies there' );
$sch['ranges'] = array( $late, $fall );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: order decides, not dates' );
$sch['ranges'] = array( 'junk', array( 'start' => '2026-10-01' ), $fall );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: malformed ranges ignored' );

// Exceptions beat ranges beat weekly.
$sch['ranges']     = array( $fall );
$sch['exceptions'] = array( '2026-10-28' => array( blk( '12:00', '13:00' ) ) );
$r = mcw_hours_resolve( $sch, '2026-10-28' );
ok( array( 'blocks' => array( blk( '12:00', '13:00' ) ), 'note' => '', 'closed' => false ) === $r, 'resolve: hours exception beats range and weekly' );
$sch['exceptions'] = array( '2026-10-28' => array( 'closed' => true, 'note' => 'Power outage' ) );
$r = mcw_hours_resolve( $sch, '2026-10-28' );
ok( array( 'blocks' => array(), 'note' => 'Power outage', 'closed' => true ) === $r, 'resolve: closed exception beats range, carries its note' );
$sch['exceptions'] = array( '2026-10-28' => array( 'closed' => true ) );
ok( '' === mcw_hours_resolve( $sch, '2026-10-28' )['note'] && true === mcw_hours_resolve( $sch, '2026-10-28' )['closed'], 'resolve: closed exception without note' );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-21' )['blocks'], 'resolve: exception only affects its own date' );
$sch['exceptions'] = array( '2026-10-28' => 'junk' );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: malformed exception falls through to the range' );
$sch['exceptions'] = array( '2026-10-28' => array() );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: empty exception falls through' );
$sch['exceptions'] = array( '2026-10-28' => array( 'closed' => false ) );
ok( array( blk( '09:00', '21:00' ) ) === mcw_hours_resolve( $sch, '2026-10-28' )['blocks'], 'resolve: closed=false exception falls through' );

// Junk schedules.
$r = mcw_hours_resolve( array(), '2026-10-28' );
ok( array( 'blocks' => array(), 'note' => '', 'closed' => true ) === $r, 'resolve: empty schedule is closed' );
ok( array() === mcw_hours_resolve( array( 'weekly' => 'junk', 'exceptions' => 'junk', 'ranges' => 'junk' ), '2026-10-28' )['blocks'], 'resolve: junk sections give no blocks' );
// Suspected bug: a string "weekly" is indexed as a string offset ("junk"[3] is "k"), so the result is
// no blocks but closed=false. Only reachable from a malformed stored option, never from the sanitizer.
ok( false === mcw_hours_resolve( array( 'weekly' => 'junk' ), '2026-10-28' )['closed'], 'CURRENT BEHAVIOUR (suspected bug): string weekly gives no blocks yet closed=false' );
ok( array( 'blocks' => array(), 'note' => '', 'closed' => false ) === mcw_hours_resolve( array( 'weekly' => array( '3' => 'junk' ) ), '2026-10-28' ), 'CURRENT BEHAVIOUR (suspected bug): non-array day value gives no blocks yet closed=false' );

// Guest schedule resolves independently of the library schedule.
$opt = array(
	'weekly'     => wk( array( 3 => array( blk( '08:00', '24:00' ) ) ) ),
	'exceptions' => array( '2026-10-28' => array( 'closed' => true, 'note' => 'Library only' ) ),
	'ranges'     => array(),
	'guest'      => array( 'weekly' => wk( array( 3 => array( blk( '10:00', '14:00' ) ) ) ), 'exceptions' => array(), 'ranges' => array() ),
);
ok( true === mcw_hours_resolve( mcw_hours_regular( $opt ), '2026-10-28' )['closed'], 'guest: library schedule closed by its exception' );
ok( array( blk( '10:00', '14:00' ) ) === mcw_hours_resolve( mcw_hours_guest( $opt ), '2026-10-28' )['blocks'], 'guest: guest schedule ignores library exception' );

/* ---------------- REST callbacks ---------------- */

foreach ( $GLOBALS['wph_actions']['rest_api_init'] as $cb ) { $cb(); }
ok( 1 === count( $GLOBALS['wph_routes'] ), 'one REST route registered' );
list( $ns, $route, $defs ) = $GLOBALS['wph_routes'][0];
ok( 'mcw-hours/v1' === $ns && '/hours' === $route && 2 === count( $defs ), 'REST namespace, route and two handlers' );
$get = $defs[0]; $post = $defs[1];
ok( 'GET' === $get['methods'] && 'POST' === $post['methods'], 'REST methods GET and POST' );
ok( true === call_user_func( $get['permission_callback'] ), 'GET is public' );

$GLOBALS['wph_options'] = array( MCW_HOURS_OPTION => array( 'libraryName' => 'Stored', 'weekly' => wk(), 'exceptions' => array(), 'ranges' => array() ) );
ok( 'Stored' === call_user_func( $get['callback'] )['libraryName'], 'GET returns the stored option' );
$GLOBALS['wph_options'] = array();
ok( mcw_hours_default()['weekly'] === call_user_func( $get['callback'] )['weekly'], 'GET with nothing stored returns defaults' );

$GLOBALS['wph_caps'] = array();
ok( false === call_user_func( $post['permission_callback'] ), 'POST denied without edit_pages' );
$GLOBALS['wph_caps'] = array( 'read' => true );
ok( false === call_user_func( $post['permission_callback'] ), 'POST denied with other capabilities' );
$GLOBALS['wph_caps'] = array( 'edit_pages' => true );
ok( true === call_user_func( $post['permission_callback'] ), 'POST allowed with edit_pages' );

$GLOBALS['wph_options'] = array();
$res = call_user_func( $post['callback'], new WP_REST_Request( array(
	'libraryName' => '<b>Lib</b>',
	'weekly'      => wk( array( 1 => array( blk( '08:00', '17:00' ), blk( '30:00', '31:00' ) ) ) ),
	'exceptions'  => array( 'bad' => array( 'closed' => true ) ),
	'guest'       => 'junk',
	'evil'        => 'x',
) ) );
$saved = get_option( MCW_HOURS_OPTION );
ok( array( 'ok' => true ) === $res, 'POST returns ok' );
ok( 'Lib' === $saved['libraryName'] && array( blk( '08:00', '17:00' ) ) === $saved['weekly']['1'] && array() === $saved['exceptions'] && ! isset( $saved['evil'] ) && ! isset( $saved['guest'] ), 'POST sanitizes before saving' );
ok( $saved === mcw_hours_sanitize( $saved ), 'POST saved value is sanitized form' );

$GLOBALS['wph_options'] = array();
foreach ( array( null, 'junk', array(), array( 'exceptions' => array() ) ) as $i => $body ) {
	$res = call_user_func( $post['callback'], new WP_REST_Request( $body ) );
	ok( $res instanceof WP_Error && 'mcw_bad_data' === $res->code && 400 === $res->data['status'], "POST bad body #$i rejected with 400" );
}
ok( array() === $GLOBALS['wph_options'], 'rejected POSTs save nothing' );

$GLOBALS['wph_options'] = array();
call_user_func( $post['callback'], new WP_REST_Request( array( 'weekly' => wk(), 'guest' => array( 'weekly' => wk( array( 2 => array( blk( '10:00', '12:00' ) ) ) ) ) ) ) );
ok( isset( get_option( MCW_HOURS_OPTION )['guest'] ) && array( blk( '10:00', '12:00' ) ) === get_option( MCW_HOURS_OPTION )['guest']['weekly']['2'], 'POST persists a guest schedule when sent' );

/* ---------------- admin page registration ---------------- */

foreach ( $GLOBALS['wph_actions']['admin_menu'] as $cb ) { $cb(); }
ok( 1 === count( $GLOBALS['wph_menus'] ) && 'edit_pages' === $GLOBALS['wph_menus'][0][2] && 'mcw-library-hours' === $GLOBALS['wph_menus'][0][3], 'admin menu requires edit_pages' );
ob_start();
mcw_hours_admin_page();
$h = ob_get_clean();
ok( false !== strpos( $h, 'window.MCW_ADMIN={"root":"https:\/\/example.test\/wp-json\/mcw-hours\/v1\/hours","nonce":"nonce123"};' ), 'admin page passes config through wp_json_encode' );

/* ---------------- shared JS resolver and shortcodes ---------------- */

$js = mcw_hours_resolve_js();
ok( false !== strpos( $js, 'function mcwResolve(' ) && false !== strpos( $js, 'function mcwSchedule(' ), 'shared JS resolver defines mcwResolve and mcwSchedule' );

$h = mcw_hours_shortcode( array() );
ok( is_string( $h ) && false !== strpos( $h, 'id="mcw-hours"' ), 'widget shortcode returns markup' );
ok( false !== strpos( $h, 'window.MCW_HOURS_CFG={"url":"https:\/\/example.test\/wp-json\/mcw-hours\/v1\/hours","full":"\/hours\/"};' ), 'widget config is wp_json_encode output with default full link' );
ok( false !== strpos( $h, 'function mcwResolve(' ) && false === strpos( $h, '$resolve' ), 'widget script has the resolver interpolated' );
$h = mcw_hours_shortcode( array( 'full' => '/our-hours/?a=1&b=2' ) );
ok( false !== strpos( $h, '"full":"\/our-hours\/?a=1&amp;b=2"' ), 'widget full attribute is esc_url-ed then JSON-encoded' );
$h = mcw_hours_shortcode( '' );
ok( false !== strpos( $h, '"full":"\/hours\/"' ), 'widget shortcode tolerates empty-string attributes' );
$h = mcw_hours_shortcode( array( 'full' => '"</script><script>alert(1)</script>' ) );
ok( 1 === substr_count( $h, '<script>window.MCW_HOURS_CFG=' ) && false === strpos( $h, '</script><script>alert(1)' ), 'widget: injected script in attribute does not break out of the config tag' );

$h = mcw_hours_full_shortcode( array() );
ok( is_string( $h ) && false !== strpos( $h, 'id="mcw-hours-full"' ), 'full shortcode returns markup' );
ok( false !== strpos( $h, 'window.MCW_HOURS_FULL={"url":"https:\/\/example.test\/wp-json\/mcw-hours\/v1\/hours"};' ), 'full config is wp_json_encode output' );
ok( false !== strpos( $h, 'function mcwResolve(' ), 'full script has the resolver interpolated' );

echo "\n$passes passed, $fails failed\n";
exit( $fails ? 1 : 0 );
