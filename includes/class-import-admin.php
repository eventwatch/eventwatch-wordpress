<?php
/**
 * 「EventWatch から取り込む」の画面と、それが呼ぶ REST。
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
 * 一覧は未公開の投稿と差し戻しの理由を読むので、設定と同じ権限の人に絞る。
 */
class Import_Admin {

	const PAGE       = 'eventwatch-import';
	const CAPABILITY = 'manage_options';
	const HANDLE     = 'eventwatch-import';

	const ID_PATTERN = '^[0-9a-fA-F-]{36}$';

	/**
	 * 1 回の取り込みで選べる数の上限。
	 */
	const MAX_IDS = 100;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * メニューを足す。キーが無いサイトには出さない。
	 *
	 * @return void
	 */
	public static function add_page() {
		if ( '' === Connection::api_key() ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'Import from EventWatch', 'eventwatch' ),
			__( 'Import from EventWatch', 'eventwatch' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * この画面にだけ読み込む。
	 *
	 * @param string $hook 画面。
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( Post_Type::POST_TYPE . '_page_' . self::PAGE !== $hook ) {
			return;
		}

		// 本体は狭い幅で表の上の .tablenav.top .actions を隠す。取り込みのボタンはそこにしか無いので、クラスを重ねて本体の指定より強くする。
		wp_add_inline_style( 'wp-admin', Admin_Columns::cell_style() . '.tablenav.top .actions.eventwatch-import__actions{display:flex;align-items:center;gap:10px}.eventwatch-import__none{color:#50575e}.eventwatch-import__progress{display:flex;flex-direction:column;gap:12px;max-width:640px;padding:16px 20px;border:1px solid #c3c4c7;background:#fff}.eventwatch-import__progress h2{margin:0;font-size:14px}.eventwatch-import__progress p{margin:0}.eventwatch-import__bar{height:8px;border-radius:4px;background:#dcdcde;overflow:hidden}.eventwatch-import__bar div{height:100%;background:var(--wp-admin-theme-color,#2271b1)}.eventwatch-import__items{margin:0}.eventwatch-import__items li{display:flex;align-items:center;gap:8px;margin:0;padding:8px 0;border-bottom:1px solid #dcdcde}.eventwatch-import__items span:first-child{flex:1;min-width:0}.eventwatch-import__state--done{color:#007017}.eventwatch-import__state--doing{color:var(--wp-admin-theme-color,#2271b1)}.eventwatch-import__state--failed{color:#cc1818}.eventwatch-import__results .notice{margin:0 0 10px;padding:10px 12px}.eventwatch-import__results p{margin:0 0 4px}.eventwatch-import__results ul{margin:4px 0 8px;padding-left:20px;list-style:disc}' );

		$asset_path = plugin_dir_path( PLUGIN_FILE ) . 'build/import-page.asset.php';
		// ビルドしていない作業ツリーでも、管理画面を壊さない。
		if ( ! is_readable( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script( self::HANDLE, plugins_url( 'build/import-page.js', PLUGIN_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'eventwatch', plugin_dir_path( PLUGIN_FILE ) . 'languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.eventwatchImport = ' . wp_json_encode(
				array(
					'unlinked'  => Event_Import::unlinked_drafts(),
					'canImport' => ! Site_Identity::has_changed(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * 画面を描く。
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表のページ送りで読むだけ。
		$listed = self::read_list( isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Import posts your organization created on EventWatch as WordPress posts. Posts listed on EventWatch are imported as published; all others as drafts.', 'eventwatch' ); ?></p>
			<div id="eventwatch-import-progress"></div>
			<?php if ( is_wp_error( $listed ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( self::list_error_text( $listed ) ); ?></p></div>
			<?php else : ?>
				<?php if ( Site_Identity::has_changed() ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( Event_Import::stop_reason( Api_Client::SITE_CHANGED ) ); ?></p></div>
				<?php endif; ?>
				<form method="get" id="eventwatch-import-table">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( Post_Type::POST_TYPE ); ?>" />
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
					<?php ( new Import_List_Table( $listed ) )->display(); ?>
				</form>
			<?php endif; ?>
			<noscript><div class="notice notice-warning inline"><p><?php esc_html_e( 'Importing needs JavaScript. Turn it on, then reload this page.', 'eventwatch' ); ?></p></div></noscript>
		</div>
		<?php
	}

	/**
	 * 表の一覧を読む。取り込むと投稿が減るので、ページが無くなっていたら最後のページを読み直す。
	 *
	 * @param int $paged 1 から数えたページ。
	 * @return array{list: array, total: int}|WP_Error
	 */
	public static function read_list( $paged ) {
		$listed = Event_Import::list_page( $paged - 1 );

		if ( ! is_wp_error( $listed ) && ! $listed['list'] && $listed['total'] > 0 && $paged > 1 ) {
			return Event_Import::list_page( (int) ceil( $listed['total'] / Event_Import::PAGE_SIZE ) - 1 );
		}

		return $listed;
	}

	/**
	 * 一覧を読めなかったときの文。
	 *
	 * @param WP_Error $error 失敗。
	 * @return string
	 */
	private static function list_error_text( WP_Error $error ) {
		if ( self::is_key_error( $error ) ) {
			return Event_Import::stop_reason( $error->get_error_code() );
		}

		return __( 'The list couldn’t be loaded from EventWatch. Wait a moment, then reload this page.', 'eventwatch' );
	}

	/**
	 * キーか団体の問題で断られたか。
	 *
	 * @param WP_Error $error 失敗。
	 * @return bool
	 */
	private static function is_key_error( WP_Error $error ) {
		return in_array( $error->get_error_code(), array( Api_Client::UNAUTHORIZED, Api_Client::SUSPENDED, Api_Client::FORBIDDEN ), true );
	}

	/**
	 * REST を登録する。取り込みはこの経路だけから動く。
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Sync_Rest::REST_NAMESPACE,
			'/import/prepare',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'prepare' ),
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array(
					'eventIds' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => self::MAX_IDS,
						'items'    => array(
							'type'    => 'string',
							'pattern' => self::ID_PATTERN,
						),
					),
				),
			)
		);
		register_rest_route(
			Sync_Rest::REST_NAMESPACE,
			'/import/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'run' ),
				'permission_callback' => array( self::class, 'can_import' ),
				'args'                => array(
					'eventId' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => self::ID_PATTERN,
					),
				),
			)
		);
	}

	/**
	 * 取り込める人か。
	 *
	 * @return bool
	 */
	public static function can_import() {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * 選ばれた投稿を読む。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return WP_REST_Response
	 */
	public static function prepare( WP_REST_Request $request ) {
		$items = Event_Import::prepare( array_values( array_unique( $request->get_param( 'eventIds' ) ) ) );

		if ( is_wp_error( $items ) ) {
			$stops = self::is_key_error( $items );

			return new WP_REST_Response(
				array(
					'status' => $stops ? 'stop' : 'failed',
					'reason' => $stops ? Event_Import::stop_reason( $items->get_error_code() ) : __( 'The list couldn’t be loaded from EventWatch. Wait a moment, then try again.', 'eventwatch' ),
					'items'  => array(),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'status' => 'ok',
				'reason' => '',
				'items'  => $items,
			)
		);
	}

	/**
	 * 1 件を取り込む。
	 *
	 * @param WP_REST_Request $request 要求。
	 * @return WP_REST_Response
	 */
	public static function run( WP_REST_Request $request ) {
		return new WP_REST_Response( Event_Import::run( (string) $request->get_param( 'eventId' ) ) );
	}
}
