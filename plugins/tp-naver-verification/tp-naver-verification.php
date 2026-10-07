<?php
/**
 * Plugin Name: TP Naver Verification
 * Description: 네이버 서치어드바이저 사이트 소유확인용 메타 태그, 연관 채널 마크업, 영문·한글 사이트 이름을 <head>에 넣는다.
 * Version: 1.2.0
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

/**
 * 네이버 "연관 채널" 마크업. 네이버 가이드가 루트 페이지에 넣으라고 해서 홈에서만 출력한다.
 *
 * Jetpack이 이미 Organization JSON-LD를 내고 있으므로, 같은 @id를 써서
 * 검색엔진이 "두 번째 회사"가 아니라 "같은 회사의 추가 정보"로 합쳐 읽게 한다.
 */
function tp_naver_channel_jsonld() {
	if ( ! is_front_page() ) {
		return;
	}

	$data = array(
		'@context'      => 'https://schema.org',
		'@type'         => 'Organization',
		'@id'           => 'https://trendportal.kr/#organization',
		'name'          => '트렌드포털',
		'alternateName' => 'TRENDPORTAL',
		'url'           => 'https://trendportal.kr/',
		'sameAs'        => array(
			'https://www.instagram.com/trend__portal/',
			'https://blog.naver.com/trendportal',
		),
	);

	// 한글·슬래시를 \u 이스케이프 없이 그대로 내보내야 사람이 HTML을 열어 봐도 읽힌다.
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', 'tp_naver_channel_jsonld' );

/**
 * 네이버가 사이트 이름 자리에 "트렌드포털" 대신 도메인을 띄워서, og:site_name을 영문+한글로 바꾼다.
 * 어피티("UPPITY 어피티")처럼 영문 검색어와 한글 이름을 한 줄에 같이 두려는 것.
 *
 * 설정의 사이트 제목을 바꾸면 헤더 로고 글자와 브라우저 탭 제목까지 바뀌므로,
 * Jetpack이 내보내는 OG 태그 중 이 값 하나만 필터로 덮어쓴다.
 */
function tp_naver_og_site_name( $tags ) {
	$tags['og:site_name'] = 'TRENDPORTAL 트렌드포털';
	return $tags;
}
add_filter( 'jetpack_open_graph_tags', 'tp_naver_og_site_name' );
