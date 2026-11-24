<?php
/**
 * 取り込める投稿の表。
 *
 * @package EventWatch
 */

namespace EventWatch;

use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * 管理画面の一覧の作法で並べる。一括の操作の代わりに、取り込みのボタンと選んだ数を上に置く。
 */
class Import_List_Table extends WP_List_Table {

	/**
	 * 読めたときの一覧。
	 *
	 * @param array $listed Event_Import::list_page の戻り。
	 */
	public function __construct( array $listed = array(
		'list'  => array(),
		'total' => 0,
	) ) {
		parent::__construct(
			array(
				'plural'   => 'eventwatch-import',
				'singular' => 'eventwatch-import-item',
				'ajax'     => false,
				'screen'   => Import_Admin::PAGE,
			)
		);

		$this->items = $listed['list'];
		$this->set_pagination_args(
			array(
				'total_items' => $listed['total'],
				'per_page'    => Event_Import::PAGE_SIZE,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
	}

	/**
	 * 列。
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'title'     => esc_html__( 'Event name', 'eventwatch' ),
			'date'      => esc_html__( 'Event date', 'eventwatch' ),
			'timing'    => esc_html__( 'Timing', 'eventwatch' ),
			'state'     => esc_html__( 'EventWatch status', 'eventwatch' ),
			'wordpress' => 'WordPress',
		);
	}

	/**
	 * 空のときの文。
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'There are no posts to import. Posts your organizer creates on EventWatch will appear here.', 'eventwatch' );
	}

	/**
	 * 行。JS が取り込みの結果で書き換えるので、投稿の ID を持たせる。
	 *
	 * @param array $item 投稿。
	 * @return void
	 */
	public function single_row( $item ) {
		printf( '<tr data-eventwatch-event="%s">', esc_attr( (string) $item['id'] ) );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * 選ぶ欄。
	 *
	 * @param array $item 投稿。
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="eventwatch-import-%1$s">%2$s</label><input type="checkbox" id="eventwatch-import-%1$s" name="eventwatch_import[]" value="%1$s" />',
			esc_attr( (string) $item['id'] ),
			/* translators: %s: the event name. */
			esc_html( sprintf( __( 'Select “%s”', 'eventwatch' ), (string) $item['title'] ) )
		);
	}

	/**
	 * イベント名。
	 *
	 * @param array $item 投稿。
	 * @return string
	 */
	public function column_title( $item ) {
		return '<strong>' . esc_html( (string) $item['title'] ) . '</strong>';
	}

	/**
	 * 開催日。
	 *
	 * @param array $item 投稿。
	 * @return string
	 */
	public function column_date( $item ) {
		$summary = Event_Dates::summary_of_rows( Event_Import::occurrence_rows( $item ), Event_Import::zone_of( $item ) );

		return $summary ? esc_html( $summary['text'] ) : '—';
	}

	/**
	 * 開催予定か終了か。
	 *
	 * @param array $item 投稿。
	 * @return string
	 */
	public function column_timing( $item ) {
		return Event_Import::has_ended( $item ) ? esc_html__( 'Ended', 'eventwatch' ) : esc_html__( 'Upcoming', 'eventwatch' );
	}

	/**
	 * EventWatch の状態。
	 *
	 * @param array $item 投稿。
	 * @return string
	 */
	public function column_state( $item ) {
		$status = Sync_Rest::event_status( $item );

		ob_start();
		Admin_Columns::print_state( $status['state'], $status['label'] );
		if ( '' !== $status['reason'] ) {
			printf(
				'<span class="eventwatch-sync-reason" title="%1$s">%2$s</span>',
				esc_attr( $status['reason'] ),
				esc_html( wp_html_excerpt( $status['reason'], Admin_Columns::REASON_LENGTH, '…' ) )
			);
		}

		return (string) ob_get_clean();
	}

	/**
	 * WordPress の投稿。作りかけの下書きか、このサイトが前に送った投稿があれば、その投稿へのリンク。
	 *
	 * @param array $item 投稿。
	 * @return string
	 */
	public function column_wordpress( $item ) {
		$post_id = Event_Import::find_local( (string) $item['id'] );
		$post_id = $post_id ? $post_id : Event_Import::own_post_id( $item );

		if ( ! $post_id ) {
			return '<span class="eventwatch-import__none">—</span>';
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ), esc_html__( 'Edit post', 'eventwatch' ) );
	}

	/**
	 * 一括の操作の代わりに、取り込みのボタンを置く。JS が動かしてから押せるようにする。
	 *
	 * @param string $which 上か下か。
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which || ! $this->items ) {
			return;
		}
		?>
		<div class="alignleft actions eventwatch-import__actions">
			<button type="button" class="button button-primary" data-eventwatch-import-start disabled><?php esc_html_e( 'Import selected', 'eventwatch' ); ?></button>
			<span data-eventwatch-import-count><?php esc_html_e( 'Select posts to import', 'eventwatch' ); ?></span>
		</div>
		<?php
	}
}
