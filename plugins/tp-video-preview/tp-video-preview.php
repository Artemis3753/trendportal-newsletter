<?php
/**
 * Plugin Name: TP Video Preview
 * Description: 글 속 영상 주소 끝에 #t=0.001을 붙여, 재생 전에도 첫 장면이 보이게 한다 (iOS·모바일 크롬에서 흰 칸으로 보이던 문제).
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 영상 블록을 화면에 내보내기 직전에 주소를 고친다.
 * 저장된 글 내용은 건드리지 않으므로 지난 글·새 글 모두 적용되고, 플러그인을 끄면 원래대로 돌아간다.
 *
 * #t=0.001 은 "0.001초 지점을 미리 준비해 둬라"라는 뜻이라, 브라우저가 첫 장면을 그려 대표 이미지처럼 보여준다.
 * 영상마다 poster 이미지를 따로 만들지 않아도 된다.
 */
function tp_video_preview_render( $block_content ) {
	// 주소에 이미 #이 있으면(손으로 붙인 경우 등) 건너뛴다. [^"#]+ 가 # 을 만나면 일치하지 않는다.
	return preg_replace(
		'/(<video\b[^>]*\bsrc=")([^"#]+)(")/i',
		'$1$2#t=0.001$3',
		$block_content,
		1
	);
}
// render_block_{블록 이름} 훅은 해당 블록에만 불리므로, 다른 블록을 검사할 필요가 없다.
add_filter( 'render_block_core/video', 'tp_video_preview_render' );
