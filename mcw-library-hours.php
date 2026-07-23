<?php
/**
 * Plugin Name:       MCW Library Hours
 * Description:        No-code library hours: staff edit hours under Library Hours in wp-admin and click Save. Show them anywhere with the [library_hours] shortcode. Replaces LibCal hours.
 * Version:           1.1.12
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MCW_HOURS_OPTION', 'mcw_library_hours' );

/* ------------------------------------------------------------------ *
 *  Data
 * ------------------------------------------------------------------ */

function mcw_hours_default() {
	return array(
		'libraryName' => 'Madeleine Clark Wallace Library',
		'timezone'    => 'America/New_York',
		'weekly'      => array(
			'0' => array( array( 'open' => '12:00', 'close' => '24:00' ) ),
			'1' => array( array( 'open' => '08:00', 'close' => '24:00' ) ),
			'2' => array( array( 'open' => '08:00', 'close' => '24:00' ) ),
			'3' => array( array( 'open' => '08:00', 'close' => '24:00' ) ),
			'4' => array( array( 'open' => '08:00', 'close' => '24:00' ) ),
			'5' => array( array( 'open' => '08:00', 'close' => '18:00' ) ),
			'6' => array( array( 'open' => '10:00', 'close' => '18:00' ) ),
		),
		'exceptions'  => array(),
	);
}

function mcw_hours_get() {
	$v = get_option( MCW_HOURS_OPTION );
	if ( empty( $v ) || ! is_array( $v ) ) { $v = mcw_hours_default(); }
	if ( ! isset( $v['weekly'] ) )     { $v['weekly'] = array(); }
	if ( ! isset( $v['exceptions'] ) ) { $v['exceptions'] = array(); }
	if ( ! isset( $v['ranges'] ) )     { $v['ranges'] = array(); }
	return $v;
}

/** Validate "HH:MM" (00:00–24:00). */
function mcw_hours_valid_time( $t ) {
	return is_string( $t ) && preg_match( '/^(?:[01][0-9]|2[0-4]):[0-5][0-9]$/', $t );
}

/** Validate "YYYY-MM-DD". */
function mcw_hours_valid_date( $d ) {
	return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
}

/** Sanitize a weekly map {"0".."6":[{open,close}]}. */
function mcw_hours_sanitize_weekly( $weekly ) {
	$out    = array();
	$weekly = is_array( $weekly ) ? $weekly : array();
	for ( $d = 0; $d < 7; $d++ ) {
		$key    = (string) $d;
		$blocks = isset( $weekly[ $key ] ) && is_array( $weekly[ $key ] ) ? $weekly[ $key ] : array();
		$clean  = array();
		foreach ( $blocks as $b ) {
			if ( is_array( $b ) && isset( $b['open'], $b['close'] )
				&& mcw_hours_valid_time( $b['open'] ) && mcw_hours_valid_time( $b['close'] ) ) {
				$clean[] = array( 'open' => $b['open'], 'close' => $b['close'] );
			}
		}
		$out[ $key ] = $clean;
	}
	return $out;
}

/** Sanitize an exceptions map {"YYYY-MM-DD": {closed,note} | [{open,close}]}. */
function mcw_hours_sanitize_exceptions( $exc ) {
	$out = array();
	$exc = is_array( $exc ) ? $exc : array();
	foreach ( $exc as $date => $val ) {
		if ( ! mcw_hours_valid_date( (string) $date ) ) { continue; }
		if ( isset( $val['closed'] ) && $val['closed'] ) {
			$entry = array( 'closed' => true );
			if ( ! empty( $val['note'] ) ) { $entry['note'] = sanitize_text_field( $val['note'] ); }
			$out[ $date ] = $entry;
		} elseif ( is_array( $val ) && isset( $val[0]['open'], $val[0]['close'] )
			&& mcw_hours_valid_time( $val[0]['open'] ) && mcw_hours_valid_time( $val[0]['close'] ) ) {
			$out[ $date ] = array( array( 'open' => $val[0]['open'], 'close' => $val[0]['close'] ) );
		}
	}
	return $out;
}

/** Sanitize term ranges: [{start,end,label,weekly}]; drop rows with bad/reversed dates. */
function mcw_hours_sanitize_ranges( $ranges ) {
	$out    = array();
	$ranges = is_array( $ranges ) ? $ranges : array();
	foreach ( $ranges as $r ) {
		if ( ! is_array( $r ) ) { continue; }
		$start = isset( $r['start'] ) ? (string) $r['start'] : '';
		$end   = isset( $r['end'] ) ? (string) $r['end'] : '';
		if ( ! mcw_hours_valid_date( $start ) || ! mcw_hours_valid_date( $end ) || $end < $start ) { continue; }
		$out[] = array(
			'start'  => $start,
			'end'    => $end,
			'label'  => isset( $r['label'] ) ? sanitize_text_field( $r['label'] ) : '',
			'weekly' => mcw_hours_sanitize_weekly( isset( $r['weekly'] ) ? $r['weekly'] : array() ),
		);
	}
	return $out;
}

/** Sanitize one schedule object {weekly, exceptions, ranges}. */
function mcw_hours_sanitize_schedule( $s ) {
	$s = is_array( $s ) ? $s : array();
	return array(
		'weekly'     => mcw_hours_sanitize_weekly( isset( $s['weekly'] ) ? $s['weekly'] : array() ),
		'exceptions' => mcw_hours_sanitize_exceptions( isset( $s['exceptions'] ) ? $s['exceptions'] : array() ),
		'ranges'     => mcw_hours_sanitize_ranges( isset( $s['ranges'] ) ? $s['ranges'] : array() ),
	);
}

/** Whitelist + validate incoming data so only clean hours are stored. */
function mcw_hours_sanitize( $in ) {
	$out = array(
		'libraryName' => 'Library',
		'timezone'    => 'America/New_York',
		'weekly'      => array(),
		'exceptions'  => array(),
		'ranges'      => array(),
	);
	if ( isset( $in['libraryName'] ) ) {
		$out['libraryName'] = sanitize_text_field( $in['libraryName'] );
	}
	if ( isset( $in['timezone'] ) && in_array( $in['timezone'], timezone_identifiers_list(), true ) ) {
		$out['timezone'] = $in['timezone'];
	}

	$reg = mcw_hours_sanitize_schedule( array(
		'weekly'     => isset( $in['weekly'] ) ? $in['weekly'] : array(),
		'exceptions' => isset( $in['exceptions'] ) ? $in['exceptions'] : array(),
		'ranges'     => isset( $in['ranges'] ) ? $in['ranges'] : array(),
	) );
	$out['weekly']     = $reg['weekly'];
	$out['exceptions'] = $reg['exceptions'];
	$out['ranges']     = $reg['ranges'];

	// Guest schedule is optional; only stored when present (staff added it).
	if ( isset( $in['guest'] ) && is_array( $in['guest'] ) ) {
		$out['guest'] = mcw_hours_sanitize_schedule( $in['guest'] );
	}
	return $out;
}

/** The regular (building) schedule = top-level weekly/exceptions/ranges. */
function mcw_hours_regular( $opt ) {
	return array(
		'weekly'     => isset( $opt['weekly'] ) ? $opt['weekly'] : array(),
		'exceptions' => isset( $opt['exceptions'] ) ? $opt['exceptions'] : array(),
		'ranges'     => isset( $opt['ranges'] ) ? $opt['ranges'] : array(),
	);
}
/** The guest schedule, or null when not configured. */
function mcw_hours_guest( $opt ) {
	return ( isset( $opt['guest'] ) && is_array( $opt['guest'] ) ) ? $opt['guest'] : null;
}

/**
 * Resolve a schedule for a date. Precedence: exception > first matching range > weekly.
 * Returns ['blocks'=>[{open,close}...], 'note'=>string, 'closed'=>bool].
 */
function mcw_hours_resolve( $schedule, $date ) {
	$exc = isset( $schedule['exceptions'] ) && is_array( $schedule['exceptions'] ) ? $schedule['exceptions'] : array();
	if ( isset( $exc[ $date ] ) ) {
		$e = $exc[ $date ];
		if ( isset( $e['closed'] ) && $e['closed'] ) {
			return array( 'blocks' => array(), 'note' => isset( $e['note'] ) ? $e['note'] : '', 'closed' => true );
		}
		if ( is_array( $e ) && isset( $e[0]['open'], $e[0]['close'] ) ) {
			return array( 'blocks' => array( array( 'open' => $e[0]['open'], 'close' => $e[0]['close'] ) ), 'note' => '', 'closed' => false );
		}
	}
	$ranges = isset( $schedule['ranges'] ) && is_array( $schedule['ranges'] ) ? $schedule['ranges'] : array();
	$dow    = (int) gmdate( 'w', strtotime( $date . ' 00:00:00' ) );
	foreach ( $ranges as $r ) {
		if ( isset( $r['start'], $r['end'] ) && $date >= $r['start'] && $date <= $r['end'] ) {
			$wk = isset( $r['weekly'][ (string) $dow ] ) ? $r['weekly'][ (string) $dow ] : array();
			return array( 'blocks' => is_array( $wk ) ? $wk : array(), 'note' => '', 'closed' => empty( $wk ) );
		}
	}
	$weekly = isset( $schedule['weekly'][ (string) $dow ] ) ? $schedule['weekly'][ (string) $dow ] : array();
	return array( 'blocks' => is_array( $weekly ) ? $weekly : array(), 'note' => '', 'closed' => empty( $weekly ) );
}

/* ------------------------------------------------------------------ *
 *  REST API   (GET = public read for the widget, POST = save)
 * ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'mcw-hours/v1', '/hours', array(
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( mcw_hours_get() );
			},
			'permission_callback' => '__return_true',
		),
		array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $req ) {
				$body = $req->get_json_params();
				if ( ! is_array( $body ) || ! isset( $body['weekly'] ) ) {
					return new WP_Error( 'mcw_bad_data', 'Invalid hours data.', array( 'status' => 400 ) );
				}
				update_option( MCW_HOURS_OPTION, mcw_hours_sanitize( $body ) );
				return rest_ensure_response( array( 'ok' => true ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
	) );
} );

/* ------------------------------------------------------------------ *
 *  Admin editor page
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page(
		'Library Hours',
		'Library Hours',
		'edit_pages',
		'mcw-library-hours',
		'mcw_hours_admin_page',
		'dashicons-clock',
		30
	);
} );

function mcw_hours_admin_page() {
	$root  = esc_url_raw( rest_url( 'mcw-hours/v1/hours' ) );
	$nonce = wp_create_nonce( 'wp_rest' );

	echo '<div class="wrap"><h1>Library Hours</h1>';
	echo '<p style="max-width:680px;color:#555">Set your hours below and click <strong>Save &amp; publish</strong>. Changes go live on the website immediately — no files, no code. Add the hours to any page or widget with the shortcode <code>[library_hours]</code>.</p>';

	// Config for the editor JS (safely JSON-encoded, no interpolation issues).
	echo '<script>window.MCW_ADMIN=' . wp_json_encode( array( 'root' => $root, 'nonce' => $nonce ) ) . ';</script>';

	echo mcw_hours_editor_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

/** The editor UI + JS. Nowdoc => no PHP interpolation of $ in JS. */
function mcw_hours_editor_markup() {
	return <<<'HTML'
<style>
  #mcwed{--accent:#00539b;--line:#dcdcdc;--muted:#666;--ok:#1a7f37;max-width:820px;font-size:14px}
  #mcwed .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin:16px 0}
  #mcwed h2{font-size:1.05rem;margin:0 0 12px}
  #mcwed .step{display:inline-block;background:var(--accent);color:#fff;border-radius:999px;width:22px;height:22px;text-align:center;line-height:22px;font-size:.8rem;margin-right:8px}
  #mcwed select,#mcwed input[type=text],#mcwed input[type=date]{font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:#fff}
  #mcwed .day{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid #eee;flex-wrap:wrap}
  #mcwed .day:first-of-type{border-top:none}
  #mcwed .day__name{width:96px;font-weight:600}
  #mcwed .toggle{display:flex;align-items:center;gap:6px;font-weight:600;font-size:.85rem;color:var(--muted)}
  #mcwed .blocks{display:flex;flex-direction:column;gap:6px;flex:1;min-width:220px}
  #mcwed .block{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  #mcwed .to{color:var(--muted)}
  #mcwed .b{cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff;padding:8px 14px;border-radius:7px;font-weight:600;font-size:.9rem}
  #mcwed .b.sec{background:#fff;color:var(--accent)}
  #mcwed .b.ghost{background:#fff;color:var(--muted);border-color:var(--line)}
  #mcwed .b.tiny{padding:3px 9px;font-size:.8rem;border-radius:5px}
  #mcwed .exc{display:flex;align-items:center;gap:8px;padding:8px 0;border-top:1px solid #eee;flex-wrap:wrap}
  #mcwed .exc:first-of-type{border-top:none}
  #mcwed .hint{font-size:.82rem;color:var(--muted);margin:6px 0 0}
  #mcwed .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
  #mcwed .status{font-weight:600}
  #mcwed .b.sched{background:#fff;color:var(--accent)}
  #mcwed .b.sched.on{background:var(--accent);color:#fff}
  #mcwed .calnote{font-size:.72rem;font-weight:600;color:var(--accent);background:#eef4fb;border-radius:999px;padding:2px 10px;margin-left:6px;vertical-align:middle}
  #mcwed .rrow{display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid #eee}
  #mcwed .rrow:first-of-type{border-top:none}
  #mcwed .radd{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-top:10px}
  #mcwed .radd label{font-size:.82rem;color:var(--muted)}
</style>

<div id="mcwed">
  <div class="card">
    <h2><span class="step">1</span>Choose a calendar to edit</h2>
    <div id="mcw-cal-target" class="bar"></div>
    <p class="hint"><strong>Library Hours</strong> is the main building schedule — it feeds the homepage widget and room booking. <strong>Guest Hours</strong> is a separate schedule patrons can switch to on the hours page; use it if guests have different hours. A brand-new Guest schedule starts as a copy of your Library weekly hours, so you can just adjust it below. Everything below — weekly hours, date ranges, holidays — applies to whichever calendar is selected here.</p>
  </div>

  <div class="card">
    <h2><span class="step">2</span>Set hours <span id="mcw-week-cal" class="calnote"></span></h2>
    <div id="mcw-week"></div>
    <p class="hint">These are the everyday hours for the selected calendar. Uncheck <em>Open</em> to mark a day closed. Use <em>+ add hours</em> for a split day. Choose <em>Midnight (end of day)</em> for late-night or 24-hour service.</p>
  </div>

  <div class="card">
    <h2><span class="step">3</span>Apply these hours to a date range</h2>
    <div id="mcw-ranges"></div>
    <div class="radd">
      <label>Start date<br><input id="mcw-r-start" type="date"></label>
      <label>End date<br><input id="mcw-r-end" type="date"></label>
    </div>
    <p class="hint">Use this to set term or seasonal hours in advance (e.g. Fall Semester). Set the hours in <em>Set hours</em> above, enter a start and end date here, then click <strong>Save &amp; publish</strong>. Those hours apply to that span only — <strong>your everyday hours stay unchanged</strong>. Leave the dates empty to have Save &amp; publish update the everyday hours instead. Ranges override the everyday hours; holidays below still override ranges.</p>
  </div>

  <div class="card">
    <h2><span class="step">4</span>Holidays &amp; special dates</h2>
    <div id="mcw-exc"></div>
    <div class="bar"><button type="button" class="b sec" id="mcw-addexc">+ Add a holiday or special date</button></div>
    <p class="hint">These override the weekly schedule (and any date range) for a single date. Add a note (e.g. "Thanksgiving") to tell visitors why.</p>
  </div>

  <div class="bar">
    <button type="button" class="b" id="mcw-save">Save &amp; publish</button>
    <button type="button" class="b ghost" id="mcw-reset">Reset hours to standard</button>
    <span class="status" id="mcw-status"></span>
  </div>
</div>

<script>
(function(){
"use strict";
var DAYS=["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
function opts(){var a=[];for(var m=0;m<1440;m+=30){var h=Math.floor(m/60),mm=m%60,ap=h<12?"AM":"PM",hh=h%12;if(hh===0)hh=12;var v=(h<10?"0"+h:h)+":"+(mm<10?"0"+mm:mm);var l=hh+":"+(mm<10?"0"+mm:mm)+" "+ap;if(m===0)l="12:00 AM (midnight)";if(m===720)l="12:00 PM (noon)";a.push({v:v,l:l});}a.push({v:"24:00",l:"Midnight (end of day)"});return a;}
var T=opts();
function tsel(sel){var s=document.createElement("select");T.forEach(function(o){var e=document.createElement("option");e.value=o.v;e.textContent=o.l;if(o.v===sel)e.selected=true;s.appendChild(e);});return s;}
function clone(o){return JSON.parse(JSON.stringify(o));}
var STD={"0":[{open:"12:00",close:"24:00"}],"1":[{open:"08:00",close:"24:00"}],"2":[{open:"08:00",close:"24:00"}],"3":[{open:"08:00",close:"24:00"}],"4":[{open:"08:00",close:"24:00"}],"5":[{open:"08:00",close:"18:00"}],"6":[{open:"10:00",close:"18:00"}]};
var meta={libraryName:"Madeleine Clark Wallace Library",timezone:"America/New_York"};
var sched={library:{weekly:clone(STD),ranges:[],exceptions:[]},guest:null};
var target="library";
var work=clone(STD);        // the "Set hours" grid (block 2), separate from each schedule's stored everyday weekly
var guestCommitted=false;   // only save guest once the user actually sets it

function buildWeek(weekly,host){
  host.innerHTML="";
  for(var d=0;d<7;d++){(function(d){
    var blocks=weekly[String(d)]||[];var open=blocks.length>0;
    var row=document.createElement("div");row.className="day";
    var nm=document.createElement("div");nm.className="day__name";nm.textContent=DAYS[d];row.appendChild(nm);
    var tg=document.createElement("label");tg.className="toggle";
    var cb=document.createElement("input");cb.type="checkbox";cb.checked=open;
    cb.onchange=function(){weekly[String(d)]=cb.checked?[{open:"08:00",close:"17:00"}]:[];buildWeek(weekly,host);};
    tg.appendChild(cb);tg.appendChild(document.createTextNode("Open"));row.appendChild(tg);
    var bw=document.createElement("div");bw.className="blocks";
    if(!open){var c=document.createElement("span");c.className="to";c.textContent="Closed";bw.appendChild(c);}
    else{blocks.forEach(function(b,i){
      var bl=document.createElement("div");bl.className="block";
      var os=tsel(b.open);os.onchange=function(){b.open=os.value;};
      var to=document.createElement("span");to.className="to";to.textContent="to";
      var cs=tsel(b.close);cs.onchange=function(){b.close=cs.value;};
      bl.appendChild(os);bl.appendChild(to);bl.appendChild(cs);
      if(blocks.length>1){var rm=document.createElement("button");rm.type="button";rm.className="b ghost tiny";rm.textContent="remove";rm.onclick=function(){blocks.splice(i,1);buildWeek(weekly,host);};bl.appendChild(rm);}
      bw.appendChild(bl);
    });
    var add=document.createElement("button");add.type="button";add.className="b ghost tiny";add.textContent="+ add hours";add.onclick=function(){blocks.push({open:"13:00",close:"17:00"});buildWeek(weekly,host);};bw.appendChild(add);}
    row.appendChild(bw);host.appendChild(row);
  })(d);}
}
function fmtDate(s){if(!s)return s;var p=s.split("-");if(p.length!==3)return s;var M=["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];return M[parseInt(p[1],10)-1]+" "+parseInt(p[2],10)+", "+p[0];}
function cur(){return target==="guest"?sched.guest:sched.library;}
function switchTarget(t){
  if(t==="guest"&&!sched.guest){sched.guest={weekly:clone(sched.library.weekly),ranges:[],exceptions:[]};}
  target=t;work=clone(cur().weekly);
  var si=document.getElementById("mcw-r-start"),ei=document.getElementById("mcw-r-end");if(si)si.value="";if(ei)ei.value="";
  renderAll();
}
function renderTabs(){
  var host=document.getElementById("mcw-cal-target");host.innerHTML="";
  [["library","Library Hours"],["guest","Guest Hours"]].forEach(function(o){
    var b=document.createElement("button");b.type="button";b.className="b sched"+(target===o[0]?" on":"");b.textContent=o[1];
    b.onclick=function(){switchTarget(o[0]);};
    host.appendChild(b);
  });
}
function renderWeek(){document.getElementById("mcw-week-cal").textContent=target==="guest"?"Guest Hours":"Library Hours";buildWeek(work,document.getElementById("mcw-week"));}
function renderRanges(){
  var w=document.getElementById("mcw-ranges");w.innerHTML="";var ranges=cur().ranges;
  if(ranges.length===0){var p=document.createElement("p");p.className="hint";p.textContent="No date ranges yet — the hours above apply to every date on this calendar.";w.appendChild(p);return;}
  ranges.forEach(function(r,i){
    var row=document.createElement("div");row.className="rrow";
    var span=document.createElement("span");span.textContent=fmtDate(r.start)+" – "+fmtDate(r.end);row.appendChild(span);
    var rm=document.createElement("button");rm.type="button";rm.className="b ghost tiny";rm.textContent="remove";rm.onclick=function(){cur().ranges.splice(i,1);renderRanges();flash("Range removed — click Save & publish to make it live.");};row.appendChild(rm);
    w.appendChild(row);
  });
}
function renderExc(){
  var w=document.getElementById("mcw-exc");w.innerHTML="";var exc=cur().exceptions;
  if(exc.length===0){var p=document.createElement("p");p.className="hint";p.textContent="No special dates yet.";w.appendChild(p);}
  exc.forEach(function(ex,i){
    var row=document.createElement("div");row.className="exc";
    var dt=document.createElement("input");dt.type="date";dt.value=ex.date||"";dt.onchange=function(){ex.date=dt.value;};row.appendChild(dt);
    var kd=document.createElement("select");[["closed","Closed"],["hours","Special hours"]].forEach(function(o){var e=document.createElement("option");e.value=o[0];e.textContent=o[1];if(ex.kind===o[0])e.selected=true;kd.appendChild(e);});
    kd.onchange=function(){ex.kind=kd.value;if(ex.kind==="hours"&&!ex.open){ex.open="09:00";ex.close="17:00";}renderExc();};row.appendChild(kd);
    if(ex.kind==="hours"){var os=tsel(ex.open||"09:00");os.onchange=function(){ex.open=os.value;};var to=document.createElement("span");to.className="to";to.textContent="to";var cs=tsel(ex.close||"17:00");cs.onchange=function(){ex.close=cs.value;};row.appendChild(os);row.appendChild(to);row.appendChild(cs);}
    var nt=document.createElement("input");nt.type="text";nt.placeholder="Note (e.g. Thanksgiving)";nt.value=ex.note||"";nt.size=16;nt.oninput=function(){ex.note=nt.value;};row.appendChild(nt);
    var rm=document.createElement("button");rm.type="button";rm.className="b ghost tiny";rm.textContent="remove";rm.onclick=function(){cur().exceptions.splice(i,1);renderExc();};row.appendChild(rm);
    w.appendChild(row);
  });
}
function renderAll(){renderTabs();renderWeek();renderRanges();renderExc();}
function excMap(arr){var m={};arr.forEach(function(ex){if(!ex.date)return;if(ex.kind==="closed"){m[ex.date]={closed:true};if(ex.note)m[ex.date].note=ex.note;}else{m[ex.date]=[{open:ex.open||"09:00",close:ex.close||"17:00"}];}});return m;}
function schedOut(s){var o={weekly:{},exceptions:excMap(s.exceptions),ranges:[]};for(var d=0;d<7;d++)o.weekly[String(d)]=clone(s.weekly[String(d)]||[]);s.ranges.forEach(function(r){if(!r.start||!r.end)return;var w={};for(var d=0;d<7;d++)w[String(d)]=clone(r.weekly[String(d)]||[]);o.ranges.push({start:r.start,end:r.end,label:r.label||"",weekly:w});});return o;}
function hasContent(s){for(var d=0;d<7;d++)if((s.weekly[String(d)]||[]).length)return true;return s.ranges.length>0||s.exceptions.length>0;}
function build(){var lib=schedOut(sched.library);var out={libraryName:meta.libraryName,timezone:meta.timezone,weekly:lib.weekly,exceptions:lib.exceptions,ranges:lib.ranges};if(sched.guest&&guestCommitted&&hasContent(sched.guest))out.guest=schedOut(sched.guest);return out;}
function doSave(msg){flash("Saving…");fetch(MCW_ADMIN.root,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":MCW_ADMIN.nonce},body:JSON.stringify(build())}).then(function(r){if(!r.ok)throw new Error(r.status);return r.json();}).then(function(){flash(msg||"Saved — it's live on the website. ✓");}).catch(function(){flash("Save failed — please try again or reload the page.",true);});}
function parseExc(map){var arr=[];if(map)Object.keys(map).sort().forEach(function(dt){var v=map[dt];if(v&&v.closed){arr.push({date:dt,kind:"closed",note:v.note||""});}else if(Array.isArray(v)&&v.length){arr.push({date:dt,kind:"hours",open:v[0].open,close:v[0].close,note:""});}});return arr;}
function loadSched(c){var s={weekly:{},ranges:[],exceptions:parseExc(c&&c.exceptions)};for(var d=0;d<7;d++)s.weekly[String(d)]=clone((c&&c.weekly&&c.weekly[String(d)])||[]);if(c&&c.ranges&&c.ranges.length)c.ranges.forEach(function(r){var w={};for(var d=0;d<7;d++)w[String(d)]=clone((r.weekly&&r.weekly[String(d)])||[]);s.ranges.push({start:r.start||"",end:r.end||"",label:r.label||"",weekly:w});});return s;}
function loadCfg(cfg){meta.libraryName=cfg.libraryName||meta.libraryName;meta.timezone=cfg.timezone||meta.timezone;sched.library=loadSched(cfg);sched.guest=(cfg.guest&&typeof cfg.guest==="object")?loadSched(cfg.guest):null;guestCommitted=!!sched.guest;target="library";work=clone(cur().weekly);renderAll();}
function flash(msg,bad){var s=document.getElementById("mcw-status");s.textContent=msg;s.style.color=bad?"#b42318":"#1a7f37";}

document.getElementById("mcw-addexc").onclick=function(){cur().exceptions.push({date:"",kind:"closed",note:""});renderExc();};
document.getElementById("mcw-reset").onclick=function(){if(confirm("Reset the hours above to the standard schedule?")){work=clone(STD);renderWeek();}};
document.getElementById("mcw-save").onclick=function(){
  var si=document.getElementById("mcw-r-start"),ei=document.getElementById("mcw-r-end"),s=si.value,e=ei.value;
  if(s||e){
    if(!s||!e){flash("Enter both a start and end date for the range (or clear both).",true);return;}
    if(e<s){flash("The end date must be on or after the start date.",true);return;}
    cur().ranges.push({start:s,end:e,label:"",weekly:clone(work)});
    si.value="";ei.value="";work=clone(cur().weekly);renderWeek();renderRanges();
    if(target==="guest")guestCommitted=true;
    doSave("Saved — those hours now apply "+fmtDate(s)+" – "+fmtDate(e)+". Your everyday hours are unchanged. ✓");
  }else{
    cur().weekly=clone(work);
    if(target==="guest")guestCommitted=true;
    doSave("Saved — it's live on the website. ✓");
  }
};

// Load current live hours on open.
fetch(MCW_ADMIN.root).then(function(r){return r.json();}).then(loadCfg).catch(function(){renderAll();});
})();
</script>
HTML;
}

/* ------------------------------------------------------------------ *
 *  Front-end widget  [library_hours]
 * ------------------------------------------------------------------ */

/** Shared JS: mcwResolve(sch, dateKey, dow) -> {blocks, note}. Precedence: exception > range > weekly. */
function mcw_hours_resolve_js() {
	return <<<'JS'
function mcwResolve(sch, dateKey, dow){
  var exc = sch.exceptions && sch.exceptions[dateKey];
  if(exc){
    if(exc.closed) return {blocks:[], note:exc.note||""};
    if(Array.isArray(exc)) return {blocks:exc, note:""};
    if(exc.blocks) return {blocks:exc.blocks, note:exc.note||""};
  }
  var ranges = sch.ranges || [];
  for(var i=0;i<ranges.length;i++){
    var r=ranges[i];
    if(r.start && r.end && dateKey>=r.start && dateKey<=r.end){
      return {blocks:(r.weekly&&r.weekly[String(dow)])||[], note:""};
    }
  }
  return {blocks:(sch.weekly&&sch.weekly[String(dow)])||[], note:""};
}
function mcwSchedule(cfg, which){
  if(which==="guest" && cfg.guest) return cfg.guest;
  return {weekly:cfg.weekly, exceptions:cfg.exceptions, ranges:cfg.ranges||[]};
}
JS;
}

add_shortcode( 'library_hours', 'mcw_hours_shortcode' );

function mcw_hours_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'full' => '/hours/' ), $atts, 'library_hours' );
	$root = esc_url( rest_url( 'mcw-hours/v1/hours' ) );
	$cfg  = array( 'url' => $root, 'full' => esc_url( $atts['full'] ) );
	$config_js = '<script>window.MCW_HOURS_CFG=' . wp_json_encode( $cfg ) . ';</script>';
	return mcw_hours_widget_markup() . $config_js . mcw_hours_widget_script();
}

function mcw_hours_widget_markup() {
	return <<<'HTML'
<div id="mcw-hours" class="mcw-hours" aria-live="polite">
  <div class="mcw-hours__note">Loading hours…</div>
</div>
<style>
  .mcw-hours{--mcw-accent:#00539b;--mcw-open:#1a7f37;--mcw-closed:#b42318;--mcw-line:#e2e2e2;--mcw-text:#1a1a1a;--mcw-muted:#666;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--mcw-text);max-width:420px;border:1px solid var(--mcw-line);border-radius:10px;overflow:hidden;background:#fff}
  .mcw-hours__head{padding:10px 16px;border-bottom:1px solid var(--mcw-line)}
  .mcw-hours__status{display:flex;align-items:center;gap:8px;font-size:1.05rem;font-weight:700}
  .mcw-hours__dot{width:10px;height:10px;border-radius:50%;flex:0 0 auto}
  .mcw-hours.is-open .mcw-hours__dot{background:var(--mcw-open)} .mcw-hours.is-open .mcw-hours__label{color:var(--mcw-open)}
  .mcw-hours.is-closed .mcw-hours__dot{background:var(--mcw-closed)} .mcw-hours.is-closed .mcw-hours__label{color:var(--mcw-closed)}
  .mcw-hours__sub{margin-left:auto;font-size:.85rem;font-weight:400;color:var(--mcw-muted)}
  .mcw-hours__list{list-style:none;margin:0;padding:2px 0}
  .mcw-hours__row{display:flex;justify-content:space-between;padding:3px 16px;font-size:.9rem}
  .mcw-hours__row.is-today{background:#f2f7fc;font-weight:700}
  .mcw-hours__day{color:var(--mcw-text)} .mcw-hours__time{color:var(--mcw-muted);font-variant-numeric:tabular-nums}
  .mcw-hours__row.is-today .mcw-hours__time{color:var(--mcw-text)}
  .mcw-hours__foot{display:flex;flex-wrap:nowrap;align-items:flex-start;gap:.35em;border-top:.07em solid var(--mcw-line);background:#fafafa;padding:0 .55em .15em}
  .mcw-hours .mcw-hours__tabs{display:contents}
  .mcw-hours .mcw-hours__tab{box-sizing:border-box;flex:0 0 auto;width:auto;min-width:0;max-width:none;min-height:0;height:auto;margin:0;overflow:visible;border:.09em solid var(--mcw-line);border-top-color:transparent;background:#fff;color:var(--mcw-muted);font:inherit;font-size:.72em;line-height:1.3;text-align:center;white-space:nowrap;padding:.2em .6em .3em;border-radius:0 0 .5em .5em;cursor:pointer;box-shadow:none;transform:none}
  .mcw-hours .mcw-hours__tab:hover{background:#f2f7fc;color:var(--mcw-accent);border-color:var(--mcw-line);border-top-color:transparent;box-shadow:none;transform:none;text-decoration:none}
  .mcw-hours .mcw-hours__tab.is-on,.mcw-hours .mcw-hours__tab.is-on:hover{background:var(--mcw-accent);color:#fff;border-color:var(--mcw-accent);border-top-color:transparent}
  .mcw-hours .mcw-hours__tab:focus-visible{outline:.12em solid var(--mcw-accent);outline-offset:.12em}
  .mcw-hours .mcw-hours__all{flex:0 0 auto;margin-left:auto;align-self:flex-start;box-sizing:border-box;border-top:.09em solid transparent;padding:.2em 0 .3em;font-size:.72em;line-height:1.3;color:var(--mcw-accent);text-decoration:none;white-space:nowrap}
  .mcw-hours__all:hover,.mcw-hours__all:focus{text-decoration:underline}
  .mcw-hours__note{padding:8px 16px;font-size:.8rem;color:var(--mcw-muted);border-top:1px solid var(--mcw-line);background:#fafafa}
</style>
HTML;
}

function mcw_hours_widget_script() {
	$resolve = mcw_hours_resolve_js();
	return <<<HTML
<script>
(function(){
"use strict";
$resolve
var mount=document.getElementById("mcw-hours");
if(!mount||!window.MCW_HOURS_CFG)return;
var CFG=window.MCW_HOURS_CFG, DATA=null, which="regular";
var DAYS=["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
var s=new Date();
var bust=""+s.getUTCFullYear()+(s.getUTCMonth()+1)+s.getUTCDate()+s.getUTCHours();
var url=CFG.url+(CFG.url.indexOf("?")>-1?"&":"?")+"v="+bust;
fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(d){DATA=d;render();}).catch(function(){
  mount.className="mcw-hours";
  mount.innerHTML='<div class="mcw-hours__note">Library hours are temporarily unavailable. Please call 508-286-3700.</div>';
});
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function nowTZ(tz){var f=new Intl.DateTimeFormat("en-US",{timeZone:tz,hour12:false,year:"numeric",month:"2-digit",day:"2-digit",weekday:"short",hour:"2-digit",minute:"2-digit"});var p={};f.formatToParts(new Date()).forEach(function(x){p[x.type]=x.value;});var wd=["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].indexOf(p.weekday);return{dateKey:p.year+"-"+p.month+"-"+p.day,dow:wd,minutes:(parseInt(p.hour,10)%24)*60+parseInt(p.minute,10)};}
function toMin(x){var a=x.split(":");return parseInt(a[0],10)*60+parseInt(a[1],10);}
function fmt(x){var m=toMin(x);if(m===0||m===1440)return"Midnight";if(m===720)return"Noon";var h=Math.floor(m/60),mm=m%60,ap=h<12?"am":"pm";h=h%12;if(h===0)h=12;return h+(mm?":"+(mm<10?"0"+mm:mm):"")+ap;}
function allDay(bl){return bl.length===1&&toMin(bl[0].open)===0&&toMin(bl[0].close)===1440;}
function render(){
  var cfg=DATA, sch=mcwSchedule(cfg,which);
  var now=nowTZ(cfg.timezone||"America/New_York");
  var today=mcwResolve(sch,now.dateKey,now.dow);
  var isOpen=false,closesAt=null,opensAt=null;
  today.blocks.forEach(function(b){var o=toMin(b.open),c=toMin(b.close);if(now.minutes>=o&&now.minutes<c){isOpen=true;closesAt=b.close;}if(!isOpen&&now.minutes<o&&opensAt===null)opensAt=b.open;});
  var lab,sub="";if(isOpen){lab="Open now";sub="Closes at "+fmt(closesAt);}else{lab="Closed now";if(opensAt!==null)sub="Opens at "+fmt(opensAt)+" today";else if(today.note)sub=today.note;}
  var rows="";
  for(var i=0;i<7;i++){var d=new Date(now.dateKey+"T00:00:00");d.setDate(d.getDate()+i);var key=d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");var dow=d.getDay();var info=mcwResolve(sch,key,dow);var t=info.blocks.length?(allDay(info.blocks)?"Open 24 hours":info.blocks.map(function(b){return fmt(b.open)+" – "+fmt(b.close);}).join(", ")):(info.note?"Closed — "+info.note:"Closed");rows+='<li class="mcw-hours__row'+(i===0?" is-today":"")+'"><span class="mcw-hours__day">'+(i===0?"Today":DAYS[dow])+'</span><span class="mcw-hours__time">'+t+'</span></li>';}
  var tabs=cfg.guest?('<div class="mcw-hours__tabs" role="tablist" aria-label="Hours calendar">'
      +'<button type="button" role="tab" class="mcw-hours__tab'+(which==="regular"?" is-on":"")+'" data-w="regular" aria-selected="'+(which==="regular")+'">Library Hours</button>'
      +'<button type="button" role="tab" class="mcw-hours__tab'+(which==="guest"?" is-on":"")+'" data-w="guest" aria-selected="'+(which==="guest")+'">Guest Hours</button></div>'):'';
  var foot='<div class="mcw-hours__foot">'+tabs+'<a class="mcw-hours__all" href="'+esc(CFG.full)+'">View all hours</a></div>';
  mount.className="mcw-hours "+(isOpen?"is-open":"is-closed");
  mount.innerHTML='<div class="mcw-hours__head"><div class="mcw-hours__status"><span class="mcw-hours__dot"></span><span class="mcw-hours__label">'+lab+'</span>'+(sub?'<span class="mcw-hours__sub">'+esc(sub)+'</span>':'')+'</div></div><ul class="mcw-hours__list">'+rows+'</ul>'+foot;
  Array.prototype.forEach.call(mount.querySelectorAll(".mcw-hours__tab"),function(b){b.addEventListener("click",function(){which=b.getAttribute("data-w");render();});});
}
})();
</script>
HTML;
}

/* ------------------------------------------------------------------
 *  Full-hours page  [library_hours_full]
 * ------------------------------------------------------------------ */

add_shortcode( 'library_hours_full', 'mcw_hours_full_shortcode' );

function mcw_hours_full_shortcode( $atts ) {
	$root      = esc_url( rest_url( 'mcw-hours/v1/hours' ) );
	$config_js = '<script>window.MCW_HOURS_FULL=' . wp_json_encode( array( 'url' => $root ) ) . ';</script>';
	return mcw_hours_full_markup() . $config_js . mcw_hours_full_script();
}

function mcw_hours_full_markup() {
	return <<<'HTML'
<div id="mcw-hours-full" class="mcw-hours-full" aria-live="polite"><p class="mcw-hf__note">Loading hours…</p></div>
<style>
  .mcw-hours-full{--acc:#00539b;--line:#e2e2e2;--muted:#666;--closed:#b42318;--today:#eef4fb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;max-width:780px}
  .mcw-hf__bar{display:flex;align-items:center;gap:10px;margin-bottom:14px}
  .mcw-hf__nav{border:1px solid var(--line);background:#fff;font:inherit;padding:6px 12px;border-radius:7px;cursor:pointer;color:var(--acc)}
  .mcw-hf__nav:hover{background:#f2f7fc}
  .mcw-hf__nav:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
  .mcw-hf__month{font-weight:700;font-size:1.2rem;flex:1;text-align:center}
  .mcw-hf__tabs{display:flex;align-items:center;gap:6px;margin-bottom:12px}
  .mcw-hf__tab{border:1px solid var(--line);background:#fff;color:var(--muted);font:inherit;padding:4px 12px;border-radius:999px;cursor:pointer}
  .mcw-hf__tab.is-on{background:var(--acc);color:#fff;border-color:var(--acc)}
  .mcw-hf__today{margin-left:auto;border:0;background:none;font:inherit;color:var(--acc);text-decoration:underline;cursor:pointer;padding:4px 2px;white-space:nowrap}
  .mcw-hf__today:hover{text-decoration:none}
  .mcw-hf__today:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
  .mcw-hf__scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .mcw-cal{border-collapse:collapse;width:100%;min-width:520px;table-layout:fixed}
  .mcw-cal caption{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  .mcw-cal th{width:14.28%;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--muted);padding:6px 4px;text-align:center;border:1px solid var(--line);background:#fafafa}
  .mcw-cal th abbr{text-decoration:none;border:0;cursor:default}
  .mcw-cal td{border:1px solid var(--line);vertical-align:top;height:78px;padding:5px 7px}
  .mcw-cal td.empty{background:#fafafa}
  .mcw-cal .num{display:block;font-size:.82rem;font-weight:700;line-height:1;margin-bottom:3px}
  .mcw-cal .hrs{display:block;font-size:.75rem;color:#333;line-height:1.3;font-variant-numeric:tabular-nums}
  .mcw-cal .cnote{display:block;font-size:.7rem;color:var(--muted);font-style:italic;line-height:1.25;margin-top:1px}
  .mcw-cal td.closed{background:repeating-linear-gradient(135deg,#fbfbfb,#fbfbfb 6px,#f4f4f4 6px,#f4f4f4 12px)}
  .mcw-cal td.closed .hrs{color:var(--closed);font-weight:600}
  .mcw-cal td.today{background:var(--today);box-shadow:inset 0 0 0 2px var(--acc)}
  .mcw-cal td.today .num{color:var(--acc)}
  .mcw-hf__legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;font-size:.78rem;color:var(--muted)}
  .mcw-hf__legend span{display:inline-flex;align-items:center;gap:6px}
  .mcw-hf__key{width:13px;height:13px;border-radius:3px;border:1px solid var(--line)}
  .mcw-hf__key.k-today{background:var(--today);box-shadow:inset 0 0 0 2px var(--acc);border-color:var(--acc)}
  .mcw-hf__key.k-closed{background:repeating-linear-gradient(135deg,#fbfbfb,#fbfbfb 4px,#f4f4f4 4px,#f4f4f4 8px)}
  .mcw-hf__note{color:var(--muted)}
  @media (max-width:520px){.mcw-cal td{height:64px}.mcw-cal .hrs,.mcw-cal .cnote{font-size:.7rem}}
</style>
HTML;
}

function mcw_hours_full_script() {
	$resolve = mcw_hours_resolve_js();
	return <<<HTML
<script>
(function(){
"use strict";
$resolve
var mount=document.getElementById("mcw-hours-full");
if(!mount||!window.MCW_HOURS_FULL)return;
var CFG=window.MCW_HOURS_FULL, DATA=null, which="regular";
var MONTHS=["January","February","March","April","May","June","July","August","September","October","November","December"];
var DAYS=["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];
var view=new Date(); view.setDate(1);
var s=new Date();
var bust=""+s.getUTCFullYear()+(s.getUTCMonth()+1)+s.getUTCDate()+s.getUTCHours();
var url=CFG.url+(CFG.url.indexOf("?")>-1?"&":"?")+"v="+bust;
fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(d){DATA=d;render();}).catch(function(){mount.innerHTML='<p class="mcw-hf__note">Hours are temporarily unavailable. Please call 508-286-3700.</p>';});
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function toMin(x){var a=x.split(":");return parseInt(a[0],10)*60+parseInt(a[1],10);}
function fmt(x){var m=toMin(x);if(m===0||m===1440)return"Midnight";if(m===720)return"Noon";var h=Math.floor(m/60),mm=m%60,ap=h<12?"am":"pm";h=h%12;if(h===0)h=12;return h+(mm?":"+(mm<10?"0"+mm:mm):"")+ap;}
function allDay(bl){return bl.length===1&&toMin(bl[0].open)===0&&toMin(bl[0].close)===1440;}
function key(d){return d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");}
var FULLDAYS=["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
function render(){
  var cfg=DATA, sch=mcwSchedule(cfg,which), hasGuest=!!cfg.guest;
  var y=view.getFullYear(), mo=view.getMonth();
  var last=new Date(y,mo+1,0).getDate(), firstDow=new Date(y,mo,1).getDay(), todayKey=key(new Date());
  var tabs='<div class="mcw-hf__tabs">'
      +(hasGuest?('<button type="button" class="mcw-hf__tab'+(which==="regular"?" is-on":"")+'" data-w="regular">Library hours</button>'
      +'<button type="button" class="mcw-hf__tab'+(which==="guest"?" is-on":"")+'" data-w="guest">Guest hours</button>'):'')
      +'<button type="button" class="mcw-hf__today" data-today="1">Back to today</button></div>';
  var cells=[];
  for(var i=0;i<firstDow;i++)cells.push(0);
  for(var dn=1;dn<=last;dn++)cells.push(dn);
  while(cells.length%7!==0)cells.push(0);
  var body="";
  for(var wk=0;wk<cells.length;wk+=7){
    body+="<tr>";
    for(var c=0;c<7;c++){
      var day=cells[wk+c];
      if(!day){body+='<td class="empty"></td>';continue;}
      var d=new Date(y,mo,day), k=key(d), info=mcwResolve(sch,k,d.getDay()), isOpen=info.blocks.length>0, isToday=k===todayKey;
      var hrs=isOpen?(allDay(info.blocks)?"Open 24 hours":info.blocks.map(function(b){return fmt(b.open)+"–"+fmt(b.close);}).join("<br>")):"Closed";
      var cnote=(!isOpen&&info.note)?'<span class="cnote" aria-hidden="true">'+esc(info.note)+'</span>':'';
      var aria=MONTHS[mo]+" "+day+", "+(isOpen?(allDay(info.blocks)?"open 24 hours":info.blocks.map(function(b){return fmt(b.open)+" to "+fmt(b.close);}).join(", ")):("closed"+(info.note?" — "+info.note:"")))+(isToday?", today":"");
      var cls="day"+(isOpen?"":" closed")+(isToday?" today":"");
      body+='<td class="'+cls+'" aria-label="'+esc(aria)+'"><span class="num" aria-hidden="true">'+day+'</span><span class="hrs" aria-hidden="true">'+hrs+'</span>'+cnote+'</td>';
    }
    body+="</tr>";
  }
  var thead="";for(var h=0;h<7;h++)thead+='<th scope="col"><abbr title="'+FULLDAYS[h]+'">'+DAYS[h]+'</abbr></th>';
  var legend='<div class="mcw-hf__legend"><span><i class="mcw-hf__key k-closed"></i>Closed</span><span><i class="mcw-hf__key k-today"></i>Today</span></div>';
  mount.innerHTML=tabs
    +'<div class="mcw-hf__bar"><button type="button" class="mcw-hf__nav" data-nav="-1" aria-label="Previous month">&lsaquo; Prev</button>'
    +'<div class="mcw-hf__month">'+MONTHS[mo]+' '+y+'</div>'
    +'<button type="button" class="mcw-hf__nav" data-nav="1" aria-label="Next month">Next &rsaquo;</button></div>'
    +'<div class="mcw-hf__scroll"><table class="mcw-cal"><caption>Hours for '+MONTHS[mo]+' '+y+'</caption><thead><tr>'+thead+'</tr></thead><tbody>'+body+'</tbody></table></div>'
    +legend;
  Array.prototype.forEach.call(mount.querySelectorAll(".mcw-hf__nav"),function(b){b.addEventListener("click",function(){view.setMonth(view.getMonth()+parseInt(b.getAttribute("data-nav"),10));render();});});
  Array.prototype.forEach.call(mount.querySelectorAll(".mcw-hf__tab"),function(b){b.addEventListener("click",function(){which=b.getAttribute("data-w");render();});});
  var tb=mount.querySelector(".mcw-hf__today");if(tb)tb.addEventListener("click",function(){var t=new Date();view=new Date(t.getFullYear(),t.getMonth(),1);render();});
}
})();
</script>
HTML;
}
