<?php
/**
 * Plugin Name: TP Transis Frame
 * Description: 트란시스 일정표(Apps Script)를 trendportal.kr의 숨은 주소에서 테두리 없이 띄운다. 주소와 일정표 URL은 코드가 아니라 설정값(tp_transis_frame)에 둔다.
 * Version: 1.0.1
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
</body>
</html>
		<?php
		exit;
	},
	0
);
