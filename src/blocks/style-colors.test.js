import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

const blocksDir = fileURLToPath( new URL( '.', import.meta.url ) );

const stylesheets = readdirSync( blocksDir )
	.map( ( name ) => join( blocksDir, name ) )
	.filter( ( dir ) => statSync( dir ).isDirectory() )
	.flatMap( ( dir ) =>
		readdirSync( dir )
			.filter( ( name ) => name.endsWith( '.css' ) )
			.map( ( name ) => join( dir, name ) )
	);

describe( 'ブロックの CSS の色', () => {
	it( 'CSS を読めている', () => {
		expect( stylesheets.length ).toBeGreaterThan( 0 );
	} );

	// accent はテーマによって飾り用の淡い色（Twenty Twenty-Four は #cfcabe）で、文字にも塗りにも使えない。
	it.each( stylesheets )( '%s は preset の accent を参照しない', ( file ) => {
		expect( readFileSync( file, 'utf8' ) ).not.toMatch(
			/--wp--preset--color--accent/
		);
	} );
} );
