<?php
/**
 * Plugin Name: TP Cover Photo
 * Description: 본문 맨 위의 글자 없는 사진(.tp-cover-photo)을 글 커버 오른쪽으로 옮기고, 커버를 검정 + 그라데이션 모양(.has-photo)으로 바꾼다. 사진이 없는 글은 원래 회색 커버 그대로다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 커버는 모든 글이 같이 쓰는 템플릿 안에 있어서, 글마다 다른 사진을 템플릿에 넘겨 줄 길이 따로 필요하다.
 * 대표 이미지는 글자가 박힌 표지라 홈 목록·카톡 미리보기용으로 그대로 두고(10/12 결정),
 * 글자 없는 사진은 본문 맨 위에 이름표(tp-cover-photo)를 달아 두면 이 플러그인이 커버로 옮긴다.
 * 주간 총정리의 호수 줄을 제목 위로 옮기는 TP Weekly Lock과 같은 방식이다.
 *
 * 주간 총정리는 회색 커버 대신 뉴스레터 머리(tp-weekly 템플릿)를 써서 .tp-cover가 없으므로 저절로 빠진다.
 */

/**
 * className 문자열에 특정 클래스가 "단어로" 들어 있는지. tp-cover-text 같은 이름에 tp-cover가 걸리지 않게 한다.
 */
function tp_cp_has_class( $class_name, $want ) {
	return in_array( $want, preg_split( '/\s+/', (string) $class_name ), true );
}

/**
 * 커버(.tp-cover)를 그릴 때 사진을 넣고, 본문을 그릴 때 같은 사진을 뺀다(두 번 나오지 않게).
 * 템플릿에서 커버가 본문보다 위에 있어서 커버가 먼저 그려진다.
 */
function tp_cp_render_block( $block_content, $block ) {
	if ( ! is_singular( 'post' ) ) {
		return $block_content;
	}
	if ( 'core/group' === $block['blockName'] && tp_cp_has_class( $block['attrs']['className'] ?? '', 'tp-cover' ) ) {
		return tp_cp_add_photo( $block_content );
	}
	if ( 'core/post-content' === $block['blockName'] && ! empty( $GLOBALS['tp_cp_photo_moved'] ) ) {
		return preg_replace( '/<figure\b[^>]*\bclass="[^"]*\btp-cover-photo\b[^"]*"[^>]*>.*?<\/figure>/s', '', $block_content, 1 );
	}
	return $block_content;
}
add_filter( 'render_block', 'tp_cp_render_block', 10, 2 );

/**
 * 본문 블록에서 이름표 달린 사진의 미디어 번호를 찾아 커버 여는 태그 바로 뒤에 넣고, 커버에 has-photo를 붙인다.
 * 저장된 HTML을 그대로 쓰지 않고 미디어 번호로 다시 그리는 건, 화면 크기별 사진(srcset)을 붙이기 위해서다.
 */
function tp_cp_add_photo( $html ) {
	$post = get_post();
	if ( ! $post ) {
		return $html;
	}
	$id = tp_cp_find_photo_id( parse_blocks( $post->post_content ) );
	if ( ! $id ) {
		return $html;
	}
	// 제목이 바로 옆에 있으니 사진은 장식으로 둔다(alt 비움). 첫 화면이라 바로 불러온다
	$img = wp_get_attachment_image( $id, 'large', false, array( 'alt' => '', 'loading' => 'eager', 'fetchpriority' => 'high' ) );
	if ( ! $img ) {
		return $html;
	}
	$GLOBALS['tp_cp_photo_moved'] = true;
	$figure = '<figure class="tp-cover-bg">' . $img . '</figure>';
	return preg_replace_callback(
		'/^(\s*<div\b[^>]*\bclass=")([^"]*)("[^>]*>)/',
		function ( $m ) use ( $figure ) {
			return $m[1] . $m[2] . ' has-photo' . $m[3] . $figure;
		},
		$html,
		1
	);
}

/**
 * 묶음 안쪽까지 내려가며 tp-cover-photo 이름표가 달린 사진 블록의 미디어 번호를 찾는다.
 */
function tp_cp_find_photo_id( $blocks ) {
	foreach ( $blocks as $b ) {
		if ( 'core/image' === $b['blockName'] && ! empty( $b['attrs']['id'] )
			&& tp_cp_has_class( $b['attrs']['className'] ?? '', 'tp-cover-photo' ) ) {
			return (int) $b['attrs']['id'];
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$found = tp_cp_find_photo_id( $b['innerBlocks'] );
			if ( $found ) {
				return $found;
			}
		}
	}
	return 0;
}
