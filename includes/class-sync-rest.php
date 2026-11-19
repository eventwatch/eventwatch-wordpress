<?php
/**
 * 編集画面が読む、送信の状態。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 同期のメタは REST のメタの一覧に載せない。編集できる人だけが、ここの口と edit の文脈の欄で読み書きする。
 */
class Sync_Rest {

	const REST_NAMESPACE = 'eventwatch/v1';
	const SEND_FIELD     = 'eventwatch_send';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * 欄と口を登録する。
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_field(
			Post_Type::POST_TYPE,
			self::SEND_FIELD,
			array(
				'get_callback'    => static function ( $post ) {
					return ! get_post_meta( (int) $post['id'], Sync_Queue::NO_SEND, true );
				},
				'update_callback' => static function ( $value, $post ) {
					if ( $value ) {
						delete_post_meta( $post->ID, Sync_Queue::NO_SEND );
					} else {
						update_post_meta( $post->ID, Sync_Queue::NO_SEND, 1 );
					}
				},
				'schema'          => array(
					'type'    => 'boolean',
					// 未認証の応答に載せない。edit の文脈は本体が編集の権限を求める。
					'context' => array( 'edit' ),
				),
			)
		);

		$args = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/sync',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_status' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/events/(?P<id>\d+)/resend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'resend' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * そのイベントを編集できるか。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return bool
	 */
	public static function can_edit( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];

		return Post_Type::POST_TYPE === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * 状態を返す。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return WP_REST_Response
	 */
	public static function read_status( WP_REST_Request $request ) {
		Sync_Pull::maybe_pull( (int) $request['id'] );

		return new WP_REST_Response( self::status( (int) $request['id'] ) );
	}

	/**
	 * 今すぐ送り直して、状態を返す。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return WP_REST_Response
	 */
	public static function resend( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		Sync_Queue::resend( $post_id );

		return new WP_REST_Response( self::status( $post_id ) );
	}

	/**
	 * 編集画面に出す状態。文はここで作り、画面は描くだけにする。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return array
	 */
	public static function status( $post_id ) {
		$state   = self::state( $post_id );
		$text    = self::state_text();
		$error   = get_post_meta( $post_id, Sync_Queue::ERROR, true );
		$blocks  = get_post_meta( $post_id, Sync_Queue::BLOCKS, true );
		$remote  = get_post_meta( $post_id, Sync_Queue::REMOTE, true );
		$next_at = (int) get_post_meta( $post_id, Sync_Queue::NEXT_AT, true );
		$sent_at = (int) get_post_meta( $post_id, Sync_Queue::SENT_AT, true );
		$reason  = array(
			'label' => '',
			'text'  => '',
		);
		$meta    = array(
			'label' => '',
			'value' => '',
		);

		// 止まっている間は、最初の 1 件にしか理由が残らず、一時的な失敗の次の時刻にも cron が張られていない。
		// 印の残っている投稿には、同じ理由を出す。
		$stopped = (string) get_post_meta( $post_id, Sync_Queue::PENDING, true ) ? self::stopped_reason() : '';
		if ( '' !== $stopped && ( 'queued' === $state || ( 'failed' === $state && is_array( $error ) && in_array( $error['code'], array( Api_Client::TEMPORARY, Api_Client::RATE_LIMITED ), true ) ) ) ) {
			$state = 'failed';
			// 止まっている間のほかの投稿にも、最初に断られた理由と同じ案内を出す。
			$error = array(
				'code'    => $stopped,
				'apiCode' => Api_Client::FORBIDDEN === $stopped ? Connection::state()['reason'] : '',
				'status'  => 0,
			);
		}

		if ( 'failed' === $state && is_array( $error ) ) {
			$reason = array(
				'label' => __( 'Reason', 'eventwatch' ),
				'text'  => self::error_text( $error ),
			);
			if ( '' === $stopped && $next_at > time() ) {
				$meta = array(
					'label' => __( 'Next retry', 'eventwatch' ),
					'value' => self::format_time( $next_at ),
				);
			}
		} elseif ( 'fix' === $state && is_array( $remote ) && '' !== $remote['rejectionReason'] ) {
			$reason = array(
				'label' => __( 'Reason from EventWatch', 'eventwatch' ),
				'text'  => $remote['rejectionReason'],
			);
		}

		if ( '' === $meta['label'] && $sent_at > 0 && in_array( $state, array( 'review', 'live', 'fix', 'stopped', 'withdrawn' ), true ) ) {
			$meta = array(
				'label' => __( 'Last sent', 'eventwatch' ),
				'value' => self::format_time( $sent_at ),
			);
		}

		return array(
			'state'       => $state,
			'label'       => $text[ $state ][0],
			'description' => 'unsent' === $state && 'publish' === get_post_status( $post_id ) ? __( 'This post hasn’t been sent to EventWatch yet. It will be sent the next time you update it.', 'eventwatch' ) : $text[ $state ][1],
			'reason'      => $reason,
			'meta'        => $meta,
			'link'        => self::link( $state, $post_id ),
			// 印が残っているものだけ。送り直しても変わらない失敗は、内容を直して更新する。キーなしと複製の間は送れない。
			'canResend'   => 'failed' === $state && (string) get_post_meta( $post_id, Sync_Queue::PENDING, true ) && is_array( $error ) && ! in_array( $error['code'], array( Api_Client::NO_KEY, Api_Client::SITE_CHANGED ), true ),
			'blocks'      => 'off' !== $state && is_array( $blocks ) ? array_map( array( self::class, 'block_item' ), $blocks ) : array(),
			'fieldErrors' => 'failed' === $state && is_array( $error ) && ! empty( $error['fields'] ) ? self::field_errors( $error['fields'] ) : array(),
			'imageIssue'  => 'off' !== $state ? self::image_issue( (string) get_post_meta( $post_id, Sync_Image::ISSUE, true ) ) : array(),
		);
	}

	/**
	 * 状態に添えるリンク。
	 *
	 * @param string $state   状態。
	 * @param int    $post_id 投稿の ID。
	 * @return array{label: string, url: string}
	 */
	private static function link( $state, $post_id ) {
		if ( 'live' === $state ) {
			return array(
				'label' => __( 'View on EventWatch', 'eventwatch' ),
				'url'   => Event_Detail::listing_url( $post_id ),
			);
		}
		if ( 'stopped' === $state ) {
			return array(
				'label' => __( 'Contact EventWatch', 'eventwatch' ),
				'url'   => Connection_Admin::web_url( '/contact' ),
			);
		}

		return array(
			'label' => '',
			'url'   => '',
		);
	}

	/**
	 * アイキャッチ画像を送れなかった理由。`blocks` と同じ形。
	 *
	 * @param string $code 理由。無ければ空。
	 * @return array
	 */
	private static function image_issue( $code ) {
		$messages = array(
			Sync_Image::ISSUE_FORMAT     => __( 'EventWatch accepts JPEG, PNG, and WebP images. Choose an image in one of these formats.', 'eventwatch' ),
			Sync_Image::ISSUE_TOO_LARGE  => __( 'The image is over EventWatch’s limit of 10 MB. Choose a smaller image.', 'eventwatch' ),
			Sync_Image::ISSUE_UNREADABLE => __( 'The image file couldn’t be read on this site. Choose the image again, or choose another one.', 'eventwatch' ),
			Sync_Image::ISSUE_REJECTED   => __( 'EventWatch didn’t accept the image. Choose another one.', 'eventwatch' ),
			Sync_Image::ISSUE_TEMPORARY  => __( 'Couldn’t upload the image to EventWatch. This is retried automatically about once an hour.', 'eventwatch' ),
			Sync_Image::ISSUE_QUOTA      => __( 'Your organizer has reached EventWatch’s limit of images for today. This is retried automatically about once an hour.', 'eventwatch' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return array();
		}

		return array(
			array(
				'code'    => 'image-' . $code,
				'message' => $messages[ $code ],
				'action'  => '',
				'field'   => '',
			),
		);
	}

	/**
	 * 断られた欄の行。知らない欄は出さず、理由の汎用の文に任せる。
	 *
	 * @param string[] $fields 応答の欄の名前。
	 * @return array<int, array{code: string, message: string, action: string, field: string}>
	 */
	private static function field_errors( array $fields ) {
		$known = array(
			'title'                 => array( __( 'EventWatch didn’t accept the title. Check it and update the post.', 'eventwatch' ), '', '' ),
			'description'           => array( __( 'EventWatch didn’t accept the content. Check it and update the post.', 'eventwatch' ), '', '' ),
			'occurrenceRanges'      => array( __( 'EventWatch didn’t accept the dates. Check them and update the post.', 'eventwatch' ), '', '' ),
			'holdingPeriodNote'     => array( __( 'EventWatch didn’t accept the notes on dates. Check them and update the post.', 'eventwatch' ), __( 'Edit the notes', 'eventwatch' ), 'eventwatch-field-schedule-note' ),
			'venueName'             => array( __( 'EventWatch didn’t accept the venue name. Check it and update the post.', 'eventwatch' ), __( 'Edit the venue', 'eventwatch' ), 'eventwatch-field-venue-name' ),
			'venueAddress'          => array( __( 'EventWatch didn’t accept the venue address. Check it and update the post.', 'eventwatch' ), __( 'Edit the venue', 'eventwatch' ), 'eventwatch-field-venue-address' ),
			'publisherEventPageUrl' => array( __( 'EventWatch didn’t accept the official page URL. Check it and update the post.', 'eventwatch' ), __( 'Edit the official page', 'eventwatch' ), 'eventwatch-field-url' ),
		);

		$items = array();
		foreach ( $fields as $name ) {
			if ( isset( $known[ $name ] ) ) {
				$items[] = array(
					'code'    => $name,
					'message' => $known[ $name ][0],
					'action'  => $known[ $name ][1],
					'field'   => $known[ $name ][2],
				);
			}
		}

		return $items;
	}

	/**
	 * 送信が止まっている理由のコード。止まっていなければ空。
	 *
	 * @return string
	 */
	private static function stopped_reason() {
		if ( '' === Connection::api_key() ) {
			return Api_Client::NO_KEY;
		}
		if ( Site_Identity::has_changed() ) {
			return Api_Client::SITE_CHANGED;
		}

		switch ( Connection::state()['status'] ) {
			case Connection::UNAUTHORIZED:
				return Api_Client::UNAUTHORIZED;
			case Connection::SUSPENDED:
				return Api_Client::SUSPENDED;
			case Connection::FORBIDDEN:
				return Api_Client::FORBIDDEN;
		}

		return '';
	}

	/**
	 * 切り替えだけを変えた、まだ保存していない間に出す文。
	 *
	 * @return array{off: array{label: string, description: string}, unsent: array{label: string, description: string}}
	 */
	public static function unsaved_text() {
		$text = self::state_text();

		return array(
			'off'    => array(
				'label'       => $text['off'][0],
				'description' => $text['off'][1],
			),
			'unsent' => array(
				'label'       => $text['unsent'][0],
				'description' => $text['unsent'][1],
			),
		);
	}

	/**
	 * 状態の種類を決める。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function state( $post_id ) {
		$error   = get_post_meta( $post_id, Sync_Queue::ERROR, true );
		$pending = get_post_meta( $post_id, Sync_Queue::PENDING, true );

		// 「送らない」にした後の取り下げが届いていない間は、EventWatch に掲載が残っている。
		if ( $error && $pending ) {
			return 'failed';
		}
		if ( get_post_meta( $post_id, Sync_Queue::NO_SEND, true ) ) {
			return 'off';
		}
		if ( $error ) {
			return 'failed';
		}
		if ( get_post_meta( $post_id, Sync_Queue::PENDING, true ) ) {
			return 'queued';
		}

		$remote = get_post_meta( $post_id, Sync_Queue::REMOTE, true );

		return is_array( $remote ) ? self::remote_state( $remote ) : 'unsent';
	}

	/**
	 * EventWatch の状態を、状態の種類にする。
	 *
	 * @param array $remote Sync_Queue::remote_from_event の戻り。
	 * @return string
	 */
	private static function remote_state( array $remote ) {
		if ( $remote['suspended'] ) {
			return 'stopped';
		}

		switch ( $remote['status'] ) {
			case 'pendingReview':
				return 'review';
			case 'rejected':
				return 'fix';
			case 'published':
				return $remote['visible'] ? 'live' : 'stopped';
			case 'draft':
			case 'private':
				return 'withdrawn';
		}

		return 'unsent';
	}

	/**
	 * 取り込む前の投稿の状態。下書き・非公開は、サイトで取り下げたのではないので別の語にする。
	 *
	 * @param array $event 一覧の投稿。
	 * @return array{state: string, label: string, reason: string}
	 */
	public static function event_status( array $event ) {
		$remote = Sync_Queue::remote_from_event( $event );
		$state  = self::remote_state( $remote );
		$label  = self::state_text()[ $state ][0];

		if ( ! $remote['suspended'] && 'draft' === $remote['status'] ) {
			$label = __( 'Draft', 'eventwatch' );
		} elseif ( ! $remote['suspended'] && 'private' === $remote['status'] ) {
			$label = __( 'Private', 'eventwatch' );
		}

		return array(
			'state'  => $state,
			'label'  => $label,
			'reason' => 'rejected' === $remote['status'] ? $remote['rejectionReason'] : '',
		);
	}

	/**
	 * 状態の語と説明。
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function state_text() {
		return array(
			'unsent'    => array( __( 'Not sent', 'eventwatch' ), __( 'This post hasn’t been sent to EventWatch yet. It will be sent automatically when you publish.', 'eventwatch' ) ),
			'queued'    => array( __( 'Waiting to send', 'eventwatch' ), __( 'This post will be sent to EventWatch shortly, usually within a few minutes.', 'eventwatch' ) ),
			'review'    => array( __( 'Under review', 'eventwatch' ), __( 'EventWatch is checking the details. Once it’s listed, you’ll see it here.', 'eventwatch' ) ),
			'live'      => array( __( 'Listed', 'eventwatch' ), __( 'This post is listed on EventWatch. Updates to the post are reflected there too.', 'eventwatch' ) ),
			'fix'       => array( __( 'Changes requested', 'eventwatch' ), __( 'EventWatch has asked for changes. Fix the post and update it to send it again.', 'eventwatch' ) ),
			'stopped'   => array( __( 'Listing paused', 'eventwatch' ), __( 'EventWatch has paused this listing. It stays published on your site.', 'eventwatch' ) ),
			'withdrawn' => array( __( 'Withdrawn from EventWatch', 'eventwatch' ), __( 'This post isn’t public on EventWatch because it isn’t published on your site. Publish it to list it again.', 'eventwatch' ) ),
			'failed'    => array( __( 'Couldn’t send', 'eventwatch' ), __( 'This post couldn’t be sent to EventWatch.', 'eventwatch' ) ),
			'off'       => array( __( 'Not sending to EventWatch', 'eventwatch' ), __( 'This post won’t be sent to EventWatch. Turn sending on, and it will be sent the next time you update the post.', 'eventwatch' ) ),
		);
	}

	/**
	 * 失敗の理由の文。原因と次の一手を書く。
	 *
	 * @param array $error Sync_Queue の記録。
	 * @return string
	 */
	private static function error_text( array $error ) {
		switch ( $error['code'] ) {
			case Api_Client::UNAUTHORIZED:
				return Connection_Admin::status_text( array( 'status' => Connection::UNAUTHORIZED ) );
			case Api_Client::SUSPENDED:
				return Connection_Admin::status_text( array( 'status' => Connection::SUSPENDED ) );
			case Api_Client::FORBIDDEN:
				return Connection_Admin::status_text(
					array(
						'status' => Connection::FORBIDDEN,
						'reason' => $error['apiCode'],
					)
				);
			case Api_Client::NO_KEY:
				return __( 'No EventWatch API key is set. Enter a key in Settings › EventWatch, and sending resumes.', 'eventwatch' );
			case Api_Client::SITE_CHANGED:
				return __( 'This site’s URL has changed, so sending is stopped. Choose whether this site is a copy or has moved in Settings › EventWatch.', 'eventwatch' );
			case Api_Client::TEMPORARY:
			case Api_Client::RATE_LIMITED:
				return __( 'Couldn’t reach EventWatch. Your site isn’t affected. Sending is retried automatically.', 'eventwatch' );
		}

		if ( 'eventDeleted' === $error['apiCode'] ) {
			return __( 'This event was deleted on EventWatch, so it can’t be sent again. Contact EventWatch if you think this is a mistake.', 'eventwatch' );
		}

		if ( 409 === $error['status'] ) {
			return __( 'EventWatch already has another listing with this official page. If it’s the same event, contact EventWatch. Otherwise, enter a separate official page and update the post.', 'eventwatch' );
		}

		return __( 'EventWatch didn’t accept the details of this event. Check the event details and update the post.', 'eventwatch' );
	}

	/**
	 * 送れない理由の 1 行。
	 *
	 * @param array $block Sync_Payload の理由。
	 * @return array{code: string, message: string, action: string, field: string}
	 */
	private static function block_item( array $block ) {
		$action = '';
		$field  = '';

		switch ( $block['code'] ) {
			case Sync_Payload::BLOCK_CATEGORY:
				$message = __( 'No EventWatch category is selected.', 'eventwatch' );
				$action  = __( 'Choose a category', 'eventwatch' );
				$field   = 'eventwatch-field-category';
				break;
			case Sync_Payload::BLOCK_PREFECTURE:
				$message = __( 'No prefecture is selected. EventWatch needs one unless the event is held online.', 'eventwatch' );
				$action  = __( 'Choose a prefecture', 'eventwatch' );
				$field   = 'eventwatch-field-prefecture';
				break;
			case Sync_Payload::BLOCK_OCCURRENCES:
				$message = __( 'This event has no dates. Add at least one date.', 'eventwatch' );
				break;
			case Sync_Payload::BLOCK_NOTE_TOO_LONG:
				$message = sprintf(
					/* translators: %s: the character limit. */
					__( 'The notes on dates are too long. EventWatch accepts up to %s characters, and counts emoji as two. Shorten the notes.', 'eventwatch' ),
					number_format_i18n( Event_Meta::MAX_NOTE )
				);
				$action = __( 'Edit the notes', 'eventwatch' );
				$field  = 'eventwatch-field-schedule-note';
				break;
			case Sync_Payload::BLOCK_VENUE_INCOMPLETE:
				$message = __( 'Only one of the venue name and address is entered. Enter both, or leave both empty.', 'eventwatch' );
				$action  = __( 'Edit the venue', 'eventwatch' );
				$field   = 'eventwatch-field-venue-name';
				break;
			case Sync_Payload::BLOCK_VENUE_TOO_LONG:
				$message = __( 'The venue name or address is too long for EventWatch, which counts emoji as two characters. Shorten it.', 'eventwatch' );
				$action  = __( 'Edit the venue', 'eventwatch' );
				$field   = 'eventwatch-field-venue-name';
				break;
			case Sync_Payload::BLOCK_URL_TOO_LONG:
				$message = sprintf(
					/* translators: %s: the character limit. */
					__( 'The official page URL is too long. EventWatch accepts up to %s characters. Enter a shorter separate official page.', 'eventwatch' ),
					number_format_i18n( Event_Meta::MAX_URL )
				);
				$action = __( 'Edit the official page', 'eventwatch' );
				$field  = 'eventwatch-field-url';
				break;
			case Sync_Payload::BLOCK_TITLE_EMPTY:
				$message = __( 'This event has no title. Add a title.', 'eventwatch' );
				break;
			case Sync_Payload::BLOCK_TITLE_TOO_LONG:
				$message = sprintf(
					/* translators: %s: the character limit. */
					__( 'The title is too long. EventWatch accepts up to %s characters. Shorten the title.', 'eventwatch' ),
					number_format_i18n( Sync_Payload::MAX_TITLE )
				);
				break;
			default:
				$message = sprintf(
					/* translators: 1: the length of the content, 2: the character limit. */
					__( 'The content has %1$s characters. EventWatch accepts up to %2$s characters. Shorten the content.', 'eventwatch' ),
					number_format_i18n( isset( $block['length'] ) ? (int) $block['length'] : 0 ),
					number_format_i18n( Sync_Payload::MAX_DESCRIPTION )
				);
		}

		return array(
			'code'    => $block['code'],
			'message' => $message,
			'action'  => $action,
			'field'   => $field,
		);
	}

	/**
	 * 時刻の書式。
	 *
	 * @param int $timestamp UNIX 時刻。
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
