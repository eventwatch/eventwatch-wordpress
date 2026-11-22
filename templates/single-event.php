<?php
/**
 * クラシックテーマのイベント詳細のページ。
 *
 * テーマの eventwatch/single-event.php か single-eventwatch_event.php を置くと差し替えられる。
 * 欄だけを使うなら、EventWatch\Event_Detail::default_content() の中のブロックを do_blocks で描く。
 *
 * @package EventWatch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) {
	the_post();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content と同じく、本体がブロックを描いた HTML。各ブロックは値ごとにエスケープしている。地図の iframe・SVG・dialog を含み、wp_kses で包むと壊れる。
	echo do_blocks( \EventWatch\Event_Detail::default_content( 'article' ) );
}

get_footer();
