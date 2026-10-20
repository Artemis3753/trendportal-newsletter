<?php
/**
 * Plugin Name: TP Trend Radar
 * Description: 관리자 전용 탭 "트란시스 특파원"(처음 이름은 트렌드 레이더). 정보원 새 글을 실시간 피드로 모아 보여 주고, 체크한 글을 클라우드 routine(tools/radar/radar.py)이 리포트로 정리해 탭에 쌓는다.
 * Version: 1.10.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/20 사용자 결정: 이 도구는 "필요할 때 들어와서 아이디어를 얻는 곳"이다.
 * 피드를 둘러보고 → 마음에 드는 글을 체크 → 특파원이 리포트로 정리. 매일 아침 리포트와 메일 발송은 범위에서 뺐다(1.6.0).
 *
 * 저장: 옵션(autoload 끔 — 이 탭과 REST에서만 읽는다)
 *   tp_radar_asks    정리 부탁과 그 결과(아래 "정리 부탁")
 *   tp_radar_titles  피드 제목 번역(아래 "제목 번역")
 *   tp_radar_bell    초인종(routine 트리거 주소·열쇠)
 */

const TP_RADAR_SLUG = 'tp-trend-radar';

// ── 리포트 카드 ──────────────────────────────────────────

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

// ── 표시 도우미 ──────────────────────────────────────────

function tp_radar_weekday( $ymd ) {
	$t = strtotime( $ymd . ' 12:00:00' );
	return wp_date( 'n월 j일', $t ) . ' (' . array( '일', '월', '화', '수', '목', '금', '토' )[ (int) wp_date( 'w', $t ) ] . ')';
}

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

/**
 * 정보원 표 — 정보원은 여기 한 곳에만 적는다: 이름 => 나라 한 글자, 갈래, 읽는 법, 주소.
 * 탭의 실시간 피드가 이 표대로 읽는다(1.6.0 전에는 아침 리포트도 REST /sources로 이 표를 받았다).
 *
 * 고른 근거(10/20, 사용자가 추천안 선택 + 밈·스포츠 2곳 → 오후에 불안정한 유튜브 2·레딧 2곳을 빼서 15곳): 인스타 892개에서 화제가 된 비율
 * (하트 공개 또는 저장 1,000회+)이 밈 59%·웹·앱 54%·플랫폼 40%·트렌드 39%·스포츠 37%, 국내 브랜드 뉴스 17%.
 * 근거 보고서: TP_RADAR_REPORT. 나라는 사이트 소개·약관으로 확인한 운영사 소재지.
 * 읽는 법: rss(RSS·Atom) · reddit(소개된 사이트 주소를 링크로) · gtrends(검색어 + 관련 기사) · wpjson · youtube(채널 id).
 * 읽는 법이 ''이면 읽지 않는다 — 뺀 정보원도 지난 리포트의 알약 색 때문에 남겨 둔다.
 */
const TP_RADAR_SOURCES = array(
	// 해외 캠페인 — 하트가 잘 나오는 갈래. Famous Campaigns는 우리 카드 출처 1위(46편), 런던 Speakeasy Media
	'Famous Campaigns'      => array( '영', 'camp', 'rss', 'https://www.famouscampaigns.com/feed/' ), // www 없는 주소는 526
	'Muse by Clio'          => array( '미', 'camp', 'rss', 'https://musebycl.io/rss.xml' ),
	'AdverTimes'            => array( '일', 'camp', 'rss', 'https://www.advertimes.com/feed/' ),
	// 플랫폼·크리에이터 — "인스타그램 이렇게 변합니다" 같은 글의 출처가 이 매체들이었다
	'Social Media Today'    => array( '미', 'plat', 'rss', 'https://www.socialmediatoday.com/feeds/news/' ),
	// 10/20 밤 추가 — 브랜드가 SNS에서 밈을 타는 법만 다루는 뉴스레터(Rachel Karten). 주 1~2편이라 속도보다 아이디어용
	'Link in Bio'           => array( '미', 'plat', 'rss', 'https://www.milkkarten.net/feed' ),
	// 웹·앱·디지털 놀이 — "신기한 사이트 모음" 갈래
	'GIGAZINE'              => array( '일', 'web', 'rss', 'https://gigazine.net/news/rss_2.0/' ),
	'Laughing Squid'        => array( '미', 'web', 'rss', 'https://laughingsquid.com/feed/' ), // ねとらぼ 자리 — 그쪽 RSS는 2025-05에 멈춤
	// 밈·트렌드 — 화제 비율 1위 갈래
	'Know Your Meme'        => array( '미', 'trend', 'rss', 'https://knowyourmeme.com/newsfeed.rss' ),
	'Mashable'              => array( '미', 'trend', 'rss', 'https://mashable.com/feeds/rss/all' ),
	'오픈서베이'             => array( '한', 'trend', 'rss', 'https://blog.opensurvey.co.kr/feed/' ),
	// 10/20 밤 추가 — 틱톡·인터넷 화제를 가장 빨리 쓰는 곳(사용자: "트렌디하고 밈을 가장 빠르게")
	'Daily Dot'             => array( '미', 'trend', 'rss', 'https://www.dailydot.com/feed/' ),
	// 10/20 밤 추가 — X에서 뜬 걸 누군가 정리해 온 곳(사용자: "그걸 우리가 빠르게 낚아채는 거지").
	// ねとらぼ(RSS 멈춤) 대신 おたくま = X 화제·이상한 신상품·콜라보, Togetter 인기 = 일본 X 화제 글 모음
	'おたくま経済新聞'       => array( '일', 'trend', 'rss', 'https://otakuma.net/feed' ),
	'Togetter'              => array( '일', 'trend', 'rss', 'https://togetter.com/rss/hot' ),
	// 국내 광고·팝업 — 10/20 사용자가 원한 "캡틴큐 이선민 광고" 같은 국내 화제 광고는 브랜드브리프 RSS 맨 위에 그 기사가 있었다
	'브랜드브리프'           => array( '한', 'kr', 'rss', 'https://www.brandbrief.co.kr/rss/allArticle.xml' ),
	'매드타임스'             => array( '한', 'kr', 'rss', 'https://www.madtimes.co.kr/rss/allArticle.xml' ),
	// 10/20 밤 추가 — 콜라보·한정판 드롭. 한국어판이라 번역 토큰이 안 든다
	'Hypebeast KR'          => array( '한', 'kr', 'rss', 'https://hypebeast.kr/feed' ),
	// 10/20 밤 추가 — 화제에 숟가락 얹은 브랜드 패러디를 국내 언론이 묶어 쓴 기사를 낚는 그물.
	// 시험 때 테슬라 로드스터 패러디 기사 8개가 잡혔다. 링크는 구글 뉴스 주소라 특파원이 본문은 못 읽는다(radar.py)
	'구글 뉴스'              => array( '한', 'kr', 'rss', 'https://news.google.com/rss/search?q=%28%22%ED%8C%A8%EB%9F%AC%EB%94%94+%EA%B4%91%EA%B3%A0%22+OR+%22%EB%B0%94%EC%9D%B4%EB%9F%B4+%EA%B4%91%EA%B3%A0%22+OR+%22%EB%B0%88+%EB%A7%88%EC%BC%80%ED%8C%85%22+OR+%22%ED%8C%A8%EB%9F%AC%EB%94%94+%EC%98%81%EC%83%81%22+OR+%22%EA%B2%8C%EC%9E%84+%EC%BD%9C%EB%9D%BC%EB%B3%B4%22+OR+%22%EC%88%9F%EA%B0%80%EB%9D%BD+%EC%96%B9%EA%B8%B0%22%29+when%3A3d&hl=ko&gl=KR&ceid=KR:ko' ),
	// heyPOP: /feed/는 2021년에 멈춘 기본 글 종류라 못 쓰고, 실제 기사는 'n' 글 종류
	'heyPOP'                => array( '한', 'kr', 'wpjson', 'https://heypop.kr/wp-json/wp/v2/n?per_page=20&_fields=date,link,title,excerpt' ),
	// 10/20 저녁에 뺀 곳(사용자: "정치·시사·사건사고, 대놓고 스포츠 뉴스, 너무 테크 기사는 필요 없어") — 그때 피드 실측:
	// Google 트렌드 8개 전부 뉴스 검색어(김정은 속보·역삼동 화재·태풍), Front Office Sports 8개 전부 스포츠 비즈니스 뉴스,
	// The Verge 8개·TechCrunch 4개 대부분 기기·투자·AI 산업 뉴스. 지난 리포트의 알약 색 때문에만 남긴다(읽는 법 '')
	'Front Office Sports'   => array( '미', 'camp', '', '' ),
	'TechCrunch'            => array( '미', 'plat', '', '' ),
	'The Verge'             => array( '미', 'plat', '', '' ),
	'Google 트렌드'          => array( '한', 'trend', '', '' ),
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
	'camp'  => array( '#d9f1e3', '#2f9e6b', '#13784f' ), // 해외 캠페인 — 초록
	'plat'  => array( '#dde9ff', '#3a86ff', '#1f5fc4' ), // 플랫폼·크리에이터 — 파랑
	'web'   => array( '#ffe0ef', '#e0559a', '#b03a76' ), // 웹·앱 놀이 — 분홍
	'trend' => array( '#fff1c2', '#d99a00', '#8a6400' ), // 밈·트렌드 — 노랑
	'kr'    => array( '#fde3da', '#e4572e', '#b8432a' ), // 국내 광고·팝업 — 주황
);
const TP_RADAR_GROUP_NAME = array( 'camp' => '해외 캠페인', 'plat' => '플랫폼', 'web' => '웹·앱 놀이', 'trend' => '밈·트렌드', 'kr' => '국내 광고·팝업' );

function tp_radar_chip_color( $source ) {
	$group = TP_RADAR_SOURCES[ $source ][1] ?? '';
	return TP_RADAR_GROUP_COLOR[ $group ] ?? array( '#efe3cf', '#8c7f73', '#5a4a42' );
}

// 정보원 나라 한 글자(한·미·일·영…), 표에 없으면 ''(10/20 사용자 아이디어: 소스의 나라 표기)
function tp_radar_country( $source ) {
	return TP_RADAR_SOURCES[ $source ][0] ?? '';
}

// ── 실시간 피드: 정보원 새 글을 사이트가 직접 모은다(토큰 0) ──────────────
/*
 * 10/20 사용자 A안: 탭의 "📰 실시간 피드" 칸. Claude 없이 PHP가 RSS를 읽으니 몇 번을 열어도 비용이 없다.
 * 외국어는 원제목 그대로 — 번역·요약이 필요한 글만 체크해서 "정리 부탁"(아래)으로 특파원을 부른다.
 * 17곳을 차례로 받으면 탭이 십몇 초 멈춰서 한꺼번에(병렬) 받고, 결과는 30분 보관한다.
 */
const TP_RADAR_FEED_TTL  = 30 * MINUTE_IN_SECONDS;
const TP_RADAR_FEED_DAYS = 3; // 이보다 오래된 글은 피드에 안 보인다
// 정보원별 예외 — Link in Bio는 주 1~2편이라 3일 창에 0개일 때가 많다(10/20 사용자: "최근 2주")
const TP_RADAR_FEED_DAYS_BY = array( 'Link in Bio' => 14 );
const TP_RADAR_FEED_EACH = 8; // 정보원마다 최신 몇 개까지
// "📅 7일치" 버튼 = 그때 한 번만 넓게 받기(10/20 사용자 B안). 30분 보관이 끝나면 평소 창으로 저절로 돌아간다.
// 상한도 같이 올린다 — 8개 그대로면 글 많은 정보원은 3일 때와 똑같아서 넓힌 의미가 없다.
const TP_RADAR_FEED_WIDE_DAYS = 7;
const TP_RADAR_FEED_WIDE_EACH = 20;
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
 * 피드 사진 주소. 원본은 수 MB짜리도 있어서 WordPress.com 사진 변환기(Photon)로 400×250으로 줄여 받는다
 * (10/20 카드 격자로 바꾸며 192×120에서 키움)
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
	return esc_url_raw( 'https://i0.wp.com/' . preg_replace( '#^https?://#', '', strtok( $url, '?' ) ) . '?resize=400,250&ssl=1' );
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
 * $force = 🔄 새로고침 버튼(보관본을 버리고 다시 받기). $wide = 📅 7일치 버튼(이번 한 번만 넓게).
 */
function tp_radar_feed( $force = false, $wide = false ) {
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

	$items  = array();
	$errors = array();
	foreach ( $resps as $name => $r ) {
		if ( ! is_object( $r ) || ! isset( $r->status_code ) || $r->status_code >= 400 ) {
			$errors[ $name ] = is_object( $r ) && isset( $r->status_code ) ? 'HTTP ' . $r->status_code : '연결 실패';
			continue;
		}
		$skip = TP_RADAR_SKIP[ $name ] ?? '';
		$days = TP_RADAR_FEED_DAYS_BY[ $name ] ?? TP_RADAR_FEED_DAYS;
		$cut  = time() - ( $wide ? max( $days, TP_RADAR_FEED_WIDE_DAYS ) : $days ) * DAY_IN_SECONDS; // Link in Bio 14일은 넓힐 때도 줄이지 않는다
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
		$items = array_merge( $items, array_slice( $got, 0, $wide ? TP_RADAR_FEED_WIDE_EACH : TP_RADAR_FEED_EACH ) );
	}
	usort( $items, fn( $a, $b ) => $b['t'] <=> $a['t'] );
	$feed = array( 'at' => time(), 'items' => tp_radar_mix( $items ), 'errors' => $errors, 'wide' => $wide );
	set_transient( 'tp_radar_feed', $feed, TP_RADAR_FEED_TTL );
	return $feed;
}

/**
 * 최신순을 거의 그대로 두되, 같은 정보원이 TP_RADAR_RUN개 넘게 연달아 나오지 않게 살짝 섞는다.
 * 10/20 사용자: 최신순 유지 + GIGAZINE처럼 한꺼번에 여러 개 올리는 곳이 맨 위를 도배하지 않게.
 * 매 칸마다 "남은 글 중 가장 새 글"을 넣되, 그 글이 같은 정보원 연속을 넘기면 그다음 새 글을 넣는다.
 * 남은 글이 한 정보원뿐이면 어쩔 수 없이 그대로 잇는다.
 */
const TP_RADAR_RUN = 3;

function tp_radar_mix( $items ) {
	$out = array();
	while ( $items ) {
		$run  = array_slice( $out, -TP_RADAR_RUN );
		$full = count( $run ) === TP_RADAR_RUN && 1 === count( array_unique( array_column( $run, 'source' ) ) );
		$pick = 0;
		if ( $full ) {
			foreach ( $items as $k => $it ) {
				if ( $it['source'] !== $run[0]['source'] ) {
					$pick = $k;
					break;
				}
			}
		}
		$out[] = $items[ $pick ];
		array_splice( $items, $pick, 1 );
	}
	return $out;
}

/** 이미 찾아 둔 대표 사진(3일 보관). 없으면 false, 찾았는데 사진이 없는 글이면 '' */
function tp_radar_img_cached( $link ) {
	// 키의 2 = 400px 판(옛 192px 보관본은 버린다)
	return get_transient( 'tp_radar_img2_' . md5( $link ) );
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
			set_transient( 'tp_radar_img2_' . md5( $l ), $img, 3 * DAY_IN_SECONDS );
			$out[ $l ] = $img;
		}
		wp_send_json_success( $out );
	}
);

// ── 번역·거르기: 외국어 제목을 특파원이 우리 말투로 + 우리 거리 아닌 글은 접어 두기 ──────────
/*
 * 10/20 사용자: 브라우저 내장 번역은 말투가 부자연스럽고 성현 님 PC에서 될지도 몰라서, 자원을 조금 써서 특파원이 번역한다.
 * 같은 날 저녁: 정치·시사·사건사고, 경기 결과·부상 같은 스포츠 뉴스, 업계 인사·실적, 순수 테크 기사는 필요 없다
 * → 정규식으로는 "너무 테크"를 못 가려서, 제목을 번역하는 김에 특파원이 한국어 글까지 훑어 "접기"를 같이 표시한다.
 * 지우지 않고 접는 이유: 잘못 접힌 글이 있어도 피드 맨 아래에서 찾을 수 있게(사용자 선택).
 * routine이 깨어날 때마다(정리 부탁 · 아래 "번역·거르기 부탁") 아직 안 훑은 글만 본다.
 * tp_radar_titles: 링크 => array( 한국어 제목(한국어 글은 ''), 저장 시각, 접기 true|false )  — 가장 긴 피드 창(Link in Bio 14일)보다 넉넉히 15일 보관
 *   접기 칸이 없는 것 = 번역만 하고 아직 안 훑은 글(10/20 저녁 전 번역분)
 */
function tp_radar_titles() {
	$t = get_option( 'tp_radar_titles', array() );
	return is_array( $t ) ? $t : array();
}

/** 피드에서 특파원이 아직 안 훑은 글(번역할 외국어 글 + 접을지 볼 한국어 글) */
function tp_radar_unjudged( $feed ) {
	$tr = tp_radar_titles();
	return array_values( array_filter( $feed['items'], fn( $it ) => ! isset( $tr[ $it['link'] ][2] ) ) );
}

/** 특파원이 접어 둔 글인가 */
function tp_radar_folded( $tr, $link ) {
	return ! empty( $tr[ $link ][2] );
}

add_action(
	'admin_post_tp_radar_tr',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 부탁할 수 있어요' );
		}
		check_admin_referer( 'tp_radar_tr' );
		$rang = tp_radar_ring( 'titles' );
		if ( true === $rang ) {
			update_option( 'tp_radar_tr_at', time(), false );
		}
		$msg = true === $rang ? '특파원에게 번역·거르기를 부탁했어요. 몇 분 뒤 저절로 바뀌어요' : $rang;
		wp_safe_redirect( add_query_arg( array( 'view' => 'feed', 'tprd_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) ) );
		exit;
	}
);

// ── 정리 부탁: 피드에서 고른 글만 특파원이 핵심 정리·번역 ─────────────────
/*
 * 10/20 사용자: 피드에서 여러 개 체크 → 한 번에 특파원 호출. routine은 한 번 깨울 때마다 고정 비용이 커서 묶어서 부른다.
 * 결과는 탭의 리포트 칸에 쌓인다(부탁한 사람이 탭을 보고 있으니 따로 알리지 않는다).
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

/*
 * 1.6.0으로 올릴 때 한 번: 예전 아침 리포트(tp_radar_days)를 리포트 칸의 기록으로 옮긴다.
 * 10/20 14:27에 실제로 한 번 나간 리포트(메일 발송분)가 탭에서 사라지지 않게. 옮긴 뒤엔 아침 리포트용 옵션을 지운다
 * — tp_radar_mail에는 발신메일봇 열쇠가 있어서, 안 쓰는 비밀값을 사이트에 남겨 두지 않으려고(원본은 local/transis.env).
 */
add_action(
	'admin_init',
	function () {
		$days = get_option( 'tp_radar_days', null );
		if ( null === $days ) {
			return;
		}
		$asks = tp_radar_asks();
		foreach ( (array) $days as $ymd => $d ) {
			if ( empty( $d['items'] ) || 0 === strpos( (string) ( $d['mail'] ?? '' ), 'preview:' ) ) {
				continue; // 미리보기(시험)는 옮기지 않는다
			}
			$at = (int) ( $d['at'] ?? 0 );
			$at = $at > 0 ? $at : (int) strtotime( $ymd . ' 09:00:00' );
			while ( isset( $asks[ $at ] ) ) {
				++$at;
			}
			$asks[ $at ] = array(
				'at'      => $at,
				'by'      => '아침 리포트',
				'morning' => true,
				'status'  => 'done',
				'items'   => $d['items'],
				'result'  => $d['items'],
				'err'     => '',
			);
		}
		tp_radar_save_asks( $asks );
		delete_option( 'tp_radar_days' );
		delete_option( 'tp_radar_offered' );
		delete_option( 'tp_radar_mail' );
	}
);

/** 초인종: 특파원 routine을 지금 깨운다. $note는 실행 기록에서 알아보는 표시일 뿐. 성공하면 true, 실패하면 이유 문장 */
function tp_radar_ring( $note ) {
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
			'body'    => wp_json_encode( array( 'text' => $note ) ),
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
	$rang                  = tp_radar_ring( "ask {$id}" );
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
		wp_safe_redirect( $back . '&view=report#ask-' . $id );
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
		wp_safe_redirect( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report' ) . '#ask-' . $id );
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
		tp_radar_feed( true, ! empty( $_POST['wide'] ) ); // 두 버튼이 한 폼 — 누른 버튼만 이름이 실려 온다
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

		// 훑을 글(피드에서 아직 안 훑은 글) / 번역·접기 저장
		register_rest_route(
			'tp-radar/v1',
			'/titles',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => function () {
						$tr = tp_radar_titles();
						return array_map(
							fn( $it ) => array(
								'link'   => $it['link'],
								'source' => $it['source'],
								'orig'   => $it['orig'],
								'text'   => $it['text'],
								'lang'   => tp_radar_lang( $it['orig'] ),          // '' = 한국어(번역 필요 없음)
								'ko'     => $tr[ $it['link'] ][0] ?? '',          // 이미 번역한 글은 다시 번역하지 않게
							),
							array_slice( tp_radar_unjudged( tp_radar_feed() ), 0, 120 )
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'callback'            => function ( $req ) {
						$tr  = tp_radar_titles();
						$cut = time() - 15 * DAY_IN_SECONDS; // 가장 긴 피드 창(Link in Bio 14일)보다 길어야 같은 글을 다시 훑지 않는다
						$tr  = array_filter( $tr, fn( $v ) => ( $v[1] ?? 0 ) > $cut );
						$ko   = array_map( fn( $v ) => sanitize_text_field( (string) $v ), (array) $req->get_param( 'titles' ) );
						$fold = array_flip( array_map( 'esc_url_raw', (array) $req->get_param( 'hide' ) ) );
						$n    = 0;
						// judged = 이번에 훑은 글 전부(접지 않은 글도 "훑었다"로 남겨야 다음에 다시 안 읽는다)
						foreach ( array_map( 'esc_url_raw', (array) $req->get_param( 'judged' ) ) as $link ) {
							$title         = '' !== ( $ko[ $link ] ?? '' ) ? $ko[ $link ] : ( $tr[ $link ][0] ?? '' );
							$tr[ $link ]   = array( $title, time(), isset( $fold[ $link ] ) );
							$n            += isset( $fold[ $link ] ) ? 1 : 0;
						}
						update_option( 'tp_radar_titles', $tr, false );
						update_option( 'tp_radar_titles_at', time(), false );
						return array( 'saved' => count( (array) $req->get_param( 'judged' ) ), 'folded' => $n );
					},
				),
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
		wp_enqueue_style( 'tp-radar-fonts', 'https://fonts.googleapis.com/css2?family=Jua&family=Do+Hyeon&family=IBM+Plex+Sans+KR:wght@400;500;700&display=swap', array(), null ); // Do Hyeon = 방송 자막 글꼴(SIL OFL)
	}
);

/** 리포트 카드 하나 — 정리 부탁 결과 */
function tp_radar_card( $it, $i ) {
	list( $bg, $line, $fg ) = tp_radar_chip_color( $it['source'] );
	$lang                   = tp_radar_lang( $it['orig'] );
	?>
<article class="tprd-card">
<span class="tprd-no">#<?php echo (int) $i + 1; ?></span>
	<?php if ( ! empty( $it['image'] ) ) : ?>
<a class="tprd-screen" href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><img class="ph" src="<?php echo esc_url( $it['image'] ); ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><span class="tprd-cap"><?php echo tp_radar_where( $it['source'] ); // phpcs:ignore -- tp_radar_where가 이미 이스케이프 ?></span></a>
	<?php else : ?>
<span class="tprd-screen empty"><span class="tprd-cap"><?php echo tp_radar_where( $it['source'] ); // phpcs:ignore ?></span></span>
	<?php endif; ?>
<div class="tprd-bd">
<span class="tprd-orig" style="margin:0"><?php echo esc_html( substr( $it['date'], 5 ) ); ?> 원문</span>
	<?php if ( $lang ) : ?>
<span class="tprd-tr">🌐 특파원 번역</span>
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

/** 사진 위 자막 띠: 갈래 색 바탕에 흰 글씨 "나라 · 정보원"(10/20 사용자: 지금 분류하는 색으로) */
function tp_radar_where( $source ) {
	$fg = tp_radar_chip_color( $source )[2]; // 갈래 색 중 가장 진한 것 — 흰 글씨가 읽히게
	$c  = tp_radar_country( $source );
	return '<span class="tprd-where" style="background:' . esc_attr( $fg ) . '">' . esc_html( ( $c ? $c . ' · ' : '' ) . $source ) . '</span>';
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
	// 첫 화면은 피드(10/20 사용자: 피드를 둘러보고 골라서 리포트로 — 들어오면 바로 고를 수 있게)
	$view = 'report' === ( $_GET['view'] ?? '' ) ? 'report' : 'feed';
	$asks = tp_radar_asks();
	// 리포트는 처음엔 정리 부탁 6번만. 더 보기를 누를 때마다 6번씩
	$show = max( 6, (int) ( $_GET['show'] ?? 6 ) );
	$msg  = sanitize_text_field( wp_unslash( $_GET['tprd_msg'] ?? '' ) );
	// 피드는 피드 칸을 열 때만 받는다(리포트만 볼 때 15곳을 부르지 않게). 보관본이 있으면 개수만 보여 준다
	$feed = 'feed' === $view ? tp_radar_feed() : get_transient( 'tp_radar_feed' );
	?>
<div class="tprd">
<style>
/* 10/20 사용자 결정: "스티커 주방" 대신 "현장 연결"(시안 3) — 트란시스가 현장 기자로 나오는 뉴스 화면.
   LIVE 표시 · 흐르는 자막 · 사진 아래 자막 띠(갈래 색)에 나라와 정보원. 귀여운 쪽(Jua·둥근 모서리)은 그대로 */
body.toplevel_page_tp-trend-radar #wpcontent, body.toplevel_page_tp-trend-radar #wpfooter { background: #fdfbf7; }
.tprd { --ink: #3b2f2a; --muted: #8c7f73; --card: #ffffff; --soft: #efe8dc; --live: #e4572e; --hl: #fff7d1;
  max-width: 1000px; margin: 18px 20px 40px 0; color: var(--ink); font-family: "IBM Plex Sans KR", "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; font-size: 14px; line-height: 1.55; word-break: keep-all; }
.tprd * { box-sizing: border-box; }
/* 방송 머리: LIVE · 트란시스 · 제목 · 칸 */
.tprd-bug { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 4px 0 12px; }
.tprd-live { padding: 3px 10px; border-radius: 6px; background: var(--live); color: #fff; font: 400 15px/1.3 "Do Hyeon", sans-serif; letter-spacing: .04em; }
.tprd-bug img { width: 54px; height: 54px; }
.tprd-bug h1 { margin: 0; padding: 0; font: 400 28px/1.2 "Jua", sans-serif; color: var(--ink); }
.tprd-bug p { margin: 0; color: var(--muted); font-size: 12.5px; }
@media (prefers-reduced-motion: no-preference) {
  .tprd-bug img { animation: tprd-bob 3s ease-in-out infinite; }
  @keyframes tprd-bob { 50% { transform: translateY(-3px) rotate(-3deg); } }
}
/* 흐르는 자막: 피드의 최신 제목들. 움직임을 줄이는 설정이면 멈춘 한 줄 */
.tprd-ticker { overflow: hidden; margin: 0 0 16px; border-radius: 8px; background: var(--ink); color: #fff; font-size: 13px; }
.tprd-ticker > div { display: inline-flex; gap: 28px; padding: 6px 14px; white-space: nowrap; }
.tprd-ticker b { color: #ffe45c; font-weight: 700; margin-right: 6px; }
@media (prefers-reduced-motion: no-preference) {
  .tprd-ticker > div { animation: tprd-tick 60s linear infinite; }
  .tprd-ticker:hover > div { animation-play-state: paused; }
  @keyframes tprd-tick { from { transform: translateX(0); } to { transform: translateX(-50%); } }
}
.tprd-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 22px; }
.tprd-nav a { text-decoration: none; }
.tprd-tab { padding: 6px 16px; border-radius: 999px; background: var(--soft); color: var(--ink); font: 400 16px/1.2 "Jua", sans-serif; }
.tprd-tab.on { background: var(--ink); color: #fff; }
.tprd-tab small { font: 700 11px "IBM Plex Sans KR", sans-serif; opacity: .75; }
.tprd-doc { margin-left: auto; padding: 5px 12px; border: 1.5px dashed var(--ink); border-radius: 999px; color: var(--ink); font-size: 12.5px; font-weight: 700; }
.tprd-doc:hover { background: var(--hl); }
.tprd-msg { margin: 0 0 18px; padding: 10px 14px; border-radius: 10px; background: var(--hl); font-weight: 700; }
/* 날짜·정리 부탁 머리 = 방송 코너 제목 */
.tprd-day { margin: 0 0 34px; scroll-margin-top: 50px; }
.tprd-day h2 { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 14px; font: 400 21px/1.2 "Jua", sans-serif; color: var(--ink); }
.tprd-corner { padding: 3px 10px; border-radius: 6px; background: var(--live); color: #fff; font: 400 14px/1.3 "Do Hyeon", sans-serif; }
.tprd-corner.ask { background: #1f5fc4; }
.tprd-anchor { display: flex; align-items: center; gap: 8px; margin: -4px 0 14px; padding: 7px 12px; border-radius: 10px; background: var(--hl); font-size: 13px; }
.tprd-anchor img { width: 30px; height: 30px; }
.tprd-state { padding: 2px 10px; border-radius: 999px; background: #d9f1e3; color: #13784f; font: 700 12px/1.5 "IBM Plex Sans KR", sans-serif; }
.tprd-state.bad { background: #fde3da; color: #b42333; }
.tprd-state.wait { background: #fff1c2; color: #8a6400; }
.tprd-wait { margin: 0; padding: 12px 16px 12px 32px; border-radius: 12px; background: var(--card); box-shadow: 0 2px 10px rgba(59,47,42,.08); }
.tprd-wait li { margin: 3px 0; }
.tprd-recall { display: inline; margin-left: 4px; }
.tprd-recall button, .tprd-btn { padding: 6px 14px; border: 0; border-radius: 999px; background: #ffe45c; color: var(--ink); font: 700 13px "IBM Plex Sans KR", sans-serif; cursor: pointer; box-shadow: 0 2px 0 #d9b800; }
.tprd-btn:disabled { background: var(--soft); color: var(--muted); box-shadow: none; cursor: default; }
/* 사진 위 자막 띠: 갈래 색 바탕 + 흰 글씨(나라 · 정보원), 그 아래 제목 */
.tprd-screen { position: relative; display: block; overflow: hidden; background: #2b2320; }
.tprd-screen img.ph { display: block; width: 100%; height: 100%; object-fit: cover; }
.tprd-cap { position: absolute; left: 0; right: 0; bottom: 0; padding: 26px 11px 9px; background: linear-gradient(transparent, rgba(0,0,0,.72)); color: #fff; }
.tprd-where { display: inline-block; margin-bottom: 4px; padding: 1px 8px; border-radius: 4px; color: #fff; font: 400 13px/1.45 "Do Hyeon", sans-serif; }
.tprd-cap .tt { display: block; font: 400 17px/1.3 "Do Hyeon", sans-serif; color: #fff; text-decoration: none; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.tprd-cap a.tt:hover { text-decoration: underline; }
.tprd-reporter { position: absolute; top: 8px; right: 8px; width: 38px; height: 38px; border-radius: 50%; background: #fff; padding: 1px; }
/* 리포트 카드: 넓은 화면(16:9) + 아래 원고 */
.tprd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px 18px; align-items: start; }
.tprd-card { position: relative; display: flex; flex-direction: column; background: var(--card); border-radius: 16px; box-shadow: 0 4px 16px rgba(59,47,42,.10); overflow: hidden; }
.tprd-card .tprd-screen { aspect-ratio: 2.2 / 1; }
.tprd-card .tprd-screen.empty { aspect-ratio: auto; min-height: 92px; }
.tprd-no { position: absolute; top: 9px; left: 9px; z-index: 1; padding: 1px 9px; border-radius: 6px; background: var(--live); color: #fff; font: 400 14px/1.4 "Do Hyeon", sans-serif; }
.tprd-bd { padding: 12px 14px 14px; }
.tprd-chip { display: inline-block; padding: 2px 10px; border-radius: 999px; border: 1.5px solid; font-size: 11.5px; font-weight: 700; }
.tprd-flag { display: inline-block; width: 17px; height: 17px; margin: 0 5px 0 -5px; border-radius: 50%; background: #3b2f2a; color: #fffdf8; font-size: 10px; line-height: 17px; text-align: center; vertical-align: 1px; }
.tprd-tr { display: inline-block; padding: 0 7px; border-radius: 6px; background: #ebe5ff; color: #5b3fd0; font-size: 11px; font-weight: 700; }
.tprd-tr.wait { background: var(--soft); color: var(--muted); }
.tprd-bd h3 { margin: 6px 0 2px; font: 400 19px/1.3 "Jua", sans-serif; }
.tprd-bd h3 a { color: var(--ink); text-decoration: none; }
.tprd-bd h3 a:hover { text-decoration: underline; }
.tprd-bd p { margin: 6px 0 0; font-size: 13.5px; line-height: 1.6; }
.tprd-bd .tprd-orig { margin-top: 6px; font-size: 12px; color: var(--muted); }
.tprd-pts { margin-top: 10px; padding: 10px 12px; border-radius: 10px; background: #faf6ef; font-size: 13px; }
.tprd-pts b { display: block; margin-bottom: 2px; }
.tprd-pts b span { font-weight: 400; color: var(--muted); }
.tprd-pts ul { margin: 0; padding-left: 16px; list-style: "· "; }
.tprd-pts li { margin: 2px 0; }
.tprd-more { display: inline-block; padding: 8px 18px; border-radius: 999px; background: var(--ink); color: #fff; font-weight: 700; text-decoration: none; }
/* 실시간 피드: 거르기 → 카드 격자(체크) → 아래에 붙는 정리 부탁 막대 */
.tprd-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 14px; }
.tprd-filters button { padding: 4px 12px; border: 2px solid var(--ink); border-radius: 999px; background: var(--card); color: var(--ink); font: 700 12.5px "IBM Plex Sans KR", sans-serif; cursor: pointer; }
.tprd-filters button[aria-pressed="true"] { background: var(--ink); color: #fff; }
.tprd-filters .tprd-refresh { margin-left: auto; font-size: 12px; color: var(--muted); }
.tprd-filters .tprd-refresh button { background: #ffe45c; border-color: #ffe45c; }
.tprd-filters .tprd-refresh button[name="wide"] { background: #fff; }
.tprd-trb { margin-left: 0 !important; }
.tprd-trb button { background: #ebe5ff !important; border-color: #ebe5ff !important; }
.tprd-rule { margin: -6px 0 12px; font-size: 12px; color: var(--muted); }
.tprd-errs { margin: -6px 0 12px; font-size: 12px; color: #b42333; }
.tprd-feed { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
.tprd-feed li { position: relative; background: var(--card); border-radius: 14px; box-shadow: 0 3px 12px rgba(59,47,42,.10); overflow: hidden; transition: transform .15s, box-shadow .15s; }
.tprd-feed li:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(59,47,42,.16); }
.tprd-feed li.on { box-shadow: 0 0 0 3px var(--live), 0 8px 20px rgba(59,47,42,.16); }
.tprd-feed label { display: flex; flex-direction: column; height: 100%; cursor: pointer; }
/* overflow를 숨겨야 큰 사진이 16:10 칸을 밀어 늘리지 않는다 */
.tprd-feed .thumb { aspect-ratio: 16 / 10; }
.tprd-feed input { position: absolute; top: 9px; left: 9px; z-index: 2; width: 20px; height: 20px; margin: 0; accent-color: var(--live); }
.tprd-feed .bd { display: grid; gap: 4px; padding: 8px 11px 11px; }
.tprd-feed .meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
.tprd-feed .sub { font-size: 12px; color: var(--muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.tprd-feed .st { padding: 0 8px; border-radius: 999px; background: var(--soft); color: var(--ink); font-weight: 700; font-size: 11px; text-decoration: none; }
.tprd-bar { position: sticky; bottom: 12px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; background: var(--ink); color: #fff; box-shadow: 0 8px 24px rgba(59,47,42,.25); }
.tprd-bar span { color: #e9e2d8; font-size: 13px; }
.tprd-bell { margin: 30px 0 0; padding: 12px 16px; border-radius: 12px; background: var(--card); box-shadow: 0 2px 10px rgba(59,47,42,.08); }
.tprd-bell summary { cursor: pointer; font-weight: 700; }
.tprd-folded { margin: 22px 0 0; }
.tprd-folded summary { cursor: pointer; margin-bottom: 12px; font-weight: 700; color: var(--muted); }
.tprd-folded summary small { font-weight: 400; }
.tprd-folded .tprd-feed li { opacity: .75; }
.tprd-bell summary small { font-weight: 400; color: var(--muted); }
.tprd-bell label { display: block; margin: 10px 0 4px; font-weight: 700; font-size: 12.5px; }
.tprd-bell input { width: 100%; max-width: 640px; }
</style>
<div class="tprd-bug">
<span class="tprd-live">● LIVE</span>
<img src="<?php echo esc_url( plugins_url( 'press.png', __FILE__ ) ); ?>" alt="트란시스 특파원">
<div><h1>트란시스 현장 연결</h1><p>정보원 새 글을 둘러보고, 마음에 드는 글을 체크하면 특파원이 리포트로 정리해 와요</p></div>
</div>
	<?php
	// 흐르는 자막: 받아 둔 피드의 최신 제목 8개(번역이 있으면 한국어). 피드를 아직 안 받았으면 없음
	$trs  = tp_radar_titles();
	$tick = is_array( $feed ) ? array_slice( array_values( array_filter( $feed['items'], fn( $it ) => ! tp_radar_folded( $trs, $it['link'] ) ) ), 0, 8 ) : array();
	?>
	<?php if ( $tick ) : ?>
<div class="tprd-ticker" aria-label="최신 소식"><div>
		<?php foreach ( array_merge( $tick, $tick ) as $it ) : // 두 번 이어 붙여야 끊김 없이 돈다 ?>
<span><b><?php echo esc_html( tp_radar_country( $it['source'] ) . ' ' . $it['source'] ); ?></b><?php echo esc_html( $trs[ $it['link'] ][0] ?? $it['orig'] ); ?></span>
		<?php endforeach; ?>
</div></div>
	<?php endif; ?>
<nav class="tprd-nav">
<a class="tprd-tab<?php echo 'feed' === $view ? ' on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) ); ?>">📰 실시간 피드<?php echo is_array( $feed ) ? ' <small>' . count( $feed['items'] ) . '</small>' : ''; ?></a>
<a class="tprd-tab<?php echo 'report' === $view ? ' on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report' ) ); ?>">🎙️ 리포트<?php echo $asks ? ' <small>' . count( $asks ) . '</small>' : ''; ?></a>
<a class="tprd-doc" href="<?php echo esc_url( TP_RADAR_REPORT ); ?>" target="_blank" rel="noopener">📊 트렌드포털 데이터마이닝 보고서</a>
</nav>
	<?php if ( $msg ) : ?>
<p class="tprd-msg"><?php echo esc_html( $msg ); ?></p>
	<?php endif; ?>
	<?php
	if ( 'feed' === $view ) {
		tp_radar_feed_view( $feed, $asks );
	} else {
		tp_radar_report_view( $asks, $show );
	}
	?>
</div>
	<?php
}

/** 🎙️ 리포트 칸: 정리 부탁과 그 결과, 최근 것부터 */
function tp_radar_report_view( $asks, $show ) {
	$pending = false;
	if ( ! $asks ) :
		?>
<p style="text-align:center">아직 리포트가 없어요. 📰 실시간 피드에서 마음에 드는 글을 체크하고 📝 정리 부탁을 눌러 보세요.</p>
		<?php
	endif;
	foreach ( array_slice( $asks, 0, $show, true ) as $id => $a ) :
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
<h2><span class="tprd-corner ask"><?php echo empty( $a['morning'] ) ? '📝 정리 부탁' : '🌅 아침 리포트'; ?></span><?php echo esc_html( tp_radar_weekday( wp_date( 'Y-m-d', (int) $a['at'] ) ) . ' ' . wp_date( 'H:i', (int) $a['at'] ) ); ?> · <?php echo count( (array) $a['items'] ); ?>개
<span class="tprd-state<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $says . ( ! empty( $a['err'] ) ? ' — ' . $a['err'] : '' ) ); ?></span>
		<?php if ( $late || ! empty( $a['err'] ) ) : ?>
<form class="tprd-recall" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_recall' ); ?><input type="hidden" name="action" value="tp_radar_recall"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"><button type="submit">🔔 다시 부르기</button></form>
		<?php endif; ?>
</h2>
		<?php if ( 'done' === $st ) : ?>
<p class="tprd-anchor"><img src="<?php echo esc_url( plugins_url( 'press.png', __FILE__ ) ); ?>" alt="">트란시스 특파원, 현장 연결합니다! <?php echo esc_html( empty( $a['morning'] ) ? '부탁하신 글 ' . count( (array) $a['result'] ) . '개 정리했어요' : '오늘 거리 ' . count( (array) $a['result'] ) . '개예요' ); ?></p>
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
	<?php if ( count( $asks ) > $show ) : ?>
<p style="text-align:center"><a class="tprd-more" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report&show=' . ( $show + 6 ) ) ); ?>">✈️ 지난 리포트 더 보기</a></p>
	<?php endif; ?>
	<?php
}

/** 피드 카드 한 장(보이는 칸과 접힌 칸이 같이 쓴다) */
function tp_radar_feed_row( $k, $it, $state, $tr ) {
	$st   = $state[ $it['link'] ] ?? null;
	$lang = tp_radar_lang( $it['orig'] );
	$ko   = $tr[ $it['link'] ][0] ?? '';
	// 사진: RSS에 있으면 그대로, 없으면 찾아 둔 것, 그것도 없으면 화면에 보일 때 찾는다(data-need)
	$img  = $it['image'] ?? '';
	$img  = '' !== $img ? $img : tp_radar_img_cached( $it['link'] );
	$need = false === $img;
	?>
<li data-g="<?php echo esc_attr( TP_RADAR_SOURCES[ $it['source'] ][1] ?? '' ); ?>"><label for="tprd-f<?php echo (int) $k; ?>">
<input type="checkbox" id="tprd-f<?php echo (int) $k; ?>" name="links[]" value="<?php echo esc_attr( $it['link'] ); ?>">
<span class="thumb tprd-screen"<?php echo $need ? ' data-need="' . esc_attr( $it['link'] ) . '"' : ''; ?>><?php echo $img ? '<img class="ph" src="' . esc_url( $img ) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : ''; ?>
<img class="tprd-reporter" src="<?php echo esc_url( plugins_url( 'press.png', __FILE__ ) ); ?>" alt="">
<span class="tprd-cap"><?php echo tp_radar_where( $it['source'] ); // phpcs:ignore -- 이미 이스케이프 ?><a class="tt" href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ko ? $ko : $it['orig'] ); ?></a></span></span>
<span class="bd">
<span class="meta"><?php echo esc_html( substr( $it['date'], 5 ) . ' · ' . tp_radar_ago( $it['t'] ) ); ?> 송출
		<?php if ( $st ) : ?>
<a class="st" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report' ) . $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></a>
		<?php endif; ?>
</span>
		<?php if ( $ko ) : // 번역 제목은 사진 위, 원제는 여기 — 원문 확인용 ?>
<span class="sub"><span class="tprd-tr">번역</span> <?php echo esc_html( $it['orig'] ); ?></span>
		<?php elseif ( $lang ) : ?>
<span class="sub"><span class="tprd-tr wait">번역 전</span></span>
		<?php elseif ( $it['text'] ) : ?>
<span class="sub"><?php echo esc_html( $it['text'] ); ?></span>
		<?php endif; ?>
</span>
</label></li>
	<?php
}

/** 📰 실시간 피드 칸: 갈래 거르기 → 글 목록(체크) → 정리 부탁 막대 → 초인종 설정 */
function tp_radar_feed_view( $feed, $asks ) {
	// 이미 정리 부탁한 글은 표시 — 같은 글을 또 부탁하지 않게
	$state = array();
	foreach ( array_reverse( $asks, true ) as $id => $a ) {
		foreach ( (array) $a['items'] as $it ) {
			$state[ $it['link'] ] = array( 'done' === ( $a['status'] ?? '' ) ? '📝 정리됨' : '⏳ 정리 중', '#ask-' . $id );
		}
	}
	$bell = get_option( 'tp_radar_bell', array() );
	$tr   = tp_radar_titles();
	$todo = tp_radar_unjudged( $feed );
	// 특파원이 접어 둔 글은 맨 아래 접힌 칸으로. 갈래 개수·전체 개수는 보이는 글만 센다
	$show = array();
	$fold = array();
	foreach ( $feed['items'] as $k => $it ) {
		if ( tp_radar_folded( $tr, $it['link'] ) ) {
			$fold[ $k ] = $it;
		} else {
			$show[ $k ] = $it;
		}
	}
	$count = array();
	foreach ( $show as $it ) {
		$g           = TP_RADAR_SOURCES[ $it['source'] ][1] ?? '';
		$count[ $g ] = ( $count[ $g ] ?? 0 ) + 1;
	}
	// 번역 부탁을 보냈는데 그 뒤로 아직 저장이 없고 20분이 안 지났으면 '번역하는 중'
	$asked = (int) get_option( 'tp_radar_tr_at', 0 );
	$busy  = $asked > (int) get_option( 'tp_radar_titles_at', 0 ) && time() - $asked < 20 * MINUTE_IN_SECONDS;
	?>
<div class="tprd-filters" role="group" aria-label="갈래 거르기">
<button type="button" data-g="" aria-pressed="true">전체 <?php echo count( $show ); ?></button>
	<?php foreach ( TP_RADAR_GROUP_NAME as $g => $name ) : ?>
		<?php list( $bg, $line, $fg ) = TP_RADAR_GROUP_COLOR[ $g ]; ?>
<button type="button" data-g="<?php echo esc_attr( $g ); ?>" aria-pressed="false" style="border-color:<?php echo esc_attr( $line ); ?>"><?php echo esc_html( $name . ' ' . ( $count[ $g ] ?? 0 ) ); ?></button>
	<?php endforeach; ?>
<form class="tprd-refresh" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_refresh' ); ?><input type="hidden" name="action" value="tp_radar_refresh"><?php echo esc_html( ( empty( $feed['wide'] ) ? '' : '📅 7일치 · ' ) . tp_radar_ago( $feed['at'] ) ); ?> 받음 <button type="submit">🔄 새로고침</button> <button type="submit" name="wide" value="1" title="이번 한 번만 7일·정보원당 20개까지 받아요. 30분 뒤엔 평소(3일)로 돌아가요">📅 7일치</button></form>
	<?php if ( $todo ) : ?>
<form class="tprd-refresh tprd-trb" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_tr' ); ?><input type="hidden" name="action" value="tp_radar_tr"><button type="submit"<?php echo $busy ? ' disabled' : ''; ?>><?php echo $busy ? '🌐 특파원이 훑는 중…' : '🌐 번역·거르기 부탁 ' . count( $todo ) . '개'; ?></button></form>
		<?php if ( $busy ) : // 끝나면 저절로 바뀌게 30초마다 새로 읽는다 ?>
<script>setTimeout(function () { location.reload(); }, 30000);</script>
		<?php endif; ?>
	<?php endif; ?>
</div>
<p class="tprd-rule">🙈 <b>특파원 거르기</b> — <b>남김</b> 재밌는 광고·캠페인, 화제에 숟가락 얹은 브랜드 패러디, 밈·X 화제, 게임·젊은 세대 마케팅, 신기한 사이트·앱, 앱 새 기능, 팝업·콜라보, 트렌드 데이터 · <b>접음</b> 대놓고 뉴스인 글(정치·사건사고, 경기 결과·부상, 업계 인사·실적·수상, 테크·AI 산업), 같은 이야기 두 번째부터</p>
	<?php if ( $feed['errors'] ) : ?>
<p class="tprd-errs">읽지 못한 곳: <?php echo esc_html( implode( ' · ', array_map( fn( $n, $e ) => "$n($e)", array_keys( $feed['errors'] ), $feed['errors'] ) ) ); ?></p>
	<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tprd-ask">
	<?php wp_nonce_field( 'tp_radar_ask' ); ?>
<input type="hidden" name="action" value="tp_radar_ask">
<ul class="tprd-feed">
	<?php
	foreach ( $show as $k => $it ) {
		tp_radar_feed_row( $k, $it, $state, $tr );
	}
	?>
</ul>
	<?php if ( $fold ) : ?>
<details class="tprd-folded">
<summary>🙈 특파원이 접어 둔 글 <?php echo count( $fold ); ?>개 <small>대놓고 뉴스인 글과 겹치는 이야기 — 잘못 접혔으면 여기서 골라도 돼요</small></summary>
<ul class="tprd-feed">
		<?php
		foreach ( $fold as $k => $it ) {
			tp_radar_feed_row( $k, $it, $state, $tr );
		}
		?>
</ul>
</details>
	<?php endif; ?>
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
				if (src) { var im = new Image(); im.className = 'ph'; im.alt = ''; im.referrerPolicy = 'no-referrer'; im.src = src; el.insertBefore(im, el.firstChild); }
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
