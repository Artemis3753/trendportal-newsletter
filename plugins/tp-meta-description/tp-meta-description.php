<?php
/**
 * Plugin Name: TP Meta Description
 * Description: 사람이 쓴 설명(SEO 설명·요약)이 없는 글의 검색 설명과 공유 미리보기 설명을, 라벨·프로필 카드·출처 줄을 뺀 본문으로 만든다. 공유 이미지가 사이트 아이콘으로 대신 들어가는 곳에는 1200×630 공유 이미지를 넣는다. 공유 이미지 대체 텍스트가 비어 있으면 제목으로 채운다.
 * Version: 1.3.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 왜 필요한가 (2026-10-15)
 *
 * Jetpack은 설명을 ① SEO 설명 칸 → ② 요약(excerpt) → ③ 본문 앞부분 순서로 고른다. 우리 글은 대부분 ③으로 가는데,
 * 본문을 태그만 지워서 쓰기 때문에
 *   - 주간 총정리: 맨 위 호수 줄이 붙는다 ("WEEKLY · 26.10 2주차 · 금요일 발행오늘은…")
 *   - 광고 글: 광고 고지 문단과 광고주 프로필 카드 글자가 섞인다 ("[광고] 이 글은…", "쉐어잇@shareit.kr프로필 보기")
 *   - 줄바꿈(<br>)·문단 사이가 붙는다 ("다녀왔습니다.많은")
 * 검색 결과와 카톡 미리보기에 그대로 나와서, ①②가 비어 있을 때만 본문을 정리해 대신 넣는다.
 * 사람이 직접 쓴 설명은 항상 그대로 둔다.
 */

const TP_MD_LENGTH = 150; // 검색 결과에 보통 다 보이는 정도의 길이(글자 수)

// 설명에 넣지 않을 요소: 주간 호수 줄·서명, 광고주 프로필 카드, 카드마다 붙는 출처 줄
const TP_MD_SKIP_CLASSES = array( 'tp-wk-issue', 'tp-wk-sig', 'tp-ad-profile', 'tp-source' );

/**
 * 본문을 정리한 설명. 만들 수 없으면 빈 문자열.
 */
function tp_md_auto_description( $post ) {
	static $cache = array(); // 검색 설명과 공유 설명이 같은 글을 두 번 정리하지 않게
	if ( ! isset( $cache[ $post->ID ] ) ) {
		$cache[ $post->ID ] = tp_md_build_description( (string) $post->post_content );
	}
	return $cache[ $post->ID ];
}

function tp_md_build_description( $html ) {
	$html = preg_replace( '/<!--.*?-->/s', '', $html ); // 블록 주석
	$html = strip_shortcodes( $html );                  // 제보 페이지처럼 본문이 게시판 숏코드뿐이면 비게 된다
	if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
		return '';
	}

	$doc = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8"><div id="tp-md-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	$xp = new DOMXPath( $doc );

	// 주간 총정리는 에디터 레터만 쓴다. 뒤의 에디터 픽·목차·카드까지 이어 붙이면 문장이 아니라 목록이 된다
	$root = $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " tp-wk-letter ")]' )->item( 0 );
	if ( ! $root ) {
		$root = $xp->query( '//*[@id="tp-md-root"]' )->item( 0 );
	}
	if ( ! $root ) {
		return '';
	}

	$remove = array();
	foreach ( TP_MD_SKIP_CLASSES as $class ) {
		foreach ( $xp->query( './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $root ) as $node ) {
			$remove[] = $node;
		}
	}
	foreach ( $xp->query( './/figure | .//script | .//style', $root ) as $node ) {
		$remove[] = $node; // 사진 캡션·임베드는 설명이 아니다
	}
	// 광고 글 맨 위 고지 문단("[광고] 이 글은 유료 광고를…", 지침서 10번). 고지는 본문에 두고 설명은 내용으로 시작한다 (1.3.0)
	foreach ( $xp->query( './/p', $root ) as $node ) {
		if ( 0 === strpos( ltrim( $node->textContent ), '[광고]' ) ) {
			$remove[] = $node;
		}
	}
	foreach ( $remove as $node ) {
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}

	// 줄바꿈과 문단 끝을 공백으로 바꾼 뒤 태그를 지워야 문장이 서로 붙지 않는다
	$out = $doc->saveHTML( $root );
	$out = preg_replace( '#<br\s*/?>#i', ' ', $out );
	$out = preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption)>#i', ' ', $out );
	$text = html_entity_decode( wp_strip_all_tags( $out ), ENT_QUOTES, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

	if ( mb_strlen( $text ) > TP_MD_LENGTH ) {
		$cut = mb_substr( $text, 0, TP_MD_LENGTH );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > TP_MD_LENGTH * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space ); // 낱말 중간에서 자르지 않는다
		}
		$text = rtrim( $cut, " ,.·" ) . '…';
	}
	return $text;
}

/**
 * 지금 페이지가 정리 대상인 글이면 그 글을, 아니면 null.
 */
function tp_md_target_post() {
	if ( ! is_singular() || is_front_page() ) {
		return null;
	}
	$post = get_post();
	if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
		return null;
	}
	return $post;
}

// 검색 결과용 <meta name="description">
function tp_md_seo_meta( $meta ) {
	$post = tp_md_target_post();
	if ( ! $post || '' !== trim( (string) $post->post_excerpt ) || '' !== trim( (string) get_post_meta( $post->ID, 'advanced_seo_description', true ) ) ) {
		return $meta;
	}
	$auto = tp_md_auto_description( $post );
	if ( '' !== $auto ) {
		$meta['description'] = $auto;
	}
	return $meta;
}
add_filter( 'jetpack_seo_meta_tags', 'tp_md_seo_meta' );

// 공유 미리보기용 og:description. Jetpack은 여기서 SEO 설명 칸을 보지 않아서, 있으면 그것을 먼저 쓴다
function tp_md_og_description( $tags ) {
	$post = tp_md_target_post();
	if ( ! $post || '' !== trim( (string) $post->post_excerpt ) ) {
		return $tags;
	}
	$custom = trim( (string) get_post_meta( $post->ID, 'advanced_seo_description', true ) );
	$value  = '' !== $custom ? $custom : tp_md_auto_description( $post );
	if ( '' !== $value ) {
		$tags['og:description'] = $value;
	}
	return $tags;
}
add_filter( 'jetpack_open_graph_tags', 'tp_md_og_description' );

/*
 * 공유 이미지 (1.1.0, 2026-10-15)
 *
 * 대표 사진이 없는 페이지(홈·소개·문의·제보 등)는 Jetpack이 사이트 아이콘(512px 정사각형)을 공유 이미지로 쓴다.
 * 카톡·검색 미리보기에서 작고 휑하게 나와서, 그 자리에만 1200×630 공유 이미지(미디어 594, 시안 B)를 넣는다.
 * 대표 사진이 있는 글은 지금처럼 자기 사진을 쓴다. Jetpack의 트위터 카드 태그도 같은 필터에서 만들어지므로
 * 그 뒤(우선순위 100)에 돌아야 아이콘이 남지 않는다.
 */
const TP_MD_SHARE_IMAGE_ID = 594;

function tp_md_share_image( $tags ) {
	$icon_id = (int) get_option( 'site_icon' );
	$icon    = $icon_id ? wp_basename( (string) get_attached_file( $icon_id ) ) : '';
	$image   = wp_get_attachment_image_src( TP_MD_SHARE_IMAGE_ID, 'full' );
	if ( ! $image ) {
		return $tags;
	}
	// 사이트 아이콘이거나 Jetpack의 빈 그림(blank.jpg)일 때만 바꾼다
	$is_fallback = function ( $url ) use ( $icon ) {
		$url = (string) $url;
		return '' === $url || false !== strpos( $url, '/i/blank.jpg' ) || ( '' !== $icon && false !== strpos( $url, $icon ) );
	};
	if ( $is_fallback( $tags['og:image'] ?? '' ) ) {
		$tags['og:image']        = $image[0];
		$tags['og:image:width']  = $image[1];
		$tags['og:image:height'] = $image[2];
		$tags['og:image:alt']    = (string) get_post_meta( TP_MD_SHARE_IMAGE_ID, '_wp_attachment_image_alt', true );
	}
	if ( isset( $tags['twitter:image'] ) && $is_fallback( $tags['twitter:image'] ) ) {
		$tags['twitter:image'] = $image[0];
		$tags['twitter:card']  = 'summary_large_image';
	}
	return $tags;
}
add_filter( 'jetpack_open_graph_tags', 'tp_md_share_image', 100 );

/*
 * 공유 이미지 대체 텍스트 (1.2.0, 2026-10-15)
 *
 * Jetpack은 og:image:alt를 대표 사진의 "대체 텍스트" 칸에서 가져오는데, 우리 글 사진은 그 칸이 비어 있어 alt=""로 나간다.
 * 비어 있을 때만 공유 카드에 함께 보이는 제목(og:title, 없으면 글 제목)을 넣는다. 사진에 직접 쓴 대체 텍스트가 있으면 그대로 둔다.
 * 위의 공유 이미지 교체가 자기 alt를 넣은 뒤에 돌아야 하므로 우선순위를 그보다 뒤로 둔다.
 */
function tp_md_share_image_alt( $tags ) {
	if ( empty( $tags['og:image'] ) || '' !== trim( (string) ( $tags['og:image:alt'] ?? '' ) ) ) {
		return $tags;
	}
	$title = trim( (string) ( $tags['og:title'] ?? '' ) );
	if ( '' === $title && is_singular() ) {
		$title = trim( wp_strip_all_tags( (string) get_post_field( 'post_title', get_queried_object_id() ) ) );
	}
	if ( '' !== $title ) {
		$tags['og:image:alt'] = $title;
	}
	return $tags;
}
add_filter( 'jetpack_open_graph_tags', 'tp_md_share_image_alt', 110 );
