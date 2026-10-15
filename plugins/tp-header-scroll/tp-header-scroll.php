<?php
/**
 * Plugin Name: TP Header Scroll
 * Description: 유리 헤더를 아래로 스크롤하면 숨기고, 위로 스크롤하면 다시 보여 준다(아이폰 사파리 주소창처럼). 숨김 모양은 추가 CSS의 .tp-hdr-hidden이 맡는다. 폰 메뉴는 열 때 헤더 위치에 맞추고, 바깥을 누르면 닫는다.
 * Version: 1.1.0
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

	/*
	 * 폰 메뉴(드롭다운 카드). 로그인 상태 600px 이하에서는 관리 막대(46px)가 스크롤과 함께 사라져서,
	 * 헤더 위치가 맨 위일 때 46px·스크롤 뒤 0px로 바뀐다. CSS만으로는 지금 어느 쪽인지 알 수 없으니
	 * 메뉴를 여는 순간 헤더 위치를 재서 --tp-menu-top으로 넘긴다(✕·카드·어두운 막이 이 값을 쓴다).
	 * 메뉴가 열려 있는 동안은 페이지 스크롤이 잠기므로 여는 순간 한 번만 재면 된다.
	 */
	var menu = header.querySelector('.wp-block-navigation__responsive-container');
	var openBtn = header.querySelector('.wp-block-navigation__responsive-container-open');
	if (!menu || !openBtn) return;
	openBtn.addEventListener('click', function () {
		// 헤더가 숨김·등장 애니메이션 중이면 transform만큼 밀려 있으니, 그 양을 빼서 원래 자리를 잰다
		var shift = new DOMMatrix(getComputedStyle(header).transform).m42;
		header.style.setProperty('--tp-menu-top', Math.round(header.getBoundingClientRect().top - shift) + 'px');
	});
	// 카드 바깥(어두운 곳·헤더 띠)을 누르면 ✕를 누른 것처럼 닫는다. 직접 닫지 않고 ✕를 누르는 건
	// 워드프레스 메뉴 스크립트가 열림 상태·포커스·스크롤 잠금을 함께 정리하게 하려는 것이다.
	menu.addEventListener('click', function (e) {
		if (!menu.classList.contains('is-menu-open')) return;
		if (e.target.closest('.wp-block-navigation__responsive-container-content, .wp-block-navigation__responsive-container-close')) return;
		var closeBtn = menu.querySelector('.wp-block-navigation__responsive-container-close');
		if (closeBtn) closeBtn.click();
	});
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_hs_footer' );
