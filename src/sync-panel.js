import apiFetch from '@wordpress/api-fetch';
import { Button, ExternalLink, ToggleControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import findField from './find-field';
import { EVENT_PANEL_ID, SYNC_BLOCKS_EVENT } from './panel-names';
import useOpenPanelOnce from './use-open-panel-once';

const POST_TYPE = 'eventwatch_event';
const PANEL_NAME = 'eventwatch-sync';
const SYNC_PLUGIN_NAME = 'eventwatch-sync';
const NOTICE_ID = 'eventwatch-sync-attention';
const DOCUMENT_SIDEBAR = 'edit-post/document';
// @wordpress/interface は本体が単独のスクリプトとして配らないので、ストアを名前で引く。
const INTERFACE_STORE = 'core/interface';
const PANEL_ID = `${ SYNC_PLUGIN_NAME }/${ PANEL_NAME }`;
const SEND_FIELD = 'eventwatch_send';

// 保存の応答は送信の前に作られるので、送信待ちの間だけ読み直す。新しい画像は約 1 分後の cron で送るので、その後まで見る。
const REFRESH_INTERVAL = 5000;
const REFRESH_MAX = 24;

const ICONS = {
	check: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	minus: 'M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	pause: 'M14.25 9v6m-4.5 0V9M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	refresh:
		'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99',
	warning:
		'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
};

const STATE_LOOKS = {
	unsent: { icon: 'minus', tone: 'muted' },
	queued: { icon: 'clock', tone: 'muted' },
	review: { icon: 'clock', tone: 'info' },
	live: { icon: 'check', tone: 'success' },
	fix: { icon: 'warning', tone: 'warning' },
	stopped: { icon: 'pause', tone: 'warning' },
	withdrawn: { icon: 'minus', tone: 'muted' },
	failed: { icon: 'warning', tone: 'error' },
	off: { icon: 'minus', tone: 'muted' },
};

const Icon = ( { name, size = 20 } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.6"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		<path d={ ICONS[ name ] } />
	</svg>
);

const useSyncStatus = ( postId ) => {
	const [ status, setStatus ] = useState( null );
	const [ resending, setResending ] = useState( false );
	const timer = useRef();
	const generation = useRef( 0 );

	const refresh = useCallback(
		( remaining = 0 ) => {
			window.clearTimeout( timer.current );
			generation.current += 1;
			const current = generation.current;

			apiFetch( { path: `/eventwatch/v1/events/${ postId }/sync` } )
				.then( ( next ) => {
					if ( current !== generation.current ) {
						return;
					}
					setStatus( next );
					if ( next.state === 'queued' && remaining > 0 ) {
						timer.current = window.setTimeout(
							() => refresh( remaining - 1 ),
							REFRESH_INTERVAL
						);
					}
				} )
				// 状態を読めなくても、編集を止めない。次の保存で読み直す。
				.catch( () => {} );
		},
		[ postId ]
	);

	useEffect( () => {
		if ( postId ) {
			refresh();
		}
		return () => window.clearTimeout( timer.current );
	}, [ postId, refresh ] );

	const saving = useSelect( ( select ) => {
		const editor = select( editorStore );
		return editor.isSavingPost() && ! editor.isAutosavingPost();
	}, [] );
	const wasSaving = useRef( false );
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			refresh( REFRESH_MAX );
		}
		wasSaving.current = saving;
	}, [ saving, refresh ] );

	const resend = () => {
		setResending( true );
		generation.current += 1;
		apiFetch( {
			path: `/eventwatch/v1/events/${ postId }/resend`,
			method: 'POST',
		} )
			.then( ( next ) => {
				setStatus( next );
				// 上げ直しが要る投稿は cron に回るので、保存の後と同じく結果を待つ。
				if ( next.state === 'queued' ) {
					timer.current = window.setTimeout(
						() => refresh( REFRESH_MAX ),
						REFRESH_INTERVAL
					);
				}
			} )
			.catch( () => {} )
			.finally( () => setResending( false ) );
	};

	return { status, resend, resending };
};

const SendBlocks = ( { blocks, heading, description } ) => {
	const registry = useRegistry();

	// 欄はパネルの中にある。閉じていれば開いてから移す。Slot が遅れて描くので待つ。
	const focusField = ( id ) => {
		const editor = registry.select( editorStore );
		if ( ! editor.isEditorPanelOpened( EVENT_PANEL_ID ) ) {
			registry
				.dispatch( editorStore )
				.toggleEditorPanelOpened( EVENT_PANEL_ID );
		}
		window.requestAnimationFrame( () => {
			window.setTimeout( () => findField( id )?.focus() );
		} );
	};

	return (
		<div className="eventwatch-sync__blocks">
			<span className="eventwatch-sync__blocks-icon">
				<Icon name="warning" />
			</span>
			<div className="eventwatch-sync__blocks-body">
				<p className="eventwatch-sync__blocks-heading">{ heading }</p>
				<p className="eventwatch-sync__muted">{ description }</p>
				<ul>
					{ blocks.map( ( block ) => (
						<li key={ block.code }>
							<span>{ block.message }</span>
							{ block.field && (
								<>
									{ ' ' }
									<Button
										variant="link"
										onClick={ () =>
											focusField( block.field )
										}
									>
										{ block.action }
									</Button>
								</>
							) }
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
};

const SyncPanelContent = ( { unsavedText } ) => {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	const [ send, setSend ] = useEntityProp(
		'postType',
		POST_TYPE,
		SEND_FIELD
	);
	const { status, resend, resending } = useSyncStatus( postId );
	const registry = useRegistry();

	useOpenPanelOnce( PANEL_ID, 'syncOpenedOnce' );

	// 閉じたままだと、送れないことに気づかないまま公開が終わる。理由が変わったら開く。
	const blockCodes = [
		...( status?.blocks ?? [] ),
		...( status?.fieldErrors ?? [] ),
		...( status?.imageIssue ?? [] ),
		// 差し戻しの理由もパネルの中にある。
		...( status?.state === 'fix' ? [ { code: 'fix' } ] : [] ),
	]
		.map( ( block ) => block.code )
		.join();
	useEffect( () => {
		if (
			blockCodes &&
			! registry.select( editorStore ).isEditorPanelOpened( PANEL_ID )
		) {
			registry
				.dispatch( editorStore )
				.toggleEditorPanelOpened( PANEL_ID );
		}
	}, [ blockCodes, registry ] );

	const sendOn = send !== false;
	const hasBlock = ( code ) =>
		sendOn &&
		Boolean( status?.blocks.some( ( item ) => item.code === code ) );
	const categoryBlocked = hasBlock( 'category' );
	const prefectureBlocked = hasBlock( 'prefecture' );
	const rejectedFields = sendOn
		? ( status?.fieldErrors ?? [] )
				.map( ( item ) => item.field )
				.filter( Boolean )
				.join()
		: '';
	useEffect( () => {
		document.dispatchEvent(
			new window.CustomEvent( SYNC_BLOCKS_EVENT, {
				detail: {
					category: categoryBlocked,
					prefecture: prefectureBlocked,
					rejected: rejectedFields ? rejectedFields.split( ',' ) : [],
				},
			} )
		);
	}, [ categoryBlocked, prefectureBlocked, rejectedFields ] );

	const panelVisible = useSelect( ( select ) => {
		const editor = select( editorStore );
		return (
			editor.isEditorPanelEnabled( PANEL_ID ) &&
			editor.isEditorPanelOpened( PANEL_ID ) &&
			select( INTERFACE_STORE ).getActiveComplementaryArea( 'core' ) ===
				DOCUMENT_SIDEBAR
		);
	}, [] );
	const { createWarningNotice, removeNotice } = useDispatch( noticesStore );
	const needsAttention =
		status?.state === 'failed' || ( sendOn && status?.blocks.length > 0 );

	// 理由はパネルの中にある。パネルが見えない間は、送れていないことを通知で出す。
	useEffect( () => {
		if ( needsAttention && ! panelVisible ) {
			createWarningNotice(
				__(
					'This post wasn’t sent to EventWatch. Open the EventWatch panel to see why.',
					'eventwatch'
				),
				{ id: NOTICE_ID }
			);
		} else {
			removeNotice( NOTICE_ID );
		}
		return () => removeNotice( NOTICE_ID );
	}, [ needsAttention, panelVisible, createWarningNotice, removeNotice ] );

	if ( ! status ) {
		return null;
	}

	const sending = sendOn;
	let shown = status;
	// 取り下げが届いていない失敗は、切り替えの見た目より優先する。掲載が残っているため。
	if ( ! sending && status.state !== 'failed' ) {
		shown = { ...status, ...unsavedText.off, state: 'off' };
	} else if ( status.state === 'off' ) {
		shown = { ...status, ...unsavedText.unsent, state: 'unsent' };
	}
	const look = STATE_LOOKS[ shown.state ] ?? STATE_LOOKS.unsent;
	const blocks = sending ? status.blocks : [];
	const fieldErrors = sending ? status.fieldErrors : [];
	const imageIssue = sending ? status.imageIssue : [];

	return (
		<PluginDocumentSettingPanel name={ PANEL_NAME } title="EventWatch">
			<div className="eventwatch-sync">
				{ blocks.length > 0 && (
					<SendBlocks
						blocks={ blocks }
						heading={ __(
							'Can’t send to EventWatch',
							'eventwatch'
						) }
						description={ __(
							'Fix the points below, and the post is sent when you update it. Publishing on your site isn’t affected.',
							'eventwatch'
						) }
					/>
				) }
				{ fieldErrors.length > 0 && (
					<SendBlocks
						blocks={ fieldErrors }
						heading={ __(
							'EventWatch didn’t accept these details',
							'eventwatch'
						) }
						description={ __(
							'Fix the points below, and the post is sent when you update it. Publishing on your site isn’t affected.',
							'eventwatch'
						) }
					/>
				) }
				{ imageIssue.length > 0 && (
					<SendBlocks
						blocks={ imageIssue }
						heading={ __(
							'The featured image wasn’t sent',
							'eventwatch'
						) }
						description={ __(
							'The other details aren’t affected. If EventWatch already has an image for this event, it stays.',
							'eventwatch'
						) }
					/>
				) }
				<div
					className={ `eventwatch-sync__state eventwatch-sync__state--${ look.tone }` }
				>
					<Icon name={ look.icon } />
					<div className="eventwatch-sync__state-text">
						<span className="eventwatch-sync__label">
							{ shown.label }
						</span>
						<span className="eventwatch-sync__muted">
							{ shown.description }
						</span>
					</div>
				</div>
				{ shown.state !== 'off' && shown.reason.text && (
					<div className="eventwatch-sync__reason">
						<span className="eventwatch-sync__reason-label">
							{ shown.reason.label }
						</span>
						<p>{ shown.reason.text }</p>
					</div>
				) }
				{ shown.state !== 'off' && shown.meta.value && (
					<dl className="eventwatch-sync__meta">
						<dt>{ shown.meta.label }</dt>
						<dd>{ shown.meta.value }</dd>
					</dl>
				) }
				{ shown.state !== 'off' && shown.link.url && (
					<ExternalLink href={ shown.link.url }>
						{ shown.link.label }
					</ExternalLink>
				) }
				{ shown.state !== 'off' && shown.canResend && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						className="eventwatch-sync__resend"
						isBusy={ resending }
						disabled={ resending }
						accessibleWhenDisabled
						onClick={ resend }
					>
						<Icon name="refresh" size={ 16 } />
						{ __( 'Retry now', 'eventwatch' ) }
					</Button>
				) }
				<div className="eventwatch-sync__toggle">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Send this post to EventWatch',
							'eventwatch'
						) }
						checked={ sending }
						onChange={ setSend }
					/>
				</div>
			</div>
		</PluginDocumentSettingPanel>
	);
};

const SyncPanel = () => {
	const { sync } = window.eventwatchEditor ?? {};
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	// キーなしのサイトでは騒がない。
	if ( postType !== POST_TYPE || ! sync?.hasKey ) {
		return null;
	}

	return <SyncPanelContent unsavedText={ sync.unsavedText } />;
};

export { SYNC_PLUGIN_NAME };
export default SyncPanel;
