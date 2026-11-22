<?php
/**
 * イベント一覧のブロックとショートコードの登録。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ショートコードはブロックと同じ経路（外側の要素・CSS の読み込み・描画）を通す。
 */
class Event_List_Block {

	const NAME      = 'eventwatch/event-list';
	const SHORTCODE = 'eventwatch_events';

	/**
	 * ブロックとショートコードを登録する。
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( self::class, 'render_shortcode' ) );

		$dir = plugin_dir_path( PLUGIN_FILE ) . 'build/blocks/event-list';
		// ビルドしていない作業ツリーでも、サイトを壊さない。
		if ( ! is_readable( $dir . '/block.json' ) ) {
			return;
		}

		register_block_type( $dir, array( 'render_callback' => array( self::class, 'render_block' ) ) );

		$handle = generate_block_asset_handle( self::NAME, 'editorScript' );
		wp_set_script_translations( $handle, 'eventwatch', plugin_dir_path( PLUGIN_FILE ) . 'languages' );
		wp_add_inline_script(
			$handle,
			'window.eventwatchEventList = ' . wp_json_encode(
				array( 'prefectures' => Editor_Assets::to_options( Event_Options::prefectures() ) )
			) . ';',
			'before'
		);
	}

	/**
	 * ブロックを描く。render_callback として本体が呼ぶ。
	 *
	 * @param array $attributes ブロックの属性。
	 * @return string
	 */
	public static function render_block( $attributes ) {
		return Event_List::render( (array) $attributes );
	}

	/**
	 * ショートコードを描く。
	 *
	 * @param array|string $atts ショートコードの属性。
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'layout'     => Event_List::LAYOUT_CARDS,
				'per_page'   => Event_List::DEFAULT_SIZE,
				'order'      => 'asc',
				'category'   => '',
				'tag'        => '',
				'prefecture' => 0,
				'from'       => '',
				'to'         => '',
				'empty_text' => '',
				'period'     => '',
			),
			$atts,
			self::SHORTCODE
		);

		return render_block(
			array(
				'blockName'    => self::NAME,
				'attrs'        => array(
					'layout'     => $atts['layout'],
					'perPage'    => (int) $atts['per_page'],
					'order'      => $atts['order'],
					'categoryId' => self::term_id( $atts['category'], Post_Type::TAX_CATEGORY ),
					'tagId'      => self::term_id( $atts['tag'], Post_Type::TAX_TAG ),
					'prefecture' => (int) $atts['prefecture'],
					'dateFrom'   => $atts['from'],
					'dateTo'     => $atts['to'],
					'emptyText'  => $atts['empty_text'],
					'period'     => $atts['period'],
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * スラッグをタームの ID にする。
	 *
	 * 誤ったスラッグで全件を出さないよう、見つからなければ、どのタームにも当たらない ID を返す。
	 *
	 * @param string $slug     スラッグ。
	 * @param string $taxonomy タクソノミー。
	 * @return int
	 */
	private static function term_id( $slug, $taxonomy ) {
		if ( '' === $slug ) {
			return 0;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );

		return $term ? (int) $term->term_id : PHP_INT_MAX;
	}
}
