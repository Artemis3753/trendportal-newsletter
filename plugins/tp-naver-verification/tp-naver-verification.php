<?php
/**
 * Plugin Name: TP Naver Verification
 * Description: 네이버 서치어드바이저 사이트 소유확인용 메타 태그와 연관 채널 마크업을 <head>에 넣고, 홈의 WebSite 구조화 데이터에 영문 이름(alternateName)을 더한다.
 * Version: 1.3.0
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

/*
 * 사이트 이름 (1.3.0, 2026-10-15)
 *
 * 구글·네이버가 검색 결과의 사이트 이름 자리에 "트렌드포털" 대신 도메인을 띄운다.
 * 구글 안내는 ① 홈의 이름 신호를 일치시키고 ② WebSite 구조화 데이터에 alternateName을 더하라는 것.
 *
 * ① 1.2.0에서 og:site_name을 "TRENDPORTAL 트렌드포털"로 덮어썼는데, 그러면 WebSite 이름·<title>의
 *    "트렌드포털"과 어긋난다. 덮어쓰던 필터를 지워 Jetpack 기본값(사이트 제목 "트렌드포털")으로 돌린다.
 * ② 헤더 로고 글자는 "TRENDPORTAL"이라, 이것을 같은 사이트의 다른 이름으로 알린다.
 *    구글은 WebSite 블록을 하나 더 만들지 말고 기존 것 안에 넣으라고 하는데, Jetpack의 WebSite 노드에는
 *    값을 바꿀 필터가 없다(Jetpack 16.3.1 jetpack-seo 패키지 Website_Schema_Node 확인).
 *    그래서 홈에서만 Jetpack이 wp_head 우선순위 5에 내보내는 출력을 4~6 사이에서 받아,
 *    @graph 안의 WebSite 노드에 alternateName만 넣고 그대로 다시 내보낸다.
 *    모양이 예상과 다르면(파싱 실패, @graph 없음, WebSite 없음) 받은 출력을 손대지 않고 내보낸다.
 */
function tp_site_name_capture_start() {
	if ( is_front_page() ) {
		ob_start();
	}
}
add_action( 'wp_head', 'tp_site_name_capture_start', 4 );

function tp_site_name_capture_end() {
	if ( ! is_front_page() ) {
		return;
	}
	$html = ob_get_clean();
	if ( false === $html ) {
		return;
	}
	echo preg_replace_callback( '#<script type="application/ld\+json">(.*?)</script>#s', 'tp_site_name_add_alternate', $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Jetpack이 만든 출력을 그대로 돌려준다
}
add_action( 'wp_head', 'tp_site_name_capture_end', 6 );

function tp_site_name_add_alternate( $match ) {
	$doc = json_decode( $match[1], true );
	if ( ! is_array( $doc ) || empty( $doc['@graph'] ) || ! is_array( $doc['@graph'] ) ) {
		return $match[0];
	}
	$changed = false;
	foreach ( $doc['@graph'] as $i => $node ) {
		if ( is_array( $node ) && 'WebSite' === ( $node['@type'] ?? '' ) && empty( $node['alternateName'] ) ) {
			$doc['@graph'][ $i ]['alternateName'] = 'TRENDPORTAL';
			$changed = true;
		}
	}
	if ( ! $changed ) {
		return $match[0];
	}
	// Jetpack과 같은 방식으로 인코딩한다: 한글은 그대로, 슬래시는 이스케이프("</script>"로 블록이 끊기지 않게)
	return '<script type="application/ld+json">' . wp_json_encode( $doc, JSON_UNESCAPED_UNICODE ) . '</script>';
}
