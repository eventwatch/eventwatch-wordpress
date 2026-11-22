<?php
/**
 * イベント詳細のページ。テンプレート・各欄の描画・構造化データ。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 題・画像・本文は本体のブロックに任せ、ここでは事実の欄と操作を描く。
 *
 * パスワードを知らない人には、どの欄も描かない。本体は WP_Query で読んだ投稿のメタを隠さない。
 */
class Event_Detail {

	const FIELD_BLOCK   = 'eventwatch/event-field';
	const ACTIONS_BLOCK = 'eventwatch/event-actions';

	/**
	 * WordPress 6.7 の register_block_template は名前に _ を許さないので、投稿タイプのキーをそのまま使えない。
	 * 本体の階層には single_template_hierarchy で足す。
	 */
	const TEMPLATE_SLUG = 'single-eventwatch-event';
	const TEMPLATE_NAME = 'eventwatch//' . self::TEMPLATE_SLUG;

	/**
	 * EventWatch に掲載中の間だけ値がある、EventWatch 側のスラッグ。同期の取り込みが書く。
	 */
	const LISTING_SLUG = '_eventwatch_listing_slug';

	const EVENTWATCH_WEB = 'https://eventwatch.app';
	const APP_STORE_URL  = 'https://apps.apple.com/jp/app/id6575346649';
	const GOOGLE_PLAY    = 'https://play.google.com/store/apps/details?id=app.eventwatch';

	/**
	 * 本文の説明に使う字数の上限。
	 */
	const DESCRIPTION_LENGTH = 160;

	/**
	 * 印の形。
	 *
	 * @var array<string, string>
	 */
	const ICONS = array(
		'back'     => 'M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18',
		'chevron'  => 'm19.5 8.25-7.5 7.5-7.5-7.5',
		'close'    => 'M6 18 18 6M6 6l12 12',
		'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
		'save'     => 'M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z',
		'share'    => 'M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z',
		'link'     => 'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244',
		'ext'      => 'M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25',
		'download' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3',
		'ended'    => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
	);

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'single_template', array( self::class, 'single_template' ) );
		add_filter( 'single_template_hierarchy', array( self::class, 'add_to_template_hierarchy' ) );
		add_filter( 'render_block_core/post-featured-image', array( self::class, 'hide_protected_image' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
		add_action( 'wp_head', array( self::class, 'print_head' ), 5 );
		add_action( 'template_redirect', array( Event_Ical::class, 'maybe_send' ), 1 );
	}

	/**
	 * ブロックとテンプレートを登録する。一覧の CSS のハンドルを使うので、一覧の後に呼ぶ。
	 *
	 * @return void
	 */
	public static function register() {
		self::register_blocks();

		register_block_template(
			self::TEMPLATE_NAME,
			array(
				'title'       => __( 'Single Event', 'eventwatch' ),
				'description' => __( 'Displays a single event.', 'eventwatch' ),
				'post_types'  => array( Post_Type::POST_TYPE ),
				'content'     => self::template_content(),
			)
		);
	}

	/**
	 * 欄と操作の列のブロックを登録する。テンプレートは二重に登録できないので、ブロックだけを登録し直せるよう分けてある。
	 *
	 * @return void
	 */
	public static function register_blocks() {
		$build = plugin_dir_path( PLUGIN_FILE ) . 'build/blocks/';
		// ビルドしていない作業ツリーでも、サイトを壊さない。
		if ( ! is_readable( $build . 'event-field/block.json' ) || ! is_readable( $build . 'event-actions/block.json' ) ) {
			return;
		}

		register_block_type( $build . 'event-field', array( 'render_callback' => array( self::class, 'render_block' ) ) );
		register_block_type( $build . 'event-actions', array( 'render_callback' => array( self::class, 'render_actions' ) ) );

		// 操作の列の JS には文言が無い。題と説明は block.json からサーバーが訳す。
		wp_set_script_translations(
			generate_block_asset_handle( self::FIELD_BLOCK, 'editorScript' ),
			'eventwatch',
			plugin_dir_path( PLUGIN_FILE ) . 'languages'
		);
	}

	/**
	 * ブロックテーマのテンプレートの中身。
	 *
	 * @return string
	 */
	private static function template_content() {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n"
			. self::default_content() . "\n"
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * 既定の並び（板 4a）。
	 *
	 * 余白は値で書く。blockGap はテーマが有効にしないと出力されず、spacing の preset もテーマが持つとは限らない。
	 *
	 * @param string $tag 外側の要素。クラシックテーマはヘッダーが main を開くので、入れ子にしない。
	 * @return string
	 */
	public static function default_content( $tag = 'main' ) {
		$gap = '{"style":{"spacing":{"margin":{"top":"1.5rem"}}}';

		return '<!-- wp:group {"tagName":"' . $tag . '","className":"eventwatch-detail-page","style":{"spacing":{"margin":{"top":"2rem","bottom":"3rem"}}},"layout":{"type":"constrained"}} -->' . "\n"
			. '<' . $tag . ' class="wp-block-group eventwatch-detail-page" style="margin-top:2rem;margin-bottom:3rem">'
			. '<!-- wp:eventwatch/event-field {"field":"back"} /-->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"category","style":{"spacing":{"margin":{"top":"1rem"}}}} /-->' . "\n"
			. '<!-- wp:post-title {"level":1,"style":{"spacing":{"margin":{"top":"0.25rem"}}}} /-->' . "\n"
			. '<!-- wp:post-featured-image {"aspectRatio":"16/9",' . substr( $gap, 1 ) . '} /-->' . "\n"
			. '<!-- wp:post-content {"layout":{"type":"constrained"},' . substr( $gap, 1 ) . '} /-->' . "\n"
			. '<!-- wp:group {"className":"eventwatch-detail-facts","style":{"spacing":{"margin":{"top":"2rem"}}}} -->' . "\n"
			. '<div class="wp-block-group eventwatch-detail-facts" style="margin-top:2rem">'
			. '<!-- wp:eventwatch/event-field {"field":"dates"} /-->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"venue"} /-->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"organizer"} /-->'
			. '</div>' . "\n"
			. '<!-- /wp:group -->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"map",' . substr( $gap, 1 ) . '} /-->' . "\n"
			. '<!-- wp:eventwatch/event-actions ' . $gap . '} -->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"calendar"} /-->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"interested"} /-->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"share"} /-->' . "\n"
			. '<!-- /wp:eventwatch/event-actions -->' . "\n"
			. '<!-- wp:eventwatch/event-field {"field":"official",' . substr( $gap, 1 ) . '} /-->'
			. '</' . $tag . '>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * 本体の階層に、プラグインのテンプレートの名前を足す。
	 *
	 * テーマの single-eventwatch_event が先に当たるよう、その直後に置く。
	 *
	 * @param string[] $templates テンプレートの名前の並び。
	 * @return string[]
	 */
	public static function add_to_template_hierarchy( $templates ) {
		$key      = 'single-' . Post_Type::POST_TYPE;
		$position = array_search( $key . '.php', $templates, true );
		if ( false === $position ) {
			return $templates;
		}

		array_splice( $templates, (int) $position + 1, 0, array( self::TEMPLATE_SLUG . '.php' ) );

		return $templates;
	}

	/**
	 * クラシックテーマのテンプレートを選ぶ。
	 *
	 * @param string $template 本体が選んだテンプレート。
	 * @return string
	 */
	public static function single_template( $template ) {
		if ( ! is_singular( Post_Type::POST_TYPE ) || wp_is_block_theme() ) {
			return $template;
		}

		// テーマが階層の名前で用意したものは、スラッグ別のものも含めてそのまま使う。
		if ( preg_match( '/^single-eventwatch[_-]event[-.]/', basename( (string) $template ) ) ) {
			return $template;
		}

		$theme = locate_template( Event_List::THEME_DIR . '/single-event.php' );

		return '' !== $theme ? $theme : plugin_dir_path( PLUGIN_FILE ) . 'templates/single-event.php';
	}

	/**
	 * パスワードを知らない人に、本体のアイキャッチ画像のブロックを出さない。本体はこのブロックで確かめない。
	 *
	 * @param string    $content  描画した HTML。
	 * @param array     $block    ブロック。
	 * @param \WP_Block $instance ブロックの実体。
	 * @return string
	 */
	public static function hide_protected_image( $content, $block, $instance ) {
		$post_id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		return $post && Post_Type::POST_TYPE === $post->post_type && post_password_required( $post ) ? '' : $content;
	}

	/**
	 * CSS を先に読む。クラシックテーマでは本文の描画の時点で head が終わっていて、フッターに回ると読み込みの間は崩れて見える。
	 *
	 * @return void
	 */
	public static function enqueue_styles() {
		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		wp_enqueue_style( generate_block_asset_handle( Event_List_Block::NAME, 'style' ) );
		$handle = generate_block_asset_handle( self::FIELD_BLOCK, 'style' );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, self::no_script_css() );
	}

	/**
	 * JS が無いときは、メニューを開かずに中のリンクをそのまま見せる。
	 *
	 * テーマの CSS が後から読まれても勝つよう !important を付ける。scripting を解さない古いブラウザーは、JS を切っているときだけメニューが開けない。
	 *
	 * @return string
	 */
	public static function no_script_css() {
		return '@media (scripting: none){[data-eventwatch-menu-trigger],[data-eventwatch-dialog-open],.eventwatch-detail__copy,.eventwatch-detail__close{display:none!important}.eventwatch-detail__menu[hidden]{display:flex!important;position:static!important;border:0!important;background:none!important;color:inherit!important}.eventwatch-detail__dialog{display:flex!important;flex-direction:column;gap:1rem;position:static!important;width:auto!important;margin:0!important;padding:0!important;background:none!important;color:inherit!important}}';
	}

	/**
	 * 欄のブロックの描画。render_callback として本体が呼ぶ。
	 *
	 * @param array  $attributes ブロックの属性。
	 * @param string $content    中身（このブロックは持たない）。
	 * @param mixed  $block      ブロック。
	 * @return string
	 */
	public static function render_block( $attributes, $content, $block ) {
		$attributes = (array) $attributes;
		$post_id    = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
		$post       = get_post( $post_id );
		$field      = isset( $attributes['field'] ) ? (string) $attributes['field'] : '';

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$inner = self::render_field( $field, $post );
		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes( array( 'class' => 'is-field-' . sanitize_html_class( $field ) ) ),
			$inner
		);
	}

	/**
	 * 操作の列のブロックの描画。render_callback として本体が呼ぶ。
	 *
	 * パスワード保護などで中身が無ければ、空の枠を残さない。
	 *
	 * @param array  $attributes ブロックの属性。
	 * @param string $content    中のブロックが描いた HTML。
	 * @return string
	 */
	public static function render_actions( $attributes, $content ) {
		if ( '' === trim( (string) $content ) ) {
			return '';
		}

		return sprintf(
			'<div %1$s role="group" aria-label="%2$s">%3$s</div>',
			get_block_wrapper_attributes(),
			esc_attr__( 'Event actions', 'eventwatch' ),
			$content
		);
	}

	/**
	 * 欄の中身。出すものが無ければ空。
	 *
	 * @param string  $field 欄の名前。
	 * @param WP_Post $post  イベント。
	 * @return string
	 */
	public static function render_field( $field, WP_Post $post ) {
		if ( Post_Type::POST_TYPE !== $post->post_type || post_password_required( $post ) ) {
			return '';
		}

		switch ( $field ) {
			case 'back':
				return self::render_back();
			case 'category':
				return self::render_category( $post );
			case 'dates':
				return self::render_dates( $post );
			case 'venue':
				return self::render_venue( $post );
			case 'organizer':
				return self::row( __( 'Organizer', 'eventwatch' ), esc_html( get_bloginfo( 'name' ) ) );
			case 'map':
				return self::render_map( $post );
			case 'calendar':
				return self::render_calendar( $post );
			case 'interested':
				return self::render_interested( $post );
			case 'share':
				return self::render_share( $post );
			case 'official':
				return self::render_official( $post );
		}

		return '';
	}

	/**
	 * SVG の印。
	 *
	 * @param string $name 印の名前。
	 * @param int    $size 大きさ。
	 * @return string
	 */
	private static function icon( $name, $size = 16 ) {
		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="%2$s"></path></svg>',
			$size,
			esc_attr( self::ICONS[ $name ] )
		);
	}

	/**
	 * 新しいタブで開くリンクの属性と、読み上げの文。
	 *
	 * @return string
	 */
	private static function new_tab_text() {
		return '<span class="eventwatch-detail__sr">' . esc_html__( ' (opens in a new tab)', 'eventwatch' ) . '</span>';
	}

	/**
	 * 項目の名前と値の行。
	 *
	 * @param string $label 名前。
	 * @param string $value エスケープ済みの値。
	 * @return string
	 */
	private static function row( $label, $value ) {
		return '<dl class="eventwatch-detail__row"><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></dl>';
	}

	/**
	 * 一覧へ戻るリンク。一覧のページを選んでいなければ出さない。
	 *
	 * @return string
	 */
	private static function render_back() {
		$page = get_post( absint( get_option( Settings::LIST_PAGE, 0 ) ) );
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			return '';
		}

		return sprintf(
			'<a class="eventwatch-detail__back" href="%1$s">%2$s%3$s</a>',
			esc_url( get_permalink( $page ) ),
			self::icon( 'back' ),
			esc_html__( 'Back to events', 'eventwatch' )
		);
	}

	/**
	 * カテゴリ。一覧と同じ語。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_category( WP_Post $post ) {
		$category = Event_List::category_text( $post );

		return '' !== $category ? '<span class="eventwatch-detail__category">' . esc_html( $category ) . '</span>' : '';
	}

	/**
	 * 日時。全組と注記。全部終わっていれば「終了」の印。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_dates( WP_Post $post ) {
		$rows = Event_Dates::occurrences( $post->ID );
		if ( ! $rows ) {
			return '';
		}

		$items = '';
		foreach ( $rows as $row ) {
			$items .= '<li><time datetime="' . esc_attr( $row['datetime'] ) . '">' . esc_html( $row['text'] ) . '</time></li>';
		}

		$value = '<ul class="eventwatch-detail__dates">' . $items . '</ul>';

		if ( Event_List::is_ended( $post->ID ) ) {
			$value = '<span class="eventwatch-event__ended">' . self::icon( 'ended', 14 ) . esc_html_x( 'Ended', 'label on an event that has ended', 'eventwatch' ) . '</span>' . $value;
		}

		$note = (string) get_post_meta( $post->ID, Event_Meta::SCHEDULE_NOTE, true );
		if ( '' !== $note ) {
			$value .= '<p class="eventwatch-detail__note">' . esc_html( $note ) . '</p>';
		}

		return self::row( __( 'Date & time', 'eventwatch' ), $value );
	}

	/**
	 * 会場。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_venue( WP_Post $post ) {
		$value = '<span>' . esc_html( Event_List::venue_text( $post->ID ) ) . '</span>';

		if ( get_post_meta( $post->ID, Event_Meta::ONLINE, true ) ) {
			$value .= '<span class="eventwatch-detail__note">' . esc_html__( 'See the description and the official page for how to join.', 'eventwatch' ) . '</span>';
		} else {
			$address = (string) get_post_meta( $post->ID, Event_Meta::VENUE_ADDRESS, true );
			if ( '' !== $address ) {
				$value .= '<span class="eventwatch-detail__note">' . esc_html( $address ) . '</span>';
			}
		}

		return self::row( __( 'Venue', 'eventwatch' ), $value );
	}

	/**
	 * 地図で引く語。住所、無ければ会場名。オンラインと場所未定は空。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function map_query( $post_id ) {
		if ( get_post_meta( $post_id, Event_Meta::ONLINE, true ) ) {
			return '';
		}

		$address = (string) get_post_meta( $post_id, Event_Meta::VENUE_ADDRESS, true );

		return '' !== $address ? $address : (string) get_post_meta( $post_id, Event_Meta::VENUE_NAME, true );
	}

	/**
	 * 地図。既定はリンクだけ。キーがあるときだけ埋め込む。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_map( WP_Post $post ) {
		$query = self::map_query( $post->ID );
		if ( '' === $query ) {
			return '';
		}

		$html = '';
		$key  = (string) get_option( Settings::MAPS_KEY, '' );
		if ( '' !== $key ) {
			$embed = add_query_arg(
				array(
					'key' => rawurlencode( $key ),
					'q'   => rawurlencode( $query ),
				),
				'https://www.google.com/maps/embed/v1/place'
			);
			$venue = (string) get_post_meta( $post->ID, Event_Meta::VENUE_NAME, true );
			$html .= sprintf(
				'<iframe class="eventwatch-detail__map" src="%1$s" title="%2$s" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>',
				esc_url( $embed ),
				/* translators: %s: the venue name or address. */
				esc_attr( sprintf( __( 'Venue map: %s', 'eventwatch' ), '' !== $venue ? $venue : $query ) )
			);
		}

		$links = array(
			array(
				__( 'Open in Google Maps', 'eventwatch' ),
				add_query_arg(
					array(
						'api'   => '1',
						'query' => rawurlencode( $query ),
					),
					'https://www.google.com/maps/search/'
				),
			),
			array(
				__( 'Open in OpenStreetMap', 'eventwatch' ),
				add_query_arg( 'query', rawurlencode( $query ), 'https://www.openstreetmap.org/search' ),
			),
		);

		$html .= '<div class="eventwatch-detail__map-links">';
		foreach ( $links as $link ) {
			$html .= sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s%4$s</a>',
				esc_url( $link[1] ),
				esc_html( $link[0] ),
				self::new_tab_text(),
				self::icon( 'ext', 14 )
			);
		}

		return $html . '</div>';
	}

	/**
	 * メニューを開くボタン。
	 *
	 * @param string $id    メニューの id。
	 * @param string $label 語。
	 * @param string $icon  印。
	 * @param bool   $chev  下向きの印を付けるか。
	 * @param string $attrs 追加の属性。
	 * @return string
	 */
	private static function menu_button( $id, $label, $icon, $chev, $attrs = '' ) {
		return sprintf(
			'<button type="button" class="eventwatch-detail__button" aria-haspopup="menu" aria-expanded="false" aria-controls="%1$s" data-eventwatch-menu-trigger %2$s>%3$s%4$s%5$s</button>',
			esc_attr( $id ),
			$attrs,
			self::icon( $icon ),
			esc_html( $label ),
			$chev ? self::icon( 'chevron', 14 ) : ''
		);
	}

	/**
	 * カレンダーに追加。全部終わったイベントと日程の無いイベントには出さない。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_calendar( WP_Post $post ) {
		if ( ! Event_Ical::is_downloadable( $post ) ) {
			return '';
		}

		$next = Event_Dates::next_occurrence( $post->ID );
		if ( null === $next ) {
			return '';
		}

		$id = 'eventwatch-calendar-' . $post->ID;

		$items = sprintf(
			'<li role="none"><a role="menuitem" href="%1$s" rel="nofollow" download>%2$s%3$s</a></li>',
			esc_url( Event_Ical::download_url( $post ) ),
			self::icon( 'download' ),
			esc_html__( 'Download iCal (.ics)', 'eventwatch' )
		);

		$google = esc_html__( 'Google Calendar', 'eventwatch' );
		if ( count( Event_Dates::occurrences( $post->ID ) ) > 1 ) {
			$google .= '<span class="eventwatch-detail__menu-note">' . esc_html( $next['text'] ) . '</span>';
		}

		$items .= sprintf(
			'<li role="none"><a role="menuitem" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="eventwatch-detail__menu-text">%3$s%4$s</span></a></li>',
			esc_url( Event_Ical::google_url( $post, $next ) ),
			self::icon( 'ext' ),
			$google,
			self::new_tab_text()
		);

		return '<div class="eventwatch-detail__action" data-eventwatch-menu>'
			. self::menu_button( $id, __( 'Add to calendar', 'eventwatch' ), 'calendar', true )
			. '<ul role="menu" class="eventwatch-detail__menu is-start" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Add to calendar', 'eventwatch' ) . '" hidden>' . $items . '</ul>'
			. '</div>';
	}

	/**
	 * EventWatch のこのイベントの URL。掲載中でなければ空。
	 *
	 * 審査の状態はサイトの表示を変えない。掲載中かどうかを読むのは「気になる」だけ。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	public static function listing_url( $post_id ) {
		$slug = (string) get_post_meta( $post_id, self::LISTING_SLUG, true );
		// DB を写した複製にはスラッグも写っている。本番の掲載を複製のページから指さない。
		if ( '' === $slug || Site_Identity::has_changed() ) {
			return '';
		}

		$prefix = 0 === strpos( determine_locale(), 'ja' ) ? '/events/' : '/en/events/';

		return self::EVENTWATCH_WEB . $prefix . rawurlencode( $slug );
	}

	/**
	 * 「気になる」。掲載中で、これから開催されるイベントにだけ出す。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_interested( WP_Post $post ) {
		$url = self::listing_url( $post->ID );
		if ( '' === $url || null === Event_Dates::next_occurrence( $post->ID ) ) {
			return '';
		}

		$dialog  = 'eventwatch-interested-' . $post->ID;
		$heading = $dialog . '-heading';

		$store_link = static function ( $href, $label ) {
			return sprintf(
				'<a class="eventwatch-detail__button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s%4$s</a>',
				esc_url( $href ),
				esc_html( $label ),
				self::new_tab_text(),
				self::icon( 'ext', 14 )
			);
		};

		return '<div class="eventwatch-detail__action">'
			. sprintf(
				'<button type="button" class="eventwatch-detail__button" aria-haspopup="dialog" data-eventwatch-dialog-open="%1$s">%2$s%3$s</button>',
				esc_attr( $dialog ),
				self::icon( 'save' ),
				esc_html_x( 'Save', 'mark an event you are interested in', 'eventwatch' )
			)
			. '<dialog class="eventwatch-detail__dialog" id="' . esc_attr( $dialog ) . '" aria-labelledby="' . esc_attr( $heading ) . '">'
			. '<div class="eventwatch-detail__dialog-head">'
			. '<h2 id="' . esc_attr( $heading ) . '" tabindex="-1">' . esc_html__( 'Get a reminder in the app', 'eventwatch' ) . '</h2>'
			. '<button type="button" class="eventwatch-detail__close" aria-label="' . esc_attr__( 'Close', 'eventwatch' ) . '" data-eventwatch-dialog-close>' . self::icon( 'close', 20 ) . '</button>'
			. '</div>'
			. '<p>' . esc_html__( 'Save this event in the EventWatch app, and we’ll send you a notification before it starts so you don’t forget.', 'eventwatch' ) . '</p>'
			. '<a class="eventwatch-detail__primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open in app', 'eventwatch' ) . '</a>'
			. '<div class="eventwatch-detail__stores">'
			. '<p>' . esc_html__( 'Don’t have the app? Get it here.', 'eventwatch' ) . '</p>'
			. '<div>' . $store_link( self::APP_STORE_URL, __( 'Get it on the App Store', 'eventwatch' ) ) . $store_link( self::GOOGLE_PLAY, __( 'Get it on Google Play', 'eventwatch' ) ) . '</div>'
			. '</div>'
			. '</dialog>'
			. '</div>';
	}

	/**
	 * 共有。端末の共有の機能が無いときのメニューを持つ。外部のスクリプトは読み込まない。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_share( WP_Post $post ) {
		$url   = (string) get_permalink( $post );
		$title = self::plain_title( $post );
		$id    = 'eventwatch-share-' . $post->ID;

		$services = array(
			array(
				__( 'Share on X', 'eventwatch' ),
				add_query_arg(
					array(
						'text' => rawurlencode( $title ),
						'url'  => rawurlencode( $url ),
					),
					'https://x.com/intent/post'
				),
			),
			array(
				__( 'Send on LINE', 'eventwatch' ),
				add_query_arg( 'url', rawurlencode( $url ), 'https://social-plugins.line.me/lineit/share' ),
			),
			array(
				__( 'Share on Facebook', 'eventwatch' ),
				add_query_arg( 'u', rawurlencode( $url ), 'https://www.facebook.com/sharer/sharer.php' ),
			),
		);

		$items = sprintf(
			'<li role="none" class="eventwatch-detail__copy"><button type="button" role="menuitem" data-eventwatch-copy>%1$s%2$s</button></li>',
			self::icon( 'link' ),
			esc_html__( 'Copy link', 'eventwatch' )
		);
		foreach ( $services as $service ) {
			$items .= sprintf(
				'<li role="none"><a role="menuitem" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s%4$s</a></li>',
				esc_url( $service[1] ),
				self::icon( 'ext' ),
				esc_html( $service[0] ),
				self::new_tab_text()
			);
		}

		$data = sprintf(
			'data-eventwatch-share data-url="%1$s" data-title="%2$s" data-copied="%3$s" data-copy-failed="%4$s"',
			esc_attr( $url ),
			esc_attr( $title ),
			esc_attr__( 'Link copied', 'eventwatch' ),
			esc_attr__( 'The link could not be copied.', 'eventwatch' )
		);

		return '<div class="eventwatch-detail__action" data-eventwatch-menu ' . $data . '>'
			. self::menu_button( $id, __( 'Share', 'eventwatch' ), 'share', false )
			. '<ul role="menu" class="eventwatch-detail__menu" id="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Share', 'eventwatch' ) . '" hidden>' . $items . '</ul>'
			. '</div>'
			// 文が入る前から置いておく。後から足した領域は読み上げられないことがある。
			. '<p class="eventwatch-detail__status" role="status" aria-live="polite" data-eventwatch-status></p>';
	}

	/**
	 * 公式ページ。別の公式ページを入れたときだけ。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function render_official( WP_Post $post ) {
		$url = (string) get_post_meta( $post->ID, Event_Meta::URL, true );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		return sprintf(
			'<a class="eventwatch-detail__primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s%4$s</a>',
			esc_url( $url ),
			esc_html__( 'Visit official page', 'eventwatch' ),
			self::new_tab_text(),
			self::icon( 'ext', 14 )
		);
	}

	/**
	 * HTML ではない出力先へ渡す文。the_title などの整形が作る実体参照を文字へ戻す。
	 *
	 * @param string $text 文。
	 * @return string
	 */
	public static function plain_text( $text ) {
		return html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * HTML ではない出力先へ渡す題。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	public static function plain_title( WP_Post $post ) {
		return self::plain_text( get_the_title( $post ) );
	}

	/**
	 * SEO プラグインが description と og:* を出すか。
	 *
	 * @return bool
	 */
	private static function has_seo_plugin() {
		foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 説明の文。抜粋、無ければ本文のタグを除いた先頭。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	public static function description( WP_Post $post ) {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : excerpt_remove_blocks( $post->post_content );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', self::plain_text( strip_shortcodes( $text ) ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::DESCRIPTION_LENGTH ) : substr( $text, 0, self::DESCRIPTION_LENGTH );
	}

	/**
	 * head に出すもの。パスワードを知らない人には何も出さない。
	 *
	 * @return void
	 */
	public static function print_head() {
		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
			return;
		}

		/**
		 * description と og:* を出すか。既定は、主な SEO プラグインが無いときだけ出す。
		 *
		 * @param bool    $output 出すか。
		 * @param WP_Post $post   イベント。
		 */
		if ( apply_filters( 'eventwatch_output_meta_tags', ! self::has_seo_plugin(), $post ) ) {
			echo wp_kses(
				self::meta_tags( $post ),
				array(
					'meta' => array(
						'name'     => true,
						'property' => true,
						'content'  => true,
					),
				)
			);
		}

		/**
		 * 構造化データを出すか。既定は出す。SEO プラグインが同じ Event を出すなら、これで止める。
		 *
		 * @param bool    $output 出すか。
		 * @param WP_Post $post   イベント。
		 */
		if ( apply_filters( 'eventwatch_output_json_ld', true, $post ) ) {
			$graph = self::json_ld( $post );
			if ( $graph ) {
				// JSON_HEX_TAG で script を閉じさせない。
				wp_print_inline_script_tag( self::encode_json_ld( $graph ), array( 'type' => 'application/ld+json' ) );
			}
		}
	}

	/**
	 * Description と og:* のタグ。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	public static function meta_tags( WP_Post $post ) {
		$description = self::description( $post );
		$html        = '';

		if ( '' !== $description ) {
			$html .= '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}

		$properties = array(
			'og:type'        => 'article',
			'og:site_name'   => self::plain_text( get_bloginfo( 'name' ) ),
			'og:title'       => self::plain_title( $post ),
			'og:url'         => (string) get_permalink( $post ),
			'og:description' => $description,
			'og:image'       => (string) get_the_post_thumbnail_url( $post, 'full' ),
		);
		foreach ( $properties as $property => $content ) {
			if ( '' !== $content ) {
				$html .= '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '">' . "\n";
			}
		}

		return $html;
	}

	/**
	 * 構造化データ。日程ごとに 1 つの Event を並べる。日程が無ければ空。
	 *
	 * @param WP_Post $post イベント。
	 * @return array
	 */
	public static function json_ld( WP_Post $post ) {
		$rows = Event_Dates::occurrences( $post->ID );
		if ( ! $rows ) {
			return array();
		}

		$url   = (string) get_permalink( $post );
		$base  = array(
			'@type'               => 'Event',
			'name'                => self::plain_title( $post ),
			'url'                 => $url,
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => get_post_meta( $post->ID, Event_Meta::ONLINE, true )
				? 'https://schema.org/OnlineEventAttendanceMode'
				: 'https://schema.org/OfflineEventAttendanceMode',
			'organizer'           => array(
				'@type' => 'Organization',
				'name'  => self::plain_text( get_bloginfo( 'name' ) ),
				'url'   => home_url( '/' ),
			),
		);
		$extra = array(
			'description' => self::description( $post ),
			'image'       => (string) get_the_post_thumbnail_url( $post, 'full' ),
		);
		foreach ( $extra as $key => $value ) {
			if ( '' !== $value ) {
				$base[ $key ] = $value;
			}
		}

		$location = self::json_ld_location( $post, $url );
		if ( $location ) {
			$base['location'] = $location;
		}

		$graph = array();
		foreach ( $rows as $row ) {
			$event = $base;

			$event['startDate'] = $row['datetime'];
			if ( '' !== $row['end_datetime'] ) {
				$event['endDate'] = $row['end_datetime'];
			}
			$graph[] = $event;
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * 構造化データの場所。場所未定なら空。
	 *
	 * @param WP_Post $post イベント。
	 * @param string  $url  イベントの URL。
	 * @return array
	 */
	private static function json_ld_location( WP_Post $post, $url ) {
		if ( get_post_meta( $post->ID, Event_Meta::ONLINE, true ) ) {
			$official = (string) get_post_meta( $post->ID, Event_Meta::URL, true );

			return array(
				'@type' => 'VirtualLocation',
				'url'   => '' !== $official ? $official : $url,
			);
		}

		$name    = (string) get_post_meta( $post->ID, Event_Meta::VENUE_NAME, true );
		$address = (string) get_post_meta( $post->ID, Event_Meta::VENUE_ADDRESS, true );
		if ( '' === $name && '' === $address ) {
			return array();
		}

		$location = array( '@type' => 'Place' );
		if ( '' !== $name ) {
			$location['name'] = $name;
		}
		// Google は address を必須にしているので、住所が無ければ会場名を入れる。
		$location['address'] = '' !== $address ? $address : $name;

		return $location;
	}

	/**
	 * Script の中に入れられる JSON にする。
	 *
	 * @param array $graph データ。
	 * @return string
	 */
	public static function encode_json_ld( array $graph ) {
		return (string) wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	}
}
