<?php
/**
 * EventWatch への送信の待ち行列。
 *
 * @package EventWatch
 */

namespace EventWatch;

use Throwable;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 契機と内容を分ける。フックでは「送る必要がある」という印だけを積み、送るときに投稿を読み直す。
 * 最後の状態が勝つので、二重の発火や順序の入れ替わりに強い。
 *
 * 印は投稿の保護メタ、完全な削除は 1 件 1 行の option に持つ。投稿は消えるのでメタに置けず、
 * 行を分けると同時の更新で上書きし合わない。
 */
class Sync_Queue {

	const RUN_HOOK    = 'eventwatch_sync_run';
	const RESCAN_HOOK = 'eventwatch_sync_rescan';

	const PENDING  = '_eventwatch_sync_pending';
	const ATTEMPTS = '_eventwatch_sync_attempts';
	const NEXT_AT  = '_eventwatch_sync_next_at';
	const ERROR    = '_eventwatch_sync_error';
	const BLOCKS   = '_eventwatch_sync_blocks';
	const HASH     = '_eventwatch_sync_hash';
	const EXISTS   = '_eventwatch_sync_exists';
	const REMOTE   = '_eventwatch_sync_remote';
	const SENT_AT  = '_eventwatch_sync_sent_at';
	const NO_SEND  = '_eventwatch_no_send';

	const PULLED_AT = '_eventwatch_sync_pulled_at';
	// 送り直しても変わらない理由で断られた内容のハッシュと、その失敗の記録。
	const REFUSED = '_eventwatch_sync_refused';

	/**
	 * 取り込み元の EventWatch の投稿 ID。やり直しで 2 件目を作らないために持つ。
	 */
	const IMPORT_SOURCE = '_eventwatch_import_source';

	/**
	 * 本文に写した画像。添付の ID と EventWatch の宛先。送るときに宛先へ戻す。
	 */
	const IMPORT_IMAGES = '_eventwatch_import_images';

	/**
	 * 添付に持つ、元の画像の URL。やり直しで入れ直さないため。
	 */
	const IMPORT_IMAGE_SOURCE = '_eventwatch_import_image_source';

	/**
	 * 作ったときの内容の控え。結び付く前にオーナーが手を入れたかを見分ける。
	 */
	const IMPORT_ORIGINAL = '_eventwatch_import_original';

	const DELETE_PREFIX = 'eventwatch_sync_delete_';
	const HOLD_OPTION   = 'eventwatch_sync_hold_until';
	const LOCK_OPTION   = 'eventwatch_sync_lock';

	/**
	 * 最後に洗い直したときの home と siteurl の保存値のハッシュ。平文で持つと、URL を変える置換で一緒に書き換わり、違いが消える。
	 */
	const URLS_HASH_OPTION = 'eventwatch_rescanned_urls_hash';

	const PATH = '/api/v1.0/org-api/sites/';

	/**
	 * 保存の時点では、公開の操作を長く待たせない。
	 */
	const IMMEDIATE_TIMEOUT = 3;
	const IMMEDIATE_MAX     = 3;

	/**
	 * Cron の 1 回。流量の上限（60 回/分）に届かない数に絞り、次の回まで間を空ける。
	 */
	const RUN_TIMEOUT  = 10;
	const RUN_BUDGET   = 25;
	const RUN_MAX      = 40;
	const RUN_INTERVAL = 60;

	const LOCK_TTL = 300;

	/**
	 * 送り直しの間隔の秒数。最後の値を繰り返す。公開済みの投稿を黙って諦めないので、回数の上限は置かない。
	 */
	const BACKOFF = array( 60, 300, 1800, 7200, 21600 );

	const ACTION_NONE      = 'none';
	const ACTION_PUT       = 'put';
	const ACTION_UNPUBLISH = 'unpublish';

	const OUTCOME_SENT    = 'sent';
	const OUTCOME_SKIPPED = 'skipped';
	const OUTCOME_RETRY   = 'retry';
	const OUTCOME_HALTED  = 'halted';

	/**
	 * この要求で積んだ投稿の ID。
	 *
	 * @var int[]
	 */
	private static $marked = array();

	/**
	 * この要求で積んだ、消えた投稿の ID。
	 *
	 * @var int[]
	 */
	private static $deleted = array();

	/**
	 * 投稿の送信のほかに出した HTTP の数。流量の上限に数える。
	 *
	 * @var int
	 */
	private static $extra_requests = 0;

	/**
	 * 保存で印を積まない間か。
	 *
	 * @var bool
	 */
	private static $marking_paused = false;

	/**
	 * 同期のメタのキー。「送らない」は利用者の選択なので含めない。
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array( self::PENDING, self::ATTEMPTS, self::NEXT_AT, self::ERROR, self::BLOCKS, self::HASH, self::EXISTS, self::REMOTE, self::SENT_AT, self::PULLED_AT, self::REFUSED, self::IMPORT_SOURCE, self::IMPORT_IMAGES, self::IMPORT_IMAGE_SOURCE, self::IMPORT_ORIGINAL, Sync_Payload::PUBLISHED_AT_META, Sync_Image::RECORD, Sync_Image::ISSUE );
	}

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'wp_after_insert_post', array( self::class, 'on_after_insert' ), 10, 4 );
		add_action( 'before_delete_post', array( self::class, 'on_before_delete' ), 10, 2 );
		add_action( 'shutdown', array( self::class, 'on_shutdown' ) );
		add_action( self::RUN_HOOK, array( self::class, 'run' ) );
		add_action( self::RESCAN_HOOK, array( self::class, 'rescan' ) );
		add_filter( 'eventwatch_sync_pending_count', array( self::class, 'pending_count' ) );
		add_action( 'add_option_' . Connection::KEY_OPTION, array( self::class, 'resume' ), 10, 0 );
		add_action( 'update_option_' . Connection::KEY_OPTION, array( self::class, 'resume' ), 10, 0 );
		add_action( 'add_option_' . Post_Type::SLUG_OPTION, array( self::class, 'request_rescan' ), 10, 0 );
		add_action( 'update_option_' . Post_Type::SLUG_OPTION, array( self::class, 'request_rescan' ), 10, 0 );
		add_action( 'update_option_permalink_structure', array( self::class, 'request_rescan' ), 10, 0 );
		// 管理画面の URL とスキームだけの変更は移転の確認を通らないので、送るサイトの URL をここで送り直す。
		add_action( 'update_option_home', array( self::class, 'request_rescan' ), 10, 0 );
		add_action( 'update_option_siteurl', array( self::class, 'request_rescan' ), 10, 0 );
	}

	/**
	 * 保存の後に印を積む。メタとタームの保存の後に発火するので、内容はここまでに揃っている。
	 *
	 * @param int          $post_id     投稿の ID。
	 * @param WP_Post      $post        投稿。
	 * @param bool         $update      更新か。
	 * @param WP_Post|null $post_before 保存の前の投稿。
	 * @return void
	 */
	public static function on_after_insert( $post_id, $post, $update, $post_before ) {
		if ( Post_Type::POST_TYPE !== $post->post_type || self::$marking_paused ) {
			return;
		}

		// 本体の予約の公開は post_modified を進めない。取り下げと同じ時刻の PUT では、EventWatch が状態を変えない。
		if ( $post_before instanceof WP_Post && 'future' === $post_before->post_status && 'publish' === $post->post_status ) {
			update_post_meta( $post_id, Sync_Payload::PUBLISHED_AT_META, time() );
		}

		// 下書きの自動保存も投稿を更新する。公開でなかった投稿の保存で、取り下げの送り直しの待ちを縮めない。
		// 公開のままパスワードや「送らない」を付けた保存は待たない。内容が EventWatch に残るため。
		$keep_retry = $post_before instanceof WP_Post && 'publish' !== $post_before->post_status;

		self::mark( (int) $post_id, true, $keep_retry );
	}

	/**
	 * 保存で印を積まずに処理を動かす。取り込んだ内容をそのまま送り返さないため。
	 *
	 * @param callable $callback 処理。
	 * @return mixed 処理の戻り。
	 */
	public static function without_marking( callable $callback ) {
		$outer                = self::$marking_paused;
		self::$marking_paused = true;

		try {
			return $callback();
		} finally {
			self::$marking_paused = $outer;
		}
	}

	/**
	 * 完全な削除の前に、送る先を投稿の外へ積む。投稿のメタに置くと、ジョブが走る前に投稿ごと消える。
	 *
	 * @param int     $post_id 投稿の ID。
	 * @param WP_Post $post    投稿。
	 * @return void
	 */
	public static function on_before_delete( $post_id, $post ) {
		if ( Post_Type::POST_TYPE !== $post->post_type || ! get_post_meta( $post_id, self::EXISTS, true ) ) {
			return;
		}

		add_option(
			self::DELETE_PREFIX . (int) $post_id,
			array(
				'attempts' => 0,
				'next_at'  => 0,
				'error'    => '',
			),
			'',
			false
		);
		self::$deleted[] = (int) $post_id;
		self::schedule_next();
	}

	/**
	 * 投稿に印を積む。
	 *
	 * @param int  $post_id    投稿の ID。
	 * @param bool $schedule   次の実行を張るか。
	 * @param bool $keep_retry 届いていない取り下げの待ちを保つか。
	 * @return void
	 */
	public static function mark( $post_id, $schedule = true, $keep_retry = false ) {
		$post   = get_post( $post_id );
		$action = $post ? self::action_for( $post ) : self::ACTION_NONE;

		// キーなしのサイトにデータを増やさない。既に送ってある投稿は、キーを入れ直したときに追いつくよう残す。
		$unsendable = '' === Connection::api_key() && ! get_post_meta( $post_id, self::EXISTS, true );

		if ( self::ACTION_UNPUBLISH === $action && $keep_retry && get_post_meta( $post_id, self::PENDING, true ) && (int) get_post_meta( $post_id, self::NEXT_AT, true ) > time() ) {
			return;
		}

		delete_post_meta( $post_id, self::ATTEMPTS );
		delete_post_meta( $post_id, self::NEXT_AT );
		delete_post_meta( $post_id, self::ERROR );
		delete_post_meta( $post_id, self::BLOCKS );
		delete_post_meta( $post_id, Sync_Image::ISSUE );

		if ( self::ACTION_NONE === $action || $unsendable ) {
			delete_post_meta( $post_id, self::PENDING );
			return;
		}

		// 送信中に積み直されたことを、送信の後に値の違いで見分ける。
		update_post_meta( $post_id, self::PENDING, wp_generate_password( 16, false ) );
		self::$marked[] = (int) $post_id;

		if ( $schedule ) {
			self::schedule_next();
		}
	}

	/**
	 * いまの投稿の状態から、送る操作を決める。
	 *
	 * @param WP_Post $post 投稿。
	 * @return string
	 */
	public static function action_for( WP_Post $post ) {
		$exists = (bool) get_post_meta( $post->ID, self::EXISTS, true );

		if ( 'publish' === $post->post_status && '' === $post->post_password && ! get_post_meta( $post->ID, self::NO_SEND, true ) ) {
			return self::ACTION_PUT;
		}

		return $exists ? self::ACTION_UNPUBLISH : self::ACTION_NONE;
	}

	/**
	 * 要求の終わりに、この要求で積んだものを送る。サイトの表示の流れでは送らず、cron に任せる。
	 *
	 * @return void
	 */
	public static function on_shutdown() {
		if ( ! self::$marked && ! self::$deleted ) {
			return;
		}

		$can_send = is_admin() || wp_is_serving_rest_request() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( $can_send ) {
			self::send_marked();
		}
	}

	/**
	 * この要求で積んだものを、短いタイムアウトで 1 度だけ送る。失敗は cron に任せる。
	 *
	 * @return void
	 */
	public static function send_marked() {
		$targets = array();
		foreach ( array_unique( self::$deleted ) as $post_id ) {
			$targets[] = array( 'delete', $post_id );
		}
		foreach ( array_unique( self::$marked ) as $post_id ) {
			$targets[] = array( 'post', $post_id );
		}
		self::$marked  = array();
		self::$deleted = array();

		self::process( $targets, self::IMMEDIATE_TIMEOUT, self::IMMEDIATE_MAX, self::IMMEDIATE_TIMEOUT, false );
	}

	/**
	 * Cron の 1 回。時刻の来たものを送る。
	 *
	 * @return void
	 */
	public static function run() {
		$targets = self::due_targets();
		if ( ! $targets ) {
			// 時刻の前に手で動かされても、動いた予定はもう外れているので、送信待ちがあれば張り直す。
			self::schedule_next();
			return;
		}

		self::process( $targets, self::RUN_TIMEOUT, self::RUN_MAX, self::RUN_BUDGET, true );
	}

	/**
	 * 1 つだけ今すぐ送り直す。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	public static function resend( $post_id ) {
		delete_post_meta( $post_id, self::REFUSED );
		self::mark( $post_id, false );
		self::$marked = array( (int) $post_id );
		self::send_marked();
	}

	/**
	 * パーマリンクが変わった投稿を洗い直す cron を張る。
	 *
	 * 接頭辞を変えた同じ要求の中では get_permalink が古い定義のままなので、別の要求で行う。
	 *
	 * @return void
	 */
	public static function request_rescan() {
		if ( ! wp_next_scheduled( self::RESCAN_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::RESCAN_HOOK );
		}
	}

	/**
	 * 送ってある公開中の投稿に印を付ける。内容が同じものは HTTP を出さない。
	 *
	 * @return void
	 */
	public static function rescan() {
		// ここで印を付けた投稿は、いまの URL で送られる。
		update_option( self::URLS_HASH_OPTION, self::stored_urls_hash(), false );

		$post_ids = get_posts(
			array(
				'post_type'        => Post_Type::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery -- 送ってある投稿だけを引く。パーマリンクの変更のときだけ動く。
				'meta_key'         => self::EXISTS,
				'suppress_filters' => true,
			)
		);

		self::mark_many( array_map( 'intval', $post_ids ) );
	}

	/**
	 * home と siteurl の保存値が最後の洗い直しと違えば、洗い直しを張る。定期の取り込みから呼ぶ。
	 *
	 * URL を変える置換は option の更新のフックを通らず、スキームだけの違いは移転の確認も通らない。記録が無ければ
	 * いまの値を覚えるだけにする。この確かめを持たない版の間に変わった URL は拾えない。
	 *
	 * @return void
	 */
	public static function rescan_if_urls_changed() {
		$hash = self::stored_urls_hash();

		if ( add_option( self::URLS_HASH_OPTION, $hash, '', false ) ) {
			return;
		}
		if ( ! hash_equals( (string) get_option( self::URLS_HASH_OPTION ), $hash ) ) {
			self::request_rescan();
		}
	}

	/**
	 * home と siteurl の保存値のハッシュ。送る値（Site_Identity::urls()）ではなく、フィルターを通す前の値で比べる。
	 * WP_HOME を is_ssl() から、WP_SITEURL を Host ヘッダーから作るサイトでは送る値が要求ごとに変わり、
	 * 比べるたびに全件を洗い直す。保存値は要求では変わらない。
	 *
	 * @return string
	 */
	private static function stored_urls_hash() {
		$options = wp_load_alloptions();

		return hash( 'sha256', (string) wp_json_encode( array( $options['home'] ?? '', $options['siteurl'] ?? '' ) ) );
	}

	/**
	 * まとめて印を付け、次の cron に任せる。
	 *
	 * @param int[] $post_ids 投稿の ID。
	 * @return void
	 */
	public static function mark_many( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			self::mark( $post_id, false );
		}
		self::$marked = array();

		self::schedule_next();
	}

	/**
	 * まとめて送る操作の対象。公開中で、まだ送っていない、これから開催するイベント。
	 *
	 * 「送らない」と、結び付く前の下書きを公開したものは外す。後者を送ると EventWatch に別の投稿ができて、
	 * 結び付けのやり直しが断られる。キーの無い間に公開した投稿には送れない理由の記録が無いので、内容から確かめる。
	 *
	 * @return int[]
	 */
	public static function unsent_published_ids() {
		$posts = get_posts(
			array(
				'post_type'        => Post_Type::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'has_password'     => false,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery -- 設定画面と、まとめて送る操作でだけ引く。
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'     => self::NO_SEND,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::EXISTS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::PENDING,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::IMPORT_ORIGINAL,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Event_Meta::LAST_END,
						'value'   => Event_Dates::upcoming_boundary(),
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$post_ids = array();
		foreach ( $posts as $post ) {
			$built = Sync_Payload::build( $post );
			if ( ! $built['blocks'] ) {
				$post_ids[] = (int) $post->ID;
			}
		}

		return $post_ids;
	}

	/**
	 * まとめて送る操作を受けられるか。接続を直すまで送れない間は受けない。
	 *
	 * @return bool
	 */
	public static function can_send_unsent() {
		if ( '' === Connection::api_key() || Site_Identity::has_changed() ) {
			return false;
		}

		$state = Connection::state();

		return ! in_array( $state['status'], array( Connection::UNAUTHORIZED, Connection::SUSPENDED, Connection::FORBIDDEN ), true );
	}

	/**
	 * まとめて送る対象に印を付ける。
	 *
	 * @return int 印を付けた件数。
	 */
	public static function mark_unsent_published() {
		$post_ids = self::unsent_published_ids();
		self::mark_many( $post_ids );

		return count( $post_ids );
	}

	/**
	 * 止めていた送信を再開する。キーの保存・接続の確認の成功・移転の選択から呼ぶ。
	 *
	 * @return void
	 */
	public static function resume() {
		self::schedule_next( 0 );
	}

	/**
	 * 複製のサイトとして、元のサイトの送信の記録を消す。「送らない」の選択は残す。
	 *
	 * @return void
	 */
	public static function forget_all() {
		self::forget_import_no_send();

		foreach ( self::meta_keys() as $key ) {
			delete_post_meta_by_key( $key );
		}
		// 元のサイトの掲載を指しているので、複製に残さない。
		delete_post_meta_by_key( Event_Detail::LISTING_SLUG );

		foreach ( self::deletion_ids() as $post_id ) {
			delete_option( self::DELETE_PREFIX . $post_id );
		}
		delete_option( self::HOLD_OPTION );
		delete_option( self::URLS_HASH_OPTION );
		delete_option( Sync_Image::HOLD_OPTION );
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_clear_scheduled_hook( self::RESCAN_HOOK );
		wp_clear_scheduled_hook( Sync_Pull::RUN_HOOK );
		self::$marked  = array();
		self::$deleted = array();
	}

	/**
	 * 結び付く前の下書きの「送らない」を消す。取り込みが立てた印で、利用者の選択ではない。取り込みの記録を消す前に呼ぶ。
	 *
	 * @return void
	 */
	public static function forget_import_no_send() {
		$unlinked = get_posts(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'post_status'    => get_post_stati(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 複製の選択と削除でだけ引く。
				'meta_key'       => self::IMPORT_ORIGINAL,
			)
		);
		foreach ( $unlinked as $post_id ) {
			delete_post_meta( $post_id, self::NO_SEND );
		}
	}

	/**
	 * 送信待ちの件数。
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_query_meta_key -- 設定画面でだけ数える。数えるだけの API が無い。
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::PENDING ) );

		return $posts + count( self::deletion_ids() );
	}

	/**
	 * 次の cron を張り直す。送るものが無ければ外す。
	 *
	 * @param int $min_delay 今から空ける秒数。
	 * @return void
	 */
	public static function schedule_next( $min_delay = self::RUN_INTERVAL ) {
		$earliest = self::earliest_due();

		wp_clear_scheduled_hook( self::RUN_HOOK );
		if ( null === $earliest ) {
			return;
		}

		wp_schedule_single_event( max( $earliest, time() + $min_delay, (int) get_option( self::HOLD_OPTION, 0 ) ), self::RUN_HOOK );
	}

	/**
	 * 順に送る。錠で 1 本に絞る。
	 *
	 * @param array $targets 送るもの。
	 * @param int   $timeout 1 通のタイムアウトの秒数。
	 * @param int   $max     HTTP を出す上限。
	 * @param int   $budget  全体の秒数の上限。
	 * @param bool  $can_upload 画像を上げてよいか。
	 * @return void
	 */
	private static function process( array $targets, $timeout, $max, $budget, $can_upload ) {
		if ( ! $targets ) {
			return;
		}

		if ( (int) get_option( self::HOLD_OPTION, 0 ) > time() ) {
			self::schedule_next();
			return;
		}

		$lock = self::take_lock();
		if ( '' === $lock ) {
			// 動いている方が後で張り直すが、落ちたときに備えて残す。
			self::schedule_next();
			return;
		}

		$halted = false;
		$sent   = 0;
		$start  = time();

		try {
			foreach ( $targets as $target ) {
				if ( $sent >= $max || time() - $start >= $budget ) {
					break;
				}

				try {
					$outcome = 'delete' === $target[0] ? self::send_deletion( $target[1], $timeout ) : self::send_post( $target[1], $timeout, $can_upload );
				} catch ( Throwable $e ) {
					// ほかのプラグインのフィルターの例外など。この 1 件を後へ回し、残りを止めない。
					self::postpone_after_exception( $target );
					continue;
				}

				if ( self::OUTCOME_SKIPPED !== $outcome ) {
					++$sent;
				}
				$sent                += self::$extra_requests;
				self::$extra_requests = 0;
				if ( self::OUTCOME_HALTED === $outcome ) {
					$halted = true;
					break;
				}
				if ( self::OUTCOME_RETRY === $outcome ) {
					break;
				}
			}
		} finally {
			self::release_lock( $lock );
		}

		if ( $halted ) {
			// 時間では直らない。キーの保存・接続の確認・移転の選択・次の保存まで動かさない。
			wp_clear_scheduled_hook( self::RUN_HOOK );
			return;
		}

		self::schedule_next();
	}

	/**
	 * 例外で送れなかったものを、一時的な失敗と同じ間隔で後へ回す。
	 *
	 * @param array $target 送るもの。
	 * @return void
	 */
	private static function postpone_after_exception( array $target ) {
		$error = new WP_Error( Api_Client::TEMPORARY, 'Sending failed unexpectedly.' );

		if ( 'delete' === $target[0] ) {
			$row = get_option( self::DELETE_PREFIX . $target[1] );
			if ( is_array( $row ) ) {
				$row['attempts'] = (int) $row['attempts'] + 1;
				$row['next_at']  = self::next_attempt_at( $row['attempts'], $error );
				$row['error']    = $error->get_error_code();
				update_option( self::DELETE_PREFIX . $target[1], $row, false );
			}
			return;
		}

		$attempts = (int) get_post_meta( $target[1], self::ATTEMPTS, true ) + 1;
		update_post_meta( $target[1], self::ATTEMPTS, $attempts );
		update_post_meta( $target[1], self::NEXT_AT, self::next_attempt_at( $attempts, $error ) );
		update_post_meta( $target[1], self::ERROR, self::error_record( $error ) );
	}

	/**
	 * 投稿を 1 つ送る。
	 *
	 * @param int  $post_id    投稿の ID。
	 * @param int  $timeout    タイムアウトの秒数。
	 * @param bool $can_upload 画像を上げてよいか。
	 * @return string
	 */
	private static function send_post( $post_id, $timeout, $can_upload ) {
		$token = (string) get_post_meta( $post_id, self::PENDING, true );
		$post  = get_post( $post_id );

		if ( '' === $token ) {
			return self::OUTCOME_SKIPPED;
		}

		$action = $post && Post_Type::POST_TYPE === $post->post_type ? self::action_for( $post ) : self::ACTION_NONE;
		if ( self::ACTION_NONE === $action ) {
			self::clear_pending( $post_id, $token );
			return self::OUTCOME_SKIPPED;
		}

		$path     = self::post_path( $post_id );
		$stale_id = false;

		if ( self::ACTION_PUT === $action ) {
			$built = Sync_Payload::build( $post );
			if ( $built['blocks'] ) {
				update_post_meta( $post_id, self::BLOCKS, $built['blocks'] );
				self::clear_pending( $post_id, $token );
				return self::OUTCOME_SKIPPED;
			}

			// 送れない投稿のために投入量を使わないよう、止める理由を見た後で上げる。
			$image = Sync_Image::resolve( $post_id, $can_upload );
			if ( is_wp_error( $image ) ) {
				return self::handle_post_error( $post_id, $token, $action, $image, '' );
			}
			if ( $image['later'] ) {
				return self::OUTCOME_SKIPPED;
			}
			self::$extra_requests += $image['requests'];

			$method   = 'PUT';
			$body     = Sync_Payload::with_image( $built['body'], $image['id'] );
			$stale_id = '' !== $image['id'] && ! $image['fresh'];
		} else {
			$method = 'POST';
			$path  .= '/unpublish';
			$body   = array( 'sourceUpdatedAt' => Sync_Payload::source_updated_at( $post ) );
		}

		$hash = self::content_hash( $action, $body );
		if ( hash_equals( (string) get_post_meta( $post_id, self::HASH, true ), $hash ) && ! self::is_rejected( $post_id ) ) {
			self::clear_pending( $post_id, $token );
			return self::OUTCOME_SKIPPED;
		}

		// 同じ内容は同じ理由で断られる。メタボックスのある編集画面は 1 回の保存で 2 度保存するので、2 度目も送らない。
		// 積み直しで消えた失敗の記録は書き戻し、断られたままに見せる。
		$refused = get_post_meta( $post_id, self::REFUSED, true );
		if ( is_array( $refused ) && isset( $refused['hash'], $refused['error'] ) && hash_equals( (string) $refused['hash'], $hash ) ) {
			if ( delete_post_meta( $post_id, self::PENDING, $token ) ) {
				update_post_meta( $post_id, self::ERROR, $refused['error'] );
			}
			return self::OUTCOME_SKIPPED;
		}

		// 応答を失っても作られていることがあるので、送る前に立てる。後の取り下げを落とさない。
		update_post_meta( $post_id, self::EXISTS, 1 );
		// 応答を失うと、EventWatch の内容は前に届いたものと限らない。同じ内容の省略は、成功の後だけにする。
		delete_post_meta( $post_id, self::HASH );

		$result = ( new Api_Client() )->request(
			$method,
			$path,
			array(
				'body'    => $body,
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( $stale_id && self::rejected_without_fields( $result ) ) {
				// 回収・運営の削除・別の団体のキーで ID が使えなくなると、欄の名前の無い 400 になる。応答の文では見分けず、
				// 上げ直して送り直す。上げた直後の ID で同じ 400 なら画像のせいではないので、次は失敗として出る。
				Sync_Image::forget( $post_id );
				return self::OUTCOME_SENT;
			}
			return self::handle_post_error( $post_id, $token, $action, $result, $hash );
		}

		update_post_meta( $post_id, self::HASH, $hash );
		delete_post_meta( $post_id, self::REFUSED );
		update_post_meta( $post_id, self::SENT_AT, time() );
		// 送った直後ほど審査の結果が動くので、定期の取り込みの順番を先頭へ戻す。
		delete_post_meta( $post_id, self::PULLED_AT );
		if ( is_array( $result['body'] ) && isset( $result['body']['event'] ) && is_array( $result['body']['event'] ) ) {
			self::store_remote( $post_id, $result['body']['event'] );
			Sync_Image::after_put( $post_id, $result['body']['event'] );
		}
		self::clear_pending( $post_id, $token );
		update_option( Sync_Status::LAST_SYNC_OPTION, time() );

		return self::OUTCOME_SENT;
	}

	/**
	 * 断られた欄の名前の無い 400 か。
	 *
	 * @param WP_Error $error 失敗。
	 * @return bool
	 */
	private static function rejected_without_fields( WP_Error $error ) {
		$data = $error->get_error_data();

		return Api_Client::INVALID_REQUEST === $error->get_error_code() && is_array( $data ) && isset( $data['status'] ) && 400 === (int) $data['status'] && empty( $data['fields'] );
	}

	/**
	 * 投稿の送信の失敗を扱う。
	 *
	 * @param int      $post_id 投稿の ID。
	 * @param string   $token   送ったときの印の値。
	 * @param string   $action  操作。
	 * @param WP_Error $error   失敗。
	 * @param string   $hash    送った内容のハッシュ。送る前の失敗は空。
	 * @return string
	 */
	private static function handle_post_error( $post_id, $token, $action, WP_Error $error, $hash ) {
		if ( Api_Client::is_temporary( $error ) ) {
			$attempts = (int) get_post_meta( $post_id, self::ATTEMPTS, true ) + 1;
			update_post_meta( $post_id, self::ATTEMPTS, $attempts );
			update_post_meta( $post_id, self::NEXT_AT, self::next_attempt_at( $attempts, $error ) );
			update_post_meta( $post_id, self::ERROR, self::error_record( $error ) );
			return self::OUTCOME_RETRY;
		}

		if ( self::is_halting( $error ) ) {
			update_post_meta( $post_id, self::ERROR, self::error_record( $error ) );
			return self::OUTCOME_HALTED;
		}

		// 取り下げる先が無い。送信済みとして扱い、次に公開したら作り直す。
		if ( self::ACTION_UNPUBLISH === $action && Api_Client::NOT_FOUND === $error->get_error_code() ) {
			self::forget_remote( $post_id );
			self::clear_pending( $post_id, $token );
			return self::OUTCOME_SENT;
		}

		// 送り直しても変わらない。内容が変わるか、取り込みで状態が分かるか、今すぐ送り直すまで送らない。
		$record = self::error_record( $error );
		delete_post_meta( $post_id, self::HASH );
		delete_post_meta( $post_id, self::ATTEMPTS );
		delete_post_meta( $post_id, self::NEXT_AT );
		update_post_meta( $post_id, self::ERROR, $record );
		if ( '' === $hash ) {
			delete_post_meta( $post_id, self::REFUSED );
		} else {
			update_post_meta(
				$post_id,
				self::REFUSED,
				array(
					'hash'  => $hash,
					'error' => $record,
				)
			);
		}
		delete_post_meta( $post_id, self::PENDING, $token );

		return self::OUTCOME_SENT;
	}

	/**
	 * 消えた投稿の削除を送る。
	 *
	 * @param int $post_id 投稿の ID。
	 * @param int $timeout タイムアウトの秒数。
	 * @return string
	 */
	private static function send_deletion( $post_id, $timeout ) {
		$option = self::DELETE_PREFIX . $post_id;
		$row    = get_option( $option );

		if ( ! is_array( $row ) ) {
			return self::OUTCOME_SKIPPED;
		}

		$result = ( new Api_Client() )->request( 'DELETE', self::post_path( $post_id ), array( 'timeout' => $timeout ) );

		if ( ! is_wp_error( $result ) ) {
			delete_option( $option );
			update_option( Sync_Status::LAST_SYNC_OPTION, time() );
			return self::OUTCOME_SENT;
		}

		if ( Api_Client::is_temporary( $result ) ) {
			$row['attempts'] = (int) $row['attempts'] + 1;
			$row['next_at']  = self::next_attempt_at( $row['attempts'], $result );
			$row['error']    = $result->get_error_code();
			update_option( $option, $row, false );
			return self::OUTCOME_RETRY;
		}

		if ( self::is_halting( $result ) ) {
			$row['error'] = $result->get_error_code();
			update_option( $option, $row, false );
			return self::OUTCOME_HALTED;
		}

		// 404 は既に無い。ほかの断りは送り直しても変わらず、編集画面も無いので行を残さない。
		delete_option( $option );

		return self::OUTCOME_SENT;
	}

	/**
	 * 時間では直らず、その回の送信を止める失敗か。送信待ちは残し、回数に数えない。
	 *
	 * @param WP_Error $error 失敗。
	 * @return bool
	 */
	public static function is_halting( WP_Error $error ) {
		return in_array(
			$error->get_error_code(),
			array( Api_Client::NO_KEY, Api_Client::SITE_CHANGED, Api_Client::UNAUTHORIZED, Api_Client::SUSPENDED, Api_Client::FORBIDDEN ),
			true
		);
	}

	/**
	 * 次に送り直す時刻を決める。Retry-After があれば、サイト全体でその間は送らない。
	 *
	 * @param int      $attempts 失敗の回数。
	 * @param WP_Error $error    失敗。
	 * @return int
	 */
	private static function next_attempt_at( $attempts, WP_Error $error ) {
		$data        = $error->get_error_data();
		$retry_after = is_array( $data ) && isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0;
		$index       = min( $attempts, count( self::BACKOFF ) ) - 1;
		$wait        = (int) ( self::BACKOFF[ $index ] * ( 1 + wp_rand( 0, 20 ) / 100 ) );

		if ( $retry_after > 0 ) {
			self::hold_for( $retry_after );
		}

		return time() + max( $wait, $retry_after );
	}

	/**
	 * サイト全体で、その間は EventWatch を呼ばない。
	 *
	 * @param int $seconds 秒数。
	 * @return void
	 */
	public static function hold_for( $seconds ) {
		update_option( self::HOLD_OPTION, time() + $seconds, false );
	}

	/**
	 * 残す失敗の記録。文は持たず、コードから編集画面で作る。
	 *
	 * @param WP_Error $error 失敗。
	 * @return array{code: string, apiCode: string, status: int, fields: string[]}
	 */
	private static function error_record( WP_Error $error ) {
		$data = $error->get_error_data();

		return array(
			'code'    => $error->get_error_code(),
			'apiCode' => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
			'status'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0,
			'fields'  => is_array( $data ) && isset( $data['fields'] ) ? $data['fields'] : array(),
		);
	}

	/**
	 * 印を外す。送信中に積み直されていたら外さない。
	 *
	 * @param int    $post_id 投稿の ID。
	 * @param string $token   送ったときの印の値。
	 * @return void
	 */
	private static function clear_pending( $post_id, $token ) {
		if ( delete_post_meta( $post_id, self::PENDING, $token ) ) {
			delete_post_meta( $post_id, self::ATTEMPTS );
			delete_post_meta( $post_id, self::NEXT_AT );
			delete_post_meta( $post_id, self::ERROR );
		}
	}

	/**
	 * 直前の応答が差し戻しか。差し戻しの再提出の契機は更新なので、内容が同じでも送る。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return bool
	 */
	private static function is_rejected( $post_id ) {
		$remote = get_post_meta( $post_id, self::REMOTE, true );

		return is_array( $remote ) && isset( $remote['status'] ) && 'rejected' === $remote['status'];
	}

	/**
	 * 送った内容の同一性。更新日時は含めない。キーの指紋を含めるのは、キーの入れ替えの再接続を止めないため。
	 *
	 * @param string $action 操作。
	 * @param array  $body   本文。
	 * @return string
	 */
	private static function content_hash( $action, array $body ) {
		unset( $body['sourceUpdatedAt'] );

		return hash( 'sha256', (string) wp_json_encode( array( $action, $body, Site_Identity::id(), Connection::fingerprint() ) ) );
	}

	/**
	 * 応答の投稿を手元に書く。送信と取り込みの両方がここを通る。
	 *
	 * 「気になる」は表示のたびに EventWatch を読まないので、掲載中の間だけスラッグを持つ。運営が止めた投稿は
	 * status が published のままなので、visible で見る。
	 *
	 * @param int   $post_id 投稿の ID。
	 * @param array $event   応答の投稿。
	 * @return void
	 */
	public static function store_remote( $post_id, array $event ) {
		$remote = self::remote_from_event( $event );

		update_post_meta( $post_id, self::REMOTE, $remote );
		if ( $remote['visible'] && '' !== $remote['slug'] ) {
			update_post_meta( $post_id, Event_Detail::LISTING_SLUG, $remote['slug'] );
		} else {
			delete_post_meta( $post_id, Event_Detail::LISTING_SLUG );
		}

		Sync_Pull::schedule();
	}

	/**
	 * 結び付けの応答を手元に書き、いまの内容を送ったものとして覚える。
	 *
	 * 下書きの覚えは取り下げの内容にする。下書きのまま保存しただけで、審査待ちの投稿を審査から外さないため。
	 * 応答を受けた後に公開して内容が同じなら、次の洗い直しでも送らない。
	 *
	 * @param int   $post_id 投稿の ID。
	 * @param array $event   応答の投稿。
	 * @return void
	 */
	public static function record_linked( $post_id, array $event ) {
		update_post_meta( $post_id, self::EXISTS, 1 );
		update_post_meta( $post_id, self::SENT_AT, time() );
		delete_post_meta( $post_id, self::PULLED_AT );
		self::store_remote( $post_id, $event );

		$post   = get_post( $post_id );
		$action = $post ? self::action_for( $post ) : self::ACTION_NONE;
		$body   = array();

		if ( self::ACTION_PUT === $action ) {
			$built = Sync_Payload::build( $post );
			if ( $built['blocks'] ) {
				delete_post_meta( $post_id, self::HASH );
				return;
			}
			$body = Sync_Payload::with_image( $built['body'], isset( $event['imageUploadId'] ) ? (string) $event['imageUploadId'] : '' );
		}

		update_post_meta( $post_id, self::HASH, self::content_hash( $action, $body ) );
	}

	/**
	 * EventWatch に無いと分かった投稿の記録を消す。次に公開したら作り直しに行く。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	public static function forget_remote( $post_id ) {
		delete_post_meta( $post_id, self::EXISTS );
		delete_post_meta( $post_id, self::PULLED_AT );
		// 作り直す投稿は本文の画像を参照していないので、宛先を送ると断られる。取り込みのやり直しの対象でもなくなる。
		delete_post_meta( $post_id, self::IMPORT_SOURCE );
		delete_post_meta( $post_id, self::IMPORT_IMAGES );
		delete_post_meta( $post_id, self::IMPORT_ORIGINAL );
		// 取り下げる先が無いので、投入画像も回収される。取り込みの 404 は別の団体のキーでも起きるので、ここだけで消す。
		Sync_Image::forget( $post_id );
		self::clear_remote( $post_id );
	}

	/**
	 * 分かっていた状態を消す。同じ内容でも次の更新で送る。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	public static function clear_remote( $post_id ) {
		delete_post_meta( $post_id, self::HASH );
		delete_post_meta( $post_id, self::REFUSED );
		delete_post_meta( $post_id, self::REMOTE );
		delete_post_meta( $post_id, Event_Detail::LISTING_SLUG );
	}

	/**
	 * 応答の投稿から、持っておく値だけを取り出す。
	 *
	 * @param array $event 応答の投稿。
	 * @return array
	 */
	public static function remote_from_event( array $event ) {
		return array(
			'status'          => isset( $event['status'] ) ? sanitize_text_field( (string) $event['status'] ) : '',
			'visible'         => ! empty( $event['visible'] ),
			'suspended'       => ! empty( $event['suspendedByOperator'] ),
			'rejectionReason' => isset( $event['rejectionReason'] ) ? sanitize_textarea_field( (string) $event['rejectionReason'] ) : '',
			'slug'            => isset( $event['slug'] ) ? sanitize_text_field( (string) $event['slug'] ) : '',
		);
	}

	/**
	 * 投稿の口のパス。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	public static function post_path( $post_id ) {
		return self::PATH . rawurlencode( Site_Identity::id() ) . '/events/' . (int) $post_id;
	}

	/**
	 * 時刻の来たものを、早い順に引く。
	 *
	 * @return array
	 */
	private static function due_targets() {
		global $wpdb;

		$targets = array();
		foreach ( self::deletion_ids() as $post_id ) {
			$row = get_option( self::DELETE_PREFIX . $post_id );
			if ( is_array( $row ) && (int) $row['next_at'] <= time() ) {
				$targets[] = array( 'delete', $post_id );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 値の比較と並べ替えを 1 文で行う。結果を覚えると送信待ちを読み違える。
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.post_id FROM {$wpdb->postmeta} p LEFT JOIN {$wpdb->postmeta} n ON n.post_id = p.post_id AND n.meta_key = %s WHERE p.meta_key = %s AND COALESCE( CAST( n.meta_value AS UNSIGNED ), 0 ) <= %d ORDER BY COALESCE( CAST( n.meta_value AS UNSIGNED ), 0 ), p.post_id LIMIT %d",
				self::NEXT_AT,
				self::PENDING,
				time(),
				self::RUN_MAX * 5
			)
		);

		foreach ( $post_ids as $post_id ) {
			$targets[] = array( 'post', (int) $post_id );
		}

		return $targets;
	}

	/**
	 * いちばん早く送る時刻。送るものが無ければ null。
	 *
	 * @return int|null
	 */
	private static function earliest_due() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 値の最小を 1 文で引く。結果を覚えると送信待ちを読み違える。
		$earliest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN( COALESCE( CAST( n.meta_value AS UNSIGNED ), 0 ) ) FROM {$wpdb->postmeta} p LEFT JOIN {$wpdb->postmeta} n ON n.post_id = p.post_id AND n.meta_key = %s WHERE p.meta_key = %s",
				self::NEXT_AT,
				self::PENDING
			)
		);
		$earliest = null === $earliest ? null : (int) $earliest;

		foreach ( self::deletion_ids() as $post_id ) {
			$row      = get_option( self::DELETE_PREFIX . $post_id );
			$next_at  = is_array( $row ) ? (int) $row['next_at'] : 0;
			$earliest = null === $earliest ? $next_at : min( $earliest, $next_at );
		}

		return $earliest;
	}

	/**
	 * 削除の行の投稿 ID。
	 *
	 * @return int[]
	 */
	private static function deletion_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 接頭辞で引く API が無い。
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::DELETE_PREFIX ) . '%' ) );

		return array_map(
			static function ( $name ) {
				return (int) substr( $name, strlen( self::DELETE_PREFIX ) );
			},
			$names
		);
	}

	/**
	 * 錠を取る。add_option は ON DUPLICATE KEY UPDATE で書くので、同時に呼ぶと両方が成功する。
	 *
	 * @param string $name 錠の option の名前。
	 * @return string 取れたら錠の値。取れなければ空。
	 */
	public static function take_lock( $name = self::LOCK_OPTION ) {
		global $wpdb;

		$value = time() . '|' . wp_generate_password( 12, false );

		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 行の有無を排他の判定に使う。
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
			if ( 1 === (int) $wpdb->rows_affected ) {
				return $value;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 書いたばかりの行を読む。覚えた値は使えない。
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

			if ( '' !== $current && time() - (int) $current < self::LOCK_TTL ) {
				return '';
			}

			// 落ちた実行の錠。読んだ値のときだけ消し、ほかが取り直した錠を消さない。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 値を条件にして消す API が無い。
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $current ) );
		}

		return '';
	}

	/**
	 * 錠を返す。
	 *
	 * @param string $value 錠の値。
	 * @param string $name  錠の option の名前。
	 * @return void
	 */
	public static function release_lock( $value, $name = self::LOCK_OPTION ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 値を条件にして消す API が無い。
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
	}
}
