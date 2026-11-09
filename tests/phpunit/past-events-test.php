<?php
/**
 * 過去のイベント・動く期間・パターンのテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Event_List;
use EventWatch\Event_List_Block;
use EventWatch\Event_Meta;
use EventWatch\Event_Patterns;
use EventWatch\Past_Events_Block;
use EventWatch\Post_Type;
use WP_UnitTestCase;

/**
 * 過去のイベントのブロック、一覧の period、パターンのテスト。
 */
class Past_Events_Test extends WP_UnitTestCase {

	/**
	 * 基底クラスの tear_down がメタとブロックの登録を外すので、テストごとに揃える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		Event_List::reset_rendered();
		$registry = \WP_Block_Type_Registry::get_instance();
		$build    = dirname( __DIR__, 2 ) . '/build/blocks/';
		if ( ! $registry->is_registered( Event_List_Block::NAME ) ) {
			register_block_type( $build . 'event-list' );
		}
		if ( ! $registry->is_registered( Past_Events_Block::NAME ) ) {
			register_block_type( $build . 'past-events' );
		}
	}

	/**
	 * 後始末。
	 */
	public function tear_down() {
		unset( $_GET );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * 今日の 0 時を基準にした壁時計の文字列。
	 *
	 * @param string $modify DateTime の相対の指定。
	 * @return string
	 */
	private function wall( $modify ) {
		return ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( $modify )->format( 'Y-m-d H:i' );
	}

	/**
	 * イベントを作る。
	 *
	 * @param string $title イベント名。
	 * @param array  $rows  日程。
	 * @param array  $post  投稿の値。
	 * @return int
	 */
	private function event( $title, array $rows, array $post = array() ) {
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
		return $post_id;
	}

	/**
	 * 1 組の日程。
	 *
	 * @param string $start 開始の相対の指定。
	 * @param string $end   終了の相対の指定。空なら終了なし。
	 * @return array
	 */
	private function dates( $start, $end = '' ) {
		return array(
			array(
				'start' => $this->wall( $start ),
				'end'   => '' !== $end ? $this->wall( $end ) : '',
			),
		);
	}

	/**
	 * 過去のイベントの名前。
	 *
	 * @param array $attributes 属性。
	 * @param int   $page       ページ。
	 * @return string[]
	 */
	private function past( array $attributes = array(), $page = 1 ) {
		$attributes = array_merge( Event_List::normalize( $attributes ), array( 'scope' => Event_List::SCOPE_PAST ) );

		return wp_list_pluck( Event_List::query( $attributes, $page )->posts, 'post_title' );
	}

	/**
	 * 開催予定のイベントの名前。
	 *
	 * @param array $attributes 属性。
	 * @return string[]
	 */
	private function upcoming( array $attributes = array() ) {
		return wp_list_pluck( Event_List::query( Event_List::normalize( $attributes ), 1 )->posts, 'post_title' );
	}

	/**
	 * ブロックを描く。1 回の呼び出しを 1 回の要求として、一覧の数え直しから始める。
	 *
	 * @param string $name       ブロックの名前。
	 * @param array  $attributes 属性。
	 * @return string
	 */
	private function render( $name, array $attributes = array() ) {
		Event_List::reset_rendered();
		return render_block(
			array(
				'blockName'    => $name,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * 終了の当日はまだ出ない。開催予定と過去の間に隙間も重なりも無い。
	 */
	public function test_boundary_has_no_gap_or_overlap() {
		$this->event( 'yesterday late', $this->dates( '-1 day 10:00', '-1 day 23:59' ) );
		$this->event( 'today midnight', $this->dates( '-1 day 10:00', 'today 00:00' ) );
		$this->event( 'today late', $this->dates( 'today 10:00', 'today 23:59' ) );
		$this->event( 'no end yesterday', $this->dates( '-1 day 23:59' ) );
		$this->event( 'no end today', $this->dates( 'today 00:00' ) );

		$past     = $this->past();
		$upcoming = $this->upcoming();
		sort( $past );
		sort( $upcoming );

		$this->assertSame( array( 'no end yesterday', 'yesterday late' ), $past );
		$this->assertSame( array( 'no end today', 'today late', 'today midnight' ), $upcoming );
	}

	/**
	 * 終了の新しい順。最初の開始の順とは違う。
	 */
	public function test_orders_by_the_last_end_newest_first() {
		$this->event(
			'started first ended last',
			array(
				array(
					'start' => $this->wall( '-40 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '-1 day 10:00' ),
					'end'   => '',
				),
			)
		);
		$this->event( 'started later ended earlier', $this->dates( '-10 days 10:00' ) );
		$this->event( 'oldest', $this->dates( '-20 days 10:00' ) );

		$this->assertSame( array( 'started first ended last', 'started later ended earlier', 'oldest' ), $this->past() );
	}

	/**
	 * 同じ終了なら新しい ID を先にして、ページをまたいでも順が動かない。
	 */
	public function test_same_end_is_stable_across_pages() {
		foreach ( array( 'a', 'b', 'c' ) as $title ) {
			$this->event( $title, $this->dates( '-2 days 10:00' ) );
		}

		$this->assertSame( array( 'c', 'b' ), $this->past( array( 'perPage' => 2 ) ) );
		$this->assertSame( array( 'a' ), $this->past( array( 'perPage' => 2 ), 2 ) );
	}

	/**
	 * 残りの組があるイベントは、最後の組が終わるまで過去に出ない。
	 */
	public function test_event_with_remaining_dates_is_not_past() {
		$this->event(
			'series',
			array(
				array(
					'start' => $this->wall( '-10 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( 'today 10:00' ),
					'end'   => '',
				),
			)
		);

		$this->assertSame( array(), $this->past() );
	}

	/**
	 * 公開でないもの・パスワード保護・日程 0 組は出ない。ログイン中の管理者でも変わらない。
	 */
	public function test_only_published_events_with_dates_are_listed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$rows = $this->dates( '-3 days 10:00' );
		$this->event( 'shown', $rows );
		$this->event( 'draft', $rows, array( 'post_status' => 'draft' ) );
		$this->event( 'private', $rows, array( 'post_status' => 'private' ) );
		$this->event( 'password', $rows, array( 'post_password' => 'secret' ) );
		$this->event( 'no dates', array() );

		$this->assertSame( array( 'shown' ), $this->past( array( 'monthsBack' => 0 ) ) );
	}

	/**
	 * さかのぼる範囲。既定は 1 年で、範囲の端の 0 時ちょうどは入る。
	 */
	public function test_months_back_limits_the_range() {
		$edge = Event_List::months_before( new \DateTimeImmutable( 'today', wp_timezone() ), 3 );
		$this->event(
			'edge',
			array(
				array(
					'start' => $edge->format( 'Y-m-d H:i' ),
					'end'   => '',
				),
			)
		);
		$this->event(
			'just over 3',
			array(
				array(
					'start' => $edge->modify( '-1 minute' )->format( 'Y-m-d H:i' ),
					'end'   => '',
				),
			)
		);
		$this->event( 'over 1 year', $this->dates( '-13 months' ) );
		$this->event( 'over 2 years', $this->dates( '-25 months' ) );

		$this->assertSame( array( 'edge' ), $this->past( array( 'monthsBack' => 3 ) ) );
		$this->assertSame( array( 'edge', 'just over 3' ), $this->past() );
		$this->assertSame( array( 'edge', 'just over 3', 'over 1 year' ), $this->past( array( 'monthsBack' => 24 ) ) );
		$this->assertSame( array( 'edge', 'just over 3', 'over 1 year', 'over 2 years' ), $this->past( array( 'monthsBack' => 0 ) ) );
	}

	/**
	 * N か月前の同じ日が無ければ月末にする。繰り上げると範囲が短くなる。
	 */
	public function test_months_before_clamps_to_the_month_end() {
		$cases = array(
			array( '2026-05-31', 3, '2026-02-28' ),
			array( '2026-08-31', 6, '2026-02-28' ),
			array( '2028-02-29', 12, '2027-02-28' ),
			array( '2026-03-31', 1, '2026-02-28' ),
			array( '2026-01-15', 3, '2025-10-15' ),
			array( '2026-11-02', 24, '2024-11-02' ),
		);
		foreach ( $cases as $case ) {
			$day = new \DateTimeImmutable( $case[0], wp_timezone() );
			$this->assertSame( $case[2], Event_List::months_before( $day, $case[1] )->format( 'Y-m-d' ), $case[0] );
		}
	}

	/**
	 * 選択肢に無い範囲は既定に戻す。
	 */
	public function test_months_back_rejects_other_values() {
		$this->assertSame( 12, Event_List::normalize( array( 'monthsBack' => 7 ) )['monthsBack'] );
		$this->assertSame( 12, Event_List::normalize( array( 'monthsBack' => array( 3 ) ) )['monthsBack'] );
		$this->assertSame( 12, Event_List::normalize( array( 'monthsBack' => 'abc' ) )['monthsBack'] );
		$this->assertSame( 12, Event_List::normalize( array( 'monthsBack' => '' ) )['monthsBack'] );
		$this->assertSame( 12, Event_List::normalize( array() )['monthsBack'] );
		$this->assertSame( 0, Event_List::normalize( array( 'monthsBack' => 0 ) )['monthsBack'] );
		$this->assertSame( 24, Event_List::normalize( array( 'monthsBack' => '24' ) )['monthsBack'] );
	}

	/**
	 * 日付は最後の組で、「ほか」を出さない。印のデータは過去だけ真。
	 */
	public function test_item_data_of_past_event() {
		$last = $this->wall( '-2 days 10:00' );
		$past = $this->event(
			'series',
			array(
				array(
					'start' => $this->wall( '-10 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $last,
					'end'   => '',
				),
			)
		);
		$next = $this->event( 'next', $this->dates( '+2 days 10:00' ) );

		$data = Event_List::item_data( get_post( $past ) );
		$this->assertTrue( $data['ended'] );
		$this->assertSame( '', $data['more'] );
		$this->assertStringStartsWith( substr( $last, 0, 10 ), $data['datetime'] );
		$this->assertFalse( Event_List::item_data( get_post( $next ) )['ended'] );
	}

	/**
	 * 今日の 0 時ちょうどに終わるイベントは、一覧の条件と同じくまだ終了の扱いにしない。
	 */
	public function test_event_ending_at_the_boundary_is_not_ended() {
		$post_id = $this->event( 'edge', $this->dates( 'today 00:00' ) );

		$this->assertFalse( Event_List::item_data( get_post( $post_id ) )['ended'] );
	}

	/**
	 * 日程の無いイベントは終了の扱いにしない。
	 */
	public function test_event_without_dates_is_not_ended() {
		$this->assertFalse( Event_List::item_data( get_post( $this->event( 'none', array() ) ) )['ended'] );
	}

	/**
	 * 描画: 一覧の CSS のクラスを持ち、既定はリストで、印が出る。
	 */
	public function test_block_renders_the_ended_label() {
		$this->event( 'old <b>show</b>', $this->dates( '-2 days 10:00' ) );
		$this->event( 'next', $this->dates( '+2 days 10:00' ) );

		$html = $this->render( Past_Events_Block::NAME );

		$this->assertStringContainsString( 'wp-block-eventwatch-past-events', $html );
		$this->assertStringContainsString( 'wp-block-eventwatch-event-list', $html );
		$this->assertStringContainsString( 'is-layout-list', $html );
		$this->assertStringContainsString( 'eventwatch-event--row', $html );
		$this->assertSame( 1, substr_count( $html, 'eventwatch-event__ended' ) );
		$this->assertStringContainsString( 'is-ended', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringNotContainsString( 'next', $html );
	}

	/**
	 * カードにも同じ印が出る。
	 */
	public function test_cards_show_the_same_label() {
		$this->event( 'old', $this->dates( '-2 days 10:00' ) );

		$html = $this->render( Past_Events_Block::NAME, array( 'layout' => 'cards' ) );

		$this->assertStringContainsString( 'eventwatch-event--card is-ended', $html );
		$this->assertSame( 1, substr_count( $html, 'eventwatch-event__ended' ) );
	}

	/**
	 * 開催予定の一覧には印が出ない。
	 */
	public function test_upcoming_list_has_no_label() {
		$this->event( 'next', $this->dates( '+2 days 10:00' ) );

		$html = $this->render( Event_List_Block::NAME );

		$this->assertStringContainsString( 'next', $html );
		$this->assertStringNotContainsString( 'eventwatch-event__ended', $html );
		$this->assertStringNotContainsString( 'is-ended', $html );
	}

	/**
	 * 投稿の本文の属性で、一覧を過去に変えられない。
	 */
	public function test_attribute_cannot_change_the_scope() {
		$this->event( 'old', $this->dates( '-2 days 10:00' ) );
		$this->event( 'next', $this->dates( '+2 days 10:00' ) );

		$html = $this->render( Event_List_Block::NAME, array( 'scope' => 'past' ) );
		$this->assertStringContainsString( 'next', $html );
		$this->assertStringNotContainsString( '>old<', $html );

		$html = $this->render( Past_Events_Block::NAME, array( 'scope' => 'upcoming' ) );
		$this->assertStringContainsString( '>old<', $html );
		$this->assertStringNotContainsString( '>next<', $html );
	}

	/**
	 * ページ送りは一覧と別の名前で、互いに動かさない。
	 */
	public function test_pagination_is_separate_from_the_list() {
		foreach ( array( 'old1', 'old2', 'old3' ) as $i => $title ) {
			$this->event( $title, $this->dates( '-' . ( $i + 2 ) . ' days 10:00' ) );
			$this->event( 'next' . $i, $this->dates( '+' . ( $i + 2 ) . ' days 10:00' ) );
		}
		$same = array(
			'layout'  => 'list',
			'perPage' => 2,
		);

		$past = $this->render( Past_Events_Block::NAME, $same );
		$list = $this->render( Event_List_Block::NAME, $same );

		$this->assertSame( 1, preg_match( '/(eventwatch_page_[0-9a-f]{8})=2/', $past, $past_param ) );
		$this->assertSame( 1, preg_match( '/(eventwatch_page_[0-9a-f]{8})=2/', $list, $list_param ) );
		$this->assertNotSame( $past_param[1], $list_param[1] );

		$_GET[ $past_param[1] ] = '2';

		$this->assertStringContainsString( '>old3<', $this->render( Past_Events_Block::NAME, $same ) );
		$this->assertStringContainsString( '>next0<', $this->render( Event_List_Block::NAME, $same ) );
	}

	/**
	 * 同じ設定の終了したイベントを 1 ページに 2 つ描くと、id とページの引数が別になる。
	 */
	public function test_same_blocks_on_one_page_get_distinct_ids() {
		foreach ( array( 'old1', 'old2', 'old3' ) as $i => $title ) {
			$this->event( $title, $this->dates( '-' . ( $i + 2 ) . ' days 10:00' ) );
		}
		$attributes = array( 'perPage' => 2 );

		$first  = $this->render( Past_Events_Block::NAME, $attributes );
		$second = render_block(
			array(
				'blockName'    => Past_Events_Block::NAME,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertSame( 1, preg_match( '/id="(eventwatch-events-[0-9a-f]{8})"/', $first, $id ) );
		$this->assertSame( 1, preg_match( '/(eventwatch_page_[0-9a-f]{8})=2/', $first, $param ) );
		$this->assertStringContainsString( 'id="' . $id[1] . '-2"', $second );
		$this->assertStringContainsString( $param[1] . '-2=2#' . $id[1] . '-2"', $second );
	}

	/**
	 * 範囲外のページは 1 ページ目に戻す。
	 */
	public function test_page_out_of_range_falls_back_to_the_first() {
		$this->event( 'old', $this->dates( '-2 days 10:00' ) );
		$this->event( 'older', $this->dates( '-3 days 10:00' ) );
		$this->event( 'oldest', $this->dates( '-4 days 10:00' ) );

		$attributes = array( 'perPage' => 2 );
		preg_match( '/(eventwatch_page_[0-9a-f]{8})=2/', $this->render( Past_Events_Block::NAME, $attributes ), $param );
		$_GET[ $param[1] ] = '9';

		$this->assertStringContainsString( '>old<', $this->render( Past_Events_Block::NAME, $attributes ) );
	}

	/**
	 * 0 件の文。
	 */
	public function test_empty_texts() {
		$this->assertStringContainsString( 'No past events yet.', $this->render( Past_Events_Block::NAME ) );
		$this->assertStringContainsString( 'none here', $this->render( Past_Events_Block::NAME, array( 'emptyText' => 'none here' ) ) );
		$this->assertStringContainsString( 'No upcoming events yet.', $this->render( Event_List_Block::NAME ) );
		$this->assertStringContainsString( 'No events this month.', $this->render( Event_List_Block::NAME, array( 'period' => 'thisMonth' ) ) );
		$this->assertStringContainsString( 'No events in the next 30 days.', $this->render( Event_List_Block::NAME, array( 'period' => 'next30Days' ) ) );
	}

	/**
	 * ショートコードはブロックと同じ描画を通す。
	 */
	public function test_shortcode() {
		$this->event( 'old', $this->dates( '-2 days 10:00' ) );
		$this->event( 'over', $this->dates( '-5 months' ) );

		$html = do_shortcode( '[eventwatch_past_events months="3" empty_text="none"]' );
		$this->assertStringContainsString( 'wp-block-eventwatch-past-events', $html );
		$this->assertStringContainsString( 'is-layout-list', $html );
		$this->assertStringContainsString( '>old<', $html );
		$this->assertStringNotContainsString( '>over<', $html );

		$html = do_shortcode( '[eventwatch_past_events layout="cards" per_page="1"]' );
		$this->assertStringContainsString( 'is-layout-cards', $html );
		$this->assertSame( 1, substr_count( $html, 'eventwatch-events__item"' ) );
	}

	/**
	 * ショートコードの不正な months は、全期間でなく既定の 1 年になる。
	 */
	public function test_shortcode_with_invalid_months_uses_the_default() {
		$this->event( 'over 1 year', $this->dates( '-13 months' ) );
		$this->event( 'old', $this->dates( '-2 days 10:00' ) );

		foreach ( array( 'months=""', 'months="abc"', 'months="7"' ) as $attr ) {
			$html = do_shortcode( '[eventwatch_past_events ' . $attr . ']' );
			$this->assertStringContainsString( '>old<', $html, $attr );
			$this->assertStringNotContainsString( '>over 1 year<', $html, $attr );
		}
		$this->assertStringContainsString( '>over 1 year<', do_shortcode( '[eventwatch_past_events months="0"]' ) );
	}

	/**
	 * データはサイトの中だけから読む。
	 */
	public function test_does_not_call_external_services() {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$calls ) {
				++$calls;
				return $pre;
			}
		);
		$this->event( 'old', $this->dates( '-2 days 10:00' ) );

		$this->render( Past_Events_Block::NAME );
		$this->render( Event_List_Block::NAME, array( 'period' => 'thisMonth' ) );

		$this->assertSame( 0, $calls );
	}

	/**
	 * 今月: 月の初めから末日まで。今日より前に終わったものは開催予定の条件で落ちる。
	 */
	public function test_period_this_month() {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$first = $today->modify( 'first day of this month' )->format( 'Y-m-d' );
		$last  = $today->modify( 'last day of this month' )->format( 'Y-m-d' );

		$this->event( 'last day', array( array( 'start' => $last . ' 23:00', 'end' => '' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- 短いので 1 行で書く。
		$this->event( 'next month', array( array( 'start' => $today->modify( 'first day of next month' )->format( 'Y-m-d' ) . ' 00:00', 'end' => '' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- 短いので 1 行で書く。
		$this->event( 'ended', $this->dates( '-1 day 10:00' ) );

		$result = Event_List::normalize( array( 'period' => 'thisMonth' ) );
		$this->assertSame( $first, $result['dateFrom'] );
		$this->assertSame( $last, $result['dateTo'] );
		$this->assertSame( array( 'last day' ), $this->upcoming( array( 'period' => 'thisMonth' ) ) );
	}

	/**
	 * 近日: 今日から 30 日目までで、31 日目は入らない。
	 */
	public function test_period_next_30_days() {
		$this->event( 'day 30', $this->dates( '+30 days 23:59' ) );
		$this->event( 'day 31', $this->dates( '+31 days 00:00' ) );
		$this->event( 'today', $this->dates( 'today 12:00' ) );

		$this->assertSame( array( 'today', 'day 30' ), $this->upcoming( array( 'period' => 'next30Days' ) ) );
	}

	/**
	 * 期間は組の単位で見る。組と組の間に入るだけのイベントは出さない。
	 */
	public function test_period_skips_events_with_no_date_in_the_period() {
		$rows = static function ( array $starts ) {
			return array_map(
				static function ( $start ) {
					return array(
						'start' => $start,
						'end'   => '',
					);
				},
				$starts
			);
		};

		$this->event( 'between', $rows( array( $this->wall( '-100 days 10:00' ), $this->wall( '+70 days 10:00' ) ) ) );
		$this->event( 'ended this period', $rows( array( $this->wall( '-1 day 10:00' ), $this->wall( '+70 days 10:00' ) ) ) );
		$this->event( 'has one', $rows( array( $this->wall( '-100 days 10:00' ), $this->wall( '+10 days 10:00' ) ) ) );

		$this->assertSame( array( 'has one' ), $this->upcoming( array( 'period' => 'next30Days' ) ) );
		$this->assertNotContains( 'between', $this->upcoming( array( 'period' => 'thisMonth' ) ) );
		$this->assertNotContains( 'ended this period', $this->upcoming( array( 'period' => 'thisMonth' ) ) );

		// 固定の日付も組の単位で見る。
		$this->assertNotContains(
			'between',
			$this->upcoming(
				array(
					'dateFrom' => substr( $this->wall( '+1 day' ), 0, 10 ),
					'dateTo'   => substr( $this->wall( '+2 days' ), 0, 10 ),
				)
			)
		);
	}

	/**
	 * 組の開始が範囲の終わりの翌日 0 時ちょうどなら、範囲に入らない。
	 */
	public function test_period_end_is_exclusive_for_each_date() {
		$this->event(
			'next day',
			array(
				array(
					'start' => $this->wall( '-100 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+31 days 00:00' ),
					'end'   => '',
				),
			)
		);

		$this->assertSame( array(), $this->upcoming( array( 'period' => 'next30Days' ) ) );
	}

	/**
	 * 動く期間は、期間に入る最初の組の順に並ぶ。過ぎた回があるイベントが直近を押し出さない。
	 */
	public function test_period_orders_by_the_first_date_in_the_period() {
		$this->event(
			'series',
			array(
				array(
					'start' => $this->wall( '-100 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+29 days 10:00' ),
					'end'   => '',
				),
			)
		);
		$this->event( 'b', $this->dates( '+2 days 10:00' ) );
		$this->event( 'a', $this->dates( '+1 day 10:00' ) );
		$this->event( 'a2', $this->dates( '+1 day 10:00' ) );

		$this->assertSame( array( 'a', 'a2', 'b', 'series' ), $this->upcoming( array( 'period' => 'next30Days' ) ) );
		$this->assertSame(
			array( 'series', 'b', 'a2', 'a' ),
			$this->upcoming(
				array(
					'period' => 'next30Days',
					'order'  => 'desc',
				)
			)
		);
		$this->assertSame(
			array( 'a', 'a2' ),
			$this->upcoming(
				array(
					'period'  => 'next30Days',
					'perPage' => 2,
				)
			)
		);
	}

	/**
	 * 動く期間でもページ送りの件数は組で絞った後の数になる。当たりが無ければ 0 件。
	 */
	public function test_period_pagination_counts_filtered_events() {
		foreach ( array( 'a', 'b', 'c' ) as $title ) {
			$this->event( $title, $this->dates( '+2 days 10:00' ) );
		}
		$this->event(
			'between',
			array(
				array(
					'start' => $this->wall( '-100 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+70 days 10:00' ),
					'end'   => '',
				),
			)
		);

		$query = Event_List::query(
			Event_List::normalize(
				array(
					'period'  => 'next30Days',
					'perPage' => 2,
				)
			),
			1
		);
		$this->assertSame( 3, (int) $query->found_posts );
		$this->assertSame( 2, (int) $query->max_num_pages );
		$this->assertCount( 2, $query->posts );
		$this->assertSame( array( 'a', 'b' ), wp_list_pluck( $query->posts, 'post_title' ) );

		$second = Event_List::query(
			Event_List::normalize(
				array(
					'period'  => 'next30Days',
					'perPage' => 2,
				)
			),
			2
		);
		$this->assertSame( array( 'c' ), wp_list_pluck( $second->posts, 'post_title' ) );
	}

	/**
	 * 期間に 2 組あるイベントは、その早いほうの組で並ぶ。
	 */
	public function test_period_uses_the_earliest_date_in_the_period() {
		$this->event(
			'two dates',
			array(
				array(
					'start' => $this->wall( '+2 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+20 days 10:00' ),
					'end'   => '',
				),
			)
		);
		$this->event( 'between them', $this->dates( '+10 days 10:00' ) );

		$this->assertSame( array( 'two dates', 'between them' ), $this->upcoming( array( 'period' => 'next30Days' ) ) );
	}

	/**
	 * 期間内の開始が同じなら ID の順にする。最初の開始の順に引き継がない。
	 */
	public function test_period_breaks_ties_by_id() {
		$this->event( 'smaller id', $this->dates( '+5 days 10:00' ) );
		$this->event(
			'larger id with an old date',
			array(
				array(
					'start' => $this->wall( '-100 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+5 days 10:00' ),
					'end'   => '',
				),
			)
		);

		$this->assertSame( array( 'smaller id', 'larger id with an old date' ), $this->upcoming( array( 'period' => 'next30Days' ) ) );
	}

	/**
	 * 動く期間に当たりが無ければ、全件を返さず 0 件にする。
	 */
	public function test_period_with_no_match_returns_nothing() {
		$this->event(
			'between',
			array(
				array(
					'start' => $this->wall( '-100 days 10:00' ),
					'end'   => '',
				),
				array(
					'start' => $this->wall( '+70 days 10:00' ),
					'end'   => '',
				),
			)
		);

		$this->assertSame( array(), $this->upcoming( array( 'period' => 'next30Days' ) ) );
	}

	/**
	 * 動く期間は固定の日付に勝つ。知らない値は無視する。
	 */
	public function test_period_overrides_dates_and_rejects_other_values() {
		$result = Event_List::normalize(
			array(
				'period'   => 'thisMonth',
				'dateFrom' => '2020-01-01',
				'dateTo'   => '2020-01-02',
			)
		);
		$this->assertNotSame( '2020-01-01', $result['dateFrom'] );
		$this->assertNotSame( '2020-01-02', $result['dateTo'] );

		$result = Event_List::normalize(
			array(
				'period'   => 'forever',
				'dateFrom' => '2020-01-01',
			)
		);
		$this->assertSame( '', $result['period'] );
		$this->assertSame( '2020-01-01', $result['dateFrom'] );
		$this->assertSame( '', Event_List::normalize( array( 'period' => array( 'thisMonth' ) ) )['period'] );
	}

	/**
	 * 動く期間のページ送りの名前は、日が変わっても同じ。共有されたリンクを効かせ続ける。
	 */
	public function test_period_page_param_does_not_change_with_the_date() {
		// 2 つのタイムゾーンは 25 時間離れているので、今日の日付は必ず違う。
		$names = array();
		foreach ( array( 'Pacific/Kiritimati', 'Pacific/Pago_Pago' ) as $zone ) {
			update_option( 'timezone_string', $zone );
			foreach ( array( 'a', 'b' ) as $title ) {
				$this->event( $title . $zone, $this->dates( '+1 day' ) );
			}
			$html = $this->render(
				Event_List_Block::NAME,
				array(
					'period'  => 'next30Days',
					'perPage' => 1,
				)
			);
			$this->assertSame( 1, preg_match( '/(eventwatch_page_[0-9a-f]{8})=2/', $html, $match ) );
			$names[] = $match[1];
		}

		$this->assertSame( $names[0], $names[1] );
	}

	/**
	 * ショートコードの period。
	 */
	public function test_list_shortcode_accepts_the_period() {
		$this->event( 'near', $this->dates( '+2 days 10:00' ) );
		$this->event( 'far', $this->dates( '+40 days 10:00' ) );

		$html = do_shortcode( '[eventwatch_events period="next30Days"]' );

		$this->assertStringContainsString( '>near<', $html );
		$this->assertStringNotContainsString( '>far<', $html );
	}

	/**
	 * パターン: 4 つが plugin の出所で登録され、色の指定を持たない。
	 */
	public function test_patterns_are_registered_without_colors() {
		Event_Patterns::register();

		$registry = \WP_Block_Patterns_Registry::get_instance();
		$expected = array(
			'this-month'        => array( 'eventwatch/event-list' ),
			'coming-soon'       => array( 'eventwatch/event-list' ),
			'past-events'       => array( 'eventwatch/past-events' ),
			'upcoming-and-past' => array( 'eventwatch/event-list', 'eventwatch/past-events' ),
		);

		$this->assertTrue( \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( Event_Patterns::CATEGORY ) );

		foreach ( $expected as $slug => $blocks ) {
			$pattern = $registry->get_registered( 'eventwatch/' . $slug );
			$this->assertIsArray( $pattern, $slug );
			$this->assertSame( 'plugin', $pattern['source'] );
			$this->assertSame( array( Event_Patterns::CATEGORY ), $pattern['categories'] );

			$names = array();
			$walk  = static function ( array $parsed ) use ( &$walk, &$names ) {
				foreach ( $parsed as $block ) {
					if ( $block['blockName'] ) {
						$names[] = $block['blockName'];
					}
					$walk( $block['innerBlocks'] );
				}
			};
			$walk( parse_blocks( $pattern['content'] ) );

			foreach ( $blocks as $block ) {
				$this->assertContains( $block, $names, $slug );
			}
			$this->assertContains( 'core/heading', $names, $slug );

			foreach ( array( 'textColor', 'backgroundColor', '"color"', 'has-text-color', 'has-background', 'color:', 'background' ) as $color ) {
				$this->assertStringNotContainsString( $color, $pattern['content'], $slug );
			}
		}
	}
}
