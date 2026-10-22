<?php
/**
 * Plugin Name: TP Weekly Lock
 * Description: 주간 총정리 글(뉴스레터와 같은 포맷)에 카드 번호·목차·구독자 잠금을 자동으로 붙이고, 전용 글 템플릿(tp-weekly)으로 보여 주며, 편집기에 "주간 총정리 틀"을 등록한다.
 * Version: 1.4.0
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
	// 전용 템플릿의 제목 묶음. 템플릿에서 본문보다 위에 있어서 본문(.tp-wk)보다 먼저 그려진다
	if ( tp_wk_has_class( $block['attrs']['className'], 'tp-wk-top' ) ) {
		return tp_wk_transform_top( $block_content );
	}
	if ( ! tp_wk_has_class( $block['attrs']['className'], 'tp-wk' ) ) {
		return $block_content;
	}
	return tp_wk_transform( $block_content );
}
add_filter( 'render_block', 'tp_wk_render_block', 10, 2 );

/**
 * 주간 총정리 글은 전용 글 템플릿(사이트 편집기의 tp-weekly)으로 보여 준다.
 * 회색 커버 대신 뉴스레터처럼 [호수 줄 → 제목 → 본문] 순서. 템플릿이 없으면 WordPress가 원래 단일 글 템플릿을 쓴다.
 */
function tp_wk_template_hierarchy( $templates ) {
	if ( tp_wk_is_weekly_post() ) {
		array_unshift( $templates, 'tp-weekly' );
	}
	return $templates;
}
add_filter( 'single_template_hierarchy', 'tp_wk_template_hierarchy' );

/**
 * 호수 줄은 본문 첫 줄에 있어서 템플릿만으로는 제목 위에 둘 수 없다. 그래서 제목 묶음을 그릴 때 본문에서 꺼내 와
 * 제목 앞에 붙이고, 본문을 그릴 때는 같은 줄을 뺀다(두 번 나오지 않게).
 * 제목 끝의 "(26.10 1주차)"는 호수 줄과 겹쳐서 화면에서만 뺀다. 탭 제목·검색 결과의 제목은 그대로다.
 */
function tp_wk_transform_top( $html ) {
	$post = get_post();
	if ( ! $post || ! preg_match( '/<p class="tp-wk-issue">(.*?)<\/p>/s', $post->post_content, $m ) ) {
		return $html;
	}
	$GLOBALS['tp_wk_issue_moved'] = true;
	$html = preg_replace( '/\s*\([^()]*\)\s*(<\/h1>)/', '$1', $html, 1 );
	return preg_replace( '/<h1\b/', '<p class="tp-wk-issue">' . esc_html( wp_strip_all_tags( $m[1] ) ) . '</p><h1', $html, 1 );
}

function tp_wk_transform( $html ) {
	if ( ! empty( $GLOBALS['tp_wk_issue_moved'] ) ) {
		$html = preg_replace( '/<p\b[^>]*\bclass="[^"]*\btp-wk-issue\b[^"]*"[^>]*>.*?<\/p>/s', '', $html, 1 );
	}

	// 1.2.0: 카드 제목(H2) 바로 아래 "무슨 일이었나"·"주목 포인트"가 H4라 H3를 건너뛰었다(10/15 보완점 7번).
	// 새 틀은 H3로 만들고, 이미 올라간 글(329·351·479)은 본문을 54군데 고치는 대신 내보낼 때 바꾼다
	$html = preg_replace( '/<h4\b([^>]*)>(.*?)<\/h4>/s', '<h3$1>$2</h3>', $html );

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
		// 번호 뒤 공백: 화면은 flex 간격이라 그대로지만, 글자만 읽는 곳(검색엔진·화면 낭독기)에서 "01뉴발란스"로 붙지 않게
		$toc .= '<li><a href="#wk-' . $num . '"><span>' . $num . '</span> ' . esc_html( $title ) . '</a></li>';
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

	return tp_wk_stage( $html );
}

/**
 * 1.1.0(10/12 C안): 에디터 픽과 목차를 한 무대(.tp-wk-stage)로 묶고, 그 주 대표 사진에서 뽑은 두 색을 넘긴다.
 * 무대 뒤에 두 색이 은은하게 번지고, 그 위의 두 상자가 유리 카드로 비친다(모양은 추가 CSS).
 * 묶음은 에디터 픽이 시작하는 곳부터 목차가 끝나는 곳까지다. 둘 중 하나가 없으면 묶지 않는다.
 */
function tp_wk_stage( $html ) {
	if ( ! preg_match( '/<div\b[^>]*\bclass="[^"]*\btp-wk-pick\b[^"]*"[^>]*>/', $html, $p, PREG_OFFSET_CAPTURE )
		|| ! preg_match( '/<nav\b[^>]*\bclass="[^"]*\btp-wk-tocbox\b/', $html, $n, PREG_OFFSET_CAPTURE, $p[0][1] ) ) {
		return $html;
	}
	$start = $p[0][1];
	$close = strpos( $html, '</nav>', $n[0][1] );
	if ( false === $close ) {
		return $html;
	}
	$end   = $close + strlen( '</nav>' );
	$tones = tp_wk_tones( get_post_thumbnail_id() );
	$style = $tones ? ' style="--tp-wk-t1:' . $tones[0] . ';--tp-wk-t2:' . $tones[1] . '"' : '';
	return substr( $html, 0, $start ) . '<div class="tp-wk-stage"' . $style . '>' . substr( $html, $start, $end - $start ) . '</div>' . substr( $html, $end );
}

/**
 * 대표 사진을 2×2로 줄여 왼쪽 위·오른쪽 아래 두 점의 색을 반투명으로 돌려준다. 사진마다 한 번만 계산해 저장한다.
 * 계산할 수 없으면 빈 배열 → CSS가 회색 기본값으로 그린다.
 */
function tp_wk_tones( $id ) {
	if ( ! $id ) {
		return array();
	}
	$saved = get_post_meta( $id, '_tp_wk_tones', true );
	if ( is_array( $saved ) && 2 === count( $saved ) ) {
		return $saved;
	}
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return array();
	}
	$src = wp_get_attachment_image_src( $id, 'medium' );
	if ( ! $src ) {
		return array();
	}
	$res = wp_remote_get( $src[0], array( 'timeout' => 5 ) );
	if ( is_wp_error( $res ) ) {
		return array();
	}
	$im = @imagecreatefromstring( wp_remote_retrieve_body( $res ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $im ) {
		return array();
	}
	$px = imagecreatetruecolor( 2, 2 );
	imagecopyresampled( $px, $im, 0, 0, 0, 0, 2, 2, imagesx( $im ), imagesy( $im ) );
	$tones = array();
	foreach ( array( array( 0, 0 ), array( 1, 1 ) ) as $xy ) {
		$c       = imagecolorat( $px, $xy[0], $xy[1] );
		$tones[] = sprintf( 'rgba(%d,%d,%d,.55)', ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 );
	}
	imagedestroy( $im );
	imagedestroy( $px );
	update_post_meta( $id, '_tp_wk_tones', $tones );
	return $tones;
}

/**
 * 잠금 안내 상자. "이미 구독 중이에요"는 처음(10/6 B안)엔 이메일만 받았고, 10/23부터 서버가 스티비 명단과
 * 대조한다(C안, 아래 tp_wk_rest_verify). 설정이 없으면 예전처럼 형식만 보고 연다.
 */
function tp_wk_gate( $rest ) {
	return '<div class="tp-wk-gate">'
		. '<p class="tp-wk-gate-label">SUBSCRIBERS ONLY</p>'
		// 10/19 사용자 B안: 사이트 💡는 짧은 버전, 메일엔 리서치한 긴 버전이 간다 — 구독할 이유를 그 차이로 앞세운다
		. '<p class="tp-wk-gate-title">뉴스레터엔 더 깊은 이야기가 있어요</p>'
		. '<p class="tp-wk-gate-desc">사이트엔 요약만, 메일엔 리서치한 긴 💡 주목 포인트와<br>출처까지 담아 보내요. 구독하면 나머지 ' . intval( $rest ) . '가지도 바로 열려요. 무료예요.</p>'
		. '<p class="tp-wk-gate-btn"><a href="#newsletter">무료로 구독하고 이어 보기</a></p>'
		. '<details class="tp-wk-gate-have"><summary>이미 구독 중이에요</summary>'
		. '<form class="tp-wk-gate-form" novalidate><input type="email" required placeholder="구독한 이메일 주소" aria-label="구독한 이메일 주소"><button type="submit">열기</button></form>'
		. '<p class="tp-wk-gate-note">구독할 때 쓴 이메일을 넣으면 이 기기에서 계속 열려 있어요.</p>'
		// 구독자 명단에 없을 때 알려 주는 자리. 사이트 CSS를 건드리지 않으려고 스타일을 여기에 직접 둔다
		. '<p class="tp-wk-gate-msg" role="alert" hidden style="margin:8px 0 0;font-size:13px;line-height:1.5;color:#c0392b"></p>'
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

	// "이미 구독 중이에요" → 서버가 스티비 구독자 명단과 대조한다(C안, 10/23).
	// 서버·스티비가 대답을 못 하거나 설정이 없으면 구독자가 막히지 않게 그대로 연다(열어 주기)
	var VERIFY = <?php echo wp_json_encode( esc_url_raw( rest_url( 'tp-weekly-lock/v1/verify' ) ) ); ?>;
	document.querySelectorAll('.tp-wk-gate-form').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var input = form.querySelector('input[type=email]');
			var btn = form.querySelector('button');
			var msg = form.parentNode.querySelector('.tp-wk-gate-msg');
			if (!input.checkValidity()) { input.reportValidity(); return; }
			if (msg) { msg.hidden = true; }
			btn.disabled = true;
			fetch(VERIFY, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: input.value }) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					btn.disabled = false;
					if (d && d.ok) { unlock(); return; }
					// 명단과 대조해서 없다고 한 경우에만 안내한다. 그 밖의 응답(오류 등)은 열어 준다
					var text = d && d.reason === 'slow' ? '시도가 너무 많아요. 잠시 뒤에 다시 해 주세요.'
						: d && d.reason === 'format' ? '이메일 주소 모양을 확인해 주세요.'
						: d && d.mode === 'checked' ? '구독자 목록에서 이 이메일을 못 찾았어요. 구독할 때 쓴 이메일인지 확인해 주세요. 방금 구독했다면 몇 분 뒤에 다시 해 보세요.' : '';
					if (!text) { unlock(); return; }
					if (msg) { msg.textContent = text; msg.hidden = false; }
				})
				.catch(function () { btn.disabled = false; unlock(); });
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

/*
 * 구독자 확인(C안, 10/23): "이미 구독 중이에요"에 넣은 이메일을 스티비 구독자 명단과 대조한다.
 * 스탠다드 요금제에 "구독자 API"가 들어 있다. 스티비 API에는 이메일로 한 명을 찾는 조회가 없어서
 * 구독 중인 주소 목록을 받아 서버에 sha256 해시로만 들고 있다가 대조한다(이메일 원문은 저장하지 않는다).
 * API 키는 저장소가 공개라서 코드에 두지 않고 사이트 설정(설정 → TP 주간 잠금)에만 둔다.
 * 키가 없거나 스티비가 대답을 못 하면 "열어 주기": 진짜 구독자를 막는 것보다 한 번 열어 주는 쪽이 싸다(10/23 결정).
 */
const TP_WK_OPT_KEY  = 'tp_wk_stibee_key';  // 스티비 API 키
const TP_WK_OPT_LIST = 'tp_wk_stibee_list'; // 주소록 번호(스티비 주소창의 lists/ 뒤 숫자)
const TP_WK_SUBS_TTL = 600;                 // 구독자 목록을 새로 받는 간격(초)

/**
 * 구독 중인 주소의 해시 집합(해시 => 1)을 돌려준다. 설정이 없거나 받지 못하면 null → 호출하는 쪽이 열어 준다.
 * 받지 못했는데 예전에 받아 둔 목록이 있으면 그걸 쓴다(오래된 명단이 아무것도 없는 것보다 낫다).
 */
function tp_wk_subscribers( $force = false ) {
	$key  = trim( (string) get_option( TP_WK_OPT_KEY, '' ) );
	$list = trim( (string) get_option( TP_WK_OPT_LIST, '' ) );
	if ( '' === $key || ! ctype_digit( $list ) ) {
		return null;
	}
	$stale = get_option( 'tp_wk_subs_last' );
	$stale = is_array( $stale ) && $stale ? $stale : null;
	if ( ! $force ) {
		$hit = get_transient( 'tp_wk_subs' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		if ( get_transient( 'tp_wk_subs_fail' ) ) { // 방금 실패했으면 1분은 다시 두드리지 않는다(분당 100회 한도)
			return $stale;
		}
	}

	$all   = array();
	$error = '';
	for ( $page = 0; $page < 30; $page++ ) { // 1000명씩 최대 3만 명까지
		$res = wp_remote_get(
			'https://api.stibee.com/v2/lists/' . $list . '/subscribers?statuses=subscribed&limit=1000&offset=' . ( $page * 1000 ),
			array(
				'timeout' => 8,
				'headers' => array( 'AccessToken' => $key ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$error = '연결하지 못했어요 (' . $res->get_error_message() . ')';
			break;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$error = 'HTTP ' . $code . ( 401 === $code || 403 === $code ? ' — API 키나 주소록 번호를 확인해 주세요' : '' );
			break;
		}
		$rows = json_decode( wp_remote_retrieve_body( $res ), true );
		// 문서 예시는 배열이 바로 오지만, 감싸서 오는 경우에도 읽는다
		if ( is_array( $rows ) && ! isset( $rows[0] ) ) {
			foreach ( array( 'items', 'Items', 'Value', 'value', 'data' ) as $wrap ) {
				if ( isset( $rows[ $wrap ] ) && is_array( $rows[ $wrap ] ) ) {
					$rows = $rows[ $wrap ];
					break;
				}
			}
		}
		if ( ! is_array( $rows ) ) {
			$error = '응답 형식을 읽지 못했어요';
			break;
		}
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['email'] ) ) {
				$all[ hash( 'sha256', strtolower( trim( $row['email'] ) ) ) ] = 1;
			}
		}
		if ( count( $rows ) < 1000 ) {
			break;
		}
	}
	// 구독 중인 사람이 한 명도 없다는 응답은 형식을 잘못 읽은 것일 가능성이 커서 실패로 본다(모두를 잠그지 않게)
	if ( '' === $error && ! $all ) {
		$error = '구독 중인 주소가 0개로 왔어요 — 주소록 번호와 응답을 확인해 주세요';
	}
	if ( '' !== $error ) {
		update_option( 'tp_wk_last_error', $error, false );
		set_transient( 'tp_wk_subs_fail', 1, 60 );
		return $stale;
	}
	delete_option( 'tp_wk_last_error' );
	delete_transient( 'tp_wk_subs_fail' );
	set_transient( 'tp_wk_subs', $all, TP_WK_SUBS_TTL );
	update_option( 'tp_wk_subs_last', $all, false );
	return $all;
}

/**
 * POST /wp-json/tp-weekly-lock/v1/verify {email} → {ok, mode}. mode가 checked일 때만 "명단에 없음"이 진짜 판정이다.
 * 이 주소가 구독자인지 아닌지 남이 마구 물어보지 못하게 IP당 10분에 30번까지만 받는다.
 */
function tp_wk_rest_verify( WP_REST_Request $req ) {
	$email = sanitize_email( (string) $req->get_param( 'email' ) );
	if ( ! is_email( $email ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'reason' => 'format' ), 200 );
	}
	$ip    = 'tp_wk_try_' . substr( hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ), 0, 24 );
	$tries = (int) get_transient( $ip );
	if ( $tries >= 30 ) {
		return new WP_REST_Response( array( 'ok' => false, 'reason' => 'slow' ), 429 );
	}
	set_transient( $ip, $tries + 1, 600 );

	$subs = tp_wk_subscribers();
	if ( null === $subs ) {
		return new WP_REST_Response( array( 'ok' => true, 'mode' => 'open' ), 200 ); // 열어 주기
	}
	$found = isset( $subs[ hash( 'sha256', strtolower( $email ) ) ] );
	return new WP_REST_Response( array( 'ok' => $found, 'mode' => 'checked' ), 200 );
}
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'tp-weekly-lock/v1',
			'/verify',
			array(
				'methods'             => 'POST',
				'callback'            => 'tp_wk_rest_verify',
				'permission_callback' => '__return_true',
			)
		);
	}
);

/* 설정 화면: 설정 → TP 주간 잠금. 키는 한 번 저장하면 화면에 다시 보여 주지 않는다. */
add_action(
	'admin_menu',
	function () {
		add_options_page( 'TP 주간 잠금', 'TP 주간 잠금', 'manage_options', 'tp-weekly-lock', 'tp_wk_settings_page' );
	}
);

function tp_wk_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$has_key = '' !== trim( (string) get_option( TP_WK_OPT_KEY, '' ) );
	$list    = (string) get_option( TP_WK_OPT_LIST, '' );
	$status  = '설정 안 됨 — 지금은 이메일 형식만 확인해요(예전 방식).';
	if ( $has_key && ctype_digit( $list ) ) {
		$subs   = tp_wk_subscribers( true ); // 화면을 열 때마다 연결을 시험한다
		$status = ( null !== $subs && ! get_option( 'tp_wk_last_error' ) )
			? '연결됨 · 구독 중 ' . count( $subs ) . '명'
			: '연결 실패: ' . ( get_option( 'tp_wk_last_error' ) ?: '알 수 없음' ) . ' — 지금은 열어 주기로 동작해요.';
	}
	?>
<div class="wrap">
	<h1>TP 주간 잠금</h1>
	<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible"><p>저장했어요.</p></div>
	<?php endif; ?>
	<p><b>구독자 확인(스티비 구독자 API)</b> — <?php echo esc_html( $status ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tp_wk_save">
		<?php wp_nonce_field( 'tp_wk_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tp-wk-key">스티비 API 키</label></th>
				<td>
					<input type="password" id="tp-wk-key" name="key" class="regular-text" autocomplete="off" placeholder="<?php echo $has_key ? '저장됨 — 바꿀 때만 새 키를 넣어요' : '스티비 → 워크스페이스 설정 → API 키'; ?>">
					<?php if ( $has_key ) : ?><label><input type="checkbox" name="clear" value="1"> 저장된 키 지우기</label><?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tp-wk-list">주소록 번호</label></th>
				<td><input type="text" id="tp-wk-list" name="list" class="regular-text" inputmode="numeric" value="<?php echo esc_attr( $list ); ?>" placeholder="스티비 주소록 주소창의 lists/ 뒤 숫자"></td>
			</tr>
		</table>
		<?php submit_button( '저장' ); ?>
	</form>
	<p class="description">키나 번호가 없거나 스티비가 대답하지 않으면, "이미 구독 중이에요"는 이메일 형식만 확인하고 열어 줘요. 구독자가 막히지 않게 하려는 거예요. 이메일은 저장하지 않고 해시만 10분 동안 들고 있어요.</p>
</div>
	<?php
}

add_action(
	'admin_post_tp_wk_save',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '권한이 없어요' );
		}
		check_admin_referer( 'tp_wk_save' );
		$key = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		if ( ! empty( $_POST['clear'] ) ) {
			delete_option( TP_WK_OPT_KEY );
		} elseif ( '' !== $key ) {
			update_option( TP_WK_OPT_KEY, $key, false ); // 자동으로 불러오는 옵션에 넣지 않는다
		}
		update_option( TP_WK_OPT_LIST, preg_replace( '/\D/', '', (string) wp_unslash( $_POST['list'] ?? '' ) ), false );
		// 설정이 바뀌면 받아 둔 명단을 버리고 처음부터 다시 받는다
		delete_transient( 'tp_wk_subs' );
		delete_transient( 'tp_wk_subs_fail' );
		delete_option( 'tp_wk_subs_last' );
		delete_option( 'tp_wk_last_error' );
		wp_safe_redirect( admin_url( 'options-general.php?page=tp-weekly-lock&saved=1' ) );
		exit;
	}
);

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
		. '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">무슨 일이었나</h3><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>카드뉴스 본문을 그대로 넣어요.</p><!-- /wp:paragraph -->'
		. '<!-- wp:group {"className":"tp-wk-insight","layout":{"type":"default"}} --><div class="wp-block-group tp-wk-insight">'
		. '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">💡 주목 포인트</h3><!-- /wp:heading -->'
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
		. '<!-- wp:paragraph {"className":"tp-wk-sig"} --><p class="tp-wk-sig">트렌드포털</p><!-- /wp:paragraph -->'
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
