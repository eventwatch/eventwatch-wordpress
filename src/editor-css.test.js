// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { EventFields } from './event-panel';
import { emptyRow } from './validate';

const css = readFileSync(
	join( dirname( fileURLToPath( import.meta.url ) ), 'editor.css' ),
	'utf8'
);

// select の枠を描く要素に効く規則のセレクタ。
const backdropSelectors = css
	.replace( /\/\*[\s\S]*?\*\//g, '' )
	.split( '}' )
	.map( ( rule ) => rule.split( '{' )[ 0 ] )
	.flatMap( ( selectors ) => selectors.split( ',' ) )
	.map( ( selector ) => selector.trim() )
	.filter( ( selector ) =>
		selector.endsWith( '.components-input-control__backdrop' )
	);

const isRed = ( backdrop ) =>
	backdropSelectors.some( ( selector ) => backdrop.matches( selector ) );

const renderFields = ( blocked ) =>
	render(
		<EventFields
			fields={ {
				meta: {},
				focusTarget: null,
				clearFocusTarget: vi.fn(),
				setField: vi.fn(),
				rows: [ emptyRow() ],
				rowErrors: [],
				changeRow: vi.fn(),
				addRow: vi.fn(),
				removeRow: vi.fn(),
				venueName: '',
				venueAddress: '',
				url: '',
				note: '',
				venueIncomplete: false,
				urlInvalid: false,
				blocked,
			} }
		/>
	);

const backdropOf = ( id ) =>
	document
		.getElementById( id )
		.closest( '.components-input-control__container' )
		.querySelector( '.components-input-control__backdrop' );

const selects = [ 'eventwatch-field-category', 'eventwatch-field-prefecture' ];

afterEach( cleanup );

describe( 'editor.css の選択欄の枠', () => {
	it( 'select の枠の規則を読めている', () => {
		expect( backdropSelectors.length ).toBeGreaterThan( 0 );
	} );

	it( 'reddens the category and prefecture selects that block sending', () => {
		renderFields( { rejected: [], category: true, prefecture: true } );

		for ( const id of selects ) {
			expect( isRed( backdropOf( id ) ) ).toBe( true );
		}
	} );

	it( 'leaves the selects alone when nothing blocks sending', () => {
		renderFields( { rejected: [], category: false, prefecture: false } );

		for ( const id of selects ) {
			expect( isRed( backdropOf( id ) ) ).toBe( false );
		}
	} );

	// 古い本体（@wordpress/components 28.8.12）の SelectControl は、className を
	// BaseControl ではなく内側の InputBase に付ける。その入れ子を部品のソースのとおりに組む。
	it( 'reddens a select whose className sits on the inner input base', () => {
		document.body.innerHTML = `
			<div class="components-base-control">
				<div class="components-base-control__field">
					<div class="components-input-base components-select-control eventwatch-control--error">
						<div class="components-input-control__container">
							<select class="components-select-control__input"></select>
							<div class="components-input-control__backdrop"></div>
						</div>
					</div>
				</div>
			</div>`;

		expect(
			isRed(
				document.querySelector( '.components-input-control__backdrop' )
			)
		).toBe( true );
	} );
} );
