<?php
/**
 * イベント 1 件のリストの行。
 *
 * テーマの eventwatch/event-row.php を置くと差し替えられる。
 * $args の値は Event_List::item_data() を参照。image は HTML。title はタグの無い文字列だが実体参照を含むので wp_kses_post で出す。
 *
 * 読み上げがイベント名から始まるよう、DOM ではイベント名を先に置き、見た目の順は CSS で決める。
 *
 * @package EventWatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<a class="eventwatch-event eventwatch-event--row<?php echo '' !== $args['image'] ? ' has-image' : ''; ?><?php echo ! empty( $args['ended'] ) ? ' is-ended' : ''; ?>" href="<?php echo esc_url( $args['url'] ); ?>">
	<div class="eventwatch-event__body">
		<h3 class="eventwatch-event__title"><?php echo wp_kses_post( $args['title'] ); ?></h3>
		<p class="eventwatch-event__date">
			<?php if ( ! empty( $args['ended'] ) ) : ?>
				<span class="eventwatch-event__ended"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg><?php echo esc_html_x( 'Ended', 'label on an event that has ended', 'eventwatch' ); ?></span>
			<?php endif; ?>
			<time datetime="<?php echo esc_attr( $args['datetime'] ); ?>"><?php echo esc_html( $args['date'] ); ?></time>
			<?php if ( '' !== $args['more'] ) : ?>
				<span class="eventwatch-event__more"><?php echo esc_html( $args['more'] ); ?></span>
			<?php endif; ?>
		</p>
		<p class="eventwatch-event__venue"><?php echo esc_html( implode( _x( ' · ', 'separator between the venue and the category', 'eventwatch' ), array_filter( array( $args['venue'], $args['category'] ) ) ) ); ?></p>
	</div>
	<?php if ( '' !== $args['image'] ) : ?>
		<div class="eventwatch-event__media"><?php echo wp_kses( $args['image'], \EventWatch\Event_List::image_allowed_html() ); ?></div>
	<?php endif; ?>
</a>
