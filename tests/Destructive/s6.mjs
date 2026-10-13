// Group 6: upgrade and downgrade, multisite.
import fs from 'node:fs';
import { spawn, spawnSync } from 'node:child_process';
import {
	BASE, PASS, SITE, adminPost, baseline, editUrl, eq, http, listUrl, logClean, logMark, note, ok, option, orderFacts, php, plugin, resetOrder, sane, scenario, sleep, sql,
	sqlRoot, sessionNonce, wp,
} from './lib.mjs';

const OLD_ZIP = '/cache/old/order-flow-for-woocommerce-0.1.0.zip';
const NEW_ZIP = '/cache/new/order-flow-for-woocommerce-current.zip';
const MS = '/cache/site-ms';
const MS_DB = 'of_destr_ms';
const MS_BASE = 'http://localhost:8081';

function configHash() {
	return JSON.stringify( [ option( 'ofwc_statuses' ), option( 'ofwc_transitions' ), option( 'ofwc_settings' ) ] );
}

export async function s6( S, ctx ) {
	const hpos = ctx.hpos;
	const { admin } = S;

	await scenario( '6.1', 'Upgrade from 0.1.0 to this build and back', 'Settings, orders, history and documents survive the upgrade; every screen works; a downgrade does not fail', async () => {
		if ( ! fs.existsSync( NEW_ZIP ) ) {
			note( 'the current build zip is missing; scenario skipped' );
			return;
		}
		const mark = logMark();
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		// install 0.1.0 and build up some state with it
		let r = wp( [ 'plugin', 'install', OLD_ZIP, '--force', '--activate' ] );
		ok( r.code === 0, `install 0.1.0: ${ r.err.slice( 0, 200 ) }` );
		eq( wp( [ 'plugin', 'get', 'order-flow-for-woocommerce', '--field=version' ] ).out.trim(), '0.1.0', 'installed version' );
		resetOrder( id );
		for ( const to of [ 'awaiting-approval', 'approved' ] ) {
			const x = await adminPost( admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', to ], [ 'ofwc_comment', `old ${ to }` ] ], { nonceAction: `ofwc_transition_${ id }`, nonceField: 'ofwc_nonce' } );
			eq( x.status, 302, `0.1.0 change to ${ to }` );
		}
		const config = configHash();
		const facts = JSON.stringify( orderFacts( id ).history );

		r = wp( [ 'plugin', 'install', NEW_ZIP, '--force', '--activate' ] );
		ok( r.code === 0, `upgrade: ${ r.err.slice( 0, 200 ) }` );
		note( `upgraded to ${ wp( [ 'plugin', 'get', 'order-flow-for-woocommerce', '--field=version' ] ).out.trim() }` );
		eq( configHash(), config, 'settings changed by the upgrade' );
		eq( JSON.stringify( orderFacts( id ).history ), facts, 'history changed by the upgrade' );
		eq( orderFacts( id ).status, 'approved', 'order status after the upgrade' );
		for ( const tab of [ 'statuses', 'transitions', 'documents', 'import-export' ] ) {
			const page = await http( 'GET', `/wp-admin/admin.php?page=ofwc&tab=${ tab }`, { cookie: admin.cookie } );
			ok( page.status === 200 && sane( page ), `tab ${ tab } after the upgrade: ${ page.status }` );
		}
		const dl = await adminPost( admin, 'ofwc_pdf', [ [ 'order_id', String( id ) ], [ 'status', 'approved' ] ], { nonceAction: `ofwc_pdf_${ id }_approved`, method: 'GET' } );
		ok( dl.status === 200 && dl.text.startsWith( '%PDF' ), `a document made after the upgrade: ${ dl.status }` );
		const x = await adminPost( admin, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', 'in-production' ], [ 'ofwc_comment', 'new build' ] ], { nonceAction: `ofwc_transition_${ id }`, nonceField: 'ofwc_nonce' } );
		eq( x.status, 302, 'a change made after the upgrade' );
		eq( orderFacts( id ).history.length, 3, 'history rows after a new change' );

		// downgrade
		r = wp( [ 'plugin', 'install', OLD_ZIP, '--force', '--activate' ] );
		ok( r.code === 0, `downgrade: ${ r.err.slice( 0, 200 ) }` );
		for ( const path of [ '/wp-admin/admin.php?page=ofwc', editUrl( id, hpos ), listUrl( hpos ) ] ) {
			const page = await http( 'GET', path, { cookie: admin.cookie } );
			ok( page.status === 200 && sane( page ), `${ path.slice( 0, 50 ) } after the downgrade: ${ page.status }` );
		}
		eq( orderFacts( id ).history.length, 3, 'history survives the downgrade' );
		// and forward again, leaving the current build installed
		r = wp( [ 'plugin', 'install', NEW_ZIP, '--force', '--activate' ] );
		ok( r.code === 0, `back to the current build: ${ r.err.slice( 0, 200 ) }` );
		logClean( mark, '6.1' );
	}, 'live (6.1)' );

	await scenario( '6.2', 'Multisite with network activation', 'The plugin works on a sub site (statuses registered, change, document); a site without WooCommerce shows a notice and nothing breaks; uninstall cleans every site and leaves look-alike rows', async () => {
		if ( ! fs.existsSync( '/cache/stage/order-flow-for-woocommerce' ) ) {
			note( 'no staged plugin files; scenario skipped' );
			return;
		}
		const wpms = ( args, opts = {} ) => spawnSync( 'php', [ '-d', 'memory_limit=1G', '-d', 'display_errors=stderr', '/wp-cli.phar', `--path=${ MS }`, ...args ], { encoding: 'utf8', ...opts } );
		const out = ( r ) => ( r.stdout || '' ).trim();
		// a clean network in its own database and folder
		sqlRoot( `DROP DATABASE IF EXISTS ${ MS_DB }; CREATE DATABASE ${ MS_DB } DEFAULT CHARACTER SET utf8mb4` );
		spawnSync( 'rm', [ '-rf', MS, `${ MS }.tmp` ] );
		fs.mkdirSync( `${ MS }.tmp`, { recursive: true } );
		spawnSync( 'unzip', [ '-q', '/cache/core/wordpress-7.1.3.zip', '-d', `${ MS }.tmp` ] );
		fs.renameSync( `${ MS }.tmp/wordpress`, MS );
		fs.rmdirSync( `${ MS }.tmp` );
		for ( const args of [
			[ 'config', 'create', `--dbname=${ MS_DB }`, '--dbuser=root', '--dbpass=wordpress', '--dbhost=db', '--skip-check' ],
			[ 'config', 'set', 'WP_DEBUG', 'true', '--raw', '--type=constant' ],
			[ 'config', 'set', 'WP_DEBUG_LOG', 'true', '--raw', '--type=constant' ],
			[ 'config', 'set', 'WP_DEBUG_DISPLAY', 'false', '--raw', '--type=constant' ],
			[ 'config', 'set', 'FS_METHOD', 'direct', '--type=constant' ],
		] ) {
			wpms( args );
		}
		let r = wpms( [ 'core', 'multisite-install', `--url=${ MS_BASE }`, '--title=OF Network', '--admin_user=of-destr-admin', `--admin_password=${ PASS }`, '--admin_email=admin@example.test', '--skip-email' ] );
		ok( r.status === 0, `multisite install: ${ ( r.stderr || '' ).slice( 0, 300 ) }` );
		fs.mkdirSync( `${ MS }/wp-content/mu-plugins`, { recursive: true } );
		spawnSync( 'cp', [ '/repo/tests/Destructive/mu/of-capture.php', `${ MS }/wp-content/mu-plugins/` ] );
		spawnSync( 'rsync', [ '-a', '/cache/stage/order-flow-for-woocommerce/', `${ MS }/wp-content/plugins/order-flow-for-woocommerce/` ] );
		r = wpms( [ 'plugin', 'install', '/cache/woo/woocommerce.11.2.1.zip' ] );
		ok( r.status === 0, `install WooCommerce: ${ ( r.stderr || '' ).slice( 0, 200 ) }` );
		for ( const slug of [ 'shop2', 'plain3' ] ) {
			r = wpms( [ 'site', 'create', `--slug=${ slug }`, `--title=${ slug }`, '--email=admin@example.test' ] );
			ok( r.status === 0, `create site ${ slug }: ${ ( r.stderr || '' ).slice( 0, 200 ) }` );
		}
		const shop = `${ MS_BASE }/shop2/`;
		// wp-cli mangles the port when it writes the network domain: repair it
		sql( "UPDATE wp_site SET domain = 'localhost:8081'", MS_DB );
		sql( "UPDATE wp_blogs SET domain = 'localhost:8081'", MS_DB );
		wpms( [ 'config', 'set', 'DOMAIN_CURRENT_SITE', 'localhost:8081', '--type=constant' ] );
		for ( const [ n, path ] of [ [ 1, '' ], [ 2, '/shop2' ], [ 3, '/plain3' ] ] ) {
			const t = n === 1 ? 'wp_options' : `wp_${ n }_options`;
			sql( `UPDATE ${ t } SET option_value = 'http://localhost:8081${ path }' WHERE option_name IN ('siteurl','home')`, MS_DB );
		}
		const shopArg = `${ MS_BASE }/shop2`;
		const plainArg = `${ MS_BASE }/plain3`;
		// the plugin declares "Requires Plugins: woocommerce", so on a network WooCommerce has to be network-active too
		r = wpms( [ 'plugin', 'activate', 'woocommerce', '--network' ] );
		ok( r.status === 0, `network activation of WooCommerce: ${ ( r.stderr || r.stdout || '' ).slice( 0, 300 ) }` );
		r = wpms( [ 'plugin', 'activate', 'order-flow-for-woocommerce', '--network' ] );
		ok( r.status === 0 && ! /Fatal/.test( ( r.stdout || '' ) + ( r.stderr || '' ) ), `network activation: ${ ( r.stderr || r.stdout || '' ).slice( 0, 300 ) }` );
		// configuration and an order on site 2, and some rows on site 3 (WooCommerce off there)
		const seedSite = ( url ) => wpms( [ 'eval', `
			update_option( 'ofwc_statuses', array( 'awaiting-approval' => array( 'slug' => 'awaiting-approval', 'label' => 'Awaiting approval', 'pdf_enabled' => true, 'email_customer' => true, 'position' => 1 ), 'approved' => array( 'slug' => 'approved', 'label' => 'Approved', 'pdf_enabled' => true, 'position' => 2 ) ), true );
			update_option( 'ofwc_transitions', array( array( 'from' => 'pending', 'to' => 'awaiting-approval', 'roles' => array( 'administrator' ), 'require_comment' => false ) ), true );
			update_option( 'ofwc_settings', array( 'store_details' => 'Net shop' ), true );
			update_option( 'my_ofwc_option', 'foreign' );
			set_transient( 'ofwc_notice_1', array( 'x' ), 300 );
		`, `--url=${ url }` ] );
		seedSite( shopArg );
		seedSite( plainArg );
		r = wpms( [ 'eval', `
			$o = wc_create_order( array( 'status' => 'pending' ) );
			$o->set_billing_email( 'ms@example.test' );
			$o->set_billing_first_name( 'Multi' );
			$p = new WC_Product_Simple(); $p->set_name( 'Net product' ); $p->set_regular_price( '5' ); $p->save();
			$o->add_product( $p, 2 );
			$o->calculate_totals( false );
			$o->save();
			echo $o->get_id();
		`, `--url=${ shopArg }` ] );
		const orderId = parseInt( out( r ).split( '\n' ).pop(), 10 );
		ok( orderId > 0, `order created on the sub site: ${ out( r ).slice( 0, 200 ) } ${ ( r.stderr || '' ).slice( 0, 200 ) }` );

		// web server for the network
		// what the .htaccess of a sub-directory network does: /site/wp-admin/... and /site/*.php reach the real files
		fs.writeFileSync( '/cache/tmp/ms-router.php', `<?php
$root = '${ MS }';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( preg_match( '#^/[_0-9a-zA-Z-]+/((?:wp-(?:content|admin|includes)/.*)|(?:[^/]*\\.php))$#', $path, $m ) && is_file( $root . '/' . $m[1] ) ) {
	$_SERVER['SCRIPT_NAME'] = '/' . $m[1];
	$_SERVER['PHP_SELF'] = '/' . $m[1];
	$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $m[1];
	chdir( dirname( $root . '/' . $m[1] ) );
	require $root . '/' . $m[1];
	return true;
}
return false;
` );
		const server = spawn( 'php', [ '-d', 'memory_limit=256M', '-d', 'display_errors=0', '-d', `error_log=${ MS }/wp-content/php-error.log`, '-S', '0.0.0.0:8081', '-t', MS, '/cache/tmp/ms-router.php' ], { detached: true, stdio: 'ignore', env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' } } );
		server.unref();
		try {
			let up = false;
			for ( let i = 0; i < 30 && ! up; i++ ) {
				try {
					const x = await fetch( MS_BASE + '/', { redirect: 'manual' } );
					up = x.status > 0;
				} catch {
					await sleep( 500 );
				}
			}
			ok( up, 'the network web server started' );
			const get = async ( url, cookie = '' ) => {
				const res = await fetch( url, { headers: cookie ? { Cookie: cookie } : {}, redirect: 'manual' } );
				return { status: res.status, text: await res.text(), headers: res.headers };
			};
			const loginAt = async ( base ) => {
				const res = await fetch( `${ base }wp-login.php`, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' }, body: new URLSearchParams( { log: 'of-destr-admin', pwd: PASS, 'wp-submit': 'Log In', redirect_to: `${ base }wp-admin/`, testcookie: '1' } ).toString() } );
				return res.headers.getSetCookie().map( ( c ) => c.split( ';' )[ 0 ] ).filter( ( c ) => ! c.endsWith( '=' ) && ! /=deleted$/.test( c ) ).join( '; ' );
			};
			const cookie = await loginAt( shop );
			ok( /wordpress_logged_in/.test( cookie ), 'logged in on the sub site' );
			// a sub site starts with the legacy posts store
			const editPath = `${ shop }wp-admin/post.php?post=${ orderId }&action=edit`;
			let page = await get( editPath, cookie );
			if ( page.status === 302 ) {
				page = await get( editPath, cookie ); // WooCommerce sends the first visit to its setup page
			}
			ok( page.status === 200 && page.text.includes( 'ofwc-box' ), `Order Flow box on the sub site order screen (HTTP ${ page.status }, Location ${ page.headers.get( 'location' ) })` );
			const nonce = ( page.text.match( /data-nonce="([0-9a-f]{10})"/ ) || [] )[ 1 ];
			if ( nonce ) {
				const res = await fetch( `${ shop }wp-admin/admin-post.php`, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie }, body: new URLSearchParams( { action: 'ofwc_transition', order_id: String( orderId ), ofwc_nonce: nonce, ofwc_to: 'awaiting-approval', ofwc_comment: 'net' } ).toString() } );
				eq( res.status, 302, 'status change on the sub site' );
				const st = wpms( [ 'eval', `echo wc_get_order( ${ orderId } )->get_status();`, `--url=${ shopArg }` ] );
				eq( out( st ).split( '\n' ).pop(), 'awaiting-approval', 'status stored on the sub site' );
				page = await get( editPath, cookie );
				const link = ( page.text.match( /href="([^"]*action=ofwc_pdf[^"]*)"/ ) || [] )[ 1 ];
				ok( !! link, 'a document link appears on the sub site' );
				if ( link ) {
					const pdf = await fetch( link.replace( /&amp;|&#038;/g, '&' ), { headers: { Cookie: cookie }, redirect: 'manual' } );
					const buf = Buffer.from( await pdf.arrayBuffer() );
					ok( pdf.status === 200 && buf.subarray( 0, 4 ).toString() === '%PDF', `document on the sub site: HTTP ${ pdf.status }` );
				}
			}
			const main = await get( `${ MS_BASE }/wp-admin/`, await loginAt( `${ MS_BASE }/` ) );
			ok( main.status < 500 && sane( main ), `main site: HTTP ${ main.status }, Location ${ main.headers.get( 'location' ) }` );
			const plain = await get( `${ MS_BASE }/plain3/wp-admin/`, await loginAt( `${ MS_BASE }/plain3/` ) );
			ok( plain.status < 500 && sane( plain ), `site 3: HTTP ${ plain.status }` );
		} finally {
			try {
				process.kill( server.pid );
			} catch {
				// gone
			}
		}
		// uninstall across the network
		r = wpms( [ 'plugin', 'deactivate', 'order-flow-for-woocommerce', '--network' ] );
		r = wpms( [ 'plugin', 'uninstall', 'order-flow-for-woocommerce' ] );
		ok( r.status === 0, `network uninstall: ${ ( r.stderr || r.stdout || '' ).slice( 0, 300 ) }` );
		const rowsLeft = [];
		for ( const prefix of [ 'wp_', 'wp_2_', 'wp_3_' ] ) {
			const rows = sql( `SELECT option_name FROM ${ prefix }options WHERE option_name REGEXP '^(_transient_|_transient_timeout_)?(woocommerce_)?ofwc_'`, MS_DB ).split( '\n' ).filter( Boolean );
			rowsLeft.push( ...rows.map( ( x ) => `${ prefix }${ x }` ) );
			const foreign = sql( `SELECT COUNT(*) FROM ${ prefix }options WHERE option_name = 'my_ofwc_option'`, MS_DB );
			if ( prefix !== 'wp_' ) {
				eq( foreign, '1', `look-alike row on ${ prefix }` );
			}
		}
		ok( rowsLeft.length === 0, `rows left on the network after uninstall: ${ rowsLeft.join( ', ' ) }` );
		spawnSync( 'rm', [ '-rf', MS ] );
		sqlRoot( `DROP DATABASE IF EXISTS ${ MS_DB }` );
	}, 'live (6.2)' );
}
