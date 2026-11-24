<?php
/**
 * イベントの一覧の画面の列。
 *
 * @package EventWatch
 */

namespace EventWatch;

use DateTimeZone;
use Exception;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 開催日・都道府県・EventWatch の状態の列と、開催日での並べ替えを持つ。
 */
class Admin_Columns {

	const COLUMN_DATE       = 'eventwatch_date';
	const COLUMN_PREFECTURE = 'eventwatch_prefecture';
	const COLUMN_SYNC       = 'eventwatch_sync';
	const ORDERBY           = 'eventwatch_first_start';

	/**
	 * 列の理由の行の字数。全文は title に持つ。
	 */
	const REASON_LENGTH = 40;

	const ICONS = array(
		'check'   => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
		'clock'   => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
		'minus'   => 'M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
		'pause'   => 'M14.25 9v6m-4.5 0V9M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
		'pencil'  => 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10',
		'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
	);

	/**
	 * 状態ごとのアイコンと色。
	 */
	const LOOKS = array(
		'unsent'    => array( 'minus', 'muted' ),
		'queued'    => array( 'clock', 'muted' ),
		'review'    => array( 'clock', 'info' ),
		'live'      => array( 'check', 'success' ),
		'fix'       => array( 'pencil', 'warning' ),
		'stopped'   => array( 'pause', 'muted' ),
		'withdrawn' => array( 'minus', 'muted' ),
		'failed'    => array( 'warning', 'error' ),
		'off'       => array( 'minus', 'muted' ),
	);

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function boot() {
		$type = Post_Type::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'add_columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( self::class, 'add_sortable' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_order' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * タイトルの直後に列を足す。
	 *
	 * @param array<string, string> $columns 列。
	 * @return array<string, string>
	 */
	public static function add_columns( $columns ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result[ self::COLUMN_DATE ]       = esc_html__( 'Event date', 'eventwatch' );
				$result[ self::COLUMN_PREFECTURE ] = esc_html__( 'Prefecture', 'eventwatch' );
				// キーなしのサイトでは騒がない。
				if ( '' !== Connection::api_key() ) {
					$result[ self::COLUMN_SYNC ] = 'EventWatch';
				}
			}
		}
		return $result;
	}

	/**
	 * 列の中身を出す。
	 *
	 * @param string $column  列。
	 * @param int    $post_id 投稿の ID。
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN_DATE === $column ) {
			echo esc_html( self::date_text( (int) $post_id ) );
		} elseif ( self::COLUMN_PREFECTURE === $column ) {
			echo esc_html( self::prefecture_text( (int) $post_id ) );
		} elseif ( self::COLUMN_SYNC === $column ) {
			self::render_sync( (int) $post_id );
		}
	}

	/**
	 * 一覧の画面にだけ、状態の列の見た目を足す。
	 *
	 * @param string $hook 画面。
	 * @return void
	 */
	public static function enqueue_style( $hook ) {
		$screen = get_current_screen();
		if ( 'edit.php' !== $hook || ! $screen || Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', self::cell_style() );
	}

	/**
	 * 状態の列の見た目。
	 *
	 * @return string
	 */
	public static function cell_style() {
		return '.eventwatch-sync-cell{display:inline-flex;align-items:flex-start;gap:4px;font-weight:500}'
			. '.eventwatch-sync-cell svg{flex:none}'
			. '.eventwatch-sync-cell--muted{color:#50575e}'
			. '.eventwatch-sync-cell--info{color:#2271b1}'
			. '.eventwatch-sync-cell--success{color:#007017}'
			. '.eventwatch-sync-cell--warning{color:#996800}'
			. '.eventwatch-sync-cell--error{color:#cc1818}'
			. '.eventwatch-sync-reason{display:block;margin-top:2px;color:#50575e}';
	}

	/**
	 * 状態の列を出す。語と理由は編集画面と同じものを読む。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return void
	 */
	private static function render_sync( $post_id ) {
		$status = Sync_Rest::status( $post_id );

		self::print_state( $status['state'], $status['label'] );

		// 一覧には編集できない投稿も並ぶ。差し戻しの理由は編集できる人にだけ見せる。
		$reason = $status['reason']['text'];
		if ( '' !== $reason && current_user_can( 'edit_post', $post_id ) ) {
			printf(
				'<span class="eventwatch-sync-reason" title="%1$s">%2$s</span>',
				esc_attr( $reason ),
				esc_html( wp_html_excerpt( $reason, self::REASON_LENGTH, '…' ) )
			);
		}
	}

	/**
	 * 状態のアイコンと語を出す。
	 *
	 * @param string $state 状態の種類。
	 * @param string $label 語。
	 * @return void
	 */
	public static function print_state( $state, $label ) {
		$look = self::LOOKS[ $state ];

		printf(
			'<span class="eventwatch-sync-cell eventwatch-sync-cell--%1$s"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="%2$s"></path></svg>%3$s</span>',
			esc_attr( $look[1] ),
			esc_attr( self::ICONS[ $look[0] ] ),
			esc_html( $label )
		);
	}

	/**
	 * 並べ替えられる列を足す。
	 *
	 * @param array<string, string> $columns 列。
	 * @return array<string, string>
	 */
	public static function add_sortable( $columns ) {
		$columns[ self::COLUMN_DATE ] = self::ORDERBY;
		return $columns;
	}

	/**
	 * 開催日で並べ替える。
	 *
	 * meta_key で並べると、メタの無い投稿が結果から消える。日程の無い下書きを一覧に残すため、NOT EXISTS と OR にする。
	 *
	 * @param WP_Query $query クエリ。
	 * @return void
	 */
	public static function apply_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::ORDERBY !== $query->get( 'orderby' ) ) {
			return;
		}
		if ( Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理画面の一覧の並べ替えだけで使う。
		$query->set(
			'meta_query',
			array(
				'relation'        => 'OR',
				// 並べ替えに使う節。JOIN にメタのキーの条件が付くのは NOT EXISTS の節だけなので、こちらに名前を付ける。
				// EXISTS の節で並べると、日程の無い投稿はほかのメタの値で並ぶ。
				'eventwatch_date' => array(
					'key'     => Event_Meta::FIRST_START,
					'compare' => 'NOT EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => Event_Meta::FIRST_START,
					'compare' => 'EXISTS',
				),
			)
		);
		// phpcs:enable
		$query->set( 'orderby', array( 'eventwatch_date' => 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC' ) );
	}

	/**
	 * 開催日の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function date_text( $post_id ) {
		$rows      = get_post_meta( $post_id, Event_Meta::OCCURRENCES, true );
		$timestamp = get_post_meta( $post_id, Event_Meta::FIRST_START, true );

		if ( ! is_array( $rows ) || ! $rows || '' === $timestamp ) {
			return '—';
		}

		try {
			$zone = new DateTimeZone( (string) get_post_meta( $post_id, Event_Meta::ZONE, true ) );
		} catch ( Exception $e ) {
			$zone = wp_timezone();
		}

		$text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp, $zone );
		$more = count( $rows ) - 1;

		if ( $more > 0 ) {
			/* translators: 1: the first date of the event, 2: the number of other dates. */
			return sprintf( _n( '%1$s (+%2$d more date)', '%1$s (+%2$d more dates)', $more, 'eventwatch' ), $text, $more );
		}

		return (string) $text;
	}

	/**
	 * 都道府県の文。
	 *
	 * @param int $post_id 投稿の ID。
	 * @return string
	 */
	private static function prefecture_text( $post_id ) {
		if ( get_post_meta( $post_id, Event_Meta::ONLINE, true ) ) {
			return __( 'Online', 'eventwatch' );
		}

		$prefectures = Event_Options::prefectures();
		$code        = (int) get_post_meta( $post_id, Event_Meta::PREFECTURE, true );

		return isset( $prefectures[ $code ] ) ? $prefectures[ $code ] : '—';
	}
}
