<?php
/**
 * Plugin Name: TP View Count
 * Description: 글 목록(홈 뉴스·카테고리 등)의 날짜 옆에 "· 조회 1,234"를 붙인다. 숫자는 Jetpack 통계의 글별 누적 조회수, 1시간마다 새로 받는다. 주간 총정리 글은 빼고, 숫자를 못 읽으면 아무것도 안 붙인다.
 * Version: 1.0.1
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/18 사용자 결정: 카드뉴스 글은 조회수를 숨기지 않고 표시, 주간 총정리는 제외.
 * 조회수 기준은 9/29에 정한 F-10 그대로 = Jetpack 기본 통계, 발행일부터 누적.
 *
 * 자리: 목록 카드마다 있는 날짜 블록(core/post-date) 뒤. 템플릿은 고치지 않는다.
 * 글 목록(Query Loop) 안의 날짜만 바꾸고, 글 본문 페이지 맨 위 날짜는 건드리지 않는다.
 */

const TP_VC_WEEKLY_SLUG = 'weekly'; // 주간 총정리 태그 (TP Related Posts와 같은 값)

/**
 * 글 하나의 누적 조회수. 못 읽으면 -1.
 * 통계는 사이트 밖(WordPress.com)에서 받아 오는 값이라 매번 부르면 목록이 느려진다 → 1시간 저장해 둔다.
 * 12개가 한꺼번에 만료돼 한 번에 몰리지 않게 만료 시각을 0~10분 흩는다.
 */
function tp_vc_views( $post_id ) {
	$key    = 'tp_vc_' . $post_id;
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	$views = null;
	// 요즘 Jetpack: Stats 패키지의 WPCOM_Stats가 글별 누적 조회수를 준다
	if ( class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
		$data = ( new \Automattic\Jetpack\Stats\WPCOM_Stats() )->get_post_views( $post_id );
		if ( is_array( $data ) && isset( $data['views'] ) ) {
			$views = (int) $data['views'];
		}
	}
	// 옛 Jetpack에만 있는 함수. 위 방법이 없을 때의 대비
	if ( null === $views && function_exists( 'stats_get_csv' ) ) {
		$rows = stats_get_csv( 'postviews', array( 'post_id' => $post_id, 'days' => -1 ) );
		if ( is_array( $rows ) && isset( $rows[0]['views'] ) ) {
			$views = (int) $rows[0]['views'];
		}
	}

	$views = null === $views ? -1 : $views;
	set_transient( $key, $views, HOUR_IN_SECONDS + wp_rand( 0, 10 * MINUTE_IN_SECONDS ) );
	return $views;
}

add_filter(
	'render_block_core/post-date',
	function ( $html, $block, $instance ) {
		if ( empty( $instance->context['postId'] ) ) {
			return $html;
		}
		$post_id = (int) $instance->context['postId'];
		// 글 본문 페이지 맨 위의 그 글 자신의 날짜는 빼고, 목록 카드의 날짜에만 붙인다.
		// (1.0.0은 queryId로 목록을 가렸는데, 목록 카드 안 블록엔 postId·postType만 넘어와서 아무 데도 안 붙었다)
		if ( is_singular() && get_queried_object_id() === $post_id ) {
			return $html;
		}
		if ( has_tag( TP_VC_WEEKLY_SLUG, $post_id ) ) {
			return $html;
		}

		$views = tp_vc_views( $post_id );
		if ( $views < 0 ) {
			// 통계를 못 읽은 경우: 화면엔 아무것도 안 붙이고, 원인을 찾을 수 있게 표시만 남긴다
			return $html . '<!-- tp-view-count: unavailable -->';
		}

		$label = '<span class="tp-views"> · 조회 ' . esc_html( number_format_i18n( $views ) ) . '</span>';
		// 날짜 글자(<time>) 바로 뒤, 같은 줄에
		$out = preg_replace( '#</time>#', '</time>' . $label, $html, 1, $count );
		return $count ? $out : $html;
	},
	10,
	3
);
