// Destructive test runner. Usage (inside the clone container): node run.mjs [1] [2] [3] [4] [5] [6]
// HPOS=off runs the groups against the legacy posts store (default: on).
import fs from 'node:fs';
import { sessions, table } from './lib.mjs';

const wanted = process.argv.slice( 2 );
const want = ( n ) => wanted.length === 0 || wanted.includes( String( n ) );
const ctx = { hpos: process.env.HPOS !== 'off' };

const S = await sessions();
for ( const n of [ 1, 2, 3, 4, 5, 6 ] ) {
	if ( ! want( n ) ) {
		continue;
	}
	let m;
	try {
		m = await import( `./s${ n }.mjs` );
	} catch ( e ) {
		if ( e.code === 'ERR_MODULE_NOT_FOUND' && String( e.message ).includes( `s${ n }.mjs` ) ) {
			continue;
		}
		throw e;
	}
	await m[ `s${ n }` ]( S, ctx );
}

const failed = table.filter( ( t ) => t.failures.length );
fs.mkdirSync( '/cache/tmp', { recursive: true } );
fs.writeFileSync( `/cache/tmp/destructive-results${ ctx.hpos ? '' : '-legacy' }.json`, JSON.stringify( table, null, 2 ) );
console.log( `\n${ table.length } scenarios, ${ failed.length } failed` );
for ( const t of table ) {
	console.log( `${ t.failures.length ? 'FAIL' : 'PASS' }  ${ t.id }  ${ t.name }  (${ t.checks } checks)` );
}
process.exit( failed.length ? 1 : 0 );
