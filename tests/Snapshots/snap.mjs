// Behaviour snapshot: drives the plugin the way a store owner does and writes everything observable to files.
// Runs INSIDE the clone container: node snap.mjs <out-dir> [hpos-on|hpos-off]
// The same script runs against v0.1.0 (the reference) and against the current build; `bin/snapshot.sh compare` diffs the two.
import fs from 'node:fs';
import path from 'node:path';
import { BASE, MARKERS, http, login, sh, wp, wpOut, enc, formString, nonceFor } from '../Destructive/lib.mjs';

const OUT = process.argv[ 2 ];
const MODE = process.argv[ 3 ] || 'hpos-on';
const HERE = path.dirname( new URL( import.meta.url ).pathname );
const TMP = '/cache/tmp';
if ( ! OUT ) {
	console.error( 'usage: node snap.mjs <out-dir> [hpos-on|hpos-off]' );
	process.exit( 2 );
}

fs.rmSync( OUT, { recursive: true, force: true } );
fs.mkdirSync( OUT, { recursive: true } );
fs.mkdirSync( TMP, { recursive: true } );

// ------------------------------------------------------------------ normalisation
let ORDER_ID = 0;
const MONTHS = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

function norm( text ) {
	let s = String( text );
	s = s.replace( /((?:_wpnonce|ofwc_nonce|_ofwc_nonce)(?:=|"\s+value="|\\?"?:\\?"))[0-9a-f]{10}/g, '$1{NONCE}' );
	s = s.replace( /data-nonce="[0-9a-f]{10}"/g, 'data-nonce="{NONCE}"' );
	s = s.replace( /wc_order_[A-Za-z0-9]+/g, 'wc_order_{KEY}' );
	s = s.replace( new RegExp( `\\b(?:${ MONTHS })[a-z]* \\d{1,2}, \\d{4} (?:at )?\\d{1,2}:\\d{2} ?[ap]m\\b`, 'gi' ), '{DATETIME}' );
	s = s.replace( /\b20\d\d-\d\d-\d\d[ T]\d\d:\d\d(?::\d\d)?(?:[+-]\d\d:\d\d|Z)?/g, '{DATETIME}' );
	if ( ORDER_ID ) {
		s = s.replace( new RegExp( `(?<![0-9])${ ORDER_ID }(?![0-9])`, 'g' ), '{ORDER}' );
	}
	s = s.replace( /\r\n/g, '\n' );
	return s;
}

function write( rel, content ) {
	const file = path.join( OUT, rel );
	fs.mkdirSync( path.dirname( file ), { recursive: true } );
	fs.writeFileSync( file, typeof content === 'string' ? norm( content ) : norm( JSON.stringify( content, null, 2 ) ) + '\n' );
}

// ------------------------------------------------------------------ html helpers
const decode = ( s ) => s.replace( /&amp;/g, '&' ).replace( /&#038;/g, '&' ).replace( /&quot;/g, '"' ).replace( /&#039;/g, "'" );

// The element that starts at the first tag containing `marker`, up to its matching close tag.
function block( html, marker, tag = 'div' ) {
	const at = html.indexOf( marker );
	if ( at === -1 ) {
		return null;
	}
	const start = html.lastIndexOf( `<${ tag }`, at );
	const re = new RegExp( `<(/?)${ tag }\\b`, 'g' );
	re.lastIndex = start;
	let depth = 0;
	let m;
	while ( ( m = re.exec( html ) ) !== null ) {
		depth += m[ 1 ] ? -1 : 1;
		if ( depth === 0 ) {
			const end = html.indexOf( '>', m.index ) + 1;
			return html.slice( start, end );
		}
	}
	return html.slice( start );
}

function notices( html ) {
	const out = [];
	const re = /<div class="notice notice-(success|warning|error|info)[^"]*"><p>([\s\S]*?)<\/p><\/div>/g;
	let m;
	while ( ( m = re.exec( html ) ) !== null ) {
		out.push( { type: m[ 1 ], message: decode( m[ 2 ].replace( /<[^>]+>/g, '' ) ) } );
	}
	return out;
}

function pretty( html ) {
	return html.replace( />\s*</g, '>\n<' ).replace( /\n{2,}/g, '\n' ) + '\n';
}

// ------------------------------------------------------------------ steps
const hpos = MODE === 'hpos-on';

function setStore() {
	wp( [ 'wc', 'hpos', hpos ? 'enable' : 'disable' ] );
	wp( [ 'option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ] );
}

function seed( stage ) {
	const r = wp( [ 'eval-file', `${ HERE }/seed.php` ], { env: { SNAP_STAGE: stage } } );
	const line = r.out.trim().split( '\n' ).pop();
	try {
		return JSON.parse( line );
	} catch {
		throw new Error( `seed ${ stage } failed: ${ r.out } ${ r.err }` );
	}
}

function mails() {
	const file = `${ TMP }/mail.jsonl`;
	if ( ! fs.existsSync( file ) ) {
		return [];
	}
	const lines = fs.readFileSync( file, 'utf8' ).split( '\n' ).filter( Boolean ).map( ( l ) => JSON.parse( l ) );
	fs.rmSync( file );
	return lines;
}

function pdfInfo( file ) {
	const r = sh( 'php', [ `${ HERE }/pdfinfo.php`, file ] );
	try {
		return JSON.parse( r.out );
	} catch {
		return { error: `pdfinfo failed: ${ r.err || r.out }` };
	}
}

async function bin( url, cookie ) {
	const res = await fetch( BASE + url, { headers: { Cookie: cookie }, redirect: 'manual' } );
	const buf = Buffer.from( await res.arrayBuffer() );
	return { status: res.status, type: res.headers.get( 'content-type' ) || '', disposition: res.headers.get( 'content-disposition' ) || '', buf };
}

const editUrl = ( id ) => ( hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }` : `/wp-admin/post.php?post=${ id }&action=edit` );
const listUrl = hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order';

function orderFacts( id ) {
	const code = `
		$o = wc_get_order( ${ id } );
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => ${ id }, 'orderby' => 'date_created_gmt', 'order' => 'ASC' ) ) as $n ) {
			$notes[] = array( 'content' => $n->content, 'customer_note' => (bool) $n->customer_note, 'added_by' => $n->added_by );
		}
		$h = $o->get_meta( '_ofwc_history' );
		$hist = array();
		foreach ( is_array( $h ) ? $h : array() as $e ) {
			$hist[] = array( 'from' => $e['from'] ?? null, 'to' => $e['to'] ?? null, 'user' => ( $u = get_userdata( (int) ( $e['user_id'] ?? 0 ) ) ) ? $u->user_login : 'system', 'comment' => $e['comment'] ?? null, 'has_time' => ! empty( $e['time'] ) );
		}
		echo wp_json_encode( array( 'status' => $o->get_status(), 'paid' => $o->is_paid(), 'notes' => $notes, 'history' => $hist, 'total' => $o->get_total() ) );
	`;
	const r = wp( [ 'eval', code ] );
	return JSON.parse( r.out.trim().split( '\n' ).pop() );
}

async function main() {
	seed( 'clean' );
	setStore();
	const ids = seed( 'seed' );
	ORDER_ID = ids.order_id;
	if ( ORDER_ID < 40000 || ORDER_ID > 40010 ) {
		throw new Error( `order id ${ ORDER_ID } is not in the fixed range; the snapshot would not be stable` );
	}
	if ( ids.hpos !== hpos ) {
		throw new Error( `store mode mismatch: wanted hpos=${ hpos }, order lives in hpos=${ ids.hpos }` );
	}
	write( 'seed.json', { hpos: ids.hpos, total: ids.total, products: Object.keys( ids.products ) } );

	const admin = await login( 'of-destr-admin' );
	const shop = await login( 'of-destr-shopmgr' );
	const cust = await login( 'of-destr-custa' );
	const actors = { admin, shop };

	fs.writeFileSync( `${ TMP }/capture-on`, '1' );
	mails();

	// ---- initial state of the order screen and the order list, per role
	for ( const [ name, session ] of Object.entries( actors ) ) {
		const page = await http( 'GET', editUrl( ORDER_ID ), { cookie: session.cookie } );
		const box = block( page.text, 'id="ofwc-order-flow"' );
		write( `initial/${ name }-metabox.html`, box ? pretty( box ) : 'MISSING\n' );
		const docs = block( page.text, 'id="ofwc_documents"' );
		write( `initial/${ name }-documents.html`, docs ? pretty( docs ) : 'MISSING\n' );
		const list = await http( 'GET', listUrl, { cookie: session.cookie } );
		const select = block( list.text, 'id="bulk-action-selector-top"', 'select' ) || '';
		const options = [ ...select.matchAll( /<option value="([^"]*)"[^>]*>([^<]*)<\/option>/g ) ].map( ( m ) => `${ m[ 1 ] } | ${ decode( m[ 2 ] ) }` );
		write( `initial/${ name }-bulk-actions.txt`, options.join( '\n' ) + '\n' );
		const statusLinks = [ ...list.text.matchAll( /<li class='(wc-[a-z-]+)'><a href='[^']*'[^>]*>([^<]*)<span class="count">/g ) ].map( ( m ) => `${ m[ 1 ] } | ${ decode( m[ 2 ] ).trim() }` );
		write( `initial/${ name }-list-status-links.txt`, statusLinks.join( '\n' ) + '\n' );
		const dropdown = block( page.text, 'id="order_status"', 'select' ) || '';
		write( `initial/${ name }-status-dropdown.txt`, [ ...dropdown.matchAll( /<option value="([^"]*)"[^>]*>([^<]*)<\/option>/g ) ].map( ( m ) => `${ m[ 1 ] } | ${ decode( m[ 2 ] ) }` ).join( '\n' ) + '\n' );
	}

	// ---- the transitions, as the Order Flow box posts them
	const plan = [
		{ actor: 'admin', to: 'awaiting-approval', comment: 'Initial intake' },
		{ actor: 'shop', to: 'approved', comment: 'Shop manager may not approve' },
		{ actor: 'admin', to: 'approved', comment: '' },
		{ actor: 'admin', to: 'in-production', comment: 'Skipping the approval step' },
		{ actor: 'admin', to: 'approved', comment: 'Checked & approved by <b>QA</b> "ok"' },
		{ actor: 'shop', to: 'in-production', comment: 'Start production\nline two' },
		{ actor: 'admin', to: 'in-production', comment: 'Same status again' },
		{ actor: 'admin', to: 'does-not-exist', comment: 'Unknown target' },
	];
	let n = 0;
	let mailNo = 0;
	for ( const step of plan ) {
		n++;
		const session = actors[ step.actor ];
		const label = `${ String( n ).padStart( 2, '0' ) }-${ step.actor }-to-${ step.to }`;
		const page = await http( 'GET', editUrl( ORDER_ID ), { cookie: session.cookie } );
		const nonce = ( page.text.match( /data-nonce="([0-9a-f]{10})"/ ) || [] )[ 1 ];
		if ( ! nonce ) {
			write( `steps/${ label }.json`, { error: 'no nonce on the order screen' } );
			continue;
		}
		const res = await http( 'POST', '/wp-admin/admin-post.php', {
			cookie: session.cookie,
			form: formString( [ [ 'action', 'ofwc_transition' ], [ 'order_id', String( ORDER_ID ) ], [ 'ofwc_nonce', nonce ], [ 'ofwc_to', step.to ], [ 'ofwc_comment', step.comment ] ] ),
		} );
		const after = await http( 'GET', editUrl( ORDER_ID ), { cookie: session.cookie } );
		const facts = orderFacts( ORDER_ID );
		const sent = mails();
		write( `steps/${ label }.json`, {
			request: step,
			http: { status: res.status, location: res.location },
			notices: notices( after.text ),
			order: facts,
			mails: sent.map( ( m, i ) => `mails/${ String( mailNo + i + 1 ).padStart( 2, '0' ) }` ),
		} );
		const box = block( after.text, 'id="ofwc-order-flow"' );
		write( `steps/${ label }-metabox.html`, box ? pretty( box ) : 'MISSING\n' );
		const dropdown = block( after.text, 'id="order_status"', 'select' ) || '';
		write( `steps/${ label }-status-dropdown.txt`, [ ...dropdown.matchAll( /<option value="([^"]*)"[^>]*(selected)?[^>]*>([^<]*)<\/option>/g ) ].map( ( m ) => `${ m[ 1 ] } | ${ decode( m[ 3 ] ) }` ).join( '\n' ) + '\n' );
		for ( const m of sent ) {
			mailNo++;
			const key = `mails/${ String( mailNo ).padStart( 2, '0' ) }`;
			const attachments = m.attachments.map( ( a ) => ( { name: a.name, bytes: a.content > 0 ? 'non-empty' : 'EMPTY', pdf: a.name.endsWith( '.pdf' ) ? pdfInfo( a.copy ) : undefined } ) );
			write( `${ key }.json`, { after_step: label, to: m.to, cc: m.cc, bcc: m.bcc, from: m.from, reply_to: m.reply_to, subject: m.subject, content_type: m.content_type, has_alt_body: m.alt_body !== '', attachments } );
			write( `${ key }.html`, pretty( m.body ) );
			if ( m.alt_body ) {
				write( `${ key }.alt.txt`, m.alt_body );
			}
		}
	}

	// ---- PDFs, as the administrator and as the customer
	const finalPage = await http( 'GET', editUrl( ORDER_ID ), { cookie: admin.cookie } );
	const docsBox = block( finalPage.text, 'id="ofwc_documents"' ) || '';
	write( 'final/documents-box.html', pretty( docsBox ) );
	const links = [ ...docsBox.matchAll( /href="([^"]*action=ofwc_pdf[^"]*)"/g ) ].map( ( m ) => decode( m[ 1 ] ).replace( /^https?:\/\/[^/]+/, '' ) );
	for ( const link of links ) {
		const slug = ( link.match( /status=([a-z0-9-]+)/ ) || [] )[ 1 ] || 'unknown';
		const r = await bin( link, admin.cookie );
		fs.writeFileSync( `${ TMP }/snap.pdf`, r.buf );
		write( `pdf/admin-${ slug }.json`, { http: r.status, type: r.type, disposition: r.disposition, pdf: pdfInfo( `${ TMP }/snap.pdf` ) } );
	}
	const account = await http( 'GET', `/my-account/view-order/${ ORDER_ID }/`, { cookie: cust.cookie } );
	const section = block( account.text, 'woocommerce-order-documents', 'section' );
	write( 'final/customer-documents-section.html', section ? pretty( section ) : `MISSING (http ${ account.status })\n` );
	const mark = ( account.text.match( /<mark class="order-status[^>]*>[\s\S]*?<\/mark>/ ) || [ 'no status mark' ] )[ 0 ];
	write( 'final/customer-status-mark.html', mark + '\n' );
	const custLinks = [ ...( section || '' ).matchAll( /href="([^"]*action=ofwc_pdf[^"]*)"/g ) ].map( ( m ) => decode( m[ 1 ] ).replace( /^https?:\/\/[^/]+/, '' ) );
	for ( const link of custLinks ) {
		const slug = ( link.match( /status=([a-z0-9-]+)/ ) || [] )[ 1 ] || 'unknown';
		const r = await bin( link, cust.cookie );
		fs.writeFileSync( `${ TMP }/snap.pdf`, r.buf );
		write( `pdf/customer-${ slug }.json`, { http: r.status, type: r.type, pdf: pdfInfo( `${ TMP }/snap.pdf` ) } );
	}
	// The status without a customer document: the customer must not be able to fetch it even with an admin link.
	const hidden = links.find( ( l ) => l.includes( 'status=in-production' ) );
	if ( hidden ) {
		const r = await bin( hidden, cust.cookie );
		write( 'pdf/customer-forbidden-in-production.json', { http: r.status } );
	}
	const accountList = await http( 'GET', '/my-account/orders/', { cookie: cust.cookie } );
	const rowStatus = [ ...accountList.text.matchAll( /data-title="Status">\s*([^<]*?)\s*</g ) ].map( ( m ) => decode( m[ 1 ] ) );
	write( 'final/customer-orders-list-status.txt', rowStatus.join( '\n' ) + '\n' );

	// ---- configuration export
	const ie = await http( 'GET', '/wp-admin/admin.php?page=ofwc&tab=import-export', { cookie: admin.cookie } );
	const exportNonce = ( ie.text.match( /name="_ofwc_nonce" value="([0-9a-f]{10})"/ ) || [] )[ 1 ];
	const exp = await http( 'POST', '/wp-admin/admin-post.php', { cookie: admin.cookie, form: formString( [ [ 'action', 'ofwc_export' ], [ '_ofwc_nonce', exportNonce || '' ] ] ) } );
	let config = null;
	try {
		config = JSON.parse( exp.text );
	} catch {
		config = { unparsable: exp.text.slice( 0, 200 ) };
	}
	delete config.exported_at;
	delete config.plugin_version;
	write( 'final/config-export.json', { http: exp.status, type: exp.headers.get( 'content-type' ), config } );

	// ---- the admin pages themselves (markup of the settings screens)
	for ( const tab of [ 'statuses', 'transitions', 'documents', 'import-export' ] ) {
		const r = await http( 'GET', `/wp-admin/admin.php?page=ofwc&tab=${ tab }`, { cookie: admin.cookie } );
		const content = block( r.text, 'class="ofwc-tab-content"' ) || 'MISSING';
		write( `screens/${ tab }.html`, pretty( content ) );
	}

	fs.rmSync( `${ TMP }/capture-on`, { force: true } );
	seed( 'clean' );
	console.log( `snapshot written to ${ OUT } (${ MODE }, ${ n } steps, ${ mailNo } mails, ${ links.length } admin PDFs)` );
}

main().catch( ( e ) => {
	fs.rmSync( `${ TMP }/capture-on`, { force: true } );
	console.error( e );
	process.exit( 1 );
} );
