<?php
/**
 * Plugin Name: TP Card Sheet
 * Description: 폰에서 목록 카드를 누르면 그 자리에서 글이 펼쳐지는 시트(눌림 반응·끌어 닫기·뒤로 가기 포함). 시트 안은 진짜 글 페이지(iframe)라서 댓글·카톡·관련 글·구독·조회수가 그대로 동작한다. PC(폭 720px 이상)에서는 아무것도 하지 않고 카드가 원래 링크대로 글 페이지로 이동한다. 목록 아래 페이지 이동(1 2 3 · 다음 페이지)도 폰에서 누르기 쉬운 모양으로 바꾼다. 이 플러그인을 끄면 모든 것이 예전으로 돌아간다.
 * Version: 1.0.2
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TP_CARD_SHEET_VERSION', '1.0.2' );

/*
 * 카드 목록이 있는 화면(홈·분류·검색)에서만 불러온다. 글 페이지에는 불필요하다.
 * 시트가 안쪽에 띄우는 글 페이지(iframe)에도 불러오지 않으므로, 시트 안에서 시트가 또 열리는 일이 없다.
 */
function tp_card_sheet_enqueue() {
	if ( is_singular() || is_admin() ) {
		return;
	}
	wp_enqueue_style( 'tp-card-sheet', plugins_url( 'card-sheet.css', __FILE__ ), array(), TP_CARD_SHEET_VERSION );
	wp_enqueue_script(
		'tp-card-sheet',
		plugins_url( 'card-sheet.js', __FILE__ ),
		array(),
		TP_CARD_SHEET_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'tp_card_sheet_enqueue' );
