<?php
/**
 * Plugin Name: TP Transis Frame
 * Description: 트란시스 일정표(Apps Script)를 trendportal.kr의 숨은 주소에서 테두리 없이 띄운다. 주소와 일정표 URL은 코드가 아니라 설정값(tp_transis_frame)에 둔다.
 * Version: 1.3.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/19 사용자 결정(A안): 앱 아이콘이 script.google.com을 직접 열면, 구글 계정이 여럿 로그인된 Safari에서
 * 주소에 /u/N/이 붙어 "현재 파일을 열 수 없습니다"가 뜬다. 우리 도메인 페이지 안에 끼워(iframe) 열면
 * Safari가 구글 쿠키를 안 보내서 이 문제가 생기지 않는다.
 *
 * WP 페이지로 만들지 않은 이유: 페이지는 사이트맵·검색·사이트 머리글에 섞인다. 일정표엔 금액·입금자명이 있어서
 * 아는 사람만 들어오는 주소여야 하고, 앱처럼 화면 전체를 일정표로 채워야 한다.
 *
 * 저장소가 공개라 숨은 주소(slug)와 일정표 URL은 코드에 적지 않고 설정값으로 둔다.
 * 값은 REST(/wp/v2/settings, 관리자만)로 넣는다.
 */

const TP_TF_OPTION = 'tp_transis_frame';

require_once __DIR__ . '/push.php';

// 앱 아이콘(마스코트 얼굴). 미디어 1033·1034, 비밀 아님
const TP_TF_ICON_180 = 'https://trendportal.kr/wp-content/uploads/2026/10/transis-app-icon-180.png';
const TP_TF_ICON_512 = 'https://trendportal.kr/wp-content/uploads/2026/10/transis-app-icon-512.png';

/*
 * 1.1.0 (10/19 사용자 결정): 폰에서 Safari "홈 화면에 추가"로 깔면 주소창 없이 앱처럼 뜨게 한다.
 * 그러려면 "나는 앱이다"라는 설명서(manifest)가 있어야 하고, 나중에 웹 푸시 알림을 붙일 때도 이 설치 방식이 조건이다.
 * (설정 파일 mobileconfig로 깐 아이콘이 웹 푸시를 받을 수 있는지는 확인된 자료가 없어서 이 방식으로 바꿈)
 */
function tp_tf_manifest( $slug ) {
	return array(
		'name'             => '트란시스 일정표',
		'short_name'       => '트란시스',
		'lang'             => 'ko',
		'start_url'        => '/' . $slug . '/',
		'scope'            => '/' . $slug . '/',
		'display'          => 'standalone',
		'background_color' => '#ffffff',
		'theme_color'      => '#ffffff',
		'icons'            => array(
			array( 'src' => TP_TF_ICON_180, 'sizes' => '180x180', 'type' => 'image/png' ),
			array( 'src' => TP_TF_ICON_512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
		),
	);
}

add_action(
	'init',
	function () {
		register_setting(
			'options',
			TP_TF_OPTION,
			array(
				'type'         => 'object',
				'default'      => array(),
				'show_in_rest' => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'slug' => array( 'type' => 'string' ),
							'url'  => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}
);

add_action(
	'parse_request',
	function () {
		$opt  = get_option( TP_TF_OPTION );
		$slug = isset( $opt['slug'] ) ? trim( $opt['slug'], '/' ) : '';
		$url  = isset( $opt['url'] ) ? $opt['url'] : '';
		// 설정이 비어 있으면 아무 주소도 가로채지 않는다
		if ( '' === $slug || 0 !== strpos( $url, 'https://script.google.com/' ) ) {
			return;
		}
		$path = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );

		if ( $path === $slug . '/manifest.webmanifest' ) {
			status_header( 200 );
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			echo wp_json_encode( tp_tf_manifest( $slug ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			exit;
		}
		if ( 0 === strpos( $path, $slug . '/' ) ) {
			tp_tf_push_route( substr( $path, strlen( $slug ) + 1 ), $slug );
			return;
		}
		if ( $path !== $slug ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		// 검색에 안 잡히게 + 일정표가 바뀌면 바로 보이게
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store' );
		?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<!-- 구글 쪽에 이 숨은 주소가 '어디서 왔는지'로 남지 않게 -->
<meta name="referrer" content="no-referrer">
<title>트란시스 일정표</title>
<link rel="manifest" href="/<?php echo esc_attr( $slug ); ?>/manifest.webmanifest">
<link rel="apple-touch-icon" href="<?php echo esc_url( TP_TF_ICON_180 ); ?>">
<!-- PC 브라우저 탭 아이콘도 마스코트로 (사용자 요청 10/19) -->
<link rel="icon" type="image/png" sizes="180x180" href="<?php echo esc_url( TP_TF_ICON_180 ); ?>">
<!-- 홈 화면 아이콘 아래 이름, 주소창 없이 열기 -->
<meta name="apple-mobile-web-app-title" content="트란시스">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#111111" media="(prefers-color-scheme: dark)">
<style>
	html, body { margin: 0; height: 100%; background: #fff; }
	iframe { display: block; border: 0; width: 100%; height: 100%; }
	@media (prefers-color-scheme: dark) { html, body { background: #111; } }
</style>
</head>
<body>
<?php
/*
 * credentialless: 이 창 안의 요청엔 쿠키를 싣지 않는다(Chrome·Edge).
 * 1.0.0에서 PC Chrome은 끼운 창에도 구글 쿠키를 보내서 /u/N/ 오류가 그대로 났다.
 * Safari는 이 속성을 모르지만 원래 끼운 창에 구글 쿠키를 안 보낸다.
 */
?>
<iframe src="<?php echo esc_url( $url ); ?>" title="트란시스 일정표" allow="clipboard-write" credentialless></iframe>
<?php tp_tf_push_button( $slug ); ?>
</body>
</html>
		<?php
		exit;
	},
	0
);
