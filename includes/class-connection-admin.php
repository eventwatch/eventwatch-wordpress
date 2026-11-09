<?php
/**
 * 設定画面の外から押す操作（接続の確認・キーの削除・複製の確認）と、管理画面の通知。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 操作は admin-post.php のフォームで受ける。JS が無くても動かすため。
 */
class Connection_Admin {

	const ACTION_CHECK      = 'eventwatch_check_connection';
	const ACTION_REMOVE_KEY = 'eventwatch_remove_api_key';
	const ACTION_MOVED      = 'eventwatch_site_moved';
	const ACTION_COPY       = 'eventwatch_site_copied';
	const ACTION_HIDE       = 'eventwatch_hide_no_key_notice';
	const ACTION_SEND_ALL   = 'eventwatch_send_all';

	/**
	 * キーなしの通知を閉じた印。
	 */
	const HIDE_META = 'eventwatch_hide_no_key_notice';

	/**
	 * 公開のドキュメント。配布した版に URL が残るので、公開後に変わらないパスだけを渡す。
	 */
	const DOCS_WEB = 'https://docs.eventwatch.app';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_' . self::ACTION_CHECK, array( self::class, 'handle_check' ) );
		add_action( 'admin_post_' . self::ACTION_REMOVE_KEY, array( self::class, 'handle_remove_key' ) );
		add_action( 'admin_post_' . self::ACTION_MOVED, array( self::class, 'handle_moved' ) );
		add_action( 'admin_post_' . self::ACTION_COPY, array( self::class, 'handle_copy' ) );
		add_action( 'admin_post_' . self::ACTION_HIDE, array( self::class, 'handle_hide_notice' ) );
		add_action( 'admin_post_' . self::ACTION_SEND_ALL, array( self::class, 'handle_send_all' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
	}

	/**
	 * 接続を確かめる。
	 *
	 * @return void
	 */
	public static function handle_check() {
		self::authorize( self::ACTION_CHECK );

		$state = Connection::check();

		if ( Connection::CONNECTED === $state['status'] ) {
			Sync_Queue::resume();
			self::report( self::status_text( $state ), 'success' );
		} elseif ( in_array( $state['status'], array( Connection::UNREACHABLE, Connection::FORBIDDEN ), true ) ) {
			self::report( self::status_text( $state ), 'warning' );
		}
		// 失効と停止は、通知が同じ内容を出す。

		self::back_to_settings();
	}

	/**
	 * 保存したキーを消す。
	 *
	 * @return void
	 */
	public static function handle_remove_key() {
		self::authorize( self::ACTION_REMOVE_KEY );

		delete_option( Connection::KEY_OPTION );
		add_option( Connection::KEY_OPTION, '', '', false );
		Connection::forget();
		self::report( __( 'The EventWatch API key was removed.', 'eventwatch' ), 'success' );

		self::back_to_settings();
	}

	/**
	 * 移転として続ける。
	 *
	 * @return void
	 */
	public static function handle_moved() {
		self::authorize( self::ACTION_MOVED );

		// 古いタブからの押し直しで、もう解決した選択をやり直さない。
		if ( Site_Identity::has_changed() ) {
			Site_Identity::use_as_moved();
			self::report( __( 'The new URL was saved, and sending to EventWatch has resumed.', 'eventwatch' ), 'success' );
		}

		self::back_to_settings();
	}

	/**
	 * 複製として、新しいサイトにする。
	 *
	 * @return void
	 */
	public static function handle_copy() {
		self::authorize( self::ACTION_COPY );

		// 古いタブからの押し直しで、サイト ID とキーを失わない。
		if ( Site_Identity::has_changed() ) {
			Site_Identity::use_as_copy();
			self::report(
				'' !== Connection::fixed_key()
					? __( 'This site is now treated as a separate site. It keeps using the API key set in wp-config.php.', 'eventwatch' )
					: __( 'This site is now treated as a separate site. Its saved API key was removed. To send events from this site, enter a key.', 'eventwatch' ),
				'success'
			);
		}

		self::back_to_settings();
	}

	/**
	 * 公開中でまだ送っていないイベントを待ち行列に積む。この要求の中では送らない。
	 *
	 * @return void
	 */
	public static function handle_send_all() {
		self::authorize( self::ACTION_SEND_ALL );

		// 画面を開いた後に接続が切れた、古いタブからの押し直しを受ける。
		if ( ! Sync_Queue::can_send_unsent() ) {
			self::report( __( 'Events can’t be sent right now. Check the connection status on this page.', 'eventwatch' ), 'warning' );
			self::back_to_settings();
			return;
		}

		$count = Sync_Queue::mark_unsent_published();
		if ( $count > 0 ) {
			self::report(
				sprintf(
					/* translators: %d: number of events. */
					_n( '%d event is waiting to be sent to EventWatch.', '%d events are waiting to be sent to EventWatch.', $count, 'eventwatch' ),
					$count
				),
				'success'
			);
		} else {
			self::report( __( 'There are no events to send.', 'eventwatch' ), 'info' );
		}

		self::back_to_settings();
	}

	/**
	 * キーなしの通知を、このユーザーには出さない。
	 *
	 * @return void
	 */
	public static function handle_hide_notice() {
		self::authorize( self::ACTION_HIDE );

		update_user_meta( get_current_user_id(), self::HIDE_META, 1 );

		if ( wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE ) ) ) {
			exit;
		}
	}

	/**
	 * 通知を出す。出すのは設定画面とイベントの一覧だけで、直せる管理者だけに出す。
	 *
	 * @return void
	 */
	public static function render_notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$on_settings = 'settings_page_' . Settings::PAGE === $screen->id;
		$on_list     = 'edit-' . Post_Type::POST_TYPE === $screen->id;
		if ( ! $on_settings && ! $on_list ) {
			return;
		}

		if ( '' === Connection::api_key() ) {
			if ( $on_list && ! get_user_meta( get_current_user_id(), self::HIDE_META, true ) ) {
				self::render_no_key_notice();
			}
			return;
		}

		if ( Site_Identity::has_changed() ) {
			self::render_site_changed_notice( $on_settings );
		}

		$state = Connection::state();
		if ( in_array( $state['status'], array( Connection::UNAUTHORIZED, Connection::SUSPENDED ), true ) ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s%2$s</p></div>',
				esc_html( self::status_text( $state ) ),
				$on_list ? ' ' . self::settings_link() : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_link() が組み立ての中で escape している。
			);
		}
	}

	/**
	 * 操作のフォーム。ボタンは form 属性でこれを指す。設定のフォームの中に入れ子にできないので外に置く。
	 *
	 * @return void
	 */
	public static function render_forms() {
		foreach ( array( self::ACTION_CHECK, self::ACTION_REMOVE_KEY, self::ACTION_MOVED, self::ACTION_COPY, self::ACTION_SEND_ALL ) as $action ) {
			printf(
				'<form id="%1$s" action="%2$s" method="post"><input type="hidden" name="action" value="%1$s">',
				esc_attr( $action ),
				esc_url( admin_url( 'admin-post.php' ) )
			);
			wp_nonce_field( $action );
			echo '</form>';
		}
	}

	/**
	 * 接続の状態の文。
	 *
	 * @param array $state Connection::state() の値。
	 * @return string
	 */
	public static function status_text( $state ) {
		switch ( $state['status'] ) {
			case Connection::CONNECTED:
				/* translators: %s: organization name on EventWatch. */
				return sprintf( __( 'Connected to EventWatch (%s)', 'eventwatch' ), $state['organization'] );
			case Connection::UNAUTHORIZED:
				if ( '' !== Connection::fixed_key() ) {
					return __( 'EventWatch rejected this API key. It may have been revoked, or the person who issued it may have left your organization or stopped being an owner. Ask an owner of your organization to issue a new key under Organization settings › Site connection, then update it in wp-config.php.', 'eventwatch' );
				}
				return __( 'EventWatch rejected this API key. It may have been revoked, or the person who issued it may have left your organization or stopped being an owner. Ask an owner of your organization to issue a new key under Organization settings › Site connection, then enter it here.', 'eventwatch' );
			case Connection::SUSPENDED:
				return __( 'Your organization is suspended on EventWatch, so nothing can be sent for now. This isn’t a problem with your API key, so you don’t need to change it. Please contact EventWatch.', 'eventwatch' );
			case Connection::FORBIDDEN:
				if ( Connection::is_site_bound( $state ) ) {
					return __( 'This site is still connected to EventWatch with another API key that hasn’t been revoked, so EventWatch doesn’t accept changes from this key. Revoke the old key under Organization settings › Site connection on EventWatch, then use “Check connection”, and sending resumes.', 'eventwatch' );
				}
				return __( 'EventWatch doesn’t allow this API key to be used for this site. Please contact EventWatch.', 'eventwatch' );
			case Connection::UNREACHABLE:
				return __( 'Couldn’t confirm the connection to EventWatch. Wait a moment, then try again. Your site and events aren’t affected.', 'eventwatch' );
		}

		return __( 'Not checked yet. Use “Check connection” to confirm that this key works.', 'eventwatch' );
	}

	/**
	 * EventWatch の Web の URL。ja 以外は /en/ の下を開く。
	 *
	 * @param string $path `/` で始まるパス。
	 * @return string
	 */
	public static function web_url( $path ) {
		return Event_Detail::EVENTWATCH_WEB . self::locale_prefix() . $path;
	}

	/**
	 * 公開のドキュメントの URL。ja 以外は /en/ の下を開く。
	 *
	 * @param string $path `/` で始まるパス。
	 * @return string
	 */
	public static function docs_url( $path ) {
		return self::DOCS_WEB . self::locale_prefix() . $path;
	}

	/**
	 * Web とドキュメントの URL で、パスの前に付ける言語の接頭辞。
	 *
	 * @return string
	 */
	private static function locale_prefix() {
		return 0 === strpos( determine_locale(), 'ja' ) ? '' : '/en';
	}

	/**
	 * 権限と nonce を確かめる。
	 *
	 * @param string $action 操作。
	 * @return void
	 */
	private static function authorize( $action ) {
		check_admin_referer( $action );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this site.', 'eventwatch' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * 結果を設定画面の通知に渡す。本体の options-head.php が settings-updated のときに読む形。
	 * 本体の settings_errors() は文を escape せずに出すので、ここで escape する。
	 *
	 * @param string $message 文。
	 * @param string $type    種類。
	 * @return void
	 */
	private static function report( $message, $type ) {
		add_settings_error( Settings::PAGE, 'eventwatch_result', esc_html( $message ), $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	/**
	 * 設定画面へ戻る。
	 *
	 * @return void
	 */
	private static function back_to_settings() {
		$url = add_query_arg(
			array(
				'page'             => Settings::PAGE,
				'settings-updated' => 'true',
			),
			admin_url( 'options-general.php' )
		);

		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * キーなしの通知。キーなしでも使えるプラグインなので、閉じられる形にする。
	 *
	 * @return void
	 */
	private static function render_no_key_notice() {
		printf(
			'<div class="notice notice-info"><p>%1$s %2$s</p><form action="%3$s" method="post"><p><input type="hidden" name="action" value="%4$s">',
			esc_html__( 'To list these events on EventWatch, enter an EventWatch API key. Your site works without one.', 'eventwatch' ),
			self::settings_link(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_link() が組み立ての中で escape している。
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_HIDE )
		);
		wp_nonce_field( self::ACTION_HIDE );
		printf( '<button type="submit" class="button">%s</button></p></form></div>', esc_html__( 'Don’t show this again', 'eventwatch' ) );
	}

	/**
	 * URL が変わったときの通知。
	 *
	 * @param bool $on_settings 設定画面か。ボタンのフォームは設定画面にだけある。
	 * @return void
	 */
	private static function render_site_changed_notice( $on_settings ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p>%2$s</p>',
			esc_html__( 'Sending to EventWatch is stopped because this site’s URL has changed.', 'eventwatch' ),
			esc_html(
				sprintf(
					/* translators: 1: site URL when it was last confirmed, 2: current site URL. */
					__( 'Saved URL: %1$s. Current URL: %2$s. Nothing is sent until you choose, so a copy of your site can’t change your live listings.', 'eventwatch' ),
					Site_Identity::recorded_url(),
					Site_Identity::current_url()
				)
			)
		);

		if ( ! $on_settings ) {
			echo '<p>' . self::settings_link() . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- settings_link() が組み立ての中で escape している。
			return;
		}

		printf(
			'<ul><li>%1$s</li><li>%2$s</li></ul><p class="eventwatch-buttons"><button type="submit" class="button" form="%3$s">%4$s</button> <button type="submit" class="button" form="%5$s">%6$s</button></p></div>',
			esc_html(
				'' !== Connection::fixed_key()
					? __( 'If this is a copy, it becomes a separate site. The API key set in wp-config.php can’t be removed here, so this copy keeps sending events with that key. Remove it from wp-config.php first if this copy shouldn’t send events.', 'eventwatch' )
					: __( 'If this is a copy, it becomes a separate site. Its saved API key is removed, and you enter a key again only if this copy should send events.', 'eventwatch' )
			),
			esc_html__( 'If the site moved, sending continues as the same site.', 'eventwatch' ),
			esc_attr( self::ACTION_COPY ),
			esc_html__( 'This is a copy', 'eventwatch' ),
			esc_attr( self::ACTION_MOVED ),
			esc_html__( 'The site moved', 'eventwatch' )
		);
	}

	/**
	 * 設定画面へのリンク。
	 *
	 * @return string
	 */
	private static function settings_link() {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE ) ),
			esc_html__( 'Open EventWatch settings', 'eventwatch' )
		);
	}
}
