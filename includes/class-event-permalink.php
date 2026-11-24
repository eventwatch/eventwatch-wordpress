<?php
/**
 * 詳細のページの接頭辞の変更と、旧い URL の転送。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保存では印を立てるだけにして、次の読み込みで投稿タイプを登録した後に 1 度だけ rewrite を作り直す。
 * flush は重いうえ、保存の要求の時点では投稿タイプが古い接頭辞で登録されている。
 */
class Event_Permalink {

	const FLUSH_FLAG = 'eventwatch_flush_rewrite';
	const PREVIOUS   = 'eventwatch_previous_slugs';

	/**
	 * 接頭辞に使えない語。本体のパスと、本体の rewrite が予約している語。
	 *
	 * @var string[]
	 */
	const RESERVED = array(
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'admin',
		'login',
		'feed',
		'rss',
		'rss2',
		'rdf',
		'atom',
		'comments',
		'page',
		'search',
		'author',
		'category',
		'tag',
		'type',
		'embed',
		'trackback',
		'sitemap',
		'robots',
		'attachment',
	);

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'add_option_' . Post_Type::SLUG_OPTION, array( self::class, 'on_slug_added' ), 10, 2 );
		add_action( 'update_option_' . Post_Type::SLUG_OPTION, array( self::class, 'on_slug_changed' ), 10, 2 );
		// 投稿タイプ（優先度 10）とほかのプラグインの登録の後に作り直す。
		add_action( 'init', array( self::class, 'flush_if_flagged' ), 99 );
		// 本体の redirect_canonical（優先度 10）が別の URL を推測する前に動かす。
		add_action( 'template_redirect', array( self::class, 'redirect_old_url' ), 5 );
	}

	/**
	 * 接頭辞の値を確かめる。
	 *
	 * 誤りのときは元の値を保つ。Settings API は sanitize の戻り値を保存するので、ここで止める。
	 *
	 * @param mixed $value 入力。
	 * @return string 保存する値。空は既定に戻す。
	 */
	public static function sanitize_slug( $value ) {
		$slug = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $slug ) {
			return '';
		}

		$error = null;
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			$error = esc_html__( 'Use only lowercase letters, numbers, and hyphens for the URL prefix.', 'eventwatch' );
		} elseif ( in_array( $slug, self::RESERVED, true ) ) {
			$error = esc_html__( 'WordPress uses this word in its own URLs. Choose another URL prefix.', 'eventwatch' );
		} elseif ( self::takes_over_urls( $slug ) ) {
			$error = esc_html__( 'Pages or posts on your site already use this URL. Choose another URL prefix.', 'eventwatch' );
		}

		if ( null === $error ) {
			return $slug;
		}

		// 管理画面の外（WP-CLI など）にはこの関数が無い。
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( Post_Type::SLUG_OPTION, 'eventwatch_invalid_slug', $error );
		}

		return (string) get_option( Post_Type::SLUG_OPTION, '' );
	}

	/**
	 * この接頭辞がいまの URL を奪うか。
	 *
	 * 投稿タイプの rewrite は固定ページや投稿の rewrite より先に当たるので、奪われたページは 404 になる。
	 * 転送は旧い接頭辞の 404 だけを見るので、救えない。
	 *
	 * @param string $slug 接頭辞。
	 * @return bool
	 */
	private static function takes_over_urls( $slug ) {
		// 年と月別のアーカイブ。
		if ( ctype_digit( $slug ) ) {
			return true;
		}

		// 固定ページそのものは重ならない（/events/ と /events/my-event/）。奪われるのは子のページ。
		$page = get_page_by_path( $slug );
		if ( $page && get_posts(
			array(
				'post_type'        => 'page',
				'post_parent'      => $page->ID,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		) ) {
			return true;
		}

		// パーマリンクの先頭の固定の語（/blog/%postname%/ の blog）。
		$first = strtok( ltrim( (string) get_option( 'permalink_structure' ), '/' ), '/' );

		return $first === $slug;
	}

	/**
	 * 初めて保存したとき。
	 *
	 * @param string $option 名前。
	 * @param mixed  $value  値。
	 * @return void
	 */
	public static function on_slug_added( $option, $value ) {
		self::on_slug_changed( '', $value );
	}

	/**
	 * 接頭辞が変わったら、旧い接頭辞を覚えて印を立てる。
	 *
	 * @param mixed $old_value 変更前の値。
	 * @param mixed $value     変更後の値。
	 * @return void
	 */
	public static function on_slug_changed( $old_value, $value ) {
		$old = '' !== (string) $old_value ? (string) $old_value : Post_Type::REWRITE_SLUG;
		$new = '' !== (string) $value ? (string) $value : Post_Type::REWRITE_SLUG;
		if ( $old === $new ) {
			return;
		}

		$previous   = self::previous_slugs();
		$previous[] = $old;
		// 元の接頭辞へ戻したら、いまの接頭辞を転送の対象から外す。
		$previous = array_values( array_diff( array_unique( $previous ), array( $new ) ) );

		// 読むのは 404 のときだけなので、毎回の読み込みに載せない。
		update_option( self::PREVIOUS, $previous, false );
		update_option( self::FLUSH_FLAG, 1 );
	}

	/**
	 * 印があれば rewrite を作り直す。
	 *
	 * @return void
	 */
	public static function flush_if_flagged() {
		if ( ! get_option( self::FLUSH_FLAG ) ) {
			return;
		}

		// 先に消す。作り直しの途中で次の要求が来ても、2 度走らせない。
		delete_option( self::FLUSH_FLAG );
		flush_rewrite_rules( false );
	}

	/**
	 * 旧い接頭辞の URL を新しい URL へ転送する。
	 *
	 * @return void
	 */
	public static function redirect_old_url() {
		if ( ! self::is_redirect_candidate( is_404(), is_attachment() ) ) {
			return;
		}

		$target = self::redirect_target( Event_List::current_uri() );
		if ( null !== $target && wp_safe_redirect( $target, 301, 'EventWatch' ) ) {
			exit;
		}
	}

	/**
	 * 転送を試す要求か。
	 *
	 * 旧い接頭辞の URL は、同じスラッグのメディアがあると本体の添付の rewrite に当たり、404 にならない。
	 *
	 * @param bool $is_404        404 か。
	 * @param bool $is_attachment 添付のページか。
	 * @return bool
	 */
	public static function is_redirect_candidate( $is_404, $is_attachment ) {
		return $is_404 || $is_attachment;
	}

	/**
	 * 旧い接頭辞の URL の転送先。
	 *
	 * @param string $uri パスとクエリ。
	 * @return string|null 公開のイベントが無ければ null。
	 */
	public static function redirect_target( $uri ) {
		$parts = wp_parse_url( $uri );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '';
		$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home  = '' !== $home ? trailingslashit( $home ) : '/';
		if ( 0 !== strpos( $path, $home ) ) {
			return null;
		}

		$relative = trim( substr( $path, strlen( $home ) ), '/' );
		// パーマリンクが /index.php/%postname%/ のときは、イベントの URL にも index.php が付く。
		if ( 0 === strpos( $relative, 'index.php/' ) ) {
			$relative = substr( $relative, strlen( 'index.php/' ) );
		}

		$segments = explode( '/', $relative );
		if ( 2 !== count( $segments ) || ! in_array( $segments[0], self::previous_slugs(), true ) ) {
			return null;
		}

		$name = sanitize_title( rawurldecode( $segments[1] ) );
		// 空の name は絞り込みにならず、最新のイベントが返る。
		if ( '' === $name ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'        => Post_Type::POST_TYPE,
				'name'             => $name,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		if ( ! $ids ) {
			return null;
		}

		$target = (string) get_permalink( $ids[0] );

		return isset( $parts['query'] ) ? $target . '?' . $parts['query'] : $target;
	}

	/**
	 * これまでに使った接頭辞。
	 *
	 * @return string[]
	 */
	private static function previous_slugs() {
		$previous = get_option( self::PREVIOUS, array() );

		return is_array( $previous ) ? array_values( array_filter( $previous, 'is_string' ) ) : array();
	}
}
