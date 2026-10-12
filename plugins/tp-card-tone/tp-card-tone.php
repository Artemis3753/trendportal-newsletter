<?php
/**
 * Plugin Name: TP Card Tone
 * Description: 글 목록 카드 표시를 돕는다. ① 카드마다 자기 대표 사진 주소를 CSS 변수 --tp-card-img로 넘긴다(사진을 흐려 카드 바탕에 까는 모양은 추가 CSS). ② 홈 시리즈 칸 제목을 주차/이름으로 나누고 첫 글에 NEW를 붙인다.
 * Version: 1.0.2
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/13 사용자 결정(목록 글래스 B안): 카드마다 자기 사진 색이 비치는 서리 유리 카드.
 * CSS는 카드 안의 <img>를 다른 요소의 배경으로 다시 쓸 수 없어서, 사진 주소만 스크립트가 변수로 옮겨 준다.
 * 스크립트가 없으면 사진 색만 빠지고 흰 카드로 보인다.
 *
 * 1.0.2: 홈 시리즈 칸은 사진 톤 카드 대신 "첫 글 크게 + 나머지 줄" 모양으로 바뀌었다(10/13, 시안 3번).
 * 주간 총정리 제목은 세 글 모두 "이번 주 일어난 마케팅 이슈 총정리 (26.10 2주차)"처럼 앞부분이 같아서,
 * 글을 실제로 구분하는 괄호 속 주차를 굵게 앞으로 빼고 나머지는 회색 한 줄로 둔다.
 * 괄호가 없는 제목(TP 1,000 등)은 건드리지 않는다. 스크립트가 없으면 원래 제목 그대로 보인다.
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

	// 홈 시리즈 칸: "이름 (주차)" → 주차(굵게, 첫 글은 NEW) + 이름(회색)
	document.querySelectorAll('.tp-series .wp-block-post-template').forEach(function (list) {
		list.querySelectorAll(':scope > li .wp-block-post-title a').forEach(function (a, i) {
			var m = a.textContent.trim().match(/^(.*?)\s*\(([^()]+)\)$/);
			if (!m) return;
			var week = document.createElement('span');
			week.className = 'tp-sr-week';
			if (i === 0) {
				var badge = document.createElement('span');
				badge.className = 'tp-sr-new';
				badge.textContent = 'NEW';
				week.appendChild(badge);
			}
			week.appendChild(document.createTextNode(m[2]));
			var name = document.createElement('span');
			name.className = 'tp-sr-name';
			name.textContent = m[1];
			a.textContent = '';
			a.appendChild(week);
			a.appendChild(name);
		});
	});
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_ct_footer' );
