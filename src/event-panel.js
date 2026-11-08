import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import {
	ERROR_END_BEFORE_START,
	ERROR_END_INCOMPLETE,
	ERROR_INVALID_DATE,
	ERROR_OVERLAP,
	ERROR_START_INCOMPLETE,
	MAX_ADDRESS,
	MAX_NOTE,
	MAX_RANGES,
	MAX_URL,
	MAX_VENUE_NAME,
	countCharacters,
	emptyRow,
	evaluateRows,
	isValidUrl,
	isVenueIncomplete,
	rowsFromOccurrences,
} from './validate';
import {
	EVENT_PANEL_ID as PANEL_ID,
	EVENT_PANEL_NAME as PANEL_NAME,
	PLUGIN_NAME,
	SYNC_BLOCKS_EVENT,
} from './panel-names';
import findField from './find-field';
import useOpenPanelOnce from './use-open-panel-once';

export { PLUGIN_NAME };

const DOCUMENT_SIDEBAR = 'edit-post/document';
const NOTICE_ID = 'eventwatch-event-details-paused';
// @wordpress/interface は本体が単独のスクリプトとして配らないので、ストアを名前で引く。
const INTERFACE_STORE = 'core/interface';
const POST_TYPE = 'eventwatch_event';
const LOCK_NAME = 'eventwatch-event-details';
const PREFERENCE_OPENED = 'eventDetailsOpenedOnce';

const OCCURRENCES = 'eventwatch_occurrences';

const { categories = [], prefectures = [] } = window.eventwatchEditor ?? {};

const fieldId = ( index, field ) =>
	`eventwatch-occurrence-${ index }-${ field }`;

const rowErrorText = ( code ) =>
	( {
		[ ERROR_START_INCOMPLETE ]: __(
			'Enter both the start date and the start time.',
			'eventwatch'
		),
		[ ERROR_END_INCOMPLETE ]: __(
			'Enter both the end date and the end time, or leave both empty.',
			'eventwatch'
		),
		[ ERROR_INVALID_DATE ]: __( 'This date does not exist.', 'eventwatch' ),
		[ ERROR_END_BEFORE_START ]: __(
			'The end can’t be before the start.',
			'eventwatch'
		),
		[ ERROR_OVERLAP ]: __(
			'This date overlaps or touches another date. Leave a gap between them.',
			'eventwatch'
		),
	} )[ code ];

const FieldError = ( { id, children } ) => (
	<span id={ id } className="eventwatch-field-error">
		<svg
			width="16"
			height="16"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.8"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
		>
			<path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
		</svg>
		<span>{ children }</span>
	</span>
);

const OccurrenceRow = ( {
	index,
	row,
	error,
	canRemove,
	onChange,
	onRemove,
} ) => {
	const number = index + 1;
	const errorId = error ? fieldId( index, 'error' ) : undefined;
	const describe = {
		'aria-describedby': errorId,
		'aria-invalid': error ? true : undefined,
	};
	const input = ( field, type, label ) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			id={ fieldId( index, field ) }
			type={ type }
			label={ label }
			hideLabelFromVision
			value={ row[ field ] }
			onChange={ ( value ) => onChange( index, field, value ) }
			{ ...describe }
		/>
	);

	return (
		<div
			className={
				'eventwatch-occurrence' +
				( error ? ' eventwatch-occurrence--error' : '' )
			}
		>
			<div className="eventwatch-occurrence__head">
				<span className="eventwatch-occurrence__title">
					{ sprintf(
						/* translators: %d: the position of the date in the list. */
						__( 'Date %d', 'eventwatch' ),
						number
					) }
				</span>
				{ canRemove && (
					<Button
						id={ fieldId( index, 'remove' ) }
						variant="link"
						isDestructive
						onClick={ () => onRemove( index ) }
						// 読み上げの名前は、見えている文から始める。
						label={ sprintf(
							/* translators: %d: the position of the date in the list. */
							__( 'Remove this date (Date %d)', 'eventwatch' ),
							number
						) }
						showTooltip={ false }
					>
						{ __( 'Remove this date', 'eventwatch' ) }
					</Button>
				) }
			</div>
			<div className="eventwatch-occurrence__grid">
				<span aria-hidden="true">{ __( 'Start', 'eventwatch' ) }</span>
				{ input(
					'startDate',
					'date',
					sprintf(
						/* translators: %d: the position of the date in the list. */
						__( 'Date %d: start date', 'eventwatch' ),
						number
					)
				) }
				{ input(
					'startTime',
					'time',
					sprintf(
						/* translators: %d: the position of the date in the list. */
						__( 'Date %d: start time', 'eventwatch' ),
						number
					)
				) }
				<span aria-hidden="true">{ __( 'End', 'eventwatch' ) }</span>
				{ input(
					'endDate',
					'date',
					sprintf(
						/* translators: %d: the position of the date in the list. */
						__( 'Date %d: end date', 'eventwatch' ),
						number
					)
				) }
				{ input(
					'endTime',
					'time',
					sprintf(
						/* translators: %d: the position of the date in the list. */
						__( 'Date %d: end time', 'eventwatch' ),
						number
					)
				) }
			</div>
			{ error && (
				<FieldError id={ errorId }>
					{ rowErrorText( error ) }
				</FieldError>
			) }
		</div>
	);
};

// 保存の後に送れない理由が返ったときだけ、欄を誤りにする。サイトだけで使う人には必須ではない。
const useSyncBlocks = () => {
	const [ blocked, setBlocked ] = useState( {
		category: false,
		prefecture: false,
		rejected: [],
	} );
	useEffect( () => {
		const listen = ( event ) => setBlocked( event.detail );
		document.addEventListener( SYNC_BLOCKS_EVENT, listen );
		return () => document.removeEventListener( SYNC_BLOCKS_EVENT, listen );
	}, [] );
	return blocked;
};

// パネルを畳むと中身が外れるので、書きかけの組と保存の停止はパネルの外で持つ。
const useEventFields = () => {
	const blocked = useSyncBlocks();
	const [ meta, setMeta ] = useEntityProp( 'postType', POST_TYPE, 'meta' );
	const stored = meta?.[ OCCURRENCES ];

	const [ rows, setRows ] = useState( () => rowsFromOccurrences( stored ) );
	const [ focusTarget, setFocusTarget ] = useState( null );
	const lastWritten = useRef( JSON.stringify( stored ?? [] ) );

	// 保存後の並べ替えや取り消しでメタが変わったら、欄を読み直す。自分が書いた値では読み直さない。
	useEffect( () => {
		const serialized = JSON.stringify( stored ?? [] );
		if ( serialized !== lastWritten.current ) {
			lastWritten.current = serialized;
			setRows( rowsFromOccurrences( stored ) );
		}
	}, [ stored ] );

	const setField = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const { errors: rowErrors } = evaluateRows( rows );

	const updateRows = ( next ) => {
		setRows( next );
		const { errors, occurrences } = evaluateRows( next );
		// 誤りのある間はメタを変えない。保存を止めているので、最後の正しい値が残る。
		if ( Object.keys( errors ).length ) {
			return;
		}
		const serialized = JSON.stringify( occurrences );
		if ( serialized !== lastWritten.current ) {
			lastWritten.current = serialized;
			setField( OCCURRENCES, occurrences );
		}
	};

	const changeRow = ( index, field, value ) =>
		updateRows(
			rows.map( ( row, i ) =>
				i === index ? { ...row, [ field ]: value } : row
			)
		);

	const addRow = () => {
		setFocusTarget( fieldId( rows.length, 'startDate' ) );
		updateRows( [ ...rows, emptyRow() ] );
	};

	// 押したボタンが消えるので、次の組の同じボタンか、無ければ前の組の最初の欄へ移す。
	const removeRow = ( index ) => {
		const next = rows.filter( ( _row, i ) => i !== index );
		if ( next.length === 1 ) {
			setFocusTarget( fieldId( 0, 'startDate' ) );
		} else if ( index < next.length ) {
			setFocusTarget( fieldId( index, 'remove' ) );
		} else {
			setFocusTarget( fieldId( index - 1, 'startDate' ) );
		}
		updateRows( next );
	};

	const venueName = meta?.eventwatch_venue_name ?? '';
	const venueAddress = meta?.eventwatch_venue_address ?? '';
	const url = meta?.eventwatch_url ?? '';
	const note = meta?.eventwatch_schedule_note ?? '';

	const venueIncomplete = isVenueIncomplete( venueName, venueAddress );
	const urlInvalid = ! isValidUrl( url );
	const hasErrors =
		Object.keys( rowErrors ).length > 0 || venueIncomplete || urlInvalid;

	const {
		lockPostSaving,
		unlockPostSaving,
		lockPostAutosaving,
		unlockPostAutosaving,
	} = useDispatch( editorStore );
	useEffect( () => {
		// 保存の停止は自動保存を止めない。別のロックがある。
		if ( hasErrors ) {
			lockPostSaving( LOCK_NAME );
			lockPostAutosaving( LOCK_NAME );
		} else {
			unlockPostSaving( LOCK_NAME );
			unlockPostAutosaving( LOCK_NAME );
		}
		return () => {
			unlockPostSaving( LOCK_NAME );
			unlockPostAutosaving( LOCK_NAME );
		};
	}, [
		hasErrors,
		lockPostSaving,
		unlockPostSaving,
		lockPostAutosaving,
		unlockPostAutosaving,
	] );

	const panelVisible = useSelect( ( select ) => {
		const editor = select( editorStore );
		return (
			editor.isEditorPanelEnabled( PANEL_ID ) &&
			editor.isEditorPanelOpened( PANEL_ID ) &&
			select( INTERFACE_STORE ).getActiveComplementaryArea( 'core' ) ===
				DOCUMENT_SIDEBAR
		);
	}, [] );
	const { createErrorNotice, removeNotice } = useDispatch( noticesStore );

	// 誤りの文はパネルの中にある。パネルが見えない間は、保存が止まっている理由を通知で出す。
	useEffect( () => {
		if ( hasErrors && ! panelVisible ) {
			createErrorNotice(
				__(
					'Saving is paused. Open “Event details” and fix the errors.',
					'eventwatch'
				),
				{ id: NOTICE_ID, isDismissible: false }
			);
		} else {
			removeNotice( NOTICE_ID );
		}
		return () => removeNotice( NOTICE_ID );
	}, [ hasErrors, panelVisible, createErrorNotice, removeNotice ] );

	return {
		meta,
		focusTarget,
		clearFocusTarget: () => setFocusTarget( null ),
		setField,
		rows,
		rowErrors,
		changeRow,
		addRow,
		removeRow,
		venueName,
		venueAddress,
		url,
		note,
		venueIncomplete,
		urlInvalid,
		blocked,
	};
};

const EventFields = ( {
	fields: {
		meta,
		focusTarget,
		clearFocusTarget,
		setField,
		rows,
		rowErrors,
		changeRow,
		addRow,
		removeRow,
		venueName,
		venueAddress,
		url,
		note,
		venueIncomplete,
		urlInvalid,
		blocked,
	},
} ) => {
	// パネルの中身は Slot が遅れて描くので、新しい組の要素を待てるこの中で動かす。
	useEffect( () => {
		if ( focusTarget ) {
			findField( focusTarget )?.focus();
			clearFocusTarget();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- 毎回作る clearFocusTarget では動かさない。
	}, [ focusTarget ] );

	const nameMissing = venueIncomplete && ! venueName.trim();
	const isRejected = ( id ) => blocked.rejected.includes( id );
	const rejectedError = (
		<FieldError>
			{ __(
				'EventWatch didn’t accept this. Check it and update the post.',
				'eventwatch'
			) }
		</FieldError>
	);
	const nameRejected = isRejected( 'eventwatch-field-venue-name' );
	const addressRejected = isRejected( 'eventwatch-field-venue-address' );
	const noteRejected = isRejected( 'eventwatch-field-schedule-note' );
	const urlRejected = isRejected( 'eventwatch-field-url' );
	const categoryMissing = blocked.category && ! meta?.eventwatch_category;
	const prefectureMissing =
		blocked.prefecture &&
		! meta?.eventwatch_prefecture &&
		! meta?.eventwatch_online;
	const addressMissing = venueIncomplete && ! venueAddress.trim();

	return (
		<div className="eventwatch-fields">
			<fieldset className="eventwatch-occurrences">
				<legend>{ __( 'Dates', 'eventwatch' ) }</legend>
				{ rows.map( ( row, index ) => (
					<OccurrenceRow
						key={ index }
						index={ index }
						row={ row }
						error={ rowErrors[ index ] }
						canRemove={ rows.length > 1 }
						onChange={ changeRow }
						onRemove={ removeRow }
					/>
				) ) }
				<Button
					__next40pxDefaultSize
					variant="secondary"
					className="eventwatch-occurrences__add"
					disabled={ rows.length >= MAX_RANGES }
					accessibleWhenDisabled
					onClick={ addRow }
				>
					{ __( 'Add a date', 'eventwatch' ) }
				</Button>
			</fieldset>

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id="eventwatch-field-venue-name"
				className={
					nameMissing || nameRejected
						? 'eventwatch-control--error'
						: undefined
				}
				aria-invalid={ nameMissing || nameRejected ? true : undefined }
				label={ __( 'Venue name', 'eventwatch' ) }
				maxLength={ MAX_VENUE_NAME }
				value={ venueName }
				onChange={ ( value ) =>
					setField( 'eventwatch_venue_name', value )
				}
				help={
					nameMissing ? (
						<FieldError>
							{ __(
								'Enter the venue name too, or clear the address.',
								'eventwatch'
							) }
						</FieldError>
					) : (
						( nameRejected && rejectedError ) || undefined
					)
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				data-eventwatch-field="eventwatch-field-venue-address"
				className={
					addressMissing || addressRejected
						? 'eventwatch-control--error'
						: undefined
				}
				aria-invalid={
					addressMissing || addressRejected ? true : undefined
				}
				label={ __( 'Venue address', 'eventwatch' ) }
				rows={ 2 }
				maxLength={ MAX_ADDRESS }
				value={ venueAddress }
				onChange={ ( value ) =>
					setField( 'eventwatch_venue_address', value )
				}
				help={
					addressMissing ? (
						<FieldError>
							{ __(
								'Enter the venue address too, or clear the venue name.',
								'eventwatch'
							) }
						</FieldError>
					) : (
						( addressRejected && rejectedError ) || undefined
					)
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Held online', 'eventwatch' ) }
				help={ __(
					'When on, “Online” is shown instead of the venue name.',
					'eventwatch'
				) }
				checked={ Boolean( meta?.eventwatch_online ) }
				onChange={ ( value ) => setField( 'eventwatch_online', value ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id="eventwatch-field-prefecture"
				className={
					prefectureMissing ? 'eventwatch-control--error' : undefined
				}
				aria-invalid={ prefectureMissing ? true : undefined }
				label={ __( 'Prefecture', 'eventwatch' ) }
				help={
					prefectureMissing ? (
						<FieldError>
							{ __( 'Choose a prefecture.', 'eventwatch' ) }
						</FieldError>
					) : undefined
				}
				value={ String( meta?.eventwatch_prefecture ?? 0 ) }
				options={ [
					{
						value: '0',
						label: __( 'Select a prefecture', 'eventwatch' ),
					},
					...prefectures,
				] }
				onChange={ ( value ) =>
					setField( 'eventwatch_prefecture', Number( value ) )
				}
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id="eventwatch-field-category"
				className={
					categoryMissing ? 'eventwatch-control--error' : undefined
				}
				aria-invalid={ categoryMissing ? true : undefined }
				label={ __( 'EventWatch category', 'eventwatch' ) }
				help={
					<>
						{ categoryMissing && (
							<FieldError>
								{ __(
									'Choose an EventWatch category.',
									'eventwatch'
								) }
							</FieldError>
						) }
						{ __(
							'Required to send to EventWatch. This is separate from your site’s categories.',
							'eventwatch'
						) }
					</>
				}
				value={ String( meta?.eventwatch_category ?? 0 ) }
				options={ [
					{
						value: '0',
						label: __( 'Select a category', 'eventwatch' ),
					},
					...categories,
				] }
				onChange={ ( value ) =>
					setField( 'eventwatch_category', Number( value ) )
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				data-eventwatch-field="eventwatch-field-schedule-note"
				className={
					noteRejected ? 'eventwatch-control--error' : undefined
				}
				aria-invalid={ noteRejected ? true : undefined }
				label={ __( 'Notes on dates', 'eventwatch' ) }
				rows={ 2 }
				maxLength={ MAX_NOTE }
				value={ note }
				onChange={ ( value ) =>
					setField( 'eventwatch_schedule_note', value )
				}
				help={
					<>
						{ noteRejected && rejectedError }
						{ sprintf(
							/* translators: 1: the number of characters entered, 2: the limit. */
							__( '%1$d / %2$d characters', 'eventwatch' ),
							countCharacters( note ),
							MAX_NOTE
						) }
					</>
				}
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				id="eventwatch-field-url"
				className={
					urlInvalid || urlRejected
						? 'eventwatch-control--error'
						: undefined
				}
				type="url"
				aria-invalid={ urlInvalid || urlRejected ? true : undefined }
				label={ __(
					'Separate official page (optional)',
					'eventwatch'
				) }
				placeholder="https://"
				maxLength={ MAX_URL }
				value={ url }
				onChange={ ( value ) => setField( 'eventwatch_url', value ) }
				help={
					<>
						{ urlInvalid && (
							<FieldError>
								{ __(
									'Enter a URL that starts with https:// or http://.',
									'eventwatch'
								) }
							</FieldError>
						) }
						{ ! urlInvalid && urlRejected && rejectedError }
						{ __(
							'When empty, this event’s page is sent to EventWatch as the official page.',
							'eventwatch'
						) }
					</>
				}
			/>
		</div>
	);
};

const EventPanelContent = () => {
	useOpenPanelOnce( PANEL_ID, PREFERENCE_OPENED );
	const fields = useEventFields();

	return (
		<PluginDocumentSettingPanel
			name={ PANEL_NAME }
			title={ __( 'Event details', 'eventwatch' ) }
		>
			<EventFields fields={ fields } />
		</PluginDocumentSettingPanel>
	);
};

const EventPanel = () => {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	return postType === POST_TYPE ? <EventPanelContent /> : null;
};

// 欄の名前と移動をテストで描いて確かめるため、パネルの外側の無い形でも書き出す。
export { EventFields };
export default EventPanel;
