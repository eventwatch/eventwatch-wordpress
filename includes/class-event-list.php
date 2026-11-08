<?php
/**
 * イベントの一覧の取得と描画。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeImmutable;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ブロックとショートコードが同じ描画を呼ぶ。データはサイトの中だけから読む。
 */
class Event_List {

	const LAYOUT_CARDS = 'cards';
	const LAYOUT_LIST  = 'list';
	const DEFAULT_SIZE = 6;
	const MAX_SIZE     = 24;

	const SCOPE_UPCOMING = 'upcoming';
	const SCOPE_PAST     = 'past';

	const PERIOD_THIS_MONTH   = 'thisMonth';
	const PERIOD_NEXT_30_DAYS = 'next30Days';

	/**
	 * 過去のイベントをさかのぼる月数の選択肢。0 はすべて。
	 */
	const MONTHS_BACK_CHOICES = array( 3, 6, 12, 24, 0 );
	const DEFAULT_MONTHS_BACK = 12;

	/**
	 * キーワードの語を渡すクエリの変数。
	 */
	const KEYWORD_VAR = 'eventwatch_keyword';

	/**
	 * テーマが置く上書きのテンプレートの置き場。
	 */
	const THEME_DIR = 'eventwatch';

	/**
	 * 属性を検証して描画する。
	 *
	 * ブロックの描画の中で呼ぶ（get_block_wrapper_attributes が描画中のブロックを読むため）。ブロックの外からは render_block を通す。
	 *
	 * @param array  $attributes ブロックの属性。
	 * @param string $scope      開催予定か過去か。投稿の本文で書き換えられないよう、属性に持たせず描画側が決める。
	 * @return string
	 */
	public static function render( $attributes, $scope = self::SCOPE_UPCOMING ) {
		$raw                 = is_array( $attributes ) ? $attributes : array();
		$attributes          = self::normalize( $raw );
		$attributes['scope'] = self::SCOPE_PAST === $scope ? self::SCOPE_PAST : self::SCOPE_UPCOMING;
		// 色や余白も含めて分ける。絞り込みが同じで色だけ違うブロックを、別の一覧として扱うため。
		$identity = array_merge( $raw, $attributes );
		// 動く期間の日付は日ごとに変わる。共有されたページ送りのリンクが効かなくならないよう、名前の元に入れない。
		if ( '' !== $attributes['period'] ) {
			unset( $identity['dateFrom'], $identity['dateTo'] );
		}
		ksort( $identity );
		$hash   = substr( md5( (string) wp_json_encode( $identity ) ), 0, 8 );
		$param  = 'eventwatch_page_' . $hash;
		$anchor = 'eventwatch-events-' . $hash;

		list( $query, $page ) = self::query_for_request( $attributes, $param );

		$classes = 'is-layout-' . $attributes['layout'];
		if ( self::SCOPE_PAST === $attributes['scope'] ) {
			// 一覧の CSS を使い回す。一覧のブロックには本体が同じクラスを付ける。
			$classes .= ' wp-block-eventwatch-event-list';
		}

		$html = '<div ' . get_block_wrapper_attributes(
			array(
				'id'    => $anchor,
				'class' => $classes,
			)
		) . '>';

		if ( $query->posts ) {
			$html .= self::render_results( $attributes, $query, $param, $anchor, $page );
		} else {
			$html .= '<div class="eventwatch-events__empty"><p>' . esc_html( self::empty_text( $attributes ) ) . '</p></div>';
		}

		return $html . '</div>';
	}

	/**
	 * 0 件のときの文。
	 *
	 * @param array $attributes normalize() を通した属性と scope。
	 * @return string
	 */
	private static function empty_text( array $attributes ) {
		if ( '' !== $attributes['emptyText'] ) {
			return $attributes['emptyText'];
		}
		if ( self::SCOPE_PAST === $attributes['scope'] ) {
			return __( 'No past events yet.', 'eventwatch' );
		}
		// 「まだありません」だと、この期間の外に予定があるサイトで誤りになる。
		if ( self::PERIOD_THIS_MONTH === $attributes['period'] ) {
			return __( 'No events this month.', 'eventwatch' );
		}
		if ( self::PERIOD_NEXT_30_DAYS === $attributes['period'] ) {
			return __( 'No events in the next 30 days.', 'eventwatch' );
		}
		return __( 'No upcoming events yet.', 'eventwatch' );
	}

	/**
	 * 要求のページ番号でイベントを引く。
	 *
	 * @param array  $attributes normalize() を通した属性。
	 * @param string $param      ページのクエリの名前。
	 * @return array{0: WP_Query, 1: int} クエリと、実際に引いたページ。
	 */
	public static function query_for_request( array $attributes, $param ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ページの番号を読むだけで、状態を変えない。
		$raw   = isset( $_GET[ $param ] ) && is_scalar( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 1;
		$page  = max( 1, $raw );
		$query = self::query( $attributes, $page );

		// 件数が減ると、共有されたリンクのページが範囲外になる。「まだありません」と誤って出さない。
		// 結果が空だと WP_Query は found_posts を数えないので、件数では判定できない。
		if ( ! $query->posts && $page > 1 ) {
			$page  = 1;
			$query = self::query( $attributes, $page );
		}

		return array( $query, $page );
	}

	/**
	 * 項目の並びとページ送りを描く。
	 *
	 * @param array    $attributes normalize() を通した属性。
	 * @param WP_Query $query      結果のあるクエリ。
	 * @param string   $param      ページのクエリの名前。
	 * @param string   $anchor     ページ送りの着地先の id。
	 * @param int      $page       今のページ。
	 * @return string
	 */
	public static function render_results( array $attributes, WP_Query $query, $param, $anchor, $page ) {
		$html = '<ul class="eventwatch-events__items">';
		foreach ( $query->posts as $post ) {
			$html .= '<li class="eventwatch-events__item">' . self::render_item( $attributes, $post ) . '</li>';
		}

		return $html . '</ul>' . self::render_pagination( $param, $anchor, $page, (int) $query->max_num_pages );
	}

	/**
	 * 属性を、描画に使える値にそろえる。
	 *
	 * 投稿の本文は投稿者が書き換えられるので、block.json の型を頼らない。
	 *
	 * @param array $attributes 属性。
	 * @return array{layout: string, perPage: int, order: string, categoryId: int, tagId: int, prefecture: int, dateFrom: string, dateTo: string, emptyText: string, period: string, monthsBack: int}
	 */
	public static function normalize( array $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'layout'     => self::LAYOUT_CARDS,
				'perPage'    => self::DEFAULT_SIZE,
				'order'      => 'asc',
				'categoryId' => 0,
				'tagId'      => 0,
				'prefecture' => 0,
				'dateFrom'   => '',
				'dateTo'     => '',
				'emptyText'  => '',
				'period'     => '',
				'monthsBack' => self::DEFAULT_MONTHS_BACK,
			)
		);

		$prefecture = is_scalar( $attributes['prefecture'] ) ? (int) $attributes['prefecture'] : 0;
		$per_page   = is_scalar( $attributes['perPage'] ) ? (int) $attributes['perPage'] : self::DEFAULT_SIZE;
		$range      = self::period_range( $attributes['period'] );

		// 数字でない値を整数にすると 0（すべて）になるので、先に落とす。
		$months = is_numeric( $attributes['monthsBack'] ) ? (int) $attributes['monthsBack'] : self::DEFAULT_MONTHS_BACK;

		return array(
			'layout'     => self::LAYOUT_LIST === $attributes['layout'] ? self::LAYOUT_LIST : self::LAYOUT_CARDS,
			'perPage'    => min( self::MAX_SIZE, max( 1, $per_page ) ),
			'order'      => 'desc' === $attributes['order'] ? 'desc' : 'asc',
			'categoryId' => is_scalar( $attributes['categoryId'] ) ? absint( $attributes['categoryId'] ) : 0,
			'tagId'      => is_scalar( $attributes['tagId'] ) ? absint( $attributes['tagId'] ) : 0,
			'prefecture' => array_key_exists( $prefecture, Event_Options::prefectures() ) ? $prefecture : 0,
			'dateFrom'   => $range ? $range[0] : ( null !== self::parse_date( $attributes['dateFrom'] ) ? $attributes['dateFrom'] : '' ),
			'dateTo'     => $range ? $range[1] : ( null !== self::parse_date( $attributes['dateTo'] ) ? $attributes['dateTo'] : '' ),
			'emptyText'  => is_string( $attributes['emptyText'] ) ? sanitize_text_field( $attributes['emptyText'] ) : '',
			'period'     => $range ? $attributes['period'] : '',
			'monthsBack' => in_array( $months, self::MONTHS_BACK_CHOICES, true ) ? $months : self::DEFAULT_MONTHS_BACK,
		);
	}

	/**
	 * 動く期間を、サイトのタイムゾーンの今日から計算する。
	 *
	 * 投稿の本文に日付を書くと古くなるので、描画のたびに求める。
	 *
	 * @param mixed $period 期間の名前。
	 * @return array{0: string, 1: string}|null 始まりと終わり。知らない名前は null。
	 */
	private static function period_range( $period ) {
		$today = new DateTimeImmutable( 'today', wp_timezone() );

		if ( self::PERIOD_THIS_MONTH === $period ) {
			return array( $today->modify( 'first day of this month' )->format( 'Y-m-d' ), $today->modify( 'last day of this month' )->format( 'Y-m-d' ) );
		}
		if ( self::PERIOD_NEXT_30_DAYS === $period ) {
			return array( $today->format( 'Y-m-d' ), $today->modify( '+30 days' )->format( 'Y-m-d' ) );
		}
		return null;
	}

	/**
	 * N か月前の同じ日。その月に同じ日が無ければ月末にする。
	 *
	 * DateTimeImmutable::modify は 5 月 31 日の 3 か月前を 3 月 3 日に繰り上げる。
	 *
	 * @param DateTimeImmutable $day    基準の日。
	 * @param int               $months 月数。
	 * @return DateTimeImmutable
	 */
	public static function months_before( DateTimeImmutable $day, $months ) {
		$month = $day->modify( 'first day of this month' )->modify( '-' . (int) $months . ' months' );

		return $month->setDate(
			(int) $month->format( 'Y' ),
			(int) $month->format( 'n' ),
			min( (int) $day->format( 'j' ), (int) $month->format( 't' ) )
		);
	}

	/**
	 * イベントを引く。既定は開催予定で、scope が past なら終了したものを終了の新しい順に引く。
	 *
	 * 配列のメタは比べられないので、SQL ではおおまかに UTC の整数のメタで絞る。日程の無いイベントはこのメタを持たないので出ない。
	 * 開催予定は、そのうえで組の単位で比べ、カードに出す回（これから開催する最初の組。期間があれば期間に入る最初の組）の開始の順に並べる。
	 * 最初の開始で並べると、過ぎた回を持つイベントが、出している回より前の位置に並ぶ。
	 *
	 * @param array $attributes normalize() を通した属性。
	 * @param int   $page       ページ。
	 * @return WP_Query
	 */
	public static function query( array $attributes, $page ) {
		$is_past = isset( $attributes['scope'] ) && self::SCOPE_PAST === $attributes['scope'];

		// 同じ境目を境に分ける。開催予定と過去の間に隙間も重なりも作らない。
		$meta_query = array(
			'relation'          => 'AND',
			'eventwatch_starts' => array(
				'key'     => Event_Meta::FIRST_START,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			'eventwatch_ends'   => array(
				'key'     => Event_Meta::LAST_END,
				'value'   => Event_Dates::upcoming_boundary(),
				'compare' => $is_past ? '<' : '>=',
				'type'    => 'NUMERIC',
			),
		);

		if ( $is_past && ! empty( $attributes['monthsBack'] ) ) {
			$meta_query[] = array(
				'key'     => Event_Meta::LAST_END,
				'value'   => self::months_before( new DateTimeImmutable( 'today', wp_timezone() ), $attributes['monthsBack'] )->getTimestamp(),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		$from = self::parse_date( $attributes['dateFrom'] );
		if ( null !== $from ) {
			$meta_query[] = array(
				'key'     => Event_Meta::LAST_END,
				'value'   => $from->getTimestamp(),
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		$to = self::parse_date( $attributes['dateTo'] );
		if ( null !== $to ) {
			$meta_query[] = array(
				'key'     => Event_Meta::FIRST_START,
				'value'   => $to->modify( '+1 day' )->getTimestamp(),
				'compare' => '<',
				'type'    => 'NUMERIC',
			);
		}

		if ( $attributes['prefecture'] > 0 ) {
			$meta_query[] = array(
				'key'   => Event_Meta::PREFECTURE,
				'value' => $attributes['prefecture'],
				'type'  => 'NUMERIC',
			);
		}

		if ( ! empty( $attributes['eventCategory'] ) ) {
			$meta_query[] = array(
				'key'   => Event_Meta::CATEGORY,
				'value' => (int) $attributes['eventCategory'],
				'type'  => 'NUMERIC',
			);
		}

		$tax_query = array();
		foreach ( array(
			Post_Type::TAX_CATEGORY => $attributes['categoryId'],
			Post_Type::TAX_TAG      => $attributes['tagId'],
		) as $taxonomy => $term_id ) {
			if ( $term_id > 0 ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'terms'    => $term_id,
				);
			}
		}

		$order = 'desc' === $attributes['order'] ? 'DESC' : 'ASC';
		// 開催予定の並びは下の ids_in_period() が決めるので、ここでは SQL に並べさせない。
		$orderby = $is_past
			? array(
				'eventwatch_ends' => 'DESC',
				'ID'              => 'DESC',
			)
			: 'none';

		// phpcs:disable WordPress.DB.SlowDBQuery -- 判定と絞り込みはメタとタームにしか持てない。
		$args = array(
			'post_type'           => Post_Type::POST_TYPE,
			// ログイン中でも、下書きや非公開を公開のページに混ぜない。
			'post_status'         => 'publish',
			// メタがイベントの中身そのものなので、パスワードを知らない人に出さない。テンプレートへ渡す前に落とす。
			'has_password'        => false,
			'posts_per_page'      => $attributes['perPage'],
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			'meta_query'          => $meta_query,
			'tax_query'           => $tax_query,
			self::KEYWORD_VAR     => empty( $attributes['keyword'] ) ? array() : array_values( $attributes['keyword'] ),
			'orderby'             => $orderby,
		);
		// phpcs:enable

		if ( ! $is_past ) {
			$args['post__in'] = self::ids_in_period(
				$args,
				max( Event_Dates::upcoming_boundary(), null !== $from ? $from->getTimestamp() : 0 ),
				null !== $to ? $to->modify( '+1 day' )->getTimestamp() : PHP_INT_MAX,
				$order
			);
			$args['orderby']  = 'post__in';
		}

		return new WP_Query( $args );
	}

	/**
	 * 範囲に重なる組を持つイベントの ID を、範囲に入る最初の組の順に返す。
	 *
	 * メタの比較は最初の開始と最後の終了しか見ないので、組と組の間の期間にも当たり、並びも過ぎた回の順になる。
	 * 「今月」と見出しを付けて来月の回だけのイベントを出したり、件数を絞った「近日」で直近のイベントを押し出したりしないよう、組ごとに確かめる。
	 *
	 * @param array  $args  絞り込みの条件を入れた WP_Query の引数。
	 * @param int    $from  範囲の始まりの UTC の整数。
	 * @param int    $until 範囲の終わりの UTC の整数。この時刻は含まない。
	 * @param string $order ASC か DESC。
	 * @return int[] 無ければ、どの投稿にも当たらない値。
	 */
	private static function ids_in_period( array $args, $from, $until, $order ) {
		$query = new WP_Query(
			array_merge(
				$args,
				array(
					'fields'         => 'ids',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ページに分ける前に組で絞るので、期間に当たるものを全部読む。
					'posts_per_page' => -1,
					'paged'          => 1,
					'no_found_rows'  => true,
				)
			)
		);

		// fields => ids はメタを読み込まないので、1 件ごとに問い合わせないよう先に読む。
		update_meta_cache( 'post', $query->posts );

		$found = array();
		foreach ( $query->posts as $post_id ) {
			$start = Event_Dates::first_start_between( (int) $post_id, $from, $until );
			if ( null !== $start ) {
				$found[] = array( $start, (int) $post_id );
			}
		}

		// 開始と ID で比べる。同じ開始でもページをまたいで順が動かない。
		usort(
			$found,
			static function ( array $a, array $b ) use ( $order ) {
				return 'DESC' === $order ? $b <=> $a : $a <=> $b;
			}
		);

		return $found ? array_column( $found, 1 ) : array( 0 );
	}

	/**
	 * キーワードの条件を足す。
	 *
	 * 本体の検索はメタを見ないので、会場名に当てるために自分で組む。語はすべて含むものに当てる。
	 *
	 * @param string   $where WHERE の文。
	 * @param WP_Query $query クエリ。
	 * @return string
	 */
	public static function filter_where( $where, $query ) {
		$terms = $query->get( self::KEYWORD_VAR );
		if ( ! is_array( $terms ) || ! $terms ) {
			return $where;
		}

		global $wpdb;
		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( (string) $term ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- 表の名前は $wpdb の値で、語は prepare を通している。
			$where .= $wpdb->prepare( " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS eventwatch_venue WHERE eventwatch_venue.post_id = {$wpdb->posts}.ID AND eventwatch_venue.meta_key = %s AND eventwatch_venue.meta_value LIKE %s))", $like, $like, Event_Meta::VENUE_NAME, $like );
		}

		return $where;
	}

	/**
	 * テンプレートへ渡す 1 件のデータ。
	 *
	 * @param WP_Post  $post イベント。
	 * @param int|null $from 期間で絞っているときの始まり。
	 * @return array{id: int, title: string, url: string, image: string, date: string, datetime: string, more: string, venue: string, category: string, ended: bool}
	 */
	public static function item_data( WP_Post $post, $from = null ) {
		$summary = Event_Dates::summary( $post->ID, $from );
		$more    = $summary ? $summary['more'] : 0;

		return array(
			'id'       => $post->ID,
			// カード全体がリンクなので、題の中のリンクが入れ子にならないようタグを落とす。
			'title'    => wp_strip_all_tags( get_the_title( $post ) ),
			'url'      => (string) get_permalink( $post ),
			'image'    => self::image_html( $post ),
			'date'     => $summary ? $summary['text'] : '',
			'datetime' => $summary ? $summary['datetime'] : '',
			'more'     => $more > 0
				/* translators: %d: the number of other dates of the event. */
				? sprintf( _n( '+%d more date', '+%d more dates', $more, 'eventwatch' ), $more )
				: '',
			'venue'    => self::venue_text( $post->ID ),
			'category' => self::category_text( $post ),
			'ended'    => self::is_ended( $post->ID ),
		);
	}

	/**
	 * 最後の終了が境目より前か。query() の過去の条件と同じ判定。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return bool
	 */
	public static function is_ended( $post_id ) {
		$last_end = get_post_meta( $post_id, Event_Meta::LAST_END, true );

		return '' !== $last_end && (int) $last_end < Event_Dates::upcoming_boundary();
	}

	/**
	 * 1 件を描く。テーマに同名のテンプレートがあれば、そちらを使う。
	 *
	 * the_post を呼ばない。入れ子のクエリで、固定ページの本文のループを壊さないため。
	 *
	 * @param array   $attributes normalize() を通した属性。
	 * @param WP_Post $post       イベント。
	 * @return string
	 */
	private static function render_item( array $attributes, WP_Post $post ) {
		$name     = self::LAYOUT_LIST === $attributes['layout'] ? 'event-row.php' : 'event-card.php';
		$template = locate_template( self::THEME_DIR . '/' . $name );
		if ( '' === $template ) {
			$template = plugin_dir_path( PLUGIN_FILE ) . 'templates/' . $name;
		}

		ob_start();
		$from = self::parse_date( $attributes['dateFrom'] );
		load_template( $template, false, self::item_data( $post, $from ? $from->getTimestamp() : null ) );
		return (string) ob_get_clean();
	}

	/**
	 * 今の要求のパスとクエリ。
	 *
	 * REQUEST_URI は `//example.com/` の形で届くことがあり、そのままリンクにすると別のサイトを指す。パスとクエリだけを取り出す。
	 *
	 * @return string
	 */
	public static function current_uri() {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$parts = wp_parse_url( $uri );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '';

		return '/' . ltrim( $path, '/' ) . ( is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * ページ送り。1 ページしかなければ出さない。
	 *
	 * クエリの名前は属性ごとに分かれるので、1 ページに複数置いても互いに動かない。属性まで同じブロックは連動する。
	 *
	 * @param string $param  ページのクエリの名前。
	 * @param string $anchor ブロックの id。移った先で一覧の頭に着地する。
	 * @param int    $page   今のページ。
	 * @param int    $total  ページの数。
	 * @return string
	 */
	private static function render_pagination( $param, $anchor, $page, $total ) {
		if ( $total < 2 ) {
			return '';
		}

		$links = paginate_links(
			array(
				'base'         => add_query_arg( $param, '%#%', self::current_uri() ),
				'format'       => '',
				'current'      => $page,
				'total'        => $total,
				'type'         => 'plain',
				'add_fragment' => '#' . $anchor,
				'prev_text'    => __( 'Previous', 'eventwatch' ),
				'next_text'    => __( 'Next', 'eventwatch' ),
			)
		);

		return '<nav class="eventwatch-events__pagination" aria-label="' . esc_attr__( 'Pagination', 'eventwatch' ) . '">' . $links . '</nav>';
	}

	/**
	 * アイキャッチ画像の HTML。無ければ空。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	private static function image_html( WP_Post $post ) {
		$thumbnail_id = (int) get_post_thumbnail_id( $post );
		if ( ! $thumbnail_id ) {
			return '';
		}

		$alt = trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );

		return (string) get_the_post_thumbnail(
			$post,
			'medium_large',
			array(
				'alt'     => '' !== $alt ? $alt : wp_strip_all_tags( get_the_title( $post ) ),
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * 会場の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	public static function venue_text( $post_id ) {
		if ( get_post_meta( $post_id, Event_Meta::ONLINE, true ) ) {
			return __( 'Online', 'eventwatch' );
		}

		$venue = (string) get_post_meta( $post_id, Event_Meta::VENUE_NAME, true );

		return '' !== $venue ? $venue : __( 'Venue to be announced', 'eventwatch' );
	}

	/**
	 * カテゴリの文。サイトのカテゴリがあればそれ、無ければ EventWatch のカテゴリ。
	 *
	 * @param WP_Post $post イベント。
	 * @return string
	 */
	public static function category_text( WP_Post $post ) {
		$terms = get_the_terms( $post, Post_Type::TAX_CATEGORY );
		if ( is_array( $terms ) && $terms ) {
			return $terms[0]->name;
		}

		$categories = Event_Options::categories();
		$category   = (int) get_post_meta( $post->ID, Event_Meta::CATEGORY, true );

		return isset( $categories[ $category ] ) ? $categories[ $category ] : '';
	}

	/**
	 * `YYYY-MM-DD` をサイトのタイムゾーンの 0 時にする。
	 *
	 * @param mixed $value 値。
	 * @return DateTimeImmutable|null 実在しない日や書式違いは null。
	 */
	public static function parse_date( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		// 2 月 30 日は 3 月 2 日に繰り上がるので、書式で戻して元の文字列と比べる。
		return $parsed && $parsed->format( 'Y-m-d' ) === $value ? $parsed : null;
	}
}
