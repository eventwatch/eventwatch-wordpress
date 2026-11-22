<?php
/**
 * 過去のイベントのブロックとショートコードの登録。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ショートコードはブロックと同じ経路を通す。
 */
class Past_Events_Block {

	const NAME      = 'eventwatch/past-events';
	const SHORTCODE = 'eventwatch_past_events';

	/**
	 * ブロックとショートコードを登録する。一覧の CSS のハンドルを使うので、一覧の後に呼ぶ。
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( self::class, 'render_shortcode' ) );

		$dir = plugin_dir_path( PLUGIN_FILE ) . 'build/blocks/past-events';
		// ビルドしていない作業ツリーでも、サイトを壊さない。
		if ( ! is_readable( $dir . '/block.json' ) ) {
			return;
		}

		register_block_type( $dir, array( 'render_callback' => array( self::class, 'render_block' ) ) );

		wp_set_script_translations(
			generate_block_asset_handle( self::NAME, 'editorScript' ),
			'eventwatch',
			plugin_dir_path( PLUGIN_FILE ) . 'languages'
		);
	}

	/**
	 * ブロックを描く。render_callback として本体が呼ぶ。
	 *
	 * @param array $attributes ブロックの属性。
	 * @return string
	 */
	public static function render_block( $attributes ) {
		return Event_List::render( (array) $attributes, Event_List::SCOPE_PAST );
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
				'layout'     => Event_List::LAYOUT_LIST,
				'per_page'   => Event_List::DEFAULT_SIZE,
				'months'     => Event_List::DEFAULT_MONTHS_BACK,
				'empty_text' => '',
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
					// 整数にしない。`months=""` が 0（すべて）にならず、検証で既定に戻るように。
					'monthsBack' => $atts['months'],
					'emptyText'  => $atts['empty_text'],
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}
