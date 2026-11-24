<?php
/**
 * イベントの投稿タイプとタクソノミー。
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 投稿タイプとタクソノミーの登録を持つ。
 *
 * 公開・予約・非公開・ゴミ箱・リビジョンは WordPress の標準のまま効かせる。
 */
class Post_Type {

	const POST_TYPE    = 'eventwatch_event';
	const TAX_CATEGORY = 'eventwatch_event_cat';
	const TAX_TAG      = 'eventwatch_event_tag';

	/**
	 * 個別ページの接頭辞の既定。
	 */
	const REWRITE_SLUG = 'event';

	/**
	 * 個別ページの接頭辞の設定。空なら既定。
	 */
	const SLUG_OPTION = 'eventwatch_event_slug';

	/**
	 * いまの接頭辞。
	 *
	 * uninstall.php は本体を読み込まずにこのファイルだけを読むので、ほかのクラスに頼らない。
	 *
	 * @return string
	 */
	public static function rewrite_slug() {
		$slug = (string) get_option( self::SLUG_OPTION, '' );

		return '' !== $slug ? $slug : self::REWRITE_SLUG;
	}

	/**
	 * 投稿タイプとタクソノミーを登録する。
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'                   => __( 'Events', 'eventwatch' ),
					'singular_name'          => __( 'Event', 'eventwatch' ),
					'add_new_item'           => __( 'Add New Event', 'eventwatch' ),
					'edit_item'              => __( 'Edit Event', 'eventwatch' ),
					'new_item'               => __( 'New Event', 'eventwatch' ),
					'view_item'              => __( 'View Event', 'eventwatch' ),
					'view_items'             => __( 'View Events', 'eventwatch' ),
					'search_items'           => __( 'Search Events', 'eventwatch' ),
					'not_found'              => __( 'No events found.', 'eventwatch' ),
					'not_found_in_trash'     => __( 'No events found in Trash.', 'eventwatch' ),
					'all_items'              => __( 'All Events', 'eventwatch' ),
					'item_published'         => __( 'Event published.', 'eventwatch' ),
					'item_scheduled'         => __( 'Event scheduled.', 'eventwatch' ),
					'item_updated'           => __( 'Event updated.', 'eventwatch' ),
					'item_reverted_to_draft' => __( 'Event reverted to draft.', 'eventwatch' ),
				),
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => false,
				'menu_icon'       => 'dashicons-calendar-alt',
				'capability_type' => array( 'eventwatch_event', 'eventwatch_events' ),
				'map_meta_cap'    => true,
				'rewrite'         => array(
					'slug'       => self::rewrite_slug(),
					'with_front' => false,
				),
				// custom-fields が無いと、投稿のメタが REST に出ない。
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'custom-fields', 'author' ),
			)
		);

		// ターム別のアーカイブの URL を作らない。絞り込みはブロックが行う。
		$taxonomy_args = array(
			'public'            => false,
			'show_ui'           => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => false,
			'capabilities'      => array(
				'manage_terms' => 'edit_others_eventwatch_events',
				'edit_terms'   => 'edit_others_eventwatch_events',
				'delete_terms' => 'edit_others_eventwatch_events',
				'assign_terms' => 'edit_eventwatch_events',
			),
		);

		register_taxonomy(
			self::TAX_CATEGORY,
			self::POST_TYPE,
			array_merge(
				$taxonomy_args,
				array(
					'hierarchical' => true,
					'labels'       => array(
						'name'          => __( 'Event Categories', 'eventwatch' ),
						'singular_name' => __( 'Event Category', 'eventwatch' ),
					),
				)
			)
		);

		register_taxonomy(
			self::TAX_TAG,
			self::POST_TYPE,
			array_merge(
				$taxonomy_args,
				array(
					'hierarchical' => false,
					'labels'       => array(
						'name'          => __( 'Event Tags', 'eventwatch' ),
						'singular_name' => __( 'Event Tag', 'eventwatch' ),
					),
				)
			)
		);
	}

	/**
	 * 登録を外す。無効化で rewrite を作り直す前に呼ぶ。
	 *
	 * @return void
	 */
	public static function unregister() {
		unregister_taxonomy( self::TAX_CATEGORY );
		unregister_taxonomy( self::TAX_TAG );
		unregister_post_type( self::POST_TYPE );
	}

	/**
	 * テーマがアイキャッチに対応していなくても、この投稿タイプでは選べるようにする。
	 *
	 * @return void
	 */
	public static function add_thumbnail_support() {
		if ( current_theme_supports( 'post-thumbnails' ) && true === get_theme_support( 'post-thumbnails' ) ) {
			return;
		}

		$supported = get_theme_support( 'post-thumbnails' );
		$types     = is_array( $supported ) && isset( $supported[0] ) ? (array) $supported[0] : array();

		add_theme_support( 'post-thumbnails', array_merge( $types, array( self::POST_TYPE ) ) );
	}

	/**
	 * いまのサイトのイベントの投稿とタームをすべて消す。削除の設定を選んだときだけ呼ぶ。
	 *
	 * 登録されていないタクソノミーのタームの結び付きは wp_delete_post では消えないので、先に登録しておく。
	 *
	 * @return void
	 */
	public static function delete_all_content() {
		self::register();

		do {
			$post_ids = get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => array_values( get_post_stati() ),
					'numberposts'      => 100,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'suppress_filters' => true,
				)
			);

			$deleted = 0;
			foreach ( $post_ids as $post_id ) {
				if ( wp_delete_post( $post_id, true ) ) {
					++$deleted;
				}
			}
			// 消せない投稿が残っても、同じ投稿を引き続けない。
		} while ( $deleted > 0 );

		$terms = get_terms(
			array(
				'taxonomy'   => array( self::TAX_CATEGORY, self::TAX_TAG ),
				'hide_empty' => false,
			)
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				wp_delete_term( $term->term_id, $term->taxonomy );
			}
		}
	}
}
