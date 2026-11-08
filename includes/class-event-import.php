<?php
/**
 * EventWatch の Web で作った投稿を、サイトの投稿として取り込む。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeZone;
use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 一覧を読み、1 件ずつ作って結び付ける。管理画面の操作からだけ呼ぶ。
 *
 * 順序は 画像 → 下書き → 結び付け → 公開。画像を欠いたまま結び付けると、次の `PUT` で EventWatch の画像が外れる。
 * 下書きは結び付くまで「送らない」にしておく。途中で公開されると、`PUT` が別の投稿を作ってしまう。
 */
class Event_Import {

	const LIST_PATH = '/api/v1.0/org-api/events';

	const PAGE_SIZE      = 20;
	const FIND_SIZE      = 100;
	const FIND_MAX_PAGES = 10;

	const TIMEOUT       = 15;
	const IMAGE_TIMEOUT = 30;

	const SNAPSHOT_PREFIX = 'eventwatch_import_snapshot_';
	const SNAPSHOT_TTL    = HOUR_IN_SECONDS;

	const LOCK_PREFIX = 'eventwatch_import_lock_';

	/**
	 * 同じ投稿を別の要求が進めているときに待つ秒数。
	 */
	const BUSY_WAIT = 5;

	const CODE_ALREADY_LINKED = 'eventAlreadyLinked';
	const CODE_IDENTIFIER     = 'externalIdentifierInUse';

	const STATUS_IMPORTED = 'imported';
	const STATUS_FAILED   = 'failed';
	const STATUS_WAIT     = 'wait';
	const STATUS_STOP     = 'stop';

	/**
	 * 手元にあると数える投稿の状態。ゴミ箱を含めない。
	 */
	const LIVE_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * 取り込む画像の形式。EventWatch が配る形式で、Sync_Image が送れる形式。
	 */
	const IMAGE_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * 一覧の 1 ページを読む。
	 *
	 * @param int $page 0 から数えたページ。
	 * @param int $size 件数。
	 * @return array{list: array, total: int}|WP_Error
	 */
	public static function list_page( $page, $size = self::PAGE_SIZE ) {
		$result = ( new Api_Client() )->request(
			'GET',
			self::LIST_PATH . '?page=' . (int) $page . '&size=' . (int) $size,
			array( 'timeout' => self::TIMEOUT )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = is_array( $result['body'] ) ? $result['body'] : array();

		return array(
			'list'  => isset( $body['list'] ) && is_array( $body['list'] ) ? $body['list'] : array(),
			'total' => isset( $body['total'] ) ? (int) $body['total'] : 0,
		);
	}

	/**
	 * 選ばれた投稿を読み、この人の取り込みの間だけ持つ。内容はブラウザーから受け取らない。
	 *
	 * 結び付けると一覧の位置がずれるので、ページの番号に頼らず先頭から探す。
	 *
	 * @param string[] $ids 投稿の ID。
	 * @return array<int, array{id: string, title: string}>|WP_Error
	 */
	public static function prepare( array $ids ) {
		$wanted = array_flip( $ids );
		$found  = array();

		for ( $page = 0; $page < self::FIND_MAX_PAGES; ++$page ) {
			$listed = self::list_page( $page, self::FIND_SIZE );
			if ( is_wp_error( $listed ) ) {
				return $listed;
			}

			foreach ( $listed['list'] as $event ) {
				if ( isset( $event['id'] ) && isset( $wanted[ $event['id'] ] ) ) {
					$found[ $event['id'] ] = $event;
				}
			}

			if ( count( $listed['list'] ) < self::FIND_SIZE || count( $found ) === count( $wanted ) ) {
				break;
			}
		}

		set_transient( self::snapshot_key(), $found, self::SNAPSHOT_TTL );

		$items = array();
		foreach ( $ids as $id ) {
			$items[] = array(
				'id'    => $id,
				'title' => isset( $found[ $id ]['title'] ) ? (string) $found[ $id ]['title'] : self::local_title( $id ),
			);
		}

		return $items;
	}

	/**
	 * 1 件を取り込む。同じ投稿を同時に進めない。
	 *
	 * @param string $event_id 投稿の ID。
	 * @return array{status: string, eventId: string, title: string, postId: int, editUrl: string, postStatus: string, reason: string, retryAfter: int}
	 */
	public static function run( $event_id ) {
		$lock = Sync_Queue::take_lock( self::lock_key( $event_id ) );
		if ( '' === $lock ) {
			return self::outcome( self::STATUS_WAIT, $event_id, array( 'retryAfter' => self::BUSY_WAIT ) );
		}

		try {
			return self::import_one( $event_id );
		} finally {
			Sync_Queue::release_lock( $lock, self::lock_key( $event_id ) );
		}
	}

	/**
	 * 結び付いていない下書き。やり直しの案内に出す。
	 *
	 * @return array<int, array{id: string, title: string, postId: int, editUrl: string}>
	 */
	public static function unlinked_drafts() {
		$post_ids = get_posts(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => self::LIVE_STATUSES,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 取り込みの画面を開いたときだけ引く。
				'meta_key'       => Sync_Queue::IMPORT_ORIGINAL,
			)
		);

		$drafts = array();
		foreach ( $post_ids as $post_id ) {
			$drafts[] = array(
				'id'      => (string) get_post_meta( $post_id, Sync_Queue::IMPORT_SOURCE, true ),
				'title'   => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'postId'  => (int) $post_id,
				'editUrl' => self::edit_url( $post_id ),
			);
		}

		return $drafts;
	}

	/**
	 * 取り込み元で手元の投稿を引く。ゴミ箱の投稿は数えない（捨てた投稿を、もう一度取り込めるようにする）。
	 *
	 * @param string $event_id 投稿の ID。
	 * @return int 無ければ 0。
	 */
	public static function find_local( $event_id ) {
		$post_ids = get_posts(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => self::LIVE_STATUSES,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 管理画面の操作でだけ引く。
				'meta_key'       => Sync_Queue::IMPORT_SOURCE,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 同上。
				'meta_value'     => $event_id,
			)
		);

		return $post_ids ? (int) $post_ids[0] : 0;
	}

	/**
	 * 切り離し中の投稿が、このサイトのいまある投稿か。
	 *
	 * @param array $event 一覧の投稿。
	 * @return int 無ければ 0。
	 */
	public static function own_post_id( array $event ) {
		if ( empty( $event['externalSiteId'] ) || empty( $event['externalId'] ) || Site_Identity::id() !== $event['externalSiteId'] ) {
			return 0;
		}

		$post = get_post( (int) $event['externalId'] );

		return $post && Post_Type::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ? (int) $post->ID : 0;
	}

	/**
	 * 応答の日程を、投稿のメタの形にする。
	 *
	 * @param array $event 投稿。
	 * @return array<int, array{start: string, end: string}>
	 */
	public static function occurrence_rows( array $event ) {
		$rows = array();
		foreach ( isset( $event['occurrenceRanges'] ) && is_array( $event['occurrenceRanges'] ) ? $event['occurrenceRanges'] : array() as $range ) {
			if ( ! empty( $range['startAt'] ) ) {
				$rows[] = array(
					'start' => (string) $range['startAt'],
					'end'   => isset( $range['closeAt'] ) ? (string) $range['closeAt'] : '',
				);
			}
		}

		return $rows;
	}

	/**
	 * イベントのタイムゾーン。読めなければサイトのもの。
	 *
	 * @param array $event 投稿。
	 * @return DateTimeZone
	 */
	public static function zone_of( array $event ) {
		try {
			return new DateTimeZone( isset( $event['zoneId'] ) ? (string) $event['zoneId'] : '' );
		} catch ( Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * 終わった投稿か。境目は一覧の「開催予定」と同じ。
	 *
	 * @param array $event 投稿。
	 * @return bool
	 */
	public static function has_ended( array $event ) {
		$rows = self::occurrence_rows( $event );
		if ( ! $rows ) {
			return false;
		}

		$last = end( $rows );
		$end  = Event_Meta::to_utc( '' !== $last['end'] ? $last['end'] : $last['start'], self::zone_of( $event )->getName() );

		return null !== $end && $end < Event_Dates::upcoming_boundary();
	}

	/**
	 * 1 件を進める。
	 *
	 * @param string $event_id 投稿の ID。
	 * @return array
	 */
	private static function import_one( $event_id ) {
		$local    = self::find_local( $event_id );
		$snapshot = get_transient( self::snapshot_key() );
		$event    = is_array( $snapshot ) && isset( $snapshot[ $event_id ] ) ? $snapshot[ $event_id ] : null;

		// 応答を失った結び付けは一覧から消えている。下書きがあれば、内容を読まずに結び付けをやり直せる。
		// 控えが残っている間は結び付いていない。「送らない」を切って公開すると、結び付かないまま EXISTS が立つ。
		if ( $local && get_post_meta( $local, Sync_Queue::IMPORT_ORIGINAL, true ) ) {
			return self::link( $event_id, $local, false );
		}

		if ( $local ) {
			// 控えの無い投稿は一度結び付いている。押した時点の一覧に出ていたなら EventWatch で切り離されたので、
			// サイトで作った投稿と同じく結び直す。出ていなければ結び付いたまま。
			return $event ? self::link( $event_id, $local, true ) : self::imported( $event_id, $local );
		}

		if ( ! $event ) {
			return self::failed( $event_id, '', __( 'This post can’t be imported any more. It may have been deleted or imported from another site.', 'eventwatch' ) );
		}

		$own = self::own_post_id( $event );
		if ( $own ) {
			return self::link( $event_id, $own, true );
		}

		$images = self::collect_images( $event );
		if ( is_wp_error( $images ) ) {
			return self::failed( $event_id, (string) $event['title'], __( 'The images couldn’t be loaded from EventWatch. Wait a moment, then try again.', 'eventwatch' ) );
		}

		$post_id = self::create_draft( $event, $images );
		if ( is_wp_error( $post_id ) ) {
			return self::failed( $event_id, (string) $event['title'], __( 'The post couldn’t be created on this site. Try again.', 'eventwatch' ) );
		}

		return self::link( $event_id, $post_id, false );
	}

	/**
	 * 本文の画像とアイキャッチ画像を、メディアライブラリへ入れる。
	 *
	 * @param array $event 投稿。
	 * @return array{featured: int, body: array<string, int>}|WP_Error
	 */
	private static function collect_images( array $event ) {
		$featured = 0;
		$url      = self::featured_url( $event );
		if ( '' !== $url ) {
			$featured = self::sideload( $url );
			if ( is_wp_error( $featured ) ) {
				return $featured;
			}
		}

		$body = array();
		foreach ( Markdown_Blocks::image_urls( isset( $event['description'] ) ? (string) $event['description'] : '' ) as $image_url ) {
			$attachment = self::sideload( $image_url );
			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}
			$body[ $image_url ] = $attachment;
		}

		return array(
			'featured' => $featured,
			'body'     => $body,
		);
	}

	/**
	 * アイキャッチ画像の URL。一番幅の広い版。
	 *
	 * @param array $event 投稿。
	 * @return string
	 */
	private static function featured_url( array $event ) {
		$list = isset( $event['thumbnails']['thumbnail']['list'] ) && is_array( $event['thumbnails']['thumbnail']['list'] ) ? $event['thumbnails']['thumbnail']['list'] : array();
		$best = '';
		$top  = -1;

		foreach ( $list as $image ) {
			if ( ! empty( $image['publicUrl'] ) && (int) ( $image['width'] ?? 0 ) > $top ) {
				$best = (string) $image['publicUrl'];
				$top  = (int) ( $image['width'] ?? 0 );
			}
		}

		return $best;
	}

	/**
	 * 画像を取得して添付にする。同じ URL を入れたことがあれば使い回す。
	 *
	 * @param string $url 画像の URL。
	 * @return int|WP_Error 添付の ID。
	 */
	private static function sideload( $url ) {
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 管理画面の操作でだけ引く。
				'meta_key'       => Sync_Queue::IMPORT_IMAGE_SOURCE,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 同上。
				'meta_value'     => $url,
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// 管理者は HTML も上げられるので、画像の拡張子に絞る。中身が拡張子と合わなければ本体が断る。
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( ! wp_check_filetype( $name, self::IMAGE_TYPES )['type'] ) {
			return new WP_Error( 'eventwatch_import_not_image', 'The address is not an image.' );
		}

		$file = download_url( $url, self::IMAGE_TIMEOUT );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$attachment = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $file,
			),
			0
		);

		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $file );
			return $attachment;
		}

		update_post_meta( $attachment, Sync_Queue::IMPORT_IMAGE_SOURCE, $url );

		return (int) $attachment;
	}

	/**
	 * 下書きを作る。欄の対応は Sync_Payload::build の逆。
	 *
	 * @param array $event  投稿。
	 * @param array $images collect_images の戻り。
	 * @return int|WP_Error 投稿の ID。
	 */
	private static function create_draft( array $event, array $images ) {
		$kept = array();
		foreach ( $images['body'] as $url => $attachment ) {
			$kept[ $attachment ] = $url;
		}

		$meta = array(
			Event_Meta::OCCURRENCES     => self::occurrence_rows( $event ),
			Event_Meta::ZONE            => isset( $event['zoneId'] ) ? (string) $event['zoneId'] : '',
			Event_Meta::SCHEDULE_NOTE   => isset( $event['holdingPeriodNote'] ) ? (string) $event['holdingPeriodNote'] : '',
			Event_Meta::CATEGORY        => isset( $event['category'] ) ? (int) $event['category'] : 0,
			Event_Meta::PREFECTURE      => isset( $event['prefectureCode'] ) ? (int) $event['prefectureCode'] : 0,
			Event_Meta::ONLINE          => ! empty( $event['online'] ),
			Event_Meta::VENUE_NAME      => isset( $event['venueName'] ) ? (string) $event['venueName'] : '',
			Event_Meta::VENUE_ADDRESS   => isset( $event['venueAddress'] ) ? (string) $event['venueAddress'] : '',
			Event_Meta::URL             => isset( $event['publisherEventPageUrl'] ) ? (string) $event['publisherEventPageUrl'] : '',
			Sync_Queue::NO_SEND         => 1,
			Sync_Queue::IMPORT_SOURCE   => (string) $event['id'],
			// 途中で落ちても、結び付いていないと分かるよう作るときに入れる。中身は作った後に入れ直す。
			Sync_Queue::IMPORT_ORIGINAL => array(
				'content'   => '',
				'thumbnail' => 0,
			),
		);
		if ( $kept ) {
			$meta[ Sync_Queue::IMPORT_IMAGES ] = $kept;
		}

		return Sync_Queue::without_marking(
			static function () use ( $event, $images, $meta ) {
				$post_id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => Post_Type::POST_TYPE,
							'post_status'  => 'draft',
							// Sync_Payload::plain_title が実体参照を戻す。タグの形の文字を消さないため。
							'post_title'   => esc_html( (string) $event['title'] ),
							'post_content' => Markdown_Blocks::from_markdown( isset( $event['description'] ) ? (string) $event['description'] : '', $images['body'] ),
							'meta_input'   => $meta,
						)
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				if ( $images['featured'] ) {
					set_post_thumbnail( $post_id, $images['featured'] );
				}

				update_post_meta(
					$post_id,
					Sync_Queue::IMPORT_ORIGINAL,
					array(
						'content'   => self::content_digest( $post_id ),
						'thumbnail' => (int) $images['featured'],
					)
				);

				return $post_id;
			}
		);
	}

	/**
	 * 結び付けて、後始末をする。失敗しても下書きは残す。
	 *
	 * @param string $event_id 投稿の ID。
	 * @param int    $post_id  投稿の ID。
	 * @param bool   $is_own   このサイトが前に送った投稿か。内容と状態を変えない。
	 * @return array
	 */
	private static function link( $event_id, $post_id, $is_own ) {
		$result = ( new Api_Client() )->request(
			'POST',
			Sync_Queue::post_path( $post_id ) . '/link',
			array(
				'body'    => array( 'eventId' => $event_id ) + Site_Identity::urls(),
				'timeout' => self::TIMEOUT,
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::link_failed( $event_id, $post_id, $result );
		}

		$event = is_array( $result['body'] ) ? $result['body'] : array();
		// 結び付いた投稿は一覧から消える。押した時点の一覧に残すと、同じ投稿を続けて送られたときに切り離し中と読んで結び直す。
		self::forget_listed( $event_id );

		if ( $is_own ) {
			Sync_Queue::record_linked( $post_id, $event );
			// 切り離しの間に手元で変えた内容があるかもしれないので、次の更新で送る。
			delete_post_meta( $post_id, Sync_Queue::HASH );
			return self::imported( $event_id, $post_id );
		}

		$original  = get_post_meta( $post_id, Sync_Queue::IMPORT_ORIGINAL, true );
		$original  = is_array( $original ) ? $original : array(
			'content'   => '',
			'thumbnail' => 0,
		);
		$is_draft  = 'draft' === get_post_status( $post_id );
		$thumbnail = (int) get_post_thumbnail_id( $post_id );
		$touched   = ! $is_draft || $thumbnail !== (int) $original['thumbnail'] || self::content_digest( $post_id ) !== $original['content'];

		Sync_Queue::without_marking(
			static function () use ( $post_id, $event, $is_draft ) {
				delete_post_meta( $post_id, Sync_Queue::NO_SEND );
				// オーナーが選んだ状態は変えない。
				if ( $is_draft && ! empty( $event['visible'] ) ) {
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => 'publish',
						)
					);
				}
			}
		);

		if ( $thumbnail && $thumbnail === (int) $original['thumbnail'] ) {
			Sync_Image::record_imported( $post_id, $thumbnail, isset( $event['imageUploadId'] ) ? (string) $event['imageUploadId'] : '' );
		}
		Sync_Queue::record_linked( $post_id, $event );
		delete_post_meta( $post_id, Sync_Queue::IMPORT_ORIGINAL );

		// やり直しの前にオーナーが手を入れた内容は EventWatch に届いていない。オーナーが選んだ状態は送る。下書きのままなら審査から外さない。
		if ( $touched && ( ! $is_draft || ! empty( $event['visible'] ) ) ) {
			delete_post_meta( $post_id, Sync_Queue::HASH );
			Sync_Queue::mark( $post_id );
		}

		return self::imported( $event_id, $post_id );
	}

	/**
	 * 結び付けの失敗を、次の動きと文にする。
	 *
	 * @param string   $event_id 投稿の ID。
	 * @param int      $post_id  投稿の ID。
	 * @param WP_Error $error    失敗。
	 * @return array
	 */
	private static function link_failed( $event_id, $post_id, WP_Error $error ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		if ( Api_Client::RATE_LIMITED === $error->get_error_code() ) {
			$wait = isset( $data['retry_after'] ) ? (int) $data['retry_after'] : Api_Client::DEFAULT_RATE_LIMIT_WAIT;
			Sync_Queue::hold_for( $wait );
			return self::outcome( self::STATUS_WAIT, $event_id, array( 'retryAfter' => $wait ) );
		}

		if ( in_array( $error->get_error_code(), array( Api_Client::UNAUTHORIZED, Api_Client::SUSPENDED, Api_Client::FORBIDDEN, Api_Client::SITE_CHANGED, Api_Client::NO_KEY ), true ) ) {
			return self::outcome(
				self::STATUS_STOP,
				$event_id,
				array(
					'title'   => self::local_title( $event_id, $post_id ),
					'postId'  => (int) $post_id,
					'editUrl' => self::edit_url( $post_id ),
					'reason'  => self::stop_reason( $error->get_error_code(), isset( $data['code'] ) ? (string) $data['code'] : '' ),
				)
			);
		}

		return self::failed( $event_id, self::local_title( $event_id, $post_id ), self::link_reason( $error, $data ), $post_id );
	}

	/**
	 * キーやサイトの問題で止めるときの文。
	 *
	 * @param string $code     失敗のコード。
	 * @param string $api_code 応答の code。
	 * @return string
	 */
	public static function stop_reason( $code, $api_code = '' ) {
		switch ( $code ) {
			case Api_Client::SUSPENDED:
				return __( 'Your EventWatch organization is suspended. Contact EventWatch support.', 'eventwatch' );
			case Api_Client::FORBIDDEN:
				if ( Api_Client::CODE_SITE_BOUND === $api_code ) {
					return __( 'This site is still connected to EventWatch with another API key that hasn’t been revoked. Revoke the old key under Organization settings › Site connection on EventWatch, then try again.', 'eventwatch' );
				}
				return __( 'This API key can’t import posts. Issue a new key with EventWatch, then set it in Settings › EventWatch.', 'eventwatch' );
			case Api_Client::SITE_CHANGED:
				return __( 'The site URL has changed. Confirm whether this is a copy in Settings › EventWatch, then try again.', 'eventwatch' );
		}

		return __( 'The API key was rejected. Check it in Settings › EventWatch, then try again.', 'eventwatch' );
	}

	/**
	 * 結び付けに失敗した理由の文。原因と次の一手を書く。
	 *
	 * @param WP_Error $error 失敗。
	 * @param array    $data  失敗の data。
	 * @return string
	 */
	private static function link_reason( WP_Error $error, array $data ) {
		$code = isset( $data['code'] ) ? $data['code'] : '';

		if ( self::CODE_ALREADY_LINKED === $code ) {
			return __( 'Another site has already imported this post. Delete this draft if you don’t need it.', 'eventwatch' );
		}
		if ( self::CODE_IDENTIFIER === $code ) {
			return __( 'This draft was already sent to EventWatch as a separate post. Delete one of the two posts on EventWatch, then try again.', 'eventwatch' );
		}
		if ( Api_Client::NOT_FOUND === $error->get_error_code() ) {
			return __( 'This post is no longer on EventWatch. Delete this draft if you don’t need it.', 'eventwatch' );
		}
		if ( Api_Client::is_temporary( $error ) ) {
			return __( 'EventWatch didn’t respond. Wait a moment, then try again.', 'eventwatch' );
		}

		return __( 'EventWatch rejected this request. Try again later.', 'eventwatch' );
	}

	/**
	 * 送る内容の控え。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function content_digest( $post_id ) {
		$body = Sync_Payload::build( get_post( $post_id ) )['body'];
		// 送るサイトの URL は投稿の内容ではないので、手元で変えたと読まない。既定の公式ページはパーマリンクなので、移転で変わる。
		unset( $body['sourceUpdatedAt'], $body['siteUrl'], $body['adminUrl'] );

		return md5( (string) wp_json_encode( $body ) );
	}

	/**
	 * 成功の戻り。
	 *
	 * @param string $event_id 投稿の ID。
	 * @param int    $post_id  投稿の ID。
	 * @return array
	 */
	private static function imported( $event_id, $post_id ) {
		return self::outcome(
			self::STATUS_IMPORTED,
			$event_id,
			array(
				'title'      => self::local_title( $event_id, $post_id ),
				'postId'     => (int) $post_id,
				'editUrl'    => self::edit_url( $post_id ),
				'postStatus' => (string) get_post_status( $post_id ),
			)
		);
	}

	/**
	 * 失敗の戻り。
	 *
	 * @param string $event_id 投稿の ID。
	 * @param string $title    題。
	 * @param string $reason   理由。
	 * @param int    $post_id  残した下書きの ID。
	 * @return array
	 */
	private static function failed( $event_id, $title, $reason, $post_id = 0 ) {
		return self::outcome(
			self::STATUS_FAILED,
			$event_id,
			array(
				'title'   => $title,
				'postId'  => (int) $post_id,
				'editUrl' => $post_id ? self::edit_url( $post_id ) : '',
				'reason'  => $reason,
			)
		);
	}

	/**
	 * 戻りの形を揃える。
	 *
	 * @param string $status   状態。
	 * @param string $event_id 投稿の ID。
	 * @param array  $fields   欄。
	 * @return array
	 */
	private static function outcome( $status, $event_id, array $fields ) {
		return array_merge(
			array(
				'status'     => $status,
				'eventId'    => $event_id,
				'title'      => '',
				'postId'     => 0,
				'editUrl'    => '',
				'postStatus' => '',
				'reason'     => '',
				'retryAfter' => 0,
			),
			$fields
		);
	}

	/**
	 * 手元の投稿の題。
	 *
	 * @param string $event_id 投稿の ID。
	 * @param int    $post_id  投稿の ID。分からなければ 0。
	 * @return string
	 */
	private static function local_title( $event_id, $post_id = 0 ) {
		$post_id = $post_id ? $post_id : self::find_local( $event_id );

		return $post_id ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * 編集画面の URL。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function edit_url( $post_id ) {
		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	/**
	 * 押した時点の一覧（prepare() が置いたもの）から 1 件を外す。
	 *
	 * @param string $event_id 投稿の ID。
	 */
	private static function forget_listed( $event_id ) {
		$snapshot = get_transient( self::snapshot_key() );
		if ( is_array( $snapshot ) && isset( $snapshot[ $event_id ] ) ) {
			unset( $snapshot[ $event_id ] );
			set_transient( self::snapshot_key(), $snapshot, self::SNAPSHOT_TTL );
		}
	}

	/**
	 * この人の取り込みの内容を持つ transient の名前。
	 *
	 * @return string
	 */
	private static function snapshot_key() {
		return self::SNAPSHOT_PREFIX . get_current_user_id();
	}

	/**
	 * 錠の option の名前。
	 *
	 * @param string $event_id 投稿の ID。
	 * @return string
	 */
	private static function lock_key( $event_id ) {
		return self::LOCK_PREFIX . md5( $event_id );
	}
}
