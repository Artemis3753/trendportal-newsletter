<?php
/**
 * Plugin Name: TP Cover Photo
 * Description: 본문 맨 위의 글자 없는 사진(.tp-cover-photo)을 글 커버 오른쪽으로 옮기고, 커버를 사진 색으로 물든 배경(.has-photo)으로 바꾼다. 사진이 없는 글은 원래 회색 커버 그대로다.
 * Version: 1.1.0
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
 *
 * 1.1.0: 커버 배경을 사진의 평균색을 어둡게 한 색(--tp-tone)으로 깐다(10/12 H안). 사람이 매번 고르지 않게
 * 처음 그릴 때 한 번 계산해 사진에 저장해 두고, 마음에 안 들면 사진 블록에 tp-tone-RRGGBB 이름표로 직접 지정한다.
 */

const TP_CP_TONE_DARKEN = 0.35; // 평균색에 곱하는 값. 흰 사진도 흰 제목이 읽히는 어두움이 되도록 시안에서 맞춘 값

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
 * 본문 블록에서 이름표 달린 사진을 찾아 커버 여는 태그 바로 뒤에 넣고, 커버에 has-photo와 배경색 변수를 붙인다.
 * 저장된 HTML을 그대로 쓰지 않고 미디어 번호로 다시 그리는 건, 화면 크기별 사진(srcset)을 붙이기 위해서다.
 */
function tp_cp_add_photo( $html ) {
	$post = get_post();
	if ( ! $post ) {
		return $html;
	}
	$photo = tp_cp_find_photo( parse_blocks( $post->post_content ) );
	if ( ! $photo ) {
		return $html;
	}
	$id = (int) $photo['id'];
	// 제목이 바로 옆에 있으니 사진은 장식으로 둔다(alt 비움). 첫 화면이라 바로 불러온다
	$img = wp_get_attachment_image( $id, 'large', false, array( 'alt' => '', 'loading' => 'eager', 'fetchpriority' => 'high' ) );
	if ( ! $img ) {
		return $html;
	}
	$GLOBALS['tp_cp_photo_moved'] = true;
	$figure = '<figure class="tp-cover-bg">' . $img . '</figure>';
	$tone   = tp_cp_tone( $id, $photo['className'] ?? '' );
	$var    = $tone ? '--tp-tone:' . $tone . ';' : '';
	return preg_replace_callback(
		'/^(\s*<div\b)([^>]*)>/',
		function ( $m ) use ( $figure, $var ) {
			$attrs = preg_replace( '/\bclass="([^"]*)"/', 'class="$1 has-photo"', $m[2], 1 );
			if ( $var ) {
				$attrs = false !== strpos( $attrs, 'style="' )
					? preg_replace( '/\bstyle="/', 'style="' . $var, $attrs, 1 )
					: $attrs . ' style="' . $var . '"';
			}
			return $m[1] . $attrs . '>' . $figure;
		},
		$html,
		1
	);
}

/**
 * 커버 배경색. 이름표(tp-tone-RRGGBB)가 있으면 그 색, 없으면 사진의 평균색을 어둡게 한 색.
 * 평균색은 사진마다 한 번만 계산해 첨부 파일 정보(_tp_cover_tone)에 저장한다. 계산할 수 없으면 빈 값 → CSS가 검정(#111)으로 그린다.
 */
function tp_cp_tone( $id, $class_name ) {
	if ( preg_match( '/\btp-tone-([0-9a-fA-F]{6})\b/', (string) $class_name, $m ) ) {
		return '#' . strtolower( $m[1] );
	}
	$saved = get_post_meta( $id, '_tp_cover_tone', true );
	if ( $saved ) {
		return $saved;
	}
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return '';
	}
	// 원본은 2000px대라 무겁다. 중간 크기 사진으로 충분하다
	$src = wp_get_attachment_image_src( $id, 'medium' );
	if ( ! $src ) {
		return '';
	}
	$res = wp_remote_get( $src[0], array( 'timeout' => 5 ) );
	if ( is_wp_error( $res ) ) {
		return '';
	}
	$im = @imagecreatefromstring( wp_remote_retrieve_body( $res ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $im ) {
		return '';
	}
	// 1×1로 줄이면 그 한 점이 사진 전체의 평균색이 된다
	$px = imagecreatetruecolor( 1, 1 );
	imagecopyresampled( $px, $im, 0, 0, 0, 0, 1, 1, imagesx( $im ), imagesy( $im ) );
	$c = imagecolorat( $px, 0, 0 );
	imagedestroy( $im );
	imagedestroy( $px );
	$tone = sprintf(
		'rgb(%d,%d,%d)',
		round( ( ( $c >> 16 ) & 255 ) * TP_CP_TONE_DARKEN ),
		round( ( ( $c >> 8 ) & 255 ) * TP_CP_TONE_DARKEN ),
		round( ( $c & 255 ) * TP_CP_TONE_DARKEN )
	);
	update_post_meta( $id, '_tp_cover_tone', $tone );
	return $tone;
}

/**
 * 묶음 안쪽까지 내려가며 tp-cover-photo 이름표가 달린 사진 블록을 찾는다(미디어 번호와 이름표 문자열).
 */
function tp_cp_find_photo( $blocks ) {
	foreach ( $blocks as $b ) {
		if ( 'core/image' === $b['blockName'] && ! empty( $b['attrs']['id'] )
			&& tp_cp_has_class( $b['attrs']['className'] ?? '', 'tp-cover-photo' ) ) {
			return $b['attrs'];
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$found = tp_cp_find_photo( $b['innerBlocks'] );
			if ( $found ) {
				return $found;
			}
		}
	}
	return null;
}
