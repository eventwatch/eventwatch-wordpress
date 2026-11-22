<?php
/**
 * イベントの検索の条件と描画のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Event_List;
use EventWatch\Event_List_Block;
use EventWatch\Event_Meta;
use EventWatch\Event_Search;
use EventWatch\Event_Search_Block;
use EventWatch\Post_Type;
use ReflectionProperty;
use WP_UnitTestCase;

/**
 * Event_Search と Event_Search_Block のテスト。
 */
class Event_Search_Test extends WP_UnitTestCase {

	/**
	 * テストの中の「今」。作るときと確かめるときで時計を読み直すと、間で日が変わって食い違う。
	 *
	 * @var \DateTimeImmutable
	 */
	private $now;

	/**
	 * 基底クラスの tear_down がメタとブロックの登録を外すので、テストごとに揃える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->now = new \DateTimeImmutable( 'now', wp_timezone() );
		$registry  = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( Event_List_Block::NAME ) ) {
			Event_List_Block::register();
		}
		if ( ! $registry->is_registered( Event_Search_Block::NAME ) ) {
			Event_Search_Block::register();
		}
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/search/';
		$this->reset_instances();
	}

	/**
	 * URL のクエリを元に戻す。
	 */
	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * 描いたブロックの数を戻す。
	 */
	private function reset_instances() {
		$property = new ReflectionProperty( Event_Search::class, 'instances' );
		$property->setAccessible( true );
		$property->setValue( null, 0 );
	}

	/**
	 * イベントを作る。
	 *
	 * @param string $title  イベント名。
	 * @param string $modify 開催日の相対の指定。
	 * @param array  $meta   メタ。
	 * @param array  $post   投稿の値。
	 * @return int
	 */
	private function event( $title, $modify = '+3 days', array $meta = array(), array $post = array() ) {
		$date    = $this->now->modify( $modify )->format( 'Y-m-d' );
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => Post_Type::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				),
				$post
			)
		);
		update_post_meta(
			$post_id,
			Event_Meta::OCCURRENCES,
			array(
				array(
					'start' => $date . ' 13:00',
					'end'   => $date . ' 15:00',
				),
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * ブロックを描く。
	 *
	 * @param array $attrs 属性。
	 * @return string
	 */
	private function render( array $attrs = array() ) {
		return render_block(
			array(
				'blockName'    => Event_Search_Block::NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * 条件で描いた結果の題。
	 *
	 * @param array $get URL のクエリ。
	 * @return string[]
	 */
	private function titles( array $get ) {
		$_GET = $get;
		$this->reset_instances();
		preg_match_all( '#<h3 class="eventwatch-event__title">(.*?)</h3>#', $this->render( array( 'perPage' => 24 ) ), $matches );
		return $matches[1];
	}

	/**
	 * 検索のブロックは render.php を使わず、render_callback で描く。
	 */
	public function test_block_renders_through_callback() {
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( Event_Search_Block::NAME );

		$this->assertSame( array( Event_Search_Block::class, 'render_block' ), $block->render_callback );
	}

	/**
	 * キーワードは題・本文・会場名に当たる。
	 */
	public function test_keyword_matches_title_content_and_venue() {
		$this->event( '秋の音楽祭' );
		$this->event( '本文で当たる', '+3 days', array(), array( 'post_content' => '音楽の話' ) );
		$this->event(
			'会場で当たる',
			'+3 days',
			array( Event_Meta::VENUE_NAME => '音楽ホール' )
		);
		$this->event( '当たらない' );

		$this->assertSame( array( '秋の音楽祭', '本文で当たる', '会場で当たる' ), $this->titles( array( Event_Search::PARAM_KEYWORD => '音楽' ) ) );
	}

	/**
	 * 語はすべて含むものに当たり、全角の空白でも分かれる。
	 */
	public function test_keyword_terms_are_all_required_and_split_on_full_width_space() {
		$this->event( '春の音楽祭' );
		$this->event( '秋の音楽祭' );

		$this->assertSame( array( '秋の音楽祭' ), $this->titles( array( Event_Search::PARAM_KEYWORD => '音楽　秋' ) ) );
		$this->assertSame( array( '秋の音楽祭' ), $this->titles( array( Event_Search::PARAM_KEYWORD => '音楽 秋' ) ) );
	}

	/**
	 * LIKE の記号と引用符は文字として扱う。
	 */
	public function test_keyword_wildcards_are_literal() {
		$this->event( '割引50%の日' );
		$this->event( 'a_b の会' );
		$this->event( "It's a party" );
		$this->event( 'aXb の会' );

		$this->assertSame( array( '割引50%の日' ), $this->titles( array( Event_Search::PARAM_KEYWORD => '50%' ) ) );
		$this->assertSame( array( 'a_b の会' ), $this->titles( array( Event_Search::PARAM_KEYWORD => 'a_b' ) ) );
		$this->assertCount( 1, $this->titles( array( Event_Search::PARAM_KEYWORD => "It's" ) ) );
	}

	/**
	 * ログイン中でも、出さないイベントにはキーワードが当たらない。
	 */
	public function test_keyword_does_not_reach_events_that_must_not_be_listed() {
		$venue = array( Event_Meta::VENUE_NAME => '秘密の会場' );
		$this->event( '秘密 公開', '+3 days', $venue );
		$this->event( '秘密 パスワード', '+3 days', $venue, array( 'post_password' => 'x' ) );
		$this->event( '秘密 下書き', '+3 days', $venue, array( 'post_status' => 'draft' ) );
		$this->event( '秘密 非公開', '+3 days', $venue, array( 'post_status' => 'private' ) );
		$this->event( '秘密 終了', '-3 days', $venue );
		self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '秘密 日程なし',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array( '秘密 公開' ), $this->titles( array( Event_Search::PARAM_KEYWORD => '秘密' ) ) );
	}

	/**
	 * キーワードの無いクエリの WHERE を変えない。
	 */
	public function test_keyword_filter_does_not_touch_other_queries() {
		$this->event( '音楽祭' );
		$this->event( '別の会' );

		$query = new \WP_Query(
			array(
				'post_type'      => Post_Type::POST_TYPE,
				'posts_per_page' => -1,
			)
		);

		$this->assertCount( 2, $query->posts );
		$this->assertSame( ' AND 1', Event_List::filter_where( ' AND 1', $query ) );
	}

	/**
	 * 都道府県と期間で絞れ、組み合わせられる。
	 */
	public function test_filters_by_prefecture_period_and_combination() {
		$this->event( '東京', '+3 days', array( Event_Meta::PREFECTURE => 13 ) );
		$this->event( '大阪', '+3 days', array( Event_Meta::PREFECTURE => 27 ) );
		$this->event( '東京の先', '+30 days', array( Event_Meta::PREFECTURE => 13 ) );

		$this->assertSame( array( '東京', '東京の先' ), $this->titles( array( Event_Search::PARAM_PREFECTURE => '13' ) ) );

		$date = $this->now->modify( '+29 days' )->format( 'Y-m-d' );
		$this->assertSame( array( '東京の先' ), $this->titles( array( Event_Search::PARAM_FROM => $date ) ) );
		$this->assertSame( array( '東京', '大阪' ), $this->titles( array( Event_Search::PARAM_TO => $date ) ) );
		$this->assertSame(
			array( '東京' ),
			$this->titles(
				array(
					Event_Search::PARAM_PREFECTURE => '13',
					Event_Search::PARAM_TO         => $date,
				)
			)
		);
	}

	/**
	 * 語のあるサイトは語で絞る。
	 */
	public function test_category_uses_site_terms_when_they_exist() {
		$term = self::factory()->term->create(
			array(
				'taxonomy' => Post_Type::TAX_CATEGORY,
				'name'     => 'A&B',
			)
		);
		$hit  = $this->event( '語あり', '+3 days', array( Event_Meta::CATEGORY => 5 ) );
		$this->event( '語なし', '+3 days', array( Event_Meta::CATEGORY => 5 ) );
		wp_set_object_terms( $hit, array( $term ), Post_Type::TAX_CATEGORY );

		$this->assertSame( array( $term ), array_keys( Event_Search::category_options() ) );
		$this->assertSame( array( '語あり' ), $this->titles( array( Event_Search::PARAM_CATEGORY => (string) $term ) ) );
		// EventWatch のカテゴリの値は、語のあるサイトでは実在しない条件になる。
		$this->assertCount( 2, $this->titles( array( Event_Search::PARAM_CATEGORY => '5' ) ) );
	}

	/**
	 * 語の無いサイトは EventWatch の固定のカテゴリで絞る。
	 */
	public function test_category_falls_back_to_eventwatch_values_without_terms() {
		$this->event( 'お祭り', '+3 days', array( Event_Meta::CATEGORY => 5 ) );
		$this->event( 'セミナー', '+3 days', array( Event_Meta::CATEGORY => 3 ) );
		$this->event( '終了した展覧', '-3 days', array( Event_Meta::CATEGORY => 7 ) );

		$this->assertSame( array( 3, 5 ), array_keys( Event_Search::category_options() ) );
		$this->assertSame( array( 3, 5, 7 ), array_keys( Event_Search::category_options( 7 ) ) );
		$this->assertSame( array( 'お祭り' ), $this->titles( array( Event_Search::PARAM_CATEGORY => '5' ) ) );
	}

	/**
	 * 都道府県の選択肢は開催予定のものと選択中のもの。
	 */
	public function test_prefecture_options_are_upcoming_only_and_keep_the_selected() {
		$this->event( '大阪', '+3 days', array( Event_Meta::PREFECTURE => 27 ) );
		$this->event( '東京', '+3 days', array( Event_Meta::PREFECTURE => 13 ) );
		$this->event( '北海道の終了', '-3 days', array( Event_Meta::PREFECTURE => 1 ) );
		$this->event( '沖縄の下書き', '+3 days', array( Event_Meta::PREFECTURE => 47 ), array( 'post_status' => 'draft' ) );
		$this->event( '福岡のパスワード', '+3 days', array( Event_Meta::PREFECTURE => 40 ), array( 'post_password' => 'x' ) );
		$this->event( 'オンライン', '+3 days', array( Event_Meta::PREFECTURE => 0 ) );

		$this->assertSame( array( 13, 27 ), array_keys( Event_Search::prefecture_options() ) );
		$this->assertSame( array( 1, 13, 27 ), array_keys( Event_Search::prefecture_options( 1 ) ) );
	}

	/**
	 * 不正な値は無かったものとして扱う。
	 */
	public function test_conditions_reject_invalid_values() {
		$_GET = array(
			Event_Search::PARAM_KEYWORD    => array( 'x' ),
			Event_Search::PARAM_FROM       => '2026-02-30',
			Event_Search::PARAM_TO         => 'tomorrow',
			Event_Search::PARAM_CATEGORY   => '99999',
			Event_Search::PARAM_PREFECTURE => '48',
		);

		$this->assertSame(
			array(
				'keyword'    => '',
				'from'       => '',
				'to'         => '',
				'category'   => 0,
				'prefecture' => 0,
			),
			Event_Search::conditions()
		);
	}

	/**
	 * 始まりが終わりより後なら入れ替える。
	 */
	public function test_conditions_swap_reversed_period() {
		$_GET = array(
			Event_Search::PARAM_FROM => '2026-12-31',
			Event_Search::PARAM_TO   => '2026-12-01',
		);

		$conditions = Event_Search::conditions();

		$this->assertSame( '2026-12-01', $conditions['from'] );
		$this->assertSame( '2026-12-31', $conditions['to'] );
	}

	/**
	 * キーワードの長さと語の数を打ち切る。
	 */
	public function test_keyword_is_limited() {
		$_GET = array( Event_Search::PARAM_KEYWORD => str_repeat( 'あ', 150 ) );
		$this->assertSame( 100, mb_strlen( Event_Search::conditions()['keyword'] ) );

		$this->assertCount( 8, Event_Search::keyword_terms( 'a b c d e f g h i j' ) );
		$this->assertSame( array(), Event_Search::keyword_terms( '　 ' ) );
	}

	/**
	 * 件数はページをまたいだ全件を数える。
	 */
	public function test_status_counts_all_pages() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->event( 'イベント' . $i );
		}

		$html = $this->render( array( 'perPage' => 2 ) );

		$this->assertMatchesRegularExpression( '#role="status"[^>]*>3 events</p>#', $html );
		$this->assertSame( 2, substr_count( $html, 'eventwatch-events__item"' ) );
	}

	/**
	 * 条件なしの 0 件にヒントとクリアを出さない。
	 */
	public function test_empty_without_conditions_has_no_clear_in_the_results() {
		$html = $this->render();

		$this->assertStringContainsString( 'No upcoming events yet.', $html );
		$this->assertStringNotContainsString( 'eventwatch-search__hint', $html );
		$this->assertSame( 1, substr_count( $html, 'eventwatch-search__clear' ) );
	}

	/**
	 * 条件ありの 0 件はヒントとクリアを出し、クリアはほかのクエリを保つ。
	 */
	public function test_empty_with_conditions_offers_clear() {
		$this->event( '音楽祭' );
		$_GET                   = array( Event_Search::PARAM_KEYWORD => 'オペラ' );
		$_SERVER['REQUEST_URI'] = '/search/?eventwatch_q=x&eventwatch_page=2&keep=1';

		$html = $this->render();

		$this->assertStringContainsString( 'No events match your search.', $html );
		$this->assertStringContainsString( 'eventwatch-search__hint', $html );
		$this->assertSame( 2, substr_count( $html, 'eventwatch-search__clear" href="/search/?keep=1"' ) );

		$_SERVER['REQUEST_URI'] = '/search/?a=1&b=2';
		$this->reset_instances();
		$this->assertSame( 2, substr_count( $this->render(), 'eventwatch-search__clear" href="/search/?a=1&#038;b=2"' ) );
	}

	/**
	 * キーワードの 0 も条件なので、当たらなければクリアを出す。
	 */
	public function test_keyword_zero_is_a_condition() {
		$this->event( '音楽祭', '+3 days', array(), array( 'post_content' => '本文' ) );
		$_GET = array( Event_Search::PARAM_KEYWORD => '0' );

		$html = $this->render();

		$this->assertStringContainsString( 'No events match your search.', $html );
		$this->assertStringContainsString( 'value="0"', $html );
	}

	/**
	 * `//` で始まる要求でも、送信先とリンクが別のサイトを指さない。
	 */
	public function test_links_stay_on_the_site_for_protocol_relative_requests() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->event( '音楽' . $i );
		}
		foreach ( array( '//evil.example/?keep=1', '///evil.example/?keep=1', '/\/evil.example/?keep=1' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->reset_instances();

			$html = $this->render( array( 'perPage' => 2 ) );

			$this->assertStringNotContainsString( 'action="//', $html, $uri );
			$this->assertStringNotContainsString( 'href="//', $html, $uri );
			$this->assertStringNotContainsString( 'href="/\\', $html, $uri );
		}
	}

	/**
	 * 差し替えで欄を揃えるために、select が今の条件を value に持つ。
	 */
	public function test_selects_carry_the_current_condition_as_value() {
		$this->event( '東京', '+3 days', array( Event_Meta::PREFECTURE => 13 ) );
		$_GET = array( Event_Search::PARAM_PREFECTURE => '13' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<select[^>]*name="eventwatch_pref" value="13">#', $html );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="eventwatch_cat" value="">#', $html );
	}

	/**
	 * 範囲外のページは 1 ページ目を出す。
	 */
	public function test_page_out_of_range_falls_back_to_the_first() {
		$this->event( '音楽祭' );
		$_GET = array( Event_Search::PARAM_PAGE => '9' );

		$html = $this->render();

		$this->assertStringContainsString( '音楽祭', $html );
		$this->assertStringNotContainsString( 'No upcoming events yet.', $html );
	}

	/**
	 * ページ送りは条件を保ち、結果の頭に着地する。
	 */
	public function test_pagination_keeps_the_conditions() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->event( '音楽' . $i );
		}
		$_GET                   = array( Event_Search::PARAM_KEYWORD => '音楽' );
		$_SERVER['REQUEST_URI'] = '/search/?eventwatch_q=%E9%9F%B3%E6%A5%BD';

		$html = $this->render( array( 'perPage' => 2 ) );

		$this->assertStringContainsString( 'eventwatch_q=%E9%9F%B3%E6%A5%BD', $html );
		$this->assertMatchesRegularExpression( '#eventwatch_page=2[^"]*\#eventwatch-search-results"#', $html );
	}

	/**
	 * GET で消えるクエリを hidden で送り直す。配列は送らない。
	 */
	public function test_form_keeps_other_query_arguments_as_hidden_fields() {
		$_SERVER['REQUEST_URI'] = '/?page_id=5&eventwatch_q=x&list[]=1';

		$html = $this->render();

		$this->assertStringContainsString( 'action="/"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page_id" value="5" />', $html );
		$this->assertStringNotContainsString( 'name="list"', $html );
	}

	/**
	 * 欄に URL の条件を出す。
	 */
	public function test_form_shows_the_conditions() {
		$this->event( '東京', '+3 days', array( Event_Meta::PREFECTURE => 13 ) );
		$_GET = array(
			Event_Search::PARAM_KEYWORD    => '音楽',
			Event_Search::PARAM_FROM       => '2026-12-01',
			Event_Search::PARAM_PREFECTURE => '13',
		);

		$html = $this->render();

		$this->assertStringContainsString( 'value="音楽"', $html );
		$this->assertStringContainsString( 'value="2026-12-01"', $html );
		$this->assertMatchesRegularExpression( '#<option value="13"\s+selected#', $html );
	}

	/**
	 * テンプレートは渡された値をエスケープする。
	 */
	public function test_form_template_escapes_its_values() {
		$xss = '"><script>alert(1)</script>';
		ob_start();
		load_template(
			dirname( __DIR__, 2 ) . '/templates/event-search-form.php',
			false,
			array(
				'conditions'  => array(
					'keyword'    => $xss,
					'from'       => $xss,
					'to'         => $xss,
					'category'   => 0,
					'prefecture' => 0,
				),
				'categories'  => array( 1 => $xss ),
				'prefectures' => array( 13 => $xss ),
				'action'      => '/search/"><script>alert(1)</script>',
				'hidden'      => array( $xss => $xss ),
				'clear_url'   => '/search/"><script>alert(1)</script>',
				'suffix'      => $xss,
				'interactive' => true,
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * URL の値がそのまま HTML に出ない。
	 */
	public function test_url_values_are_escaped_in_the_output() {
		$_GET                   = array( Event_Search::PARAM_KEYWORD => '"><img src=x>' );
		$_SERVER['REQUEST_URI'] = '/search/?a="><script>alert(1)</script>&b=%22%3E%3Cscript%3E';

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	/**
	 * 同じページの 2 つ目は Router を使わず、id も重ならない。
	 */
	public function test_only_the_first_block_on_a_page_uses_the_router() {
		$first  = $this->render();
		$second = $this->render();

		$this->assertStringContainsString( 'data-wp-router-region="eventwatch-event-search"', $first );
		$this->assertStringContainsString( 'data-wp-on--submit', $first );
		$this->assertStringNotContainsString( 'data-wp-router-region', $second );
		$this->assertStringNotContainsString( 'data-wp-on', $second );
		$this->assertStringContainsString( 'id="eventwatch-search-results-2"', $second );
		$this->assertStringContainsString( 'id="eventwatch-search-keyword-2"', $second );
	}

	/**
	 * ショートコードはブロックと同じ出力になる。
	 */
	public function test_shortcode_matches_the_block() {
		$this->event( '音楽祭' );

		$block = $this->render( array( 'layout' => 'list' ) );
		$this->reset_instances();
		$shortcode = do_shortcode( '[' . Event_Search_Block::SHORTCODE . ' layout="list"]' );

		$this->assertSame( $block, $shortcode );
		$this->assertStringContainsString( 'is-layout-list', $shortcode );

		$this->event( '別の会' );
		$this->reset_instances();
		$this->assertSame( 1, substr_count( do_shortcode( '[' . Event_Search_Block::SHORTCODE . ' per_page="1"]' ), 'eventwatch-events__item"' ) );
	}

	/**
	 * ブロックのサポートが外側の要素に届き、一覧の CSS のクラスも付く。
	 */
	public function test_block_supports_reach_the_wrapper() {
		$html = $this->render( array( 'backgroundColor' => 'contrast' ) );

		$this->assertMatchesRegularExpression( '#class="[^"]*wp-block-eventwatch-event-search[^"]*has-contrast-background-color#', $html );
		$this->assertStringContainsString( 'wp-block-eventwatch-event-list', $html );
	}

	/**
	 * 結果はテーマの差し替えのテンプレートで描く。
	 */
	public function test_results_use_the_theme_template() {
		$this->event( '音楽祭' );
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();
		switch_theme( 'eventwatch-override' );

		$html = $this->render();

		switch_theme( WP_DEFAULT_THEME );
		$this->assertStringContainsString( 'override-card', $html );
	}

	/**
	 * 名前付きの import に畳まれると、withSyncEvent の無い WordPress 6.7 でモジュールごと読めなくなる。
	 */
	public function test_view_module_does_not_import_names_missing_from_the_minimum_version() {
		$view = (string) file_get_contents( dirname( __DIR__, 2 ) . '/build/blocks/event-search/view.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ビルドの結果を読むだけ。

		$this->assertStringContainsString( 'withSyncEvent', $view );
		$this->assertDoesNotMatchRegularExpression( '/import\s*\{[^}]*withSyncEvent/', $view );
	}

	/**
	 * 描画でサーバーの外へ通信しない。
	 */
	public function test_rendering_makes_no_http_request() {
		$requests = 0;
		$watch    = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $watch );
		$this->event( '音楽祭' );

		$this->render();

		remove_filter( 'pre_http_request', $watch );
		$this->assertSame( 0, $requests );
	}
}
