<?php
/**
 * Plugin Name: TP Trend Radar
 * Description: 관리자 전용 탭 "트란시스 특파원"(처음 이름은 트렌드 레이더).매일 아침 클라우드 routine(tools/radar/radar.py)이 고른 카드뉴스 거리를 REST로 받아 날짜별로 쌓고, 트란시스 발신메일봇으로 알림 메일을 보낸다.
 * Version: 1.5.0
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
/**
 * 원문이 외국어면 그 언어 이름, 한국어면 ''. 원문 제목에 한글이 없으면 외국어로 본다(radar.py is_english와 같은 기준).
 * 10/20 사용자: 외국어 원문은 정보가 더 많고 특파원이 번역한 것이라 그 사실을 보여 준다
 */
function tp_radar_lang( $orig ) {
	if ( preg_match( '/[가-힣]/u', $orig ) ) {
		return '';
	}
	return preg_match( '/[\x{3040}-\x{30ff}]/u', $orig ) ? '일본어' : '영어'; // 가나가 있으면 일본어
}

// 번역 표시 알약 색(보라): 정보원 알약과 겹치지 않게
const TP_RADAR_TR = array( '#ebe5ff', '#7a5af8', '#5b3fd0' );

/**
 * 정보원 표 — 정보원은 여기 한 곳에만 적는다: 이름 => 나라 한 글자, 갈래, 읽는 법, 주소.
 * 탭의 실시간 피드(이 파일)와 아침 리포트(tools/radar/radar.py, REST /sources로 이 표를 받는다)가 같은 표를 쓴다.
 *
 * 고른 근거(10/20, 사용자가 추천안 선택 + 밈·스포츠 2곳 → 오후에 불안정한 유튜브 2·레딧 2곳을 빼서 15곳): 인스타 892개에서 화제가 된 비율
 * (하트 공개 또는 저장 1,000회+)이 밈 59%·웹·앱 54%·플랫폼 40%·트렌드 39%·스포츠 37%, 국내 브랜드 뉴스 17%.
 * 근거 보고서: TP_RADAR_REPORT. 나라는 사이트 소개·약관으로 확인한 운영사 소재지.
 * 읽는 법: rss(RSS·Atom) · reddit(소개된 사이트 주소를 링크로) · gtrends(검색어 + 관련 기사) · wpjson · youtube(채널 id).
 * 읽는 법이 ''이면 읽지 않는다 — 뺀 정보원도 지난 리포트의 알약 색 때문에 남겨 둔다.
 */
const TP_RADAR_SOURCES = array(
	// 해외 캠페인·스포츠 — 하트가 잘 나오는 갈래. Famous Campaigns는 우리 카드 출처 1위(46편), 런던 Speakeasy Media
	'Famous Campaigns'      => array( '영', 'camp', 'rss', 'https://www.famouscampaigns.com/feed/' ), // www 없는 주소는 526
	'Muse by Clio'          => array( '미', 'camp', 'rss', 'https://musebycl.io/rss.xml' ),
	'AdverTimes'            => array( '일', 'camp', 'rss', 'https://www.advertimes.com/feed/' ),
	'Front Office Sports'   => array( '미', 'camp', 'rss', 'https://frontofficesports.com/feed/' ), // 스포츠 비즈니스·마케팅
	// 플랫폼·크리에이터 — "인스타그램 이렇게 변합니다" 같은 글의 출처가 이 매체들이었다
	'Social Media Today'    => array( '미', 'plat', 'rss', 'https://www.socialmediatoday.com/feeds/news/' ),
	'TechCrunch'            => array( '미', 'plat', 'rss', 'https://techcrunch.com/category/social/feed/' ), // 소셜 갈래만
	'The Verge'             => array( '미', 'plat', 'rss', 'https://www.theverge.com/rss/index.xml' ),
	// 웹·앱·디지털 놀이 — "신기한 사이트 모음" 갈래
	'GIGAZINE'              => array( '일', 'web', 'rss', 'https://gigazine.net/news/rss_2.0/' ),
	'Laughing Squid'        => array( '미', 'web', 'rss', 'https://laughingsquid.com/feed/' ), // ねとらぼ 자리 — 그쪽 RSS는 2025-05에 멈춤
	// 밈·트렌드 — 화제 비율 1위 갈래
	'Know Your Meme'        => array( '미', 'trend', 'rss', 'https://knowyourmeme.com/newsfeed.rss' ),
	'Google 트렌드'          => array( '한', 'trend', 'gtrends', 'https://trends.google.com/trending/rss?geo=KR' ),
	'Mashable'              => array( '미', 'trend', 'rss', 'https://mashable.com/feeds/rss/all' ),
	'오픈서베이'             => array( '한', 'trend', 'rss', 'https://blog.opensurvey.co.kr/feed/' ),
	'매드타임스'             => array( '한', 'trend', 'rss', 'https://www.madtimes.co.kr/rss/allArticle.xml' ),
	// 국내 팝업 — /feed/는 2021년에 멈춘 기본 글 종류라 못 쓰고, 실제 기사는 'n' 글 종류
	'heyPOP'                => array( '한', 'kr', 'wpjson', 'https://heypop.kr/wp-json/wp/v2/n?per_page=20&_fields=date,link,title,excerpt' ),
	// 10/20 오후에 뺀 곳 — 지난 리포트의 알약 색 때문에만 남긴다(읽는 법 '').
	// 사용자 원칙: "작동 안 하면 빼 버려. 정상적인 컨디션에서 양질을 검토하는 게 목표".
	// WLDO·a:mag = 유튜브 RSS가 이 두 채널만 오후 내내 404(채널 id는 맞음, 다른 채널은 200).
	// 레딧 = 부를 때마다 막히는 게시판이 바뀌는 요청 제한(429). 다시 넣을 땐 reader(youtube·reddit)는 그대로 있다
	'WLDO'                  => array( '한', 'camp', '', '' ),
	'a:mag'                 => array( '한', 'camp', '', '' ),
);
// 성현 님이 원래 보던 곳 중 못 넣은 곳(10/20 확인): exchange4media(Cloudflare가 프로그램 접근 403), X·틱톡(RSS 없음, 로그인 필요)

// 정보원별로 거를 제목(대소문자 무시). Mashable은 하루 100개 중 경기 중계·퍼즐 정답·할인 글이 많다(10/20 실측)
// PHP와 Python(radar.py) 둘 다 이 문자열을 정규식으로 쓴다 → 두 쪽에 공통인 문법만
const TP_RADAR_SKIP = array(
	'Mashable' => 'how to watch|hints|answers|wordle|\bnyt\b|\bdeals?\b|% off|where to buy|how to buy|lowest price|price drop',
);

// 정보원 재정비 보고서(10/20, 비공개 — 성현 님은 공유받아야 열림). 정보원이 바뀌면 Claude가 같이 고친다
const TP_RADAR_REPORT = 'https://claude.ai/artifact/7dmz1dLtc67YJihUvzWpRs';

// 갈래 색(배경 · 테두리 · 글자). 보라는 '특파원 번역' 알약 몫이라 쓰지 않는다
const TP_RADAR_GROUP_COLOR = array(
	'camp'  => array( '#d9f1e3', '#2f9e6b', '#13784f' ), // 해외 캠페인·스포츠 — 초록
	'plat'  => array( '#dde9ff', '#3a86ff', '#1f5fc4' ), // 플랫폼·크리에이터 — 파랑
	'web'   => array( '#ffe0ef', '#e0559a', '#b03a76' ), // 웹·앱 놀이 — 분홍
	'trend' => array( '#fff1c2', '#d99a00', '#8a6400' ), // 밈·트렌드 — 노랑
	'kr'    => array( '#fde3da', '#e4572e', '#b8432a' ), // 국내 팝업 — 주황
);
const TP_RADAR_GROUP_NAME = array( 'camp' => '해외 캠페인·스포츠', 'plat' => '플랫폼', 'web' => '웹·앱 놀이', 'trend' => '밈·트렌드', 'kr' => '국내 팝업' );

function tp_radar_chip_color( $source ) {
	$group = TP_RADAR_SOURCES[ $source ][1] ?? '';
	return TP_RADAR_GROUP_COLOR[ $group ] ?? array( '#efe3cf', '#8c7f73', '#5a4a42' );
}

// 정보원 나라 한 글자(한·미·일·영…), 표에 없으면 ''(10/20 사용자 아이디어: 소스의 나라 표기)
function tp_radar_country( $source ) {
	return TP_RADAR_SOURCES[ $source ][0] ?? '';
}

/**
 * 메일용 나라 동그라미(사용자 A안: 알약 맨 앞, 진한 동그라미 안 흰 글자).
 * 국기 이모지는 윈도우에서 'GB' 같은 글자 두 개로 보여서(성현 님 PC) 한 글자로 쓴다.
 * 탭은 같은 모양을 CSS .tprd-flag로 그린다.
 */
function tp_radar_flag_mail( $source, $ink ) {
	$c = tp_radar_country( $source );
	return $c ? '<span style="display:inline-block;width:17px;height:17px;margin:0 5px 0 -5px;border-radius:50%;background:' . $ink . ';color:#fffdf8;font-size:10px;font-weight:700;line-height:17px;text-align:center;vertical-align:1px;">' . esc_html( $c ) . '</span>' : '';
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
			. '<span style="display:inline-block;padding:2px 10px;border:1.5px solid ' . $line . ';border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:700;vertical-align:middle;">' . tp_radar_flag_mail( $it['source'], $ink ) . esc_html( $it['source'] . ' · ' . substr( $it['date'], 5 ) ) . '</span>'
			. ( tp_radar_lang( $it['orig'] ) ? '<span style="display:inline-block;margin-left:4px;padding:2px 10px;border:1.5px solid ' . TP_RADAR_TR[1] . ';border-radius:999px;background:' . TP_RADAR_TR[0] . ';color:' . TP_RADAR_TR[2] . ';font-size:12px;font-weight:700;vertical-align:middle;">🌐 특파원 번역</span>' : '' )
			. '<div style="margin:8px 0 4px;font-size:18px;font-weight:700;line-height:1.35;"><a href="' . esc_url( $it['link'] ) . '" style="color:' . $ink . ';text-decoration:none;">' . esc_html( $it['title'] ) . '</a></div>'
			. '<div style="font-size:14px;line-height:1.6;color:' . $ink . ';">' . esc_html( $it['note'] ) . '</div>'
			. tp_radar_mail_points( $it['points'] ?? array(), tp_radar_lang( $it['orig'] ) )
			. '</td></tr></table>';
	}
	return '<div style="background:#fbf4e6;padding:24px 14px;">'
		. '<div style="max-width:520px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Apple SD Gothic Neo\',\'Malgun Gothic\',sans-serif;color:' . $ink . ';">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:2px solid ' . $ink . ';border-radius:6px;border-collapse:separate;overflow:hidden;"><tr>' . $air . '</tr></table>'
		. '<div style="text-align:center;margin:0 0 20px;">'
		. '<img src="' . esc_url( plugins_url( 'press.png', __FILE__ ) ) . '" width="88" height="88" alt="트란시스 특파원" style="display:inline-block;width:88px;height:88px;border:0;">'
		. '<div style="margin:4px 0 2px;font-size:23px;font-weight:800;">특파원 리포트 - 기사 ' . count( $items ) . '개</div>'
		. '<div style="font-size:13px;color:#8c7f73;">트란시스 특파원 · ' . esc_html( tp_radar_weekday( $ymd ) ) . ' 송고</div>'
		. '</div>'
		. $cards
		. '<div style="text-align:center;margin-top:6px;"><a href="' . esc_url( $tab ) . '" style="display:inline-block;padding:9px 18px;border:2.5px solid ' . $ink . ';border-radius:999px;background:#fffdf8;color:' . $ink . ';text-decoration:none;font-weight:700;font-size:14px;">✈️ 지난 리포트까지 보기</a></div>'
		. '</div></div>';
}

// 핵심 정리 상자(메일). 목록 태그(ul)는 메일 앱마다 들여쓰기가 달라서 줄마다 '·'를 붙인 문단으로
function tp_radar_mail_points( $points, $lang = '' ) {
	if ( ! $points ) {
		return '';
	}
	$rows = '';
	foreach ( $points as $p ) {
		$rows .= '<div style="margin:3px 0;">· ' . esc_html( $p ) . '</div>';
	}
	return '<div style="margin-top:10px;padding:10px 12px;border:1.5px dashed #dcc9ab;border-radius:12px;background:#fdf8ee;font-size:13.5px;line-height:1.6;color:#3b2f2a;">'
		. '<div style="margin-bottom:4px;font-weight:700;">📝 핵심 정리' . ( $lang ? '<span style="font-weight:400;color:#8c7f73;"> · ' . $lang . ' 원문을 특파원이 번역해 정리했어요</span>' : '' ) . '</div>' . $rows . '</div>';
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
	$subject = '📰 특파원 리포트 - 기사 ' . count( $items ) . '개 · ' . tp_radar_weekday( $ymd );
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

// ── 실시간 피드: 정보원 새 글을 사이트가 직접 모은다(토큰 0) ──────────────
/*
 * 10/20 사용자 A안: 탭의 "📰 실시간 피드" 칸. Claude 없이 PHP가 RSS를 읽으니 몇 번을 열어도 비용이 없다.
 * 외국어는 원제목 그대로 — 번역·요약이 필요한 글만 체크해서 "정리 부탁"(아래)으로 특파원을 부른다.
 * 17곳을 차례로 받으면 탭이 십몇 초 멈춰서 한꺼번에(병렬) 받고, 결과는 30분 보관한다.
 */
const TP_RADAR_FEED_TTL  = 30 * MINUTE_IN_SECONDS;
const TP_RADAR_FEED_DAYS = 3; // 이보다 오래된 글은 피드에 안 보인다
const TP_RADAR_FEED_EACH = 8; // 정보원마다 최신 몇 개까지
const TP_RADAR_UA        = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36';

/** 날짜 글자 → 유닉스 시각. 매드타임스처럼 시간대가 없으면 한국 매체라 KST로 본다(radar.py when()과 같은 규칙) */
function tp_radar_when( $s ) {
	$s = trim( (string) $s );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $s ) ) {
		$s .= ' +0900';
	}
	return (int) strtotime( $s );
}

function tp_radar_plain( $s ) {
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * 피드 작은 사진 주소. 원본은 수 MB짜리도 있어서 WordPress.com 사진 변환기(Photon)로 192×120으로 줄여 받는다
 * (radar.py image_of와 같은 방법). 구글 트렌드(gstatic)·유튜브 썸네일은 Photon이 받지 않아 그대로.
 */
function tp_radar_thumb( $url ) {
	$url = html_entity_decode( trim( (string) $url ) );
	if ( ! preg_match( '#^https?://#', $url ) ) {
		return '';
	}
	if ( preg_match( '#gstatic\.com|ytimg\.com#', $url ) ) {
		return esc_url_raw( $url );
	}
	return esc_url_raw( 'https://i0.wp.com/' . preg_replace( '#^https?://#', '', strtok( $url, '?' ) ) . '?resize=192,120&ssl=1' );
}

/** RSS 글 하나에 딸린 사진 — media:content·enclosure·본문 첫 <img> 순서(10/20 실측: 15곳 중 6곳이 RSS에 사진을 넣어 준다) */
function tp_radar_item_img( $it, $html ) {
	$media = $it->children( 'http://search.yahoo.com/mrss/' );
	foreach ( array( 'content', 'thumbnail' ) as $tag ) {
		if ( isset( $media->$tag ) && (string) $media->$tag->attributes()->url ) {
			return (string) $media->$tag->attributes()->url;
		}
	}
	if ( isset( $it->enclosure ) && 0 === strpos( (string) $it->enclosure['type'], 'image' ) ) {
		return (string) $it->enclosure['url'];
	}
	return preg_match( '/<img[^>]+src=["\']([^"\']+)/i', $html, $m ) ? $m[1] : '';
}

/** 정보원 하나의 응답 본문 → 글 목록 [orig, link, t, text, 사진]. radar.py의 리더들과 같은 규칙 */
function tp_radar_parse( $kind, $body ) {
	$out = array();
	if ( 'wpjson' === $kind ) {
		// heyPOP은 JSON 앞에 <style> 덩어리를 붙여 내보낸다(테마 버그) → '[{'부터
		$pos  = strpos( $body, '[{' );
		$list = false === $pos ? array() : (array) json_decode( substr( $body, $pos ), true );
		foreach ( $list as $p ) {
			$out[] = array( $p['title']['rendered'] ?? '', $p['link'] ?? '', tp_radar_when( $p['date'] ?? '' ), $p['excerpt']['rendered'] ?? '', '' );
		}
		return $out;
	}
	$prev = libxml_use_internal_errors( true );
	$x    = simplexml_load_string( $body );
	libxml_use_internal_errors( $prev );
	if ( ! $x ) {
		return $out;
	}
	// RSS 2.0
	foreach ( $x->channel->item ?? array() as $it ) {
		if ( 'gtrends' === $kind ) {
			// 검색어 하나에 관련 기사 몇 개: 제목 = 검색어·검색량, 링크 = 첫 기사, 요약 = 기사 제목들
			$news = $it->children( 'https://trends.google.com/trending/rss' );
			$list = array();
			foreach ( $news->news_item as $n ) {
				$list[] = (string) $n->news_item_title;
			}
			if ( $list ) {
				$out[] = array( '[검색 급상승 ' . (string) $news->approx_traffic . '] ' . (string) $it->title, (string) $news->news_item[0]->news_item_url, tp_radar_when( $it->pubDate ), implode( ' / ', $list ), (string) $news->picture );
			}
			continue;
		}
		$body  = (string) $it->description . (string) $it->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded;
		$out[] = array( (string) $it->title, (string) $it->link, tp_radar_when( $it->pubDate ), (string) $it->description, tp_radar_item_img( $it, $body ) );
	}
	// Atom (The Verge · 유튜브 · 레딧)
	foreach ( $x->children( 'http://www.w3.org/2005/Atom' )->entry ?? array() as $e ) {
		$href = '';
		foreach ( $e->link as $l ) {
			// Atom 이름공간 안에서는 $l['href']가 비어서 나온다 → 속성은 attributes()로(10/20 The Verge에서 발견)
			$href = $href ? $href : (string) $l->attributes()->href;
		}
		$text = (string) ( $e->summary ?? '' ) ? (string) $e->summary : (string) $e->content;
		if ( 'reddit' === $kind && preg_match( '/<a href="([^"]+)">\[link\]<\/a>/', $text, $m ) ) {
			$href = html_entity_decode( $m[1] ); // 레딧 글 대신 소개된 사이트로 바로
			$text = '';
		}
		if ( 'youtube' === $kind ) {
			$text = (string) $e->children( 'http://search.yahoo.com/mrss/' )->group->description;
		}
		$img   = preg_match( '/<img[^>]+src=["\']([^"\']+)/i', (string) $e->content . (string) $e->summary, $m ) ? $m[1] : '';
		$out[] = array( (string) $e->title, $href, tp_radar_when( (string) $e->published ? $e->published : $e->updated ), $text, $img );
	}
	return $out;
}

/** 주소 여러 개를 한꺼번에(병렬로) 받는다. 키 => 응답 객체(status_code·body) */
function tp_radar_multi( $reqs ) {
	// 워드프레스 6.2부터 Requests 2(이름공간 있음), 그 전은 옛 이름
	$multi = class_exists( '\WpOrg\Requests\Requests' ) ? array( '\WpOrg\Requests\Requests', 'request_multiple' ) : array( 'Requests', 'request_multiple' );
	return call_user_func( $multi, $reqs, array( 'timeout' => 15, 'follow_redirects' => true ) );
}

/**
 * 피드 전체: array( 'at' => 받은 시각, 'items' => [ {source, orig, link, date, t, text} … ] 최신순, 'errors' => 이름 => 이유 ).
 * $force = 🔄 새로고침 버튼(보관본을 버리고 다시 받기).
 */
function tp_radar_feed( $force = false ) {
	$cached = $force ? false : get_transient( 'tp_radar_feed' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$reqs = array();
	foreach ( TP_RADAR_SOURCES as $name => $s ) {
		if ( '' === $s[2] ) {
			continue;
		}
		$reqs[ $name ] = array(
			'url'     => 'youtube' === $s[2] ? 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $s[3] : $s[3],
			'type'    => 'GET',
			'headers' => array( 'User-Agent' => TP_RADAR_UA ),
		);
	}
	$resps = tp_radar_multi( $reqs );
	// 잠깐 흔들린 곳은 한 번 더(radar.py get()의 재시도와 같은 뜻)
	$again = array_filter( $reqs, fn( $k ) => ! is_object( $resps[ $k ] ?? null ) || ! isset( $resps[ $k ]->status_code ) || $resps[ $k ]->status_code >= 400, ARRAY_FILTER_USE_KEY );
	if ( $again ) {
		$resps = array_merge( $resps, tp_radar_multi( $again ) );
	}

	$cut    = time() - TP_RADAR_FEED_DAYS * DAY_IN_SECONDS;
	$items  = array();
	$errors = array();
	foreach ( $resps as $name => $r ) {
		if ( ! is_object( $r ) || ! isset( $r->status_code ) || $r->status_code >= 400 ) {
			$errors[ $name ] = is_object( $r ) && isset( $r->status_code ) ? 'HTTP ' . $r->status_code : '연결 실패';
			continue;
		}
		$skip = TP_RADAR_SKIP[ $name ] ?? '';
		$got  = array();
		foreach ( tp_radar_parse( TP_RADAR_SOURCES[ $name ][2], $r->body ) as list( $orig, $link, $t, $text, $img ) ) {
			$orig = tp_radar_plain( $orig );
			if ( $t < $cut || '' === $link || ( $skip && preg_match( '~' . $skip . '~i', $orig ) ) ) {
				continue;
			}
			$got[] = array(
				'source' => $name,
				'orig'   => $orig,
				'link'   => esc_url_raw( $link ),
				't'      => $t,
				'date'   => wp_date( 'Y-m-d', $t ),
				'text'   => mb_substr( tp_radar_plain( $text ), 0, 140 ),
				'image'  => tp_radar_thumb( $img ), // 없으면 '' → 탭이 화면에 보일 때 tp_radar_img로 찾는다
			);
		}
		usort( $got, fn( $a, $b ) => $b['t'] <=> $a['t'] );
		$items = array_merge( $items, array_slice( $got, 0, TP_RADAR_FEED_EACH ) );
	}
	usort( $items, fn( $a, $b ) => $b['t'] <=> $a['t'] );
	$feed = array( 'at' => time(), 'items' => $items, 'errors' => $errors );
	set_transient( 'tp_radar_feed', $feed, TP_RADAR_FEED_TTL );
	return $feed;
}

/** 이미 찾아 둔 대표 사진(3일 보관). 없으면 false, 찾았는데 사진이 없는 글이면 '' */
function tp_radar_img_cached( $link ) {
	return get_transient( 'tp_radar_img_' . md5( $link ) );
}

/*
 * 피드 사진 B안(10/20 사용자): RSS에 사진이 없는 글(15곳 중 9곳)은 그 줄이 화면에 보일 때만 탭이 이 창구를 부른다.
 * 서버가 기사 페이지를 열어 대표 사진(og:image)을 찾고 3일 보관 — 스크롤한 만큼만 비용이 들고, 한 번 찾은 글은 다시 안 연다.
 * 피드에 있는 글 주소만 받는다(아무 주소나 서버가 열지 않게).
 */
add_action(
	'wp_ajax_tp_radar_img',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'tp_radar_img' );
		$feed  = get_transient( 'tp_radar_feed' );
		$known = is_array( $feed ) ? wp_list_pluck( $feed['items'], 'link' ) : array();
		$out   = array();
		$reqs  = array();
		foreach ( array_slice( array_map( 'esc_url_raw', array_map( 'wp_unslash', (array) ( $_POST['links'] ?? array() ) ) ), 0, 8 ) as $l ) {
			if ( ! in_array( $l, $known, true ) ) {
				continue;
			}
			$c = tp_radar_img_cached( $l );
			if ( false !== $c ) {
				$out[ $l ] = $c;
				continue;
			}
			$reqs[ $l ] = array( 'url' => $l, 'type' => 'GET', 'headers' => array( 'User-Agent' => TP_RADAR_UA ) );
		}
		foreach ( $reqs ? tp_radar_multi( $reqs ) : array() as $l => $r ) {
			$body = is_object( $r ) && isset( $r->body ) ? (string) $r->body : '';
			$img  = preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $body, $m )
				|| preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image/i', $body, $m ) ? tp_radar_thumb( $m[1] ) : '';
			set_transient( 'tp_radar_img_' . md5( $l ), $img, 3 * DAY_IN_SECONDS );
			$out[ $l ] = $img;
		}
		wp_send_json_success( $out );
	}
);

// ── 정리 부탁: 피드에서 고른 글만 특파원이 핵심 정리·번역 ─────────────────
/*
 * 10/20 사용자: 피드에서 여러 개 체크 → 한 번에 특파원 호출. routine은 한 번 깨울 때마다 고정 비용이 커서 묶어서 부른다.
 * 결과는 탭에만(메일 없음 — 부탁한 사람이 탭을 보고 있으니까).
 * 초인종은 셰프와 같은 방식: routine의 API 트리거 주소로 POST 한 번. 특파원은 신호에 딸린 글을 따르지 않고,
 * REST /asks에서 '기다리는 중' 부탁만 가져간다(tools/radar/PROMPT.md) — 열쇠가 새도 할 수 있는 건 "특파원 깨우기"뿐.
 *
 * tp_radar_asks: id => { at, by, status(waiting·cooking·done·failed), items, rang, took, result, err }  최근 TP_RADAR_ASKS_KEEP개
 */
const TP_RADAR_ASK_MAX        = 10; // 한 번에 부탁할 수 있는 글 수 — 한 번 실행에 읽는 양의 상한
const TP_RADAR_ASKS_KEEP      = 40;
const TP_RADAR_ASK_STALE      = 15 * MINUTE_IN_SECONDS; // 이만큼 지나도 안 끝나면 "다시 부르기"를 보여 준다

function tp_radar_asks() {
	$a = get_option( 'tp_radar_asks', array() );
	return is_array( $a ) ? $a : array();
}

function tp_radar_save_asks( $asks ) {
	krsort( $asks );
	update_option( 'tp_radar_asks', array_slice( $asks, 0, TP_RADAR_ASKS_KEEP, true ), false );
}

/** 초인종: 특파원 routine을 지금 깨운다. 성공하면 true, 실패하면 이유 문장 */
function tp_radar_ring( $id ) {
	$bell = get_option( 'tp_radar_bell', array() );
	if ( empty( $bell['url'] ) || empty( $bell['token'] ) ) {
		return '초인종이 아직 설정되지 않았어요 (피드 칸 맨 아래 "특파원 초인종 설정")';
	}
	$res  = wp_remote_post(
		$bell['url'],
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization'     => 'Bearer ' . $bell['token'],
				// routine 문서가 요구하는 머리글(셰프 초인종과 같음). 실험 기능이라 바뀌면 이 날짜만 고친다
				'anthropic-beta'    => 'experimental-cc-routine-2026-04-01',
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			// 특파원은 이 글을 따르지 않는다. 실행 기록에서 어느 부탁 때 깨웠는지 알아보는 표시일 뿐
			'body'    => wp_json_encode( array( 'text' => "ask {$id}" ) ),
		)
	);
	$code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
	if ( $code >= 200 && $code < 300 ) {
		return true;
	}
	return is_wp_error( $res )
		? '특파원에게 연결하지 못했어요: ' . $res->get_error_message()
		: "특파원이 응답하지 않았어요 (오류 {$code}" . ( 401 === $code ? ', 열쇠가 틀렸거나 바뀌었어요' : '' ) . ')';
}

/** 부탁 하나를 '기다리는 중'으로 두고 초인종 — 새 부탁과 "다시 부르기"가 같이 쓴다 */
function tp_radar_ring_ask( &$asks, $id ) {
	$asks[ $id ]['status'] = 'waiting';
	$asks[ $id ]['rang']   = time();
	$rang                  = tp_radar_ring( $id );
	$asks[ $id ]['err']    = true === $rang ? '' : $rang;
}

add_action(
	'admin_post_tp_radar_ask',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 부탁할 수 있어요' );
		}
		check_admin_referer( 'tp_radar_ask' );
		$want = array_map( 'esc_url_raw', array_map( 'wp_unslash', (array) ( $_POST['links'] ?? array() ) ) );
		// 글 정보는 폼이 아니라 사이트가 받아 둔 피드에서 찾는다 — 폼으로 엉뚱한 주소를 넣어도 특파원이 읽지 않게
		$pick = array();
		foreach ( tp_radar_feed()['items'] as $it ) {
			if ( in_array( $it['link'], $want, true ) ) {
				unset( $it['t'] );
				$pick[] = $it;
			}
		}
		$back = admin_url( 'admin.php?page=' . TP_RADAR_SLUG );
		if ( ! $pick ) {
			wp_safe_redirect( add_query_arg( array( 'view' => 'feed', 'tprd_msg' => rawurlencode( '고른 글이 피드에 없어요. 새로고침한 뒤 다시 골라 주세요' ) ), $back ) );
			exit;
		}
		$asks        = tp_radar_asks();
		$id          = time();
		$asks[ $id ] = array(
			'at'    => $id,
			'by'    => wp_get_current_user()->display_name,
			'items' => array_slice( $pick, 0, TP_RADAR_ASK_MAX ),
		);
		tp_radar_ring_ask( $asks, $id );
		tp_radar_save_asks( $asks );
		wp_safe_redirect( $back . '#ask-' . $id );
		exit;
	}
);

// "다시 부르기": 초인종이 실패했거나, 너무 오래 안 끝났을 때
add_action(
	'admin_post_tp_radar_recall',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 부를 수 있어요' );
		}
		check_admin_referer( 'tp_radar_recall' );
		$id   = (int) ( $_POST['id'] ?? 0 );
		$asks = tp_radar_asks();
		if ( isset( $asks[ $id ] ) && 'done' !== ( $asks[ $id ]['status'] ?? '' ) ) {
			tp_radar_ring_ask( $asks, $id );
			tp_radar_save_asks( $asks );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) . '#ask-' . $id );
		exit;
	}
);

// 🔄 피드 새로고침: 30분 보관본을 버리고 지금 다시 받기
add_action(
	'admin_post_tp_radar_refresh',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 할 수 있어요' );
		}
		check_admin_referer( 'tp_radar_refresh' );
		tp_radar_feed( true );
		wp_safe_redirect( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=feed' ) );
		exit;
	}
);

// 초인종 설정 저장. 열쇠 칸을 비워 두면 기존 열쇠를 그대로 둔다(화면에 열쇠를 다시 보여 주지 않아서)
add_action(
	'admin_post_tp_radar_bell',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 바꿀 수 있어요' );
		}
		check_admin_referer( 'tp_radar_bell' );
		$bell  = get_option( 'tp_radar_bell', array() );
		$url   = esc_url_raw( trim( wp_unslash( $_POST['bell_url'] ?? '' ) ) );
		$token = trim( sanitize_text_field( wp_unslash( $_POST['bell_token'] ?? '' ) ) );
		$back  = admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=feed' );
		// 엉뚱한 곳으로 열쇠를 보내지 않게, routine 주소 모양만 받는다
		if ( ! preg_match( '#^https://api\.anthropic\.com/v1/claude_code/routines/trig_\w+/fire$#', $url ) ) {
			wp_safe_redirect( add_query_arg( 'tprd_msg', rawurlencode( '초인종 주소는 https://api.anthropic.com/v1/claude_code/routines/trig_…/fire 모양이에요' ), $back ) );
			exit;
		}
		$bell['url'] = $url;
		if ( '' !== $token ) {
			$bell['token'] = $token;
		}
		update_option( 'tp_radar_bell', $bell, false );
		wp_safe_redirect( add_query_arg( 'tprd_msg', rawurlencode( '초인종을 저장했어요. 이제 정리 부탁을 하면 특파원이 바로 와요' ), $back ) );
		exit;
	}
);

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
					// reported: 진짜(미리보기 아닌) 리포트를 보낸 날짜들 — 정리 부탁으로 낮에 깨어난 routine이 아침 리포트를 또 만들지 않게
					$reported = array();
					foreach ( tp_radar_days() as $ymd => $d ) {
						if ( 0 !== strpos( (string) ( $d['mail'] ?? '' ), 'preview:' ) ) {
							$reported[] = $ymd;
						}
					}
					return array(
						'links'    => array_keys( (array) get_option( 'tp_radar_offered', array() ) ),
						'reported' => $reported,
					);
				},
			)
		);

		// 정보원 표 — radar.py collect가 이걸 받아 읽는다(정보원은 이 플러그인 한 곳에만 적으려고)
		register_rest_route(
			'tp-radar/v1',
			'/sources',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function () {
					$out = array();
					foreach ( TP_RADAR_SOURCES as $name => $s ) {
						if ( '' !== $s[2] ) {
							$out[] = array( 'name' => $name, 'kind' => $s[2], 'url' => $s[3], 'skip' => TP_RADAR_SKIP[ $name ] ?? '' );
						}
					}
					return $out;
				},
			)
		);

		// 정리 부탁 가져가기: '기다리는 중'인 부탁을 '정리 중'으로 바꾸며 돌려준다(두 번 깨어나도 한 번만 정리하게)
		register_rest_route(
			'tp-radar/v1',
			'/asks',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function () {
					$asks = tp_radar_asks();
					$out  = array();
					foreach ( $asks as $id => $a ) {
						if ( 'waiting' === ( $a['status'] ?? '' ) ) {
							$asks[ $id ]['status'] = 'cooking';
							$asks[ $id ]['took']   = time();
							$out[]                 = array( 'id' => $id, 'items' => $a['items'] );
						}
					}
					if ( $out ) {
						tp_radar_save_asks( $asks );
					}
					return $out;
				},
			)
		);

		// 정리 결과 받기(items) 또는 실패 알리기(error)
		register_rest_route(
			'tp-radar/v1',
			'/asks/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) {
					$id   = (int) $req['id'];
					$asks = tp_radar_asks();
					if ( ! isset( $asks[ $id ] ) ) {
						return new WP_Error( 'tp_radar_ask', '그런 부탁이 없어요', array( 'status' => 404 ) );
					}
					$err = sanitize_text_field( (string) $req->get_param( 'error' ) );
					if ( '' !== $err ) {
						$asks[ $id ]['status'] = 'failed';
						$asks[ $id ]['err']    = $err;
					} else {
						$items = array_values( array_map( 'tp_radar_clean_item', (array) $req->get_param( 'items' ) ) );
						if ( ! $items ) {
							return new WP_Error( 'tp_radar_empty', '정리한 글이 없어요', array( 'status' => 400 ) );
						}
						$asks[ $id ]['status']  = 'done';
						$asks[ $id ]['result']  = $items;
						$asks[ $id ]['done_at'] = time();
						$asks[ $id ]['err']     = '';
					}
					tp_radar_save_asks( $asks );
					return array( 'id' => $id, 'status' => $asks[ $id ]['status'] );
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

/** 리포트 카드 하나 — 아침 리포트와 정리 부탁 결과가 같은 모양을 쓴다 */
function tp_radar_card( $it, $i ) {
	list( $bg, $line, $fg ) = tp_radar_chip_color( $it['source'] );
	$lang                   = tp_radar_lang( $it['orig'] );
	?>
<article class="tprd-card">
	<?php if ( ! empty( $it['image'] ) ) : ?>
<a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $it['image'] ); ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></a>
	<?php endif; ?>
<span class="tprd-no"><?php echo (int) $i + 1; ?></span>
<div class="tprd-bd"<?php echo empty( $it['image'] ) ? ' style="padding-top:54px"' : ''; ?>>
<?php tp_radar_chip( $it['source'], $it['date'] ); ?>
	<?php if ( $lang ) : ?>
<span class="tprd-chip" style="background:<?php echo esc_attr( TP_RADAR_TR[0] ); ?>;border-color:<?php echo esc_attr( TP_RADAR_TR[1] ); ?>;color:<?php echo esc_attr( TP_RADAR_TR[2] ); ?>">🌐 특파원 번역</span>
	<?php endif; ?>
<h3><a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a></h3>
<p><?php echo esc_html( $it['note'] ); ?></p>
	<?php if ( ! empty( $it['points'] ) ) : ?>
<div class="tprd-pts"><b>📝 핵심 정리<?php echo $lang ? '<span> · ' . esc_html( $lang ) . ' 원문을 특파원이 번역해 정리했어요</span>' : ''; ?></b><ul>
		<?php foreach ( $it['points'] as $pt ) : ?>
<li><?php echo esc_html( $pt ); ?></li>
		<?php endforeach; ?>
</ul></div>
	<?php endif; ?>
<p class="tprd-orig">원문: <?php echo esc_html( $it['orig'] ); ?></p>
</div>
</article>
	<?php
}

/** 정보원 알약: 나라 동그라미 + 이름 · 날짜(월-일) */
function tp_radar_chip( $source, $date ) {
	list( $bg, $line, $fg ) = tp_radar_chip_color( $source );
	$c                      = tp_radar_country( $source );
	echo '<span class="tprd-chip" style="background:' . esc_attr( $bg ) . ';border-color:' . esc_attr( $line ) . ';color:' . esc_attr( $fg ) . '">'
		. ( $c ? '<span class="tprd-flag">' . esc_html( $c ) . '</span>' : '' )
		. esc_html( $source . ' · ' . substr( $date, 5 ) ) . '</span>';
}

/** "3시간 전"처럼 — 피드에서 얼마나 따끈한지 */
function tp_radar_ago( $t ) {
	$m = max( 0, (int) ( ( time() - $t ) / 60 ) );
	return $m < 60 ? $m . '분 전' : ( $m < 1440 ? (int) ( $m / 60 ) . '시간 전' : (int) ( $m / 1440 ) . '일 전' );
}

function tp_radar_page() {
	$view = 'feed' === ( $_GET['view'] ?? '' ) ? 'feed' : 'report';
	$days = tp_radar_days();
	$asks = tp_radar_asks();
	// 처음엔 2주만. 더 보기를 누를 때마다 2주씩
	$show  = min( TP_RADAR_KEEP, max( 14, (int) ( $_GET['show'] ?? 14 ) ) );
	$more  = count( $days ) > $show;
	$days  = array_slice( $days, 0, $show, true );
	$first = $days ? reset( $days ) : null;
	$msg   = sanitize_text_field( wp_unslash( $_GET['tprd_msg'] ?? '' ) );
	// 피드는 피드 칸을 열 때만 받는다(리포트만 볼 때 17곳을 부르지 않게). 보관본이 있으면 개수만 보여 준다
	$feed = 'feed' === $view ? tp_radar_feed() : get_transient( 'tp_radar_feed' );
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
.tprd-head { text-align: center; margin: 14px 0 22px; }
.tprd-head img { width: 104px; height: 104px; }
.tprd-head h1 { margin: 2px 0 4px; padding: 0; font: 400 30px/1.15 "Jua", sans-serif; color: var(--ink); }
.tprd-head p { margin: 0; color: var(--muted); font-size: 13px; }
.tprd-head p b { display: inline-block; margin-right: 6px; padding: 1px 9px; border-radius: 6px; background: var(--ink); color: #fffdf8; font-size: 11px; letter-spacing: .08em; }
@media (prefers-reduced-motion: no-preference) {
  .tprd-head img { animation: tprd-bob 3s ease-in-out infinite; }
  @keyframes tprd-bob { 50% { transform: translateY(-4px) rotate(-3deg); } }
}
/* 위쪽 칸 나누기(10/20 사용자): 리포트 | 실시간 피드, 오른쪽에 정보원 보고서 */
.tprd-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 26px; }
.tprd-nav a { text-decoration: none; }
.tprd-tab { padding: 8px 18px; border: 2.5px solid var(--ink); border-radius: 999px; background: var(--card); color: var(--ink); font: 400 17px/1.2 "Jua", sans-serif; box-shadow: 3px 3px 0 var(--ink); }
.tprd-tab.on { background: var(--ink); color: #fffdf8; box-shadow: none; transform: translate(3px, 3px); }
.tprd-tab small { font: 700 11px "IBM Plex Sans KR", sans-serif; opacity: .7; }
.tprd-doc { margin-left: auto; padding: 5px 12px; border: 1.5px dashed var(--ink); border-radius: 999px; color: var(--ink); font-size: 12.5px; font-weight: 700; }
.tprd-doc:hover { background: var(--card); }
.tprd-msg { margin: 0 0 18px; padding: 10px 14px; border: 2px solid var(--ink); border-radius: 12px; background: #fff7d1; font-weight: 700; }
.tprd-day { margin: 0 0 34px; scroll-margin-top: 50px; }
.tprd-day h2 { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 0 0 16px; font: 400 20px/1.2 "Jua", sans-serif; color: var(--ink); }
/* 날짜 = 우체국 소인 도장 */
.tprd-stamp { flex: none; display: grid; place-items: center; width: 66px; height: 66px; border: 3px double var(--post); border-radius: 50%; color: var(--post); transform: rotate(-12deg); text-align: center; line-height: 1; opacity: .9; }
.tprd-stamp small { display: block; font: 700 8px/1 "IBM Plex Sans KR", sans-serif; letter-spacing: .12em; }
.tprd-stamp strong { display: block; margin: 3px 0 2px; font: 400 17px/1 "Jua", sans-serif; }
/* 정리 부탁 = 소인 대신 메모지 도장 */
.tprd-stamp.ask { border-style: solid; border-width: 2.5px; border-color: var(--ink); color: var(--ink); background: #ffe45c; transform: rotate(8deg); font-size: 28px; }
.tprd-mail { padding: 2px 10px; border-radius: 999px; background: #d9f1e3; color: #13784f; font: 700 12px/1.5 "IBM Plex Sans KR", sans-serif; }
.tprd-mail.bad { background: #fde3da; color: #b42333; }
.tprd-mail.wait { background: #fff1c2; color: #8a6400; }
.tprd-wait { margin: 0; padding: 12px 16px 12px 32px; border: 2px dashed #dcc9ab; border-radius: 14px; background: #fdf8ee; }
.tprd-wait li { margin: 3px 0; }
.tprd-recall { display: inline; margin-left: 4px; }
.tprd-recall button, .tprd-btn { padding: 6px 14px; border: 2.5px solid var(--ink); border-radius: 999px; background: #ffe45c; color: var(--ink); font: 700 13px "IBM Plex Sans KR", sans-serif; cursor: pointer; box-shadow: 3px 3px 0 var(--ink); }
.tprd-btn:disabled { background: #efe3cf; color: var(--muted); box-shadow: none; cursor: default; }
/* 10/20 사용자: 사진은 작게, 글(핵심 정리)을 더 → 2열로 넓히고 사진은 납작한 띠(2.4:1) */
.tprd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 22px 18px; align-items: start; }
.tprd-card { position: relative; display: flex; flex-direction: column; background: var(--card); border: 2.5px solid var(--ink); border-radius: 18px; box-shadow: 5px 5px 0 var(--ink); overflow: hidden; transition: transform .15s, box-shadow .15s; }
.tprd-card:hover { transform: translate(-2px, -2px); box-shadow: 7px 7px 0 var(--ink); }
/* overflow를 숨겨야 유튜브 원본(16:9) 높이가 띠를 밀어 늘리지 않는다(aspect-ratio의 최소 높이가 0이 됨) */
.tprd-card > a { display: block; overflow: hidden; aspect-ratio: 2.4 / 1; border-bottom: 2.5px solid var(--ink); background: #efe3cf; }
.tprd-card > a img { display: block; width: 100%; height: 100%; object-fit: cover; }
.tprd-no { position: absolute; top: 10px; left: 10px; width: 36px; height: 36px; border-radius: 50%; background: #ffe45c; border: 2.5px solid var(--ink); font: 400 19px/31px "Jua", sans-serif; text-align: center; transform: rotate(-8deg); }
.tprd-bd { padding: 12px 14px 14px; }
.tprd-chip { display: inline-block; padding: 2px 10px; border-radius: 999px; border: 1.5px solid; font-size: 11.5px; font-weight: 700; }
.tprd-flag { display: inline-block; width: 17px; height: 17px; margin: 0 5px 0 -5px; border-radius: 50%; background: #3b2f2a; color: #fffdf8; font-size: 10px; line-height: 17px; text-align: center; vertical-align: 1px; }
.tprd-bd h3 { margin: 7px 0 4px; font: 400 19px/1.3 "Jua", sans-serif; }
.tprd-bd h3 a { color: var(--ink); text-decoration: none; }
.tprd-bd h3 a:hover { text-decoration: underline; }
.tprd-bd p { margin: 0; font-size: 13.5px; line-height: 1.6; }
.tprd-bd .tprd-orig { margin-top: 6px; font-size: 12px; color: var(--muted); }
/* 영어 원문 거리의 핵심 정리: 셰프 탭의 안내 상자처럼 점선 메모 */
.tprd-pts { margin-top: 10px; padding: 10px 12px; border: 1.5px dashed #dcc9ab; border-radius: 12px; background: #fdf8ee; font-size: 13px; }
.tprd-pts b { display: block; margin-bottom: 2px; }
.tprd-pts b span { font-weight: 400; color: var(--muted); }
.tprd-pts ul { margin: 0; padding-left: 16px; list-style: "· "; }
.tprd-pts li { margin: 2px 0; }
.tprd-more { display: inline-block; padding: 8px 18px; border: 2.5px solid var(--ink); border-radius: 999px; background: var(--card); color: var(--ink); font-weight: 700; text-decoration: none; box-shadow: 3px 3px 0 var(--ink); }
/* 실시간 피드: 거르기 알약 줄 → 한 줄짜리 글 목록(체크) → 아래에 붙는 정리 부탁 막대 */
.tprd-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 14px; }
.tprd-filters button { padding: 4px 12px; border: 2px solid var(--ink); border-radius: 999px; background: var(--card); color: var(--ink); font: 700 12.5px "IBM Plex Sans KR", sans-serif; cursor: pointer; }
.tprd-filters button[aria-pressed="true"] { background: var(--ink); color: #fffdf8; }
.tprd-filters .tprd-refresh { margin-left: auto; font-size: 12px; color: var(--muted); }
.tprd-filters .tprd-refresh button { background: #ffe45c; }
.tprd-errs { margin: -6px 0 12px; font-size: 12px; color: #b42333; }
.tprd-feed { list-style: none; margin: 0; padding: 0; background: var(--card); border: 2.5px solid var(--ink); border-radius: 18px; box-shadow: 5px 5px 0 var(--ink); overflow: hidden; }
.tprd-feed li { border-bottom: 1.5px solid #ead9bb; }
.tprd-feed li:last-child { border-bottom: 0; }
.tprd-feed label { display: grid; grid-template-columns: 22px 112px minmax(0, 1fr); gap: 3px 12px; padding: 11px 16px; cursor: pointer; }
/* 10/20 사용자 B안: 작은 사진(1.6:1). 사진이 없으면 정보원 갈래 색 칸 */
.tprd-feed .thumb { grid-column: 2; grid-row: 1 / span 3; align-self: start; display: block; aspect-ratio: 1.6 / 1; border: 2px solid var(--ink); border-radius: 10px; overflow: hidden; }
.tprd-feed .thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
.tprd-feed .meta { grid-column: 3; }
.tprd-feed label:hover { background: #fdf8ee; }
.tprd-feed li.on label { background: #fff7d1; }
.tprd-feed input { grid-row: 1; margin: 3px 0 0; width: 17px; height: 17px; accent-color: var(--ink); }
.tprd-feed .meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
.tprd-feed .ttl { grid-column: 3; font-weight: 700; font-size: 14.5px; line-height: 1.45; }
.tprd-feed .ttl a { color: var(--ink); text-decoration: none; }
.tprd-feed .ttl a:hover { text-decoration: underline; }
.tprd-feed .txt { grid-column: 3; font-size: 12.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tprd-feed .st { padding: 0 8px; border-radius: 999px; background: #efe3cf; color: var(--ink); font-weight: 700; font-size: 11px; text-decoration: none; }
.tprd-bar { position: sticky; bottom: 12px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 16px 0 0; padding: 12px 16px; border: 2.5px solid var(--ink); border-radius: 16px; background: var(--card); box-shadow: 5px 5px 0 var(--ink); }
.tprd-bar span { color: var(--muted); font-size: 13px; }
.tprd-bell { margin: 30px 0 0; padding: 12px 16px; border: 2px dashed #dcc9ab; border-radius: 14px; background: #fdf8ee; }
.tprd-bell summary { cursor: pointer; font-weight: 700; }
.tprd-bell summary small { font-weight: 400; color: var(--muted); }
.tprd-bell label { display: block; margin: 10px 0 4px; font-weight: 700; font-size: 12.5px; }
.tprd-bell input { width: 100%; max-width: 640px; }
</style>
<div class="tprd-air"></div>
<div class="tprd-head">
<img src="<?php echo esc_url( plugins_url( 'press.png', __FILE__ ) ); ?>" alt="트란시스 특파원">
<h1><?php echo 'report' === $view && $first ? '특파원 리포트 - 기사 ' . count( (array) $first['items'] ) . '개' : '트란시스 특파원'; ?></h1>
<p><b>PRESS</b>트란시스 특파원이 매일 아침 9시에 카드뉴스 거리를 송고해요</p>
</div>
<nav class="tprd-nav">
<a class="tprd-tab<?php echo 'report' === $view ? ' on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) ); ?>">✈️ 리포트</a>
<a class="tprd-tab<?php echo 'feed' === $view ? ' on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=feed' ) ); ?>">📰 실시간 피드<?php echo is_array( $feed ) ? ' <small>' . count( $feed['items'] ) . '</small>' : ''; ?></a>
<a class="tprd-doc" href="<?php echo esc_url( TP_RADAR_REPORT ); ?>" target="_blank" rel="noopener">📊 정보원 보고서</a>
</nav>
	<?php if ( $msg ) : ?>
<p class="tprd-msg"><?php echo esc_html( $msg ); ?></p>
	<?php endif; ?>
	<?php
	if ( 'feed' === $view ) {
		tp_radar_feed_view( $feed, $days, $asks );
	} else {
		tp_radar_report_view( $days, $asks, $show, $more );
	}
	?>
</div>
	<?php
}

/** ✈️ 리포트 칸: 정리 부탁(최근 것) → 날짜별 아침 리포트 */
function tp_radar_report_view( $days, $asks, $show, $more ) {
	$pending = false;
	foreach ( array_slice( $asks, 0, 6, true ) as $id => $a ) :
		$st       = $a['status'] ?? 'waiting';
		$pending  = $pending || in_array( $st, array( 'waiting', 'cooking' ), true );
		$late     = in_array( $st, array( 'waiting', 'cooking' ), true ) && time() - (int) ( $a['rang'] ?? $a['at'] ) > TP_RADAR_ASK_STALE;
		$says     = array(
			'waiting' => '특파원 부르는 중',
			'cooking' => '정리하는 중',
			'done'    => '정리 완료',
			'failed'  => '정리 실패',
		)[ $st ] ?? $st;
		$cls      = 'done' === $st ? '' : ( 'failed' === $st || ! empty( $a['err'] ) ? ' bad' : ' wait' );
		?>
<section class="tprd-day" id="ask-<?php echo (int) $id; ?>">
<h2><span class="tprd-stamp ask" aria-hidden="true">📝</span>정리 부탁 · <?php echo esc_html( tp_radar_weekday( wp_date( 'Y-m-d', (int) $a['at'] ) ) . ' ' . wp_date( 'H:i', (int) $a['at'] ) ); ?> · <?php echo count( (array) $a['items'] ); ?>개
<span class="tprd-mail<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $says . ( ! empty( $a['err'] ) ? ' — ' . $a['err'] : '' ) ); ?></span>
		<?php if ( $late || ! empty( $a['err'] ) ) : ?>
<form class="tprd-recall" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_recall' ); ?><input type="hidden" name="action" value="tp_radar_recall"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"><button type="submit">🔔 다시 부르기</button></form>
		<?php endif; ?>
</h2>
		<?php if ( 'done' === $st ) : ?>
<div class="tprd-grid">
			<?php
			foreach ( array_values( (array) $a['result'] ) as $i => $it ) {
				tp_radar_card( $it, $i );
			}
			?>
</div>
		<?php else : ?>
<ul class="tprd-wait">
			<?php foreach ( (array) $a['items'] as $it ) : ?>
<li><?php tp_radar_chip( $it['source'], $it['date'] ); ?> <?php echo esc_html( $it['orig'] ); ?></li>
			<?php endforeach; ?>
</ul>
		<?php endif; ?>
</section>
	<?php endforeach; ?>
	<?php if ( $pending ) : // 정리 중인 부탁이 있으면 30초마다 새로 읽어 끝난 결과가 저절로 보이게 ?>
<script>setTimeout(function () { location.reload(); }, 30000);</script>
	<?php endif; ?>
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
		<?php
		foreach ( array_values( (array) $day['items'] ) as $i => $it ) {
			tp_radar_card( $it, $i );
		}
		?>
</div>
</section>
	<?php endforeach; ?>
	<?php if ( $more ) : ?>
<p style="text-align:center"><a class="tprd-more" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&show=' . ( $show + 14 ) ) ); ?>">✈️ 지난 리포트 더 보기</a></p>
	<?php endif; ?>
	<?php
}

/** 📰 실시간 피드 칸: 갈래 거르기 → 글 목록(체크) → 정리 부탁 막대 → 초인종 설정 */
function tp_radar_feed_view( $feed, $days, $asks ) {
	// 이미 리포트에 실렸거나 정리 부탁한 글은 표시 — 같은 글을 또 부탁하지 않게
	$state = array();
	foreach ( $days as $d ) {
		foreach ( (array) $d['items'] as $it ) {
			$state[ $it['link'] ] = array( '📨 리포트', '' );
		}
	}
	foreach ( array_reverse( $asks, true ) as $id => $a ) {
		foreach ( (array) $a['items'] as $it ) {
			$state[ $it['link'] ] = array( 'done' === ( $a['status'] ?? '' ) ? '📝 정리됨' : '⏳ 정리 중', '#ask-' . $id );
		}
	}
	$count = array();
	foreach ( $feed['items'] as $it ) {
		$g           = TP_RADAR_SOURCES[ $it['source'] ][1] ?? '';
		$count[ $g ] = ( $count[ $g ] ?? 0 ) + 1;
	}
	$bell = get_option( 'tp_radar_bell', array() );
	?>
<div class="tprd-filters" role="group" aria-label="갈래 거르기">
<button type="button" data-g="" aria-pressed="true">전체 <?php echo count( $feed['items'] ); ?></button>
	<?php foreach ( TP_RADAR_GROUP_NAME as $g => $name ) : ?>
		<?php list( $bg, $line, $fg ) = TP_RADAR_GROUP_COLOR[ $g ]; ?>
<button type="button" data-g="<?php echo esc_attr( $g ); ?>" aria-pressed="false" style="border-color:<?php echo esc_attr( $line ); ?>"><?php echo esc_html( $name . ' ' . ( $count[ $g ] ?? 0 ) ); ?></button>
	<?php endforeach; ?>
<form class="tprd-refresh" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_refresh' ); ?><input type="hidden" name="action" value="tp_radar_refresh"><?php echo esc_html( tp_radar_ago( $feed['at'] ) ); ?> 받음 <button type="submit">🔄 새로고침</button></form>
</div>
	<?php if ( $feed['errors'] ) : ?>
<p class="tprd-errs">읽지 못한 곳: <?php echo esc_html( implode( ' · ', array_map( fn( $n, $e ) => "$n($e)", array_keys( $feed['errors'] ), $feed['errors'] ) ) ); ?></p>
	<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tprd-ask">
	<?php wp_nonce_field( 'tp_radar_ask' ); ?>
<input type="hidden" name="action" value="tp_radar_ask">
<ul class="tprd-feed">
	<?php foreach ( $feed['items'] as $k => $it ) : ?>
		<?php $st = $state[ $it['link'] ] ?? null; ?>
<li data-g="<?php echo esc_attr( TP_RADAR_SOURCES[ $it['source'] ][1] ?? '' ); ?>"><label for="tprd-f<?php echo (int) $k; ?>">
<input type="checkbox" id="tprd-f<?php echo (int) $k; ?>" name="links[]" value="<?php echo esc_attr( $it['link'] ); ?>">
		<?php
		// 사진: RSS에 있으면 그대로, 없으면 찾아 둔 것, 그것도 없으면 화면에 보일 때 찾는다(data-need)
		$img  = $it['image'] ?? '';
		$img  = '' !== $img ? $img : tp_radar_img_cached( $it['link'] );
		$need = false === $img;
		list( $gbg ) = tp_radar_chip_color( $it['source'] );
		?>
<span class="thumb" style="background-color:<?php echo esc_attr( $gbg ); ?>"<?php echo $need ? ' data-need="' . esc_attr( $it['link'] ) . '"' : ''; ?>><?php echo $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : ''; ?></span>
<span class="meta"><?php tp_radar_chip( $it['source'], $it['date'] ); ?> <?php echo esc_html( tp_radar_ago( $it['t'] ) ); ?>
		<?php if ( $st ) : ?>
<a class="st" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) . $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></a>
		<?php endif; ?>
</span>
<span class="ttl"><a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['orig'] ); ?></a></span>
		<?php if ( $it['text'] ) : ?>
<span class="txt"><?php echo esc_html( $it['text'] ); ?></span>
		<?php endif; ?>
</label></li>
	<?php endforeach; ?>
</ul>
<div class="tprd-bar">
<button type="submit" class="tprd-btn" id="tprd-ask-btn" disabled>📝 정리 부탁</button>
<span id="tprd-ask-n">정리할 글을 골라 주세요 · 한 번에 <?php echo (int) TP_RADAR_ASK_MAX; ?>개까지 · 끝나면 리포트 칸에 쌓여요</span>
</div>
</form>
<script>
(function () {
	var form = document.getElementById('tprd-ask'), btn = document.getElementById('tprd-ask-btn'), say = document.getElementById('tprd-ask-n'), max = <?php echo (int) TP_RADAR_ASK_MAX; ?>;
	// 고른 개수를 막대에 보여 주고, 0개거나 너무 많으면 버튼을 잠근다
	form.addEventListener('change', function (e) {
		if (e.target.name !== 'links[]') return;
		e.target.closest('li').classList.toggle('on', e.target.checked);
		var n = form.querySelectorAll('input[name="links[]"]:checked').length;
		btn.disabled = n === 0 || n > max;
		btn.textContent = n ? '📝 고른 ' + n + '개 정리 부탁' : '📝 정리 부탁';
		say.textContent = n > max ? '한 번에 ' + max + '개까지예요 — ' + (n - max) + '개를 빼 주세요' : (n ? '특파원이 2~5분 안에 정리해 리포트 칸에 올려요' : '정리할 글을 골라 주세요 · 한 번에 ' + max + '개까지 · 끝나면 리포트 칸에 쌓여요');
	});
	// 사진 B안: 사진 없는 줄이 화면에 들어오면 모아서(0.3초) 한 번에 최대 8개씩 서버에 찾아 달라고 한다
	var waiting = [], timer = null;
	function ask() {
		timer = null;
		var batch = waiting.splice(0, 8);
		if (!batch.length) return;
		var fd = new FormData();
		fd.append('action', 'tp_radar_img');
		fd.append('_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'tp_radar_img' ) ); ?>);
		batch.forEach(function (el) { fd.append('links[]', el.dataset.need); });
		fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			batch.forEach(function (el) {
				var src = res && res.success ? res.data[el.dataset.need] : '';
				if (src) { var im = new Image(); im.alt = ''; im.referrerPolicy = 'no-referrer'; im.src = src; el.appendChild(im); }
				el.removeAttribute('data-need');
			});
		}).catch(function () {}).then(function () { if (waiting.length) timer = setTimeout(ask, 300); });
	}
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (ents) {
			ents.forEach(function (en) {
				if (!en.isIntersecting) return;
				io.unobserve(en.target);
				waiting.push(en.target);
				if (!timer) timer = setTimeout(ask, 300);
			});
		}, { rootMargin: '200px' });
		document.querySelectorAll('.tprd-feed .thumb[data-need]').forEach(function (el) { io.observe(el); });
	}
	// 갈래 거르기: 화면에서 숨기기만(고른 체크는 그대로)
	document.querySelectorAll('.tprd-filters button[data-g]').forEach(function (b) {
		b.addEventListener('click', function () {
			document.querySelectorAll('.tprd-filters button[data-g]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
			form.querySelectorAll('.tprd-feed li').forEach(function (li) { li.hidden = b.dataset.g !== '' && li.dataset.g !== b.dataset.g; });
		});
	});
})();
</script>
<?php // 초인종 설정: 처음 한 번과 열쇠를 바꿀 때만 쓰니 접어 둔다. 열쇠는 저장 뒤 다시 보여 주지 않는다 ?>
<details class="tprd-bell"<?php echo empty( $bell['token'] ) ? ' open' : ''; ?>>
<summary>🔔 특파원 초인종 설정 <small><?php echo empty( $bell['token'] ) ? '아직 설정 안 됨 — 정리 부탁을 해도 특파원이 오지 않아요' : '연결됨'; ?></small></summary>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'tp_radar_bell' ); ?>
<input type="hidden" name="action" value="tp_radar_bell">
<p class="tprd-orig">claude.ai/code/routines → 트란시스 특파원 routine → Edit → API 트리거에서 받은 주소와 열쇠를 넣어 주세요.</p>
<label for="tprd-bell-url">주소</label>
<input type="url" id="tprd-bell-url" name="bell_url" required value="<?php echo esc_attr( $bell['url'] ?? '' ); ?>" placeholder="https://api.anthropic.com/v1/claude_code/routines/trig_…/fire">
<label for="tprd-bell-token">열쇠<?php echo empty( $bell['token'] ) ? '' : ' (바꿀 때만 넣으세요)'; ?></label>
<input type="password" id="tprd-bell-token" name="bell_token" autocomplete="off"<?php echo empty( $bell['token'] ) ? ' required' : ''; ?>>
<p><button type="submit" class="tprd-btn" style="margin-top:10px">저장</button></p>
</form>
</details>
	<?php
}
