<?php
/**
 * Plugin Name: TP Card Tone
 * Description: 글 목록 카드마다 자기 대표 사진 주소를 CSS 변수 --tp-card-img로 넘긴다. 사진을 크게 흐려 카드 바탕에 까는 모양은 추가 CSS가 맡는다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/13 사용자 결정(목록 글래스 B안): 카드마다 자기 사진 색이 비치는 서리 유리 카드.
 * CSS는 카드 안의 <img>를 다른 요소의 배경으로 다시 쓸 수 없어서, 사진 주소만 스크립트가 변수로 옮겨 준다.
 * 스크립트가 없으면 사진 색만 빠지고 흰 카드로 보인다.
 */
function tp_ct_footer() {
	?>
<script id="tp-card-tone">
(function () {
	// srcset에서 가장 작은 사진을 고른다 — 크게 흐려 쓰므로 원본(2000px)을 받을 이유가 없다
	function smallest(img) {
		var best = null;
		(img.getAttribute('srcset') || '').split(',').forEach(function (part) {
			var bits = part.trim().split(/\s+/);
			var w = parseInt(bits[1], 10);
			if (bits[0] && w && (!best || w < best.w)) best = { url: bits[0], w: w };
		});
		return best ? best.url : img.src;
	}
	document.querySelectorAll('.wp-block-post-template.is-layout-grid > li').forEach(function (card) {
		var img = card.querySelector('.wp-block-post-featured-image img');
		if (!img) return;
		card.style.setProperty('--tp-card-img', 'url("' + smallest(img) + '")');
	});
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_ct_footer' );
