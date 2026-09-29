<?php
/**
 * Plugin Name: TP Naver Verification
 * Description: 네이버 서치어드바이저 사이트 소유확인용 메타 태그를 <head>에 넣는다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress.com은 사이트 루트에 인증 파일을 올릴 수 없고, Jetpack 인증 메뉴에도 네이버 칸이 없어서
 * 메타 태그 방식을 플러그인으로 넣는다.
 *
 * 네이버는 소유확인 뒤에도 이 태그가 남아 있어야 소유 연장이 되므로, 확인이 끝나도 플러그인을 끄지 않는다.
 * 이 값은 비밀이 아니다(모든 방문자에게 HTML로 보이는 공개 값).
 */
function tp_naver_verification_meta() {
	echo '<meta name="naver-site-verification" content="aad5dd92518f183966289a82c544d21f2dd7b269" />' . "\n";
}
add_action( 'wp_head', 'tp_naver_verification_meta', 1 );
