<?php
/**
 * 트란시스 웹 푸시 (1.2.0, 10/19 사용자 결정)
 * - 언제: 트란시스가 시트를 갱신하다 바뀐 일정(새 일정·날짜 변경·확정·취소)이 생겼을 때만
 * - 무엇: 건수만 ("바뀐 일정 N건이 있어요"). 누르면 앱이 열린다
 *
 * 흐름: 앱의 '알림 켜기' → 폰이 애플·구글 알림 서버 주소(endpoint)를 받아 subscribe로 등록
 *      → Code.gs doPost가 바뀐 게 있으면 notify를 부름 → 등록된 주소마다 '빈 알림'을 보냄
 *      → 폰의 service worker(sw)가 깨어나 latest에서 문구를 받아 알림을 띄운다
 *
 * 알림에 내용을 직접 싣지 않고 '빈 알림 + 문구는 따로 받기'로 한 이유:
 * 내용을 실으려면 폰마다 암호화(RFC 8291)를 해야 하는데, 건수 한 줄이라 그 코드를 둘 이유가 없다.
 * 빈 알림은 서명(VAPID)만 있으면 된다.
 *
 * 비밀값(서명용 비밀 열쇠, notify 열쇠)은 저장소가 공개라 코드가 아니라 설정값(tp_transis_push)에 둔다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TP_TF_PUSH = 'tp_transis_push';       // { public_key, private_pem, notify_key, subject } — REST로 관리자가 넣는다
const TP_TF_SUBS = 'tp_transis_push_subs';  // 등록된 폰들 { endpoint => 등록 시각 }
const TP_TF_LAST = 'tp_transis_push_last';  // 마지막 알림 문구 { title, body, at }

// 알림 서버로 인정할 곳. 아무 주소나 등록받으면 우리 사이트가 엉뚱한 곳에 요청을 보내게 된다
const TP_TF_PUSH_HOSTS = array( 'push.apple.com', 'fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com' );

add_action(
	'init',
	function () {
		register_setting(
			'options',
			TP_TF_PUSH,
			array(
				'type'         => 'object',
				'default'      => array(),
				'show_in_rest' => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'public_key'  => array( 'type' => 'string' ),
							'private_pem' => array( 'type' => 'string' ),
							'notify_key'  => array( 'type' => 'string' ),
							'subject'     => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}
);

/** 설정이 다 들어 있을 때만 설정값을, 아니면 null (알림 기능 전체를 끈다) */
function tp_tf_push_config() {
	$c = get_option( TP_TF_PUSH );
	foreach ( array( 'public_key', 'private_pem', 'notify_key' ) as $k ) {
		if ( empty( $c[ $k ] ) ) {
			return null;
		}
	}
	return $c;
}

function tp_tf_b64url( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

/** 숨은 주소 아래 /sw · /subscribe · /latest · /notify. 처리했으면 exit, 아니면 그냥 돌아간다 */
function tp_tf_push_route( $rest, $slug ) {
	$c = tp_tf_push_config();
	if ( ! $c ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );

	// 주소에 .js를 붙이면 WordPress.com 서버가 정적 파일로만 찾고 PHP까지 안 와서 404가 난다(1.2.0에서 겪음)
	if ( 'sw' === $rest ) {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		echo tp_tf_sw_js( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
	if ( 'latest' === $rest ) {
		$last = get_option( TP_TF_LAST );
		wp_send_json( is_array( $last ) ? $last : array( 'title' => '트란시스', 'body' => '바뀐 일정이 있어요', 'kind' => 'change' ) );
	}
	if ( 'subscribe' === $rest && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$sub      = json_decode( file_get_contents( 'php://input' ), true );
		$endpoint = isset( $sub['endpoint'] ) ? (string) $sub['endpoint'] : '';
		$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$ok       = 0 === strpos( $endpoint, 'https://' );
		$ok       = $ok && array_filter( TP_TF_PUSH_HOSTS, fn( $h ) => $host === $h || substr( $host, -strlen( $h ) - 1 ) === '.' . $h );
		if ( ! $ok ) {
			wp_send_json( array( 'ok' => false ), 400 );
		}
		$subs              = (array) get_option( TP_TF_SUBS, array() );
		$subs[ $endpoint ] = time();
		// 쓰는 사람이 두세 명이라 20개면 충분하다. 넘치면 오래된 것부터 버린다
		asort( $subs );
		$subs = array_slice( $subs, -20, null, true );
		update_option( TP_TF_SUBS, $subs, false );
		wp_send_json( array( 'ok' => true, 'devices' => count( $subs ) ) );
	}
	if ( 'notify' === $rest && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$key = isset( $_SERVER['HTTP_X_TRANSIS_KEY'] ) ? (string) $_SERVER['HTTP_X_TRANSIS_KEY'] : '';
		if ( ! hash_equals( $c['notify_key'], $key ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		$data = json_decode( file_get_contents( 'php://input' ), true );
		$n    = max( 1, (int) ( $data['count'] ?? 1 ) );
		$test = ! empty( $data['test'] );
		/*
		 * 아이폰은 웹 앱 알림 제목 아래에 'from 트란시스'를 늘 붙인다(제목을 비워도 그대로).
		 * 내용을 제목 자리에 두는 두 줄 방식(1.2.5)은 제목이 한 줄에서 잘려서, 사용자가 다시
		 * '트란시스 / from 트란시스 / 내용' 세 줄로 돌림(10/19). 본문은 길면 다음 줄로 넘어간다.
		 *
		 * kind: 폰에서 같은 종류의 이전 알림을 지우는 데 쓴다(변경·밤 확인이 하나씩만 남게).
		 * 시험 알림만 제목·본문을 바꿔 볼 수 있다.
		 */
		if ( $test ) {
			$kind = 'test';
			$body = isset( $data['body'] ) ? (string) $data['body'] : '알림 시험이에요. 잘 받으셨으면 성공!';
		} elseif ( isset( $data['message'] ) && '' !== trim( (string) $data['message'] ) ) {
			// 트란시스가 문구를 정해 보내는 알림(밤 확인: '오늘 남은 일 2건 · …', 10/19)
			$kind = 'evening';
			$body = mb_substr( trim( (string) $data['message'] ), 0, 150 );
		} else {
			$kind = 'change';
			$body = "바뀐 일정 {$n}건이 있어요";
		}
		$title = ( $test && isset( $data['title'] ) ) ? (string) $data['title'] : '트란시스';
		update_option(
			TP_TF_LAST,
			array(
				'title' => $title,
				'body'  => $body,
				'kind'  => $kind,
				'at'    => time(),
			),
			false
		);
		wp_send_json( array( 'ok' => true ) + tp_tf_push_send_all( $c ) );
	}
}

/** 등록된 폰 모두에 빈 알림. 알림 서버가 '없는 주소'(404·410)라고 하면 목록에서 지운다 */
function tp_tf_push_send_all( $c ) {
	$subs    = (array) get_option( TP_TF_SUBS, array() );
	$sent    = 0;
	$removed = 0;
	$errors  = array();
	foreach ( array_keys( $subs ) as $endpoint ) {
		$res  = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'vapid t=' . tp_tf_vapid_jwt( $endpoint, $c ) . ', k=' . $c['public_key'],
					'TTL'           => '86400', // 폰이 꺼져 있어도 하루 동안은 전달을 시도
					'Urgency'       => 'normal',
					'Content-Length' => '0',
				),
				'body'    => '',
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			$sent++;
		} elseif ( 404 === $code || 410 === $code ) {
			unset( $subs[ $endpoint ] );
			$removed++;
		} else {
			$errors[] = $code ? $code . ' ' . substr( wp_remote_retrieve_body( $res ), 0, 120 ) : $res->get_error_message();
		}
	}
	if ( $removed ) {
		update_option( TP_TF_SUBS, $subs, false );
	}
	return array( 'sent' => $sent, 'removed' => $removed, 'errors' => $errors );
}

/**
 * 알림 서버에 '보내는 쪽이 우리다'를 증명하는 서명 토큰(VAPID JWT, ES256).
 * openssl이 주는 서명은 DER 형식이라, JWT가 요구하는 r‖s 64바이트로 바꿔 붙인다.
 */
function tp_tf_vapid_jwt( $endpoint, $c ) {
	$parts  = wp_parse_url( $endpoint );
	$claims = array(
		'aud' => $parts['scheme'] . '://' . $parts['host'],
		'exp' => time() + 12 * HOUR_IN_SECONDS,
		'sub' => ! empty( $c['subject'] ) ? $c['subject'] : 'mailto:contact@trendportal.kr',
	);
	$input  = tp_tf_b64url( '{"typ":"JWT","alg":"ES256"}' ) . '.' . tp_tf_b64url( wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
	openssl_sign( $input, $der, $c['private_pem'], OPENSSL_ALGO_SHA256 );

	// DER: 30 len 02 lenR R 02 lenS S → R, S를 각각 32바이트로 맞춘다
	$off = 3 + ( ord( $der[1] ) & 0x80 ? 1 : 0 );
	$lr  = ord( $der[ $off ] );
	$r   = substr( $der, $off + 1, $lr );
	$ls  = ord( $der[ $off + 2 + $lr ] );
	$s   = substr( $der, $off + 3 + $lr, $ls );
	$pad = fn( $x ) => str_pad( ltrim( $x, "\0" ), 32, "\0", STR_PAD_LEFT );
	return $input . '.' . tp_tf_b64url( $pad( $r ) . $pad( $s ) );
}

/** 폰에서 백그라운드로 도는 일꾼. 빈 알림을 받으면 문구를 받아 와서 띄운다 */
function tp_tf_sw_js( $slug ) {
	$base = '/' . $slug . '/';
	$icon = TP_TF_ICON_180;
	return <<<JS
const BASE = '{$base}';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('push', e => {
  // 문구를 못 받아도 알림은 꼭 띄운다(iOS는 알림 없이 끝나는 푸시가 쌓이면 구독을 끊는다)
  e.waitUntil(
    fetch(BASE + 'latest', { cache: 'no-store' }).then(r => r.json()).catch(() => ({}))
      .then(async m => {
        const kind = m.kind || 'change';
        // 같은 종류의 이전 알림은 지운다(변경·밤 확인이 종류마다 최신 하나만 남게, 10/19 사용자).
        // tag만으로는 아이폰이 옛 알림을 바꿔 주지 않았다
        try {
          (await self.registration.getNotifications()).filter(n => (n.data || {}).kind === kind).forEach(n => n.close());
        } catch (err) {}
        return self.registration.showNotification(typeof m.title === 'string' ? m.title : '트란시스', {
          body: typeof m.body === 'string' ? m.body : '바뀐 일정이 있어요',
          icon: '{$icon}', tag: 'transis-' + kind, data: { url: BASE, kind: kind }
        });
      })
  );
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    const open = list.find(c => c.url.includes(BASE));
    return open ? open.focus() : self.clients.openWindow(BASE);
  }));
});
JS;
}

/** 앱 화면 위의 '알림 켜기' 버튼. 홈 화면 앱으로 열었고 아직 허락 전일 때만 보인다 */
function tp_tf_push_button( $slug ) {
	$c = tp_tf_push_config();
	if ( ! $c ) {
		return;
	}
	$base = '/' . $slug . '/';
	?>
<button id="tp-push" hidden>🔔 알림 켜기</button>
<style>
	#tp-push { position: fixed; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); z-index: 2;
		padding: 10px 16px; border: 0; border-radius: 999px; font: 600 15px/1.2 -apple-system, sans-serif;
		color: #fff; background: #4f46e5; box-shadow: 0 4px 14px rgba(0,0,0,.25); }
</style>
<script>
(() => {
	const BASE = <?php echo wp_json_encode( $base ); ?>;
	const KEY = <?php echo wp_json_encode( $c['public_key'] ); ?>;
	const btn = document.getElementById('tp-push');
	const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
	// iOS는 홈 화면 앱에서만 웹 푸시가 된다. PC·일반 Safari 탭에서는 버튼을 안 띄운다
	if (!standalone || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

	const toBytes = s => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), ch => ch.charCodeAt(0));
	async function subscribe() {
		const reg = await navigator.serviceWorker.register(BASE + 'sw', { scope: BASE });
		await navigator.serviceWorker.ready;
		const sub = (await reg.pushManager.getSubscription())
			|| await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toBytes(KEY) });
		await fetch(BASE + 'subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) });
	}

	if (Notification.permission === 'granted') {
		// 이미 허락한 폰: 등록이 풀렸을 수 있으니 열 때마다 조용히 다시 등록
		subscribe().catch(() => {});
		// 앱을 열었으면 알림은 확인한 셈이라 남은 트란시스 알림을 정리한다(10/19 사용자)
		navigator.serviceWorker.ready
			.then(reg => reg.getNotifications())
			.then(list => list.forEach(n => n.close()))
			.catch(() => {});
	} else if (Notification.permission === 'default') {
		btn.hidden = false;
		btn.onclick = async () => {
			btn.disabled = true;
			// 허락 창은 버튼을 누른 그 순간에만 띄울 수 있다(iOS 규칙)
			const p = await Notification.requestPermission();
			if (p === 'granted') {
				try { await subscribe(); btn.textContent = '✓ 알림 켜짐'; } catch (e) { btn.textContent = '알림 등록 실패'; }
			}
			setTimeout(() => { btn.hidden = true; }, 1500);
		};
	}
})();
</script>
	<?php
}
