<?php
/**
 * Plugin Name: TP Header Scroll
 * Description: 유리 헤더를 아래로 스크롤하면 숨기고, 위로 스크롤하면 다시 보여 준다(아이폰 사파리 주소창처럼). 숨김 모양은 추가 CSS의 .tp-hdr-hidden이 맡는다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/12 사용자 결정(B안): 헤더가 화면을 계속 따라오면 읽는 자리를 가려서, 내릴 때는 비켜 주고 올릴 때만 나타나게 한다.
 * 스크롤 방향은 CSS만으로 알 수 없어 스크립트가 필요하다. 클래스만 붙였다 떼고, 움직임과 위치는 CSS가 정한다.
 */
function tp_hs_footer() {
	?>
<script id="tp-header-scroll">
(function () {
	var header = document.querySelector('.wp-site-blocks > header.wp-block-template-part');
	if (!header) return;
	var TOP_ZONE = 80; // 맨 위 근처에서는 항상 보인다
	var NUDGE = 4;     // 손가락 떨림 정도의 작은 움직임은 무시한다
	var last = window.scrollY;
	var ticking = false;
	function update() {
		var y = window.scrollY;
		// 폰 메뉴가 열려 있으면 숨기지 않는다(메뉴가 헤더 안에 있다)
		var menuOpen = header.querySelector('.wp-block-navigation__responsive-container.is-menu-open');
		if (menuOpen || y < TOP_ZONE) {
			header.classList.remove('tp-hdr-hidden');
		} else if (y > last + NUDGE) {
			header.classList.add('tp-hdr-hidden');
		} else if (y < last - NUDGE) {
			header.classList.remove('tp-hdr-hidden');
		}
		last = y;
		ticking = false;
	}
	window.addEventListener('scroll', function () {
		if (!ticking) {
			ticking = true;
			window.requestAnimationFrame(update);
		}
	}, { passive: true });
	// 키보드로 헤더 안 링크에 들어오면 다시 보여 준다
	header.addEventListener('focusin', function () {
		header.classList.remove('tp-hdr-hidden');
	});
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_hs_footer' );
