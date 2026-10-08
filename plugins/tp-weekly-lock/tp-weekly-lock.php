<?php
/**
 * Plugin Name: TP Weekly Lock
 * Description: 주간 총정리 글(뉴스레터와 같은 포맷)에 카드 번호·목차·구독자 잠금을 자동으로 붙이고, 편집기에 "주간 총정리 틀"을 등록한다.
 * Version: 1.0.1
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 친구분은 카드만 쓰고, 번호·목차·"나머지 N가지"·잠금 위치는 이 플러그인이 글을 내보낼 때 채운다.
 * 손으로 맞추게 두면 카드 수가 바뀌는 주마다 어긋나기 때문이다.
 *
 * 잠금은 본문을 HTML에 그대로 두고 화면에서만 가린다(B안). 서버에서 사람마다 다른 페이지를 보내면
 * WordPress.com 페이지 캐시가 구독자용·비구독자용을 섞어 보낼 수 있어서다.
 */

const TP_WK_OPEN_CARDS = 1; // 잠금 없이 보여 줄 카드 수 (10/8 결정: 01번만 공개)

/**
 * className 문자열에 특정 클래스가 "단어로" 들어 있는지. tp-wk-item 같은 이름에 tp-wk가 걸리지 않게 한다.
 */
function tp_wk_has_class( $class_name, $want ) {
	return in_array( $want, preg_split( '/\s+/', (string) $class_name ), true );
}

/**
 * 바깥 묶음(.tp-wk)이 그려질 때 한 번만 손댄다. 안쪽 블록은 이미 다 그려진 HTML이라 문자열로 다룬다.
 */
function tp_wk_render_block( $block_content, $block ) {
	if ( 'core/group' !== $block['blockName'] || empty( $block['attrs']['className'] ) ) {
		return $block_content;
	}
	if ( ! tp_wk_has_class( $block['attrs']['className'], 'tp-wk' ) ) {
		return $block_content;
	}
	return tp_wk_transform( $block_content );
}
add_filter( 'render_block', 'tp_wk_render_block', 10, 2 );

function tp_wk_transform( $html ) {
	// 카드 묶음의 여는 태그 위치를 모두 찾는다
	preg_match_all( '/<div\b[^>]*\bclass="[^"]*\btp-wk-item\b[^"]*"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE );
	$opens = $m[0];
	$count = count( $opens );
	if ( 0 === $count ) {
		return $html;
	}

	// 마지막 카드가 끝나는 곳 = 맺음(.tp-wk-end)이 시작하는 곳, 없으면 바깥 묶음이 닫히는 곳
	if ( preg_match( '/<div\b[^>]*\bclass="[^"]*\btp-wk-end\b/', $html, $end_m, PREG_OFFSET_CAPTURE ) ) {
		$cards_end = $end_m[0][1];
	} else {
		$cards_end = strrpos( $html, '</div>' );
	}

	$cards_start = $opens[0][1];
	$cards       = array();
	$toc         = '';

	foreach ( $opens as $i => $open ) {
		$start = $open[1];
		$end   = isset( $opens[ $i + 1 ] ) ? $opens[ $i + 1 ][1] : $cards_end;
		$card  = substr( $html, $start, $end - $start );
		$num   = sprintf( '%02d', $i + 1 );

		// 목차가 누를 곳(id)과 카드 번호를 여는 태그 바로 뒤에 붙인다
		$tag      = $open[0];
		$new_tag  = false === strpos( $tag, ' id=' ) ? preg_replace( '/^<div\b/', '<div id="wk-' . $num . '"', $tag ) : $tag;
		$card     = $new_tag . '<p class="tp-wk-num">' . $num . '</p>' . substr( $card, strlen( $tag ) );
		$cards[]  = $card;

		$title = '';
		if ( preg_match( '/<h2\b[^>]*>(.*?)<\/h2>/s', $card, $h ) ) {
			$title = trim( wp_strip_all_tags( $h[1] ) );
		}
		$toc .= '<li><a href="#wk-' . $num . '"><span>' . $num . '</span>' . esc_html( $title ) . '</a></li>';
	}

	$open_cards   = array_slice( $cards, 0, TP_WK_OPEN_CARDS );
	$locked_cards = array_slice( $cards, TP_WK_OPEN_CARDS );

	$body = implode( '', $open_cards );
	if ( $locked_cards ) {
		$body .= '<div class="tp-wk-lock"><div class="tp-wk-locked">' . implode( '', $locked_cards ) . '</div>'
			. tp_wk_gate( count( $locked_cards ) ) . '</div>';
	}

	$html = substr( $html, 0, $cards_start ) . $body . substr( $html, $cards_end );

	// 목차 자리(<nav class="tp-wk-tocbox">)를 채운다. 친구분이 지웠으면 목차 없이 둔다
	$toc_html = '<p class="tp-wk-label">이번 주 ' . $count . '가지</p><ol class="tp-wk-toc">' . $toc . '</ol>';
	$html     = preg_replace( '/(<nav\b[^>]*\bclass="[^"]*\btp-wk-tocbox\b[^"]*"[^>]*>).*?(<\/nav>)/s', '$1' . str_replace( '$', '\$', $toc_html ) . '$2', $html, 1 );

	return $html;
}

/**
 * 잠금 안내 상자. "이미 구독 중이에요"는 확인 없이 이메일만 받는다(10/6 B안 — 무료 콘텐츠라 손해가 없고,
 * 나중에 스티비 명단 대조(C안)로 바꿀 자리).
 */
function tp_wk_gate( $rest ) {
	return '<div class="tp-wk-gate">'
		. '<p class="tp-wk-gate-label">SUBSCRIBERS ONLY</p>'
		. '<p class="tp-wk-gate-title">여기서부터는 구독자 전용이에요</p>'
		. '<p class="tp-wk-gate-desc">뉴스레터를 구독하면 나머지 ' . intval( $rest ) . '가지와<br>💡 주목 포인트를 끝까지 볼 수 있어요. 무료예요.</p>'
		. '<p class="tp-wk-gate-btn"><a href="#newsletter">무료로 구독하고 이어 보기</a></p>'
		. '<details class="tp-wk-gate-have"><summary>이미 구독 중이에요</summary>'
		. '<form class="tp-wk-gate-form" novalidate><input type="email" required placeholder="구독한 이메일 주소" aria-label="구독한 이메일 주소"><button type="submit">열기</button></form>'
		. '<p class="tp-wk-gate-note">구독할 때 쓴 이메일을 넣으면 이 기기에서 계속 열려 있어요.</p>'
		. '</details></div>';
}

/**
 * 이 글이 주간 총정리 포맷인지. 저장된 블록 속성(className)으로 판단한다.
 */
function tp_wk_is_weekly_post() {
	if ( ! is_singular( 'post' ) ) {
		return false;
	}
	$post = get_post();
	return $post && false !== strpos( $post->post_content, '"className":"tp-wk"' );
}

/**
 * 구글에 "이 부분은 구독자 영역"이라고 알린다. 본문은 HTML에 다 있는데 사람에게는 가려 보이므로,
 * 이 표시가 없으면 검색봇과 사람에게 다른 걸 보여 주는 속임수(클로킹)로 오해받을 수 있다.
 */
function tp_wk_head() {
	if ( ! tp_wk_is_weekly_post() ) {
		return;
	}
	$data = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'NewsArticle',
		'headline'            => wp_strip_all_tags( get_the_title() ),
		'datePublished'       => get_the_date( 'c' ),
		'isAccessibleForFree' => false,
		'hasPart'             => array(
			'@type'               => 'WebPageElement',
			'isAccessibleForFree' => false,
			'cssSelector'         => '.tp-wk-locked',
		),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}
add_action( 'wp_head', 'tp_wk_head' );

/**
 * 잠금을 여닫는 스크립트. 주간 총정리 글에서만 내보낸다.
 */
function tp_wk_footer() {
	if ( ! tp_wk_is_weekly_post() ) {
		return;
	}
	?>
<script>
(function () {
	var KEY = 'tp_sub';
	function subscribed() {
		try { if (localStorage.getItem(KEY) === '1') return true; } catch (e) {}
		return document.cookie.indexOf(KEY + '=1') !== -1;
	}
	function remember() {
		// 사파리는 스크립트가 남긴 표시를 오래 방문하지 않으면 지울 수 있어서 두 군데에 남긴다
		try { localStorage.setItem(KEY, '1'); } catch (e) {}
		document.cookie = KEY + '=1; max-age=31536000; path=/; SameSite=Lax';
	}
	function setOpen(open) {
		document.querySelectorAll('.tp-wk-lock').forEach(function (lock) {
			lock.classList.toggle('is-open', open);
			var inner = lock.querySelector('.tp-wk-locked');
			if (!inner) return;
			// 가려진 동안은 화면 읽기 프로그램도 건너뛰게 한다
			if (open) { inner.removeAttribute('aria-hidden'); inner.inert = false; }
			else { inner.setAttribute('aria-hidden', 'true'); inner.inert = true; }
		});
	}
	function unlock() { remember(); setOpen(true); }

	// 뉴스레터·웰컴메일 속 링크에는 ?tp_sub=1을 붙인다. 메일을 받은 사람은 이미 구독자라 바로 연다.
	// 주소창에서는 지워서, 독자가 주소를 복사해 공유해도 표시가 따라가지 않게 한다
	var params = new URLSearchParams(location.search);
	if (params.get('tp_sub') === '1') {
		remember();
		params.delete('tp_sub');
		var rest = params.toString();
		history.replaceState(null, '', location.pathname + (rest ? '?' + rest : '') + location.hash);
	}

	setOpen(subscribed());

	// "이미 구독 중이에요" → 이메일 형식만 확인하고 연다
	document.querySelectorAll('.tp-wk-gate-form').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var input = form.querySelector('input[type=email]');
			if (!input.checkValidity()) { input.reportValidity(); return; }
			unlock();
		});
	});

	// 페이지 아래 스티비 구독 폼이 성공하면(결과 칸에 success가 붙으면) 바로 연다
	var result = document.querySelector('.tp-nl-result');
	if (result && window.MutationObserver) {
		new MutationObserver(function () {
			if (result.classList.contains('success')) unlock();
		}).observe(result, { attributes: true, attributeFilter: ['class'] });
	}
})();
</script>
	<?php
}
add_action( 'wp_footer', 'tp_wk_footer' );

/**
 * 편집기 "패턴"에 주간 총정리 틀을 등록한다. 불러온 뒤 칸만 채우면 되고, 카드는 복제(⌘⇧D)해서 늘린다.
 * 번호·목차·잠금은 위 필터가 채우므로 틀에는 넣지 않는다.
 */
function tp_wk_register_pattern() {
	register_block_pattern_category( 'trendportal', array( 'label' => '트렌드포털' ) );
	register_block_pattern(
		'trendportal/weekly',
		array(
			'title'       => '주간 총정리 틀',
			'description' => '뉴스레터와 같은 포맷. 카드는 복제해서 늘리고, 번호·목차·잠금은 자동.',
			'categories'  => array( 'trendportal' ),
			'postTypes'   => array( 'post' ),
			'content'     => tp_wk_pattern_content(),
		)
	);
}
add_action( 'init', 'tp_wk_register_pattern' );

function tp_wk_card_markup( $title, $is_video ) {
	$video = $is_video
		? '<!-- wp:paragraph {"className":"tp-wk-video"} --><p class="tp-wk-video">▶ <a href="https://www.instagram.com/reel/릴스ID/" target="_blank" rel="noreferrer noopener">인스타그램에서 영상 보기 (@계정)</a></p><!-- /wp:paragraph -->'
		: '';
	return '<!-- wp:group {"className":"tp-wk-item","layout":{"type":"default"}} --><div class="wp-block-group tp-wk-item">'
		. '<!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading -->'
		. '<!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img alt=""/></figure><!-- /wp:image -->'
		. $video
		. '<!-- wp:paragraph {"className":"tp-wk-src"} --><p class="tp-wk-src">© 출처 (없으면 이 줄 삭제)</p><!-- /wp:paragraph -->'
		. '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">무슨 일이었나</h4><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>카드뉴스 본문을 그대로 넣어요.</p><!-- /wp:paragraph -->'
		. '<!-- wp:group {"className":"tp-wk-insight","layout":{"type":"default"}} --><div class="wp-block-group tp-wk-insight">'
		. '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">💡 주목 포인트</h4><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>왜 이 이슈를 골랐는지 에디터의 한마디.</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->'
		. '</div><!-- /wp:group -->';
}

function tp_wk_pattern_content() {
	return '<!-- wp:group {"className":"tp-wk","layout":{"type":"default"}} --><div class="wp-block-group tp-wk">'
		. '<!-- wp:paragraph {"className":"tp-wk-issue"} --><p class="tp-wk-issue">WEEKLY · 26.10 N주차 · 금요일 발행</p><!-- /wp:paragraph -->'
		. '<!-- wp:group {"className":"tp-wk-letter","layout":{"type":"default"}} --><div class="wp-block-group tp-wk-letter">'
		. '<!-- wp:paragraph --><p>에디터 레터 첫 문단.</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p>이번 주를 한마디로 정리하는 문단.</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph {"className":"tp-wk-sig"} --><p class="tp-wk-sig">— 트렌드포털 주인장</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:group {"className":"tp-wk-pick","layout":{"type":"default"}} --><div class="wp-block-group tp-wk-pick">'
		. '<!-- wp:paragraph {"className":"tp-wk-label"} --><p class="tp-wk-label">이번 주 에디터 픽</p><!-- /wp:paragraph -->'
		. '<!-- wp:list {"ordered":true} --><ol class="wp-block-list"><!-- wp:list-item --><li>픽 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>픽 2</li><!-- /wp:list-item --><!-- wp:list-item --><li>픽 3</li><!-- /wp:list-item --></ol><!-- /wp:list -->'
		. '</div><!-- /wp:group -->'
		. '<!-- wp:html --><nav class="tp-wk-tocbox"><!-- 목차·카드 번호·잠금은 플러그인이 자동으로 채워요. 이 칸은 지우지 마세요 --></nav><!-- /wp:html -->'
		. tp_wk_card_markup( '카드 제목 (사진 카드)', false )
		. tp_wk_card_markup( '카드 제목 (영상 카드: 사진 자리에 영상 정지 화면)', true )
		. '<!-- wp:group {"className":"tp-wk-end","layout":{"type":"default"}} --><div class="wp-block-group tp-wk-end">'
		. '<!-- wp:heading --><h2 class="wp-block-heading">여러분의 이번 주 픽은?</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>이번 주 가장 인상 깊었던 마케팅이 있다면 <strong>댓글로</strong> 알려 주세요. 다음 호에서 소개할게요.</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p>먼저 본 트렌드가 있다면 제보도 언제든 환영이에요.</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph {"className":"tp-wk-btn"} --><p class="tp-wk-btn"><a href="/report/">제보하러 가기</a></p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph {"className":"tp-wk-sig"} --><p class="tp-wk-sig">매주 금요일, 국내외에서 실제로 터진 마케팅만 골라 보내 드릴게요.<br>그럼 다음 주 금요일에 만나요!</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->'
		. '</div><!-- /wp:group -->';
}
