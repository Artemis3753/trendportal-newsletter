<?php
/**
 * Plugin Name: TP Trend Radar
 * Description: 관리자 전용 탭 "트란시스 특파원"(처음 이름은 트렌드 레이더).매일 아침 클라우드 routine(tools/radar/radar.py)이 고른 카드뉴스 거리를 REST로 받아 날짜별로 쌓고, 트란시스 발신메일봇으로 알림 메일을 보낸다.
 * Version: 1.2.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/20 사용자 결정: 거리는 관리자 탭에 쌓고(지난 거리도 다시 볼 수 있게), 알림은 메일로.
 * 메일은 사이트 메일(wp_mail)이 아니라 트란시스 발신메일봇(transis/mailer/Mailer.gs) — 성현 님 Gmail이 이미 스팸에서 풀어 둔 발신자라서.
 * 봇 주소·열쇠는 이 사이트 옵션에 둔다. 그래서 클라우드 routine은 사이트 열쇠(셰프와 같은 것) 하나만 있으면 된다.
 *
 * 저장: 옵션 두 개(autoload 끔 — 이 탭과 REST에서만 읽는다).
 *   tp_radar_days    날짜(Y-m-d) → { items, at, mail }   최근 TP_RADAR_KEEP일만
 *   tp_radar_offered 후보로 한 번 보여 준 링크 → 시각     다음 날 같은 글을 Claude에게 다시 읽히지 않으려고(토큰)
 */

const TP_RADAR_SLUG = 'tp-trend-radar';
const TP_RADAR_KEEP = 120; // 거리를 남겨 두는 날 수
const TP_RADAR_SEEN = 30;  // 이미 본 링크를 기억하는 날 수 (정보원의 새 글 창보다 넉넉하게)

// ── 저장 ────────────────────────────────────────────────

function tp_radar_days() {
	$days = get_option( 'tp_radar_days', array() );
	return is_array( $days ) ? $days : array();
}

function tp_radar_clean_item( $it ) {
	return array(
		'source' => sanitize_text_field( $it['source'] ?? '' ),
		'title'  => sanitize_text_field( $it['title'] ?? '' ),  // Claude가 쓴 한국어 제목
		'note'   => sanitize_text_field( $it['note'] ?? '' ),   // 무엇이고 왜 거리인지 한두 문장
		'orig'   => sanitize_text_field( $it['orig'] ?? '' ),   // 원문 제목 (원문 확인용)
		'link'   => esc_url_raw( $it['link'] ?? '' ),
		'date'   => sanitize_text_field( $it['date'] ?? '' ),   // 원문 날짜 Y-m-d
		'image'  => esc_url_raw( $it['image'] ?? '' ),          // 원문 대표 사진(radar.py가 Photon으로 줄인 주소). 없을 수 있다
		// 영어 원문일 때만: Claude가 본문을 우리 카드 말투로 녹인 핵심 정리(10/20 사용자 A안 — 전문 번역 대신)
		'points' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $it['points'] ?? array() ) ) ) ), 0, 5 ),
	);
}

// ── 메일 ────────────────────────────────────────────────

function tp_radar_weekday( $ymd ) {
	$t = strtotime( $ymd . ' 12:00:00' );
	return wp_date( 'n월 j일', $t ) . ' (' . array( '일', '월', '화', '수', '목', '금', '토' )[ (int) wp_date( 'w', $t ) ] . ')';
}

// 정보원 알약 색: 탭과 메일이 같은 색을 쓴다 (배경 · 테두리 · 글자)
function tp_radar_chip_color( $source ) {
	$map = array(
		'Famous Campaigns' => array( '#d9f1e3', '#2f9e6b', '#13784f' ),
		'heyPOP'           => array( '#fde3da', '#e4572e', '#b8432a' ),
		'WLDO'             => array( '#dde9ff', '#3a86ff', '#1f5fc4' ),
		'a:mag'            => array( '#ebe5ff', '#7a5af8', '#5b3fd0' ),
	);
	return $map[ $source ] ?? array( '#efe3cf', '#8c7f73', '#5a4a42' );
}

// 항공우편 띠(빨강·흰·파랑) — 해외 소식을 부쳐 오는 특파원이라서. 탭과 메일이 같은 색
const TP_RADAR_AIR = array( '#e4572e', '#fffdf8', '#3a86ff', '#fffdf8' );

/**
 * 메일 본문 — 탭과 같은 '스티커 주방' 모양(10/20 사용자: A 바탕 + C의 맨 위 + 이름 "트란시스 특파원").
 * Gmail은 그림자·기울이기·겹치기·웹 글꼴·그라데이션을 지운다 → 그런 것 없이 테두리·둥근 모서리·색만으로 같은 느낌을 낸다.
 * 모자 쓴 얼굴은 겹치기 대신 한 장으로 합친 그림(press.png), 항공우편 띠는 그라데이션 대신 색칠한 칸을 늘어놓는다.
 * 바탕색이 지워져도 흰 바탕에 갈색 글자라 읽히는 데는 문제가 없다(10/14 트란시스 메일 원칙: 스타일을 버려도 안 깨지게).
 */
function tp_radar_mail_html( $ymd, $items, $tab ) {
	$ink   = '#3b2f2a';
	$air   = '';
	for ( $i = 0; $i < 24; $i++ ) {
		$air .= '<td style="height:8px;font-size:0;line-height:0;background:' . TP_RADAR_AIR[ $i % 4 ] . ';">&nbsp;</td>';
	}
	$cards = '';
	foreach ( $items as $i => $it ) {
		list( $bg, $line, $fg ) = tp_radar_chip_color( $it['source'] );
		$img    = $it['image'] ? '<a href="' . esc_url( $it['link'] ) . '"><img src="' . esc_url( $it['image'] ) . '" width="100%" alt="" style="display:block;width:100%;height:auto;border:0;border-radius:15px 15px 0 0;border-bottom:2.5px solid ' . $ink . ';"></a>' : '';
		$cards .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:2.5px solid ' . $ink . ';border-radius:18px;background:#fffdf8;border-collapse:separate;">'
			. '<tr><td style="padding:0;">' . $img . '</td></tr>'
			. '<tr><td style="padding:12px 14px 14px;">'
			. '<span style="display:inline-block;width:26px;height:26px;margin-right:6px;border:2px solid ' . $ink . ';border-radius:50%;background:#ffe45c;color:' . $ink . ';font-size:14px;font-weight:700;line-height:26px;text-align:center;vertical-align:middle;">' . ( $i + 1 ) . '</span>'
			. '<span style="display:inline-block;padding:2px 10px;border:1.5px solid ' . $line . ';border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:700;vertical-align:middle;">' . esc_html( $it['source'] . ' · ' . substr( $it['date'], 5 ) ) . '</span>'
			. '<div style="margin:8px 0 4px;font-size:18px;font-weight:700;line-height:1.35;"><a href="' . esc_url( $it['link'] ) . '" style="color:' . $ink . ';text-decoration:none;">' . esc_html( $it['title'] ) . '</a></div>'
			. '<div style="font-size:14px;line-height:1.6;color:' . $ink . ';">' . esc_html( $it['note'] ) . '</div>'
			. tp_radar_mail_points( $it['points'] ?? array() )
			. '</td></tr></table>';
	}
	return '<div style="background:#fbf4e6;padding:24px 14px;">'
		. '<div style="max-width:520px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Apple SD Gothic Neo\',\'Malgun Gothic\',sans-serif;color:' . $ink . ';">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:2px solid ' . $ink . ';border-radius:6px;border-collapse:separate;overflow:hidden;"><tr>' . $air . '</tr></table>'
		. '<div style="text-align:center;margin:0 0 20px;">'
		. '<img src="' . esc_url( plugins_url( 'press.png', __FILE__ ) ) . '" width="88" height="88" alt="트란시스 특파원" style="display:inline-block;width:88px;height:88px;border:0;">'
		. '<div style="margin:4px 0 2px;font-size:23px;font-weight:800;">특파원 리포트 · 거리 ' . count( $items ) . '개</div>'
		. '<div style="font-size:13px;color:#8c7f73;">트란시스 특파원 · ' . esc_html( tp_radar_weekday( $ymd ) ) . ' 송고</div>'
		. '</div>'
		. $cards
		. '<div style="text-align:center;margin-top:6px;"><a href="' . esc_url( $tab ) . '" style="display:inline-block;padding:9px 18px;border:2.5px solid ' . $ink . ';border-radius:999px;background:#fffdf8;color:' . $ink . ';text-decoration:none;font-weight:700;font-size:14px;">✈️ 지난 리포트까지 보기</a></div>'
		. '</div></div>';
}

// 핵심 정리 상자(메일). 목록 태그(ul)는 메일 앱마다 들여쓰기가 달라서 줄마다 '·'를 붙인 문단으로
function tp_radar_mail_points( $points ) {
	if ( ! $points ) {
		return '';
	}
	$rows = '';
	foreach ( $points as $p ) {
		$rows .= '<div style="margin:3px 0;">· ' . esc_html( $p ) . '</div>';
	}
	return '<div style="margin-top:10px;padding:10px 12px;border:1.5px dashed #dcc9ab;border-radius:12px;background:#fdf8ee;font-size:13.5px;line-height:1.6;color:#3b2f2a;">'
		. '<div style="margin-bottom:4px;font-weight:700;">📝 핵심 정리</div>' . $rows . '</div>';
}

function tp_radar_mail_text( $ymd, $items, $tab ) {
	$out = '트란시스 특파원 리포트 · ' . tp_radar_weekday( $ymd ) . "\n\n";
	foreach ( $items as $i => $it ) {
		$out .= ( $i + 1 ) . '. ' . $it['title'] . ' (' . $it['source'] . ")\n" . $it['note'] . "\n";
		foreach ( (array) ( $it['points'] ?? array() ) as $p ) {
			$out .= '  · ' . $p . "\n";
		}
		$out .= $it['link'] . "\n\n";
	}
	return $out . '탭: ' . $tab . "\n";
}

/**
 * 발신메일봇에 보내고 결과를 한 단어로: sent · duplicate · 그 밖은 오류 문장.
 * 미리보기는 지환 님에게만, 같은 날 한 번만 규칙(id)도 안 건다 — 시험을 여러 번 해도 진짜 9시 메일이 막히지 않게.
 */
function tp_radar_send_mail( $ymd, $items, $preview ) {
	$cfg = get_option( 'tp_radar_mail', array() );
	if ( empty( $cfg['url'] ) || empty( $cfg['token'] ) || empty( $cfg['to'] ) ) {
		return '메일 설정이 없어요';
	}
	$tab     = admin_url( 'admin.php?page=' . TP_RADAR_SLUG );
	$subject = '📰 특파원 리포트: 거리 ' . count( $items ) . '개 · ' . tp_radar_weekday( $ymd );
	$mail    = array(
		'token'   => $cfg['token'],
		'subject' => $preview ? '[미리보기] ' . $subject : $subject,
		'html'    => tp_radar_mail_html( $ymd, $items, $tab ),
		'text'    => tp_radar_mail_text( $ymd, $items, $tab ),
	);
	if ( $preview ) {
		$mail['to'] = array( $cfg['preview'] ?? $cfg['bcc'][0] ?? '' );
	} else {
		$mail['to']  = (array) $cfg['to'];
		$mail['bcc'] = (array) ( $cfg['bcc'] ?? array() );
		$mail['id']  = 'radar:' . $ymd;
	}
	// Apps Script 웹앱은 POST에 302로 답하고, 결과는 넘겨준 주소를 GET으로 읽어야 나온다.
	// 워드프레스 HTTP는 302를 따라갈 때 POST를 그대로 다시 보내서 실패한다 → 넘겨받기는 직접 GET으로
	$r = wp_remote_post(
		$cfg['url'],
		array(
			'body'        => wp_json_encode( $mail, JSON_UNESCAPED_UNICODE ),
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'timeout'     => 40,
			'redirection' => 0,
		)
	);
	if ( ! is_wp_error( $r ) && in_array( wp_remote_retrieve_response_code( $r ), array( 301, 302, 303 ), true ) ) {
		$r = wp_remote_get( wp_remote_retrieve_header( $r, 'location' ), array( 'timeout' => 40 ) );
	}
	if ( is_wp_error( $r ) ) {
		return '메일 보내기 실패: ' . $r->get_error_message();
	}
	$res = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $res['ok'] ) ) {
		return '메일 보내기 실패: ' . ( $res['error'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
	}
	return ! empty( $res['duplicate'] ) ? 'duplicate' : 'sent';
}

// ── REST (routine이 쓰는 창구) ───────────────────────────

add_action(
	'rest_api_init',
	function () {
		$admin = function () {
			return current_user_can( 'manage_options' ); // 애플리케이션 비밀번호로 들어온 관리자만
		};

		// 이미 후보로 보여 준 링크. radar.py collect가 이걸 빼고 새 글만 Claude에게 넘긴다
		register_rest_route(
			'tp-radar/v1',
			'/seen',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function () {
					return array( 'links' => array_keys( (array) get_option( 'tp_radar_offered', array() ) ) );
				},
			)
		);

		// 오늘의 거리 받기 → 저장 → 메일. 같은 날 두 번 오면 거리는 새것으로 바꾸고, 메일은 봇이 두 번째를 버린다
		register_rest_route(
			'tp-radar/v1',
			'/days',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) {
					$ymd = (string) $req->get_param( 'date' );
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
						return new WP_Error( 'tp_radar_date', 'date는 YYYY-MM-DD예요', array( 'status' => 400 ) );
					}
					$items = array_values( array_map( 'tp_radar_clean_item', (array) $req->get_param( 'items' ) ) );
					if ( ! $items ) {
						return new WP_Error( 'tp_radar_empty', '거리가 없어요', array( 'status' => 400 ) );
					}
					$preview = (bool) $req->get_param( 'preview' );
					$mail    = tp_radar_send_mail( $ymd, $items, $preview );

					$days         = tp_radar_days();
					$days[ $ymd ] = array( 'items' => $items, 'at' => time(), 'mail' => $preview ? 'preview:' . $mail : $mail );
					krsort( $days );
					update_option( 'tp_radar_days', array_slice( $days, 0, TP_RADAR_KEEP, true ), false );

					// 미리보기는 "봤다"로 치지 않는다 — 시험한 날에도 다음 날 진짜 실행이 같은 후보를 다시 고를 수 있게
					if ( ! $preview ) {
						$seen = (array) get_option( 'tp_radar_offered', array() );
						foreach ( (array) $req->get_param( 'offered' ) as $link ) {
							$seen[ esc_url_raw( $link ) ] = time();
						}
						$cut  = time() - TP_RADAR_SEEN * DAY_IN_SECONDS;
						$seen = array_filter(
							$seen,
							function ( $t ) use ( $cut ) {
								return $t > $cut;
							}
						);
						update_option( 'tp_radar_offered', $seen, false );
					}
					return array( 'date' => $ymd, 'count' => count( $items ), 'mail' => $mail );
				},
			)
		);

		// 메일 설정(봇 주소·열쇠·받는 사람). 저장소가 공개라 코드에 적지 않고 여기로 넣는다. 열쇠는 돌려주지 않는다
		register_rest_route(
			'tp-radar/v1',
			'/mail',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) {
					$cfg = (array) get_option( 'tp_radar_mail', array() );
					foreach ( array( 'url', 'token', 'preview' ) as $k ) {
						if ( null !== $req->get_param( $k ) ) {
							$cfg[ $k ] = sanitize_text_field( $req[ $k ] );
						}
					}
					foreach ( array( 'to', 'bcc' ) as $k ) {
						if ( null !== $req->get_param( $k ) ) {
							$cfg[ $k ] = array_values( array_filter( array_map( 'sanitize_email', (array) $req[ $k ] ) ) );
						}
					}
					update_option( 'tp_radar_mail', $cfg, false );
					return array(
						'url'     => ! empty( $cfg['url'] ),
						'token'   => ! empty( $cfg['token'] ),
						'to'      => $cfg['to'] ?? array(),
						'bcc'     => $cfg['bcc'] ?? array(),
						'preview' => $cfg['preview'] ?? '',
					);
				},
			)
		);
	}
);

// ── 탭 화면 ──────────────────────────────────────────────

add_action(
	'admin_menu',
	function () {
		// 카드뉴스 도우미(3) 바로 아래. 거리를 보고 → 카드를 만들고 → 도우미에 주문하는 순서라서
		// 화면 이름은 "트란시스 특파원"(10/20 사용자). 코드 안 이름(radar)은 처음 이름 그대로 둔다
		add_menu_page( '트란시스 특파원', '트란시스 특파원', 'manage_options', TP_RADAR_SLUG, 'tp_radar_page', 'dashicons-airplane', '3.1' );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'toplevel_page_' . TP_RADAR_SLUG !== $hook ) {
			return;
		}
		// 카드뉴스 도우미 탭과 같은 글꼴(둘 다 SIL OFL 1.1)
		wp_enqueue_style( 'tp-radar-fonts', 'https://fonts.googleapis.com/css2?family=Jua&family=IBM+Plex+Sans+KR:wght@400;500;700&display=swap', array(), null );
	}
);

function tp_radar_page() {
	$days = tp_radar_days();
	// 처음엔 2주만. 더 보기를 누를 때마다 2주씩
	$show  = min( TP_RADAR_KEEP, max( 14, (int) ( $_GET['show'] ?? 14 ) ) );
	$more  = count( $days ) > $show;
	$days  = array_slice( $days, 0, $show, true );
	$first = $days ? reset( $days ) : null;
	?>
<div class="tprd">
<style>
/* 10/20 사용자 결정: A 스티커 주방(카드뉴스 도우미 탭과 한 가족) + 맨 위만 C 스크랩북처럼 가운데 얼굴·큰 제목,
   이름 "트란시스 특파원"에 맞춰 항공우편 띠 · PRESS 모자 · 날짜 소인 도장 */
body.toplevel_page_tp-trend-radar #wpcontent, body.toplevel_page_tp-trend-radar #wpfooter { background: #fbf4e6 radial-gradient(#ead9bb 1.2px, transparent 1.3px) 0 0 / 16px 16px; }
.tprd { --ink: #3b2f2a; --muted: #8c7f73; --card: #fffdf8; --post: #3a5bbf;
  max-width: 980px; margin: 18px 20px 40px 0; color: var(--ink); font-family: "IBM Plex Sans KR", "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; font-size: 14px; line-height: 1.55; word-break: keep-all; }
.tprd * { box-sizing: border-box; }
/* 맨 위 항공우편 띠: 셰프 탭의 깅엄 행주 띠 자리 */
.tprd-air { height: 14px; border: 2.5px solid var(--ink); border-radius: 999px;
  background: repeating-linear-gradient(-45deg, #e4572e 0 12px, #fffdf8 12px 20px, #3a86ff 20px 32px, #fffdf8 32px 40px); }
.tprd-head { text-align: center; margin: 14px 0 30px; }
.tprd-head img { width: 104px; height: 104px; }
.tprd-head h1 { margin: 2px 0 4px; padding: 0; font: 400 30px/1.15 "Jua", sans-serif; color: var(--ink); }
.tprd-head p { margin: 0; color: var(--muted); font-size: 13px; }
.tprd-head p b { display: inline-block; margin-right: 6px; padding: 1px 9px; border-radius: 6px; background: var(--ink); color: #fffdf8; font-size: 11px; letter-spacing: .08em; }
@media (prefers-reduced-motion: no-preference) {
  .tprd-head img { animation: tprd-bob 3s ease-in-out infinite; }
  @keyframes tprd-bob { 50% { transform: translateY(-4px) rotate(-3deg); } }
}
.tprd-day { margin: 0 0 34px; }
.tprd-day h2 { display: flex; align-items: center; gap: 12px; margin: 0 0 16px; font: 400 20px/1.2 "Jua", sans-serif; color: var(--ink); }
/* 날짜 = 우체국 소인 도장 */
.tprd-stamp { flex: none; display: grid; place-items: center; width: 66px; height: 66px; border: 3px double var(--post); border-radius: 50%; color: var(--post); transform: rotate(-12deg); text-align: center; line-height: 1; opacity: .9; }
.tprd-stamp small { display: block; font: 700 8px/1 "IBM Plex Sans KR", sans-serif; letter-spacing: .12em; }
.tprd-stamp strong { display: block; margin: 3px 0 2px; font: 400 17px/1 "Jua", sans-serif; }
.tprd-mail { padding: 2px 10px; border-radius: 999px; background: #d9f1e3; color: #13784f; font: 700 12px/1.5 "IBM Plex Sans KR", sans-serif; }
.tprd-mail.bad { background: #fde3da; color: #b42333; }
.tprd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 22px 18px; }
.tprd-card { position: relative; display: flex; flex-direction: column; background: var(--card); border: 2.5px solid var(--ink); border-radius: 18px; box-shadow: 5px 5px 0 var(--ink); overflow: hidden; transition: transform .15s, box-shadow .15s; }
.tprd-card:hover { transform: translate(-2px, -2px); box-shadow: 7px 7px 0 var(--ink); }
.tprd-card > a { display: block; aspect-ratio: 16 / 9; border-bottom: 2.5px solid var(--ink); background: #efe3cf; }
.tprd-card > a img { display: block; width: 100%; height: 100%; object-fit: cover; }
.tprd-no { position: absolute; top: 10px; left: 10px; width: 36px; height: 36px; border-radius: 50%; background: #ffe45c; border: 2.5px solid var(--ink); font: 400 19px/31px "Jua", sans-serif; text-align: center; transform: rotate(-8deg); }
.tprd-bd { padding: 12px 14px 14px; }
.tprd-chip { display: inline-block; padding: 2px 10px; border-radius: 999px; border: 1.5px solid; font-size: 11.5px; font-weight: 700; }
.tprd-bd h3 { margin: 7px 0 4px; font: 400 19px/1.3 "Jua", sans-serif; }
.tprd-bd h3 a { color: var(--ink); text-decoration: none; }
.tprd-bd h3 a:hover { text-decoration: underline; }
.tprd-bd p { margin: 0; font-size: 13.5px; line-height: 1.6; }
.tprd-bd .tprd-orig { margin-top: 6px; font-size: 12px; color: var(--muted); }
/* 영어 원문 거리의 핵심 정리: 셰프 탭의 안내 상자처럼 점선 메모 */
.tprd-pts { margin-top: 10px; padding: 10px 12px; border: 1.5px dashed #dcc9ab; border-radius: 12px; background: #fdf8ee; font-size: 13px; }
.tprd-pts b { display: block; margin-bottom: 2px; }
.tprd-pts ul { margin: 0; padding-left: 16px; list-style: "· "; }
.tprd-pts li { margin: 2px 0; }
.tprd-more { display: inline-block; padding: 8px 18px; border: 2.5px solid var(--ink); border-radius: 999px; background: var(--card); color: var(--ink); font-weight: 700; text-decoration: none; box-shadow: 3px 3px 0 var(--ink); }
</style>
<div class="tprd-air"></div>
<div class="tprd-head">
<img src="<?php echo esc_url( plugins_url( 'press.png', __FILE__ ) ); ?>" alt="트란시스 특파원">
<h1><?php echo $first ? '특파원 리포트 · 거리 ' . count( (array) $first['items'] ) . '개' : '트란시스 특파원'; ?></h1>
<p><b>PRESS</b>트란시스 특파원이 매일 아침 9시에 카드뉴스 거리를 송고해요</p>
</div>
	<?php if ( ! $days ) : ?>
<p style="text-align:center">아직 도착한 리포트가 없어요. 아침 9시에 첫 리포트가 와요.</p>
	<?php endif; ?>
	<?php foreach ( $days as $ymd => $day ) : ?>
		<?php
		$mail = (string) ( $day['mail'] ?? '' );
		$ok   = in_array( $mail, array( 'sent', 'duplicate' ), true ) || 0 === strpos( $mail, 'preview:sent' );
		$says = 'sent' === $mail ? '메일 보냄' : ( 'duplicate' === $mail ? '메일은 이미 보냄' : ( 0 === strpos( $mail, 'preview:' ) ? '미리보기 메일만' : $mail ) );
		?>
<section class="tprd-day">
<h2><span class="tprd-stamp" aria-hidden="true"><span><small>AIR MAIL</small><strong><?php echo esc_html( substr( $ymd, 5, 2 ) . '.' . substr( $ymd, 8, 2 ) ); ?></strong><small>SEOUL</small></span></span><?php echo esc_html( tp_radar_weekday( $ymd ) ); ?> 리포트 <span class="tprd-mail<?php echo $ok ? '' : ' bad'; ?>"><?php echo esc_html( $says ); ?></span></h2>
<div class="tprd-grid">
		<?php foreach ( array_values( (array) $day['items'] ) as $i => $it ) : ?>
			<?php list( $bg, $line, $fg ) = tp_radar_chip_color( $it['source'] ); ?>
<article class="tprd-card">
			<?php if ( ! empty( $it['image'] ) ) : ?>
<a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $it['image'] ); ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></a>
			<?php endif; ?>
<span class="tprd-no"><?php echo (int) $i + 1; ?></span>
<div class="tprd-bd"<?php echo empty( $it['image'] ) ? ' style="padding-top:54px"' : ''; ?>>
<span class="tprd-chip" style="background:<?php echo esc_attr( $bg ); ?>;border-color:<?php echo esc_attr( $line ); ?>;color:<?php echo esc_attr( $fg ); ?>"><?php echo esc_html( $it['source'] . ' · ' . substr( $it['date'], 5 ) ); ?></span>
<h3><a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a></h3>
<p><?php echo esc_html( $it['note'] ); ?></p>
			<?php if ( ! empty( $it['points'] ) ) : ?>
<div class="tprd-pts"><b>📝 핵심 정리</b><ul>
				<?php foreach ( $it['points'] as $pt ) : ?>
<li><?php echo esc_html( $pt ); ?></li>
				<?php endforeach; ?>
</ul></div>
			<?php endif; ?>
<p class="tprd-orig">원문: <?php echo esc_html( $it['orig'] ); ?></p>
</div>
</article>
		<?php endforeach; ?>
</div>
</section>
	<?php endforeach; ?>
	<?php if ( $more ) : ?>
<p style="text-align:center"><a class="tprd-more" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&show=' . ( $show + 14 ) ) ); ?>">✈️ 지난 리포트 더 보기</a></p>
	<?php endif; ?>
</div>
	<?php
}
