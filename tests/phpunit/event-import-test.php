<?php
/**
 * 取り込みのテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Api_Client;
use EventWatch\Capabilities;
use EventWatch\Connection;
use EventWatch\Event_Dates;
use EventWatch\Event_Import;
use EventWatch\Event_Meta;
use EventWatch\Import_Admin;
use EventWatch\Post_Type;
use EventWatch\Site_Identity;
use EventWatch\Sync_Image;
use EventWatch\Sync_Queue;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Event_Import のテスト。
 */
class Event_Import_Test extends WP_UnitTestCase {

	const ID = '11111111-1111-4111-8111-111111111111';

	const IMAGE_URL = 'https://cdn.example.com/v4/owned/poster.jpg';

	/**
	 * 一覧の投稿。
	 *
	 * @var array
	 */
	private $listed = array();

	/**
	 * 結び付けの応答。無ければ成功。
	 *
	 * @var array|\WP_Error|null
	 */
	private $link_answer = null;

	/**
	 * 受けた EventWatch への要求。
	 *
	 * @var array
	 */
	private $api = array();

	/**
	 * 取得で返す画像の中身のファイル。空なら既定の画像。
	 *
	 * @var string
	 */
	private $image_content = '';

	/**
	 * 画像の取得の失敗。
	 *
	 * @var bool
	 */
	private $image_fails = false;

	/**
	 * 画像を取得した回数。
	 *
	 * @var int
	 */
	private $image_fetches = 0;

	/**
	 * テストごとに登録し直し、HTTP を差し替える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		Post_Type::register();
		Capabilities::grant();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		Site_Identity::use_as_moved();
		$this->listed        = array();
		$this->link_answer   = null;
		$this->api           = array();
		$this->image_fails   = false;
		$this->image_content = '';
		$this->image_fetches = 0;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		add_filter( 'pre_http_request', array( $this, 'answer_request' ), 10, 3 );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 本体のフックを起こす。
	}

	/**
	 * 状態を戻す。
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Sync_Queue::forget_all();
		parent::tear_down();
	}

	/**
	 * HTTP の差し替え。
	 *
	 * @param mixed  $pre  前の値。
	 * @param array  $args 引数。
	 * @param string $url  URL。
	 * @return array|\WP_Error
	 */
	public function answer_request( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}

		if ( 0 === strpos( $url, 'https://cdn.example.com/' ) ) {
			++$this->image_fetches;
			if ( $this->image_fails ) {
				return new \WP_Error( 'http_request_failed', 'no' );
			}
			if ( ! empty( $args['filename'] ) ) {
				copy( '' !== $this->image_content ? $this->image_content : DIR_TESTDATA . '/images/canola.jpg', $args['filename'] );
			}
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
				'filename' => $args['filename'] ?? null,
			);
		}

		$this->api[] = array( $args['method'], $url, isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null );

		if ( false !== strpos( $url, '/link' ) ) {
			return $this->link_answer ?? $this->http( 200, $this->linked() );
		}

		if ( false !== strpos( $url, '/org-api/events' ) ) {
			return $this->http(
				200,
				array(
					'list'  => array_values( $this->listed ),
					'total' => count( $this->listed ),
				)
			);
		}

		return $this->http(
			200,
			array(
				'event' => array(
					'status' => 'pendingReview',
				),
			)
		);
	}

	/**
	 * 応答を作る。
	 *
	 * @param int        $status ステータス。
	 * @param array|null $body   本文。
	 * @return array
	 */
	private function http( $status, $body = null ) {
		return array(
			'headers'  => array(),
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * 一覧の投稿を作る。
	 *
	 * @param array $fields 変える欄。
	 * @return array
	 */
	private function event( array $fields = array() ) {
		return array_merge(
			array(
				'id'                    => self::ID,
				'status'                => 'published',
				'visible'               => true,
				'suspendedByOperator'   => false,
				'slug'                  => 'abc123',
				'title'                 => 'A & B の <Roland> 演奏会',
				'description'           => "## 見どころ\n\n**大事** な話\n\n- 一つ目\n- 二つ目",
				'occurrenceRanges'      => array(
					array(
						'startAt' => '2030-05-01 10:00',
						'closeAt' => '2030-05-01 12:00',
					),
					array(
						'startAt' => '2030-05-02 10:00',
						'closeAt' => '',
					),
				),
				'holdingPeriodNote'     => '雨天順延',
				'zoneId'                => 'Asia/Tokyo',
				'category'              => 3,
				'prefectureCode'        => 34,
				'online'                => false,
				'venueName'             => '市民ホール',
				'venueAddress'          => '広島市中区 1-1',
				'publisherEventPageUrl' => 'https://example.org/concert',
				'imageUploadId'         => 'upload-1',
				'thumbnails'            => array(
					'thumbnail' => array(
						'list' => array(
							array(
								'publicUrl' => 'https://cdn.example.com/v4/owned/small.jpg',
								'width'     => 400,
							),
							array(
								'publicUrl' => self::IMAGE_URL,
								'width'     => 1600,
							),
						),
					),
				),
			),
			$fields
		);
	}

	/**
	 * 結び付けの応答の投稿。一覧の投稿を返す。
	 *
	 * @return array
	 */
	private function linked() {
		return isset( $this->listed[0] ) ? $this->listed[0] : $this->event();
	}

	/**
	 * 一覧に置いて、1 件を取り込む。
	 *
	 * @param array $event 投稿。
	 * @return array Event_Import::run の戻り。
	 */
	private function import( array $event ) {
		$this->listed = array( $event );
		Event_Import::prepare( array( $event['id'] ) );
		$this->api = array();

		return Event_Import::run( $event['id'] );
	}

	/**
	 * EventWatch へ送った書き込みの数。
	 *
	 * @param string $needle URL に含む文字列。
	 * @return int
	 */
	private function sent( $needle ) {
		return count(
			array_filter(
				$this->api,
				static function ( $request ) use ( $needle ) {
					return false !== strpos( $request[1], $needle );
				}
			)
		);
	}

	/**
	 * 欄の対応。送るときの対応の逆。
	 */
	public function test_fields_are_mapped() {
		$result = $this->import( $this->event() );

		$this->assertSame( 'imported', $result['status'] );
		$post = get_post( $result['postId'] );
		$this->assertSame( Post_Type::POST_TYPE, $post->post_type );
		$this->assertStringContainsString( '<!-- wp:heading -->', $post->post_content );
		$this->assertStringContainsString( '<strong>大事</strong>', $post->post_content );
		$this->assertStringContainsString( '<!-- wp:list -->', $post->post_content );
		$this->assertSame(
			array(
				array(
					'start' => '2030-05-01 10:00',
					'end'   => '2030-05-01 12:00',
				),
				array(
					'start' => '2030-05-02 10:00',
					'end'   => '',
				),
			),
			array_values( get_post_meta( $post->ID, Event_Meta::OCCURRENCES, true ) )
		);
		$this->assertSame( 'Asia/Tokyo', get_post_meta( $post->ID, Event_Meta::ZONE, true ) );
		$this->assertSame( '雨天順延', get_post_meta( $post->ID, Event_Meta::SCHEDULE_NOTE, true ) );
		$this->assertSame( 3, (int) get_post_meta( $post->ID, Event_Meta::CATEGORY, true ) );
		$this->assertSame( 34, (int) get_post_meta( $post->ID, Event_Meta::PREFECTURE, true ) );
		$this->assertFalse( (bool) get_post_meta( $post->ID, Event_Meta::ONLINE, true ) );
		$this->assertSame( '市民ホール', get_post_meta( $post->ID, Event_Meta::VENUE_NAME, true ) );
		$this->assertSame( '広島市中区 1-1', get_post_meta( $post->ID, Event_Meta::VENUE_ADDRESS, true ) );
		$this->assertSame( 'https://example.org/concert', get_post_meta( $post->ID, Event_Meta::URL, true ) );
		$this->assertNotEmpty( get_post_meta( $post->ID, Event_Meta::FIRST_START, true ) );
		$this->assertTrue( wp_attachment_is_image( get_post_thumbnail_id( $post->ID ) ) );
		$this->assertSame( self::IMAGE_URL, get_post_meta( get_post_thumbnail_id( $post->ID ), Sync_Queue::IMPORT_IMAGE_SOURCE, true ) );
		$this->assertSame( self::ID, get_post_meta( $post->ID, Sync_Queue::IMPORT_SOURCE, true ) );
	}

	/**
	 * 題のタグの形と & を、送り返すときに崩さない。
	 */
	public function test_title_round_trip() {
		$result = $this->import( $this->event() );
		$built  = \EventWatch\Sync_Payload::build( get_post( $result['postId'] ) );

		$this->assertSame( 'A & B の <Roland> 演奏会', $built['body']['title'] );
		$this->assertSame( 'A & B の <Roland> 演奏会', $result['title'] );
	}

	/**
	 * 状態の対応。
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public function status_cases() {
		return array(
			'掲載中'   => array(
				array(
					'status'  => 'published',
					'visible' => true,
				),
				'publish',
			),
			'審査待ち'  => array(
				array(
					'status'  => 'pendingReview',
					'visible' => false,
				),
				'draft',
			),
			'下書き'   => array(
				array(
					'status'  => 'draft',
					'visible' => false,
				),
				'draft',
			),
			'差し戻し'  => array(
				array(
					'status'          => 'rejected',
					'visible'         => false,
					'rejectionReason' => '日付',
				),
				'draft',
			),
			'非公開'   => array(
				array(
					'status'  => 'private',
					'visible' => false,
				),
				'draft',
			),
			'運営の停止' => array(
				array(
					'status'              => 'published',
					'visible'             => false,
					'suspendedByOperator' => true,
				),
				'draft',
			),
		);
	}

	/**
	 * 掲載中は公開、それ以外は下書き。
	 *
	 * @dataProvider status_cases
	 *
	 * @param array  $fields   投稿の欄。
	 * @param string $expected 投稿の状態。
	 */
	public function test_status_mapping( array $fields, $expected ) {
		$result = $this->import( $this->event( $fields ) );

		$this->assertSame( 'imported', $result['status'] );
		$this->assertSame( $expected, get_post_status( $result['postId'] ) );
		$this->assertSame( $expected, $result['postStatus'] );
		$this->assertSame( $fields['status'], get_post_meta( $result['postId'], Sync_Queue::REMOTE, true )['status'] );
	}

	/**
	 * 結び付けの要求の形。
	 */
	public function test_link_request() {
		$result = $this->import( $this->event() );

		$this->assertCount( 1, $this->api );
		$this->assertSame( 'POST', $this->api[0][0] );
		$this->assertStringEndsWith( Sync_Queue::post_path( $result['postId'] ) . '/link', $this->api[0][1] );
		$this->assertSame(
			array(
				'eventId'  => self::ID,
				'siteUrl'  => get_option( 'home' ),
				'adminUrl' => get_option( 'siteurl' ) . '/wp-admin/',
			),
			$this->api[0][2]
		);
	}

	/**
	 * 取り込んだ直後に送り返さない。公開でも下書きでも。
	 *
	 * @dataProvider status_cases
	 *
	 * @param array $fields 投稿の欄。
	 */
	public function test_not_sent_back( array $fields ) {
		$result = $this->import( $this->event( $fields ) );

		$this->assertSame( '', get_post_meta( $result['postId'], Sync_Queue::PENDING, true ) );
		$this->assertSame( '', get_post_meta( $result['postId'], Sync_Queue::NO_SEND, true ) );
		$this->assertSame( '1', get_post_meta( $result['postId'], Sync_Queue::EXISTS, true ) );

		// 洗い直しで積まれても、内容が同じなら送らない。
		$this->api = array();
		Sync_Queue::mark( $result['postId'] );
		Sync_Queue::run();

		$rejected = 'rejected' === $fields['status'];
		$this->assertSame( $rejected ? 1 : 0, $this->sent( '/unpublish' ) );
		$this->assertSame( 0, count( array_filter( $this->api, static fn( $r ) => 'PUT' === $r[0] ) ) );
	}

	/**
	 * 審査待ちの下書きは、保存しただけでは審査から外れない。
	 */
	public function test_saving_draft_keeps_review() {
		$result = $this->import(
			$this->event(
				array(
					'status'  => 'pendingReview',
					'visible' => false,
				)
			)
		);

		$this->api = array();
		wp_update_post(
			array(
				'ID'           => $result['postId'],
				'post_content' => 'ちょっと直した',
			)
		);
		Sync_Queue::run();

		$this->assertSame( array(), $this->api );
	}

	/**
	 * 公開すると、送り返す欄が揃った PUT が出る。
	 */
	public function test_publish_sends_put_with_kept_fields() {
		$result = $this->import(
			$this->event(
				array(
					'status'  => 'draft',
					'visible' => false,
				)
			)
		);

		$this->api = array();
		wp_update_post(
			array(
				'ID'          => $result['postId'],
				'post_status' => 'publish',
			)
		);
		Sync_Queue::run();

		$this->assertCount( 1, $this->api );
		$this->assertSame( 'PUT', $this->api[0][0] );
		$this->assertSame( 'upload-1', $this->api[0][2]['imageUploadId'] );
		$this->assertSame( 'https://example.org/concert', $this->api[0][2]['publisherEventPageUrl'] );
		$this->assertSame( '市民ホール', $this->api[0][2]['venueName'] );
		$this->assertSame( "## 見どころ\n\n**大事** な話\n\n- 一つ目\n- 二つ目", $this->api[0][2]['description'] );
	}

	/**
	 * 取り込んだ画像は上げ直さない。
	 */
	public function test_image_is_not_uploaded_again() {
		$result = $this->import( $this->event() );

		$record = get_post_meta( $result['postId'], Sync_Image::RECORD, true );
		$this->assertSame( 'upload-1', $record['id'] );
		$this->assertSame( 'upload-1', $record['attachedId'] );
		$this->assertTrue( $record['attached'] );

		$resolved = Sync_Image::resolve( $result['postId'], true );
		$this->assertSame( 'upload-1', $resolved['id'] );
		$this->assertSame( 0, $resolved['requests'] );
	}

	/**
	 * 本文の画像を残して送り返す。
	 */
	public function test_body_images_round_trip() {
		$remote = 'https://cdn.example.com/v4/owned-description/a.jpg';
		$result = $this->import( $this->event( array( 'description' => "前\n\n![会場]({$remote})" ) ) );

		$post = get_post( $result['postId'] );
		$this->assertStringNotContainsString( 'cdn.example.com', $post->post_content );
		$this->assertStringContainsString( 'wp:image', $post->post_content );

		$built = \EventWatch\Sync_Payload::build( $post );
		$this->assertSame( "前\n\n![会場]({$remote})", $built['body']['description'] );
	}

	/**
	 * 結び付けに失敗しても下書きで残り、やり直すと 2 件目を作らない。
	 */
	public function test_link_failure_leaves_draft_and_retry_is_idempotent() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );

		$this->assertSame( 'failed', $first['status'] );
		$this->assertNotEmpty( $first['reason'] );
		$this->assertSame( 'draft', get_post_status( $first['postId'] ) );
		$this->assertSame( '1', get_post_meta( $first['postId'], Sync_Queue::NO_SEND, true ) );
		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Queue::EXISTS, true ) );
		$this->assertSame( self::ID, Event_Import::unlinked_drafts()[0]['id'] );

		$fetches           = $this->image_fetches;
		$this->link_answer = null;
		$second            = $this->import( $this->event() );

		$this->assertSame( 'imported', $second['status'] );
		$this->assertSame( $first['postId'], $second['postId'] );
		$this->assertSame( 'publish', get_post_status( $second['postId'] ) );
		$this->assertSame( $fetches, $this->image_fetches );
		$this->assertSame( array(), Event_Import::unlinked_drafts() );
		$this->assertSame( 1, count( get_posts( array( 'post_type' => Post_Type::POST_TYPE, 'post_status' => 'any', 'fields' => 'ids' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * 応答を失って一覧から消えた投稿も、やり直しで結び付く。
	 */
	public function test_retry_after_lost_response_without_listing() {
		$this->link_answer = new \WP_Error( 'http_request_failed', 'timeout' );
		$first             = $this->import( $this->event() );
		$this->assertSame( 'failed', $first['status'] );

		$this->listed      = array();
		$this->link_answer = $this->http( 200, $this->event() );
		Event_Import::prepare( array( self::ID ) );
		$second = Event_Import::run( self::ID );

		$this->assertSame( 'imported', $second['status'] );
		$this->assertSame( $first['postId'], $second['postId'] );
	}

	/**
	 * 結び付いた後にもう一度送っても、何もしない。
	 */
	public function test_run_twice_does_nothing() {
		$first     = $this->import( $this->event() );
		$this->api = array();
		$second    = Event_Import::run( self::ID );

		$this->assertSame( $first['postId'], $second['postId'] );
		$this->assertSame( 'imported', $second['status'] );
		$this->assertSame( array(), $this->api );
	}

	/**
	 * 画像を入れられなければ、投稿を作らず結び付けない。
	 */
	public function test_image_failure_creates_nothing() {
		$this->image_fails = true;
		$result            = $this->import( $this->event() );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertNotEmpty( $result['reason'] );
		$this->assertSame( array(), $this->api );
		$this->assertSame( 0, Event_Import::find_local( self::ID ) );

		$this->image_fails = false;
		$this->assertSame( 'imported', $this->import( $this->event() )['status'] );
	}

	/**
	 * 入れた画像は、途中で失敗してもやり直しで使い回す。
	 */
	public function test_images_are_reused_on_retry() {
		$remote = 'https://cdn.example.com/v4/owned-description/never.jpg';
		$event  = $this->event( array( 'description' => "![a]({$remote})" ) );

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $remote ) {
				return $url === $remote ? new \WP_Error( 'http_request_failed', 'no' ) : $pre;
			},
			5,
			3
		);
		$this->assertSame( 'failed', $this->import( $event )['status'] );
		$this->assertSame( 1, $this->image_fetches );
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', array( $this, 'answer_request' ), 10, 3 );

		$this->assertSame( 'imported', $this->import( $event )['status'] );
		$this->assertSame( 2, $this->image_fetches );
	}

	/**
	 * 結び付けの失敗の種類ごとの動き。
	 *
	 * @return array<string, array{0: int, 1: array, 2: string}>
	 */
	public function link_error_cases() {
		return array(
			'別のサイトが接続中' => array( 409, array( 'code' => 'eventAlreadyLinked' ), 'failed' ),
			'識別子を別の投稿'  => array( 409, array( 'code' => 'externalIdentifierInUse' ), 'failed' ),
			'無い'        => array( 404, array(), 'failed' ),
			'鍵'         => array( 401, array(), 'stop' ),
			'権限'        => array( 403, array(), 'stop' ),
			'流量'        => array( 429, array(), 'wait' ),
		);
	}

	/**
	 * 失敗の種類ごとに、次の動きが分かれ、下書きは残る。
	 *
	 * @dataProvider link_error_cases
	 *
	 * @param int    $status   応答のステータス。
	 * @param array  $body     応答の本文。
	 * @param string $expected 戻りの状態。
	 */
	public function test_link_errors( $status, array $body, $expected ) {
		$this->link_answer = $this->http( $status, $body );
		$result            = $this->import( $this->event() );

		$this->assertSame( $expected, $result['status'] );
		$local = Event_Import::find_local( self::ID );
		$this->assertSame( 'draft', get_post_status( $local ) );
		$this->assertSame( '', get_post_meta( $local, Sync_Queue::EXISTS, true ) );
		if ( 'wait' === $expected ) {
			$this->assertGreaterThan( 0, $result['retryAfter'] );
			$this->assertGreaterThan( time(), (int) get_option( Sync_Queue::HOLD_OPTION ) );
		} else {
			$this->assertNotEmpty( $result['reason'] );
		}
	}

	/**
	 * 2 つの 409 は文が違い、どちらも code の無い 409 とも違う。
	 */
	public function test_conflicts_have_different_reasons() {
		$reasons = array();
		foreach ( array( array( 'code' => 'eventAlreadyLinked' ), array( 'code' => 'externalIdentifierInUse' ), array() ) as $body ) {
			$this->link_answer = $this->http( 409, $body );
			$reasons[]         = $this->import( $this->event() )['reason'];
		}

		$this->assertCount( 3, array_unique( $reasons ) );
	}

	/**
	 * 別のキーに結び付いたサイトの 403 は、新しいキーの発行ではなく古いキーの失効を案内する。ほかの 403 の文は変えない。
	 */
	public function test_site_bound_link_asks_to_revoke_the_old_key() {
		$this->link_answer = $this->http( 403, array( 'code' => 'siteBoundToAnotherKey' ) );
		$bound             = $this->import( $this->event() );

		$this->assertSame( 'stop', $bound['status'] );
		$this->assertStringContainsString( 'Revoke the old key', $bound['reason'] );
		$this->assertStringNotContainsString( 'Issue a new key', $bound['reason'] );

		$this->link_answer = $this->http( 403, array( 'code' => 'other' ) );
		$other             = $this->import( $this->event() );

		$this->assertSame( Event_Import::stop_reason( Api_Client::FORBIDDEN ), $other['reason'] );
		$this->assertStringContainsString( 'Issue a new key', $other['reason'] );
	}

	/**
	 * 取り込む前の状態の語。
	 *
	 * @return array<string, array{0: array, 1: string, 2: string}>
	 */
	public function event_status_cases() {
		return array(
			'下書き'       => array( array( 'status' => 'draft' ), 'withdrawn', 'Draft' ),
			'非公開'       => array( array( 'status' => 'private' ), 'withdrawn', 'Private' ),
			'審査待ち'      => array( array( 'status' => 'pendingReview' ), 'review', 'Under review' ),
			'掲載中'       => array(
				array(
					'status'  => 'published',
					'visible' => true,
				),
				'live',
				'Listed',
			),
			'差し戻し'      => array( array( 'status' => 'rejected' ), 'fix', 'Changes requested' ),
			'運営の停止'     => array(
				array(
					'status'              => 'published',
					'suspendedByOperator' => true,
				),
				'stopped',
				'Listing paused',
			),
			'運営の停止の下書き' => array(
				array(
					'status'              => 'draft',
					'suspendedByOperator' => true,
				),
				'stopped',
				'Listing paused',
			),
		);
	}

	/**
	 * 状態の語。一覧の列の語を使い、下書きと非公開だけ別の語にする。
	 *
	 * @dataProvider event_status_cases
	 *
	 * @param array  $event 投稿の欄。
	 * @param string $state 状態の種類。
	 * @param string $label 語。
	 */
	public function test_event_status( array $event, $state, $label ) {
		$status = \EventWatch\Sync_Rest::event_status( $event );

		$this->assertSame( $state, $status['state'] );
		$this->assertSame( $label, $status['label'] );
	}

	/**
	 * 差し戻しの理由は差し戻しのときだけ出す。
	 */
	public function test_event_status_reason() {
		$this->assertSame(
			'日付',
			\EventWatch\Sync_Rest::event_status(
				array(
					'status'          => 'rejected',
					'rejectionReason' => '日付',
				)
			)['reason']
		);
		$this->assertSame(
			'',
			\EventWatch\Sync_Rest::event_status(
				array(
					'status'          => 'draft',
					'rejectionReason' => '日付',
				)
			)['reason']
		);
	}

	/**
	 * ゴミ箱にあるこのサイトの投稿へは結び直さず、新しく作る。
	 */
	public function test_trashed_own_post_creates_new() {
		$post_id = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		wp_trash_post( $post_id );

		$result = $this->import(
			$this->event(
				array(
					'externalSiteId' => Site_Identity::id(),
					'externalId'     => (string) $post_id,
				)
			)
		);

		$this->assertNotSame( $post_id, $result['postId'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/**
	 * 複製の選択と削除で、取り込みの記録も消える。
	 */
	public function test_forget_all_clears_import_records() {
		$remote     = 'https://cdn.example.com/v4/owned-description/a.jpg';
		$result     = $this->import( $this->event( array( 'description' => "![a]({$remote})" ) ) );
		$attachment = (int) get_post_thumbnail_id( $result['postId'] );

		Sync_Queue::forget_all();

		$this->assertSame( '', get_post_meta( $result['postId'], Sync_Queue::IMPORT_SOURCE, true ) );
		$this->assertSame( '', get_post_meta( $result['postId'], Sync_Queue::IMPORT_IMAGES, true ) );
		$this->assertSame( '', get_post_meta( $attachment, Sync_Queue::IMPORT_IMAGE_SOURCE, true ) );
	}

	/**
	 * 印を止める区間は入れ子にでき、例外でも元に戻る。
	 */
	public function test_without_marking_restores() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$publish = static function ( $title ) use ( $post_id ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
		};

		Sync_Queue::without_marking(
			static function () use ( $publish ) {
				Sync_Queue::without_marking( '__return_null' );
				$publish( 'a' );
			}
		);
		$this->assertSame( '', get_post_meta( $post_id, Sync_Queue::PENDING, true ) );

		try {
			Sync_Queue::without_marking(
				static function () {
					throw new \RuntimeException( 'x' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'x', $e->getMessage() );
		}

		$publish( 'b' );
		$this->assertNotSame( '', get_post_meta( $post_id, Sync_Queue::PENDING, true ) );
	}

	/**
	 * 下書きの間に公開されても、別の投稿として送らない。
	 */
	public function test_unlinked_draft_is_not_sent_when_published() {
		$this->link_answer = $this->http( 503 );
		$result            = $this->import( $this->event() );

		$this->api = array();
		wp_update_post(
			array(
				'ID'          => $result['postId'],
				'post_status' => 'publish',
			)
		);
		Sync_Queue::run();

		$this->assertSame( array(), $this->api );
	}

	/**
	 * 切り離し中のこのサイトの投稿は、新しく作らず結び直す。内容と状態を変えない。
	 */
	public function test_own_detached_post_is_relinked() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => '手元の題',
				'post_content' => '手元の本文',
			)
		);
		$event   = $this->event(
			array(
				'externalSiteId' => Site_Identity::id(),
				'externalId'     => (string) $post_id,
				'detachedAt'     => '2030-01-01T00:00:00.000+0000',
			)
		);

		$result = $this->import( $event );

		$this->assertSame( 'imported', $result['status'] );
		$this->assertSame( $post_id, $result['postId'] );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( '手元の本文', get_post( $post_id )->post_content );
		$this->assertSame( '1', get_post_meta( $post_id, Sync_Queue::EXISTS, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Sync_Queue::HASH, true ) );
		$this->assertSame( 0, $this->image_fetches );
	}

	/**
	 * 取り込んで結び付いた後に切り離された投稿も、取り込み直すと結び直す。内容と状態を変えない。
	 */
	public function test_detached_imported_post_is_relinked() {
		$first   = $this->import(
			$this->event(
				array(
					'status'  => 'draft',
					'visible' => false,
				)
			)
		);
		$post_id = $first['postId'];
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '切り離しの間に直した本文',
			)
		);
		$fetches = $this->image_fetches;

		$second = $this->import(
			$this->event(
				array(
					'status'         => 'draft',
					'visible'        => false,
					'externalSiteId' => Site_Identity::id(),
					'externalId'     => (string) $post_id,
					'detachedAt'     => '2030-01-01T00:00:00.000+0000',
				)
			)
		);

		$this->assertSame( 'imported', $second['status'] );
		$this->assertSame( $post_id, $second['postId'] );
		$this->assertSame( 1, $this->sent( '/link' ) );
		$this->assertCount( 1, $this->api );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( '切り離しの間に直した本文', get_post( $post_id )->post_content );
		$this->assertSame( '1', get_post_meta( $post_id, Sync_Queue::EXISTS, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Sync_Queue::HASH, true ) );
		$this->assertSame( $fetches, $this->image_fetches );
	}

	/**
	 * 結び直しに失敗しても、続けて送り直すと結び直す。
	 */
	public function test_failed_relink_of_imported_post_is_retried() {
		$post_id = $this->import( $this->event() )['postId'];

		$this->link_answer = $this->http( 503 );
		$first             = $this->import(
			$this->event(
				array(
					'externalSiteId' => Site_Identity::id(),
					'externalId'     => (string) $post_id,
					'detachedAt'     => '2030-01-01T00:00:00.000+0000',
				)
			)
		);
		$this->assertSame( 'failed', $first['status'] );

		$this->link_answer = null;
		$this->api         = array();
		$second            = Event_Import::run( self::ID );

		$this->assertSame( 'imported', $second['status'] );
		$this->assertSame( $post_id, $second['postId'] );
		$this->assertSame( 1, $this->sent( '/link' ) );
	}

	/**
	 * 別のサイトの切り離し中の投稿は新しく作る。
	 */
	public function test_other_site_detached_post_creates_new() {
		$post_id = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		$result  = $this->import(
			$this->event(
				array(
					'externalSiteId' => 'other-site',
					'externalId'     => (string) $post_id,
				)
			)
		);

		$this->assertNotSame( $post_id, $result['postId'] );
	}

	/**
	 * ゴミ箱の投稿は数えず、もう一度取り込める。
	 */
	public function test_trashed_draft_can_be_imported_again() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		wp_trash_post( $first['postId'] );

		$this->link_answer = null;
		$second            = $this->import( $this->event() );

		$this->assertSame( 'imported', $second['status'] );
		$this->assertNotSame( $first['postId'], $second['postId'] );
	}

	/**
	 * EventWatch から消えた投稿の記録は、取り込みのやり直しの対象から外す。
	 */
	public function test_forget_remote_clears_import_records() {
		$result = $this->import( $this->event( array( 'description' => '![a](https://cdn.example.com/a.jpg)' ) ) );

		Sync_Queue::forget_remote( $result['postId'] );

		$this->assertSame( '', get_post_meta( $result['postId'], Sync_Queue::IMPORT_SOURCE, true ) );
		$this->assertSame( '', get_post_meta( $result['postId'], Sync_Queue::IMPORT_IMAGES, true ) );
		$this->assertSame( array(), Event_Import::unlinked_drafts() );
	}

	/**
	 * 画像でない宛先は取得しない。
	 */
	public function test_non_image_address_is_not_fetched() {
		$result = $this->import(
			$this->event(
				array(
					'thumbnails'  => array(),
					'description' => '![a](https://cdn.example.com/x.html)',
				)
			)
		);

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 0, $this->image_fetches );
	}

	/**
	 * 画像でない中身はアップロードの場所へ置かない。
	 */
	public function test_non_image_content_is_not_stored() {
		$this->image_content = DIR_TESTDATA . '/formatting/entities.txt';
		$before              = count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$result = $this->import( $this->event() );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 0, Event_Import::find_local( self::ID ) );
		$this->assertSame( $before, count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * 本文の文字をショートコードとして動かさない。
	 */
	public function test_shortcodes_in_body_do_not_run() {
		add_shortcode(
			'c12_probe',
			static function () {
				return 'RAN';
			}
		);
		$result = $this->import( $this->event( array( 'description' => "[c12_probe]\n\n`[c12_probe]`\n\n[c12_probe](https://example.com/)" ) ) );

		$html = do_shortcode( get_post( $result['postId'] )->post_content );
		remove_shortcode( 'c12_probe' );

		$this->assertStringNotContainsString( 'RAN', $html );
	}

	/**
	 * 結び付く前に手を入れて公開した下書きは、やり直しの後に送る。
	 */
	public function test_touched_draft_is_sent_after_retry() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import(
			$this->event(
				array(
					'status'  => 'draft',
					'visible' => false,
				)
			)
		);
		wp_update_post(
			array(
				'ID'           => $first['postId'],
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>直した</p><!-- /wp:paragraph -->',
			)
		);

		$this->link_answer = null;
		$this->listed      = array(
			$this->event(
				array(
					'status'  => 'draft',
					'visible' => false,
				)
			),
		);
		$this->api         = array();
		$this->assertSame( 'imported', Event_Import::run( self::ID )['status'] );
		Sync_Queue::run();

		$puts = array_values( array_filter( $this->api, static fn( $r ) => 'PUT' === $r[0] ) );
		$this->assertCount( 1, $puts );
		$this->assertSame( '直した', $puts[0][2]['description'] );
		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Queue::NO_SEND, true ) );
	}

	/**
	 * 結び付く前に公開しただけの下書きも、やり直しの後に提出する。
	 */
	public function test_published_draft_is_sent_after_retry() {
		$draft             = array(
			'status'  => 'draft',
			'visible' => false,
		);
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event( $draft ) );
		wp_update_post(
			array(
				'ID'          => $first['postId'],
				'post_status' => 'publish',
			)
		);

		$this->link_answer = null;
		$this->listed      = array( $this->event( $draft ) );
		$this->api         = array();
		Event_Import::run( self::ID );
		Sync_Queue::run();

		$this->assertCount( 1, array_filter( $this->api, static fn( $r ) => 'PUT' === $r[0] ) );
		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Queue::IMPORT_ORIGINAL, true ) );
	}

	/**
	 * 掲載中の投稿の下書きに手を入れていたら、公開したときに直した内容を送る。
	 */
	public function test_edited_listed_draft_is_sent_after_retry() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		wp_update_post(
			array(
				'ID'           => $first['postId'],
				'post_content' => '<!-- wp:paragraph --><p>直した</p><!-- /wp:paragraph -->',
			)
		);

		$this->link_answer = null;
		$this->api         = array();
		Event_Import::run( self::ID );
		Sync_Queue::run();

		$puts = array_values( array_filter( $this->api, static fn( $r ) => 'PUT' === $r[0] ) );
		$this->assertCount( 1, $puts );
		$this->assertSame( '直した', $puts[0][2]['description'] );
	}

	/**
	 * 結び付く前にアイキャッチ画像を外していたら、外した内容を送る。
	 */
	public function test_removed_thumbnail_is_sent_after_retry() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		delete_post_thumbnail( $first['postId'] );

		$this->link_answer = null;
		$this->api         = array();
		Event_Import::run( self::ID );
		Sync_Queue::run();

		$puts = array_values( array_filter( $this->api, static fn( $r ) => 'PUT' === $r[0] ) );
		$this->assertCount( 1, $puts );
		$this->assertArrayNotHasKey( 'imageUploadId', $puts[0][2] );
	}

	/**
	 * オーナーが選んだ状態をやり直しで変えない。
	 */
	public function test_retry_keeps_chosen_status() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		wp_update_post(
			array(
				'ID'          => $first['postId'],
				'post_status' => 'private',
			)
		);

		$this->link_answer = null;
		$this->assertSame( 'imported', Event_Import::run( self::ID )['status'] );

		$this->assertSame( 'private', get_post_status( $first['postId'] ) );
	}

	/**
	 * 「送らない」を切って公開した下書きは、結び付いたことにしない。
	 */
	public function test_toggled_draft_is_not_reported_as_imported() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		delete_post_meta( $first['postId'], Sync_Queue::NO_SEND );
		wp_update_post(
			array(
				'ID'          => $first['postId'],
				'post_status' => 'publish',
			)
		);
		Sync_Queue::run();
		$this->assertSame( '1', get_post_meta( $first['postId'], Sync_Queue::EXISTS, true ) );

		$this->link_answer = $this->http( 409, array( 'code' => 'externalIdentifierInUse' ) );
		$result            = Event_Import::run( self::ID );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( $first['postId'], $result['postId'] );
		$this->assertSame( self::ID, Event_Import::unlinked_drafts()[0]['id'] );
	}

	/**
	 * 複製の選択で、結び付く前の下書きの「送らない」を残さない。利用者が選んだものは残す。
	 */
	public function test_forget_all_clears_import_no_send_only() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		$chosen            = self::factory()->post->create( array( 'post_type' => Post_Type::POST_TYPE ) );
		update_post_meta( $chosen, Sync_Queue::NO_SEND, 1 );

		Sync_Queue::forget_all();

		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Queue::NO_SEND, true ) );
		$this->assertSame( '1', get_post_meta( $chosen, Sync_Queue::NO_SEND, true ) );
	}

	/**
	 * 最後のページを取り込み終えたら、前のページを読む。
	 */
	public function test_read_list_falls_back_to_last_page() {
		$pages = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$pages ) {
				if ( false === strpos( $url, '/org-api/events' ) ) {
					return $pre;
				}
				preg_match( '/page=(\d+)/', $url, $page );
				$pages[] = (int) $page[1];
				return $this->http(
					200,
					array(
						'list'  => 0 === (int) $page[1] ? array( $this->event() ) : array(),
						'total' => 20,
					)
				);
			},
			5,
			3
		);

		$listed = Import_Admin::read_list( 2 );

		$this->assertSame( array( 1, 0 ), $pages );
		$this->assertCount( 1, $listed['list'] );
	}

	/**
	 * 本当に空なら読み直さない。
	 */
	public function test_read_list_empty() {
		$this->assertSame( array(), Import_Admin::read_list( 1 )['list'] );
		$this->assertCount( 1, $this->api );
	}

	/**
	 * 手を入れずにやり直したら送らない。
	 */
	public function test_untouched_retry_sends_nothing() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );

		$this->link_answer = null;
		$this->api         = array();
		Event_Import::run( self::ID );
		Sync_Queue::run();

		$this->assertCount( 1, $this->api );
		$this->assertStringEndsWith( '/link', $this->api[0][1] );
		$this->assertSame( 'publish', get_post_status( $first['postId'] ) );
	}

	/**
	 * 下書きの後に送るサイトの URL が変わっても、手元で変えたと読まず、結び付けの後に送らない。
	 */
	public function test_site_url_change_before_retry_is_not_a_touch() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );

		// 手元の環境は WP_SITEURL を定義していて、option の更新では値が変わらない。
		$admin = static function ( $url ) {
			return $url . '/wp';
		};
		// スキームだけの変更は移転の確認を通らない。パーマリンクを使わない投稿なので、公式ページは変わらない。
		$home = static function ( $url ) {
			return set_url_scheme( $url, 'https' );
		};
		add_filter( 'option_siteurl', $admin, 20 );
		add_filter( 'option_home', $home, 20 );

		$this->link_answer = null;
		$this->api         = array();
		Event_Import::run( self::ID );
		Sync_Queue::run();
		remove_filter( 'option_siteurl', $admin, 20 );
		remove_filter( 'option_home', $home, 20 );

		$this->assertCount( 1, $this->api );
		$this->assertStringEndsWith( '/link', $this->api[0][1] );
		$this->assertStringEndsWith( '/wp/wp-admin/', $this->api[0][2]['adminUrl'] );
		$this->assertStringStartsWith( 'https://', $this->api[0][2]['siteUrl'] );
		$this->assertSame( 'publish', get_post_status( $first['postId'] ) );
	}

	/**
	 * 一覧を読めないときの動きを、失敗の種類で分ける。
	 */
	public function test_rest_prepare_errors() {
		$cases = array(
			array( 401, 'stop' ),
			array( 500, 'failed' ),
			array( 400, 'failed' ),
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$cases ) {
				return false !== strpos( $url, '/org-api/events' ) ? $this->http( $cases[0][0] ) : $pre;
			},
			5,
			3
		);

		while ( $cases ) {
			$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/eventwatch/v1/import/prepare' );
			$request->set_body_params( array( 'eventIds' => array( self::ID ) ) );
			$data = rest_do_request( $request )->get_data();
			$this->assertSame( $cases[0][1], $data['status'], (string) $cases[0][0] );
			$this->assertNotEmpty( $data['reason'] );
			array_shift( $cases );
		}
	}

	/**
	 * 結び付く前に手を入れた下書きは、下書きのままなら審査から外さない。
	 */
	public function test_touched_draft_stays_quiet_while_draft() {
		$pending           = array(
			'status'  => 'pendingReview',
			'visible' => false,
		);
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event( $pending ) );
		wp_update_post(
			array(
				'ID'           => $first['postId'],
				'post_content' => '<!-- wp:paragraph --><p>直した</p><!-- /wp:paragraph -->',
			)
		);

		$this->link_answer = null;
		$this->listed      = array( $this->event( $pending ) );
		$this->api         = array();
		$this->assertSame( 'imported', Event_Import::run( self::ID )['status'] );
		Sync_Queue::run();

		$this->assertSame( array(), array_values( array_filter( $this->api, static fn( $r ) => false === strpos( $r[1], '/link' ) ) ) );
	}

	/**
	 * 結び付く前にアイキャッチ画像を替えたら、古い ID と組にしない。
	 */
	public function test_replaced_thumbnail_is_not_recorded() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		$other             = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		set_post_thumbnail( $first['postId'], $other );

		$this->link_answer = null;
		$this->assertSame( 'imported', Event_Import::run( self::ID )['status'] );

		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Image::RECORD, true ) );
	}

	/**
	 * 掲載中の投稿の下書きを非公開にしていたら、やり直しの後に取り下げを送る。
	 */
	public function test_private_choice_is_sent_after_retry() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		wp_update_post(
			array(
				'ID'          => $first['postId'],
				'post_status' => 'private',
			)
		);

		$this->link_answer = null;
		$this->api         = array();
		Event_Import::run( self::ID );
		Sync_Queue::run();

		$this->assertSame( 1, $this->sent( '/unpublish' ) );
	}

	/**
	 * 投稿を作った時点で控えの枠がある。ここより後で落ちても、結び付いていないと読める。
	 */
	public function test_original_exists_when_post_is_created() {
		$seen  = null;
		$watch = static function ( $post_id ) use ( &$seen ) {
			if ( null === $seen ) {
				$seen = get_post_meta( $post_id, Sync_Queue::IMPORT_ORIGINAL, true );
			}
		};
		add_action( 'wp_after_insert_post', $watch );

		$this->import( $this->event() );
		remove_action( 'wp_after_insert_post', $watch );

		$this->assertIsArray( $seen );
	}

	/**
	 * 控えを書く前に落ちても、結び付いていないと読む。
	 */
	public function test_placeholder_original_links_on_retry() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		update_post_meta(
			$first['postId'],
			Sync_Queue::IMPORT_ORIGINAL,
			array(
				'content'   => '',
				'thumbnail' => 0,
			)
		);

		$this->link_answer = null;
		$this->api         = array();
		Event_Import::run( self::ID );

		$this->assertSame( 1, $this->sent( '/link' ) );
		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Queue::NO_SEND, true ) );
	}

	/**
	 * ゴミ箱の結び付く前の下書きの「送らない」も消す。
	 */
	public function test_forget_all_clears_trashed_import_no_send() {
		$this->link_answer = $this->http( 503 );
		$first             = $this->import( $this->event() );
		wp_trash_post( $first['postId'] );

		Sync_Queue::forget_all();

		$this->assertSame( '', get_post_meta( $first['postId'], Sync_Queue::NO_SEND, true ) );
	}

	/**
	 * キーを断られて止まった件にも、残した下書きへのリンクを返す。
	 */
	public function test_stop_returns_edit_link() {
		$this->link_answer = $this->http( 401 );
		$result            = $this->import( $this->event() );

		$this->assertSame( 'stop', $result['status'] );
		$this->assertStringContainsString( 'post=' . $result['postId'], $result['editUrl'] );
	}

	/**
	 * EventWatch から消えた投稿は、結び付く前でもやり直しの案内に出さない。
	 */
	public function test_forget_remote_clears_unlinked_draft() {
		$this->link_answer = $this->http( 503 );
		$result            = $this->import( $this->event() );
		$this->assertCount( 1, Event_Import::unlinked_drafts() );

		Sync_Queue::forget_remote( $result['postId'] );

		$this->assertSame( array(), Event_Import::unlinked_drafts() );
	}

	/**
	 * 同じ投稿を同時に進めない。
	 */
	public function test_lock_makes_second_request_wait() {
		add_option( Event_Import::LOCK_PREFIX . md5( self::ID ), time(), '', false );

		$result = Event_Import::run( self::ID );

		$this->assertSame( 'wait', $result['status'] );
		$this->assertSame( Event_Import::BUSY_WAIT, $result['retryAfter'] );
		$this->assertSame( array(), $this->api );
	}

	/**
	 * 落ちた要求の錠は残らない。
	 */
	public function test_stale_lock_is_taken() {
		add_option( Event_Import::LOCK_PREFIX . md5( self::ID ), time() - Sync_Queue::LOCK_TTL - 1, '', false );

		$this->assertSame( 'imported', $this->import( $this->event() )['status'] );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 覚えた値は使えないので、行を直接読む。
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", Event_Import::LOCK_PREFIX . md5( self::ID ) ) ) );
	}

	/**
	 * 一覧に無く手元にも無い投稿は失敗にする。内容をブラウザーから受け取らない。
	 */
	public function test_unknown_event_fails() {
		Event_Import::prepare( array( self::ID ) );
		$result = Event_Import::run( self::ID );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 0, Event_Import::find_local( self::ID ) );
	}

	/**
	 * 選んだ投稿を先頭から探す。結び付けで位置がずれても読み飛ばさない。
	 */
	public function test_prepare_reads_from_first_page() {
		$this->listed = array( $this->event() );

		$items = Event_Import::prepare( array( self::ID ) );

		$this->assertSame( self::ID, $items[0]['id'] );
		$this->assertStringContainsString( 'page=0&size=' . Event_Import::FIND_SIZE, $this->api[0][1] );
	}

	/**
	 * 終わった投稿の判定は、一覧の開催予定と同じ境目。
	 */
	public function test_has_ended() {
		$this->assertFalse( Event_Import::has_ended( $this->event() ) );

		$today = wp_date( 'Y-m-d', Event_Dates::upcoming_boundary() );
		$this->assertFalse(
			Event_Import::has_ended(
				$this->event(
					array(
						'zoneId'           => wp_timezone_string(),
						'occurrenceRanges' => array(
							array(
								'startAt' => $today . ' 00:00',
								'closeAt' => '',
							),
						),
					)
				)
			)
		);
		$this->assertTrue(
			Event_Import::has_ended(
				$this->event(
					array(
						'occurrenceRanges' => array(
							array(
								'startAt' => '2020-01-01 10:00',
								'closeAt' => '2020-01-01 12:00',
							),
						),
					)
				)
			)
		);
	}

	/**
	 * 権限の無い人は REST を呼べない。
	 */
	public function test_rest_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array(
			'/eventwatch/v1/import/prepare' => array( 'eventIds' => array( self::ID ) ),
			'/eventwatch/v1/import/run'     => array( 'eventId' => self::ID ),
		) as $route => $params ) {
			$request = new WP_REST_Request( WP_REST_Server::CREATABLE, $route );
			$request->set_body_params( $params );
			$this->assertSame( 403, rest_do_request( $request )->get_status(), $route );
		}
		$this->assertSame( array(), $this->api );
	}

	/**
	 * REST の通し。
	 */
	public function test_rest_flow() {
		$this->listed = array( $this->event() );

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/eventwatch/v1/import/prepare' );
		$request->set_body_params( array( 'eventIds' => array( self::ID ) ) );
		$prepared = rest_do_request( $request )->get_data();
		$this->assertSame( 'ok', $prepared['status'] );
		$this->assertSame( 'A & B の <Roland> 演奏会', $prepared['items'][0]['title'] );

		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/eventwatch/v1/import/run' );
		$request->set_body_params( array( 'eventId' => self::ID ) );
		$this->assertSame( 'imported', rest_do_request( $request )->get_data()['status'] );
	}

	/**
	 * ID の形でないものは断る。
	 */
	public function test_rest_rejects_bad_id() {
		$request = new WP_REST_Request( WP_REST_Server::CREATABLE, '/eventwatch/v1/import/run' );
		$request->set_body_params( array( 'eventId' => '../etc' ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * キーが無いサイトにメニューを出さない。
	 */
	public function test_menu_needs_key() {
		global $submenu;
		$parent = 'edit.php?post_type=' . Post_Type::POST_TYPE;

		delete_option( Connection::KEY_OPTION );
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストの間だけ。
		Import_Admin::add_page();
		$this->assertArrayNotHasKey( $parent, $submenu );

		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );
		Import_Admin::add_page();
		$this->assertSame( Import_Admin::PAGE, $submenu[ $parent ][0][2] );
	}
}
