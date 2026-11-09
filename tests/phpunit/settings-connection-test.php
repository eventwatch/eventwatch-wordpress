<?php
/**
 * 設定画面の接続の行・操作・通知と、サイトの識別のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Api_Client;
use EventWatch\Connection;
use EventWatch\Connection_Admin;
use EventWatch\Event_Meta;
use EventWatch\Plugin;
use EventWatch\Post_Type;
use EventWatch\Settings;
use EventWatch\Site_Identity;
use EventWatch\Sync_Status;
use RuntimeException;
use WP_UnitTestCase;
use WPDieException;

/**
 * Settings・Connection_Admin・Site_Identity のテスト。
 */
class Settings_Connection_Test extends WP_UnitTestCase {

	const KEY = 'ewk_a1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Ba1Bxyz1';

	/**
	 * EventWatch への要求。
	 *
	 * @var array[]
	 */
	private $sent = array();

	/**
	 * 管理画面の関数を読み込み、要求を記録する。
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		$this->sent                    = array();
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 前のテストの誤りを数えない。
		delete_option( Connection::KEY_OPTION );
		Connection::forget();
		Site_Identity::use_as_moved();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->sent[] = array(
					'args' => $args,
					'url'  => $url,
				);
				return new \WP_Error( 'http_request_failed', 'EventWatch is down' );
			},
			10,
			3
		);
	}

	/**
	 * 画面とユーザーを戻す。
	 */
	public function tear_down() {
		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 次のテストへ画面を持ち越さない。
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 出力を受ける。
	 *
	 * @param callable $callback 描く処理。
	 * @return string
	 */
	private function output_of( callable $callback ) {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * 管理者になる。
	 *
	 * @return int
	 */
	private function log_in_as_admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * リダイレクトを例外にして、操作の最後の exit を避ける。
	 *
	 * @return void
	 */
	private function stop_at_redirect() {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- テストの中で読むだけ。
			}
		);
	}

	/**
	 * 操作を nonce つきで呼ぶ。戻り先の URL を返す。
	 *
	 * @param string   $action  操作。
	 * @param callable $handler 受ける処理。
	 * @return string
	 */
	private function run_action( $action, callable $handler ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$this->stop_at_redirect();

		try {
			$handler();
		} catch ( RuntimeException $redirect ) {
			return substr( $redirect->getMessage(), strlen( 'redirect:' ) );
		}

		$this->fail( 'The action did not redirect.' );
	}

	/**
	 * 操作と受ける処理の表。
	 *
	 * @return array[]
	 */
	public function actions() {
		return array(
			'check'  => array( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) ),
			'remove' => array( Connection_Admin::ACTION_REMOVE_KEY, array( Connection_Admin::class, 'handle_remove_key' ) ),
			'moved'  => array( Connection_Admin::ACTION_MOVED, array( Connection_Admin::class, 'handle_moved' ) ),
			'copy'   => array( Connection_Admin::ACTION_COPY, array( Connection_Admin::class, 'handle_copy' ) ),
			'hide'   => array( Connection_Admin::ACTION_HIDE, array( Connection_Admin::class, 'handle_hide_notice' ) ),
		);
	}

	/**
	 * Nonce が無いと何も変えない。
	 *
	 * @dataProvider actions
	 *
	 * @param string   $action  操作。
	 * @param callable $handler 受ける処理。
	 */
	public function test_actions_need_a_nonce( $action, $handler ) {
		$user_id = $this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();

		try {
			$handler();
			$this->fail( 'wp_die was not called: ' . $action );
		} catch ( WPDieException $e ) {
			$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
			$this->assertSame( $id, Site_Identity::id() );
			$this->assertSame( array(), $this->sent );
			$this->assertSame( '', get_user_meta( $user_id, Connection_Admin::HIDE_META, true ) );
		}
	}

	/**
	 * 管理者以外は nonce があっても何も変えない。
	 *
	 * @dataProvider actions
	 *
	 * @param string   $action  操作。
	 * @param callable $handler 受ける処理。
	 */
	public function test_actions_need_manage_options( $action, $handler ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();

		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );

		try {
			$handler();
			$this->fail( 'wp_die was not called: ' . $action );
		} catch ( WPDieException $e ) {
			$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
			$this->assertSame( $id, Site_Identity::id() );
			$this->assertSame( array(), $this->sent );
			$this->assertSame( '', get_user_meta( $user_id, Connection_Admin::HIDE_META, true ) );
		}
	}

	/**
	 * 操作が通知へ渡した結果。本体の settings_errors() が読む transient から読む。
	 *
	 * @return array[]
	 */
	private function reported() {
		$errors = get_transient( 'settings_errors' );
		delete_transient( 'settings_errors' );
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 次の操作へ持ち越さない。
		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * 確かめると EventWatch を呼び、設定画面へ戻る。届かなければ警告の通知を渡す。
	 */
	public function test_check_action() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );

		$location = $this->run_action( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( Connection::UNREACHABLE, Connection::state()['status'] );
		$this->assertStringContainsString( 'page=eventwatch', $location );
		$this->assertStringContainsString( 'settings-updated=true', $location );

		$reported = $this->reported();
		$this->assertCount( 1, $reported );
		$this->assertSame( 'warning', $reported[0]['type'] );
	}

	/**
	 * つながれば団体名の成功の通知を渡す。本体は文を escape しないので、渡す時点で escape してある。
	 */
	public function test_check_reports_escaped_success() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{"displayName":"A & B"}',
					'response' => array( 'code' => 200 ),
				);
			}
		);
		add_filter(
			'gettext_eventwatch',
			static function ( $translation ) {
				return 0 === strpos( $translation, 'Connected to EventWatch' ) ? '<img src=x>' . $translation : $translation;
			}
		);

		$this->run_action( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) );

		$reported = $this->reported();
		$this->assertSame( 'success', $reported[0]['type'] );
		$this->assertStringContainsString( '&lt;img', $reported[0]['message'] );
		$this->assertStringNotContainsString( '<img', $reported[0]['message'] );
		$this->assertStringContainsString( 'A &amp; B', $reported[0]['message'] );
	}

	/**
	 * 失効では結果の通知を渡さない。同じ内容を通知が出す。
	 */
	public function test_check_does_not_report_a_revoked_key_twice() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array( 'code' => 401 ),
				);
			}
		);

		$this->run_action( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) );

		$this->assertSame( Connection::UNAUTHORIZED, Connection::state()['status'] );
		$this->assertSame( array(), $this->reported() );
	}

	/**
	 * 2 回目からは option へ書かない。定数のキーのサイトでは、キーの行が空のまま残る。
	 */
	public function test_ensure_does_not_write_twice() {
		delete_option( Connection::KEY_OPTION );
		Site_Identity::ensure();

		// 値が同じ INSERT は added_option を起こさないので、発行した文を数える。
		$writes = 0;
		add_filter(
			'query',
			static function ( $query ) use ( &$writes ) {
				if ( false !== stripos( $query, 'INSERT INTO' ) ) {
					++$writes;
				}
				return $query;
			}
		);

		Site_Identity::ensure();
		Site_Identity::has_changed();

		$this->assertSame( 0, $writes );
	}

	/**
	 * 保存したキーを ensure で消さない。
	 */
	public function test_ensure_keeps_a_saved_key() {
		update_option( Connection::KEY_OPTION, self::KEY );

		Site_Identity::ensure();

		$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
	}

	/**
	 * キーを削除すると、自動の読み込みに載らないまま空になる。
	 */
	public function test_remove_key_action() {
		global $wpdb;

		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );

		$this->run_action( Connection_Admin::ACTION_REMOVE_KEY, array( Connection_Admin::class, 'handle_remove_key' ) );

		$this->assertSame( '', Connection::api_key() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Connection::KEY_OPTION ) ), array( 'off', 'no', 'auto-off' ) );
	}

	/**
	 * キーを保存できる。形の違う値と空は元の値を保つ。
	 */
	public function test_sanitize_api_key() {
		update_option( Connection::KEY_OPTION, ' ' . self::KEY . ' ' );
		$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );

		foreach ( array( 'ewk_short', 'abc', 'ewk_' . str_repeat( 'a', 44 ), 'ewk_' . str_repeat( '-', 43 ), self::KEY . "\nx", '"><script>' ) as $bad ) {
			update_option( Connection::KEY_OPTION, $bad );
			$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ), $bad );
		}

		update_option( Connection::KEY_OPTION, array( 'x' ) );
		$this->assertSame( self::KEY, get_option( Connection::KEY_OPTION ) );
	}

	/**
	 * 空の保存は「替えない」なので、誤りを出さない。形の違う値には出す。
	 */
	public function test_empty_key_is_not_an_error() {
		global $wp_settings_errors;

		update_option( Connection::KEY_OPTION, self::KEY );
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- テストごとに空から数える。

		$this->assertSame( self::KEY, Settings::sanitize_api_key( '' ) );
		$this->assertSame( array(), get_settings_errors( Connection::KEY_OPTION ) );

		$this->assertSame( self::KEY, Settings::sanitize_api_key( 'abc' ) );
		$this->assertCount( 1, get_settings_errors( Connection::KEY_OPTION ) );
	}

	/**
	 * 固定のキーがあれば、入力を受けない。
	 */
	public function test_sanitize_ignores_input_when_fixed() {
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return 'ewk_fixed';
			}
		);

		update_option( Connection::KEY_OPTION, self::KEY );

		$this->assertSame( '', (string) get_option( Connection::KEY_OPTION ) );
	}

	/**
	 * 保存した欄の HTML に平文が無い。替える欄は空で、name を持つ。
	 */
	public function test_saved_key_is_never_rendered() {
		update_option( Connection::KEY_OPTION, self::KEY );

		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( substr( self::KEY, 12 ), $html );
		$this->assertStringContainsString( Connection::masked_key(), $html );
		$this->assertMatchesRegularExpression( '/<input type="password"[^>]*name="eventwatch_api_key"/', $html );
		$this->assertStringNotContainsString( 'value="ewk_a1Ba1Ba1Ba1', str_replace( 'value="' . Connection::masked_key() . '"', '', $html ) );
		$this->assertStringContainsString( Connection_Admin::ACTION_REMOVE_KEY, $html );
	}

	/**
	 * 固定のキーは読み取り専用で、欄と削除を出さず、平文も出さない。
	 */
	public function test_fixed_key_field_is_read_only() {
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return self::KEY;
			}
		);

		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( 'name="eventwatch_api_key"', $html );
		$this->assertStringNotContainsString( Connection_Admin::ACTION_REMOVE_KEY, $html );
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( Connection_Admin::ACTION_CHECK, $html );
		$this->assertStringContainsString( 'wp-config.php', $html );
	}

	/**
	 * キーが無いときは、アカウントとキーが要ることと発行の画面へのリンクを出す。
	 */
	public function test_empty_field_explains_what_is_needed() {
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertStringContainsString( 'EventWatch account', $html );
		$this->assertStringContainsString( '/organizer/api-keys', $html );
		$this->assertStringContainsString( 'name="eventwatch_api_key"', $html );
		$this->assertStringNotContainsString( Connection_Admin::ACTION_CHECK, $html );
	}

	/**
	 * キーの欄に、規約とガイドラインへの同意を出す。定数のキーは文を変える。
	 */
	public function test_key_field_states_consent() {
		$empty = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'By saving a key', $empty );
		$this->assertStringContainsString( '/terms"', $empty );
		$this->assertStringContainsString( '/post/guidelines"', $empty );

		update_option( Connection::KEY_OPTION, self::KEY );
		$saved = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'By saving a key', $saved );

		$fixed = static function () {
			return self::KEY;
		};
		add_filter( 'eventwatch_fixed_api_key', $fixed );
		$constant = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		remove_filter( 'eventwatch_fixed_api_key', $fixed );

		$this->assertStringContainsString( 'By sending events with this key', $constant );
		$this->assertStringNotContainsString( 'By saving a key', $constant );
		$this->assertStringContainsString( '/post/guidelines"', $constant );
	}

	/**
	 * 失効では原因と直し方を出す。団体名は escape する。
	 */
	public function test_status_messages() {
		update_option( Connection::KEY_OPTION, self::KEY );
		$fingerprint = wp_hash( self::KEY );
		$save        = static function ( $status, $name = '' ) use ( $fingerprint ) {
			update_option(
				Connection::STATE_OPTION,
				array(
					'status'       => $status,
					'organization' => $name,
					'checked_at'   => 1,
					'key'          => $fingerprint,
				)
			);
		};

		$save( Connection::CONNECTED, '<b>A & B</b>' );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringContainsString( '&lt;b&gt;A &amp; B&lt;/b&gt;', $html );

		$save( Connection::UNAUTHORIZED );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'revoked', $html );
		$this->assertStringContainsString( 'owner', $html );
		$this->assertStringContainsString( '/organizer/api-keys', $html );

		$save( Connection::SUSPENDED );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'don’t need to change it', $html );
		$this->assertStringContainsString( '/contact', $html );
		$this->assertStringNotContainsString( '/organizer/api-keys', $html );
	}

	/**
	 * 別のキーに結び付いたサイトの 403 は、古いキーの失効と主催者面のキーの画面を出す。ほかの 403 は問い合わせのまま。
	 */
	public function test_site_bound_status_message() {
		update_option( Connection::KEY_OPTION, self::KEY );
		$fingerprint = wp_hash( self::KEY );
		$save        = static function ( array $extra ) use ( $fingerprint ) {
			update_option(
				Connection::STATE_OPTION,
				array_merge(
					array(
						'status'       => Connection::FORBIDDEN,
						'organization' => '',
						'checked_at'   => 1,
						'key'          => $fingerprint,
					),
					$extra
				)
			);
		};

		$save( array( 'reason' => Api_Client::CODE_SITE_BOUND ) );
		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
		$this->assertStringContainsString( 'Revoke the old key under Organization settings', $html );
		$this->assertStringContainsString( 'Check connection', $html );
		$this->assertStringContainsString( '/organizer/api-keys', $html );
		$this->assertStringNotContainsString( '/contact', $html );
		$this->assertStringNotContainsString( 'Please contact EventWatch', $html );

		// 理由を持たない記録（この版より前に残ったもの）と、ほかの code の 403。
		foreach ( array( array(), array( 'reason' => '' ), array( 'reason' => 'other' ) ) as $extra ) {
			$save( $extra );
			$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );
			$this->assertStringContainsString( 'Please contact EventWatch', $html );
			$this->assertStringContainsString( '/contact', $html );
			$this->assertStringNotContainsString( '/organizer/api-keys', $html );
			$this->assertStringNotContainsString( 'Revoke the old key', $html );
		}
	}

	/**
	 * 結び付きの 403 の後、古いキーを失効して確かめると、つながった状態に戻って理由も消える。
	 */
	public function test_check_after_revoking_the_old_key_clears_the_reason() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		Connection::observe( new \WP_Error( Api_Client::FORBIDDEN, 'x', array( 'code' => Api_Client::CODE_SITE_BOUND ) ) );
		$this->assertTrue( Connection::is_site_bound( Connection::state() ) );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{"displayName":"Org"}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			20
		);

		$this->run_action( Connection_Admin::ACTION_CHECK, array( Connection_Admin::class, 'handle_check' ) );

		$this->assertSame( Connection::CONNECTED, Connection::state()['status'] );
		$this->assertSame( '', Connection::state()['reason'] );
		$this->assertStringNotContainsString( 'Revoke the old key', $this->output_of( array( Settings::class, 'render_api_key_field' ) ) );
	}

	/**
	 * キーを替える欄に、古いキーを先に失効する順序を出す。
	 */
	public function test_change_key_explains_the_order() {
		update_option( Connection::KEY_OPTION, self::KEY );

		$html = $this->output_of( array( Settings::class, 'render_api_key_field' ) );

		$this->assertMatchesRegularExpression( '/<details>.*revoke the old key on EventWatch first.*<\/details>/s', $html );
	}

	/**
	 * ja は日本語の Web、それ以外は /en/ の下へ。
	 */
	public function test_web_url_follows_the_locale() {
		$this->assertSame( 'https://eventwatch.app/en/contact', Connection_Admin::web_url( '/contact' ) );

		add_filter(
			'determine_locale',
			static function () {
				return 'ja';
			}
		);
		$this->assertSame( 'https://eventwatch.app/contact', Connection_Admin::web_url( '/contact' ) );
	}

	/**
	 * 公開のドキュメントも、ja は接頭辞なし、それ以外は /en/ の下へ。
	 */
	public function test_docs_url_follows_the_locale() {
		$this->assertSame( 'https://docs.eventwatch.app/en/wordpress/setup', Connection_Admin::docs_url( '/wordpress/setup' ) );

		add_filter(
			'determine_locale',
			static function () {
				return 'ja';
			}
		);
		$this->assertSame( 'https://docs.eventwatch.app/wordpress/setup', Connection_Admin::docs_url( '/wordpress/setup' ) );
	}

	/**
	 * 設定の手順へのリンクは、キーを入れる前も入れた後も接続の節に出る。
	 */
	public function test_setup_guide_link_is_shown_with_and_without_a_key() {
		Settings::add_fields();
		$link = '<a href="https://docs.eventwatch.app/en/wordpress/setup" target="_blank" rel="noopener noreferrer">';

		$without_key = $this->output_of(
			static function () {
				do_settings_sections( Settings::PAGE );
			}
		);

		update_option( Connection::KEY_OPTION, self::KEY );
		$with_key = $this->output_of(
			static function () {
				do_settings_sections( Settings::PAGE );
			}
		);

		$this->assertStringContainsString( $link, $without_key );
		$this->assertStringContainsString( $link, $with_key );
	}

	/**
	 * キーと識別の option が自動の読み込みに載らない。
	 */
	public function test_options_are_not_autoloaded() {
		global $wpdb;

		delete_option( Connection::KEY_OPTION );
		delete_option( Site_Identity::ID_OPTION );
		delete_option( Site_Identity::URL_OPTION );

		// 有効化を通らずに保存する経路でも載らない。
		update_option( Connection::KEY_OPTION, self::KEY );
		Site_Identity::ensure();
		Connection::check();

		foreach ( array( Connection::KEY_OPTION, Connection::STATE_OPTION, Site_Identity::ID_OPTION, Site_Identity::URL_OPTION ) as $option ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			$this->assertContains( $autoload, array( 'off', 'no', 'auto-off' ), $option );
		}

		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Connection::KEY_OPTION, wp_load_alloptions() );
	}

	/**
	 * 有効化でサイト ID と URL とキーの行ができる。2 回目で変わらない。
	 */
	public function test_activation_creates_the_identity() {
		delete_option( Site_Identity::ID_OPTION );
		delete_option( Site_Identity::URL_OPTION );
		delete_option( Connection::KEY_OPTION );

		Plugin::activate();

		$this->assertSame( '', get_option( Connection::KEY_OPTION, false ) );

		$id = get_option( Site_Identity::ID_OPTION );
		$this->assertTrue( wp_is_uuid( $id, 4 ) );
		$this->assertSame( get_option( 'home' ), Site_Identity::recorded_url() );

		Plugin::activate();
		$this->assertSame( $id, get_option( Site_Identity::ID_OPTION ) );
	}

	/**
	 * URL が変わると書き込みが HTTP を出さない。読むだけなら通る。確かめるまで止まったまま。
	 */
	public function test_changed_url_stops_writes_until_confirmed() {
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://production.example.com' ) );

		$this->assertTrue( Site_Identity::has_changed() );

		foreach ( array( 'POST', 'PUT', 'DELETE' ) as $method ) {
			$result = ( new Api_Client() )->request( $method, '/api/v1.0/org-api/x' );
			$this->assertSame( Api_Client::SITE_CHANGED, $result->get_error_code() );
			$this->assertFalse( Api_Client::is_temporary( $result ) );
		}
		$this->assertSame( array(), $this->sent );

		( new Api_Client() )->request( 'GET', '/api/v1.0/org-api/organization' );
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * 移転を選ぶと、同じサイト ID で続く。
	 */
	public function test_moved_keeps_the_id_and_key() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://old.example.com' ) );

		$this->run_action( Connection_Admin::ACTION_MOVED, array( Connection_Admin::class, 'handle_moved' ) );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertSame( $id, Site_Identity::id() );
		$this->assertSame( self::KEY, Connection::api_key() );
		$this->assertSame( Site_Identity::current_url(), Site_Identity::recorded_url() );
	}

	/**
	 * 複製を選ぶと、新しいサイト ID になり、保存したキーと記録が消える。
	 */
	public function test_copy_gets_a_new_id_and_loses_the_key() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option( Sync_Status::LAST_SYNC_OPTION, 123 );
		Connection::check();
		$id = Site_Identity::id();
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://production.example.com' ) );

		$this->run_action( Connection_Admin::ACTION_COPY, array( Connection_Admin::class, 'handle_copy' ) );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertNotSame( $id, Site_Identity::id() );
		$this->assertTrue( wp_is_uuid( Site_Identity::id(), 4 ) );
		$this->assertSame( '', Connection::api_key() );
		$this->assertFalse( get_option( Connection::STATE_OPTION ) );
		$this->assertSame( 0, Sync_Status::last_success() );
	}

	/**
	 * URL が変わっていないのに古いタブから押しても、サイト ID とキーを失わない。
	 *
	 * @testWith ["eventwatch_site_copied", "handle_copy"]
	 *           ["eventwatch_site_moved", "handle_moved"]
	 *
	 * @param string $action 操作。
	 * @param string $method 受ける処理。
	 */
	public function test_stale_choice_changes_nothing( $action, $method ) {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();

		$this->run_action( $action, array( Connection_Admin::class, $method ) );

		$this->assertSame( $id, Site_Identity::id() );
		$this->assertSame( self::KEY, Connection::api_key() );
		$this->assertSame( array(), $this->reported() );
	}

	/**
	 * 定数のキーが失効したら、直す先は wp-config.php だと案内する。
	 */
	public function test_revoked_fixed_key_points_to_wp_config() {
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return self::KEY;
			}
		);

		$text = Connection_Admin::status_text(
			array(
				'status'       => Connection::UNAUTHORIZED,
				'organization' => '',
				'checked_at'   => 1,
			)
		);

		$this->assertStringContainsString( 'wp-config.php', $text );
		$this->assertStringNotContainsString( 'enter it here', $text );
	}

	/**
	 * 同じサイトの見え方の違いでは止まらない。
	 */
	public function test_same_site_does_not_stop() {
		$home = Site_Identity::current_url();

		add_filter(
			'home_url',
			static function () {
				return 'https://example.org/en';
			}
		);
		$this->assertFalse( Site_Identity::has_changed(), 'home_url filter' );

		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( $home . '/' ) );
		$this->assertFalse( Site_Identity::has_changed(), 'trailing slash' );

		$other_scheme = 0 === strpos( $home, 'https://' ) ? 'http://' . substr( $home, 8 ) : 'https://' . substr( $home, 7 );
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( $other_scheme ) );
		$this->assertFalse( Site_Identity::has_changed(), 'scheme' );

		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( strtoupper( $home ) ) );
		$this->assertFalse( Site_Identity::has_changed(), 'case' );

		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( $home . '/sub' ) );
		$this->assertTrue( Site_Identity::has_changed(), 'path' );
	}

	/**
	 * 通知を描く。
	 *
	 * @param string $screen 画面。
	 * @return string
	 */
	private function notices_on( $screen ) {
		set_current_screen( $screen );
		return $this->output_of( array( Connection_Admin::class, 'render_notices' ) );
	}

	/**
	 * 通知は設定画面とイベントの一覧だけに出る。
	 */
	public function test_notices_only_on_plugin_screens() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://production.example.com' ) );

		foreach ( array( 'dashboard', 'edit-post', 'edit-page', 'plugins', 'eventwatch_event' ) as $screen ) {
			$this->assertSame( '', $this->notices_on( $screen ), $screen );
		}

		$on_settings = $this->notices_on( 'settings_page_eventwatch' );
		$this->assertStringContainsString( 'production.example.com', $on_settings );
		$this->assertStringContainsString( Connection_Admin::ACTION_COPY, $on_settings );
		$this->assertStringContainsString( Connection_Admin::ACTION_MOVED, $on_settings );

		$on_list = $this->notices_on( 'edit-eventwatch_event' );
		$this->assertStringContainsString( 'production.example.com', $on_list );
		$this->assertStringContainsString( 'page=eventwatch', $on_list );
		$this->assertStringNotContainsString( Connection_Admin::ACTION_COPY, $on_list );
	}

	/**
	 * 固定のキーは複製でも消えないので、そのことを選ぶ前に伝える。
	 */
	public function test_copy_notice_explains_the_fixed_key() {
		$this->log_in_as_admin();
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://production.example.com' ) );
		add_filter(
			'eventwatch_fixed_api_key',
			static function () {
				return self::KEY;
			}
		);

		$html = $this->notices_on( 'settings_page_eventwatch' );

		$this->assertStringContainsString( 'wp-config.php', $html );
		$this->assertStringNotContainsString( self::KEY, $html );

		$this->run_action( Connection_Admin::ACTION_COPY, array( Connection_Admin::class, 'handle_copy' ) );
		$this->assertSame( self::KEY, Connection::api_key() );
		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertStringContainsString( 'wp-config.php', $this->reported()[0]['message'] );
	}

	/**
	 * 直せない編集者には出さない。
	 */
	public function test_notices_only_for_administrators() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://production.example.com' ) );

		$this->assertSame( '', $this->notices_on( 'edit-eventwatch_event' ) );
	}

	/**
	 * キーなしの通知は一覧にだけ出て、閉じられる。キーなしなら URL の変化を騒がない。
	 */
	public function test_no_key_notice() {
		$user_id = $this->log_in_as_admin();
		update_option( Site_Identity::URL_OPTION, Site_Identity::encode_url( 'https://production.example.com' ) );

		$this->assertSame( '', $this->notices_on( 'settings_page_eventwatch' ) );

		$html = $this->notices_on( 'edit-eventwatch_event' );
		$this->assertStringContainsString( Connection_Admin::ACTION_HIDE, $html );
		$this->assertStringNotContainsString( 'production.example.com', $html );

		$this->run_action( Connection_Admin::ACTION_HIDE, array( Connection_Admin::class, 'handle_hide_notice' ) );
		$this->assertSame( '1', get_user_meta( $user_id, Connection_Admin::HIDE_META, true ) );

		remove_all_filters( 'wp_redirect' );
		$this->assertSame( '', $this->notices_on( 'edit-eventwatch_event' ) );
	}

	/**
	 * 失効と停止は通知に出る。つながっている・未確認では出ない。
	 */
	public function test_key_problem_notice() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );

		$this->assertSame( '', $this->notices_on( 'settings_page_eventwatch' ) );

		update_option(
			Connection::STATE_OPTION,
			array(
				'status'       => Connection::UNAUTHORIZED,
				'organization' => '',
				'checked_at'   => 1,
				'key'          => wp_hash( self::KEY ),
			)
		);

		$this->assertStringContainsString( 'revoked', $this->notices_on( 'settings_page_eventwatch' ) );
		$this->assertStringContainsString( 'page=eventwatch', $this->notices_on( 'edit-eventwatch_event' ) );
	}

	/**
	 * 翻訳の文も通知で escape する。
	 */
	public function test_key_problem_notice_escapes_text() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		update_option(
			Connection::STATE_OPTION,
			array(
				'status'       => Connection::UNAUTHORIZED,
				'organization' => '',
				'checked_at'   => 1,
				'key'          => wp_hash( self::KEY ),
			)
		);
		add_filter(
			'gettext_eventwatch',
			static function ( $translation ) {
				return false !== strpos( $translation, 'rejected' ) ? '<img src=x>' : $translation;
			}
		);

		$html = $this->notices_on( 'settings_page_eventwatch' );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	/**
	 * 操作のフォームごとに nonce がある。
	 */
	public function test_forms_have_nonces() {
		$this->log_in_as_admin();

		$html = $this->output_of( array( Connection_Admin::class, 'render_forms' ) );

		foreach ( array( Connection_Admin::ACTION_CHECK, Connection_Admin::ACTION_REMOVE_KEY, Connection_Admin::ACTION_MOVED, Connection_Admin::ACTION_COPY, Connection_Admin::ACTION_SEND_ALL ) as $action ) {
			$this->assertStringContainsString( 'id="' . $action . '"', $html );
			$this->assertStringContainsString( wp_create_nonce( $action ), $html );
		}
		$this->assertSame( 5, substr_count( $html, 'admin-post.php' ) );
	}

	/**
	 * 健康状態の欄。
	 */
	public function test_sync_status_field() {
		$html = $this->output_of( array( Settings::class, 'render_sync_status_field' ) );
		$this->assertStringContainsString( 'Not yet', $html );
		$this->assertStringContainsString( 'Waiting to send: 0 events', $html );
		$this->assertStringContainsString( Settings::CRON_HELP_URL, $html );

		update_option( Sync_Status::LAST_SYNC_OPTION, 1790000000 );
		add_filter(
			'eventwatch_sync_pending_count',
			static function () {
				return 1;
			}
		);
		$html = $this->output_of( array( Settings::class, 'render_sync_status_field' ) );
		$this->assertStringNotContainsString( 'Not yet', $html );
		$this->assertStringContainsString( 'Waiting to send: 1 event<', $html );
	}

	/**
	 * 投稿も消す欄。既定はオフで、保存すると削除の処理が読む値になる。
	 */
	public function test_delete_events_field() {
		$this->assertStringNotContainsString( 'checked', $this->output_of( array( Settings::class, 'render_delete_events_field' ) ) );

		update_option( Settings::DELETE_EVENTS, '1' );
		$this->assertTrue( (bool) get_option( 'eventwatch_delete_events_on_uninstall' ) );
		$this->assertStringContainsString( "checked='checked'", $this->output_of( array( Settings::class, 'render_delete_events_field' ) ) );

		update_option( Settings::DELETE_EVENTS, null );
		$this->assertFalse( (bool) get_option( 'eventwatch_delete_events_on_uninstall' ) );
	}

	/**
	 * API を止めても公開と表示は変わらず、表示の流れは EventWatch を呼ばない。
	 */
	public function test_site_does_not_depend_on_the_api() {
		Event_Meta::register();
		update_option( Connection::KEY_OPTION, self::KEY );
		$day = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, wp_timezone() );

		$id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'A & B',
			)
		);
		update_post_meta(
			$id,
			'eventwatch_occurrences',
			array(
				array(
					'start' => $day . ' 13:30',
					'end'   => $day . ' 15:00',
				),
			)
		);
		wp_publish_post( $id );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$list = do_shortcode( '[eventwatch_events]' );
		$this->assertStringContainsString( 'A &amp; B', $list );

		$this->go_to( get_permalink( $id ) );
		$head = get_echo( 'wp_head' );
		$this->assertStringNotContainsString( self::KEY, $head . $list );

		$this->assertSame( array(), $this->sent );
	}

	/**
	 * サイトごとに別のキーとサイト ID を持つ。ネットワーク有効化と新しいサイトで ID ができる。
	 *
	 * @group ms-required
	 */
	public function test_each_site_has_its_own_key_and_id() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$first  = self::factory()->blog->create();
		$second = self::factory()->blog->create();
		$other  = 'ewk_' . str_repeat( 'q', 43 );

		Plugin::activate( true );

		switch_to_blog( $first );
		$first_id = get_option( Site_Identity::ID_OPTION );
		update_option( Connection::KEY_OPTION, self::KEY );
		restore_current_blog();

		switch_to_blog( $second );
		$second_id = get_option( Site_Identity::ID_OPTION );
		update_option( Connection::KEY_OPTION, $other );
		$this->assertSame( $other, Connection::api_key() );
		restore_current_blog();

		$this->assertTrue( wp_is_uuid( $first_id, 4 ) );
		$this->assertTrue( wp_is_uuid( $second_id, 4 ) );
		$this->assertNotSame( $first_id, $second_id );

		switch_to_blog( $first );
		$this->assertSame( self::KEY, Connection::api_key() );
		restore_current_blog();
	}

	/**
	 * ネットワーク全体で有効なときの新しいサイトにも ID ができる。
	 *
	 * @group ms-required
	 */
	public function test_new_site_gets_an_id_when_network_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		add_filter(
			'pre_site_option_active_sitewide_plugins',
			static function () {
				return array( plugin_basename( \EventWatch\PLUGIN_FILE ) => time() );
			}
		);

		$site = self::factory()->blog->create();

		switch_to_blog( $site );
		$this->assertTrue( wp_is_uuid( get_option( Site_Identity::ID_OPTION ), 4 ) );
		restore_current_blog();
	}

	/**
	 * 今のサイトの option に、wp search-replace と同じ置換を当てる。更新のフックは通らない。
	 *
	 * 定数の WP_HOME があると置換の後も home が変わらないので、今の URL はフィルターで差し替える。
	 *
	 * @param string $to 置換の後の URL。
	 * @return string 置換の前の URL。
	 */
	private function search_replace_home( $to ) {
		global $wpdb;

		$from = Site_Identity::current_url();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は本体の値で、置換の再現なので option の API を通さない。
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = REPLACE( option_value, %s, %s )", $from, $to ) );
		wp_cache_flush();

		add_filter(
			'option_home',
			static function () use ( $to ) {
				return $to;
			},
			20
		);

		return $from;
	}

	/**
	 * 記録の行に URL の文字列が無い。置換に当たらないので、複製が記録まで書き換えられない。
	 */
	public function test_record_does_not_contain_the_url() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Site_Identity::URL_OPTION ) );

		$this->assertSame( Site_Identity::encode_url( Site_Identity::current_url() ), $raw );
		$this->assertStringNotContainsString( (string) wp_parse_url( Site_Identity::current_url(), PHP_URL_HOST ), $raw );
		$this->assertSame( Site_Identity::current_url(), Site_Identity::recorded_url() );
	}

	/**
	 * 置換で複製したサイトは、確かめるまで書き込みを出さない。読むだけなら通る。
	 */
	public function test_search_replace_copy_stops_writes() {
		update_option( Connection::KEY_OPTION, self::KEY );
		$this->search_replace_home( 'https://staging.example.test' );

		$this->assertTrue( Site_Identity::has_changed() );

		foreach ( array( 'POST', 'PUT', 'DELETE' ) as $method ) {
			$result = ( new Api_Client() )->request( $method, '/api/v1.0/org-api/x' );
			$this->assertSame( Api_Client::SITE_CHANGED, $result->get_error_code() );
		}
		$this->assertSame( array(), $this->sent );

		( new Api_Client() )->request( 'GET', '/api/v1.0/org-api/organization' );
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * 置換の後の通知に、置換の前の URL と今の URL が出る。
	 */
	public function test_search_replace_notice_shows_the_url_before_the_replace() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$from = $this->search_replace_home( 'https://staging.example.test' );

		$notice = $this->notices_on( 'settings_page_eventwatch' );

		$this->assertStringContainsString( esc_html( $from ), $notice );
		$this->assertStringContainsString( 'https://staging.example.test', $notice );
		$this->assertStringContainsString( Connection_Admin::ACTION_COPY, $notice );
		$this->assertStringContainsString( Connection_Admin::ACTION_MOVED, $notice );
	}

	/**
	 * 置換の後に移転を選ぶと、今の URL を記録して続く。
	 */
	public function test_search_replace_then_moved_resumes() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();
		$this->search_replace_home( 'https://staging.example.test' );

		$this->run_action( Connection_Admin::ACTION_MOVED, array( Connection_Admin::class, 'handle_moved' ) );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertSame( 'https://staging.example.test', Site_Identity::recorded_url() );
		$this->assertSame( $id, Site_Identity::id() );
		$this->assertSame( self::KEY, Connection::api_key() );
	}

	/**
	 * 置換の後に複製を選ぶと、別のサイトになり、今の URL を記録する。
	 */
	public function test_search_replace_then_copy_becomes_another_site() {
		$this->log_in_as_admin();
		update_option( Connection::KEY_OPTION, self::KEY );
		$id = Site_Identity::id();
		$this->search_replace_home( 'https://staging.example.test' );

		$this->run_action( Connection_Admin::ACTION_COPY, array( Connection_Admin::class, 'handle_copy' ) );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertSame( 'https://staging.example.test', Site_Identity::recorded_url() );
		$this->assertNotSame( $id, Site_Identity::id() );
		$this->assertSame( '', Connection::api_key() );
	}

	/**
	 * 旧名の平文の行が残っていても、判定に使わない。
	 */
	public function test_plain_record_under_the_old_name_is_ignored() {
		update_option( 'eventwatch_site_url', 'https://old.example.com' );

		$this->assertFalse( Site_Identity::has_changed() );
		$this->assertSame( Site_Identity::current_url(), Site_Identity::recorded_url() );
	}

	/**
	 * 読めない記録は違うものとして止める。警告は出さない（出るとテストが落ちる）。
	 *
	 * @dataProvider unreadable_records
	 *
	 * @param string $record 記録の値。
	 */
	public function test_unreadable_record_stops_writes( $record ) {
		update_option( Site_Identity::URL_OPTION, $record );

		$this->assertSame( '', Site_Identity::recorded_url() );
		$this->assertTrue( Site_Identity::has_changed() );
	}

	/**
	 * 読めない記録。平文の URL は 16 進の文字でない字を含む。
	 *
	 * @return array<string, array{string}>
	 */
	public function unreadable_records() {
		return array(
			'odd length' => array( 'abc' ),
			'not hex'    => array( '68zz' ),
			'plain url'  => array( 'https://example.org' ),
			'newline'    => array( "6869\n" ),
		);
	}

	/**
	 * マルチサイトの子サイトで置換すると、その子サイトだけが止まる。
	 *
	 * @group ms-required
	 */
	public function test_search_replace_on_a_child_site_stops_only_that_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$child = self::factory()->blog->create();

		switch_to_blog( $child );
		Site_Identity::ensure();
		update_option( Connection::KEY_OPTION, self::KEY );
		$from = Site_Identity::current_url();
		$this->assertFalse( Site_Identity::has_changed() );

		$this->search_replace_home( 'https://staging.example.test' );
		$this->assertTrue( Site_Identity::has_changed() );
		$this->assertSame( $from, Site_Identity::recorded_url() );

		$result = ( new Api_Client() )->request( 'PUT', '/api/v1.0/org-api/x' );
		$this->assertSame( Api_Client::SITE_CHANGED, $result->get_error_code() );
		$this->assertSame( array(), $this->sent );
		remove_all_filters( 'option_home', 20 );
		restore_current_blog();

		$this->assertFalse( Site_Identity::has_changed() );
	}
}
