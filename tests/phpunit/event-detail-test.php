<?php
/**
 * イベント詳細のページのテスト。
 *
 * @package EventWatch
 */

namespace EventWatch\Tests;

use EventWatch\Event_Detail;
use EventWatch\Event_Ical;
use EventWatch\Event_Dates;
use EventWatch\Event_List_Block;
use EventWatch\Event_Meta;
use EventWatch\Post_Type;
use EventWatch\Settings;
use WP_UnitTestCase;

/**
 * Event_Detail と Event_Ical のテスト。
 */
class Event_Detail_Test extends WP_UnitTestCase {

	/**
	 * 欄の名前の一覧。
	 *
	 * @var string[]
	 */
	const FIELDS = array( 'back', 'category', 'dates', 'venue', 'organizer', 'map', 'calendar', 'interested', 'share', 'official' );

	/**
	 * 基底クラスの tear_down がメタとブロックの登録を外すので、テストごとに揃える。
	 */
	public function set_up() {
		parent::set_up();
		Event_Meta::register();
		update_option( 'timezone_string', 'Asia/Tokyo' );
		$this->set_permalink_structure( '/%postname%/' );
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( Event_List_Block::NAME ) ) {
			Event_List_Block::register();
		}
		if ( ! $registry->is_registered( Event_Detail::FIELD_BLOCK ) ) {
			Event_Detail::register_blocks();
		}
	}

	/**
	 * 今日から数えた壁時計の文字列。
	 *
	 * @param int    $days 日数。
	 * @param string $time 時刻。
	 * @return string
	 */
	private function day( $days, $time = '13:30' ) {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS, wp_timezone() ) . ' ' . $time;
	}

	/**
	 * イベントを作る。
	 *
	 * @param array $meta メタ。
	 * @param array $post 投稿の値。
	 * @return \WP_Post
	 */
	private function event( array $meta = array(), array $post = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Organ class',
					'post_name'    => 'organ-class',
					'post_content' => '<p>Body text</p>',
					'post_excerpt' => '',
				),
				$post
			)
		);

		$meta = array_merge(
			array(
				Event_Meta::ZONE          => 'Asia/Tokyo',
				Event_Meta::OCCURRENCES   => array(
					array(
						'start' => $this->day( 10 ),
						'end'   => $this->day( 10, '15:00' ),
					),
				),
				Event_Meta::VENUE_NAME    => 'Harbor Hall',
				Event_Meta::VENUE_ADDRESS => '1-2-3 Minato, Yokohama',
			),
			$meta
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return get_post( $id );
	}

	/**
	 * 既定の並びが板 4a のとおりで、中のブロックに色の指定が無い。
	 */
	public function test_default_content_follows_the_board_order() {
		$blocks = parse_blocks( Event_Detail::default_content() );
		$blocks = array_values( array_filter( $blocks, static fn( $b ) => null !== $b['blockName'] ) );

		$this->assertCount( 1, $blocks );
		$names = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$names ) {
			foreach ( $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$names[] = isset( $block['attrs']['field'] ) ? $block['attrs']['field'] : $block['blockName'];
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( $blocks[0]['innerBlocks'] );

		$this->assertSame(
			array(
				'back',
				'category',
				'core/post-title',
				'core/post-featured-image',
				'core/post-content',
				'core/group',
				'dates',
				'venue',
				'organizer',
				'map',
				'eventwatch/event-actions',
				'calendar',
				'interested',
				'share',
				'official',
			),
			$names
		);
		$this->assertStringNotContainsString( '"color"', Event_Detail::default_content() );
	}

	/**
	 * クラシックテーマのヘッダーが開く main と入れ子にならないよう、外側の要素を変えられる。
	 */
	public function test_default_content_outer_element() {
		$html = Event_Detail::default_content( 'article' );

		$this->assertStringNotContainsString( '<main', $html );
		$this->assertStringNotContainsString( '</main', $html );
		$this->assertStringContainsString( '<article class="wp-block-group eventwatch-detail-page"', $html );
		$this->assertStringContainsString( '<main class="wp-block-group eventwatch-detail-page"', Event_Detail::default_content() );
	}

	/**
	 * テンプレートがこの投稿タイプに登録される。
	 */
	public function test_block_template_is_registered() {
		$template = \WP_Block_Templates_Registry::get_instance()->get_registered( Event_Detail::TEMPLATE_NAME );

		$this->assertNotNull( $template );
		$this->assertSame( 'single-eventwatch-event', $template->slug );
		$this->assertSame( array( Post_Type::POST_TYPE ), $template->post_types );
	}

	/**
	 * イベントの階層に、テーマの名前の次にプラグインのテンプレートが並ぶ。ほかの投稿には足さない。
	 */
	public function test_template_hierarchy() {
		$this->assertSame(
			array( 'single-eventwatch_event-a.php', 'single-eventwatch_event.php', 'single-eventwatch-event.php', 'single.php', 'singular.php' ),
			Event_Detail::add_to_template_hierarchy( array( 'single-eventwatch_event-a.php', 'single-eventwatch_event.php', 'single.php', 'singular.php' ) )
		);

		$other = array( 'single-post.php', 'single.php', 'singular.php' );
		$this->assertSame( $other, Event_Detail::add_to_template_hierarchy( $other ) );
	}

	/**
	 * 本体がイベントのページでプラグインのテンプレートを選ぶ。
	 */
	public function test_block_theme_resolves_the_plugin_template() {
		$this->go_to( get_permalink( $this->event() ) );

		$found = array_values( get_block_templates( array( 'slug__in' => array( 'single-eventwatch-event' ) ) ) );

		$this->assertSame( 10, has_filter( 'single_template_hierarchy', array( Event_Detail::class, 'add_to_template_hierarchy' ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'plugin', $found[0]->source );
	}

	/**
	 * 日時は全組を出し、注記を添える。
	 */
	public function test_dates_lists_every_occurrence_with_the_note() {
		$post = $this->event(
			array(
				Event_Meta::OCCURRENCES   => array(
					array(
						'start' => $this->day( 5 ),
						'end'   => '',
					),
					array(
						'start' => $this->day( 6 ),
						'end'   => $this->day( 6, '15:00' ),
					),
				),
				Event_Meta::SCHEDULE_NOTE => 'Rain or shine',
			)
		);

		$html = Event_Detail::render_field( 'dates', $post );

		$this->assertSame( 2, substr_count( $html, '<time' ) );
		$this->assertStringContainsString( 'Rain or shine', $html );
		$this->assertStringNotContainsString( 'eventwatch-event__ended', $html );
	}

	/**
	 * 全日程が過ぎたら「終了」を出し、カレンダーと気になるを出さない。今日に終わるならまだ出す。
	 */
	public function test_ended_event() {
		$ended = $this->event(
			array(
				Event_Meta::OCCURRENCES    => array(
					array(
						'start' => $this->day( -1, '10:00' ),
						'end'   => $this->day( -1, '23:59' ),
					),
				),
				Event_Detail::LISTING_SLUG => 'organ',
			)
		);
		$this->assertStringContainsString( 'eventwatch-event__ended', Event_Detail::render_field( 'dates', $ended ) );
		$this->assertSame( '', Event_Detail::render_field( 'calendar', $ended ) );
		$this->assertSame( '', Event_Detail::render_field( 'interested', $ended ) );
		$this->assertNotSame( '', Event_Detail::render_field( 'share', $ended ) );

		$today = $this->event(
			array(
				Event_Meta::OCCURRENCES    => array(
					array(
						'start' => $this->day( 0, '00:00' ),
						'end'   => $this->day( 0, '00:01' ),
					),
				),
				Event_Detail::LISTING_SLUG => 'organ',
			)
		);
		$this->assertStringNotContainsString( 'eventwatch-event__ended', Event_Detail::render_field( 'dates', $today ) );
		$this->assertNotSame( '', Event_Detail::render_field( 'calendar', $today ) );
		$this->assertNotSame( '', Event_Detail::render_field( 'interested', $today ) );
	}

	/**
	 * 日程が 0 組なら、日時・カレンダー・気になる・構造化データを出さない。
	 */
	public function test_event_without_dates() {
		$post = $this->event(
			array(
				Event_Meta::OCCURRENCES    => array(),
				Event_Detail::LISTING_SLUG => 'organ',
			)
		);

		$this->assertSame( '', Event_Detail::render_field( 'dates', $post ) );
		$this->assertSame( '', Event_Detail::render_field( 'calendar', $post ) );
		$this->assertSame( '', Event_Detail::render_field( 'interested', $post ) );
		$this->assertSame( array(), Event_Detail::json_ld( $post ) );
		$this->assertFalse( Event_Ical::is_downloadable( $post ) );
		$this->assertNotSame( '', Event_Detail::render_field( 'venue', $post ) );
	}

	/**
	 * パスワードを知らない人には、どの欄も構造化データも .ics も出さない。
	 */
	public function test_password_protected_event_leaks_nothing() {
		update_option( Settings::MAPS_KEY, 'KEY' );
		$post = $this->event( array( Event_Detail::LISTING_SLUG => 'organ' ), array( 'post_password' => 'secret' ) );

		foreach ( self::FIELDS as $field ) {
			$this->assertSame( '', Event_Detail::render_field( $field, $post ), $field );
		}
		$this->assertFalse( Event_Ical::is_downloadable( $post ) );

		$this->go_to( get_permalink( $post ) );
		ob_start();
		Event_Detail::print_head();
		$head = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'ld+json', $head );
		$this->assertStringNotContainsString( 'og:', $head );
		$this->assertStringNotContainsString( 'Harbor Hall', $head );
	}

	/**
	 * 本体のアイキャッチ画像のブロックも、パスワードを知らない人に出さない。本体はこのブロックで確かめない。
	 */
	public function test_featured_image_is_hidden_when_protected() {
		$thumbnail = self::factory()->attachment->create_object(
			array(
				'file'           => 'image.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$open = $this->event();
		set_post_thumbnail( $open, $thumbnail );
		$this->go_to( get_permalink( $open ) );
		the_post();
		$this->assertStringContainsString( '<img', do_blocks( '<!-- wp:post-featured-image /-->' ) );

		$locked = $this->event( array(), array( 'post_password' => 'secret' ) );
		set_post_thumbnail( $locked, $thumbnail );
		$this->go_to( get_permalink( $locked ) );
		the_post();
		$this->assertStringNotContainsString( '<img', do_blocks( '<!-- wp:post-featured-image /-->' ) );
	}

	/**
	 * パスワード保護の別の投稿タイプの画像は変えない。
	 */
	public function test_featured_image_of_other_post_types_is_untouched() {
		$thumbnail = self::factory()->attachment->create_object(
			array(
				'file'           => 'image.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$post      = self::factory()->post->create( array( 'post_password' => 'secret' ) );
		set_post_thumbnail( $post, $thumbnail );
		$this->go_to( get_permalink( $post ) );
		the_post();

		$this->assertStringContainsString( '<img', do_blocks( '<!-- wp:post-featured-image /-->' ) );
	}

	/**
	 * テーマが階層の名前で用意したテンプレートは、スラッグ別のものも含めて上書きしない。
	 *
	 * @dataProvider theme_templates
	 *
	 * @param string $file テンプレート。
	 */
	public function test_theme_templates_win_on_classic_themes( $file ) {
		switch_theme( 'twentytwentyone' );
		$this->go_to( get_permalink( $this->event() ) );

		$this->assertSame( $file, Event_Detail::single_template( $file ) );
	}

	/**
	 * テーマのテンプレートの名前。
	 *
	 * @return array<string, array{string}>
	 */
	public function theme_templates() {
		return array(
			'投稿タイプ'    => array( '/theme/single-eventwatch_event.php' ),
			'スラッグ別'    => array( '/theme/single-eventwatch_event-organ-class.php' ),
			'プラグインの名前' => array( '/theme/single-eventwatch-event.php' ),
		);
	}

	/**
	 * テーマの single.php は、プラグインのテンプレートに置き換える。
	 */
	public function test_generic_theme_template_is_replaced_on_classic_themes() {
		switch_theme( 'twentytwentyone' );
		$this->go_to( get_permalink( $this->event() ) );

		$this->assertStringEndsWith( 'templates/single-event.php', Event_Detail::single_template( '/theme/single.php' ) );
	}

	/**
	 * 別の投稿タイプでは何も描かない。
	 */
	public function test_other_post_types_render_nothing() {
		$post = get_post( self::factory()->post->create() );

		foreach ( self::FIELDS as $field ) {
			$this->assertSame( '', Event_Detail::render_field( $field, $post ), $field );
		}
	}

	/**
	 * ブロックの描画を通すと、外側の要素とテーマの支えが付く。空の欄は枠を残さない。
	 */
	public function test_block_rendering() {
		$post = $this->event();
		$this->go_to( get_permalink( $post ) );
		the_post();

		$html = do_blocks( '<!-- wp:eventwatch/event-field {"field":"venue"} /-->' );
		$this->assertStringContainsString( 'is-field-venue wp-block-eventwatch-event-field', $html );

		$this->assertSame( '', trim( do_blocks( '<!-- wp:eventwatch/event-field {"field":"official"} /-->' ) ) );
	}

	/**
	 * ブロックは render.php を使わず、render_callback で描く。プラグインのコードで描画の HTML を echo しない。
	 */
	public function test_blocks_render_through_callbacks() {
		$registry = \WP_Block_Type_Registry::get_instance();
		$expected = array(
			Event_List_Block::NAME      => array( Event_List_Block::class, 'render_block' ),
			Event_Detail::FIELD_BLOCK   => array( Event_Detail::class, 'render_block' ),
			Event_Detail::ACTIONS_BLOCK => array( Event_Detail::class, 'render_actions' ),
		);
		foreach ( $expected as $name => $callback ) {
			$this->assertSame( $callback, $registry->get_registered( $name )->render_callback, $name );
		}

		$this->assertSame( '', Event_Detail::render_actions( array(), " \n" ) );
	}

	/**
	 * 操作の列はパスワード保護で空の枠を残さない。
	 */
	public function test_actions_row_is_empty_when_protected() {
		$post = $this->event( array(), array( 'post_password' => 'secret' ) );
		$this->go_to( get_permalink( $post ) );
		the_post();

		$html = do_blocks( '<!-- wp:eventwatch/event-actions --><!-- wp:eventwatch/event-field {"field":"share"} /--><!-- /wp:eventwatch/event-actions -->' );

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * オンラインは住所と地図を出さない。キーがあっても埋め込まない。
	 */
	public function test_online_event() {
		update_option( Settings::MAPS_KEY, 'KEY' );
		$post = $this->event( array( Event_Meta::ONLINE => true ) );

		$venue = Event_Detail::render_field( 'venue', $post );
		$this->assertStringContainsString( 'Online', $venue );
		$this->assertStringNotContainsString( 'Minato', $venue );
		$this->assertSame( '', Event_Detail::render_field( 'map', $post ) );
	}

	/**
	 * 場所未定は文を出し、地図を出さない。
	 */
	public function test_venue_to_be_announced() {
		update_option( Settings::MAPS_KEY, 'KEY' );
		$post = $this->event(
			array(
				Event_Meta::VENUE_NAME    => '',
				Event_Meta::VENUE_ADDRESS => '',
			)
		);

		$this->assertStringContainsString( 'Venue to be announced', Event_Detail::render_field( 'venue', $post ) );
		$this->assertSame( '', Event_Detail::render_field( 'map', $post ) );
	}

	/**
	 * キーが無ければ地図はリンクだけ。詳細のどの欄を描いても外部へ接続しない。
	 */
	public function test_map_without_key_is_links_only() {
		$requests = 0;
		$watch    = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $watch );

		$post = $this->event();
		$html = Event_Detail::render_field( 'map', $post );
		foreach ( self::FIELDS as $field ) {
			Event_Detail::render_field( $field, $post );
		}
		remove_filter( 'pre_http_request', $watch );

		$this->assertSame( 0, $requests );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'https://www.google.com/maps/search/?api=1&#038;query=1-2-3%20Minato%2C%20Yokohama', $html );
		$this->assertStringContainsString( 'https://www.openstreetmap.org/search?query=1-2-3%20Minato%2C%20Yokohama', $html );
		$this->assertSame( 2, substr_count( $html, 'target="_blank"' ) );
		$this->assertSame( 2, substr_count( $html, 'opens in a new tab' ) );
	}

	/**
	 * キーがあれば埋め込み、リンクも残す。住所が無ければ会場名で引く。
	 */
	public function test_map_with_key_embeds_and_keeps_links() {
		update_option( Settings::MAPS_KEY, 'KEY_1' );
		$post = $this->event( array( Event_Meta::VENUE_ADDRESS => '' ) );

		$html = Event_Detail::render_field( 'map', $post );

		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'maps/embed/v1/place?key=KEY_1&#038;q=Harbor%20Hall', $html );
		$this->assertStringContainsString( 'title="Venue map: Harbor Hall"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'query=Harbor%20Hall', $html );
	}

	/**
	 * 「気になる」は掲載中のイベントにだけ出る。ほかの欄は掲載の状態で変わらない。
	 */
	public function test_interested_only_when_listed() {
		$unlisted = $this->event();
		$listed   = $this->event( array( Event_Detail::LISTING_SLUG => 'organ class' ) );

		$this->assertSame( '', Event_Detail::render_field( 'interested', $unlisted ) );

		$html = Event_Detail::render_field( 'interested', $listed );
		$this->assertStringContainsString( '<dialog', $html );
		$this->assertStringContainsString( 'href="https://eventwatch.app/en/events/organ%20class"', $html );
		$this->assertStringContainsString( 'apps.apple.com', $html );
		$this->assertStringContainsString( 'play.google.com', $html );

		foreach ( array( 'dates', 'venue', 'organizer', 'map', 'official' ) as $field ) {
			$this->assertSame(
				Event_Detail::render_field( $field, $unlisted ),
				Event_Detail::render_field( $field, $listed ),
				$field
			);
		}
	}

	/**
	 * Ja のサイトは接頭辞の無い URL。
	 */
	public function test_listing_url_for_japanese() {
		$post   = $this->event( array( Event_Detail::LISTING_SLUG => 'organ' ) );
		$locale = static function () {
			return 'ja';
		};
		add_filter( 'locale', $locale );
		$url = Event_Detail::listing_url( $post->ID );
		remove_filter( 'locale', $locale );

		$this->assertSame( 'https://eventwatch.app/events/organ', $url );
	}

	/**
	 * 状態のメタは未認証の REST の応答に出ない。
	 */
	public function test_listing_slug_is_not_in_rest() {
		$post = $this->event( array( Event_Detail::LISTING_SLUG => 'organ' ) );

		$response = rest_do_request( rest_get_route_for_post( $post ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'organ"', wp_json_encode( $response->get_data()['meta'] ) );
		$this->assertArrayNotHasKey( Event_Detail::LISTING_SLUG, $response->get_data()['meta'] );
	}

	/**
	 * 公式ページは入れたときだけ、http と https だけ。
	 */
	public function test_official_page() {
		$this->assertSame( '', Event_Detail::render_field( 'official', $this->event() ) );

		$with = $this->event( array( Event_Meta::URL => 'https://example.com/a' ) );
		$this->assertStringContainsString( 'href="https://example.com/a"', Event_Detail::render_field( 'official', $with ) );

		$bad = $this->event();
		$raw = static function ( $value, $id, $key ) use ( $bad ) {
			return Event_Meta::URL === $key && $id === $bad->ID ? array( 'javascript:alert(1)' ) : $value;
		};
		add_filter( 'get_post_metadata', $raw, 10, 3 );
		$html = Event_Detail::render_field( 'official', $bad );
		remove_filter( 'get_post_metadata', $raw, 10 );

		$this->assertSame( '', $html );
	}

	/**
	 * 一覧へ戻るは、公開の固定ページを選んだときだけ。
	 */
	public function test_back_link() {
		$post = $this->event();
		$this->assertSame( '', Event_Detail::render_field( 'back', $post ) );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'events-list',
			)
		);
		update_option( Settings::LIST_PAGE, $page );
		$this->assertStringContainsString( 'href="' . get_permalink( $page ) . '"', Event_Detail::render_field( 'back', $post ) );

		wp_update_post(
			array(
				'ID'          => $page,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( '', Event_Detail::render_field( 'back', $post ) );
	}

	/**
	 * 悪い値をメタの読み出しへ直接入れて、エスケープを確かめる。保存の sanitize に隠れないように。
	 */
	public function test_values_are_escaped() {
		$evil = '"><script>alert(1)</script>';
		$post = $this->event( array( Event_Detail::LISTING_SLUG => 'organ' ) );
		$raw  = static function ( $value, $id, $key ) use ( $evil, $post ) {
			if ( $id !== $post->ID ) {
				return $value;
			}
			if ( in_array( $key, array( Event_Meta::VENUE_NAME, Event_Meta::VENUE_ADDRESS, Event_Meta::SCHEDULE_NOTE, Event_Detail::LISTING_SLUG ), true ) ) {
				return array( $evil );
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $raw, 10, 3 );
		update_option( Settings::MAPS_KEY, 'KEY' );
		update_option( 'blogname', $evil );

		foreach ( self::FIELDS as $field ) {
			$this->assertStringNotContainsString( '<script', Event_Detail::render_field( $field, $post ), $field );
		}
		remove_filter( 'get_post_metadata', $raw, 10 );
	}

	/**
	 * 共有は外部のスクリプトを読まず、共有用の URL と状態の領域を最初から持つ。
	 */
	public function test_share_markup() {
		$post = $this->event( array(), array( 'post_title' => 'A & B' ) );

		$html = Event_Detail::render_field( 'share', $post );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'https://x.com/intent/post', $html );
		$this->assertStringContainsString( 'social-plugins.line.me', $html );
		$this->assertStringContainsString( 'facebook.com/sharer', $html );
		$this->assertMatchesRegularExpression( '#<p[^>]*role="status"[^>]*aria-live="polite"[^>]*></p>#', $html );
		$this->assertStringContainsString( 'data-url="' . get_permalink( $post ) . '"', $html );
	}

	/**
	 * Google カレンダーは、まだ終わっていない最初の組を指す。複数なら日付を添える。
	 */
	public function test_calendar_points_google_to_the_next_occurrence() {
		$next_day = $this->day( 3, '10:00' );
		$post     = $this->event(
			array(
				Event_Meta::OCCURRENCES => array(
					array(
						'start' => $this->day( -5, '10:00' ),
						'end'   => $this->day( -5, '11:00' ),
					),
					array(
						'start' => $next_day,
						'end'   => '',
					),
					array(
						'start' => $this->day( 4, '10:00' ),
						'end'   => '',
					),
				),
			)
		);

		$next = Event_Dates::next_occurrence( $post->ID );
		$html = Event_Detail::render_field( 'calendar', $post );

		$this->assertSame( strtotime( $next_day . ' Asia/Tokyo' ), $next['start'] );
		$this->assertStringContainsString( 'eventwatch_ics=1', $html );
		$this->assertStringContainsString( 'dates=' . gmdate( 'Ymd\THis\Z', $next['start'] ) . '/' . gmdate( 'Ymd\THis\Z', $next['start'] + HOUR_IN_SECONDS ), $html );
		$this->assertStringContainsString( 'eventwatch-detail__menu-note', $html );
	}

	/**
	 * 構造化データは日程ごとの Event で、終了が空なら endDate を持たない。
	 */
	public function test_json_ld_graph() {
		$post = $this->event(
			array(
				Event_Meta::OCCURRENCES => array(
					array(
						'start' => $this->day( 5, '10:00' ),
						'end'   => '',
					),
					array(
						'start' => $this->day( 6, '10:00' ),
						'end'   => $this->day( 6, '12:00' ),
					),
				),
			)
		);

		$data = Event_Detail::json_ld( $post );

		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertCount( 2, $data['@graph'] );
		$this->assertSame( 'Event', $data['@graph'][0]['@type'] );
		$this->assertSame( 'Organ class', $data['@graph'][0]['name'] );
		$this->assertStringEndsWith( '+09:00', $data['@graph'][0]['startDate'] );
		$this->assertArrayNotHasKey( 'endDate', $data['@graph'][0] );
		$this->assertStringEndsWith( '+09:00', $data['@graph'][1]['endDate'] );
		$this->assertSame( 'Place', $data['@graph'][0]['location']['@type'] );
		$this->assertSame( '1-2-3 Minato, Yokohama', $data['@graph'][0]['location']['address'] );
		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $data['@graph'][0]['eventAttendanceMode'] );
		$this->assertSame( 'Body text', $data['@graph'][0]['description'] );
	}

	/**
	 * オンラインは VirtualLocation。場所未定は location を持たない。住所が無ければ会場名を住所に入れる。
	 */
	public function test_json_ld_location() {
		$online = Event_Detail::json_ld( $this->event( array( Event_Meta::ONLINE => true ) ) );
		$this->assertSame( 'VirtualLocation', $online['@graph'][0]['location']['@type'] );
		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $online['@graph'][0]['eventAttendanceMode'] );

		$unknown = Event_Detail::json_ld(
			$this->event(
				array(
					Event_Meta::VENUE_NAME    => '',
					Event_Meta::VENUE_ADDRESS => '',
				)
			)
		);
		$this->assertArrayNotHasKey( 'location', $unknown['@graph'][0] );

		$name_only = Event_Detail::json_ld( $this->event( array( Event_Meta::VENUE_ADDRESS => '' ) ) );
		$this->assertSame( 'Harbor Hall', $name_only['@graph'][0]['location']['address'] );
	}

	/**
	 * 題に閉じるタグがあっても script を閉じない。
	 */
	public function test_json_ld_cannot_close_the_script() {
		$json = Event_Detail::encode_json_ld( array( 'name' => '</script><b>x' ) );

		$this->assertStringNotContainsString( '</script>', $json );
		$this->assertSame( array( 'name' => '</script><b>x' ), json_decode( $json, true ) );
	}

	/**
	 * Head には構造化データと og を出す。フィルターで止められる。
	 */
	public function test_head_output_and_filters() {
		$post = $this->event();
		$this->go_to( get_permalink( $post ) );

		$capture = static function () {
			ob_start();
			Event_Detail::print_head();
			return (string) ob_get_clean();
		};

		$head = $capture();
		$this->assertStringContainsString( 'application/ld+json', $head );
		$this->assertStringContainsString( 'property="og:title" content="Organ class"', $head );
		$this->assertStringContainsString( 'name="description" content="Body text"', $head );

		add_filter( 'eventwatch_output_meta_tags', '__return_false' );
		$head = $capture();
		remove_filter( 'eventwatch_output_meta_tags', '__return_false' );
		$this->assertStringNotContainsString( 'og:title', $head );
		$this->assertStringNotContainsString( 'name="description"', $head );
		$this->assertStringContainsString( 'application/ld+json', $head, 'SEO プラグインがあっても Event は出す' );

		add_filter( 'eventwatch_output_json_ld', '__return_false' );
		$head = $capture();
		remove_filter( 'eventwatch_output_json_ld', '__return_false' );
		$this->assertStringNotContainsString( 'ld+json', $head );
	}

	/**
	 * JS が動かないときの CSS は、詳細の CSS に添えて読ませる。JS が動いた印は head のインラインのスクリプトが付ける。head に style・script を直に書かない。
	 */
	public function test_no_script_css_is_enqueued_inline() {
		$this->go_to( get_permalink( $this->event() ) );
		$handle = generate_block_asset_handle( 'eventwatch/event-field', 'style' );

		Event_Detail::enqueue_styles();

		$this->assertTrue( wp_style_is( $handle, 'enqueued' ) );
		$this->assertContains( Event_Detail::no_script_css(), (array) wp_styles()->get_data( $handle, 'after' ) );
		$this->assertStringStartsWith( 'html:not(.eventwatch-js) [data-eventwatch-menu-trigger],html:not(.eventwatch-js) [data-eventwatch-dialog-open],', Event_Detail::no_script_css() );
		$this->assertStringNotContainsString( '@media', Event_Detail::no_script_css() );

		$this->assertTrue( wp_script_is( Event_Detail::SCRIPT_FLAG_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_scripts()->get_data( Event_Detail::SCRIPT_FLAG_HANDLE, 'group' ), 'body の後ろに回ると、印が付く前にメニューの形が描かれる' );
		$this->assertContains( 'document.documentElement.classList.add("eventwatch-js");', (array) wp_scripts()->get_data( Event_Detail::SCRIPT_FLAG_HANDLE, 'after' ) );

		ob_start();
		Event_Detail::print_head();
		$head = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<style', $head );
		$this->assertStringNotContainsString( '<noscript', $head );
	}

	/**
	 * イベント以外のページの head には何も出さない。
	 */
	public function test_head_is_empty_elsewhere() {
		$this->go_to( get_permalink( self::factory()->post->create() ) );

		ob_start();
		Event_Detail::print_head();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * 非公開・下書き・ゴミ箱は、未ログインでは 404。公開は開く。
	 *
	 * @dataProvider hidden_statuses
	 *
	 * @param string $status 状態。
	 */
	public function test_hidden_events_are_404( $status ) {
		wp_set_current_user( 0 );
		$post = $this->event( array(), array( 'post_status' => $status ) );

		$this->go_to( '/?post_type=' . Post_Type::POST_TYPE . '&p=' . $post->ID );

		$this->assertTrue( is_404(), $status );
		$this->assertFalse( Event_Ical::is_downloadable( $post ) );
	}

	/**
	 * 隠れる状態。
	 *
	 * @return array<string, array{string}>
	 */
	public function hidden_statuses() {
		return array(
			'非公開' => array( 'private' ),
			'下書き' => array( 'draft' ),
			'ゴミ箱' => array( 'trash' ),
		);
	}

	/**
	 * .ics の本文。全組・UTC・終了が空は 1 時間・CRLF。
	 */
	public function test_ical_body() {
		$first_day  = $this->day( 5, '10:00' );
		$second_day = $this->day( 6, '10:00' );
		$post       = $this->event(
			array(
				Event_Meta::OCCURRENCES => array(
					array(
						'start' => $first_day,
						'end'   => substr( $first_day, 0, 10 ) . ' 12:00',
					),
					array(
						'start' => $second_day,
						'end'   => '',
					),
				),
			)
		);

		$ics   = Event_Ical::build( $post );
		$lines = explode( "\r\n", $ics );

		$this->assertSame( 'BEGIN:VCALENDAR', $lines[0] );
		$this->assertSame( '', end( $lines ), '最後の行も CRLF で終わる' );
		$this->assertSame( 0, preg_match( "/(?<!\r)\n/", $ics ), 'LF だけの改行が無い' );
		$this->assertSame( 2, substr_count( $ics, 'BEGIN:VEVENT' ) );

		$first  = strtotime( $first_day . ' Asia/Tokyo' );
		$second = strtotime( $second_day . ' Asia/Tokyo' );
		$this->assertContains( 'DTSTART:' . gmdate( 'Ymd\THis\Z', $first ), $lines );
		$this->assertContains( 'DTEND:' . gmdate( 'Ymd\THis\Z', $first + 2 * HOUR_IN_SECONDS ), $lines );
		$this->assertContains( 'DTEND:' . gmdate( 'Ymd\THis\Z', $second + HOUR_IN_SECONDS ), $lines );
		$this->assertContains( 'SUMMARY:Organ class', $lines );
		$this->assertStringContainsString( 'LOCATION:Harbor Hall 1-2-3 Minato\\, Yokohama', $ics );
	}

	/**
	 * 特別な字をエスケープする。
	 */
	public function test_ical_escape() {
		$this->assertSame( 'a\\;b\\,c\\\\d\\ne', Event_Ical::escape( "a;b,c\\d\ne" ) );
	}

	/**
	 * 折り返しは 75 オクテットを超えず、マルチバイトを切らず、戻すと元の行になる。
	 *
	 * @dataProvider long_lines
	 *
	 * @param string $line 行。
	 */
	public function test_ical_fold( $line ) {
		$folded = Event_Ical::fold( $line );

		foreach ( explode( "\r\n", $folded ) as $part ) {
			$this->assertLessThanOrEqual( 75, strlen( $part ) );
			$this->assertSame( 1, preg_match( '//u', $part ), '不正な UTF-8 にならない' );
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );
	}

	/**
	 * 長い行。
	 *
	 * @return array<string, array{string}>
	 */
	public function long_lines() {
		return array(
			'ちょうど 75'   => array( str_repeat( 'a', 75 ) ),
			'76'        => array( str_repeat( 'a', 76 ) ),
			'長い ASCII'  => array( str_repeat( 'a', 500 ) ),
			'長い日本語'     => array( 'SUMMARY:' . str_repeat( 'オルガン教室', 40 ) ),
			'境に 3 バイト字' => array( str_repeat( 'a', 74 ) . 'あいう' ),
		);
	}

	/**
	 * 75 オクテットちょうどは折り返さない。
	 */
	public function test_ical_fold_keeps_short_lines() {
		$line = str_repeat( 'a', 75 );

		$this->assertSame( $line, Event_Ical::fold( $line ) );
	}

	/**
	 * 次の組は終了で見る。始まっていて終わっていない組と、今日の 0 時ちょうどに終わる組は、まだ次の組。
	 */
	public function test_next_occurrence_uses_the_end_and_the_boundary() {
		$running = $this->event(
			array(
				Event_Meta::OCCURRENCES => array(
					array(
						'start' => $this->day( -1, '10:00' ),
						'end'   => $this->day( 1, '10:00' ),
					),
				),
			)
		);
		$this->assertNotNull( Event_Dates::next_occurrence( $running->ID ) );

		$midnight = $this->event(
			array(
				Event_Meta::OCCURRENCES => array(
					array(
						'start' => $this->day( -1, '23:00' ),
						'end'   => $this->day( 0, '00:00' ),
					),
				),
			)
		);
		$this->assertNotNull( Event_Dates::next_occurrence( $midnight->ID ) );
	}

	/**
	 * 主催の名前をエスケープする。保存の sanitize に隠れないよう、読み出しへ直接入れる。
	 */
	public function test_organizer_is_escaped() {
		$raw = static function () {
			return '<script>alert(1)</script>';
		};
		add_filter( 'option_blogname', $raw );
		$html = Event_Detail::render_field( 'organizer', $this->event() );
		remove_filter( 'option_blogname', $raw );

		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * 掲載のスラッグは URL のパスを壊さない。
	 */
	public function test_listing_url_encodes_the_slug() {
		$post = $this->event( array( Event_Detail::LISTING_SLUG => 'a/b?c' ) );

		$this->assertSame( 'https://eventwatch.app/en/events/a%2Fb%3Fc', Event_Detail::listing_url( $post->ID ) );
	}

	/**
	 * HTML ではない出力先に、題と説明の実体参照を文字のまま出さない。
	 */
	public function test_plain_outputs_decode_entities() {
		update_option( 'blogname', 'A &amp; B Hall' );
		$post = $this->event(
			array(),
			array(
				'post_title'   => "Tom's A & B",
				'post_content' => '<p>Rock &amp; roll</p>',
			)
		);

		$ics = str_replace( "\r\n ", '', Event_Ical::build( $post ) );
		$this->assertStringContainsString( 'SUMMARY:Tom’s A & B', str_replace( '\\', '', $ics ) );
		$this->assertStringNotContainsString( '&#', $ics );

		$row = Event_Dates::next_occurrence( $post->ID );
		$this->assertStringContainsString( 'text=' . rawurlencode( 'Tom’s A & B' ), Event_Ical::google_url( $post, $row ) );

		$share = Event_Detail::render_field( 'share', $post );
		$this->assertStringContainsString( 'text=' . rawurlencode( 'Tom’s A & B' ), html_entity_decode( $share ) );

		$event = Event_Detail::json_ld( $post )['@graph'][0];
		$this->assertSame( 'Tom’s A & B', $event['name'] );
		$this->assertSame( 'Rock & roll', $event['description'] );
		$this->assertSame( 'A & B Hall', $event['organizer']['name'] );
	}

	/**
	 * 終了が開始と同じ組は、長さ 0 の予定にしない。
	 */
	public function test_ical_same_start_and_end_gets_the_default_length() {
		$start = $this->day( 5, '10:00' );
		$post  = $this->event(
			array(
				Event_Meta::OCCURRENCES => array(
					array(
						'start' => $start,
						'end'   => $start,
					),
				),
			)
		);
		$utc   = strtotime( $start . ' Asia/Tokyo' );

		$this->assertContains( 'DTEND:' . gmdate( 'Ymd\THis\Z', $utc + HOUR_IN_SECONDS ), explode( "\r\n", Event_Ical::build( $post ) ) );
	}

	/**
	 * JS が無くてもキーボードで届くよう、メニューの項目の tabindex はマークアップに書かない。
	 */
	public function test_menu_items_are_reachable_without_js() {
		$post = $this->event();

		$this->assertStringNotContainsString( 'tabindex', Event_Detail::render_field( 'calendar', $post ) );
		$this->assertStringNotContainsString( 'tabindex', Event_Detail::render_field( 'share', $post ) );
	}

	/**
	 * 説明は抜粋を先に使い、タグを除き、長さを切る。
	 */
	public function test_description() {
		$this->assertSame( 'Short', Event_Detail::description( $this->event( array(), array( 'post_excerpt' => 'Short' ) ) ) );

		$long = Event_Detail::description( $this->event( array(), array( 'post_content' => '<p>' . str_repeat( 'あ', 300 ) . '</p>' ) ) );
		$this->assertSame( 160, mb_strlen( $long ) );
		$this->assertStringNotContainsString( '<p>', $long );
	}
}
