// Group 3: status changes by role and from every screen, concurrency, emails.
import fs from 'node:fs';
import {
	adminPost, baseline, captureOff, captureOn, editUrl, eq, formString, http, listUrl, logClean, logMark, nonceFor, note, ok, oneOf, option, orderFacts, php,
	resetOrder, saveOrderForm, scenario, sessionNonce, setOption, sleep, takeMails, wp,
} from './lib.mjs';

const T = ( id ) => `ofwc_transition_${ id }`;

function transition( session, id, to, comment ) {
	return adminPost( session, 'ofwc_transition', [ [ 'order_id', String( id ) ], [ 'ofwc_to', to ], [ 'ofwc_comment', comment ] ], { nonceAction: T( id ), nonceField: 'ofwc_nonce' } );
}

// Sets the baseline rules plus one extra order so bulk actions have something to chew on.
function extraOrders( count ) {
	const out = php( `
		$ids = array();
		for ( $i = 0; $i < ${ count }; $i++ ) {
			$o = wc_create_order( array( 'status' => 'pending' ) );
			$o->set_billing_email( 'bulk' . $i . '@example.test' );
			$o->update_meta_data( '_snap_order', '1' );
			$o->save();
			$ids[] = $o->get_id();
		}
		echo wp_json_encode( $ids );
	` );
	return JSON.parse( out.split( '\n' ).pop() );
}

export async function s3( S, ctx ) {
	const hpos = ctx.hpos;
	const { admin, shop, sub, custa } = S;

	await scenario( '3.1', 'Status change from the order screen dropdown (full form save)', 'Allowed changes go through, forbidden ones are reverted with a notice, a missing required comment is refused, the same status is a no-op; roles without the capability cannot save', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		resetOrder( id );
		// shop manager: pending -> awaiting-approval is allowed, pending -> approved is not
		let r = await saveOrderForm( shop, id, hpos, { order_status: 'wc-approved', ofwc_comment: 'trying' } );
		oneOf( r.status, [ 200, 302 ], 'shop manager forbidden change answers normally' );
		eq( orderFacts( id ).status, 'pending', 'forbidden dropdown change must be reverted' );
		r = await saveOrderForm( shop, id, hpos, { order_status: 'wc-awaiting-approval', ofwc_comment: '' } );
		eq( orderFacts( id ).status, 'awaiting-approval', 'allowed dropdown change' );
		// awaiting-approval -> approved needs a comment (administrators included, because comment rules apply to everybody)
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-approved', ofwc_comment: '' } );
		eq( orderFacts( id ).status, 'awaiting-approval', 'required comment missing on the order screen' );
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-approved', ofwc_comment: '   ' } );
		eq( orderFacts( id ).status, 'awaiting-approval', 'required comment made of spaces' );
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-approved', ofwc_comment: ' ' } );
		eq( orderFacts( id ).status, 'awaiting-approval', 'required comment made of a non-breaking space' );
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-approved', ofwc_comment: '​' } );
		eq( orderFacts( id ).status, 'awaiting-approval', 'required comment made of a zero-width space' );
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-approved', ofwc_comment: 'Approved <b>by</b> the form' } );
		eq( orderFacts( id ).status, 'approved', 'allowed change with a comment' );
		const h = orderFacts( id ).history;
		eq( h.length, 2, 'history rows after two changes' );
		ok( ! /<b>/.test( ( h[ 1 ] || {} ).comment || '' ), 'comment markup must not be kept in the history' );
		// the same status again: nothing new in the history
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-approved', ofwc_comment: 'again' } );
		eq( orderFacts( id ).history.length, 2, 'saving the same status adds no history row' );
		// nonexistent status posted by hand
		r = await saveOrderForm( admin, id, hpos, { order_status: 'wc-does-not-exist', ofwc_comment: 'x' } );
		ok( r.status > 0 && r.status < 500, `unknown status posted: ${ r.status }` );
		eq( orderFacts( id ).status, 'approved', 'unknown status must not be stored' );
		// a role without edit_shop_orders cannot save the order at all
		for ( const [ name, s ] of Object.entries( { sub, custa } ) ) {
			const x = await saveOrderForm( s, id, hpos, { order_status: 'wc-pending' } );
			ok( x.status === 0 || x.status === 403 || x.status === 302 || x.status === 200, `${ name }: ${ x.status }` );
			eq( orderFacts( id ).status, 'approved', `${ name } must not change the status` );
		}
		logClean( mark, '3.1' );
	}, 'live (3.1)' );

	await scenario( '3.2', 'Bulk actions on the orders list', 'Only allowed orders move, the others are skipped with a count; a forged nonce, an unknown action, arrays and 300 ids never fail hard', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const more = extraOrders( 4 );
		const all = [ id, ...more ];
		const mark = logMark();
		captureOn();
		for ( const o of all ) {
			resetOrder( o );
		}
		const list = await http( 'GET', listUrl( hpos ), { cookie: shop.cookie } );
		const bulkNonce = ( list.text.match( /name="_wpnonce" value="([0-9a-f]{10})"/ ) || [] )[ 1 ];
		ok( !! bulkNonce, 'the list page has a bulk nonce' );
		const post = ( session, action, idsList, nonce ) => {
			const pairs = [ [ 'action', action ], [ '_wpnonce', nonce ] ];
			for ( const i of idsList ) {
				pairs.push( [ hpos ? 'id[]' : 'post[]', String( i ) ] );
			}
			const path = hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order';
			return http( 'POST', path, { cookie: session.cookie, form: formString( pairs ) } );
		};
		let r = await post( shop, 'ofwc_to_approved', all, bulkNonce );
		ok( r.status > 0 && r.status < 500, `shop manager bulk to approved: ${ r.status }` );
		for ( const o of all ) {
			eq( orderFacts( o ).status, 'pending', `order ${ o } must stay pending (pending to approved is not a rule)` );
		}
		r = await post( shop, 'ofwc_to_awaiting-approval', all, bulkNonce );
		ok( r.status > 0 && r.status < 500, `shop manager bulk to awaiting-approval: ${ r.status }` );
		for ( const o of all ) {
			eq( orderFacts( o ).status, 'awaiting-approval', `order ${ o } moves to awaiting-approval` );
		}
		eq( takeMails().length, all.length * 2, 'two emails per moved order' );
		// moves needing a comment are skipped in bulk
		r = await post( admin, 'ofwc_to_approved', all, ( ( await http( 'GET', listUrl( hpos ), { cookie: admin.cookie } ) ).text.match( /name="_wpnonce" value="([0-9a-f]{10})"/ ) || [] )[ 1 ] );
		for ( const o of all ) {
			eq( orderFacts( o ).status, 'awaiting-approval', `order ${ o }: a comment-required change must be skipped in bulk` );
		}
		// forged nonce, unknown action, hostile ids
		for ( const [ label, action, nonce, list2 ] of [
			[ 'forged nonce', 'ofwc_to_awaiting-approval', '0123456789', all ],
			[ 'unknown status', 'ofwc_to_does-not-exist', bulkNonce, all ],
			[ 'empty status', 'ofwc_to_', bulkNonce, all ],
			[ 'hostile status', 'ofwc_to_<script>', bulkNonce, all ],
			[ 'hostile ids', 'ofwc_to_awaiting-approval', bulkNonce, [ '-1', '0', '1.5', 'abc', '99999999', '%00' ] ],
			[ '300 missing ids', 'ofwc_to_awaiting-approval', bulkNonce, Array.from( { length: 300 }, ( _, i ) => 70000 + i ) ],
		] ) {
			const x = await post( shop, action, list2, nonce );
			ok( x.status > 0 && x.status < 500 && ! /Fatal error|Uncaught/.test( x.text ), `bulk ${ label }: ${ x.status }` );
		}
		// subscribers and customers
		for ( const [ name, s ] of Object.entries( { sub, custa } ) ) {
			const x = await post( s, 'ofwc_to_awaiting-approval', [ id ], bulkNonce );
			ok( x.status > 0, `${ name } bulk: ${ x.status } (the refusal page itself comes from WooCommerce)` );
		}
		eq( orderFacts( id ).status, 'awaiting-approval', 'order unchanged after the hostile bulk calls' );
		logClean( mark, '3.2' );
		captureOff();
	}, 'live (3.2)' );

	await scenario( '3.3', 'Two administrators change the same order at the same time', 'The status, the history and the emails agree with each other: each change appears once and the history reads as one chain', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		captureOn();
		let duplicated = 0;
		let broken = 0;
		const rounds = 6;
		for ( let round = 0; round < rounds; round++ ) {
			resetOrder( id );
			takeMails();
			// six simultaneous requests: the same change from every tab
			const reqs = Array.from( { length: 6 }, () => transition( admin, id, 'awaiting-approval', 'parallel' ) );
			await Promise.all( reqs );
			const facts = orderFacts( id );
			const rows = facts.history.filter( ( e ) => e.to === 'awaiting-approval' );
			const mails = takeMails();
			if ( rows.length > 1 || mails.length > 2 ) {
				duplicated++;
				note( `round ${ round + 1 }: ${ rows.length } history rows and ${ mails.length } emails for one change` );
			}
			const chain = facts.history.every( ( e, i, arr ) => i === 0 || arr[ i - 1 ].to === e.from );
			if ( ! chain || facts.status !== 'awaiting-approval' ) {
				broken++;
			}
		}
		ok( duplicated === 0, `${ duplicated } of ${ rounds } rounds recorded the same change more than once` );
		ok( broken === 0, `${ broken } of ${ rounds } rounds left a history that does not chain` );
		// two different targets at once
		let mixed = 0;
		for ( let round = 0; round < rounds; round++ ) {
			resetOrder( id, 'awaiting-approval' );
			takeMails();
			await Promise.all( [
				transition( admin, id, 'approved', 'a' ),
				transition( admin, id, 'in-production', 'b' ),
				transition( shop, id, 'in-production', 'c' ),
			] );
			const facts = orderFacts( id );
			const last = facts.history[ facts.history.length - 1 ] || {};
			if ( last.to !== facts.status || ! facts.history.every( ( e, i, arr ) => i === 0 || arr[ i - 1 ].to === e.from ) ) {
				mixed++;
			}
			takeMails();
		}
		ok( mixed === 0, `${ mixed } of ${ rounds } rounds ended with a status that disagrees with the last history row, or a history that does not chain` );
		logClean( mark, '3.3' );
		captureOff();
	}, 'live (3.3)' );

	await scenario( '3.4', 'The comment of a status change', 'A comment is kept short, shown as plain text, and a huge one never destroys the history', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		resetOrder( id );
		for ( const to of [ 'awaiting-approval', 'approved' ] ) {
			await transition( admin, id, to, `step ${ to }` );
		}
		const before = orderFacts( id ).history.length;
		eq( before, 2, 'two history rows before the large comment' );
		for ( const len of [ 5000, 70000, 1048576 ] ) {
			await transition( admin, id, 'in-production', 'B'.repeat( len ) );
			const facts = orderFacts( id );
			ok( facts.history.length >= 3, `a ${ len } character comment left ${ facts.history.length } history rows (the earlier rows were lost)` );
			ok( facts.history.every( ( e ) => typeof e.comment === 'string' && e.comment.length <= 10000 ), `a ${ len } character comment was stored in full` );
			await transition( admin, id, 'approved', 'back' );
			resetOrder( id );
			for ( const to of [ 'awaiting-approval', 'approved' ] ) {
				await transition( admin, id, to, `step ${ to }` );
			}
		}
		// many changes: the history is one meta value, so its size matters
		resetOrder( id );
		for ( let i = 0; i < 60; i++ ) {
			await transition( admin, id, i % 2 === 0 ? 'awaiting-approval' : 'approved', `change ${ i } ${ 'x'.repeat( 400 ) }` );
		}
		const facts = orderFacts( id );
		ok( facts.history.length >= 55, `after 60 changes of 400 characters the history holds ${ facts.history.length } rows (the stored meta value outgrew its column)` );
		logClean( mark, '3.4' );
	}, 'live (3.4)' );

	await scenario( '3.5', 'Emails per status: recipients, formats, injection, attachments', 'The right emails go to the right people; markup and line breaks in names and comments do not reach headers or HTML; plain text shows real characters; the PDF is attached only when asked and the temporary file is removed', async () => {
		const ids = baseline( { hpos } );
		const id = ids.order_id;
		const mark = logMark();
		captureOn();
		// a nasty customer name
		php( `$o = wc_get_order( ${ id } ); $o->set_billing_first_name( "Mal\\r\\nBcc: evil@example.test" ); $o->set_billing_last_name( "O'Brien & Sons <b>Ltd</b>" ); $o->save();` );
		resetOrder( id );
		takeMails();
		await transition( admin, id, 'awaiting-approval', 'first' );
		let mails = takeMails();
		eq( mails.length, 2, 'customer and administrator email for awaiting-approval' );
		const toCustomer = mails.find( ( m ) => m.to.includes( 'alice.snapshot@example.test' ) );
		const toAdmin = mails.find( ( m ) => m.to.includes( 'admin@example.test' ) );
		ok( !! toCustomer && !! toAdmin, 'recipients are the billing email and the admin email' );
		for ( const m of mails ) {
			ok( m.bcc.length === 0 && m.cc.length === 0, `no injected Bcc/Cc (${ JSON.stringify( [ m.bcc, m.cc ] ) })` );
			ok( ! /[\r\n]/.test( m.subject ), 'subject is one line' );
			ok( ! m.custom.some( ( h ) => /bcc/i.test( h[ 0 ] ) ), 'no Bcc header' );
		}
		ok( toCustomer && toCustomer.attachments.length === 1 && /\.pdf$/.test( toCustomer.attachments[ 0 ].name ), 'the PDF is attached when the status asks for it' );
		const left = fs.existsSync( `${ process.env.DESTR_SITE || '/cache/site' }/wp-content/uploads/ofwc-tmp` ) ? sh_ls( `${ process.env.DESTR_SITE || '/cache/site' }/wp-content/uploads/ofwc-tmp` ) : [];
		ok( left.length === 0, `temporary PDF folders left behind: ${ left.join( ', ' ) }` );
		await transition( admin, id, 'approved', 'second' );
		mails = takeMails();
		eq( mails.length, 2 - 1 + 0, 'approved: only the customer is mailed' );
		const approved = mails[ 0 ];
		ok( approved && approved.attachments.length === 0, 'no attachment when the status does not attach the PDF' );
		await transition( admin, id, 'in-production', 'third' );
		mails = takeMails();
		const plain = mails.find( ( m ) => m.content_type === 'text/plain' && m.to.includes( 'alice.snapshot@example.test' ) );
		ok( !! plain, 'a plain text email for the customer' );
		if ( plain ) {
			ok( ! /&#0?39;|&amp;|&lt;|&quot;/.test( plain.body ), `plain text email shows HTML entities instead of characters: ${ ( plain.body.match( /.{0,30}(&#0?39;|&amp;|&lt;|&quot;).{0,20}/ ) || [ '' ] )[ 0 ] }` );
			ok( ! /<b>|<script/i.test( plain.body.split( 'BILLING ADDRESS' )[ 0 ] ), 'no markup in the plain text intro' );
		}
		logClean( mark, '3.5' );
		captureOff();
	}, 'live (3.5)' );
}

function sh_ls( dir ) {
	try {
		return fs.readdirSync( dir ).filter( ( f ) => ! /^(index\.php|\.htaccess)$/.test( f ) );
	} catch {
		return [];
	}
}
