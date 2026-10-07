<?php
/**
 * Plugin Name: TP Weekly Links
 * Description: 고정 주소(?tp_weekly=1~3)를 최근 주간 총정리 글이나 그 대표 이미지로 넘겨준다. 웰컴메일의 "최근 글 3개"용.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 웰컴메일은 한 번 저장하면 내용이 고정이라 최신 글 주소를 직접 넣으면 매주 옛 글이 된다.
 * 그래서 메일에는 "몇 번째 최신 글"이라는 고정 주소만 넣고, 누를 때마다 여기서 지금의 글을 찾아 보낸다.
 *
 * ?tp_weekly=1            → 최신 주간 총정리 글
 * ?tp_weekly=2            → 그 전 글 (3도 같은 식)
 * ?tp_weekly=1&tp_cover=1 → 그 글의 대표 이미지
 *
 * 예쁜 주소(/go/weekly-1) 대신 물음표 주소를 쓴 이유: 예쁜 주소는 rewrite 규칙을 등록하고
 * 다시 읽히는 과정이 필요한데, 물음표 주소는 그런 준비 없이 바로 동작한다. 메일 속 주소라 독자 눈에도 안 보인다.
 */
function tp_weekly_links_redirect() {
	if ( ! isset( $_GET['tp_weekly'] ) ) {
		return;
	}

	$nth = absint( $_GET['tp_weekly'] );
	if ( $nth < 1 || $nth > 3 ) {
		return;
	}

	$want_cover = ! empty( $_GET['tp_cover'] );

	$posts = get_posts(
		array(
			'tag'            => 'weekly',
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => 1,
			'offset'         => $nth - 1,
		)
	);

	// 글이 아직 3편이 안 되는 등 해당 순번이 없으면, 링크는 목록 페이지로, 이미지는 로고로 보내서 깨진 칸이 안 생기게 한다.
	$target = $want_cover
		? 'https://trendportal.kr/wp-content/uploads/2026/09/cropped-tp-icon-square.png'
		: home_url( '/archives/tag/weekly/' );

	if ( $posts ) {
		$post = $posts[0];
		if ( $want_cover ) {
			// 메일에서 한 칸이 약 175px이라 레티나 화면(2배)에서도 흐리지 않은 medium_large(폭 768)를 쓴다.
			$cover = get_the_post_thumbnail_url( $post, 'medium_large' );
			if ( $cover ) {
				$target = $cover;
			}
		} else {
			$target = get_permalink( $post );
		}
	}

	// 302(임시 이동)여야 브라우저가 이 이동을 기억해 두지 않는다. 301이면 다음 주에도 옛 글로 간다.
	nocache_headers();
	wp_redirect( $target, 302 );
	exit;
}
add_action( 'template_redirect', 'tp_weekly_links_redirect', 1 );
