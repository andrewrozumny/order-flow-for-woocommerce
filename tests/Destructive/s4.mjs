// Group 4: PDF documents (size, characters, hostile content, memory and time) and the dompdf conflict.
import fs from 'node:fs';
import http_ from 'node:http';
import {
	SITE, adminPost, baseline, defaultServer, eq, http, logClean, logMark, note, ok, oneOf, option, php, plugin, resetOrder, restartServer, scenario, setOption, sh, sleep, wp,
} from './lib.mjs';

const PDFINFO = '/repo/tests/Snapshots/pdfinfo.php';

function pdfInfo( buf ) {
	fs.writeFileSync( '/cache/tmp/s4.pdf', buf );
	const r = sh( 'php', [ '-d', 'display_errors=0', PDFINFO, '/cache/tmp/s4.pdf' ] );
	try {
		return JSON.parse( r.out );
	} catch {
		return { error: r.err || r.out };
	}
}

async function download( admin, id, slug ) {
	const t0 = Date.now();
	const url = `/wp-admin/admin-post.php?action=ofwc_pdf&order_id=${ id }&status=${ slug }&_wpnonce=`;
	const nonceR = await adminPost( admin, 'ofwc_pdf', [ [ 'order_id', String( id ) ], [ 'status', slug ] ], { nonceAction: `ofwc_pdf_${ id }_${ slug }`, method: 'GET' } );
	void url;
	return { ...nonceR, ms: Date.now() - t0 };
}

async function binary( session, id, slug ) {
	const nonce = (await import( './lib.mjs' )).sessionNonce( session, `ofwc_pdf_${ id }_${ slug }` );
	const t0 = Date.now();
	try {
		const res = await fetch( `http://localhost:8080/wp-admin/admin-post.php?action=ofwc_pdf&order_id=${ id }&status=${ slug }&_wpnonce=${ nonce }`, { headers: { Cookie: session.cookie }, redirect: 'manual', signal: AbortSignal.timeout( 120000 ) } );
		const buf = Buffer.from( await res.arrayBuffer() );
		return { status: res.status, buf, ms: Date.now() - t0 };
	} catch ( e ) {
		return { status: 0, buf: Buffer.alloc( 0 ), ms: Date.now() - t0, error: String( e ) };
	}
}

function bigOrder( id, lines ) {
	php( `
		$o = wc_get_order( ${ id } );
		foreach ( $o->get_items() as $i ) { $o->remove_item( $i->get_id() ); }
		$p = wc_get_product( wc_get_product_id_by_sku( 'SNAP-001' ) );
		for ( $n = 1; $n <= ${ lines }; $n++ ) {
			$item = new WC_Order_Item_Product();
			$item->set_product( $p );
			$item->set_name( 'Line ' . $n . ' Oak desk, 120 cm with a long product title to make the row wrap in the narrow column of the document' );
			$item->set_quantity( 1 + ( $n % 5 ) );
			$item->set_subtotal( 10 );
			$item->set_total( 10 );
			$o->add_item( $item );
		}
		$o->calculate_totals( false );
		$o->save();
	` );
}

export async function s4( S, ctx ) {
	const hpos = ctx.hpos;
	const { admin } = S;

	await scenario( '4.1', 'Large documents under 128 MB of memory and 30 seconds', 'An order with 300 lines and a 50-page document are produced without a timeout or a memory error', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		await restartServer( { memory: '128M', time: 30 } );
		try {
			resetOrder( id, 'awaiting-approval' );
			bigOrder( id, 300 );
			let r = await binary( admin, id, 'awaiting-approval' );
			ok( r.status === 200 && r.buf.subarray( 0, 4 ).toString() === '%PDF', `300 lines: status ${ r.status } after ${ r.ms } ms ${ r.error || '' }` );
			if ( r.status === 200 ) {
				const info = pdfInfo( r.buf );
				note( `300 lines: ${ info.pages } pages in ${ r.ms } ms` );
				ok( info.pages >= 5, `300 lines produced ${ info.pages } pages` );
			}
			bigOrder( id, 700 );
			r = await binary( admin, id, 'awaiting-approval' );
			ok( r.status === 200 && r.buf.subarray( 0, 4 ).toString() === '%PDF', `700 lines: status ${ r.status } after ${ r.ms } ms ${ r.error || '' }` );
			if ( r.status === 200 ) {
				const info = pdfInfo( r.buf );
				note( `700 lines: ${ info.pages } pages in ${ r.ms } ms` );
				ok( info.pages >= 50, `700 lines produced ${ info.pages } pages (wanted 50 or more)` );
			}
			// a very long status text (4200 paragraphs) is the stress case: it must not end in a fatal error
			const statuses = option( 'ofwc_statuses' );
			statuses[ 'awaiting-approval' ].pdf_text = Array.from( { length: 4200 }, ( _, i ) => `<p>Paragraph ${ i + 1 }: terms of the work order, repeated for the size test.</p>` ).join( '' );
			setOption( 'ofwc_statuses', statuses );
			r = await binary( admin, id, 'awaiting-approval' );
			ok( r.status === 200 && r.buf.subarray( 0, 4 ).toString() === '%PDF', `50-page document: status ${ r.status } after ${ r.ms } ms ${ r.error || '' }` );
			if ( r.status === 200 ) {
				const info = pdfInfo( r.buf );
				note( `long text: ${ info.pages } pages in ${ r.ms } ms` );
				ok( info.pages >= 50, `the long text produced ${ info.pages } pages` );
			}
		} finally {
			await defaultServer();
		}
		logClean( mark, '4.1' );
	}, 'live (4.1)' );

	await scenario( '4.2', 'Names and addresses in other scripts', 'Cyrillic, CJK, Arabic and emoji never break the document; Cyrillic and Latin text is extractable', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		const names = { cyr: 'Дубовый стол «Север»', cjk: '橡木书桌 日本語のテスト', ar: 'مكتب من خشب البلوط', emoji: 'Desk 😀🔥👨‍👩‍👧', mix: 'Ünïcödé ñ ß ł' };
		php( `
			$o = wc_get_order( ${ id } );
			$names = ${ JSON.stringify( JSON.stringify( names ) ) };
			$names = array_values( json_decode( $names, true ) );
			$i = 0;
			foreach ( $o->get_items() as $item ) { $item->set_name( $names[ $i ] ?? 'x' ); $item->save(); ++$i; }
			$o->set_billing_first_name( 'Анна' ); $o->set_billing_last_name( '李小龍' ); $o->set_billing_address_1( 'ул. Пушкина 10, مبنى ١٢' ); $o->set_billing_city( '東京都' ); $o->set_billing_company( 'Ünïcödé ñ ß ł 😀' );
			$o->save();
		` );
		resetOrder( id, 'awaiting-approval' );
		const r = await binary( admin, id, 'awaiting-approval' );
		ok( r.status === 200 && r.buf.subarray( 0, 4 ).toString() === '%PDF', `status ${ r.status }` );
		if ( r.status === 200 ) {
			const info = pdfInfo( r.buf );
			ok( ! info.error, `PDF parse: ${ info.error }` );
			const text = ( info.text || [] ).flat().join( '\n' );
			ok( /Дубовый стол/.test( text ), 'Cyrillic product name is in the text layer' );
			ok( /Ünïcödé/.test( text ), `accented Latin text is in the text layer (company line: ${ ( text.match( /.*Snapshot Studio.*|.*cödé.*|.*Unic.*/ ) || [ 'not found' ] )[ 0 ] })` );
			note( `CJK in text layer: ${ /橡木/.test( text ) }; Arabic: ${ /مكتب/.test( text ) }; emoji: ${ /😀/.test( text ) } (DejaVu Sans has no CJK or emoji glyphs)` );
		}
		logClean( mark, '4.2' );
	}, 'live (4.2)' );

	await scenario( '4.3', 'Hostile content in the document: no network request, no local file read', 'dompdf never requests a URL or reads a local file named in order data or in the template text', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		const hits = [];
		const srv = http_.createServer( ( req, res ) => {
			hits.push( req.url );
			res.statusCode = 404;
			res.end();
		} );
		await new Promise( ( r ) => srv.listen( 9777, '127.0.0.1', r ) );
		try {
			const base = 'http://127.0.0.1:9777';
			const text = [
				`<p><img src="${ base }/text-img" alt=""></p>`,
				'<p><img src="file:///etc/passwd" alt=""></p>',
				`<p><img src="//127.0.0.1:9777/proto-relative" alt=""></p>`,
				`<div style="background-image:url(${ base }/text-bg)">bg</div>`,
				`<p style="list-style-image:url(${ base }/list)">x</p>`,
				'<p>marker-end</p>',
			].join( '' );
			const statuses = option( 'ofwc_statuses' );
			statuses[ 'awaiting-approval' ].pdf_text = text;
			setOption( 'ofwc_statuses', statuses );
			setOption( 'ofwc_settings', { store_details: `<img src="${ base }/store" alt=""><link rel="stylesheet" href="${ base }/css"><style>@import url(${ base }/import);</style>` } );
			php( `
				$o = wc_get_order( ${ id } );
				$o->set_billing_company( '<img src="${ base }/company"><link rel=stylesheet href="${ base }/company-css">' );
				$o->set_billing_address_1( '<style>@import url(${ base }/addr-import)</style> file:///etc/passwd' );
				foreach ( $o->get_items() as $item ) { $item->set_name( '<img src="${ base }/item" onerror=alert(1)>' ); $item->save(); break; }
				$o->save();
			` );
			resetOrder( id, 'awaiting-approval' );
			const r = await binary( admin, id, 'awaiting-approval' );
			ok( r.status === 200 && r.buf.subarray( 0, 4 ).toString() === '%PDF', `status ${ r.status }` );
			await sleep( 500 );
			ok( hits.length === 0, `dompdf made ${ hits.length } outside requests: ${ hits.join( ', ' ) }` );
			if ( r.status === 200 ) {
				const info = pdfInfo( r.buf );
				const all = ( info.text || [] ).flat().join( '\n' );
				ok( ! /root:.*:0:0/.test( all ), 'the text layer holds /etc/passwd' );
				ok( /marker-end/.test( all ), 'the document still renders the rest of the text' );
			}
		} finally {
			srv.close();
		}
		logClean( mark, '4.3' );
	}, 'live (4.3)' );

	await scenario( '4.4', 'Another plugin ships its own dompdf', 'Both plugins create PDFs in either activation order; no "class already declared" fatal; the loaded dompdf is reported', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const zip = '/cache/extras/woocommerce-pdf-invoices-packing-slips.zip';
		if ( ! fs.existsSync( zip ) ) {
			note( 'the PDF invoices plugin archive is missing; scenario skipped' );
			return;
		}
		const mark = logMark();
		const inst = wp( [ 'plugin', 'install', zip, '--force' ] );
		ok( inst.code === 0, `install: ${ inst.err.slice( 0, 200 ) }` );
		const theirs = wp( [ 'plugin', 'list', '--format=json' ] ).out;
		const slug = ( JSON.parse( theirs.slice( theirs.indexOf( '[' ) ) ).find( ( p ) => /pdf-invoices/.test( p.name ) ) || {} ).name;
		ok( !! slug, 'the other plugin is installed' );
		if ( ! slug ) {
			return;
		}
		// the first activation of the other plugin installs its defaults; do it once before the real runs
		wp( [ 'plugin', 'activate', slug ] );
		wp( [ 'plugin', 'deactivate', slug ] );
		const probe = ( first ) => `
			wp_set_current_user( 1 );
			$o = wc_get_order( ${ id } );
			$out = array();
			$ours = static function () use ( $o ) {
				$g = new OrderFlowWC\\Pdf\\Generator();
				$pdf = $g->render_pdf( $o, 'awaiting-approval' );
				return strlen( $pdf ) > 1000 && str_starts_with( $pdf, '%PDF' );
			};
			$theirs = static function () use ( $o ) {
				if ( ! function_exists( 'wcpdf_get_document' ) ) { return 'no api'; }
				$doc = wcpdf_get_document( 'invoice', $o, true );
				if ( ! $doc ) { return 'no document'; }
				$pdf = $doc->get_pdf();
				return strlen( (string) $pdf ) > 1000 && str_starts_with( (string) $pdf, '%PDF' );
			};
			try {
				${ first === 'ours' ? `$out['ours'] = $ours(); $out['theirs'] = $theirs();` : `$out['theirs'] = $theirs(); $out['ours'] = $ours();` }
			} catch ( \\Throwable $e ) { $out['error'] = get_class( $e ) . ': ' . $e->getMessage(); }
			$cls = class_exists( 'Dompdf\\Dompdf', false ) ? ( new ReflectionClass( 'Dompdf\\Dompdf' ) )->getFileName() : 'none';
			$out['dompdf'] = str_replace( WP_PLUGIN_DIR, '', (string) $cls );
			echo wp_json_encode( $out );
		`;
		const result = {};
		for ( const order of [ [ 'order-flow-for-woocommerce', slug ], [ slug, 'order-flow-for-woocommerce' ] ] ) {
			wp( [ 'plugin', 'deactivate', slug, 'order-flow-for-woocommerce' ] );
			for ( const p of order ) {
				const a = wp( [ 'plugin', 'activate', p ] );
				ok( ! /Fatal|Cannot redeclare|already/i.test( a.out + a.err ), `activating ${ p }: ${ ( a.out + a.err ).slice( 0, 300 ) }` );
			}
			for ( const first of [ 'ours', 'theirs' ] ) {
				const r = wp( [ 'eval', probe( first ) ] );
				const line = r.out.trim().split( '\n' ).pop();
				let parsed;
				try {
					parsed = JSON.parse( line );
				} catch {
					parsed = { raw: ( r.out + r.err ).slice( 0, 400 ) };
				}
				const key = `activated ${ order[ 0 ].slice( 0, 12 ) } first, used ${ first } first`;
				result[ key ] = parsed;
				ok( parsed.ours === true && parsed.theirs === true && ! parsed.error, `${ key }: ${ JSON.stringify( parsed ).slice( 0, 400 ) }` );
			}
			// and over HTTP
			resetOrder( id, 'awaiting-approval' );
			const dl = await binary( admin, id, 'awaiting-approval' );
			ok( dl.status === 200, `HTTP PDF with the other plugin active (${ order.join( ' then ' ) }): ${ dl.status }` );
		}
		note( `whose dompdf loaded: ${ JSON.stringify( result ) }` );
		wp( [ 'plugin', 'deactivate', slug ] );
		wp( [ 'plugin', 'uninstall', slug, '--deactivate' ] );
		wp( [ 'plugin', 'activate', 'order-flow-for-woocommerce' ] );
		logClean( mark, '4.4' );
	}, 'live (4.4)' );
}
