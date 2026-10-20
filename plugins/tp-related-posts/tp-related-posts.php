<?php
/**
 * Plugin Name: TP Related Posts
 * Description: 글 끝(카톡 공유와 댓글 사이)에 "다른 글도 볼까요?" 카드 줄을 붙인다. 일반 글은 같은 카테고리 최신 글부터, 모자라면 전체 최신 글로 채우고, 주간 총정리 글에는 지난 총정리를 보여 준다. 모양은 추가 CSS(.tp-related).
 * Version: 1.1.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/15 사용자 결정(시안 E): 사진 카드를 가로로 넘기는 줄. 폰은 최대 5장(2장 + 셋째 카드가 엿보임), PC는 넘기지 않고 3장.
 * 장수 차이는 CSS가 맡는다 — PHP는 언제나 5장까지 내보낸다.
 *
 * 자리: 카톡 공유 버튼은 템플릿 안 shortcode가 <p>로 감싸여 있어서 그 뒤에 붙이면 <p> 안에 <section>이 들어가 HTML이 깨진다.
 * 그래서 바로 다음에 오는 댓글 블록 앞에 끼운다. 일반 글·주간 글 템플릿 둘 다 댓글 블록이 있어서 템플릿은 고치지 않는다.
 */

const TP_RP_MAX         = 5;        // 폰에서 넘겨 볼 수 있는 최대 장수
const TP_RP_WEEKLY_SLUG = 'weekly'; // 주간 총정리 태그

/**
 * 대표 사진이 있는 글만 고른다. 카드가 사진 중심이라 사진 없는 글이 끼면 빈 칸이 생긴다.
 */
function tp_rp_ids( $args ) {
	return get_posts(
		array_merge(
			array(
				'post_type'   => 'post',
				'numberposts' => TP_RP_MAX,
				'fields'      => 'ids',
				'meta_key'    => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery -- 글 수가 적어 문제없음
			),
			$args
		)
	);
}

/**
 * 보여 줄 글 ID 목록과 제목·"전체 보기" 주소를 정한다.
 */
function tp_rp_pick( $post_id ) {
	$weekly = get_term_by( 'slug', TP_RP_WEEKLY_SLUG, 'post_tag' );
	$wk_id  = $weekly ? (int) $weekly->term_id : 0;

	// 주간 총정리 글: 지난 호(더 오래된 글)부터, 모자라면 더 최근 호로 채운다
	if ( $wk_id && has_term( $wk_id, 'post_tag', $post_id ) ) {
		$ids = tp_rp_ids(
			array(
				'tag_id'       => $wk_id,
				'post__not_in' => array( $post_id ),
				'date_query'   => array( array( 'before' => get_the_date( 'c', $post_id ) ) ),
			)
		);
		if ( count( $ids ) < TP_RP_MAX ) {
			$newer = tp_rp_ids(
				array(
					'tag_id'       => $wk_id,
					'post__not_in' => array_merge( array( $post_id ), $ids ),
				)
			);
			$ids   = array_merge( $ids, $newer );
		}
		return array(
			'ids'     => array_slice( $ids, 0, TP_RP_MAX ),
			'heading' => '지난 총정리',
			'more'    => get_term_link( $weekly ),
			'weekly'  => true,
		);
	}

	// 일반 글: 같은 카테고리 최신 글부터, 모자라면 전체 최신 글. 주간 총정리는 따로 칸이 있으니 뺀다
	$not_tag = $wk_id ? array( $wk_id ) : array();
	$cats    = wp_get_post_categories( $post_id );
	$cat     = $cats ? (int) $cats[0] : 0;
	$same    = $cat ? tp_rp_ids(
		array(
			'cat'          => $cat,
			'tag__not_in'  => $not_tag,
			'post__not_in' => array( $post_id ),
		)
	) : array();
	$ids     = $same;
	if ( count( $ids ) < TP_RP_MAX ) {
		$latest = tp_rp_ids(
			array(
				'tag__not_in'  => $not_tag,
				'post__not_in' => array_merge( array( $post_id ), $ids ),
			)
		);
		$ids    = array_merge( $ids, $latest );
	}

	// 같은 카테고리 글이 하나라도 있으면 그 카테고리로, 없으면(인터뷰 첫 글 등) 홈 목록으로 보낸다
	$more = $same ? get_category_link( $cat ) : home_url( '/' );

	return array(
		'ids'     => array_slice( $ids, 0, TP_RP_MAX ),
		'heading' => '다른 글도 볼까요?',
		'more'    => $more,
		'weekly'  => false,
	);
}

/**
 * 카드 한 장. 주간 총정리는 제목 앞부분이 모두 같아서 괄호 속 주차를 위 작은 줄로, 나머지를 제목으로 둔다(TP Card Tone과 같은 규칙).
 */
function tp_rp_card( $id, $weekly ) {
	$title = wp_strip_all_tags( get_the_title( $id ) );
	$label = '';
	if ( $weekly && preg_match( '/^(.*?)\s*\(([^()]+)\)$/u', $title, $m ) ) {
		$title = $m[1];
		$label = $m[2];
	} else {
		$cats  = get_the_category( $id );
		$label = $cats ? $cats[0]->name : '';
	}

	// alt를 비우는 이유: 바로 아래 제목 글자가 같은 내용을 읽어 주므로, 화면 낭독기가 제목을 두 번 읽지 않게
	$img = wp_get_attachment_image(
		get_post_thumbnail_id( $id ),
		'medium_large',
		false,
		array(
			'class'   => 'tp-related-img',
			'alt'     => '',
			'loading' => 'lazy',
			'sizes'   => '(min-width: 782px) 210px, 42vw',
		)
	);

	// 저장 1,000+ 뱃지(10/20): 목록 카드는 워드프레스가 붙이는 tag-tp1000 class로 뱃지를 그린다. 여기도 같은 이름을 붙여 CSS 한 줄로 같이 그린다
	$saved = has_tag( 'tp1000', $id ) ? ' tag-tp1000' : '';

	return sprintf(
		'<a class="tp-related-card%s" href="%s">%s<span class="tp-related-label">%s</span><span class="tp-related-title">%s</span></a>',
		$saved,
		esc_url( get_permalink( $id ) ),
		$img,
		esc_html( $label ),
		esc_html( $title )
	);
}

function tp_rp_render( $post_id ) {
	$pick = tp_rp_pick( $post_id );
	if ( ! $pick['ids'] ) {
		return '';
	}
	$cards = '';
	foreach ( $pick['ids'] as $id ) {
		$cards .= tp_rp_card( $id, $pick['weekly'] );
	}
	return sprintf(
		'<section class="tp-related" aria-labelledby="tp-related-h"><div class="tp-related-head"><h2 id="tp-related-h">%s</h2><a href="%s">전체 보기 →</a></div><div class="tp-related-list">%s</div></section>',
		esc_html( $pick['heading'] ),
		esc_url( $pick['more'] ),
		$cards
	);
}

/**
 * 글 페이지의 댓글 블록 바로 앞에 끼운다. 페이지(소개·문의 등)에는 넣지 않는다.
 */
function tp_rp_before_comments( $html ) {
	static $done = false; // 혹시 댓글 블록이 두 번 그려져도 한 번만
	if ( $done || ! is_singular( 'post' ) ) {
		return $html;
	}
	$done = true;
	return tp_rp_render( get_queried_object_id() ) . $html;
}
add_filter( 'render_block_core/comments', 'tp_rp_before_comments' );
