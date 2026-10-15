<?php
/**
 * Plugin Name: TP No Comment Popup
 * Description: 댓글을 단 뒤 뜨는 Jetpack 구독 창을 끈다. 이 창은 WordPress.com 구독자 명단으로 받아서, 스티비 구독 양식과 개인정보·광고 수신 동의를 건너뛴다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 왜 필요한가 (2026-10-15)
 *
 * 이 창은 뉴스레터 설정의 스위치들(구독 팝업·오버레이 등)과 상관없이 jetpack_verbum_subscription_modal 옵션 하나로 켜진다.
 * 기본값이 "켜짐"인데 wp-admin·WordPress.com 설정 화면 어디에도 끄는 스위치가 없어서, 지금까지는 CSS로 숨기기만 했다.
 * 숨겨도 구독 양식 HTML은 글마다 실려 나갔다.
 *
 * pre_option_{옵션 이름} 필터는 DB를 읽기 전에 값을 대신 돌려준다. false가 아닌 값을 돌려줘야 그 값이 쓰이므로
 * 0을 돌려준다. Jetpack이 get_option()으로 읽으면 0(꺼짐)이 되어 창 HTML 자체를 내보내지 않는다.
 * 플러그인을 끄면 원래 설정(켜짐)으로 돌아간다.
 */
add_filter( 'pre_option_jetpack_verbum_subscription_modal', '__return_zero' );
