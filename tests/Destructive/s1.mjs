// Group 1: hostile input into every entry point (admin-post handlers, admin pages), roles and nonces.
import {
	liveMarkup, BASE, BIG, PAYLOADS, adminPost, baseline, captureOff, captureOn, editUrl, eq, formFields, formString, htmlProblems, http, listUrl, logClean, logMark,
	nonceFor, note, ok, oneOf, option, orderFacts, pluginRows, resetOrder, sane, scenario, sessionNonce, setOption, sql, stateHash, takeMails, withField, wp,
} from './lib.mjs';

const T = ( id ) => `ofwc_transition_${ id }`;

function transition( session, id, to, comment, nonce ) {
	const fields = [ [ 'order_id', String( id ) ], [ 'ofwc_to', to ], [ 'ofwc_comment', comment ] ];
	return adminPost( session, 'ofwc_transition', fields, { nonceAction: T( id ), nonceField: 'ofwc_nonce', nonce } );
}

const STATUS_FIELDS = [ 'label', 'slug', 'color', 'icon', 'position', 'email_subject', 'email_heading', 'email_body', 'pdf_title', 'pdf_text' ];

function statusRowsValid( rows ) {
	if ( ! rows || typeof rows !== 'object' ) {
		return 'option is not an array';
	}
	for ( const r of Object.values( rows ) ) {
		if ( typeof r !== 'object' || ! /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test( r.slug || '' ) || r.slug.length > 17 ) {
			return `bad slug ${ JSON.stringify( r && r.slug ) }`;
		}
		if ( ! /^#[0-9a-f]{6}$/.test( r.color ) ) {
			return `bad colour ${ r.color }`;
		}
		if ( /<[a-z!/]/i.test( r.label ) ) {
			return `label keeps markup: ${ r.label.slice( 0, 40 ) }`;
		}
	}
	return '';
}

export async function s1( S, ctx ) {
	const { admin, sub, shop, custa, custb, editor } = S;
	const hpos = ctx.hpos;

	await scenario( '1.1', 'Status change endpoint: roles and nonces', 'Guest 400, subscriber, editor and customer 403, every bad nonce 403, shop manager and administrator work; refused requests change nothing and send nothing', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		captureOn();
		const mark = logMark();
		resetOrder( id );
		const before = stateHash( id );

		const g = await transition( null, id, 'awaiting-approval', 'x', 'abcdef1234' );
		eq( g.status, 400, 'guest' );
		for ( const [ name, s ] of Object.entries( { sub, editor, custa } ) ) {
			const r = await transition( s, id, 'awaiting-approval', 'x' );
			eq( r.status, 403, `${ name } with a valid nonce` );
		}
		const variants = {
			missing: null,
			empty: '',
			forged: '0123456789',
			too_long: 'a'.repeat( 5000 ),
			other_order: nonceFor( admin, T( id + 1 ) ),
			other_action: nonceFor( admin, 'ofwc_pdf' ),
			expired_3_ticks: nonceFor( admin, T( id ), 3 ),
			expired_9_ticks: nonceFor( admin, T( id ), 9 ),
			other_user: nonceFor( sub, T( id ) ),
		};
		for ( const [ name, v ] of Object.entries( variants ) ) {
			const r = await transition( admin, id, 'awaiting-approval', 'x', v );
			eq( r.status, 403, `administrator with ${ name } nonce` );
		}
		const arr = await http( 'POST', '/wp-admin/admin-post.php', { cookie: admin.cookie, form: `action=ofwc_transition&order_id=${ id }&ofwc_nonce[]=x&ofwc_to=awaiting-approval` } );
		eq( arr.status, 403, 'array nonce' );
		eq( stateHash( id ), before, 'refused requests changed the order or the plugin rows' );
		eq( takeMails().length, 0, 'refused requests sent mail' );

		const okShop = await transition( shop, id, 'awaiting-approval', 'by shop manager' );
		eq( okShop.status, 302, 'shop manager moves pending to awaiting-approval' );
		eq( orderFacts( id ).status, 'awaiting-approval', 'status after the shop manager change' );
		resetOrder( id );
		const okAdmin = await transition( admin, id, 'awaiting-approval', 'by admin' );
		eq( okAdmin.status, 302, 'administrator' );
		eq( orderFacts( id ).status, 'awaiting-approval', 'status after the administrator change' );
		logClean( mark, '1.1' );
		captureOff();
	}, 'live (1.1)' );

	await scenario( '1.2', 'Status change endpoint: hostile parameters', 'Never a 500; an unknown or hostile target changes nothing; comments are stored as inert text and the metabox stays safe; a very large comment is refused or cut', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		captureOn();
		const mark = logMark();
		for ( const [ name, v ] of Object.entries( PAYLOADS ) ) {
			resetOrder( id );
			const r = await transition( admin, id, v, 'ok comment' );
			ok( sane( r ), `hostile target ${ name }: status ${ r.status }` );
			eq( orderFacts( id ).status, 'pending', `hostile target ${ name } must not change the status` );
		}
		for ( const [ name, v ] of Object.entries( PAYLOADS ) ) {
			resetOrder( id );
			const r = await transition( admin, id, 'awaiting-approval', v );
			ok( sane( r ), `hostile comment ${ name }: status ${ r.status }` );
			const facts = orderFacts( id );
			const stored = ( facts.history[ 0 ] || {} ).comment || '';
			if ( name === 'one_mb' ) {
				ok( facts.history.length === 1 && stored.length <= 20000, `1 MB comment: ${ facts.history.length } history rows, ${ stored.length } characters kept (a lost row means the stored history was cut)` );
			}
			if ( name !== 'one_mb' ) {
				const page = await http( 'GET', editUrl( id, hpos ), { cookie: admin.cookie } );
				const problems = htmlProblems( page.text.slice( page.text.indexOf( 'ofwc-box' ) ) );
				ok( problems.length === 0, `metabox with comment ${ name }: ${ problems.join( ', ' ) }` );
			}
		}
		// arrays instead of strings
		resetOrder( id );
		const nonce = sessionNonce( admin, T( id ) );
		for ( const body of [
			`action=ofwc_transition&ofwc_nonce=${ nonce }&order_id[]=${ id }&ofwc_to=awaiting-approval`,
			`action=ofwc_transition&ofwc_nonce=${ nonce }&order_id=${ id }&ofwc_to[]=awaiting-approval`,
			`action=ofwc_transition&ofwc_nonce=${ nonce }&order_id=${ id }&ofwc_to=awaiting-approval&ofwc_comment[]=a&ofwc_comment[b]=c`,
			`action=ofwc_transition&ofwc_nonce=${ nonce }&order_id=${ id }`,
			`action=ofwc_transition&ofwc_nonce=${ nonce }`,
			`action=ofwc_transition&ofwc_nonce=${ nonce }&order_id=${ id }&ofwc_to=awaiting-approval&extra=1&extra2[]=2`,
		] ) {
			const r = await http( 'POST', '/wp-admin/admin-post.php', { cookie: admin.cookie, form: body } );
			ok( sane( r ), `array/missing parameters ${ body.slice( 40, 110 ) }: status ${ r.status }` );
		}
		// order ids that are not orders
		for ( const bad of [ '0', '-1', '99999999', '1.5', '1e3', 'abc', '%00' ] ) {
			const r = await adminPost( admin, 'ofwc_transition', [ [ 'order_id', bad ], [ 'ofwc_to', 'approved' ] ], { nonceAction: T( Math.abs( parseInt( bad, 10 ) || 0 ) ), nonceField: 'ofwc_nonce' } );
			ok( sane( r ), `order id ${ bad }: status ${ r.status }` );
		}
		logClean( mark, '1.2' );
		captureOff();
	}, 'live (1.2)' );

	await scenario( '1.3', 'Settings forms: hostile values in every field of a status', 'Every save ends in a redirect; the stored statuses stay well formed (slug, colour, plain-text label); the pages show the values as inert text', async () => {
		const ids = baseline( { hpos } );
		const mark = logMark();
		const action = 'ofwc_save_status';
		const first = await adminPost( admin, action, [ [ 'label', 'Hostile test' ], [ 'slug', 'hostile-test' ] ], { nonceAction: action } );
		eq( first.status, 302, 'create the status the loop edits' );
		for ( const field of STATUS_FIELDS ) {
			for ( const [ name, v ] of Object.entries( PAYLOADS ) ) {
				// Editing an existing status for every field except the slug, which only matters when a status is created.
				const fields = field === 'slug' ? [ [ 'label', 'Slug test' ], [ 'slug', v ] ] : [ [ 'label', 'Hostile test' ], [ 'editing', 'hostile-test' ], [ field, v ] ];
				const r = await adminPost( admin, action, fields, { nonceAction: action } );
				ok( sane( r ), `${ field } = ${ name }: status ${ r.status }` );
				const rows = option( 'ofwc_statuses' );
				const bad = statusRowsValid( rows );
				ok( bad === '', `${ field } = ${ name }: ${ bad }` );
				if ( name === 'one_mb' ) {
					const size = JSON.stringify( rows ).length;
					ok( size < 200000, `${ field } = 1 MB: the autoloaded option ofwc_statuses grew to ${ size } bytes (no length limit)` );
					const fixed = [ [ 'label', 'Hostile test' ], [ 'editing', 'hostile-test' ] ];
					await adminPost( admin, action, fixed, { nonceAction: action } );
				}
			}
		}
		// the edit link of an unknown status is rebuilt from the posted value: the redirect must stay short
		const long = await adminPost( admin, action, [ [ 'label', 'x' ], [ 'editing', 'a'.repeat( 100000 ) ] ], { nonceAction: action } );
		ok( long.status === 302 && long.location.length < 2000, `save with a 100 KB "editing" value: status ${ long.status }, Location of ${ long.location.length } characters` );
		// flags as arrays, position hostile
		for ( const body of [ 'paid[]=1', 'pdf_enabled[a]=1', 'position[]=3', 'position=1e400', 'position=-99999999999999999999', 'color[]=%23fff', 'icon[]=x', 'editing[]=x', 'label[]=a&label[]=b' ] ) {
			const nonce = sessionNonce( admin, action );
			const r = await http( 'POST', '/wp-admin/admin-post.php', { cookie: admin.cookie, form: `action=${ action }&_wpnonce=${ nonce }&label=Arr&slug=arr-test&${ body }` } );
			ok( sane( r ), `${ body }: status ${ r.status }` );
		}
		for ( const view of [ '', '&view=add', '&view=edit&status=hostile-test', '&view=delete&status=hostile-test' ] ) {
			const page = await http( 'GET', `/wp-admin/admin.php?page=ofwc&tab=statuses${ view }`, { cookie: admin.cookie } );
			eq( page.status, 200, `statuses page ${ view }` );
			const problems = htmlProblems( page.text );
			ok( problems.length === 0, `statuses page ${ view }: ${ problems.join( ', ' ) }` );
		}
		logClean( mark, '1.3' );
	}, 'live (1.3)' );

	await scenario( '1.4', 'Settings forms: transitions, documents and delete', 'Hostile rules and values are cleaned or refused; saved rules only reference known statuses and roles; delete with hostile slug or target never fails hard', async () => {
		baseline( { hpos } );
		const mark = logMark();
		const rulesBefore = JSON.stringify( option( 'ofwc_transitions' ) );
		const attempts = [
			'rules[0][from]=*&rules[0][to]=approved&rules[0][roles][]=administrator&rules[0][roles][]=nonexistent',
			'rules[0][from][]=x&rules[0][to][]=y',
			'rules[0]=string&rules[1]=other',
			'rules[0][from]=%3Cscript%3E&rules[0][to]=approved&rules[0][roles]=administrator',
			'rules[0][from]=approved&rules[0][to]=approved&rules[0][roles][]=shop_manager',
			'rules[0][from]=wc-pending&rules[0][to]=wc-approved&rules[0][roles][]=customer&rules[0][require_comment][]=1',
			'rules=notanarray',
			'rules[99999999999][from]=*&rules[99999999999][to]=approved',
		];
		for ( const body of attempts ) {
			const nonce = sessionNonce( admin, 'ofwc_save_transitions' );
			const r = await http( 'POST', '/wp-admin/admin-post.php', { cookie: admin.cookie, form: `action=ofwc_save_transitions&_wpnonce=${ nonce }&${ body }` } );
			ok( sane( r ), `rules ${ body.slice( 0, 60 ) }: status ${ r.status }` );
			const rules = option( 'ofwc_transitions' );
			ok( Array.isArray( rules ), 'transitions option stays a list' );
			for ( const rule of Array.isArray( rules ) ? rules : [] ) {
				ok( typeof rule.from === 'string' && typeof rule.to === 'string' && Array.isArray( rule.roles ) && rule.from !== rule.to, `malformed rule saved: ${ JSON.stringify( rule ) }` );
			}
		}
		for ( const [ name, v ] of Object.entries( PAYLOADS ) ) {
			const r = await adminPost( admin, 'ofwc_save_documents', [ [ 'store_details', v ], [ 'logo_id', v ] ], { nonceAction: 'ofwc_save_documents' } );
			ok( sane( r ), `documents ${ name }: status ${ r.status }` );
		}
		const settings = option( 'ofwc_settings' );
		ok( settings && typeof settings === 'object', 'ofwc_settings stays an array' );
		ok( ( settings.store_details || '' ).length <= 20000, `store details kept at ${ ( settings.store_details || '' ).length } characters` );
		for ( const [ name, v ] of Object.entries( PAYLOADS ) ) {
			const r = await adminPost( admin, 'ofwc_delete_status', [ [ 'status', v ], [ 'target', v === PAYLOADS.one_mb ? '' : v ] ], { nonceAction: 'ofwc_delete_status' } );
			ok( sane( r ), `delete with ${ name }: status ${ r.status } (0 = connection dropped: the error redirect repeats the whole value in the Location header)` );
		}
		const longDelete = await adminPost( admin, 'ofwc_delete_status', [ [ 'status', 'a'.repeat( 100000 ) ] ], { nonceAction: 'ofwc_delete_status' } );
		ok( longDelete.status === 302 && longDelete.location.length < 2000, `delete with a 100 KB status: status ${ longDelete.status }, Location of ${ longDelete.location.length } characters` );
		ok( JSON.stringify( option( 'ofwc_statuses' ) ).includes( 'awaiting-approval' ), 'a hostile delete must not remove the real statuses' );
		void rulesBefore;
		logClean( mark, '1.4' );
	}, 'live (1.4)' );

	await scenario( '1.5', 'Settings and import/export endpoints: roles and nonces', 'Guest 400; subscriber, editor, customer and shop-manager-without-capability 403 or refused; bad nonces 403; no change to plugin rows', async () => {
		baseline( { hpos } );
		const mark = logMark();
		const endpoints = [
			{ action: 'ofwc_save_status', nonceAction: 'ofwc_save_status', nonceField: '_wpnonce', fields: [ [ 'label', 'Role test' ], [ 'slug', 'role-test' ] ] },
			{ action: 'ofwc_delete_status', nonceAction: 'ofwc_delete_status', nonceField: '_wpnonce', fields: [ [ 'status', 'approved' ] ] },
			{ action: 'ofwc_save_transitions', nonceAction: 'ofwc_save_transitions', nonceField: '_wpnonce', fields: [] },
			{ action: 'ofwc_save_documents', nonceAction: 'ofwc_save_documents', nonceField: '_wpnonce', fields: [ [ 'store_details', 'x' ] ] },
			{ action: 'ofwc_export', nonceAction: 'ofwc_export', nonceField: '_ofwc_nonce', fields: [] },
			{ action: 'ofwc_import', nonceAction: 'ofwc_import', nonceField: '_ofwc_nonce', fields: [ [ 'ofwc_json', '{}' ], [ 'mode', 'replace' ] ] },
			{ action: 'ofwc_deactivate', nonceAction: 'ofwc_deactivate', nonceField: '_ofwc_nonce', fields: [ [ 'choice', 'keep' ] ] },
		];
		const before = pluginRows();
		for ( const e of endpoints ) {
			const o = { nonceAction: e.nonceAction, nonceField: e.nonceField };
			eq( ( await adminPost( null, e.action, e.fields, { nonce: 'abcdef1234', nonceField: e.nonceField } ) ).status, 400, `${ e.action }: guest` );
			for ( const [ name, s ] of Object.entries( { sub, editor, custa } ) ) {
				eq( ( await adminPost( s, e.action, e.fields, o ) ).status, 403, `${ e.action }: ${ name }` );
			}
			const variants = { missing: null, empty: '', forged: '0123456789', too_long: 'a'.repeat( 5000 ), other_action: nonceFor( admin, 'ofwc_pdf' ), expired: nonceFor( admin, e.nonceAction, 9 ), other_user: nonceFor( sub, e.nonceAction ) };
			for ( const [ name, v ] of Object.entries( variants ) ) {
				eq( ( await adminPost( admin, e.action, e.fields, { nonce: v, nonceField: e.nonceField } ) ).status, 403, `${ e.action }: administrator with ${ name } nonce` );
			}
			const arr = await http( 'POST', '/wp-admin/admin-post.php', { cookie: admin.cookie, form: `action=${ e.action }&${ e.nonceField }[]=x` } );
			eq( arr.status, 403, `${ e.action }: array nonce` );
		}
		// shop manager has manage_woocommerce: allowed to configure, but not to deactivate the plugin
		eq( ( await adminPost( shop, 'ofwc_deactivate', [ [ 'choice', 'keep' ] ], { nonceAction: 'ofwc_deactivate', nonceField: '_ofwc_nonce' } ) ).status, 403, 'shop manager must not deactivate the plugin' );
		eq( pluginRows(), before, 'refused requests changed plugin rows' );
		logClean( mark, '1.5' );
	}, 'live (1.5)' );

	await scenario( '1.6', 'Document download: who may fetch which PDF', 'Guest 400; subscriber, editor 403; customer only their own order and only statuses shown in the account; bad nonces 403; unknown status or order 404; shop manager and administrator 200', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		resetOrder( id );
		// move through the statuses as the administrator so the documents exist
		for ( const to of [ 'awaiting-approval', 'approved', 'in-production' ] ) {
			const r = await transition( admin, id, to, 'step' );
			eq( r.status, 302, `setup: ${ to }` );
		}
		const pdf = ( session, slug, nonceOverride, orderId = id ) => adminPost( session, 'ofwc_pdf', [ [ 'order_id', String( orderId ) ], [ 'status', slug ] ], { nonceAction: `ofwc_pdf_${ orderId }_${ slug }`, nonce: nonceOverride, method: 'GET' } );
		eq( ( await pdf( null, 'approved', 'abcdef1234' ) ).status, 400, 'guest' );
		for ( const [ name, s ] of Object.entries( { sub, editor, custb } ) ) {
			eq( ( await pdf( s, 'approved' ) ).status, 403, `${ name } with a valid own nonce for approved` );
		}
		for ( const slug of [ 'awaiting-approval', 'approved' ] ) {
			const r = await pdf( custa, slug );
			eq( r.status, 200, `customer A downloads ${ slug }` );
			ok( r.text.startsWith( '%PDF' ), `${ slug } is a PDF` );
		}
		eq( ( await pdf( custa, 'in-production' ) ).status, 403, 'customer A, status hidden from the account' );
		for ( const slug of [ 'awaiting-approval', 'approved', 'in-production' ] ) {
			eq( ( await pdf( shop, slug ) ).status, 200, `shop manager ${ slug }` );
			eq( ( await pdf( admin, slug ) ).status, 200, `administrator ${ slug }` );
		}
		const variants = { missing: null, empty: '', forged: '0123456789', expired: nonceFor( admin, `ofwc_pdf_${ id }_approved`, 9 ), other_status: nonceFor( admin, `ofwc_pdf_${ id }_awaiting-approval` ), other_user: nonceFor( custb, `ofwc_pdf_${ id }_approved` ) };
		for ( const [ name, v ] of Object.entries( variants ) ) {
			eq( ( await pdf( admin, 'approved', v ) ).status, 403, `administrator with ${ name } nonce` );
		}
		const arr = await http( 'GET', `/wp-admin/admin-post.php?action=ofwc_pdf&order_id=${ id }&status=approved&_wpnonce[]=x`, { cookie: admin.cookie } );
		eq( arr.status, 403, 'array nonce' );
		for ( const bad of [ 'pending', 'does-not-exist', 'wc-approved', '', '../x', '%00', 'A'.repeat( 3000 ) ] ) {
			const r = await pdf( admin, bad );
			oneOf( r.status, [ 403, 404 ], `unknown status ${ bad.slice( 0, 20 ) }` );
		}
		for ( const bad of [ 0, 99999999 ] ) {
			oneOf( ( await pdf( admin, 'approved', undefined, bad ) ).status, [ 404 ], `missing order ${ bad }` );
		}
		logClean( mark, '1.6' );
	}, 'live (1.6)' );

	await scenario( '1.7', 'Admin pages with hostile query strings and odd roles', '200 for administrator and shop manager, 403 for subscriber, editor and customer, redirect for guests; nothing reflected; no PHP errors', async () => {
		baseline( { hpos } );
		const mark = logMark();
		const paths = [ '/wp-admin/admin.php?page=ofwc' ];
		for ( const [ name, v ] of Object.entries( PAYLOADS ) ) {
			if ( name === 'one_mb' ) {
				continue;
			}
			const q = encodeURIComponent( v );
			paths.push( `/wp-admin/admin.php?page=ofwc&tab=${ q }`, `/wp-admin/admin.php?page=ofwc&tab=statuses&view=${ q }&status=${ q }`, `/wp-admin/admin.php?page=ofwc&tab=transitions&x=${ q }` );
		}
		for ( const p of paths ) {
			const r = await http( 'GET', p, { cookie: admin.cookie } );
			eq( r.status, 200, `administrator ${ p.slice( 0, 90 ) }` );
			const problems = htmlProblems( r.text );
			ok( problems.length === 0, `${ p.slice( 0, 90 ) }: ${ problems.join( ', ' ) }` );
			ok( ! /<script>alert\(1\)<\/script>|onerror=alert/.test( r.text ), `${ p.slice( 0, 90 ) } reflects markup` );
		}
		oneOf( ( await http( 'GET', '/wp-admin/admin.php?page=ofwc', { cookie: shop.cookie } ) ).status, [ 200, 403 ], 'shop manager (0.1.0 allowed it; configuration is administrator-only from the next release)' );
		for ( const [ name, s ] of Object.entries( { sub, editor, custa } ) ) {
			eq( ( await http( 'GET', '/wp-admin/admin.php?page=ofwc', { cookie: s.cookie } ) ).status, 403, name );
		}
		const g = await http( 'GET', '/wp-admin/admin.php?page=ofwc' );
		eq( g.status, 302, 'guest' );
		const dp = await http( 'GET', '/wp-admin/admin.php?page=ofwc-deactivate', { cookie: shop.cookie } );
		eq( dp.status, 403, 'deactivation screen for shop manager' );
		logClean( mark, '1.7' );
	}, 'live (1.7)' );

	await scenario( '1.8', 'Site data as an XSS source', 'Hostile order, customer and product text is inert in the order screen, the account page, the documents box, the history and every email', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		captureOn();
		const evil = '<script>alert(1)</script>"><img src=x onerror=alert(1)>';
		wp( [ 'eval', `
			$o = wc_get_order( ${ id } );
			$o->set_billing_first_name( ${ JSON.stringify( evil ) } );
			$o->set_billing_last_name( 'O\\'Brien & Sons' );
			$o->set_billing_company( ${ JSON.stringify( evil ) } );
			$o->set_billing_address_1( ${ JSON.stringify( evil ) } );
			$o->set_shipping_address_1( ${ JSON.stringify( evil ) } );
			foreach ( $o->get_items() as $item ) { $item->set_name( ${ JSON.stringify( evil ) } ); $item->save(); break; }
			$o->save();
		` ] );
		resetOrder( id );
		for ( const to of [ 'awaiting-approval', 'approved', 'in-production' ] ) {
			const r = await transition( admin, id, to, evil );
			eq( r.status, 302, `step ${ to }` );
		}
		const pages = [
			[ 'order screen', editUrl( id, hpos ), admin ],
			[ 'orders list', listUrl( hpos ), admin ],
			[ 'account order page', `/my-account/view-order/${ id }/`, custa ],
		];
		for ( const [ label, path, s ] of pages ) {
			const r = await http( 'GET', path, { cookie: s.cookie } );
			const problems = liveMarkup( r.text );
			ok( problems.length === 0, `${ label }: ${ problems.join( ', ' ) }` );
		}
		const mails = takeMails();
		ok( mails.length >= 4, `emails captured: ${ mails.length }` );
		for ( const m of mails ) {
			const problems = m.content_type === 'text/plain' ? [] : liveMarkup( m.body );
			ok( problems.length === 0, `email "${ m.subject.slice( 0, 40 ) }": ${ problems.join( ', ' ) }` );
			ok( ! /[\r\n]/.test( m.subject ), 'subject holds a line break' );
		}
		logClean( mark, '1.8' );
		captureOff();
	}, 'live (1.8)' );
}
