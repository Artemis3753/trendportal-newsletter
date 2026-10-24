<?php
/**
 * Plugin Name: TP Card Clip
 * Description: 글에 "카드용 작은 영상"(메타 tp_card_clip = 미디어 번호)이 붙어 있으면, 홈·분류·검색 목록의 대표 사진 위에 그 영상을 겹쳐 소리 없이 반복 재생한다. 사진은 그대로 아래에 남아서 재생이 안 되는 환경(저전력 모드·데이터 절약·동작 줄이기)에서는 예전처럼 정지 사진으로 보인다. 글 페이지·카톡 미리보기는 그대로. 이 플러그인을 끄면 예전으로 돌아간다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TP_CARD_CLIP_META = 'tp_card_clip';

/*
 * 영상은 대표 이미지 <img>를 "바꾸지" 않고 그 위에 "겹친다".
 * TP Card Sheet(카드를 누르면 펼쳐지는 시트)와 TP Card Tone(카드 색)이 `.wp-block-post-featured-image img`를 직접 읽어서,
 * <img>를 영상으로 갈아 끼우면 둘 다 깨진다. 겹쳐 두면 영상이 재생되기 전·못 되는 환경에선 사진이 그대로 보인다.
 */

// 셰프가 REST로 글을 만들 때 meta 칸에 미디어 번호를 넣을 수 있게 열어 둔다 (편집 권한자만)
function tp_card_clip_register_meta() {
	register_post_meta(
		'post',
		TP_CARD_CLIP_META,
		array(
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'init', 'tp_card_clip_register_meta' );

/**
 * 목록의 대표 이미지 블록에 영상을 덧붙인다. 글 페이지·관리자·피드에선 하지 않는다.
 * 영상 주소는 src가 아니라 data-src에 둔다 — 화면에 들어올 때까지 받지 않으려고(아래 스크립트).
 */
function tp_card_clip_render( $block_content, $block, $instance = null ) {
	if ( is_singular() || is_admin() || is_feed() ) {
		return $block_content;
	}
	$post_id = $instance instanceof WP_Block ? (int) ( $instance->context['postId'] ?? 0 ) : 0;
	if ( ! $post_id ) {
		return $block_content;
	}
	$clip_id = (int) get_post_meta( $post_id, TP_CARD_CLIP_META, true );
	$url     = $clip_id ? wp_get_attachment_url( $clip_id ) : '';
	if ( ! $url ) {
		return $block_content;
	}
	$video = sprintf(
		'<video class="tp-card-clip" data-src="%s" muted loop playsinline preload="none" tabindex="-1" aria-hidden="true" style="position:absolute;top:0;left:0;width:100%%;aspect-ratio:4/5;object-fit:cover;opacity:0;pointer-events:none"></video>',
		esc_url( $url )
	);
	// 링크 안(</a> 앞)에 넣고, 링크가 없는 블록이면 </figure> 앞에 넣는다
	$count = 0;
	$out   = preg_replace( '#</a>#', $video . '</a>', $block_content, 1, $count );
	if ( ! $count ) {
		$out = preg_replace( '#</figure>#', $video . '</figure>', $block_content, 1, $count );
	}
	if ( $count ) {
		$GLOBALS['tp_card_clip_used'] = true;
		return $out;
	}
	return $block_content;
}
add_filter( 'render_block_core/post-featured-image', 'tp_card_clip_render', 10, 3 );

/**
 * 영상이 한 번이라도 쓰인 화면에만 스타일과 스크립트를 한 번 찍는다.
 * 보이는 카드만 받아서 재생하고(IntersectionObserver), 벗어나면 멈춘다. 영상이 실제로 돌기 시작하면(playing) 그때 사진 위로 드러낸다.
 * 동작 줄이기·데이터 절약 설정이거나 재생이 거부되면(iOS 저전력 모드 등) 아무것도 안 하고 사진이 그대로 보인다.
 */
function tp_card_clip_footer() {
	if ( empty( $GLOBALS['tp_card_clip_used'] ) ) {
		return;
	}
	?>
<style>
.wp-block-post-featured-image { position: relative; }
.wp-block-post-featured-image video.tp-card-clip { transition: opacity 0.4s ease, transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1); }
/* 사진에 걸어 둔 호버 확대와 같이 움직이게 */
.wp-block-post-template > li:hover .wp-block-post-featured-image video.tp-card-clip,
.wp-block-post-template > li:focus-within .wp-block-post-featured-image video.tp-card-clip { transform: scale(1.05); }
@media (max-width: 781px), (prefers-reduced-motion: reduce) {
	.wp-block-post-featured-image video.tp-card-clip { transform: none !important; }
}
</style>
<script>
(function () {
	var clips = document.querySelectorAll('video.tp-card-clip');
	if (!clips.length || !('IntersectionObserver' in window)) { return; }
	var conn = navigator.connection;
	if ((conn && conn.saveData) || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) { return; }
	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (e) {
			var v = e.target;
			if (e.isIntersecting) {
				if (!v.getAttribute('src')) { v.setAttribute('src', v.getAttribute('data-src')); }
				var p = v.play();
				if (p && p.catch) { p.catch(function () {}); }
			} else {
				v.pause();
			}
		});
	}, { threshold: 0.5 });
	Array.prototype.forEach.call(clips, function (v) {
		v.addEventListener('playing', function () { v.style.opacity = '1'; });
		io.observe(v);
	});
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_card_clip_footer' );
