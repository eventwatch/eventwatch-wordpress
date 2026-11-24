<?php
/**
 * 訳文が HTML を含んでいても、生のまま画面に出ないことのテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Admin_Columns;
use EventWatch\Connection;
use EventWatch\Event_Detail;
use EventWatch\Event_List;
use EventWatch\Event_List_Block;
use EventWatch\Event_Meta;
use EventWatch\Event_Permalink;
use EventWatch\Event_Search_Block;
use EventWatch\Import_Admin;
use EventWatch\Import_List_Table;
use EventWatch\Past_Events_Block;
use EventWatch\Post_Type;
use EventWatch\Settings;
use EventWatch\Site_Identity;
use WP_UnitTestCase;

/**
 * プラグインの全訳文の後ろに目印のタグを付け、描いた結果に生のタグが残らないことを見る。
 *
 * PHPCS の EscapeOutput は自分のコードの echo の引数しか見ない。描画の返り値や、本体の関数へ渡して本体が生のまま出す値
 * （paginate_links の prev_text、add_settings_error の文、設定の節と欄の見出し、列の見出し、メニューの名前）は見えないので、
 * 本体を通った後の出力で確かめる。
 */
class Escaped_Translations_Test extends WP_UnitTestCase {

	const RAW     = '<x-ew-raw>';
	const ESCAPED = '&lt;x-ew-raw&gt;';

	/**
	 * 訳文に目印を付け、ブロックを登録する。
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		Event_Meta::register();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->set_permalink_structure( '/%postname%/' );
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 前のテストの誤りを数えない。

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach (
			array(
				Event_List_Block::NAME    => array( Event_List_Block::class, 'register' ),
				Past_Events_Block::NAME   => array( Past_Events_Block::class, 'register' ),
				Event_Search_Block::NAME  => array( Event_Search_Block::class, 'register' ),
				Event_Detail::FIELD_BLOCK => array( Event_Detail::class, 'register_blocks' ),
			) as $name => $register
		) {
			if ( ! $registry->is_registered( $name ) ) {
				call_user_func( $register );
			}
		}

		$mark = static function ( $translation ) {
			return $translation . self::RAW;
		};
		add_filter( 'gettext_eventwatch', $mark );
		add_filter( 'gettext_with_context_eventwatch', $mark );
		add_filter( 'ngettext_eventwatch', $mark );
		add_filter( 'ngettext_with_context_eventwatch', $mark );
	}

	/**
	 * 画面とユーザーを戻す。
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * 目印が付いた訳文が出ていて、どれもエスケープされている。
	 *
	 * @param string $html 出力。
	 * @return void
	 */
	private function assert_escaped( $html ) {
		$this->assertStringContainsString( self::ESCAPED, $html, '訳文が出ていないので、この出力では確かめられない。' );
		$this->assertStringNotContainsString( self::RAW, $html );
	}

	/**
	 * 出力を受け取る。
	 *
	 * @param callable $callback 出力する処理。
	 * @return string
	 */
	private function output_of( callable $callback ) {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * イベントを作る。
	 *
	 * @param int $days 今日から数えた日数。負は終わったイベント。
	 * @return int
	 */
	private function event( $days ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'event ' . $days,
			)
		);
		$start   = wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS, wp_timezone() ) . ' 13:30';
		update_post_meta(
			$post_id,
			Event_Meta::OCCURRENCES,
			array(
				array(
					'start' => $start,
					'end'   => '',
				),
			)
		);
		update_post_meta( $post_id, Event_Meta::VENUE_NAME, 'Hall' );
		update_post_meta( $post_id, Event_Meta::VENUE_ADDRESS, 'Tokyo' );
		return $post_id;
	}

	/**
	 * ブロックを、本番と同じ描画の関数で描く。
	 *
	 * @param string $name  ブロックの名前。
	 * @param array  $attrs 属性。
	 * @return string
	 */
	private function block( $name, array $attrs ) {
		Event_List::reset_rendered();
		return render_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * 1 ページ目と 2 ページ目を描き、前へ・次へのリンクの文がエスケープされていることを見る。
	 *
	 * 前へのリンクは 2 ページ目からしか出ないので、1 ページ目だけでは prev_text を確かめられない。
	 *
	 * @param string   $label  失敗のときに出す名前。
	 * @param callable $render 描く処理。
	 * @return void
	 */
	private function assert_pagination_escaped( $label, callable $render ) {
		$first = $render();
		$this->assert_escaped( $first );
		$this->assertSame( 1, preg_match( '/([a-z0-9_]+)=2#/', $first, $param ), $label . ' の 2 ページ目へのリンクが無い。' );

		$_GET[ $param[1] ] = '2';
		$second            = $render();
		unset( $_GET[ $param[1] ] );

		$this->assert_escaped( $second );
		foreach ( array( 'prev', 'next' ) as $rel ) {
			$this->assertSame( 1, preg_match( '#class="' . $rel . ' page-numbers"[^>]*>(.*?)</a>#s', $second, $link ), $label . ' の ' . $rel . ' のリンクが無い。' );
			$this->assertStringContainsString( self::ESCAPED, $link[1], $label . ' の ' . $rel . ' の文' );
		}
	}

	/**
	 * 一覧・過去・検索のブロックとショートコード。3 ページにして、2 ページ目の前へ・次への両方を出す。
	 */
	public function test_list_blocks_and_shortcodes() {
		foreach ( array( 1, 2, 3, -1, -2, -3 ) as $days ) {
			$this->event( $days );
		}

		foreach ( array( Event_List_Block::NAME, Past_Events_Block::NAME, Event_Search_Block::NAME ) as $name ) {
			$this->assert_pagination_escaped(
				$name,
				function () use ( $name ) {
					return $this->block( $name, array( 'perPage' => 1 ) );
				}
			);
		}

		foreach ( array( Event_List_Block::SHORTCODE, Past_Events_Block::SHORTCODE, Event_Search_Block::SHORTCODE ) as $shortcode ) {
			$this->assert_pagination_escaped(
				$shortcode,
				static function () use ( $shortcode ) {
					Event_List::reset_rendered();
					return do_shortcode( '[' . $shortcode . ' per_page="1"]' );
				}
			);
		}
	}

	/**
	 * 詳細ページの既定の並び。
	 */
	public function test_detail_page() {
		$this->go_to( get_permalink( $this->event( 3 ) ) );

		$this->assert_escaped( do_blocks( Event_Detail::default_content( 'article' ) ) );
	}

	/**
	 * 設定画面の節・欄の見出しと、入力の誤りの通知。
	 */
	public function test_settings_page() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'settings_page_' . Settings::PAGE );
		Site_Identity::use_as_moved();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );

		// 固定ページが無いと、一覧のページの欄は select ごと出ない。
		self::factory()->post->create( array( 'post_type' => 'page' ) );

		Settings::add_fields();
		$sections = $this->output_of(
			static function () {
				do_settings_sections( Settings::PAGE );
			}
		);
		$this->assert_escaped( $sections );

		// 節の見出し 3 つと欄の見出し 6 つ。どれか 1 つが生のままでも、ここで分かる。
		preg_match_all( '#<(h2|th)\b[^>]*>(.*?)</\1>#s', $sections, $headings, PREG_SET_ORDER );
		$this->assertCount( 9, $headings );
		foreach ( $headings as $heading ) {
			$this->assertStringContainsString( self::ESCAPED, $heading[2] );
		}
		$this->assertSame( 1, preg_match( '#<option[^>]*value="0"[^>]*>(.*?)</option>#s', $sections, $none ) );
		$this->assertStringContainsString( self::ESCAPED, $none[1] );

		// 誤りの文は 5 つ。URL の接頭辞は、形・予約語・年と月のアーカイブとの重なりの 3 通り。
		Settings::sanitize_maps_key( 'not a key!' );
		Settings::sanitize_api_key( 'not-a-key' );
		Event_Permalink::sanitize_slug( 'Not A Slug' );
		Event_Permalink::sanitize_slug( 'wp-admin' );
		Event_Permalink::sanitize_slug( '2024' );
		$notices = $this->output_of(
			static function () {
				settings_errors();
			}
		);
		$this->assertSame( 5, substr_count( $notices, self::ESCAPED ) );
		$this->assertStringNotContainsString( self::RAW, $notices );
	}

	/**
	 * 投稿タイプとタクソノミーのラベル。本体がメニュー・管理バー・列の見出しへ生のまま入れる。
	 */
	public function test_post_type_labels() {
		Post_Type::register();

		$labels = (array) get_post_type_object( Post_Type::POST_TYPE )->labels;
		foreach ( array( 'singular_name', 'add_new_item', 'edit_item', 'view_item', 'view_items', 'all_items', 'name_admin_bar' ) as $key ) {
			$this->assertStringContainsString( self::ESCAPED, $labels[ $key ], $key );
		}
		foreach ( array( Post_Type::TAX_CATEGORY, Post_Type::TAX_TAG ) as $taxonomy ) {
			$labels = array_merge( $labels, (array) get_taxonomy( $taxonomy )->labels );
			$this->assertStringContainsString( self::ESCAPED, get_taxonomy( $taxonomy )->labels->name, $taxonomy );
		}
		foreach ( $labels as $key => $label ) {
			$this->assertStringNotContainsString( self::RAW, (string) $label, $key );
		}
	}

	/**
	 * 投稿一覧の列と、取り込みの一覧表の見出し。本体の print_column_headers() が生のまま出す。
	 */
	public function test_column_headers() {
		foreach ( Admin_Columns::add_columns( array( 'title' => 'Title' ) ) as $label ) {
			$this->assertStringNotContainsString( self::RAW, $label );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$table = $this->output_of(
			static function () {
				( new Import_List_Table(
					array(
						'list'  => array(),
						'total' => 0,
					)
				) )->display();
			}
		);
		$this->assert_escaped( $table );
	}

	/**
	 * 管理メニューの名前。本体の menu-header.php が生のまま出す。
	 */
	public function test_menu_titles() {
		global $submenu;

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		if ( is_multisite() ) {
			grant_super_admin( $user );
		}
		set_current_screen( 'dashboard' );
		Post_Type::register();
		update_option( Connection::KEY_OPTION, 'ewk_0123456789012345678901234567890123456789012' );

		Settings::add_page();
		Import_Admin::add_page();

		$titles = array();
		foreach ( (array) $submenu as $items ) {
			foreach ( $items as $item ) {
				if ( in_array( $item[2], array( Settings::PAGE, Import_Admin::PAGE ), true ) ) {
					$titles[] = $item[0] . ' ' . $item[3];
				}
			}
		}

		$this->assertCount( 2, $titles );
		foreach ( $titles as $title ) {
			$this->assertStringNotContainsString( self::RAW, $title );
		}
	}
}
