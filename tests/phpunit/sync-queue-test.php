<?php
/**
 * 送信の待ち行列のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- 錠と更新日時の行を、API を通さずに作る。

use EventWatch\Capabilities;
use EventWatch\Connection;
use EventWatch\Event_Detail;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Payload;
use EventWatch\Sync_Pull;
use EventWatch\Sync_Queue;
use EventWatch\Sync_Status;
use WP_UnitTestCase;

/**
 * Sync_Queue のテスト。HTTP は pre_http_request で返す。
 */
class Sync_Queue_Test extends WP_UnitTestCase {

	const KEY = 'ewk_0123456789012345678901234567890123456789012';

	/**
	 * 受けた要求。
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * 次の応答。空なら 200。
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * 応答の前に動かす処理。
	 *
	 * @var callable|null
	 */
	private $before_response = null;

	/**
	 * テストごとにメタを登録し、キーを入れて HTTP を差し替える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		Post_Type::register();
		update_option( Connection::KEY_OPTION, self::KEY );
		Site_Identity::use_as_moved();
		wp_clear_scheduled_hook( Sync_Queue::RESCAN_HOOK );
		wp_clear_scheduled_hook( Sync_Queue::RUN_HOOK );
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * 差し替えを戻す。
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		Sync_Queue::forget_all();
		delete_option( Sync_Queue::LOCK_OPTION );
		parent::tear_down();
	}

	/**
	 * 要求を記録して応答を返す。
	 *
	 * @param mixed  $preempt 本体の値。
	 * @param array  $args    要求。
	 * @param string $url     URL。
	 * @return array
	 */
	public function answer( $preempt, $args, $url ) {
		$this->requests[] = array(
			'method'  => $args['method'],
			'path'    => substr( $url, strlen( 'https://api.eventwatch.app' ) ),
			'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			'timeout' => $args['timeout'],
			'key'     => $args['headers']['X-EW-Api-Key'],
		);

		if ( $this->before_response ) {
			$run                   = $this->before_response;
			$this->before_response = null;
			$run();
		}

		return $this->responses ? array_shift( $this->responses ) : $this->ok( 'pendingReview' );
	}

	/**
	 * 成功の応答。
	 *
	 * @param string $status 投稿の状態。
	 * @param bool   $applied 反映したか。
	 * @return array
	 */
	private function ok( $status, $applied = true ) {
		return $this->response(
			200,
			array(
				'applied' => $applied,
				'event'   => array(
					'status'              => $status,
					'visible'             => 'published' === $status,
					'suspendedByOperator' => false,
					'rejectionReason'     => 'rejected' === $status ? '時刻を直してください' : null,
					'slug'                => 'abc',
				),
			)
		);
	}

	/**
	 * 応答を作る。
	 *
	 * @param int        $status  ステータス。
	 * @param array|null $body    本文。
	 * @param array      $headers ヘッダー。
	 * @return array
	 */
	private function response( $status, $body = null, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 送れる内容のイベントを作る。作った時点の印は送らずに積んだまま。
	 *
	 * @param array $post 上書きする投稿の値。
	 * @return int
	 */
	private function event( array $post = array() ) {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'draft',
					'post_title'   => '題',
					'post_content' => '<p>本文</p>',
				),
				$post
			)
		);
		update_post_meta(
			$id,
			Event_Meta::OCCURRENCES,
			array(
				array(
					'start' => '2026-12-01 10:00',
					'end'   => '',
				),
			)
		);
		update_post_meta( $id, Event_Meta::CATEGORY, 3 );
		update_post_meta( $id, Event_Meta::PREFECTURE, 13 );

		return $id;
	}

	/**
	 * 公開して送る。
	 *
	 * @return int
	 */
	private function sent_event() {
		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();
		$this->requests = array();

		return $id;
	}

	/**
	 * 受けた要求を「メソッド パス」の形で並べる。
	 *
	 * @return string[]
	 */
	private function sent() {
		return array_map(
			static function ( $request ) {
				return $request['method'] . ' ' . preg_replace( '#^/api/v1\.0/org-api/sites/[^/]+#', '', $request['path'] );
			},
			$this->requests
		);
	}

	/**
	 * 公開で PUT を 1 通送る。パスの識別子と本文を確かめる。
	 */
	public function test_publish_sends_put_once() {
		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'PUT', $this->requests[0]['method'] );
		$this->assertSame( '/api/v1.0/org-api/sites/' . Site_Identity::id() . '/events/' . $id, $this->requests[0]['path'] );
		$this->assertSame( '題', $this->requests[0]['body']['title'] );
		$this->assertSame( (float) Sync_Queue::IMMEDIATE_TIMEOUT, (float) $this->requests[0]['timeout'] );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertGreaterThan( 0, Sync_Status::last_success() );
	}

	/**
	 * 同じ要求の中で何度保存しても 1 通。
	 */
	public function test_two_saves_in_a_request_send_once() {
		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => '題 2',
			)
		);
		Sync_Queue::send_marked();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( '題 2', $this->requests[0]['body']['title'] );
	}

	/**
	 * 別の要求で同じ内容をもう一度保存しても送らない（旧式のメタボックスの 2 回目の更新）。
	 */
	public function test_second_save_with_same_content_sends_nothing() {
		$id = $this->sent_event();
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * メタだけの変更も送る。
	 */
	public function test_meta_only_change_is_sent() {
		$id = $this->sent_event();
		update_post_meta( $id, Event_Meta::VENUE_NAME, '新しい会場' );
		update_post_meta( $id, Event_Meta::VENUE_ADDRESS, '新しい住所' );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertSame( '新しい会場', $this->requests[0]['body']['venueName'] );
	}

	/**
	 * 差し戻しを取り込んだ後は、同じ内容でも送る。再提出の契機が更新のため。
	 */
	public function test_rejected_post_is_resent_with_same_content() {
		$id     = $this->sent_event();
		$remote = get_post_meta( $id, Sync_Queue::REMOTE, true );
		update_post_meta( $id, Sync_Queue::REMOTE, array_merge( $remote, array( 'status' => 'rejected' ) ) );

		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		// camelCase の語のまま持つ。
		$this->assertSame( 'pendingReview', get_post_meta( $id, Sync_Queue::REMOTE, true )['status'] );
	}

	/**
	 * 応答を失った後は、前に届いた内容へ戻しても送る。失った要求が反映されていることがある。
	 */
	public function test_returning_to_previous_content_after_lost_response_is_sent() {
		$id                = $this->sent_event();
		$this->responses[] = new \WP_Error( 'http_request_failed', 'timeout' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );

		$this->requests = array();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
	}

	/**
	 * 送信中の例外は、その 1 件を後へ回し、残りを送る。錠も返し、次の実行も張る。
	 */
	public function test_exception_postpones_one_and_continues() {
		global $wpdb;

		$bad  = $this->event();
		$good = $this->event();
		foreach ( array( $bad, $good ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		$throw = static function ( $url, $post ) use ( $bad ) {
			if ( $bad === $post->ID ) {
				throw new \RuntimeException( 'another plugin failed' );
			}
			return $url;
		};
		add_filter( 'post_type_link', $throw, 10, 2 );
		Sync_Queue::send_marked();
		remove_filter( 'post_type_link', $throw, 10 );

		$this->assertSame( array( "PUT /events/$good" ), $this->sent() );
		$this->assertNotSame( '', (string) get_post_meta( $bad, Sync_Queue::PENDING, true ) );
		$this->assertSame( 1, (int) get_post_meta( $bad, Sync_Queue::ATTEMPTS, true ) );
		$this->assertGreaterThan( time(), (int) get_post_meta( $bad, Sync_Queue::NEXT_AT, true ) );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Sync_Queue::LOCK_OPTION ) ) );
	}

	/**
	 * 送らない状態。
	 *
	 * @return array
	 */
	public function unsent_statuses() {
		return array(
			'下書き'    => array( 'draft', '' ),
			'レビュー待ち' => array( 'pending', '' ),
			'予約'     => array( 'future', '' ),
			'非公開'    => array( 'private', '' ),
			'パスワード'  => array( 'publish', 'secret' ),
		);
	}

	/**
	 * まだ送っていない投稿は、公開でない間は何も送らない。
	 *
	 * @dataProvider unsent_statuses
	 *
	 * @param string $status   状態。
	 * @param string $password パスワード。
	 */
	public function test_never_sent_post_is_not_sent( $status, $password ) {
		$args = array(
			'post_status'   => $status,
			'post_password' => $password,
		);
		if ( 'future' === $status ) {
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			$args['post_date_gmt'] = $args['post_date'];
		}
		$id = $this->event( $args );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 公開をやめる操作。
	 *
	 * @return array
	 */
	public function withdrawals() {
		return array(
			'下書き'    => array( array( 'post_status' => 'draft' ) ),
			'レビュー待ち' => array( array( 'post_status' => 'pending' ) ),
			'非公開'    => array( array( 'post_status' => 'private' ) ),
			'パスワード'  => array( array( 'post_password' => 'secret' ) ),
			'予約へ戻す'  => array(
				array(
					'post_status'   => 'future',
					'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				),
			),
		);
	}

	/**
	 * 公開をやめると取り下げを送る。
	 *
	 * @dataProvider withdrawals
	 *
	 * @param array $change 変更。
	 */
	public function test_withdrawal_sends_unpublish( $change ) {
		$id                = $this->sent_event();
		$this->responses[] = $this->ok( 'private' );
		wp_update_post( array_merge( array( 'ID' => $id ), $change ) );
		Sync_Queue::send_marked();

		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );
		$this->assertSame( Sync_Payload::source_updated_at( get_post( $id ) ), $this->requests[0]['body']['sourceUpdatedAt'] );
		$this->assertSame( 'private', get_post_meta( $id, Sync_Queue::REMOTE, true )['status'] );
	}

	/**
	 * ゴミ箱で取り下げ、戻しても送らない。再び公開すると PUT。
	 */
	public function test_trash_and_restore() {
		$id                = $this->sent_event();
		$this->responses[] = $this->ok( 'private' );
		wp_trash_post( $id );
		Sync_Queue::send_marked();
		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );

		$this->requests = array();
		wp_untrash_post( $id );
		$this->assertSame( 'draft', get_post_status( $id ) );
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests );

		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
	}

	/**
	 * 予約の公開で PUT を送る。更新日時を進めない本体の経路でも、取り下げより新しい時刻を送る。
	 */
	public function test_scheduled_publish_sends_put_with_newer_time() {
		$id                = $this->sent_event();
		$this->responses[] = $this->ok( 'private' );
		$future            = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => 'future',
				'post_date'     => $future,
				'post_date_gmt' => $future,
			)
		);
		Sync_Queue::send_marked();
		$withdrawn = $this->requests[0]['body']['sourceUpdatedAt'];

		// 更新日時を過去へ戻し、公開の時刻だけが新しい状態を作る。
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - 100 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified_gmt' => $old,
				'post_modified'     => $old,
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		$this->requests = array();
		wp_publish_post( $id );
		Sync_Queue::send_marked();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertGreaterThanOrEqual( strtotime( $withdrawn ), strtotime( $this->requests[0]['body']['sourceUpdatedAt'] ) );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s', (int) get_post_meta( $id, Sync_Payload::PUBLISHED_AT_META, true ) ) . '+00:00', $this->requests[0]['body']['sourceUpdatedAt'] );
	}

	/**
	 * 完全に削除すると、投稿が消えた後に DELETE を送る。
	 */
	public function test_permanent_delete_sends_delete_after_post_is_gone() {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 204 );
		wp_delete_post( $id, true );

		$this->assertNull( get_post( $id ) );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 1, Sync_Queue::pending_count() );

		Sync_Queue::send_marked();

		$this->assertSame( array( "DELETE /events/$id" ), $this->sent() );
		$this->assertSame( 0, Sync_Queue::pending_count() );
	}

	/**
	 * 送っていない投稿の削除は送らない。
	 */
	public function test_delete_of_never_sent_post_sends_nothing() {
		$id = $this->event();
		wp_delete_post( $id, true );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( 0, Sync_Queue::pending_count() );
	}

	/**
	 * 削除の送信の失敗は cron で送り直し、404 は済んだものとして扱う。
	 */
	public function test_delete_retries_and_treats_404_as_done() {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 503 );
		wp_delete_post( $id, true );
		Sync_Queue::send_marked();

		$this->assertSame( 1, Sync_Queue::pending_count() );
		$row = get_option( Sync_Queue::DELETE_PREFIX . $id );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertGreaterThan( time(), $row['next_at'] );

		// 時刻が来るまでは送らない。
		$this->requests = array();
		Sync_Queue::run();
		$this->assertSame( array(), $this->requests );

		$row['next_at'] = time() - 1;
		update_option( Sync_Queue::DELETE_PREFIX . $id, $row, false );
		$this->responses[] = $this->response( 404 );
		Sync_Queue::run();

		$this->assertSame( array( "DELETE /events/$id" ), $this->sent() );
		$this->assertSame( 0, Sync_Queue::pending_count() );
	}

	/**
	 * 一時的な失敗は印を残して間隔を空ける。復旧後に送り直す。公開は止まらない。
	 */
	public function test_temporary_failure_retries_with_backoff() {
		$id                = $this->event();
		$this->responses[] = new \WP_Error( 'http_request_failed', 'timeout' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( 1, (int) get_post_meta( $id, Sync_Queue::ATTEMPTS, true ) );
		$next = (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true );
		$this->assertGreaterThanOrEqual( time() + 60, $next );
		$this->assertLessThanOrEqual( time() + 72, $next );
		$this->assertSame( Sync_Queue::RUN_HOOK, wp_get_scheduled_event( Sync_Queue::RUN_HOOK )->hook );
		$this->assertSame( 'eventwatch_temporary', get_post_meta( $id, Sync_Queue::ERROR, true )['code'] );

		// 時刻が来るまでは送らない。
		$this->requests = array();
		Sync_Queue::run();
		$this->assertSame( array(), $this->requests );

		update_post_meta( $id, Sync_Queue::NEXT_AT, time() - 1 );
		Sync_Queue::run();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertSame( (float) Sync_Queue::RUN_TIMEOUT, (float) $this->requests[0]['timeout'] );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::ERROR, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::ATTEMPTS, true ) );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 送り直しの時刻の前に cron が動いても、送信待ちの投稿があれば次の cron が残る。
	 */
	public function test_run_before_retry_time_keeps_next_run() {
		$id                = $this->event();
		$this->responses[] = $this->response( 503 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();
		$next = (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true );
		$this->assertGreaterThan( time(), $next );

		// cron は動かす予定を先に外す。
		wp_clear_scheduled_hook( Sync_Queue::RUN_HOOK );
		$this->requests = array();
		Sync_Queue::run();

		$this->assertSame( array(), $this->requests );
		$event = wp_get_scheduled_event( Sync_Queue::RUN_HOOK );
		$this->assertNotFalse( $event );
		$this->assertGreaterThanOrEqual( $next, $event->timestamp );
	}

	/**
	 * 削除の送り直しの時刻の前に cron が動いても、次の cron が残る。
	 */
	public function test_run_before_delete_retry_time_keeps_next_run() {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 503 );
		wp_delete_post( $id, true );
		Sync_Queue::send_marked();
		$next = (int) get_option( Sync_Queue::DELETE_PREFIX . $id )['next_at'];
		$this->assertGreaterThan( time(), $next );

		wp_clear_scheduled_hook( Sync_Queue::RUN_HOOK );
		$this->requests = array();
		Sync_Queue::run();

		$this->assertSame( array(), $this->requests );
		$event = wp_get_scheduled_event( Sync_Queue::RUN_HOOK );
		$this->assertNotFalse( $event );
		$this->assertGreaterThanOrEqual( $next, $event->timestamp );
	}

	/**
	 * 送信待ちが無ければ、cron が動いても次の cron は張らない。
	 */
	public function test_run_without_pending_does_not_schedule() {
		$this->assertSame( 0, Sync_Queue::pending_count() );

		Sync_Queue::run();

		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 間隔は延び、最後の値を繰り返す。回数の上限で諦めない。
	 */
	public function test_backoff_grows_and_never_gives_up() {
		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->responses[] = $this->response( 500 );
		Sync_Queue::send_marked();

		foreach ( array( 300, 1800, 7200, 21600, 21600, 21600 ) as $wait ) {
			update_post_meta( $id, Sync_Queue::NEXT_AT, time() - 1 );
			$this->responses[] = $this->response( 500 );
			Sync_Queue::run();
			$next = (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true );
			$this->assertGreaterThanOrEqual( time() + $wait, $next );
			$this->assertLessThanOrEqual( time() + (int) ( $wait * 1.2 ) + 1, $next );
		}

		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 429 は Retry-After の間、サイト全体で送らない。一時的な失敗でその回を止める。
	 */
	public function test_rate_limit_holds_the_whole_site() {
		$first  = $this->event();
		$second = $this->event();
		foreach ( array( $first, $second ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		$this->responses[] = $this->response( 429, null, array( 'retry-after' => '600' ) );
		Sync_Queue::send_marked();

		$this->assertCount( 1, $this->requests );
		$this->assertGreaterThanOrEqual( time() + 599, (int) get_option( Sync_Queue::HOLD_OPTION ) );
		$this->assertGreaterThanOrEqual( time() + 599, wp_next_scheduled( Sync_Queue::RUN_HOOK ) );

		// 待ちの間は、時刻の来ている別の投稿も、保存の時点の送信も出さない。
		$this->requests = array();
		Sync_Queue::run();
		wp_update_post(
			array(
				'ID'         => $second,
				'post_title' => '題 3',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests );

		// 待ちが過ぎれば送る。
		update_option( Sync_Queue::HOLD_OPTION, time() - 1, false );
		update_post_meta( $first, Sync_Queue::NEXT_AT, time() - 1 );
		Sync_Queue::run();
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * 503 の Retry-After もサイト全体の待ちにする。送り直しの間隔を下回らない。
	 */
	public function test_retry_after_of_503_is_respected() {
		$id                = $this->event();
		$this->responses[] = $this->response( 503, null, array( 'retry-after' => '900' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertGreaterThanOrEqual( time() + 899, (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true ) );
		$this->assertGreaterThanOrEqual( time() + 899, (int) get_option( Sync_Queue::HOLD_OPTION ) );
	}

	/**
	 * 送り直さない失敗。
	 *
	 * @return array
	 */
	public function final_failures() {
		return array(
			'検証エラー'   => array( 400, array(), 'eventwatch_invalid_request', '' ),
			'削除済み'    => array( 409, array( 'code' => 'eventDeleted' ), 'eventwatch_invalid_request', 'eventDeleted' ),
			'URL の衝突' => array( 409, array(), 'eventwatch_invalid_request', '' ),
			'見つからない'  => array( 404, array(), 'eventwatch_not_found', '' ),
		);
	}

	/**
	 * 送り直しても変わらない失敗は印を外し、理由を残す。次の保存では送る。
	 *
	 * @dataProvider final_failures
	 *
	 * @param int    $status   ステータス。
	 * @param array  $body     本文。
	 * @param string $code     残るコード。
	 * @param string $api_code 残る応答の code。
	 */
	public function test_final_failure_is_not_retried( $status, $body, $code, $api_code ) {
		$id                = $this->event();
		$this->responses[] = $this->response( $status, $body );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$error = get_post_meta( $id, Sync_Queue::ERROR, true );
		$this->assertSame( $code, $error['code'] );
		$this->assertSame( $api_code, $error['apiCode'] );
		$this->assertSame( $status, $error['status'] );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );

		$this->requests = array();
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * 反映されなかった応答は送り直さない。
	 */
	public function test_not_applied_is_not_retried() {
		$id                = $this->event();
		$this->responses[] = $this->ok( 'published', false );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::ERROR, true ) );
	}

	/**
	 * 止める失敗。
	 *
	 * @return array
	 */
	public function halting_failures() {
		return array(
			'失効'       => array( 401, array(), 'eventwatch_unauthorized' ),
			'団体の停止'    => array( 403, array( 'code' => 'organizationSuspended' ), 'eventwatch_suspended' ),
			'範囲の外'     => array( 403, array(), 'eventwatch_forbidden' ),
			'別のキーのサイト' => array( 403, array( 'code' => 'siteBoundToAnotherKey' ), 'eventwatch_forbidden' ),
		);
	}

	/**
	 * 結び付きの 403 の案内は、定期の取り込みの GET が通っても消えない。失効の後は取り込みが起こす送信で消える。
	 */
	public function test_site_bound_reason_survives_the_hourly_pull() {
		$sent = $this->sent_event();
		$id   = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->responses[] = $this->response( 403, array( 'code' => 'siteBoundToAnotherKey' ) );
		Sync_Queue::send_marked();
		$this->assertTrue( Connection::is_site_bound( Connection::state() ) );

		// 結び付きでは GET は断られない。定期の取り込みの成功で案内を消さない。
		$this->requests    = array();
		$this->responses[] = $this->ok( 'pendingReview' );
		Sync_Pull::run();

		$this->assertSame( array( 'GET' ), array_column( $this->requests, 'method' ) );
		$this->assertSame( Sync_Queue::post_path( $sent ), $this->requests[0]['path'] );
		$this->assertTrue( Connection::is_site_bound( Connection::state() ) );

		// 取り込みは止まった送信を起こす。古いキーが残っている間は、また断られて案内が残る。
		$this->requests    = array();
		$this->responses[] = $this->response( 403, array( 'code' => 'siteBoundToAnotherKey' ) );
		Sync_Queue::run();
		$this->assertCount( 1, $this->requests );
		$this->assertTrue( Connection::is_site_bound( Connection::state() ) );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );

		// 古いキーを失効した後は、ボタンを押さなくても次の取り込みからの送信が通り、案内が消える。
		$this->requests = array();
		update_post_meta( $sent, Sync_Queue::PULLED_AT, 0 );
		Sync_Pull::run();
		Sync_Queue::run();
		$this->assertSame( array( 'GET', 'PUT' ), array_column( $this->requests, 'method' ) );
		$this->assertSame( 0, Sync_Queue::pending_count() );
		$this->assertFalse( Connection::is_site_bound( Connection::state() ) );
	}

	/**
	 * 別のキーに結び付いたサイトで止まった待ちは、古いキーを失効して接続を確かめると、次の送信で送られる。
	 * 確かめるのは団体の GET で、結び付きでは断られない。
	 */
	public function test_site_bound_queue_is_sent_after_revoking_the_old_key() {
		$first  = $this->event();
		$second = $this->event();
		foreach ( array( $first, $second ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		$this->responses[] = $this->response( 403, array( 'code' => 'siteBoundToAnotherKey' ) );
		Sync_Queue::send_marked();

		$this->assertSame( 2, Sync_Queue::pending_count() );
		$this->assertTrue( Connection::is_site_bound( Connection::state() ) );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );

		// 古いキーを失効した後の「接続を確かめる」（Connection_Admin::handle_check と同じ流れ）。
		$this->responses[] = $this->response( 200, array( 'displayName' => 'Org' ) );
		$this->assertSame( Connection::CONNECTED, Connection::check()['status'] );
		Sync_Queue::resume();

		$this->requests = array();
		Sync_Queue::run();

		$this->assertSame( array( 'PUT', 'PUT' ), array_column( $this->requests, 'method' ) );
		$this->assertSame( 0, Sync_Queue::pending_count() );
		$this->assertFalse( Connection::is_site_bound( Connection::state() ) );
	}

	/**
	 * キーの問題は印を残し、回数に数えず、その回を止める。cron も張らない。キーの保存で再開する。
	 *
	 * @dataProvider halting_failures
	 *
	 * @param int    $status ステータス。
	 * @param array  $body   本文。
	 * @param string $code   残るコード。
	 */
	public function test_key_problem_halts_without_counting( $status, $body, $code ) {
		$first  = $this->event();
		$second = $this->event();
		foreach ( array( $first, $second ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}
		$this->responses[] = $this->response( $status, $body );
		Sync_Queue::send_marked();

		$this->assertCount( 1, $this->requests );
		$this->assertNotSame( '', (string) get_post_meta( $first, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', (string) get_post_meta( $first, Sync_Queue::ATTEMPTS, true ) );
		$this->assertSame( $code, get_post_meta( $first, Sync_Queue::ERROR, true )['code'] );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );

		update_option( Connection::KEY_OPTION, 'ewk_9123456789012345678901234567890123456789012' );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );

		$this->requests = array();
		Sync_Queue::run();

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 0, Sync_Queue::pending_count() );
	}

	/**
	 * 送信中に積み直された印を消さない。
	 */
	public function test_save_during_send_keeps_the_mark() {
		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$this->before_response = static function () use ( $id ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => '送信中に直した題',
				)
			);
		};
		Sync_Queue::send_marked();

		$this->assertSame( '題', $this->requests[0]['body']['title'] );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );

		Sync_Queue::send_marked();
		$this->assertSame( '送信中に直した題', $this->requests[1]['body']['title'] );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 応答を失った最初の PUT の後でも、取り下げを送る。
	 */
	public function test_withdrawal_after_lost_response() {
		$id                = $this->event();
		$this->responses[] = new \WP_Error( 'http_request_failed', 'timeout' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->requests = array();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );
	}

	/**
	 * 取り下げの 404 は送信済みとして扱い、次の公開で作り直す。
	 */
	public function test_unpublish_404_is_done() {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 404 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::ERROR, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::EXISTS, true ) );

		$this->requests = array();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
	}

	/**
	 * 錠がある間は送らず、印を残す。古い錠は取り直す。
	 */
	public function test_lock_blocks_and_stale_lock_is_taken_over() {
		global $wpdb;

		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", Sync_Queue::LOCK_OPTION, time() . '|other' ) );
		Sync_Queue::run();
		$this->assertSame( array(), $this->requests );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
		$this->assertStringEndsWith( '|other', (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Sync_Queue::LOCK_OPTION ) ) );

		$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - Sync_Queue::LOCK_TTL - 1 ) . '|other' ), array( 'option_name' => Sync_Queue::LOCK_OPTION ) );
		Sync_Queue::run();
		$this->assertCount( 1, $this->requests );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Sync_Queue::LOCK_OPTION ) ) );
	}

	/**
	 * Cron の 1 回の上限を超えた分は、次の回に回す。
	 */
	public function test_run_stops_at_the_limit() {
		$ids = array();
		for ( $i = 0; $i < Sync_Queue::RUN_MAX + 2; $i++ ) {
			$ids[] = $this->event();
		}
		foreach ( $ids as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}

		Sync_Queue::run();

		$this->assertCount( Sync_Queue::RUN_MAX, $this->requests );
		$this->assertSame( 2, Sync_Queue::pending_count() );
		$this->assertGreaterThanOrEqual( time() + Sync_Queue::RUN_INTERVAL - 1, wp_next_scheduled( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 保存の時点の送信は上限の件数まで。残りは cron。
	 */
	public function test_immediate_send_is_limited() {
		$ids = array();
		for ( $i = 0; $i < Sync_Queue::IMMEDIATE_MAX + 1; $i++ ) {
			$ids[] = $this->event();
		}
		foreach ( $ids as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
				)
			);
		}

		Sync_Queue::send_marked();

		$this->assertCount( Sync_Queue::IMMEDIATE_MAX, $this->requests );
		$this->assertSame( 1, Sync_Queue::pending_count() );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * サイトの表示の流れでは送らず、cron に任せる。
	 */
	public function test_shutdown_does_not_send_on_public_requests() {
		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		Sync_Queue::on_shutdown();

		$this->assertSame( array(), $this->requests );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );

		$doing_cron = '__return_true';
		add_filter( 'wp_doing_cron', $doing_cron );
		Sync_Queue::on_shutdown();
		remove_filter( 'wp_doing_cron', $doing_cron );

		$this->assertCount( 1, $this->requests );
	}

	/**
	 * 送れない理由があれば送らず、理由を残す。直して保存すれば送る。公開は止まらない。
	 */
	public function test_blocked_post_is_not_sent() {
		$id = $this->event();
		update_post_meta( $id, Event_Meta::CATEGORY, 0 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( array( array( 'code' => Sync_Payload::BLOCK_CATEGORY ) ), get_post_meta( $id, Sync_Queue::BLOCKS, true ) );

		update_post_meta( $id, Event_Meta::CATEGORY, 3 );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::BLOCKS, true ) );
	}

	/**
	 * 「送らない」: 未送信なら何も送らない。送ってあれば取り下げを 1 回。送るに戻すと PUT。
	 */
	public function test_no_send() {
		$never = $this->event();
		update_post_meta( $never, Sync_Queue::NO_SEND, 1 );
		wp_update_post(
			array(
				'ID'          => $never,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $never, Sync_Queue::EXISTS, true ) );

		$id = $this->sent_event();
		update_post_meta( $id, Sync_Queue::NO_SEND, 1 );
		$this->responses[] = $this->ok( 'private' );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );

		$this->requests = array();
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests );

		delete_post_meta( $id, Sync_Queue::NO_SEND );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
	}

	/**
	 * 完全な削除は「送らない」でも届ける。
	 */
	public function test_delete_ignores_no_send() {
		$id = $this->sent_event();
		update_post_meta( $id, Sync_Queue::NO_SEND, 1 );
		$this->responses[] = $this->response( 204 );
		wp_delete_post( $id, true );
		Sync_Queue::send_marked();

		$this->assertSame( array( "DELETE /events/$id" ), $this->sent() );
	}

	/**
	 * キーなしのサイトには印を積まない。送ってある投稿の取り下げは残し、キーを入れると送る。
	 */
	public function test_no_key() {
		$sent = $this->sent_event();

		delete_option( Connection::KEY_OPTION );
		add_option( Connection::KEY_OPTION, '', '', false );

		$id = $this->event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );

		wp_update_post(
			array(
				'ID'          => $sent,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests );
		$this->assertNotSame( '', (string) get_post_meta( $sent, Sync_Queue::PENDING, true ) );
		$this->assertSame( 'eventwatch_no_key', get_post_meta( $sent, Sync_Queue::ERROR, true )['code'] );

		update_option( Connection::KEY_OPTION, self::KEY );
		Sync_Queue::run();
		$this->assertSame( array( "POST /events/$sent/unpublish" ), $this->sent() );
	}

	/**
	 * 複製と判定されたサイトからは送らない。印は残し、回数に数えない。移転を選ぶと再開する。
	 */
	public function test_copied_site_sends_nothing_until_moved() {
		$id = $this->event();
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://other.example.com' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertNotSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::ATTEMPTS, true ) );
		$this->assertSame( 'eventwatch_site_changed', get_post_meta( $id, Sync_Queue::ERROR, true )['code'] );

		Site_Identity::use_as_moved();
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
		Sync_Queue::run();
		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
	}

	/**
	 * 「複製です」を選ぶと、送信の記録と削除の行を消す。「送らない」の選択は残す。
	 */
	public function test_copy_forgets_sync_state() {
		$sent    = $this->sent_event();
		$no_send = $this->sent_event();
		$deleted = $this->sent_event();
		update_post_meta( $sent, Event_Detail::LISTING_SLUG, 'abc' );
		update_post_meta( $no_send, Sync_Queue::NO_SEND, 1 );
		wp_delete_post( $deleted, true );

		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://other.example.com' ) );
		wp_update_post(
			array(
				'ID'         => $sent,
				'post_title' => '複製で直した題',
			)
		);
		$this->assertSame( 2, Sync_Queue::pending_count() );

		Site_Identity::use_as_copy();

		$this->assertSame( 0, Sync_Queue::pending_count() );
		foreach ( Sync_Queue::meta_keys() as $key ) {
			$this->assertSame( '', (string) get_post_meta( $sent, $key, true ) );
		}
		$this->assertSame( '', (string) get_post_meta( $sent, Event_Detail::LISTING_SLUG, true ) );
		$this->assertSame( '1', (string) get_post_meta( $no_send, Sync_Queue::NO_SEND, true ) );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RUN_HOOK ) );
	}

	/**
	 * 接頭辞を変えると、公開中の投稿を洗い直して公式ページを送り直す。内容が同じ投稿は送らない。
	 */
	public function test_rescan_resends_changed_permalinks_only() {
		$this->set_permalink_structure( '/%postname%/' );
		wp_clear_scheduled_hook( Sync_Queue::RESCAN_HOOK );
		$changed = $this->sent_event();
		$same    = $this->sent_event();
		$other   = $this->event();
		update_post_meta( $same, Event_Meta::URL, 'https://example.com/official' );
		wp_update_post( array( 'ID' => $same ) );
		Sync_Queue::send_marked();
		$this->requests = array();

		update_option( Post_Type::SLUG_OPTION, 'gigs' );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
		$modified_before = get_post( $changed )->post_modified_gmt;

		Post_Type::register();
		Sync_Queue::rescan();
		Sync_Queue::run();

		$this->assertSame( array( "PUT /events/$changed" ), $this->sent() );
		$this->assertStringContainsString( 'gigs', $this->requests[0]['body']['publisherEventPageUrl'] );
		// 同じ時刻で送るので、差し戻しの再提出などの状態の遷移を起こさない。
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s', strtotime( $modified_before . ' UTC' ) ) . '+00:00', $this->requests[0]['body']['sourceUpdatedAt'] );
		$this->assertSame( '', (string) get_post_meta( $other, Sync_Queue::PENDING, true ) );

		delete_option( Post_Type::SLUG_OPTION );
		wp_clear_scheduled_hook( Sync_Queue::RESCAN_HOOK );
		Post_Type::register();
	}

	/**
	 * 公式ページを上書きした投稿だけのサイトでも、移転の確認の後に新しい URL が届く。確認の前は送らない。
	 */
	public function test_new_site_urls_arrive_after_move_is_confirmed() {
		$id = $this->sent_event();
		update_post_meta( $id, Event_Meta::URL, 'https://example.com/official' );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->requests = array();

		$moved = static function () {
			return 'https://moved.example.com';
		};
		add_filter( 'option_home', $moved, 20 );

		Sync_Queue::rescan();
		Sync_Queue::run();
		$this->assertSame( array(), $this->requests );

		Site_Identity::use_as_moved();
		Sync_Queue::rescan();
		Sync_Queue::run();
		remove_filter( 'option_home', $moved, 20 );

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertSame( 'https://moved.example.com', $this->requests[0]['body']['siteUrl'] );
		$this->assertSame( 'https://example.com/official', $this->requests[0]['body']['publisherEventPageUrl'] );
	}

	/**
	 * 管理画面の URL だけが変わると、移転の確認を通らずに洗い直して送り直す。
	 */
	public function test_siteurl_change_resends_with_new_admin_url() {
		$id = $this->sent_event();

		update_option( 'siteurl', get_option( 'home' ) . '/changed' );
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );

		// 手元の環境は WP_SITEURL を定義していて、option の更新では値が変わらない。
		$moved = static function ( $url ) {
			return $url . '/wp';
		};
		add_filter( 'option_siteurl', $moved, 20 );
		Sync_Queue::rescan();
		Sync_Queue::run();
		remove_filter( 'option_siteurl', $moved, 20 );

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertStringEndsWith( '/wp/wp-admin/', $this->requests[0]['body']['adminUrl'] );
	}

	/**
	 * 公開 URL のスキームだけの変更は移転の確認を通らないので、更新で洗い直す。
	 */
	public function test_home_update_requests_rescan() {
		update_option( 'home', 'https://example.org/changed' );

		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
	}

	/**
	 * 置換でスキームだけを変えたサイトは、定期の確かめで洗い直して新しい URL を送る。洗い直した後は重ねて張らない。
	 */
	public function test_scheme_change_by_replace_resends_on_check() {
		$id = $this->sent_event();
		Sync_Queue::rescan();
		Sync_Queue::run();
		$this->requests = array();
		$replaced       = $this->flip_scheme( (string) get_option( 'home' ) );

		// 置換は option の更新のフックを通らない。保存値だけを変える。WP_HOME を定義した環境でも送る値が変わるよう、フィルターで揃える。
		$this->replace_stored_scheme();
		$as_replaced = static function () use ( $replaced ) {
			return $replaced;
		};
		add_filter( 'option_home', $as_replaced, 20 );
		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );

		Sync_Queue::rescan_if_urls_changed();
		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );

		wp_clear_scheduled_hook( Sync_Queue::RESCAN_HOOK );
		Sync_Queue::rescan();
		Sync_Queue::run();
		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertSame( $replaced, $this->requests[0]['body']['siteUrl'] );

		Sync_Queue::rescan_if_urls_changed();
		remove_filter( 'option_home', $as_replaced, 20 );

		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
	}

	/**
	 * URL が最後の洗い直しと同じなら、定期の確かめで何も送らない。
	 */
	public function test_same_urls_do_not_rescan_on_check() {
		$this->sent_event();
		Sync_Queue::rescan();
		Sync_Queue::run();
		$this->requests = array();

		Sync_Queue::rescan_if_urls_changed();
		Sync_Queue::run();

		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 記録が無いとき（確かめを持たない版から上げた直後）は、いまの URL を覚えるだけにする。
	 */
	public function test_first_check_only_records_urls() {
		$this->sent_event();
		delete_option( Sync_Queue::URLS_HASH_OPTION );

		Sync_Queue::rescan_if_urls_changed();
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
		$this->assertNotFalse( get_option( Sync_Queue::URLS_HASH_OPTION ) );

		$this->replace_stored_scheme();
		Sync_Queue::rescan_if_urls_changed();

		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
	}

	/**
	 * siteurl の保存値だけが置換で変わっても洗い直す。管理画面の URL は siteurl から作る。
	 */
	public function test_check_sees_stored_siteurl() {
		Sync_Queue::rescan();

		$this->replace_stored_scheme( array( 'siteurl' ) );
		Sync_Queue::rescan_if_urls_changed();

		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
	}

	/**
	 * 要求ごとに URL が変わるサイト（WP_HOME を is_ssl() から作る・WP_SITEURL を Host ヘッダーから作る）では、
	 * 保存値が同じなら洗い直しを張らない。送る値で比べると、要求のたびに全件を送り直す。
	 */
	public function test_check_ignores_urls_that_change_per_request() {
		$this->sent_event();
		$https      = static function ( $url ) {
			return set_url_scheme( $url, 'https' );
		};
		$other_host = static function () {
			return 'http://other.example/wp';
		};

		Sync_Queue::rescan();
		add_filter( 'option_home', $https, 20 );
		add_filter( 'option_siteurl', $other_host, 20 );
		Sync_Queue::rescan_if_urls_changed();
		$changed = wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK );

		Sync_Queue::rescan();
		remove_filter( 'option_home', $https, 20 );
		remove_filter( 'option_siteurl', $other_host, 20 );
		Sync_Queue::rescan_if_urls_changed();

		$this->assertFalse( $changed );
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
	}

	/**
	 * 複製として別のサイトになると、URL の記録も消える。
	 */
	public function test_forget_all_forgets_rescanned_urls() {
		Sync_Queue::rescan();
		$this->assertNotFalse( get_option( Sync_Queue::URLS_HASH_OPTION ) );

		Sync_Queue::forget_all();

		$this->assertFalse( get_option( Sync_Queue::URLS_HASH_OPTION ) );
	}

	/**
	 * スキームを入れ替える。置換で http と https を入れ替えたサイトの代わり。
	 *
	 * @param string $url URL。
	 * @return string
	 */
	public function flip_scheme( $url ) {
		if ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return set_url_scheme( $url, 'http' );
		}

		return set_url_scheme( $url, 'https' );
	}

	/**
	 * option の保存値のスキームを、フックを通さずに入れ替える。wp search-replace の代わり。
	 *
	 * @param string[] $names 入れ替える option。
	 * @return void
	 */
	private function replace_stored_scheme( array $names = array( 'home', 'siteurl' ) ) {
		global $wpdb;

		foreach ( $names as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 置換と同じく、option の更新のフックを通さない。
			$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 同上。
			$wpdb->update( $wpdb->options, array( 'option_value' => $this->flip_scheme( $stored ) ), array( 'option_name' => $name ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * 要求の経路が変わっても、同じ内容の投稿は送らない。
	 */
	public function test_request_scheme_does_not_resend() {
		$id = $this->sent_event();
		// 既定の公式ページのパーマリンクは is_ssl() で変わるので、別の公式ページで管理画面の URL だけを見る。
		update_post_meta( $id, Event_Meta::URL, 'https://example.com/official' );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();
		$this->requests = array();

		$_SERVER['HTTPS'] = 'on';
		Sync_Queue::rescan();
		Sync_Queue::run();
		unset( $_SERVER['HTTPS'] );

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * 取り下げにはサイトの URL を載せない。サーバーの受け口が持たない。
	 */
	public function test_unpublish_has_no_site_urls() {
		$id = $this->sent_event();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );
		$this->assertSame( array( 'sourceUpdatedAt' ), array_keys( $this->requests[0]['body'] ) );
	}

	/**
	 * 下書きの自動保存は、まだ送っていない投稿でも、取り下げ済みの投稿でも送らない。
	 */
	public function test_autosave_of_draft_sends_nothing() {
		$never = $this->event();
		$this->autosave_as_editor( $never );
		$withdrawn         = $this->sent_event();
		$this->responses[] = $this->ok( 'private' );
		wp_update_post(
			array(
				'ID'          => $withdrawn,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();
		$this->requests = array();

		$this->autosave_as_editor( $withdrawn );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $never, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 届いていない取り下げの送り直しの待ちを、自動保存で縮めない。今すぐ送り直すは待たない。
	 */
	public function test_autosave_keeps_retry_wait_of_withdrawal() {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 503 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();
		$next           = (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true );
		$this->requests = array();

		$this->autosave_as_editor( $id );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( $next, (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true ) );
		$this->assertSame( 1, (int) get_post_meta( $id, Sync_Queue::ATTEMPTS, true ) );

		$this->responses[] = $this->ok( 'private' );
		Sync_Queue::resend( $id );

		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * PUT の送り直しの待ちがあっても、公開のままパスワードや「送らない」を付けた保存では、すぐ取り下げる。
	 *
	 * @dataProvider withdrawals_keeping_publish_status
	 *
	 * @param callable $change 変更。
	 */
	public function test_withdrawal_with_same_status_does_not_wait( $change ) {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 503 );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => '更新',
			)
		);
		Sync_Queue::send_marked();
		$this->assertGreaterThan( time(), (int) get_post_meta( $id, Sync_Queue::NEXT_AT, true ) );
		$this->requests = array();

		$this->responses[] = $this->ok( 'private' );
		$change( $id );
		Sync_Queue::send_marked();

		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );
	}

	/**
	 * 公開のままの取り下げ。
	 *
	 * @return array
	 */
	public function withdrawals_keeping_publish_status() {
		return array(
			'パスワード' => array(
				static function ( $id ) {
					wp_update_post(
						array(
							'ID'            => $id,
							'post_password' => 'secret',
						)
					);
				},
			),
			'送らない'  => array(
				static function ( $id ) {
					update_post_meta( $id, Sync_Queue::NO_SEND, 1 );
					wp_update_post( array( 'ID' => $id ) );
				},
			),
		);
	}

	/**
	 * 止まっている失敗には待ちが無いので、下書きの保存で取り下げを試す。
	 */
	public function test_halted_withdrawal_is_retried_on_save() {
		$id                = $this->sent_event();
		$this->responses[] = $this->response( 401 );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();
		$this->requests = array();

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => '更新',
			)
		);
		Sync_Queue::send_marked();

		$this->assertSame( array( "POST /events/$id/unpublish" ), $this->sent() );
	}

	/**
	 * 公開中の投稿のリビジョンの保存は送らない。
	 */
	public function test_revision_sends_nothing() {
		$id = $this->sent_event();
		wp_save_post_revision( $id );
		_wp_put_post_revision( get_post( $id ), true );
		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * キーなしでも、送ってある公開中の投稿の更新は積み、キーを入れると追いつく。
	 */
	public function test_update_of_sent_post_without_key_catches_up() {
		$id = $this->sent_event();
		delete_option( Connection::KEY_OPTION );
		add_option( Connection::KEY_OPTION, '', '', false );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'キーなしの間の題',
			)
		);
		Sync_Queue::send_marked();
		$this->assertSame( array(), $this->requests );

		update_option( Connection::KEY_OPTION, self::KEY );
		Sync_Queue::run();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertSame( 'キーなしの間の題', $this->requests[0]['body']['title'] );
	}

	/**
	 * 編集者の自動保存を流す。編集画面と同じ口を通す。
	 *
	 * @param int $id 投稿の ID。
	 * @return void
	 */
	private function autosave_as_editor( $id ) {
		Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		// 投稿者でないと、本体はリビジョンを作るだけで投稿を更新しない。フックを通らないように直接書く。
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_author' => get_current_user_id() ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$modified = get_post( $id )->post_content;

		$request = new \WP_REST_Request( 'POST', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $id . '/autosaves' );
		$request->set_param( 'content', '<p>自動保存の本文 ' . wp_generate_password( 6, false ) . '</p>' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertNotSame( $modified, get_post( $id )->post_content );
		wp_set_current_user( 0 );
	}

	/**
	 * 洗い直しは公開中の投稿だけを積む。取り下げた投稿に余計な印を付けない。
	 */
	public function test_rescan_marks_published_posts_only() {
		$published = $this->sent_event();
		$withdrawn = $this->sent_event();
		wp_update_post(
			array(
				'ID'          => $withdrawn,
				'post_status' => 'draft',
			)
		);
		Sync_Queue::send_marked();

		Sync_Queue::rescan();

		$this->assertNotSame( '', (string) get_post_meta( $published, Sync_Queue::PENDING, true ) );
		$this->assertSame( '', (string) get_post_meta( $withdrawn, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 移転はパーマリンクが変わるので、洗い直しを張る。
	 */
	public function test_moving_requests_rescan() {
		$this->assertFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );

		Site_Identity::use_as_moved();

		$this->assertNotFalse( wp_get_scheduled_event( Sync_Queue::RESCAN_HOOK ) );
	}

	/**
	 * 送信待ちの件数を設定画面の値に返す。
	 */
	public function test_pending_count_filter() {
		$deleted = $this->sent_event();
		$first   = $this->event();
		wp_update_post(
			array(
				'ID'          => $first,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 1, Sync_Status::pending_count() );

		wp_delete_post( $deleted, true );
		$this->assertSame( 2, Sync_Status::pending_count() );
	}

	/**
	 * 別のキーへ替えると、内容が同じでも送る（再接続）。
	 */
	public function test_new_key_resends_same_content() {
		$id = $this->sent_event();
		update_option( Connection::KEY_OPTION, 'ewk_9123456789012345678901234567890123456789012' );
		wp_update_post( array( 'ID' => $id ) );
		Sync_Queue::send_marked();

		$this->assertSame( array( "PUT /events/$id" ), $this->sent() );
		$this->assertSame( 'ewk_9123456789012345678901234567890123456789012', $this->requests[0]['key'] );
	}

	/**
	 * リビジョンと投稿タイプの違う投稿には印を積まない。
	 */
	public function test_ignores_other_post_types() {
		$other = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertSame( '', (string) get_post_meta( $other, Sync_Queue::PENDING, true ) );

		Sync_Queue::send_marked();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', (string) get_post_meta( $other, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 同期のメタは保護されたメタで、登録されたメタの一覧に出ない。
	 */
	public function test_sync_meta_is_protected() {
		foreach ( array_merge( Sync_Queue::meta_keys(), array( Sync_Queue::NO_SEND ) ) as $key ) {
			$this->assertTrue( is_protected_meta( $key, 'post' ), $key );
			$this->assertArrayNotHasKey( $key, get_registered_meta_keys( 'post', Post_Type::POST_TYPE ) );
		}
	}
}
