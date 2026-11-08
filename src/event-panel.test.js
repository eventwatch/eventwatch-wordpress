// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { EventFields } from './event-panel';
import findField from './find-field';
import { emptyRow } from './validate';

const renderFields = ( overrides = {} ) => {
	const fields = {
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
		blocked: { rejected: [], category: false, prefecture: false },
		...overrides,
	};
	render( <EventFields fields={ fields } /> );
	return fields;
};

afterEach( cleanup );

describe( 'EventFields', () => {
	it( 'names every field after its visible label', () => {
		renderFields();

		for ( const name of [
			'Venue name',
			'Venue address',
			'Notes on dates',
			'Separate official page (optional)',
		] ) {
			expect( screen.getByRole( 'textbox', { name } ) ).toBeTruthy();
		}
		for ( const name of [ 'Prefecture', 'EventWatch category' ] ) {
			expect( screen.getByRole( 'combobox', { name } ) ).toBeTruthy();
		}
		expect(
			screen.getByRole( 'checkbox', { name: 'Held online' } )
		).toBeTruthy();
	} );

	it( 'points each label at its field', () => {
		renderFields();

		for ( const [ label, role ] of [
			[ 'Venue address', 'textbox' ],
			[ 'Notes on dates', 'textbox' ],
			[ 'Held online', 'checkbox' ],
		] ) {
			expect(
				screen.getByText( label, { selector: 'label' } ).control
			).toBe( screen.getByRole( role, { name: label } ) );
		}
	} );

	it( 'turns on “Held online” from its label', () => {
		const fields = renderFields();

		fireEvent.click(
			screen.getByText( 'Held online', { selector: 'label' } )
		);

		expect( fields.setField ).toHaveBeenCalledWith(
			'eventwatch_online',
			true
		);
	} );

	it( 'moves focus to a field named by a sync error', () => {
		const fields = renderFields( {
			focusTarget: 'eventwatch-field-schedule-note',
		} );

		expect( document.activeElement ).toBe(
			screen.getByRole( 'textbox', { name: 'Notes on dates' } )
		);
		expect( fields.clearFocusTarget ).toHaveBeenCalled();
	} );
} );

describe( 'findField', () => {
	it( 'finds a field by its id or by its data attribute', () => {
		renderFields();

		expect( findField( 'eventwatch-field-venue-name' ) ).toBe(
			screen.getByRole( 'textbox', { name: 'Venue name' } )
		);
		expect( findField( 'eventwatch-field-venue-address' ) ).toBe(
			screen.getByRole( 'textbox', { name: 'Venue address' } )
		);
		expect( findField( 'eventwatch-field-unknown' ) ).toBeNull();
	} );
} );
