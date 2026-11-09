<?php
/**
 * プラグインの設定画面（設定 › EventWatch）。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保存は Settings API に任せる。権限と nonce は options.php が確かめる。
 */
class Settings {

	const PAGE           = 'eventwatch';
	const GROUP          = 'eventwatch';
	const SECTION_DETAIL = 'eventwatch_detail';
	const LIST_PAGE      = 'eventwatch_list_page';
	const MAPS_KEY       = 'eventwatch_google_maps_key';

	const SECTION_CONNECTION = 'eventwatch_connection_section';
	const SECTION_REMOVAL    = 'eventwatch_removal';
	const DELETE_EVENTS      = 'eventwatch_delete_events_on_uninstall';

	/**
	 * 毎回の読み込みに載せない option。使う場面が限られ、キーは読む経路を増やしたくない。
	 */
	const NOT_AUTOLOADED = array(
		self::MAPS_KEY,
		Connection::KEY_OPTION,
		Connection::STATE_OPTION,
		Site_Identity::ID_OPTION,
		Site_Identity::URL_OPTION,
	);

	/**
	 * WP-Cron をサーバーの cron から動かす公式の案内。
	 */
	const CRON_HELP_URL = 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/';

	/**
	 * Google のキーの取り方の案内。
	 */
	const MAPS_KEY_HELP_URL = 'https://developers.google.com/maps/documentation/embed/get-api-key';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		// 管理画面の外の更新（WP-CLI など）にも sanitize を効かせるので、init で登録する。
		add_action( 'init', array( self::class, 'register_settings' ) );
		add_action( 'admin_init', array( self::class, 'add_fields' ) );
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
		add_filter( 'wp_default_autoload_value', array( self::class, 'exclude_key_from_autoload' ), 10, 2 );
	}

	/**
	 * 設定を登録する。
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			Post_Type::SLUG_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( Event_Permalink::class, 'sanitize_slug' ),
			)
		);
		register_setting(
			self::GROUP,
			self::LIST_PAGE,
			array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => array( self::class, 'sanitize_list_page' ),
			)
		);
		register_setting(
			self::GROUP,
			self::MAPS_KEY,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_maps_key' ),
			)
		);
		register_setting(
			self::GROUP,
			Connection::KEY_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_api_key' ),
			)
		);
		register_setting(
			self::GROUP,
			self::DELETE_EVENTS,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * キーと識別の option を毎回の読み込みに載せない。
	 *
	 * @param bool|null $autoload 自動で読むか。
	 * @param string    $option   名前。
	 * @return bool|null
	 */
	public static function exclude_key_from_autoload( $autoload, $option ) {
		return in_array( $option, self::NOT_AUTOLOADED, true ) ? false : $autoload;
	}

	/**
	 * 一覧のページ。公開の固定ページだけを受ける。
	 *
	 * @param mixed $value 入力。
	 * @return int
	 */
	public static function sanitize_list_page( $value ) {
		$page = get_post( absint( $value ) );

		return $page && 'page' === $page->post_type && 'publish' === $page->post_status ? (int) $page->ID : 0;
	}

	/**
	 * Google Maps のキー。URL に載せるので、形の違う値を受けない。誤りのときは元の値を保つ。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_maps_key( $value ) {
		$key = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $key || preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
			return $key;
		}

		// 管理画面の外（WP-CLI など）にはこの関数が無い。
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::MAPS_KEY, 'eventwatch_invalid_maps_key', __( 'The Google Maps API key can contain only letters, numbers, hyphens, and underscores.', 'eventwatch' ) );
		}

		return (string) get_option( self::MAPS_KEY, '' );
	}

	/**
	 * EventWatch の API キー。空は「替えない」。保存したキーは画面に出さないので、欄はいつも空で届く。
	 * 消すのは「キーを削除」だけ。誤りのときは元の値を保つ。
	 *
	 * @param mixed $value 入力。
	 * @return string
	 */
	public static function sanitize_api_key( $value ) {
		$current = (string) get_option( Connection::KEY_OPTION, '' );
		$key     = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $key || '' !== Connection::fixed_key() ) {
			return $current;
		}

		if ( preg_match( '/^ewk_[A-Za-z0-9]{43}\z/', $key ) ) {
			return $key;
		}

		// 管理画面の外（WP-CLI など）にはこの関数が無い。
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( Connection::KEY_OPTION, 'eventwatch_invalid_api_key', __( 'That doesn’t look like an EventWatch API key. Keys start with “ewk_” and have 47 characters. Copy the whole key and try again.', 'eventwatch' ) );
		}

		return $current;
	}

	/**
	 * 画面をメニューに足す。
	 *
	 * @return void
	 */
	public static function add_page() {
		add_options_page( __( 'EventWatch Settings', 'eventwatch' ), 'EventWatch', 'manage_options', self::PAGE, array( self::class, 'render_page' ) );
	}

	/**
	 * 欄を足す。
	 *
	 * @return void
	 */
	public static function add_fields() {
		add_settings_section( self::SECTION_CONNECTION, __( 'EventWatch connection', 'eventwatch' ), array( self::class, 'render_connection_section' ), self::PAGE );
		add_settings_field(
			Connection::KEY_OPTION,
			__( 'EventWatch API key', 'eventwatch' ),
			array( self::class, 'render_api_key_field' ),
			self::PAGE,
			self::SECTION_CONNECTION,
			array( 'label_for' => Connection::KEY_OPTION )
		);
		add_settings_field(
			'eventwatch_sync_status',
			__( 'Sending to EventWatch', 'eventwatch' ),
			array( self::class, 'render_sync_status_field' ),
			self::PAGE,
			self::SECTION_CONNECTION
		);

		add_settings_section( self::SECTION_DETAIL, __( 'Event pages', 'eventwatch' ), '__return_false', self::PAGE );

		add_settings_field(
			Post_Type::SLUG_OPTION,
			__( 'URL prefix', 'eventwatch' ),
			array( self::class, 'render_slug_field' ),
			self::PAGE,
			self::SECTION_DETAIL,
			array( 'label_for' => Post_Type::SLUG_OPTION )
		);
		add_settings_field(
			self::LIST_PAGE,
			__( 'Event list page', 'eventwatch' ),
			array( self::class, 'render_list_page_field' ),
			self::PAGE,
			self::SECTION_DETAIL,
			array( 'label_for' => self::LIST_PAGE )
		);
		add_settings_field(
			self::MAPS_KEY,
			__( 'Google Maps API key (optional)', 'eventwatch' ),
			array( self::class, 'render_maps_key_field' ),
			self::PAGE,
			self::SECTION_DETAIL,
			array( 'label_for' => self::MAPS_KEY )
		);

		add_settings_section( self::SECTION_REMOVAL, __( 'Removing the plugin', 'eventwatch' ), '__return_false', self::PAGE );
		add_settings_field(
			self::DELETE_EVENTS,
			__( 'Events', 'eventwatch' ),
			array( self::class, 'render_delete_events_field' ),
			self::PAGE,
			self::SECTION_REMOVAL
		);
	}

	/**
	 * 設定画面の小さな並びの指定。
	 *
	 * @param string $hook_suffix 画面。
	 * @return void
	 */
	public static function enqueue_styles( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_register_style( 'eventwatch-settings', false, array(), '1' );
		wp_enqueue_style( 'eventwatch-settings' );
		wp_add_inline_style(
			'eventwatch-settings',
			'.eventwatch-key{display:flex;flex-wrap:wrap;align-items:center;gap:8px}'
			. '.eventwatch-key input{max-width:100%}'
			. '.eventwatch-key details[open]{flex-basis:100%}'
			. '.eventwatch-key__change{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px}'
			. '.eventwatch-key__change label{flex-basis:100%}'
			. '.eventwatch-key__change .description{flex-basis:100%;margin:0}'
			. '.eventwatch-status{display:flex;gap:4px;margin:8px 0 0}'
			. '.eventwatch-status .dashicons{flex:none}'
		);
	}

	/**
	 * 画面を描く。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Settings that connect this site to EventWatch.', 'eventwatch' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<?php Connection_Admin::render_forms(); ?>
		</div>
		<?php
	}

	/**
	 * 接続の節の説明。欄の描画はキーの有無で分かれるので、どちらでも見えるようにここへ置く。
	 *
	 * @return void
	 */
	public static function render_connection_section() {
		printf(
			'<p class="description"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( Connection_Admin::docs_url( '/wordpress/setup' ) ),
			esc_html__( 'How to connect your site to EventWatch', 'eventwatch' )
		);
	}

	/**
	 * EventWatch の API キーの欄。保存したキーは伏せて出し、HTML に残りを出さない。
	 *
	 * @return void
	 */
	public static function render_api_key_field() {
		if ( '' === Connection::api_key() ) {
			self::render_empty_api_key_field();
			return;
		}

		$fixed = '' !== Connection::fixed_key();

		echo '<div class="eventwatch-key">';
		printf(
			'<input type="text" class="regular-text code" id="%1$s" value="%2$s" readonly>',
			esc_attr( Connection::KEY_OPTION ),
			esc_attr( Connection::masked_key() )
		);
		printf(
			'<button type="submit" class="button" form="%1$s">%2$s</button>',
			esc_attr( Connection_Admin::ACTION_CHECK ),
			esc_html__( 'Check connection', 'eventwatch' )
		);

		if ( ! $fixed ) {
			printf(
				'<details><summary class="button">%1$s</summary><div class="eventwatch-key__change"><label for="%2$s-new">%3$s</label><input type="password" class="regular-text code" id="%2$s-new" name="%2$s" autocomplete="new-password" spellcheck="false"><button type="submit" class="button button-link-delete" form="%4$s">%5$s</button><p class="description">%6$s</p></div></details>',
				esc_html__( 'Change key', 'eventwatch' ),
				esc_attr( Connection::KEY_OPTION ),
				esc_html__( 'New key. Enter it, then save your changes.', 'eventwatch' ),
				esc_attr( Connection_Admin::ACTION_REMOVE_KEY ),
				esc_html__( 'Remove key', 'eventwatch' ),
				// 送ったことのあるサイトは、古いキーが有効な間ほかのキーの書き込みを断られる。入れる前に見える場所に置く。
				esc_html__( 'If this site has already sent events with the old key, revoke the old key on EventWatch first. Until it’s revoked, EventWatch doesn’t accept changes from the new key.', 'eventwatch' )
			);
		}
		echo '</div>';

		self::render_connection_status();

		if ( $fixed ) {
			printf( '<p class="description">%s</p>', esc_html__( 'This key is set in wp-config.php, so it can’t be changed here.', 'eventwatch' ) );
		}
		self::render_api_key_description( ! $fixed );
	}

	/**
	 * キーがまだ無いときの欄。
	 *
	 * @return void
	 */
	private static function render_empty_api_key_field() {
		printf(
			'<input type="password" class="regular-text code" id="%1$s" name="%1$s" placeholder="%2$s" autocomplete="new-password" spellcheck="false">',
			esc_attr( Connection::KEY_OPTION ),
			esc_attr__( 'Not set', 'eventwatch' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'To list your events on EventWatch, you need an EventWatch account and an API key. Your site works without them: events stay on your site and nothing is sent.', 'eventwatch' )
		);
		printf(
			'<p class="description"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( Connection_Admin::web_url( '/organizer/api-keys' ) ),
			esc_html__( 'Issue an API key on EventWatch', 'eventwatch' )
		);
		self::render_api_key_description( true );
	}

	/**
	 * キーの説明。
	 *
	 * @param bool $recommend_constant 定数を勧める文を添えるか。
	 * @return void
	 */
	private static function render_api_key_description( $recommend_constant ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The key you issued in EventWatch under Organization settings › Site connection. Saved keys are hidden.', 'eventwatch' )
		);
		self::render_consent();
		if ( ! $recommend_constant ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A key saved here is also included in database backups and copies of your site. We recommend setting it in wp-config.php instead:', 'eventwatch' )
		);
		echo '<p class="description"><code>define( \'EVENTWATCH_API_KEY\', \'ewk_…\' );</code></p>';

		if ( is_multisite() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'On a multisite network, the constant applies to every site. To use a different key for each site, save the key on each site’s settings page.', 'eventwatch' )
			);
		}
	}

	/**
	 * 投稿の規約への同意。EventWatch の投稿フォームの送信と同じ形で、操作に添えて伝える。
	 * 定数のキーは欄を通らないので、チェックボックスでは取れない。
	 *
	 * @return void
	 */
	private static function render_consent() {
		printf(
			'<p class="description">%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a> <a href="%4$s" target="_blank" rel="noopener noreferrer">%5$s</a></p>',
			esc_html(
				'' !== Connection::fixed_key()
					? __( 'By sending events with this key, you agree to the EventWatch Terms of Service and Posting Guidelines.', 'eventwatch' )
					: __( 'By saving a key, you agree to the EventWatch Terms of Service and Posting Guidelines.', 'eventwatch' )
			),
			esc_url( Connection_Admin::web_url( '/terms' ) ),
			esc_html__( 'Terms of Service', 'eventwatch' ),
			esc_url( Connection_Admin::web_url( '/post/guidelines' ) ),
			esc_html__( 'Posting Guidelines', 'eventwatch' )
		);
	}

	/**
	 * つながっているかをアイコンと語で出す。
	 *
	 * @return void
	 */
	private static function render_connection_status() {
		$state = Connection::state();
		$kinds = array(
			Connection::CONNECTED    => array( 'yes-alt', '#007017' ),
			Connection::UNCHECKED    => array( 'clock', 'inherit' ),
			Connection::UNREACHABLE  => array( 'warning', '#996800' ),
			Connection::UNAUTHORIZED => array( 'warning', '#b32d2e' ),
			Connection::SUSPENDED    => array( 'warning', '#b32d2e' ),
			Connection::FORBIDDEN    => array( 'warning', '#b32d2e' ),
		);
		$kind  = isset( $kinds[ $state['status'] ] ) ? $kinds[ $state['status'] ] : $kinds[ Connection::UNCHECKED ];

		printf(
			'<p class="eventwatch-status"><span class="dashicons dashicons-%1$s" style="color:%2$s" aria-hidden="true"></span><span>%3$s</span></p>',
			esc_attr( $kind[0] ),
			esc_attr( $kind[1] ),
			esc_html( Connection_Admin::status_text( $state ) )
		);

		if ( Connection::UNAUTHORIZED === $state['status'] || Connection::is_site_bound( $state ) ) {
			$link = array( '/organizer/api-keys', __( 'Open Site connection on EventWatch', 'eventwatch' ) );
		} elseif ( in_array( $state['status'], array( Connection::SUSPENDED, Connection::FORBIDDEN ), true ) ) {
			$link = array( '/contact', __( 'Contact EventWatch', 'eventwatch' ) );
		} else {
			return;
		}

		printf(
			'<p class="description"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( Connection_Admin::web_url( $link[0] ) ),
			esc_html( $link[1] )
		);
	}

	/**
	 * 同期の健康状態の欄。
	 *
	 * @return void
	 */
	public static function render_sync_status_field() {
		$last    = Sync_Status::last_success();
		$pending = Sync_Status::pending_count();

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time, or "Not yet". */
					__( 'Last successful send: %s', 'eventwatch' ),
					$last > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) : __( 'Not yet', 'eventwatch' )
				)
			)
		);
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of events. */
					_n( 'Waiting to send: %d event', 'Waiting to send: %d events', $pending, 'eventwatch' ),
					$pending
				)
			)
		);
		self::render_send_all();
		printf(
			'<p class="description">%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p>',
			esc_html(
				Sync_Status::is_wp_cron_disabled()
					? __( 'DISABLE_WP_CRON is on, so WP-Cron doesn’t run on its own. If your server’s cron doesn’t run it, failed sends aren’t retried.', 'eventwatch' )
					: __( 'Retries use WP-Cron, which only runs when someone visits your site. To retry on time, run it from your server’s cron.', 'eventwatch' )
			),
			esc_url( self::CRON_HELP_URL ),
			esc_html__( 'How to run WP-Cron from your server', 'eventwatch' )
		);
	}

	/**
	 * 公開中でまだ送っていないイベントの件数と、まとめて送る操作。対象が無ければ出さない。
	 * キーの保存では送らないので、後からキーを入れたサイトはここから送る。
	 *
	 * @return void
	 */
	private static function render_send_all() {
		if ( '' === Connection::api_key() ) {
			return;
		}

		$count = count( Sync_Queue::unsent_published_ids() );
		if ( 0 === $count ) {
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of events. */
					_n( 'Published but not sent yet: %d event', 'Published but not sent yet: %d events', $count, 'eventwatch' ),
					$count
				)
			)
		);
		printf(
			'<p><button type="submit" class="button" form="%1$s"%2$s>%3$s</button></p>',
			esc_attr( Connection_Admin::ACTION_SEND_ALL ),
			disabled( Sync_Queue::can_send_unsent(), false, false ),
			esc_html__( 'Send all to EventWatch', 'eventwatch' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Each event goes to review on EventWatch. Events are sent a few dozen at a time with WP-Cron, so this can take a while. Events set not to send, events that have ended, and events missing required details aren’t included.', 'eventwatch' )
		);
	}

	/**
	 * プラグインの削除で投稿も消すかの欄。
	 *
	 * @return void
	 */
	public static function render_delete_events_field() {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( self::DELETE_EVENTS ),
			checked( (bool) get_option( self::DELETE_EVENTS, false ), true, false ),
			esc_html__( 'Delete all events when the plugin is deleted', 'eventwatch' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Off by default, so your events stay in the database. If you turn this on, deleting the plugin permanently removes the events on this site, and you can’t restore them.', 'eventwatch' )
		);
	}

	/**
	 * 接頭辞の欄。
	 *
	 * @return void
	 */
	public static function render_slug_field() {
		printf(
			'<code>%1$s</code><input type="text" class="regular-text code" id="%2$s" name="%2$s" value="%3$s" placeholder="%4$s" spellcheck="false" aria-describedby="%2$s-description"><code>/my-event/</code>',
			esc_html( home_url( '/' ) ),
			esc_attr( Post_Type::SLUG_OPTION ),
			esc_attr( (string) get_option( Post_Type::SLUG_OPTION, '' ) ),
			esc_attr( Post_Type::REWRITE_SLUG )
		);
		printf(
			'<p class="description" id="%1$s-description">%2$s</p>',
			esc_attr( Post_Type::SLUG_OPTION ),
			esc_html__( 'Lowercase letters, numbers, and hyphens. When you change it, the old URLs redirect to the new ones.', 'eventwatch' )
		);
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'This has no effect while your permalinks use the Plain setting.', 'eventwatch' )
			);
		}
	}

	/**
	 * 一覧のページの欄。
	 *
	 * @return void
	 */
	public static function render_list_page_field() {
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::LIST_PAGE ),
				'id'                => esc_attr( self::LIST_PAGE ),
				'selected'          => absint( get_option( self::LIST_PAGE, 0 ) ),
				'show_option_none'  => esc_html__( '— Not set —', 'eventwatch' ),
				'option_none_value' => '0',
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The “Back to events” link on event pages goes here. The link is hidden until you choose a page.', 'eventwatch' )
		);
	}

	/**
	 * Google Maps のキーの欄。
	 *
	 * @return void
	 */
	public static function render_maps_key_field() {
		printf(
			'<input type="password" class="regular-text code" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" autocomplete="off" spellcheck="false">',
			esc_attr( self::MAPS_KEY ),
			esc_attr( (string) get_option( self::MAPS_KEY, '' ) ),
			esc_attr__( 'Not set', 'eventwatch' )
		);

		$lines = array(
			__( 'When set, a venue map is embedded on event detail pages. When empty, only map links are shown.', 'eventwatch' ),
			__( 'Embedding a map makes visitors’ browsers load the map from Google. The key appears in the page HTML, so restrict it to your site’s domain with an HTTP referrer restriction.', 'eventwatch' ),
			__( 'Create the key in Google Cloud (with the Maps Embed API enabled). As the site owner, you’re responsible for reviewing Google’s terms and pricing. This key is not sent to EventWatch.', 'eventwatch' ),
		);
		foreach ( $lines as $line ) {
			printf( '<p class="description">%s</p>', esc_html( $line ) );
		}

		printf(
			'<p class="description"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( self::MAPS_KEY_HELP_URL ),
			esc_html__( 'How to get an API key (Google)', 'eventwatch' )
		);
	}
}
