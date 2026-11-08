<?php
/**
 * イベント一覧の取得と描画のテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Event_List;
use EventWatch\Event_List_Block;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use WP_UnitTestCase;

/**
 * Event_List と Event_List_Block のテスト。
 */
class Event_List_Test extends WP_UnitTestCase {

	/**
	 * テストの中の「今」。作るときと確かめるときで時計を読み直すと、間で分が変わって食い違う。
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
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( Event_List_Block::NAME ) ) {
			register_block_type( dirname( __DIR__, 2 ) . '/build/blocks/event-list' );
		}
	}

	/**
	 * サイトのタイムゾーンの壁時計の文字列。
	 *
	 * @param string $modify DateTime の相対の指定。
	 * @return string
	 */
	private function wall( $modify ) {
		return $this->now->modify( $modify )->format( 'Y-m-d H:i' );
	}

	/**
	 * イベントを作る。
	 *
	 * @param string $title イベント名。
	 * @param array  $rows  日程。
	 * @param array  $post  投稿の値。
	 * @param array  $meta  メタ。
	 * @return int
	 */
	private function event( $title, array $rows, array $post = array(), array $meta = array() ) {
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
		if ( $rows ) {
			update_post_meta( $post_id, Event_Meta::OCCURRENCES, $rows );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * 未来の 1 組の日程。
	 *
	 * @param string $modify 相対の指定。
	 * @return array
	 */
	private function future( $modify = '+3 days' ) {
		return array(
			array(
				'start' => $this->wall( $modify ),
				'end'   => '',
			),
		);
	}

	/**
	 * 属性で引いた結果のイベント名。
	 *
	 * @param array $attributes 属性。
	 * @param int   $page       ページ。
	 * @return string[]
	 */
	private function titles( array $attributes = array(), $page = 1 ) {
		return wp_list_pluck( Event_List::query( Event_List::normalize( $attributes ), $page )->posts, 'post_title' );
	}

	/**
	 * 公開で開催予定のものだけが出る。ログイン中の管理者でも変わらない。
	 */
	public function test_only_published_upcoming_events_are_listed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->event( 'shown', $this->future() );
		$this->event( 'draft', $this->future(), array( 'post_status' => 'draft' ) );
		$this->event( 'private', $this->future(), array( 'post_status' => 'private' ) );
		$this->event(
			'scheduled',
			$this->future(),
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->event( 'password', $this->future(), array( 'post_password' => 'secret' ) );
		$this->event( 'no dates', array() );
		$this->event( 'ended', $this->future( '-2 days' ) );

		$this->assertSame( array( 'shown' ), $this->titles() );
	}

	/**
	 * 終了の当日は出て、前日は出ない。
	 */
	public function test_the_boundary_is_the_start_of_today_in_the_site_timezone() {
		$today     = new \DateTimeImmutable( 'today', wp_timezone() );
		$yesterday = $today->modify( '-1 minute' )->format( 'Y-m-d H:i' );

		$this->event(
			'today at midnight',
			array(
				array(
					'start' => $today->format( 'Y-m-d H:i' ),
					'end'   => '',
				),
			)
		);
		$this->event(
			'one minute before today',
			array(
				array(
					'start' => $yesterday,
					'end'   => '',
				),
			)
		);

		$this->assertSame( array( 'today at midnight' ), $this->titles() );
	}

	/**
	 * 並びは最初の開始で、昇順と降順を選べる。
	 */
	public function test_order() {
		$this->event( 'later', $this->future( '+5 days' ) );
		$this->event( 'sooner', $this->future( '+2 days' ) );

		$this->assertSame( array( 'sooner', 'later' ), $this->titles() );
		$this->assertSame( array( 'later', 'sooner' ), $this->titles( array( 'order' => 'desc' ) ) );
	}

	/**
	 * 同じ開始のイベントは ID で並び、ページをまたいで重ならない。
	 */
	public function test_same_start_is_stable_across_pages() {
		$rows = $this->future();
		$this->event( 'a', $rows );
		$this->event( 'b', $rows );
		$this->event( 'c', $rows );

		$this->assertSame( array( 'a', 'b' ), $this->titles( array( 'perPage' => 2 ) ) );
		$this->assertSame( array( 'c' ), $this->titles( array( 'perPage' => 2 ), 2 ) );
	}

	/**
	 * 過ぎた回を持つ定期のイベント。過ぎた回・7 日後・14 日後・21 日後。
	 *
	 * @param string $title イベント名。
	 * @return int
	 */
	private function series_with_a_past_date( $title ) {
		return $this->event(
			$title,
			array_map(
				function ( $modify ) {
					return array(
						'start' => $this->wall( $modify ),
						'end'   => '',
					);
				},
				array( '-6 days', '+7 days', '+14 days', '+21 days' )
			)
		);
	}

	/**
	 * 期間の指定が無くても、並びはカードに出す回（これから開催する最初の回）の開始で決まる。過ぎた回では決まらない。
	 */
	public function test_order_uses_the_shown_occurrence_not_a_past_one() {
		$this->series_with_a_past_date( 'series' );
		$this->event( 'tomorrow', $this->future( '+1 day' ) );
		$this->event( 'in two days', $this->future( '+2 days' ) );

		$this->assertSame( array( 'tomorrow', 'in two days', 'series' ), $this->titles() );
		$this->assertSame( array( 'series', 'in two days', 'tomorrow' ), $this->titles( array( 'order' => 'desc' ) ) );
	}

	/**
	 * ページ送りをまたいでも並びが崩れない。属性は「開催予定と終了したイベント」のパターンの開催予定と同じ（期間なし・1 ページ 2 件）。
	 */
	public function test_order_with_a_past_date_holds_across_pages() {
		$this->series_with_a_past_date( 'series' );
		$this->event( 'tomorrow', $this->future( '+1 day' ) );
		$this->event( 'in two days', $this->future( '+2 days' ) );

		$this->assertSame( array( 'tomorrow', 'in two days' ), $this->titles( array( 'perPage' => 2 ) ) );
		$this->assertSame( array( 'series' ), $this->titles( array( 'perPage' => 2 ), 2 ) );
	}

	/**
	 * 始まりだけを指定すると、始まりより後の最初の回で並ぶ。ショートコードの from でも同じ。
	 */
	public function test_order_with_only_a_start_date_uses_the_first_occurrence_after_it() {
		$this->series_with_a_past_date( 'Weekly series' );
		$this->event( 'Single show', $this->future( '+18 days' ) );
		$from = $this->now->modify( '+15 days' )->format( 'Y-m-d' );

		$this->assertSame( array( 'Single show', 'Weekly series' ), $this->titles( array( 'dateFrom' => $from ) ) );

		$html = do_shortcode( '[eventwatch_events from="' . $from . '"]' );
		$this->assertStringContainsString( 'datetime="' . $this->now->modify( '+21 days' )->format( 'Y-m-d' ), $html );
		$this->assertLessThan( strpos( $html, 'Weekly series' ), strpos( $html, 'Single show' ) );
	}

	/**
	 * 固定の期間でも、期間に入る回の無いイベントは出さない。最初の開始と最後の終了の間に期間があるだけでは当たらない。
	 */
	public function test_fixed_period_skips_events_with_no_date_inside_it() {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$this->event(
			'around the period',
			array(
				array(
					'start' => $today->modify( '+5 days' )->format( 'Y-m-d' ) . ' 10:00',
					'end'   => '',
				),
				array(
					'start' => $today->modify( '+40 days' )->format( 'Y-m-d' ) . ' 10:00',
					'end'   => '',
				),
			)
		);
		$this->event( 'inside', $this->future( '+12 days' ) );

		$this->assertSame(
			array( 'inside' ),
			$this->titles(
				array(
					'dateFrom' => $today->modify( '+10 days' )->format( 'Y-m-d' ),
					'dateTo'   => $today->modify( '+20 days' )->format( 'Y-m-d' ),
				)
			)
		);
	}

	/**
	 * カテゴリ・タグ・都道府県で絞れる。
	 */
	public function test_filters_by_terms_and_prefecture() {
		$category = self::factory()->term->create( array( 'taxonomy' => Post_Type::TAX_CATEGORY ) );
		$tag      = self::factory()->term->create( array( 'taxonomy' => Post_Type::TAX_TAG ) );

		$in_category = $this->event( 'in category', $this->future() );
		wp_set_object_terms( $in_category, $category, Post_Type::TAX_CATEGORY );
		$tagged = $this->event( 'tagged', $this->future() );
		wp_set_object_terms( $tagged, $tag, Post_Type::TAX_TAG );
		$this->event( 'tokyo', $this->future(), array(), array( Event_Meta::PREFECTURE => 13 ) );

		$this->assertSame( array( 'in category' ), $this->titles( array( 'categoryId' => $category ) ) );
		$this->assertSame( array( 'tagged' ), $this->titles( array( 'tagId' => $tag ) ) );
		$this->assertSame( array( 'tokyo' ), $this->titles( array( 'prefecture' => 13 ) ) );
	}

	/**
	 * 期間は、日程が期間に重なるイベントを出す。両端の日を含む。
	 */
	public function test_filters_by_period() {
		$day = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+10 days' );

		$this->event(
			'on the day',
			array(
				array(
					'start' => $day->format( 'Y-m-d' ) . ' 23:59',
					'end'   => '',
				),
			)
		);
		$this->event(
			'next day',
			array(
				array(
					'start' => $day->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00',
					'end'   => '',
				),
			)
		);
		$this->event(
			'previous day',
			array(
				array(
					'start' => $day->modify( '-1 day' )->format( 'Y-m-d' ) . ' 23:59',
					'end'   => '',
				),
			)
		);

		$this->assertSame(
			array( 'on the day' ),
			$this->titles(
				array(
					'dateFrom' => $day->format( 'Y-m-d' ),
					'dateTo'   => $day->format( 'Y-m-d' ),
				)
			)
		);
	}

	/**
	 * 期間で絞ると、カードの日付は期間に入る最初の組になる。
	 */
	public function test_period_start_selects_the_shown_occurrence() {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$first = $today->modify( '+5 days' );
		$later = $today->modify( '+40 days' );
		$this->event(
			'series',
			array(
				array(
					'start' => $first->format( 'Y-m-d' ) . ' 10:00',
					'end'   => '',
				),
				array(
					'start' => $later->format( 'Y-m-d' ) . ' 10:00',
					'end'   => '',
				),
			)
		);

		$html = $this->render_list( array( 'dateFrom' => $today->modify( '+30 days' )->format( 'Y-m-d' ) ) );

		$this->assertStringContainsString( 'datetime="' . $later->format( 'Y-m-d' ) . 'T10:00', $html );
		$this->assertStringNotContainsString( 'datetime="' . $first->format( 'Y-m-d' ), $html );
	}

	/**
	 * 属性を描画に使える値にそろえる。
	 */
	public function test_normalize_rejects_invalid_values() {
		$result = Event_List::normalize(
			array(
				'layout'     => 'grid',
				'perPage'    => 500,
				'order'      => 'random',
				'categoryId' => -3,
				'prefecture' => 48,
				'dateFrom'   => '2026-02-30',
				'dateTo'     => "2026-12-01' OR 1=1",
				'emptyText'  => '<script>alert(1)</script>none',
			)
		);

		$this->assertSame( 'cards', $result['layout'] );
		$this->assertSame( 24, $result['perPage'] );
		$this->assertSame( 'asc', $result['order'] );
		$this->assertSame( 3, $result['categoryId'] );
		$this->assertSame( 0, $result['prefecture'] );
		$this->assertSame( '', $result['dateFrom'] );
		$this->assertSame( '', $result['dateTo'] );
		$this->assertSame( 'none', $result['emptyText'] );
		$this->assertSame( 1, Event_List::normalize( array( 'perPage' => -1 ) )['perPage'] );
		$this->assertSame( 6, Event_List::normalize( array( 'perPage' => array( 1 ) ) )['perPage'] );
	}

	/**
	 * 日時の文は、まだ終わっていない最初の組と、その後の数を出す。
	 */
	public function test_item_shows_the_next_occurrence_and_the_rest() {
		$post_id = $this->event(
			'series',
			array(
				array(
					'start' => $this->wall( '-10 days' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+2 days' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+4 days' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+6 days' ),
					'end'   => '',
				),
			)
		);

		$data = Event_List::item_data( get_post( $post_id ) );

		$next = $this->now->modify( '+2 days' );
		$this->assertStringContainsString( $next->format( 'g:i A' ), $data['date'] );
		$this->assertStringStartsWith( $next->format( 'Y-m-d\TH:i' ), $data['datetime'] );
		$this->assertSame( '+2 more dates', $data['more'] );
	}

	/**
	 * 1 組なら「ほか」を出さない。
	 */
	public function test_item_single_occurrence_has_no_more() {
		$data = Event_List::item_data( get_post( $this->event( 'one', $this->future() ) ) );

		$this->assertSame( '', $data['more'] );
	}

	/**
	 * 同じ日に終わる組は開始だけ、日をまたぐ組は終わりの日まで出す。
	 */
	public function test_item_range_only_when_the_occurrence_spans_days() {
		$start = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+3 days' );

		$same_day = $this->event(
			'same day',
			array(
				array(
					'start' => $start->format( 'Y-m-d' ) . ' 10:00',
					'end'   => $start->format( 'Y-m-d' ) . ' 17:00',
				),
			)
		);
		$spanning = $this->event(
			'spanning',
			array(
				array(
					'start' => $start->format( 'Y-m-d' ) . ' 10:00',
					'end'   => $start->modify( '+1 day' )->format( 'Y-m-d' ) . ' 17:00',
				),
			)
		);

		$this->assertStringNotContainsString( '–', Event_List::item_data( get_post( $same_day ) )['date'] );
		$this->assertStringNotContainsString( '5:00 PM', Event_List::item_data( get_post( $same_day ) )['date'] );

		$text = Event_List::item_data( get_post( $spanning ) )['date'];
		$this->assertStringContainsString( '–', $text );
		$this->assertStringContainsString( $start->modify( '+1 day' )->format( 'M j' ), $text );
		$this->assertStringNotContainsString( '5:00 PM', $text );
	}

	/**
	 * サイトのタイムゾーンを変えても、イベントの壁時計の時刻のまま出す。
	 */
	public function test_item_keeps_the_wall_clock_after_the_site_timezone_changes() {
		$day     = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+5 days' )->format( 'Y-m-d' );
		$post_id = $this->event(
			'zoned',
			array(
				array(
					'start' => $day . ' 13:30',
					'end'   => '',
				),
			)
		);

		update_option( 'timezone_string', 'America/New_York' );

		$data = Event_List::item_data( get_post( $post_id ) );
		$this->assertStringContainsString( '1:30 PM', $data['date'] );
		$this->assertStringStartsWith( $day . 'T13:30:00+09:00', $data['datetime'] );
	}

	/**
	 * 終了が空の組も表示できる。
	 */
	public function test_item_with_empty_end() {
		$data = Event_List::item_data( get_post( $this->event( 'open ended', $this->future() ) ) );

		$this->assertNotSame( '', $data['date'] );
		$this->assertStringNotContainsString( '–', $data['date'] );
	}

	/**
	 * 会場: オンラインが優先で、空なら未定の文。
	 */
	public function test_item_venue_text() {
		$venue  = $this->event( 'venue', $this->future(), array(), array( Event_Meta::VENUE_NAME => 'Hall' ) );
		$online = $this->event(
			'online',
			$this->future(),
			array(),
			array(
				Event_Meta::VENUE_NAME => 'Hall',
				Event_Meta::ONLINE     => true,
			)
		);
		$none   = $this->event( 'none', $this->future() );

		$this->assertSame( 'Hall', Event_List::item_data( get_post( $venue ) )['venue'] );
		$this->assertSame( 'Online', Event_List::item_data( get_post( $online ) )['venue'] );
		$this->assertSame( 'Venue to be announced', Event_List::item_data( get_post( $none ) )['venue'] );
	}

	/**
	 * カテゴリ: サイトのカテゴリが先で、無ければ EventWatch のカテゴリ、どちらも無ければ空。
	 */
	public function test_item_category_text() {
		$term = self::factory()->term->create(
			array(
				'taxonomy' => Post_Type::TAX_CATEGORY,
				'name'     => 'Concerts',
			)
		);

		$both = $this->event( 'both', $this->future(), array(), array( Event_Meta::CATEGORY => 1 ) );
		wp_set_object_terms( $both, $term, Post_Type::TAX_CATEGORY );
		$fixed = $this->event( 'fixed', $this->future(), array(), array( Event_Meta::CATEGORY => 1 ) );
		$none  = $this->event( 'none', $this->future() );

		$this->assertSame( 'Concerts', Event_List::item_data( get_post( $both ) )['category'] );
		$this->assertSame( 'Competitions & contests', Event_List::item_data( get_post( $fixed ) )['category'] );
		$this->assertSame( '', Event_List::item_data( get_post( $none ) )['category'] );
	}

	/**
	 * 画像は遅延読み込みで、代替テキストはメディアのもの、空ならイベント名。無ければ枠ごと出さない。
	 */
	public function test_item_image() {
		$with_alt    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$without_alt = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $with_alt, '_wp_attachment_image_alt', 'A hall' );

		$first  = $this->event( 'first', $this->future() );
		$second = $this->event( 'second', $this->future() );
		$third  = $this->event( 'third', $this->future() );
		set_post_thumbnail( $first, $with_alt );
		set_post_thumbnail( $second, $without_alt );

		$image = Event_List::item_data( get_post( $first ) )['image'];
		$this->assertStringContainsString( 'alt="A hall"', $image );
		$this->assertStringContainsString( 'loading="lazy"', $image );
		$this->assertStringContainsString( 'alt="second"', Event_List::item_data( get_post( $second ) )['image'] );
		$this->assertSame( '', Event_List::item_data( get_post( $third ) )['image'] );

		$this->assertStringNotContainsString( 'eventwatch-event__media', $this->render_only( $third ) );
	}

	/**
	 * 指定したイベントだけが出る条件で描く。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private function render_only( $post_id ) {
		$term = self::factory()->term->create( array( 'taxonomy' => Post_Type::TAX_TAG ) );
		wp_set_object_terms( $post_id, $term, Post_Type::TAX_TAG );
		return $this->render_list( array( 'tagId' => $term ) );
	}

	/**
	 * カードとリストの形を切り替えられる。
	 */
	public function test_render_layouts() {
		$this->event( 'event', $this->future() );

		$cards = render_block( $this->block() );
		$this->assertStringContainsString( 'is-layout-cards', $cards );
		$this->assertStringContainsString( 'eventwatch-event--card', $cards );

		$list = render_block( $this->block( array( 'layout' => 'list' ) ) );
		$this->assertStringContainsString( 'is-layout-list', $list );
		$this->assertStringContainsString( 'eventwatch-event--row', $list );
	}

	/**
	 * ブロックサポートの指定が外側の要素に載る。
	 */
	public function test_block_supports_reach_the_wrapper() {
		$this->event( 'event', $this->future() );

		$html = render_block(
			$this->block(
				array(
					'textColor' => 'accent',
					'fontSize'  => 'small',
					'style'     => array(
						'spacing' => array( 'padding' => array( 'top' => '10px' ) ),
						'border'  => array( 'radius' => '7px' ),
					),
				)
			)
		);

		$this->assertMatchesRegularExpression( '/^<div [^>]*class="[^"]*wp-block-eventwatch-event-list[^"]*has-accent-color/', $html );
		$this->assertMatchesRegularExpression( '/^<div [^>]*style="[^"]*padding-top:10px/', $html );
		$this->assertMatchesRegularExpression( '/^<div [^>]*style="[^"]*border-radius:7px/', $html );
		$this->assertMatchesRegularExpression( '/^<div [^>]*class="[^"]*has-small-font-size/', $html );
	}

	/**
	 * 本番と同じくブロックの描画の中で描く。
	 *
	 * @param array $attrs 属性。
	 * @return string
	 */
	private function render_list( array $attrs ) {
		return render_block( $this->block( $attrs ) );
	}

	/**
	 * ブロックの配列を作る。
	 *
	 * @param array $attrs 属性。
	 * @return array
	 */
	private function block( array $attrs = array() ) {
		return array(
			'blockName'    => Event_List_Block::NAME,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * ショートコードはブロックと同じものを出す。
	 */
	public function test_shortcode_matches_the_block() {
		$this->event( 'event', $this->future() );

		$block     = render_block( $this->block( array( 'layout' => 'list' ) ) );
		$shortcode = do_shortcode( '[eventwatch_events layout="list"]' );

		$this->assertStringContainsString( 'eventwatch-event--row', $shortcode );
		$this->assertStringContainsString( 'wp-block-eventwatch-event-list', $shortcode );
		$this->assertSame( $block, $shortcode );
	}

	/**
	 * ショートコードの絞り込み。誤ったスラッグで全件を出さない。
	 */
	public function test_shortcode_filters() {
		$term = self::factory()->term->create(
			array(
				'taxonomy' => Post_Type::TAX_CATEGORY,
				'slug'     => 'concert',
			)
		);
		$post = $this->event( 'matched', $this->future(), array(), array( Event_Meta::PREFECTURE => 13 ) );
		wp_set_object_terms( $post, $term, Post_Type::TAX_CATEGORY );
		$this->event( 'other', $this->future() );

		$by_slug = do_shortcode( '[eventwatch_events category="concert"]' );
		$this->assertStringContainsString( 'matched', $by_slug );
		$this->assertStringNotContainsString( 'other', $by_slug );

		$by_prefecture = do_shortcode( '[eventwatch_events prefecture="13"]' );
		$this->assertStringContainsString( 'matched', $by_prefecture );
		$this->assertStringNotContainsString( 'other', $by_prefecture );

		$unknown = do_shortcode( '[eventwatch_events category="nothing" empty_text="None here"]' );
		$this->assertStringNotContainsString( 'matched', $unknown );
		$this->assertStringContainsString( 'None here', $unknown );
	}

	/**
	 * 0 件のときは文を出す。既定と、オーナーの文。
	 */
	public function test_empty_text() {
		$this->assertStringContainsString( 'No upcoming events yet.', $this->render_list( array() ) );

		$html = $this->render_list( array( 'emptyText' => 'Nothing <b>yet</b>' ) );
		$this->assertStringContainsString( 'Nothing yet', $html );
		$this->assertStringNotContainsString( 'No upcoming events yet.', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	/**
	 * 値はエスケープされる。
	 */
	public function test_values_are_escaped() {
		$post_id = $this->event(
			'<script>alert(1)</script>Concert',
			$this->future(),
			array(),
			array( Event_Meta::VENUE_NAME => 'Hall "A" <img src=x onerror=alert(2)>' )
		);
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => '<script>alert(1)</script>Concert',
			)
		);

		foreach ( array( 'cards', 'list' ) as $layout ) {
			$html = $this->render_list( array( 'layout' => $layout ) );
			$this->assertStringNotContainsString( '<script', $html );
			$this->assertStringNotContainsString( '<img src=x', $html );
		}
	}

	/**
	 * 同梱のテンプレートは、渡された値をそのまま信じない。テーマや保存の経路が変わっても守る。
	 */
	public function test_templates_escape_their_values() {
		$data = array(
			'id'       => 1,
			'title'    => '<script>alert(1)</script>Concert',
			'url'      => 'javascript:alert(2)',
			'image'    => '',
			'date'     => '<b>date</b>',
			'datetime' => '"><i>',
			'more'     => '<b>more</b>',
			'venue'    => '<img src=x onerror=alert(3)>',
			'category' => '<u>category</u>',
		);

		foreach ( array( 'event-card.php', 'event-row.php' ) as $name ) {
			ob_start();
			load_template( dirname( __DIR__, 2 ) . '/templates/' . $name, false, $data );
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsString( '<script', $html, $name );
			$this->assertStringNotContainsString( 'javascript:', $html, $name );
			$this->assertStringNotContainsString( '<b>', $html, $name );
			$this->assertStringNotContainsString( '<i>', $html, $name );
			$this->assertStringNotContainsString( '<img', $html, $name );
			$this->assertStringNotContainsString( '<u>', $html, $name );
		}
	}

	/**
	 * テンプレートへ渡す題から、危ないタグを落とす。テーマのテンプレートがそのまま出しても安全なように。
	 */
	public function test_item_title_is_sanitized_for_theme_templates() {
		$post_id = $this->event( 'Concert', $this->future() );

		// 保存の経路は権限のある人なら生の HTML を残せるので、読むときの値を差し替えて確かめる。
		$raw = static function () {
			return '<script>alert(1)</script>Concert';
		};
		add_filter( 'the_title', $raw );
		$title = Event_List::item_data( get_post( $post_id ) )['title'];
		remove_filter( 'the_title', $raw );

		$this->assertStringNotContainsString( '<script', $title );
		$this->assertStringContainsString( 'Concert', $title );
	}

	/**
	 * 題にリンクがあっても、カードのリンクの中に入れ子にしない。
	 */
	public function test_item_title_has_no_nested_link() {
		$this->event( '<a href="https://example.test/">Linked</a> title', $this->future() );

		foreach ( array( 'cards', 'list' ) as $layout ) {
			$html = $this->render_list( array( 'layout' => $layout ) );
			$this->assertSame( 1, substr_count( $html, '<a ' ), $layout );
			$this->assertStringContainsString( 'Linked title', $html, $layout );
		}
	}

	/**
	 * 色だけが違うブロックは別の一覧として扱い、同じ id にしない。
	 */
	public function test_blocks_differing_only_in_color_have_their_own_id() {
		$this->event( 'event', $this->future() );

		$plain   = $this->render_list( array() );
		$colored = $this->render_list( array( 'textColor' => 'accent' ) );

		preg_match( '/id="([^"]+)"/', $plain, $first );
		preg_match( '/id="([^"]+)"/', $colored, $second );
		$this->assertNotSame( $first[1], $second[1] );
	}

	/**
	 * 0 件の文は HTML として出さない。
	 */
	public function test_empty_text_is_escaped() {
		$this->assertStringContainsString( 'A &amp; B', $this->render_list( array( 'emptyText' => 'A & B' ) ) );
	}

	/**
	 * テーマのテンプレートでカードの描画を差し替えられる。リストは差し替えていないので、そのまま。
	 */
	public function test_theme_template_replaces_the_card() {
		$this->event( 'event', $this->future() );

		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();
		switch_theme( 'eventwatch-override' );

		$cards = $this->render_list( array() );
		$list  = $this->render_list( array( 'layout' => 'list' ) );

		switch_theme( WP_DEFAULT_THEME );

		$this->assertStringContainsString( 'override-card', $cards );
		$this->assertStringNotContainsString( 'eventwatch-event--card', $cards );
		$this->assertStringContainsString( 'eventwatch-event--row', $list );
	}

	/**
	 * ページ送りは 2 ページ以上のときだけ出て、番号はブロックごとのクエリで読む。
	 */
	public function test_pagination() {
		$this->event( 'a', $this->future( '+1 days' ) );
		$this->event( 'b', $this->future( '+2 days' ) );
		$this->event( 'c', $this->future( '+3 days' ) );

		$this->assertStringNotContainsString( 'eventwatch-events__pagination', $this->render_list( array( 'perPage' => 3 ) ) );

		$html = $this->render_list( array( 'perPage' => 2 ) );
		$this->assertStringContainsString( 'eventwatch-events__pagination', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertMatchesRegularExpression( '/href="[^"]*eventwatch_page_[0-9a-f]{8}=2#eventwatch-events-[0-9a-f]{8}"/', $html );

		preg_match( '/eventwatch_page_[0-9a-f]{8}/', $html, $param );
		$_GET[ $param[0] ] = '2';
		$second            = $this->render_list( array( 'perPage' => 2 ) );
		unset( $_GET[ $param[0] ] );

		$this->assertStringContainsString( '>c<', $second );
		$this->assertStringNotContainsString( '>a<', $second );
	}

	/**
	 * 範囲外のページは「まだありません」と出さず、1 ページ目に戻す。
	 */
	public function test_page_out_of_range_falls_back_to_the_first() {
		$this->event( 'a', $this->future() );

		$html = $this->render_list( array() );
		preg_match( '/id="eventwatch-events-([0-9a-f]{8})"/', $html, $hash );
		$param = 'eventwatch_page_' . $hash[1];

		$_GET[ $param ] = '99';
		$result         = $this->render_list( array() );
		unset( $_GET[ $param ] );

		$this->assertStringContainsString( '>a<', $result );
		$this->assertStringNotContainsString( 'No upcoming events yet.', $result );
	}

	/**
	 * 本文のループの中で呼んでも、今の投稿を動かさない。
	 */
	public function test_rendering_does_not_move_the_current_post() {
		$this->event( 'event', $this->future() );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page ) );
		the_post();
		$this->render_list( array() );

		$this->assertSame( $page, get_the_ID() );
	}

	/**
	 * 描画は EventWatch へ通信しない。
	 */
	public function test_rendering_makes_no_http_request() {
		$this->event( 'event', $this->future() );

		$requests = 0;
		$watch    = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $watch );
		$this->render_list( array() );
		do_shortcode( '[eventwatch_events]' );
		remove_filter( 'pre_http_request', $watch );

		$this->assertSame( 0, $requests );
	}
}
