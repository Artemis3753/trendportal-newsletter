<?php
/**
 * Plugin Name: TP Video Sound
 * Description: 글 속 영상 블록에 tp-has-sound 이름표(소리가 실제로 들어 있는 영상, 카드뉴스 도우미가 붙인다)가 있으면 영상 오른쪽 아래에 작은 소리 켜기/끄기 버튼을 단다. 영상은 계속 소리 없이 자동 재생되고, 독자가 누를 때만 소리가 난다. 이름표가 없는 영상(소리 없는 영상, 지난 글)에는 아무것도 안 한다. 이 플러그인을 끄면 예전으로 돌아간다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 버튼은 저장된 글에 넣지 않고 화면에서 스크립트가 붙인다. 글 내용(블록 HTML)에는 이름표만 남아서,
 * 에디터에서 블록이 깨졌다고 뜨지 않고 플러그인을 꺼도 글이 그대로다.
 */

/**
 * 이름표가 붙은 영상이 이 화면에 한 번이라도 나오면 표시해 둔다. 아래 푸터는 표시가 있을 때만 스타일·스크립트를 찍는다.
 */
function tp_video_sound_render( $block_content ) {
	if ( false !== strpos( $block_content, 'tp-has-sound' ) ) {
		$GLOBALS['tp_video_sound_used'] = true;
	}
	return $block_content;
}
add_filter( 'render_block_core/video', 'tp_video_sound_render' );

function tp_video_sound_footer() {
	if ( empty( $GLOBALS['tp_video_sound_used'] ) ) {
		return;
	}
	?>
<style>
figure.wp-block-video.tp-has-sound { position: relative; }
/* 가운데 정렬된 작은 영상(주간 총정리의 240px 영상)에서도 영상 모서리에 붙도록 위치는 스크립트가 계산해 넣는다 */
.tp-snd { position: absolute; z-index: 2; width: 32px; height: 32px; padding: 0; border: 0; border-radius: 8px; background: rgba(0, 0, 0, 0.4); color: #fff; opacity: 0.8; display: grid; place-items: center; cursor: pointer; transition: opacity 0.2s ease, background 0.2s ease; -webkit-tap-highlight-color: transparent; }
.tp-snd:hover, .tp-snd:focus-visible, .tp-snd[aria-pressed="true"] { opacity: 1; background: rgba(0, 0, 0, 0.6); }
.tp-snd:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
/* 눈에 보이는 건 32px이지만 손가락으로 누르기 쉽게 눌리는 칸은 바깥으로 6px 더 넓힌다 */
.tp-snd::after { content: ""; position: absolute; inset: -6px; }
.tp-snd svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tp-snd .tp-snd-on { display: none; }
.tp-snd[aria-pressed="true"] .tp-snd-on { display: block; }
.tp-snd[aria-pressed="true"] .tp-snd-off { display: none; }
</style>
<script>
(function () {
	var figs = document.querySelectorAll('figure.wp-block-video.tp-has-sound');
	if (!figs.length) { return; }
	var SVG = 'viewBox="0 0 24 24" aria-hidden="true"';
	var SPEAKER = '<path d="M11 5 6 9H2v6h4l5 4z"/>';
	var ICONS =
		'<svg class="tp-snd-off" ' + SVG + '>' + SPEAKER + '<path d="m22 9-6 6M16 9l6 6"/></svg>' +
		'<svg class="tp-snd-on" ' + SVG + '>' + SPEAKER + '<path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>';
	var items = [];

	// 한 번에 한 영상만 소리가 나게: 하나를 켜면 나머지는 끈다
	function setSound(item, on) {
		item.video.muted = !on;
		item.btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		item.btn.setAttribute('aria-label', on ? '소리 끄기' : '소리 켜기');
	}

	function place(item) {
		var v = item.video;
		if (!v.offsetWidth) { return; }
		item.btn.style.left = (v.offsetLeft + v.offsetWidth - 10 - 32) + 'px';
		item.btn.style.top = (v.offsetTop + v.offsetHeight - 10 - 32) + 'px';
	}

	Array.prototype.forEach.call(figs, function (fig) {
		var v = fig.querySelector('video');
		if (!v) { return; }
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'tp-snd';
		btn.innerHTML = ICONS;
		fig.appendChild(btn);
		var item = { video: v, btn: btn };
		items.push(item);
		setSound(item, false);
		place(item);
		btn.addEventListener('click', function () {
			var turnOn = v.muted;
			items.forEach(function (o) { setSound(o, false); });
			if (turnOn) {
				setSound(item, true);
				// 저전력 모드 등으로 자동 재생이 막혀 멈춰 있었다면, 누른 김에 재생도 시작한다
				if (v.paused) { var p = v.play(); if (p && p.catch) { p.catch(function () {}); } }
			}
		});
		// 영상 크기는 불러온 뒤·화면 폭이 바뀔 때 달라지므로, 크기가 변할 때마다 버튼 위치를 다시 잡는다
		if ('ResizeObserver' in window) {
			new ResizeObserver(function () { place(item); }).observe(v);
		} else {
			v.addEventListener('loadedmetadata', function () { place(item); });
			window.addEventListener('resize', function () { place(item); });
		}
	});

	// 영상이 화면에서 벗어나면 소리를 끈다 — 스크롤해서 다른 글 내용을 읽는데 뒤에서 소리가 나지 않게
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (e.isIntersecting) { return; }
				items.forEach(function (o) { if (o.video === e.target) { setSound(o, false); } });
			});
		}, { threshold: 0.2 });
		items.forEach(function (o) { io.observe(o.video); });
	}
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_video_sound_footer' );
