// Group 2: a broken or unusual environment.
import fs from 'node:fs';
import {
	SITE, adminPost, baseline, captureOff, captureOn, defaultServer, editUrl, eq, http, listUrl, logClean, logMark, note, ok, option, orderFacts, php, plugin,
	resetOrder, restartServer, sane, scenario, setOption, sql, sqlRoot, takeMails, wp,
} from './lib.mjs';

const TABS = [ 'statuses', 'transitions', 'documents', 'import-export' ];

// Visits every screen and endpoint of the plugin and returns the problems seen (empty = fine).
async function smoke( S, id, hpos, label, { transition = true } = {} ) {
	const problems = [];
	for ( const tab of TABS ) {
		const r = await http( 'GET', `/wp-admin/admin.php?page=ofwc&tab=${ tab }`, { cookie: S.admin.cookie } );
		if ( r.status !== 200 || ! sane( r ) ) {
			problems.push( `${ label }: tab ${ tab } answered ${ r.status }` );
		}
	}
	for ( const path of [ editUrl( id, hpos ), listUrl( hpos ), '/wp-admin/plugins.php', '/wp-admin/admin.php?page=wc-settings&tab=email', `/my-account/view-order/${ id }/` ] ) {
		const r = await http( 'GET', path, { cookie: path.startsWith( '/my-account' ) ? S.custa.cookie : S.admin.cookie } );
		if ( r.status >= 500 || ! sane( r ) ) {
			problems.push( `${ label }: ${ path.slice( 0, 60 ) } answered ${ r.status }` );
		}
	}
	const exp = await http( 'GET', '/wp-admin/admin.php?page=ofwc&tab=import-export', { cookie: S.admin.cookie } );
	const nonce = ( exp.text.match( /name="_ofwc_nonce" value="([0-9a-f]{10})"/ ) || [] )[ 1 ];
	if ( nonce ) {
		const r = await adminPost( S.admin, 'ofwc_export', [], { nonce, nonceField: '_ofwc_nonce' } );
		if ( r.status !== 200 || ! sane( r ) ) {
			problems.push( `${ label }: export answered ${ r.status }` );
		}
	}
	if ( transition ) {
		const page = await http( 'GET', editUrl( id, hpos ), { cookie: S.admin.cookie } );
		const n = ( page.text.match( /data-nonce="([0-9a-f]{10})"/ ) || [] )[ 1 ];
		if ( n ) {
			const r = await adminPost( S.admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', 'awaiting-approval' ], [ 'ofwc_comment', 'smoke' ] ], { nonce: n, nonceField: 'ofwc_nonce' } );
			if ( ! sane( r ) ) {
				problems.push( `${ label }: transition answered ${ r.status }` );
			}
		}
	}
	return problems;
}

export async function s2( S, ctx ) {
	const hpos = ctx.hpos;
	const { admin } = S;

	await scenario( '2.1', 'WooCommerce deactivated (and activating the plugin without it)', 'No fatal error anywhere; a notice is shown to administrators; the endpoints are gone; reactivating WooCommerce restores everything', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		resetOrder( id, 'awaiting-approval' );
		const mark = logMark();
		const woo = plugin( 'deactivate', 'woocommerce' );
		ok( woo.code === 0, `deactivate woocommerce: ${ woo.err.slice( 0, 200 ) }` );
		for ( const path of [ '/', '/wp-admin/', '/wp-admin/plugins.php', '/wp-admin/admin.php?page=ofwc', '/wp-login.php' ] ) {
			const r = await http( 'GET', path, { cookie: admin.cookie } );
			ok( r.status > 0 && r.status < 500 && sane( r ), `${ path } without WooCommerce: status ${ r.status }` );
		}
		const dash = await http( 'GET', '/wp-admin/', { cookie: admin.cookie } );
		ok( /needs WooCommerce|WooCommerce to be installed/i.test( dash.text ), 'the administrator gets a notice that WooCommerce is missing' );
		const sub = await http( 'GET', '/wp-admin/', { cookie: S.sub.cookie } );
		ok( ! /needs WooCommerce/i.test( sub.text ), 'a subscriber must not see the notice' );
		const tr = await adminPost( admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', 'approved' ] ], { nonceAction: `ofwc_transition_${ id }`, nonceField: 'ofwc_nonce' } );
		ok( tr.status === 400, `status change endpoint without WooCommerce: ${ tr.status }` );
		// our plugin off, WooCommerce off: activating ours must not fatal
		plugin( 'deactivate' );
		const act = plugin( 'activate' );
		ok( ! /Fatal|Uncaught/.test( act.out + act.err ), `activating the plugin without WooCommerce: ${ ( act.out + act.err ).slice( 0, 200 ) }` );
		plugin( 'deactivate' );
		plugin( 'activate', 'woocommerce' );
		const back = plugin( 'activate' );
		ok( back.code === 0, `reactivate: ${ back.err.slice( 0, 200 ) }` );
		const problems = await smoke( S, id, hpos, 'after reactivation', { transition: false } );
		ok( problems.length === 0, problems.join( '; ' ) );
		eq( orderFacts( id ).status, 'awaiting-approval', 'the order keeps its custom status' );
		logClean( mark, '2.1' );
	}, 'live (2.1)' );

	await scenario( '2.2', 'WooCommerce at the declared minimum (9.0.4) and below it (8.9.3)', 'The plugin works on the minimum; below it nothing fatal comes from the plugin', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		const zips = [ [ '9.0.4', '/cache/woo/woocommerce.9.0.4.zip' ], [ '8.9.3', '/cache/woo/woocommerce.8.9.3.zip' ] ];
		for ( const [ version, zip ] of zips ) {
			if ( ! fs.existsSync( zip ) ) {
				note( `WooCommerce ${ version } archive not available; skipped` );
				continue;
			}
			const inst = wp( [ 'plugin', 'install', zip, '--force', '--activate' ] );
			ok( inst.code === 0, `install ${ version }: ${ inst.err.slice( 0, 200 ) }` );
			const v = wp( [ 'plugin', 'get', 'woocommerce', '--field=version' ] ).out.trim();
			eq( v, version, 'installed WooCommerce version' );
			resetOrder( id );
			const problems = await smoke( S, id, hpos, `WooCommerce ${ version }` );
			ok( problems.length === 0, problems.join( '; ' ) );
			const l = logClean( mark, `2.2 (${ version })` );
			note( `${ version }: ${ l.other.length } lines from other code in debug.log` );
		}
		const restore = wp( [ 'plugin', 'install', '/cache/woo/woocommerce.11.2.1.zip', '--force', '--activate' ] );
		ok( restore.code === 0, `restore 11.2.1: ${ restore.err.slice( 0, 200 ) }` );
	}, 'live (2.2)' );

	await scenario( '2.3', 'WooCommerce tables missing, one by one', 'No fatal error; screens answer; the PDF and the status change either work or fail with a clear message', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		const tables = [ 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_sessions', 'wc_orders_meta' ];
		for ( const t of tables ) {
			const exists = sql( `SHOW TABLES LIKE 'wp_${ t }'` );
			if ( ! exists ) {
				continue;
			}
			sql( `RENAME TABLE wp_${ t } TO wp_${ t }_bak` );
			try {
				resetOrder( id );
				const problems = await smoke( S, id, hpos, `without ${ t }`, { transition: true } );
				ok( problems.length === 0, problems.join( '; ' ) );
			} finally {
				sql( `RENAME TABLE wp_${ t }_bak TO wp_${ t }` );
			}
		}
		logClean( mark, '2.3', { ignoreDb: true } );
	}, 'live (2.3)' );

	await scenario( '2.4', 'Plugin options missing or damaged', 'Every screen and endpoint keeps working; nothing fatal; damaged values behave like missing ones', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		const names = [ 'ofwc_statuses', 'ofwc_transitions', 'ofwc_settings' ];
		const shapes = {
			missing: null,
			string: 'just text',
			number: 42,
			bool_true: true,
			empty_array: [],
			list_of_strings: [ 'a', 'b' ],
			rows_of_wrong_type: { x: 'string', y: 5, z: null },
			row_fields_wrong_type: { x: { slug: [ 'a' ], label: { a: 1 }, color: 5, icon: [], paid: [ 1 ], position: 'abc', email_body: [ 'x' ] } },
			rules_wrong_type: [ { from: [ 'x' ], to: 5, roles: 'administrator', require_comment: [ 1 ] }, 'string', 5, null ],
			hostile_strings: { 'bad': { slug: 'bad', label: '<script>alert(1)</script>', color: 'red', icon: 'nope', pdf_text: '<script>alert(1)</script>', email_body: '<img src=x onerror=alert(1)>' } },
			huge_position: { big: { slug: 'big', label: 'Big', position: 9.99e99 } },
		};
		for ( const name of names ) {
			for ( const [ shape, value ] of Object.entries( shapes ) ) {
				baseline( { hpos } );
				if ( value === null ) {
					wp( [ 'option', 'delete', name ] );
				} else {
					setOption( name, value );
				}
				resetOrder( id );
				const problems = await smoke( S, id, hpos, `${ name } = ${ shape }` );
				ok( problems.length === 0, problems.join( '; ' ) );
			}
		}
		// broken serialisation written straight into the database
		for ( const name of names ) {
			baseline( { hpos } );
			sql( `UPDATE wp_options SET option_value = 'a:3:{s:4:"slug";s:99:"cut' WHERE option_name = '${ name }'` );
			resetOrder( id );
			const problems = await smoke( S, id, hpos, `${ name } with broken serialisation` );
			ok( problems.length === 0, problems.join( '; ' ) );
		}
		// order meta in the wrong shape
		baseline( { hpos } );
		for ( const bad of [ 'text', '5', 'a:1:{', 'a:1:{i:0;s:3:"abc";}' ] ) {
			php( `$o = wc_get_order( ${ id } ); $o->update_meta_data( '_ofwc_history', ${ JSON.stringify( bad ) } ); $o->save();` );
			const problems = await smoke( S, id, hpos, `_ofwc_history = ${ bad }`, { transition: false } );
			ok( problems.length === 0, problems.join( '; ' ) );
		}
		php( `$o = wc_get_order( ${ id } ); $o->update_meta_data( '_ofwc_history', array( array( 'time' => 'x', 'user_id' => array(), 'from' => 5, 'to' => null, 'comment' => array( 1 ) ), 'junk', 5 ) ); $o->save();` );
		const problems = await smoke( S, id, hpos, 'history rows of the wrong type', { transition: false } );
		ok( problems.length === 0, problems.join( '; ' ) );
		logClean( mark, '2.4' );
	}, 'live (2.4)' );

	await scenario( '2.5', 'Uploads folder and plugin folder not writable, temp folder unavailable', 'The status change completes; the email goes out (without the PDF when it cannot be written); a PDF download still works; no PHP warnings', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const uploads = `${ SITE }/wp-content/uploads`;
		const fonts = `${ SITE }/wp-content/plugins/order-flow-for-woocommerce/vendor/dompdf/dompdf/lib/fonts`;
		const fontsDir = fs.existsSync( fonts ) ? fonts : '';
		const mark = logMark();
		captureOn();
		const variants = {
			'uploads missing': () => { fs.renameSync( uploads, `${ uploads }-away` ); },
			'uploads is a file': () => { fs.renameSync( uploads, `${ uploads }-away` ); fs.writeFileSync( uploads, 'not a folder' ); },
			'uploads read-only': () => { fs.chmodSync( uploads, 0o555 ); },
			'uploads and font folder read-only': () => { fs.chmodSync( uploads, 0o555 ); if ( fontsDir ) { fs.chmodSync( fontsDir, 0o555 ); } },
		};
		const undo = () => {
			if ( fs.existsSync( `${ uploads }-away` ) ) {
				fs.rmSync( uploads, { recursive: true, force: true } );
				fs.renameSync( `${ uploads }-away`, uploads );
			}
			fs.chmodSync( uploads, 0o755 );
			if ( fontsDir ) {
				fs.chmodSync( fontsDir, 0o755 );
			}
		};
		fs.mkdirSync( uploads, { recursive: true } );
		for ( const [ name, apply ] of Object.entries( variants ) ) {
			try {
				apply();
				resetOrder( id );
				takeMails();
				const page = await http( 'GET', editUrl( id, hpos ), { cookie: admin.cookie } );
				const nonce = ( page.text.match( /data-nonce="([0-9a-f]{10})"/ ) || [] )[ 1 ];
				const r = await adminPost( admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', 'awaiting-approval' ], [ 'ofwc_comment', 'x' ] ], { nonce, nonceField: 'ofwc_nonce' } );
				eq( r.status, 302, `${ name }: status change` );
				eq( orderFacts( id ).status, 'awaiting-approval', `${ name }: status after the change` );
				const mails = takeMails();
				ok( mails.length === 2, `${ name }: ${ mails.length } emails (expected customer and admin)` );
				const dl = await adminPost( admin, 'ofwc_pdf', [ [ 'order_id', String( id ) ], [ 'status', 'awaiting-approval' ] ], { nonceAction: `ofwc_pdf_${ id }_awaiting-approval`, method: 'GET' } );
				ok( dl.status === 200 && dl.text.startsWith( '%PDF' ), `${ name }: PDF download answered ${ dl.status }` );
			} finally {
				undo();
			}
		}
		// temp folder
		await restartServer( { env: { TMPDIR: '/nonexistent-tmp' } } );
		try {
			resetOrder( id, 'awaiting-approval' );
			const dl = await adminPost( admin, 'ofwc_pdf', [ [ 'order_id', String( id ) ], [ 'status', 'awaiting-approval' ] ], { nonceAction: `ofwc_pdf_${ id }_awaiting-approval`, method: 'GET' } );
			ok( dl.status === 200 && dl.text.startsWith( '%PDF' ), `no temp folder: PDF download answered ${ dl.status }` );
		} finally {
			await defaultServer();
		}
		const l = logClean( mark, '2.5' );
		ok( l.all.filter( ( x ) => /order-flow-for-woocommerce|dompdf|Dompdf/.test( x ) ).length === 0, `warnings from dompdf or the plugin: ${ l.all.slice( 0, 3 ).join( ' | ' ) }` );
		captureOff();
	}, 'live (2.5)' );

	await scenario( '2.6', 'Cron off, external requests blocked, mail failing', 'Status changes still work; PDF generation makes no outside request; a failing mailer does not break the change', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		wp( [ 'config', 'set', 'DISABLE_WP_CRON', 'true', '--raw', '--type=constant' ] );
		wp( [ 'config', 'set', 'WP_HTTP_BLOCK_EXTERNAL', 'true', '--raw', '--type=constant' ] );
		try {
			resetOrder( id );
			captureOn();
			const problems = await smoke( S, id, hpos, 'cron off and external blocked' );
			ok( problems.length === 0, problems.join( '; ' ) );
			const mails = takeMails();
			ok( mails.length >= 1, 'emails still go out' );
			// the mailer fails
			fs.writeFileSync( '/cache/tmp/mail-fail', '1' );
			resetOrder( id );
			const page = await http( 'GET', editUrl( id, hpos ), { cookie: admin.cookie } );
			const nonce = ( page.text.match( /data-nonce="([0-9a-f]{10})"/ ) || [] )[ 1 ];
			const r = await adminPost( admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', 'awaiting-approval' ], [ 'ofwc_comment', 'x' ] ], { nonce, nonceField: 'ofwc_nonce' } );
			eq( r.status, 302, 'status change while the mailer fails' );
			eq( orderFacts( id ).status, 'awaiting-approval', 'status after a failed mail' );
		} finally {
			fs.rmSync( '/cache/tmp/mail-fail', { force: true } );
			captureOff();
			wp( [ 'config', 'delete', 'DISABLE_WP_CRON', '--type=constant' ] );
			wp( [ 'config', 'delete', 'WP_HTTP_BLOCK_EXTERNAL', '--type=constant' ] );
		}
		logClean( mark, '2.6' );
	}, 'live (2.6)' );
}
