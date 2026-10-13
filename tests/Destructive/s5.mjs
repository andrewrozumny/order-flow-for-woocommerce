// Group 5: configuration import, deactivation and reactivation, uninstall.
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
import {
	sleep, BIG, SITE, adminPost, baseline, editUrl, eq, formString, http, listUrl, logClean, logMark, multipart, note, ok, oneOf, option, orderFacts, php, plugin, resetOrder,
	sane, scenario, sessionNonce, setOption, setOrderStatus, sql, wp,
} from './lib.mjs';

const PLUGIN_DIR = `${ SITE }/wp-content/plugins/order-flow-for-woocommerce`;

async function importConfig( session, content, mode, { asFile = false, filename = 'config.json', type = 'application/json', nonce } = {} ) {
	const n = nonce !== undefined ? nonce : sessionNonce( session, 'ofwc_import' );
	const fields = [ [ 'action', 'ofwc_import' ], [ '_ofwc_nonce', n ] ];
	if ( mode !== undefined ) {
		fields.push( [ 'mode', mode ] );
	}
	if ( asFile ) {
		const mp = multipart( fields, [ { name: 'ofwc_file', filename, type, data: content } ] );
		return http( 'POST', '/wp-admin/admin-post.php', { cookie: session.cookie, body: mp.body, headers: mp.headers } );
	}
	fields.push( [ 'ofwc_json', content ] );
	return http( 'POST', '/wp-admin/admin-post.php', { cookie: session.cookie, form: formString( fields ) } );
}

const file = ( over = {} ) => JSON.stringify( { format: 'order-flow-for-woocommerce', version: 1, statuses: [], transitions: [], settings: {}, ...over } );
const st = ( slug, label = slug, extra = {} ) => ( { slug, label, ...extra } );

function statusSlugs() {
	const rows = option( 'ofwc_statuses' );
	return rows && typeof rows === 'object' ? Object.keys( rows ) : [];
}

function wellFormed() {
	const rows = option( 'ofwc_statuses' );
	if ( ! rows || typeof rows !== 'object' ) {
		return 'option is not an array';
	}
	for ( const [ key, r ] of Object.entries( rows ) ) {
		if ( ! /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test( r.slug ) || r.slug.length > 17 || String( key ) !== String( r.slug ) ) {
			return `bad slug ${ JSON.stringify( r.slug ) } under key ${ key }`;
		}
		if ( [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'draft' ].includes( r.slug ) ) {
			return `core slug ${ r.slug } was stored`;
		}
	}
	const rules = option( 'ofwc_transitions' );
	if ( ! Array.isArray( rules ) ) {
		return 'rules option is not a list';
	}
	return '';
}

// Puts the plugin files back from the staged copy after `wp plugin uninstall` deleted them.
function sqlSafeRsync() {
	return spawnSync( 'rsync', [ '-a', '--delete', '/cache/stage/order-flow-for-woocommerce/', `${ PLUGIN_DIR }/` ], { encoding: 'utf8' } );
}

export async function s5( S, ctx ) {
	const hpos = ctx.hpos;
	const { admin, shop, sub } = S;

	await scenario( '5.1', 'Import: broken, hostile and oversized files', 'A bad file is refused with a message and changes nothing; a good file adds what it says; the stored statuses stay well formed and core statuses are never replaced', async () => {
		baseline( { hpos } );
		const mark = logMark();
		const before = JSON.stringify( option( 'ofwc_statuses' ) );
		const bad = {
			'truncated JSON': file().slice( 0, 30 ),
			'empty text': '',
			'array root': '[]',
			'string root': '"text"',
			'null root': 'null',
			'number root': '123',
			'wrong format': file( { format: 'something-else' } ),
			'missing format': JSON.stringify( { version: 1, statuses: [] } ),
			'newer version': file( { version: 99 } ),
			'version as string': file( { version: '1' } ),
			'version as fraction': file( { version: 1.5 } ),
			'version zero': file( { version: 0 } ),
			'statuses as string': file( { statuses: 'nope' } ),
			'201 statuses': file( { statuses: Array.from( { length: 201 }, ( _, i ) => st( `s${ i }` ) ) } ),
			'10000 statuses': file( { statuses: Array.from( { length: 10000 }, ( _, i ) => st( `s${ i }` ) ) } ),
			'over 1 MB': file( { pad: 'x'.repeat( 1048577 ) } ),
		};
		for ( const [ name, content ] of Object.entries( bad ) ) {
			const r = await importConfig( admin, content, 'merge' );
			ok( sane( r ), `${ name }: status ${ r.status }` );
			eq( JSON.stringify( option( 'ofwc_statuses' ) ), before, `${ name } must change nothing` );
		}
		// files that are not JSON at all
		const png = Buffer.from( '89504e470d0a1a0a0000000d49484452', 'hex' );
		for ( const [ name, data, type, filename ] of [ [ 'PNG file', png, 'image/png', 'x.png' ], [ 'empty file', Buffer.alloc( 0 ), 'application/json', 'x.json' ], [ 'PHP file', Buffer.from( '<?php echo 1;' ), 'application/x-php', 'x.php' ], [ 'file with null bytes', Buffer.from( '{"format":\u0000}' ), 'application/json', 'x.json' ] ] ) {
			const r = await importConfig( admin, data, 'merge', { asFile: true, filename, type } );
			ok( sane( r ), `${ name }: status ${ r.status }` );
			eq( JSON.stringify( option( 'ofwc_statuses' ) ), before, `${ name } must change nothing` );
		}
		// arrays and odd shapes inside a valid file
		const odd = {
			'statuses as object of objects': file( { statuses: { a: st( 'odd-a' ), b: st( 'odd-b' ) } } ),
			'statuses as strings': file( { statuses: [ 'a', 'b', 5, null, [ 'x' ] ] } ),
			'fields as arrays': file( { statuses: [ { slug: [ 'x' ], label: { a: 1 }, color: [ 1 ], icon: [ 'a' ], pdf_text: [ 'x' ], email_body: { a: 1 }, paid: [ 1 ] } ] } ),
			'empty slug': file( { statuses: [ st( '', 'No slug' ) ] } ),
			'slug longer than 17': file( { statuses: [ st( 'a'.repeat( 18 ), 'Too long' ) ] } ),
			'slug equal to processing': file( { statuses: [ st( 'processing', 'Fake processing' ) ] } ),
			'slug wc-processing': file( { statuses: [ st( 'wc-processing', 'Fake processing 2' ) ] } ),
			'slug wc-completed': file( { statuses: [ st( 'wc-completed', 'Fake completed' ) ] } ),
			'slug in capitals': file( { statuses: [ st( 'WC-Upper', 'Capital' ) ] } ),
			'slug with unicode': file( { statuses: [ st( 'статус', 'Unicode' ) ] } ),
			'slug with spaces and markup': file( { statuses: [ st( '<b>x y</b>', 'Markup slug' ) ] } ),
			'numeric slugs': file( { statuses: [ st( '123', 'Numeric' ), st( '0', 'Zero' ), st( '1.5', 'Fraction' ) ] } ),
			'duplicate slugs': file( { statuses: [ st( 'dup-one', 'First' ), st( 'dup-one', 'Second' ) ] } ),
			'hostile labels': file( { statuses: [ st( 'hostile-one', '<script>alert(1)</script>"><img onerror=alert(1)>' ) ] } ),
			'1 MB label': file( { statuses: [ st( 'huge-label', 'L'.repeat( 900000 ) ) ] } ),
			'rules as strings': file( { transitions: [ 'a', 5, null ] } ),
			'rules with array roles': file( { transitions: [ { from: 'pending', to: 'approved', roles: 'administrator' }, { from: 'pending', to: 'approved', roles: [ [ 'x' ], 5, null ] } ] } ),
			'rules to unknown statuses': file( { transitions: [ { from: 'ghost', to: 'phantom', roles: [ 'administrator' ] } ] } ),
			'settings as array': file( { settings: [ 1, 2 ] } ),
			'store details as array': file( { settings: { store_details: [ 'x' ] } } ),
			'store details with script': file( { settings: { store_details: '<script>alert(1)</script><b>ok</b>' } } ),
			'extra keys': file( { surprise: { deep: [ 1, 2, 3 ] }, [ 'a'.repeat( 5000 ) ]: 1 } ),
		};
		for ( const mode of [ 'merge', 'replace' ] ) {
			for ( const [ name, content ] of Object.entries( odd ) ) {
				baseline( { hpos } );
				const r = await importConfig( admin, content, mode );
				ok( sane( r ), `${ mode }, ${ name }: status ${ r.status }` );
				const problem = wellFormed();
				ok( problem === '', `${ mode }, ${ name }: ${ problem }` );
				const slugs = statusSlugs();
				ok( ! slugs.some( ( s ) => [ 'processing', 'completed' ].includes( s ) ), `${ mode }, ${ name }: a core status was stored` );
				const size = JSON.stringify( option( 'ofwc_statuses' ) ).length;
				ok( size < 200000, `${ mode }, ${ name }: ofwc_statuses grew to ${ size } bytes` );
			}
		}
		// modes
		for ( const m of [ undefined, '', 'REPLACE', 'merge ', 'delete', 'replace\u0000' ] ) {
			const r = await importConfig( admin, file( { statuses: [ st( 'mode-test', 'Mode test' ) ] } ), m );
			ok( sane( r ), `mode ${ JSON.stringify( m ) }: ${ r.status }` );
		}
		// a roles check: shop manager can import (manage_woocommerce) only with a nonce of their own
		const own = await importConfig( shop, file( { statuses: [ st( 'by-shop', 'By shop' ) ] } ), 'merge' );
		ok( sane( own ), 'shop manager import answers normally' );
		logClean( mark, '5.1' );
	}, 'live (5.1)' );

	await scenario( '5.2', 'Import in replace mode: what a missing section means', 'A file that only carries transitions must not wipe the statuses; a file that only carries statuses must not wipe the rules; statuses with orders are never removed', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		resetOrder( id, 'awaiting-approval' );
		const before = statusSlugs();
		ok( before.length === 3, 'baseline has three statuses' );
		const rulesBefore = JSON.stringify( option( 'ofwc_transitions' ) );
		await importConfig( admin, JSON.stringify( { format: 'order-flow-for-woocommerce', version: 1, transitions: [] } ), 'replace' );
		ok( statusSlugs().includes( 'awaiting-approval' ), 'the status with an order is kept' );
		eq( statusSlugs().length, 3, 'replace with a file that has no "statuses" section removed statuses' );
		baseline( { hpos } );
		await importConfig( admin, JSON.stringify( { format: 'order-flow-for-woocommerce', version: 1, statuses: [ ...before.map( ( slug ) => st( slug, `Kept ${ slug }` ) ), st( 'only-one', 'Only one' ) ] } ), 'replace' );
		// Rules that mention a status that replace removes go with that status; here none is removed, so every rule stays.
		eq( JSON.stringify( option( 'ofwc_transitions' ) ), rulesBefore, 'replace with a file that has no "transitions" section cleared the rules' );
		logClean( mark, '5.2' );
	}, 'live (5.2)' );

	await scenario( '5.3', 'Deactivate and reactivate with orders in custom statuses', 'Orders stay readable whichever choice is made; "move to On hold" brings every order back to its own status on reactivation, in batches, on this store layout', async () => {
		const ids = baseline( { hpos } );
		const mark = logMark();
		const created = JSON.parse( php( `
			$slugs = array( 'awaiting-approval', 'approved', 'in-production' );
			$out = array();
			for ( $i = 0; $i < 130; $i++ ) {
				$o = wc_create_order( array( 'status' => 'pending' ) );
				$o->set_billing_email( 'dz' . $i . '@example.test' );
				$o->update_meta_data( '_snap_order', '1' );
				$o->set_status( $slugs[ $i % 3 ] );
				$o->save();
				$out[ $o->get_id() ] = $slugs[ $i % 3 ];
			}
			echo wp_json_encode( $out );
		` ).split( '\n' ).pop() );
		const orderIds = Object.keys( created );
		const statusOf = () => JSON.parse( php( `
			$out = array();
			foreach ( ${ JSON.stringify( orderIds.map( Number ) ) } as $id ) { $o = wc_get_order( $id ); $out[ $id ] = $o ? $o->get_status() : null; }
			echo wp_json_encode( $out );
		` ).split( '\n' ).pop() );
		const mismatches = ( now ) => orderIds.filter( ( i ) => now[ i ] !== created[ i ] );
		eq( mismatches( statusOf() ).length, 0, 'setup: orders have their statuses' );

		let lastPage = null;
		// choice: keep
		let r = await adminPost( admin, 'ofwc_deactivate', [ [ 'choice', 'keep' ] ], { nonceAction: 'ofwc_deactivate', nonceField: '_ofwc_nonce' } );
		eq( r.status, 302, 'deactivation (keep)' );
		eq( wp( [ 'plugin', 'get', 'order-flow-for-woocommerce', '--field=status' ] ).out.trim(), 'inactive', 'plugin is inactive' );
		for ( const path of [ editUrl( orderIds[ 0 ], hpos ), listUrl( hpos ), `${ listUrl( hpos ) }${ hpos ? '&' : '&' }post_status=all` ] ) {
			const page = await http( 'GET', path, { cookie: admin.cookie } );
			ok( page.status === 200 && sane( page ), `while inactive (keep) ${ path.slice( 0, 60 ) }: ${ page.status }` );
		}
		const keepNow = statusOf();
		eq( mismatches( keepNow ).length, 0, 'keep: statuses unchanged while inactive' );
		// While the plugin is inactive WooCommerce does not know the statuses; the deactivation screen says so, so only readability is checked.
		let a = plugin( 'activate' );
		ok( a.code === 0, `reactivate: ${ a.err.slice( 0, 200 ) }` );
		eq( mismatches( statusOf() ).length, 0, 'keep: statuses unchanged after reactivation' );

		// choice: move to on hold, then reactivate. Newer builds park the orders in batches on a progress page
		// that continues by itself; the loop below follows it the way a browser would.
		r = await adminPost( admin, 'ofwc_deactivate', [ [ 'choice', 'move' ] ], { nonceAction: 'ofwc_deactivate', nonceField: '_ofwc_nonce' } );
		eq( r.status, 302, 'deactivation (move)' );
		let next = r.location;
		note( `first answer: ${ r.status } -> ${ r.location }` );
		for ( let i = 0; i < 60 && wp( [ 'plugin', 'get', 'order-flow-for-woocommerce', '--field=status' ] ).out.trim() !== 'inactive'; i++ ) {
			let page = null;
			if ( next ) {
				page = await http( 'GET', next.replace( /^https?:\/\/[^/]+/, '' ), { cookie: admin.cookie } );
			} else if ( lastPage ) {
				const link = ( lastPage.text.match( /id="ofwc-continue"[^>]*href="([^"]+)"|href="([^"]+)"[^>]*id="ofwc-continue"/ ) || [] );
				const href = link[ 1 ] || link[ 2 ];
				if ( ! href ) {
					note( `no continue link on the progress page: ${ lastPage.status } ${ lastPage.text.split( '</head>' ).pop().replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).slice( 0, 300 ) }` );
					break;
				}
				page = await http( 'GET', href.replace( /&amp;|&#038;/g, '&' ).replace( /^https?:\/\/[^/]+/, '' ), { cookie: admin.cookie } );
			} else {
				break;
			}
			next = page.location;
			lastPage = page;
		}
		eq( wp( [ 'plugin', 'get', 'order-flow-for-woocommerce', '--field=status' ] ).out.trim(), 'inactive', 'the plugin ends up inactive after the batches' );
		const held = statusOf();
		const notOnHold = orderIds.filter( ( i ) => held[ i ] !== 'on-hold' );
		ok( notOnHold.length === 0, `move: ${ notOnHold.length } of ${ orderIds.length } orders are not On hold` );
		a = plugin( 'activate' );
		ok( a.code === 0, `reactivate after move: ${ a.err.slice( 0, 200 ) }` );
		// restoring may continue in the background (Action Scheduler), so give it a few rounds
		let back = mismatches( statusOf() );
		for ( let i = 0; i < 12 && back.length > 0; i++ ) {
			wp( [ 'action-scheduler', 'run', '--force' ] );
			wp( [ 'cron', 'event', 'run', '--due-now' ] );
			await sleep( 1000 );
			back = mismatches( statusOf() );
		}
		ok( back.length === 0, `move: ${ back.length } of ${ orderIds.length } orders did not return to their own status after reactivation (store layout: ${ hpos ? 'HPOS' : 'posts' })` );
		logClean( mark, '5.3', { ignoreDb: true } );
		void ids;
	}, 'live (5.3)' );

	await scenario( '5.4', 'Uninstall', 'Only the plugin\'s own rows and files go; orders, their meta, history and notes stay and remain readable and listed; look-alike rows of other plugins stay', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		resetOrder( id );
		await adminPost( admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', 'awaiting-approval' ], [ 'ofwc_comment', 'before uninstall' ] ], { nonceAction: `ofwc_transition_${ id }`, nonceField: 'ofwc_nonce' } );
		php( `
			set_transient( 'ofwc_notice_1', array( 'x' ), 300 );
			set_transient( 'ofwc_form_1', array( 'x' ), 300 );
			set_transient( 'ofwc_tn_1', array( 'x' ), 300 );
			update_option( 'ofwc_restore_pending', '1' );
			update_option( 'my_ofwc_option', 'foreign' );
			update_option( 'xofwc_statuses', 'foreign' );
			update_option( 'woocommerce_ofwcx_settings', 'foreign' );
			update_option( 'ofwcfoo', 'foreign' );
			set_transient( 'other_ofwc_note', 'foreign', 300 );
			add_option( 'ofwc_lock_99999', '1', '', 'no' );
			update_user_meta( 1, 'ofwc_notices', array( array( 'type' => 'error', 'message' => 'x' ) ) );
			update_user_meta( 1, 'my_ofwc_notices', 'foreign' );
		` );
		// something the plugin created in uploads
		fs.mkdirSync( `${ SITE }/wp-content/uploads/ofwc-tmp`, { recursive: true } );
		fs.writeFileSync( `${ SITE }/wp-content/uploads/ofwc-tmp/index.php`, '<?php' );
		fs.mkdirSync( `${ SITE }/wp-content/uploads/ofwc-fonts`, { recursive: true } );
		fs.writeFileSync( `${ SITE }/wp-content/uploads/ofwc-fonts/cache.txt`, 'x' );
		const factsBefore = orderFacts( id );
		wp( [ 'plugin', 'deactivate', 'order-flow-for-woocommerce' ] );
		const un = wp( [ 'plugin', 'uninstall', 'order-flow-for-woocommerce' ] );
		ok( un.code === 0, `uninstall: ${ un.err.slice( 0, 300 ) }` );
		const left = sql( "SELECT option_name FROM wp_options WHERE option_name REGEXP '^(_transient_|_transient_timeout_)?(woocommerce_)?ofwc_' ORDER BY option_name" ).split( '\n' ).filter( Boolean );
		ok( left.length === 0, `rows left after uninstall: ${ left.join( ', ' ) }` );
		const foreign = sql( "SELECT option_name FROM wp_options WHERE option_name IN ('my_ofwc_option','xofwc_statuses','woocommerce_ofwcx_settings','ofwcfoo','_transient_other_ofwc_note') ORDER BY option_name" ).split( '\n' ).filter( Boolean );
		eq( foreign.length, 5, `look-alike rows of other plugins: ${ foreign.join( ', ' ) }` );
		const notices = sql( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = 'ofwc_notices'" ).trim();
		eq( notices, '0', 'the notice queue in user meta is removed' );
		eq( sql( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = 'my_ofwc_notices'" ).trim(), '1', 'a look-alike user meta row stays' );
		const factsAfter = orderFacts( id );
		eq( JSON.stringify( factsAfter.history ), JSON.stringify( factsBefore.history ), 'uninstall must keep the history meta' );
		ok( factsAfter.notes.length >= factsBefore.notes.length, 'uninstall must keep the order notes' );
		note( `order status after uninstall: ${ factsAfter.status } (before: ${ factsBefore.status })` );
		const page = await http( 'GET', editUrl( id, hpos ), { cookie: admin.cookie } );
		ok( page.status === 200 && sane( page ), `order screen after uninstall: ${ page.status }` );
		const listed = await http( 'GET', listUrl( hpos ), { cookie: admin.cookie } );
		ok( listed.text.includes( `${ id }` ), 'the order is still listed under "All" after uninstall' );
		ok( ! fs.existsSync( `${ SITE }/wp-content/uploads/ofwc-tmp` ) && ! fs.existsSync( `${ SITE }/wp-content/uploads/ofwc-fonts` ), 'folders the plugin created in uploads are removed' );
		// bring the plugin back for the next scenarios
		const back = sqlSafeRsync();
		ok( back.status === 0, `restore plugin files: ${ back.stderr }` );
		a_activate();
		wp( [ 'option', 'delete', 'my_ofwc_option' ] );
		wp( [ 'option', 'delete', 'xofwc_statuses' ] );
		wp( [ 'option', 'delete', 'woocommerce_ofwcx_settings' ] );
		wp( [ 'option', 'delete', 'ofwcfoo' ] );
		wp( [ 'user', 'meta', 'delete', '1', 'my_ofwc_notices' ] );
		logClean( mark, '5.4', { ignoreDb: true } );
	}, 'live (5.4)' );
}

function a_activate() {
	wp( [ 'plugin', 'activate', 'order-flow-for-woocommerce' ] );
}

void BIG;
void oneOf;
void setOption;
void setOrderStatus;
