// Helpers for the destructive scenarios. Runs INSIDE the clone container (node 20, php 8.4, mysql client).
// Nothing here touches the lab site: every path and database below belongs to the throwaway clone.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';

export const BASE = 'http://localhost:8080';
export const SITE = process.env.DESTR_SITE || '/cache/site';
export const DB = process.env.DESTR_DB || 'of_destr_1';
export const PLUGIN_DIR = `${SITE}/wp-content/plugins/order-flow-for-woocommerce`;
export const LOG = `${SITE}/wp-content/debug.log`;
export const PHP_ERR_LOG = `${SITE}/wp-content/php-error.log`;
export const PASS = 'Destr-Pass-123!';

// ------------------------------------------------------------------ shell, wp-cli, sql
export function sh( cmd, args, opts = {} ) {
	const r = spawnSync( cmd, args, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, env: { ...process.env, ...( opts.env || {} ) }, input: opts.input, timeout: opts.timeout || 600000 } );
	return { code: r.status === null ? 124 : r.status, out: r.stdout || '', err: r.stderr || '' };
}

export function wp( args, opts = {} ) {
	const path = opts.path || SITE;
	return sh( 'php', [ '-d', 'memory_limit=1G', '-d', 'display_errors=stderr', '/wp-cli.phar', `--path=${path}`, ...args ], opts );
}

export function wpOut( args, opts = {} ) {
	const r = wp( args, opts );
	return r.out.trim();
}

export function evalPhp( code, env = {}, opts = {} ) {
	return wp( [ 'eval', code ], { ...opts, env } );
}

export function sql( query, db = DB ) {
	const r = sh( 'mysql', [ '-h', 'db', '-uroot', '-pwordpress', '-N', '-B', db, '-e', query ] );
	if ( r.code !== 0 ) {
		throw new Error( `mysql failed: ${ r.err }` );
	}
	return r.out.trim();
}

export function sqlRoot( query ) {
	const r = sh( 'mysql', [ '-h', 'db', '-uroot', '-pwordpress', '-N', '-B', '-e', query ] );
	if ( r.code !== 0 ) {
		throw new Error( `mysql failed: ${ r.err }` );
	}
	return r.out.trim();
}

// Names and a hash of the value of every option or transient that belongs to the plugin, as one comparable string.
export function pluginRows( db = DB, prefix = 'wp_' ) {
	return sql( `SELECT option_name, MD5(option_value) FROM ${ prefix }options WHERE option_name LIKE '%ofwc\\_%' ORDER BY option_name`, db );
}

// ------------------------------------------------------------------ debug.log accounting
export function logMark() {
	const size = ( file ) => ( fs.existsSync( file ) ? fs.statSync( file ).size : 0 );
	return { log: size( LOG ), php: size( PHP_ERR_LOG ) };
}

function slice( file, from ) {
	if ( ! fs.existsSync( file ) ) {
		return '';
	}
	const buf = fs.readFileSync( file );
	return buf.subarray( Math.min( from, buf.length ) ).toString( 'utf8' );
}

const ERR_RE = /PHP (Fatal error|Parse error|Warning|Notice|Deprecated)|WordPress database error|_doing_it_wrong|Uncaught/;
const OURS_RE = /order-flow-for-woocommerce|OrderFlowWC|ofwc_/;

// New error lines since the mark, split into the plugin's own and everybody else's.
export function logSince( mark ) {
	const text = slice( LOG, mark.log ) + '\n' + slice( PHP_ERR_LOG, mark.php );
	const lines = text.split( '\n' ).filter( ( l ) => ERR_RE.test( l ) );
	return { ours: lines.filter( ( l ) => OURS_RE.test( l ) ), other: lines.filter( ( l ) => ! OURS_RE.test( l ) ), all: lines };
}

// ------------------------------------------------------------------ http
export async function http( method, path, { cookie = '', form = null, body: rawBody = null, headers = {}, timeout = 120000 } = {} ) {
	const h = { ...headers };
	if ( cookie ) {
		h.Cookie = cookie;
	}
	let body;
	if ( rawBody !== null ) {
		body = rawBody;
	} else if ( form !== null && method !== 'GET' ) {
		h[ 'Content-Type' ] = h[ 'Content-Type' ] || 'application/x-www-form-urlencoded';
		body = typeof form === 'string' ? form : new URLSearchParams( form ).toString();
	}
	const t0 = Date.now();
	try {
		const res = await fetch( BASE + path, { method, headers: h, body, redirect: 'manual', signal: AbortSignal.timeout( timeout ) } );
		const text = await res.text();
		return { status: res.status, headers: res.headers, text, ms: Date.now() - t0, location: res.headers.get( 'location' ) || '' };
	} catch ( e ) {
		return { status: 0, headers: new Headers(), text: String( e ), ms: Date.now() - t0, location: '', error: true };
	}
}

export function enc( s ) {
	return encodeURIComponent( s );
}

export function formString( pairs ) {
	return pairs.map( ( [ k, v ] ) => `${ enc( k ) }=${ enc( v ) }` ).join( '&' );
}

export function jsonBody( res ) {
	try {
		return JSON.parse( res.text );
	} catch {
		return null;
	}
}

// ------------------------------------------------------------------ sessions
export async function login( user, pass = PASS ) {
	const res = await http( 'POST', '/wp-login.php', {
		cookie: 'wordpress_test_cookie=WP%20Cookie%20check',
		form: { log: user, pwd: pass, 'wp-submit': 'Log In', redirect_to: `${ BASE }/wp-admin/`, testcookie: '1' },
	} );
	const jar = [];
	for ( const c of res.headers.getSetCookie() ) {
		const pair = c.split( ';' )[ 0 ];
		if ( ! pair.endsWith( '=' ) && ! /=deleted$/.test( pair ) ) {
			jar.push( pair );
		}
	}
	const loggedIn = jar.find( ( c ) => c.startsWith( 'wordpress_logged_in_' ) );
	if ( ! loggedIn ) {
		throw new Error( `login failed for ${ user }: HTTP ${ res.status }` );
	}
	return { user, cookie: jar.join( '; ' ), loggedIn, nonces: {} };
}

// Creates a nonce for the user's session through WordPress itself. offsetTicks > 0 gives an expired one.
export function nonceFor( session, action, offsetTicks = 0 ) {
	const [ name, ...rest ] = session.loggedIn.split( '=' );
	const code = `
		$u = get_user_by( 'login', getenv( 'OF_USER' ) );
		wp_set_current_user( $u->ID );
		$_COOKIE[ LOGGED_IN_COOKIE ] = getenv( 'OF_COOKIE' );
		$off = (int) getenv( 'OF_OFF' );
		if ( 0 === $off ) { echo wp_create_nonce( getenv( 'OF_ACTION' ) ); return; }
		$i = wp_nonce_tick() - $off;
		echo substr( wp_hash( $i . '|' . getenv( 'OF_ACTION' ) . '|' . $u->ID . '|' . wp_get_session_token(), 'nonce' ), -12, 10 );
	`;
	const r = evalPhp( code, { OF_USER: session.user, OF_COOKIE: decodeURIComponent( rest.join( '=' ) ), OF_ACTION: action, OF_OFF: String( offsetTicks ), OF_NAME: name } );
	return r.out.trim();
}

export function sessionNonce( session, action ) {
	if ( ! session.nonces[ action ] ) {
		session.nonces[ action ] = nonceFor( session, action );
	}
	return session.nonces[ action ];
}

// ------------------------------------------------------------------ html safety
// Splits a page the way a browser does: returns the text outside scripts and the script bodies.
export function splitHtml( html ) {
	const scripts = [];
	let outside = '';
	let i = 0;
	const re = /<script\b[^>]*>/gi;
	let m;
	while ( ( m = re.exec( html ) ) !== null ) {
		outside += html.slice( i, m.index );
		const end = html.slice( re.lastIndex ).search( /<\/script/i );
		if ( end === -1 ) {
			scripts.push( html.slice( re.lastIndex ) );
			i = html.length;
			break;
		}
		scripts.push( html.slice( re.lastIndex, re.lastIndex + end ) );
		i = re.lastIndex + end;
		re.lastIndex = i;
	}
	outside += html.slice( i );
	return { outside, scripts };
}

// Markers used by every hostile data set below.
export const MARKERS = [ 'alert(1)', 'alert("xss', 'onerror=', 'onmouseover=' ];

// Returns a list of problems found in an HTML page (empty list = safe).
export function htmlProblems( html, { allowScripts = true } = {} ) {
	const problems = [];
	const { outside, scripts } = splitHtml( html );
	for ( const s of scripts ) {
		if ( /alert\s*\(/.test( s ) && ! /\bwp\./.test( s ) && ! /\{/.test( s.slice( 0, 4 ) ) ) {
			problems.push( 'unexpected script containing alert(' );
		}
		if ( /<!--/.test( s ) ) {
			problems.push( 'inline script contains "<!--"' );
		}
	}
	if ( /<script[^>]*>[^<]*alert/i.test( outside ) ) {
		problems.push( 'live <script> with alert in markup' );
	}
	if ( /<img[^>]*\sonerror\s*=/i.test( outside ) ) {
		problems.push( 'live <img onerror>' );
	}
	if ( /<[a-z][a-z0-9]*\s[^>]*\bon[a-z]+\s*=\s*["']?\s*alert/i.test( outside ) ) {
		problems.push( 'event handler attribute with alert' );
	}
	if ( /"\s*onmouseover\s*=/i.test( outside ) ) {
		problems.push( 'quote breakout before onmouseover' );
	}
	if ( /href\s*=\s*["']?\s*javascript:/i.test( outside ) ) {
		problems.push( 'javascript: URL in href' );
	}
	if ( /Fatal error|Warning:|Notice:|Deprecated:|Stack trace/.test( outside ) ) {
		problems.push( 'PHP error text in the page' );
	}
	if ( ! allowScripts && scripts.length > 0 ) {
		problems.push( 'unexpected inline script' );
	}
	return problems;
}

// ------------------------------------------------------------------ test framework
export const table = [];
let current = null;

export async function scenario( id, name, expected, test, tests = '' ) {
	if ( process.env.ONLY && ! process.env.ONLY.split( ',' ).includes( id ) ) {
		return;
	}
	current = { id, name, expected, failures: [], notes: [], tests, checks: 0 };
	const t0 = Date.now();
	process.stdout.write( `\n== ${ id } ${ name }\n` );
	try {
		await test( current );
	} catch ( e ) {
		current.failures.push( `scenario crashed: ${ e && e.stack ? e.stack : e }` );
	}
	current.seconds = Math.round( ( Date.now() - t0 ) / 1000 );
	process.stdout.write( `   ${ current.failures.length ? 'FAIL' : 'ok' } (${ current.checks } checks, ${ current.seconds }s)\n` );
	for ( const f of current.failures.slice( 0, 25 ) ) {
		process.stdout.write( `   - ${ f }\n` );
	}
	if ( current.failures.length > 25 ) {
		process.stdout.write( `   ... ${ current.failures.length - 25 } more\n` );
	}
	for ( const n of current.notes ) {
		process.stdout.write( `   note: ${ n }\n` );
	}
	table.push( current );
	current = null;
}

export function ok( cond, message ) {
	if ( ! current ) {
		if ( ! cond ) {
			console.log( `outside a scenario: ${ message }` );
		}
		return !! cond;
	}
	current.checks++;
	if ( ! cond ) {
		current.failures.push( message );
	}
	return !! cond;
}

export function note( message ) {
	if ( ! current ) {
		console.log( `note: ${ message }` );
		return;
	}
	current.notes.push( message );
}

export function eq( actual, expected, message ) {
	return ok( actual === expected, `${ message } (expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) })` );
}

export function oneOf( actual, list, message ) {
	return ok( list.includes( actual ), `${ message } (expected one of ${ JSON.stringify( list ) }, got ${ JSON.stringify( actual ) })` );
}

export function logClean( mark, message, { ignoreDb = false } = {} ) {
	const l = logSince( mark );
	if ( ignoreDb ) {
		l.ours = l.ours.filter( ( x ) => ! /WordPress database error/.test( x ) );
	}
	ok( l.ours.length === 0, `${ message }: PHP errors from the plugin in debug.log: ${ l.ours.slice( 0, 3 ).join( ' | ' ) }` );
	if ( l.other.length ) {
		note( `${ message }: ${ l.other.length } line(s) from other code (e.g. ${ l.other[ 0 ].slice( 0, 160 ) })` );
	}
	return l;
}

// ------------------------------------------------------------------ plugin specific helpers
export const REPO_DIR = process.env.DESTR_REPO || '/repo';

// Sessions for every role the scenarios need.
export async function sessions() {
	return {
		admin: await login( 'of-destr-admin' ),
		sub: await login( 'of-destr-sub' ),
		shop: await login( 'of-destr-shopmgr' ),
		custa: await login( 'of-destr-custa' ),
		custb: await login( 'of-destr-custb' ),
		editor: await login( 'of-destr-editor' ),
	};
}

export function setHpos( on ) {
	wp( [ 'wc', 'hpos', on ? 'enable' : 'disable' ] );
	wp( [ 'option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ] );
}

// Removes the snapshot order, resets the configuration to the baseline data set and returns the ids.
export function baseline( { hpos = true } = {} ) {
	// A damaged option can make every wp-cli call fail (a fatal error on init), so it is removed straight in the database first.
	sql( "DELETE FROM wp_options WHERE option_name IN ('ofwc_statuses','ofwc_transitions','ofwc_settings')" );
	// a scenario that deactivated the plugin and stopped half way must not poison the next one
	wp( [ 'plugin', 'activate', 'order-flow-for-woocommerce' ] );
	wp( [ 'plugin', 'activate', 'woocommerce' ] );
	wp( [ 'eval-file', `${ REPO_DIR }/tests/Snapshots/seed.php` ], { env: { SNAP_STAGE: 'clean' } } );
	setHpos( hpos );
	const r = wp( [ 'eval-file', `${ REPO_DIR }/tests/Snapshots/seed.php` ], { env: { SNAP_STAGE: 'seed' } } );
	const line = r.out.trim().split( '\n' ).pop();
	try {
		return JSON.parse( line );
	} catch {
		throw new Error( `baseline failed: ${ r.out } ${ r.err }` );
	}
}

export const editUrl = ( id, hpos = true ) => ( hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : `/wp-admin/post.php?post=${ id }&action=edit` );
export const listUrl = ( hpos = true ) => ( hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order' );

// Order facts read through WooCommerce itself (status, notes, history meta).
export function orderFacts( id ) {
	const code = `
		$o = wc_get_order( ${ id } );
		if ( ! $o ) { echo wp_json_encode( array( 'missing' => true ) ); return; }
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => ${ id }, 'orderby' => 'date_created_gmt', 'order' => 'ASC' ) ) as $n ) { $notes[] = $n->content; }
		$h = $o->get_meta( '_ofwc_history' );
		echo wp_json_encode( array( 'status' => $o->get_status(), 'notes' => $notes, 'history' => is_array( $h ) ? $h : array(), 'meta_keys' => array_map( static function ( $m ) { return $m->key; }, $o->get_meta_data() ) ) );
	`;
	const r = wp( [ 'eval', code ] );
	try {
		return JSON.parse( r.out.trim().split( '\n' ).pop() );
	} catch {
		return { error: r.out + r.err };
	}
}

export function setOrderStatus( id, status ) {
	wp( [ 'eval', `$o = wc_get_order( ${ id } ); $o->set_status( '${ status }' ); $o->save();` ] );
}

export function option( name ) {
	const r = wp( [ 'option', 'get', name, '--format=json' ] );
	try {
		return JSON.parse( r.out );
	} catch {
		return undefined;
	}
}

export function setOption( name, value ) {
	// Through a file: large values do not fit comfortably on a command line.
	const file = `/cache/tmp/option-${ process.pid }.json`;
	fs.mkdirSync( '/cache/tmp', { recursive: true } );
	fs.writeFileSync( file, JSON.stringify( value ) );
	const r = wp( [ 'eval', `update_option( ${ JSON.stringify( name ) }, json_decode( file_get_contents( '${ file }' ), true ), true );` ] );
	fs.rmSync( file, { force: true } );
	return r;
}

export function rawOptionSql( name, value ) {
	// Writes a raw string into wp_options (for broken serialisation and similar).
	const esc = value.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" );
	sql( `INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('${ name }', '${ esc }', 'yes') ON CONFLICT DO NOTHING` );
}

// Pulls the named inputs of the form that contains `marker`, as the browser would submit them.
export function formFields( html, marker ) {
	const at = html.indexOf( marker );
	if ( at === -1 ) {
		return null;
	}
	const start = html.lastIndexOf( '<form', at );
	const end = html.indexOf( '</form>', at );
	const form = html.slice( start, end );
	const fields = [];
	for ( const m of form.matchAll( /<input\b([^>]*)>/gi ) ) {
		const a = m[ 1 ];
		const name = ( a.match( /\bname=["']([^"']*)["']/ ) || [] )[ 1 ];
		if ( ! name ) {
			continue;
		}
		const type = ( ( a.match( /\btype=["']([^"']*)["']/ ) || [] )[ 1 ] || 'text' ).toLowerCase();
		const value = ( a.match( /\bvalue=["']([^"']*)["']/ ) || [ '', '' ] )[ 1 ];
		if ( ( type === 'checkbox' || type === 'radio' ) && ! /\bchecked\b/.test( a ) ) {
			continue;
		}
		if ( [ 'submit', 'button', 'file', 'image' ].includes( type ) ) {
			continue;
		}
		fields.push( [ name, value.replace( /&amp;/g, '&' ).replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ) ] );
	}
	for ( const m of form.matchAll( /<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/gi ) ) {
		const name = ( m[ 1 ].match( /\bname=["']([^"']*)["']/ ) || [] )[ 1 ];
		if ( name ) {
			fields.push( [ name, m[ 2 ].replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&amp;/g, '&' ) ] );
		}
	}
	for ( const m of form.matchAll( /<select\b([^>]*)>([\s\S]*?)<\/select>/gi ) ) {
		const name = ( m[ 1 ].match( /\bname=["']([^"']*)["']/ ) || [] )[ 1 ];
		if ( ! name ) {
			continue;
		}
		const sel = m[ 2 ].match( /<option[^>]*value=["']([^"']*)["'][^>]*selected[^>]*>/ ) || m[ 2 ].match( /<option[^>]*value=["']([^"']*)["']/ );
		fields.push( [ name, sel ? sel[ 1 ] : '' ] );
	}
	return fields;
}

// Replaces or adds a field in a list of [name, value] pairs.
export function withField( fields, name, value ) {
	const out = fields.filter( ( [ k ] ) => k !== name );
	out.push( [ name, value ] );
	return out;
}

// multipart/form-data body with optional file parts.
export function multipart( fields, files = [] ) {
	const boundary = '----ofdestr' + Math.random().toString( 16 ).slice( 2 );
	const parts = [];
	for ( const [ k, v ] of fields ) {
		parts.push( Buffer.from( `--${ boundary }\r\nContent-Disposition: form-data; name="${ k }"\r\n\r\n` ), Buffer.from( String( v ) ), Buffer.from( '\r\n' ) );
	}
	for ( const f of files ) {
		parts.push( Buffer.from( `--${ boundary }\r\nContent-Disposition: form-data; name="${ f.name }"; filename="${ f.filename }"\r\nContent-Type: ${ f.type || 'application/octet-stream' }\r\n\r\n` ), Buffer.isBuffer( f.data ) ? f.data : Buffer.from( f.data ), Buffer.from( '\r\n' ) );
	}
	parts.push( Buffer.from( `--${ boundary }--\r\n` ) );
	return { body: Buffer.concat( parts ), headers: { 'Content-Type': `multipart/form-data; boundary=${ boundary }` } };
}

// The one-shot admin-post.php call: nonce handling in one place.
export async function adminPost( session, action, fields, { nonceAction, nonceField = '_wpnonce', nonce, method = 'POST', query = '' } = {} ) {
	const pairs = [ [ 'action', action ] ];
	const n = nonce !== undefined ? nonce : ( session && nonceAction ? sessionNonce( session, nonceAction ) : undefined );
	if ( n !== undefined && n !== null ) {
		pairs.push( [ nonceField, n ] );
	}
	for ( const [ k, v ] of fields || [] ) {
		pairs.push( [ k, v ] );
	}
	const cookie = session ? session.cookie : '';
	if ( method === 'GET' ) {
		return http( 'GET', `/wp-admin/admin-post.php?${ formString( pairs ) }${ query }`, { cookie } );
	}
	return http( 'POST', `/wp-admin/admin-post.php${ query }`, { cookie, form: formString( pairs ) } );
}

// Counts and hashes of everything the plugin keeps, to prove that a refused request changed nothing.
export function stateHash( orderId ) {
	const rows = pluginRows();
	const facts = orderId ? JSON.stringify( orderFacts( orderId ) ) : '';
	return `${ rows }\n${ facts }`;
}

export function php( code, env = {} ) {
	const r = evalPhp( code, env );
	return r.out.trim();
}

// Puts the order back to a status and wipes its Order Flow history and notes (without sending anything).
export function resetOrder( id, status = 'pending' ) {
	wp( [ 'eval', `
		$o = wc_get_order( ${ id } );
		$o->set_status( '${ status }' );
		$o->save();
		$o = wc_get_order( ${ id } );
		$o->delete_meta_data( '_ofwc_history' );
		$o->delete_meta_data( '_ofwc_pre_deactivation_status' );
		$o->save();
		foreach ( wc_get_order_notes( array( 'order_id' => ${ id } ) ) as $n ) { wc_delete_order_note( $n->id ); }
	` ] );
}

// Mail capture: the mu-plugin of the clone writes /cache/tmp/mail.jsonl while /cache/tmp/capture-on exists.
export function captureOn() {
	fs.mkdirSync( '/cache/tmp', { recursive: true } );
	fs.writeFileSync( '/cache/tmp/capture-on', '1' );
	fs.rmSync( '/cache/tmp/mail.jsonl', { force: true } );
}

export function captureOff() {
	fs.rmSync( '/cache/tmp/capture-on', { force: true } );
}

export function takeMails() {
	const file = '/cache/tmp/mail.jsonl';
	if ( ! fs.existsSync( file ) ) {
		return [];
	}
	const lines = fs.readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( l ) => JSON.parse( l ) );
	fs.rmSync( file );
	return lines;
}

export const BIG = 'A'.repeat( 1024 * 1024 );

export const PAYLOADS = {
	xss_script: '<script>alert(1)</script>',
	xss_img: '"><img src=x onerror=alert(1)>',
	js_url: 'javascript:alert(1)',
	sql_or: "' OR 1=1 --",
	sql_union: "1' UNION SELECT user_pass FROM wp_users-- -",
	percent: '%',
	underscore: '_',
	backslash: '\\',
	unicode: 'ключ-проверки-日本語-ñ',
	emoji: '😀🔥👨‍👩‍👧',
	rtl: '‮evil‬‏x',
	null_byte: 'abc\u0000def',
	crlf: 'a\r\nBcc: evil@example.test',
	traversal: '../../../../etc/passwd',
	negative: '-1',
	fraction: '1.5',
	huge_exp: '1e309',
	zero: '0',
	empty: '',
	spaces: '   ',
	one_mb: BIG,
};

// Any admin-post answer must be a redirect or a plain error page: never a 500, never a PHP error text.
export function sane( res ) {
	return res.status > 0 && res.status < 500 && ! /Fatal error|Parse error|Stack trace|Uncaught/.test( res.text );
}

// Live markup an attacker planted: script elements and event-handler attributes, outside the page's own scripts.
export function liveMarkup( html ) {
	const { outside } = splitHtml( html );
	const found = [];
	if ( /<script\b/i.test( outside ) ) {
		found.push( 'script element' );
	}
	// Text inside quoted attribute values is data, not markup.
	const bare = outside.replace( /="[^"]*"/g, '=""' ).replace( /='[^']*'/g, "=''" );
	if ( /<[a-z][a-z0-9]*\s[^>]*\bon(error|load|mouseover|click|focus)\s*=\s*["']?\s*alert/i.test( bare ) ) {
		found.push( 'event handler attribute' );
	}
	if ( /href\s*=\s*["']?\s*javascript:/i.test( outside ) ) {
		found.push( 'javascript: link' );
	}
	return found;
}

// ------------------------------------------------------------------ the clone's web server
import { spawn } from 'node:child_process';

function serverPids() {
	const r = sh( 'pgrep', [ '-f', 'php -d memory_limit.*-S 0.0.0.0:8080' ] );
	return r.out.split( '\n' ).map( ( s ) => parseInt( s, 10 ) ).filter( ( n ) => n > 0 && n !== process.pid );
}

// Restarts the built-in server with other limits or environment, e.g. { memory: '128M', time: 30, env: { TMPDIR: '/nonexistent' } }.
export async function restartServer( { memory = '256M', time = 30, env = {} } = {} ) {
	for ( const pid of serverPids() ) {
		try {
			process.kill( pid );
		} catch {
			// already gone
		}
	}
	await new Promise( ( r ) => setTimeout( r, 700 ) );
	const child = spawn( 'php', [ '-d', `memory_limit=${ memory }`, '-d', `max_execution_time=${ time }`, '-d', 'display_errors=0', '-d', `error_log=${ SITE }/wp-content/php-error.log`, '-S', '0.0.0.0:8080', '-t', SITE ], {
		detached: true,
		stdio: 'ignore',
		env: { ...process.env, PHP_CLI_SERVER_WORKERS: '8', ...env },
	} );
	child.unref();
	for ( let i = 0; i < 30; i++ ) {
		const r = await http( 'GET', '/', { timeout: 5000 } );
		if ( r.status > 0 ) {
			return;
		}
		await new Promise( ( r2 ) => setTimeout( r2, 500 ) );
	}
	throw new Error( 'server did not come back' );
}

export const defaultServer = () => restartServer( {} );

// Runs a request against the plugin's checks and returns what an outside observer sees.
export function plugin( action, slug = 'order-flow-for-woocommerce' ) {
	return wp( [ 'plugin', action, slug ] );
}

export function sleep( ms ) {
	return new Promise( ( r ) => setTimeout( r, ms ) );
}

// Saves the order the way the browser does: the whole edit form, with some fields replaced.
export async function saveOrderForm( session, id, hpos, overrides = {} ) {
	const page = await http( 'GET', editUrl( id, hpos ), { cookie: session.cookie } );
	let fields = formFields( page.text, hpos ? 'name="order"' : 'id="post"' );
	if ( ! fields ) {
		return { status: 0, text: `no edit form (page status ${ page.status })`, location: '' };
	}
	for ( const [ k, v ] of Object.entries( overrides ) ) {
		fields = fields.filter( ( [ n ] ) => n !== k );
		fields.push( [ k, v ] );
	}
	fields.push( [ 'save', 'Update' ] );
	const action = hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : '/wp-admin/post.php';
	return http( 'POST', action, { cookie: session.cookie, form: formString( fields ) } );
}
