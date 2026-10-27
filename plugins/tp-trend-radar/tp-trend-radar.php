<?php
/**
 * Plugin Name: TP Trend Radar
 * Description: 관리자 전용 탭 "트란시스 특파원"(처음 이름은 트렌드 레이더). 정보원 새 글을 실시간 피드로 모아 보여 주고, 체크한 글을 클라우드 routine(tools/radar/radar.py)이 카드뉴스 형식의 비공개 임시글(모의 글)로 만들어 탭에 링크로 쌓는다.
 * Version: 2.1.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/20 사용자 결정: 이 도구는 "필요할 때 들어와서 아이디어를 얻는 곳"이다.
 * 피드를 둘러보고 → 마음에 드는 글을 체크 → 특파원이 카드뉴스 형식의 비공개 임시글(모의 글)로 만든다 → 사람이 검토하고 발행.
 * 매일 아침 리포트와 메일 발송은 범위에서 뺐다(1.6.0). 글자 요약 리포트는 2.0.0(10/28 사용자)에서 모의 글로 바꿨다.
 *
 * 저장: 옵션(autoload 끔 — 이 탭과 REST에서만 읽는다)
 *   tp_radar_asks       모의 글 부탁과 그 결과(아래 "모의 글 부탁")
 *   tp_radar_titles     피드 제목 번역(아래 "제목 번역")
 *   tp_radar_archive    받아 둔 글 보관함(피드는 여기서 최근 7일을 읽는다)
 *   tp_radar_bell       초인종 ① 번역·거르기 routine(Sonnet) 트리거 주소·열쇠
 *   tp_radar_bell_mock  초인종 ② 모의 글 routine(Opus) 트리거 주소·열쇠
 */

const TP_RADAR_SLUG = 'tp-trend-radar';

// 안내 문구(?tprd_msg=)는 한 번만 보여 준다 — 워드프레스가 페이지를 띄운 뒤 주소창에서 지워 준다.
// 10/20 사용자: 번역이 끝나도 문구가 안 사라짐 → '훑는 중' 30초 자동 새로고침이 문구 붙은 주소를 그대로 다시 읽고 있었다
add_filter( 'removable_query_args', fn( $args ) => array_merge( $args, array( 'tprd_msg' ) ) );

// ── 모의 글 결과 ─────────────────────────────────────────

/**
 * 모의 글 하나의 결과(radar.py answer가 보낸 것). 성공이면 post_id·preview·edit·changes, 못 만들었으면 error.
 * 링크는 우리 사이트 주소만 받는다 — 결과 줄의 버튼이 엉뚱한 곳으로 가지 않게
 */
function tp_radar_clean_draft( $it ) {
	$own = function ( $u ) {
		$u = esc_url_raw( (string) $u );
		return 0 === strpos( $u, home_url( '/' ) ) ? $u : '';
	};
	return array(
		'source'  => sanitize_text_field( $it['source'] ?? '' ),
		'orig'    => sanitize_text_field( $it['orig'] ?? '' ),    // 원문 제목
		'link'    => esc_url_raw( $it['link'] ?? '' ),             // 원문 주소
		'date'    => sanitize_text_field( $it['date'] ?? '' ),    // 원문 날짜 Y-m-d
		'title'   => sanitize_text_field( $it['title'] ?? '' ),   // 만든 글의 제목
		'post_id' => (int) ( $it['post_id'] ?? 0 ),
		'preview' => $own( $it['preview'] ?? '' ),
		'edit'    => $own( $it['edit'] ?? '' ),
		// 셰프와 같은 표시 규칙: '꼭 확인:'·'확인 필요:'로 시작하는 줄은 발행 전에 봐야 할 것
		'changes' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $it['changes'] ?? array() ) ) ) ), 0, 30 ),
		'error'   => sanitize_text_field( $it['error'] ?? '' ),
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
 * 읽는 법: rss(RSS·Atom) · reddit(소개된 사이트 주소를 링크로) · gtrends(검색어 + 관련 기사) · wpjson · youtube(채널 id) · sitemap(구글 뉴스 사이트맵).
 * 읽는 법이 ''이면 읽지 않는다 — 뺀 정보원도 지난 리포트의 알약 색 때문에 남겨 둔다.
 */
const TP_RADAR_SOURCES = array(
	// 해외 캠페인 — 하트가 잘 나오는 갈래. Famous Campaigns는 우리 카드 출처 1위(46편), 런던 Speakeasy Media
	'Famous Campaigns'      => array( 'gb', 'camp', 'rss', 'https://www.famouscampaigns.com/feed/' ), // www 없는 주소는 526
	'Muse by Clio'          => array( 'us', 'camp', 'rss', 'https://musebycl.io/rss.xml' ),
	'AdverTimes'            => array( 'jp', 'camp', 'rss', 'https://www.advertimes.com/feed/' ),
	// 10/22 추가(사용자: "사람 냄새 많이 나는 컨텐츠 올리는 사이트를 추가해도 돼") — 일본 광고 사례를 매일 소개. 스포 회피 광고·역 연애 이야기 음성 콘텐츠처럼
	// 사람 이야기가 든 기획이 많다. 나라는 .jp 도메인과 일본어 매체라는 점으로 적었고 운영사는 못 찾았다(회사 소개 페이지 없음)
	'Adgang'                => array( 'jp', 'camp', 'rss', 'https://adgang.jp/feed' ),
	// 플랫폼·크리에이터 — "인스타그램 이렇게 변합니다" 같은 글의 출처가 이 매체들이었다
	'Social Media Today'    => array( 'us', 'plat', 'rss', 'https://www.socialmediatoday.com/feeds/news/' ),
	// 10/20 밤 추가 — 브랜드가 SNS에서 밈을 타는 법만 다루는 뉴스레터(Rachel Karten). 주 1~2편이라 속도보다 아이디어용
	'Link in Bio'           => array( 'us', 'plat', 'rss', 'https://www.milkkarten.net/feed' ),
	// 웹·앱·디지털 놀이 — "신기한 사이트 모음" 갈래
	'GIGAZINE'              => array( 'jp', 'web', 'rss', 'https://gigazine.net/news/rss_2.0/' ),
	'Laughing Squid'        => array( 'us', 'web', 'rss', 'https://laughingsquid.com/feed/' ), // ねとらぼ 자리 — 그쪽 RSS는 2025-05에 멈춤
	// 밈·트렌드 — 화제 비율 1위 갈래
	'Know Your Meme'        => array( 'us', 'trend', 'rss', 'https://knowyourmeme.com/newsfeed.rss' ),
	'Mashable'              => array( 'us', 'trend', 'rss', 'https://mashable.com/feeds/rss/all' ),
	'오픈서베이'             => array( 'kr', 'trend', 'rss', 'https://blog.opensurvey.co.kr/feed/' ),
	// 10/20 밤 추가 — 틱톡·인터넷 화제를 가장 빨리 쓰는 곳(사용자: "트렌디하고 밈을 가장 빠르게")
	'Daily Dot'             => array( 'us', 'trend', 'rss', 'https://www.dailydot.com/feed/' ),
	// 10/20 밤 추가 — X에서 뜬 걸 누군가 정리해 온 곳(사용자: "그걸 우리가 빠르게 낚아채는 거지").
	// ねとらぼ(RSS 멈춤) 대신 おたくま = X 화제·이상한 신상품·콜라보.
	// Togetter 인기(일본 X 화제 글 모음)도 넣었다가 뺐다 — 사이트 서버에서 받으면 403(로컬은 200, 10/20 밤)
	'おたくま経済新聞'       => array( 'jp', 'trend', 'rss', 'https://otakuma.net/feed' ),
	// 10/22 추가(같은 요청) — SNS에서 반응 얻은 일반인 에피소드(육아 메모·반려동물·외국인이 본 일본). 연예인 근황 글도 섞여 있어 접기가 거른다. 운영사 株式会社Creative2(도쿄)
	'Hint-Pot'              => array( 'jp', 'trend', 'rss', 'https://hint-pot.jp/feed' ),
	// 10/20 추가(사용자: "한국꺼로 좁혀서") — SNS 마케터용 매거진. 월별 밈 모음·인기 릴스·브랜드 오프라인 기획, 하루 1편꼴.
	// 국내 밈은 X·인스타·커뮤니티에서 도는데 거긴 RSS가 없거나 톤이 안 맞아서, 그걸 정리해 오는 곳으로 받는다
	'고구마팜'               => array( 'kr', 'trend', 'rss', 'https://gogumafarm.kr/feed/' ),
	// 국내 광고·팝업 — 10/20 사용자가 원한 "캡틴큐 이선민 광고" 같은 국내 화제 광고는 브랜드브리프 RSS 맨 위에 그 기사가 있었다
	'브랜드브리프'           => array( 'kr', 'kr', 'rss', 'https://www.brandbrief.co.kr/rss/allArticle.xml' ),
	'매드타임스'             => array( 'kr', 'kr', 'rss', 'https://www.madtimes.co.kr/rss/allArticle.xml' ),
	// 10/20 밤 추가 — 콜라보·한정판 드롭. 한국어판이라 번역 토큰이 안 든다
	'Hypebeast KR'          => array( 'kr', 'kr', 'rss', 'https://hypebeast.kr/feed' ),
	// 10/22 추가 — 하입비스트 코리아 론칭 소식도 낸 한국 스트리트·컬처 웹 매거진(운영: 한국). RSS가 없어(/feed·/rss·/rss.xml 전부 404)
	// 구글 뉴스 사이트맵을 읽는다. 사이트맵에는 최근 2일 10개만 있지만 받을 때마다 보관함(15일)에 쌓이니 자주 열수록 길어진다.
	// robots.txt는 /admin/만 막음. 사진은 사이트맵에 없어 탭이 화면에 보일 때 og:image로 찾는다
	'아이즈매거진'           => array( 'kr', 'kr', 'sitemap', 'https://www.eyesmag.com/sitemap/sitemap-news.xml.gz' ),
	// 10/20 밤 추가 — 화제에 숟가락 얹은 브랜드 패러디를 국내 언론이 묶어 쓴 기사를 낚는 그물.
	// 시험 때 테슬라 로드스터 패러디 기사 8개가 잡혔다. 링크는 구글 뉴스 주소라 특파원이 본문은 못 읽는다(radar.py)
	'구글 뉴스'              => array( 'kr', 'kr', 'rss', 'https://news.google.com/rss/search?q=%28%22%ED%8C%A8%EB%9F%AC%EB%94%94+%EA%B4%91%EA%B3%A0%22+OR+%22%EB%B0%94%EC%9D%B4%EB%9F%B4+%EA%B4%91%EA%B3%A0%22+OR+%22%EB%B0%88+%EB%A7%88%EC%BC%80%ED%8C%85%22+OR+%22%ED%8C%A8%EB%9F%AC%EB%94%94+%EC%98%81%EC%83%81%22+OR+%22%EA%B2%8C%EC%9E%84+%EC%BD%9C%EB%9D%BC%EB%B3%B4%22+OR+%22%EC%88%9F%EA%B0%80%EB%9D%BD+%EC%96%B9%EA%B8%B0%22%29+when%3A7d&hl=ko&gl=KR&ceid=KR:ko' ),
	// 10/20 추가 — 두 번째 그물: 국내 언론의 "온라인·SNS에서 화제" 기사 중 광고·브랜드·콜라보·신제품만(おたくま의 한국판 역할).
	// 시험 때 3일 4개 중 2개가 캡틴큐 이선민 광고. 위 그물과 합치지 않은 이유: 정보원마다 8개 상한이라 양 적은 이쪽이 밀려난다
	'구글 뉴스 화제'          => array( 'kr', 'kr', 'rss', 'https://news.google.com/rss/search?q=%28%22%EC%98%A8%EB%9D%BC%EC%9D%B8%EC%97%90%EC%84%9C+%ED%99%94%EC%A0%9C%22+OR+%22SNS%EC%97%90%EC%84%9C+%ED%99%94%EC%A0%9C%22%29+%28%EA%B4%91%EA%B3%A0+OR+%EB%B8%8C%EB%9E%9C%EB%93%9C+OR+%EC%BD%9C%EB%9D%BC%EB%B3%B4+OR+%EC%8B%A0%EC%A0%9C%ED%92%88%29+when%3A7d&hl=ko&gl=KR&ceid=KR:ko' ),
	// heyPOP: /feed/는 2021년에 멈춘 기본 글 종류라 못 쓰고, 실제 기사는 'n' 글 종류
	'heyPOP'                => array( 'kr', 'kr', 'wpjson', 'https://heypop.kr/wp-json/wp/v2/n?per_page=20&_fields=date,link,title,excerpt' ),
	// 10/20 저녁에 뺀 곳(사용자: "정치·시사·사건사고, 대놓고 스포츠 뉴스, 너무 테크 기사는 필요 없어") — 그때 피드 실측:
	// Google 트렌드 8개 전부 뉴스 검색어(김정은 속보·역삼동 화재·태풍), Front Office Sports 8개 전부 스포츠 비즈니스 뉴스,
	// The Verge 8개·TechCrunch 4개 대부분 기기·투자·AI 산업 뉴스. 지난 리포트의 알약 색 때문에만 남긴다(읽는 법 '')
	'Front Office Sports'   => array( 'us', 'camp', '', '' ),
	'TechCrunch'            => array( 'us', 'plat', '', '' ),
	'The Verge'             => array( 'us', 'plat', '', '' ),
	'Google 트렌드'          => array( 'kr', 'trend', '', '' ),
	// 10/20 오후에 뺀 곳 — 지난 리포트의 알약 색 때문에만 남긴다(읽는 법 '').
	// 사용자 원칙: "작동 안 하면 빼 버려. 정상적인 컨디션에서 양질을 검토하는 게 목표".
	// WLDO·a:mag = 유튜브 RSS가 이 두 채널만 오후 내내 404(채널 id는 맞음, 다른 채널은 200).
	// 레딧 = 부를 때마다 막히는 게시판이 바뀌는 요청 제한(429). 다시 넣을 땐 reader(youtube·reddit)는 그대로 있다
	'WLDO'                  => array( 'kr', 'camp', '', '' ),
	'a:mag'                 => array( 'kr', 'camp', '', '' ),
);
// 성현 님이 원래 보던 곳 중 못 넣은 곳(10/20 확인): exchange4media(Cloudflare가 프로그램 접근 403), X·틱톡(RSS 없음, 로그인 필요)

// 정보원별로 거를 제목(대소문자 무시). Mashable은 하루 100개 중 경기 중계·퍼즐 정답·할인 글이 많다(10/20 실측)
// PHP와 Python(radar.py) 둘 다 이 문자열을 정규식으로 쓴다 → 두 쪽에 공통인 문법만
const TP_RADAR_SKIP = array(
	'Mashable' => 'how to watch|hints|answers|wordle|\bnyt\b|\bdeals?\b|% off|where to buy|how to buy|lowest price|price drop',
	// 고구마팜의 매일 뉴스 묶음 글 — 하나에 여러 소식이라 카드 거리 하나로 못 쓴다
	'고구마팜' => '뉴스클리핑',
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

// 정보원 나라 코드(kr·us·jp·gb), 표에 없으면 ''(10/20 사용자 아이디어: 소스의 나라 표기)
function tp_radar_country( $source ) {
	return TP_RADAR_SOURCES[ $source ][0] ?? '';
}

const TP_RADAR_COUNTRY_NAME = array( 'kr' => '한국', 'us' => '미국', 'jp' => '일본', 'gb' => '영국' );

/*
 * 나라 국기 — 10/20 사용자: 한 글자 대신 국기로.
 * 국기 이모지(🇰🇷)를 쓰지 않는 이유: Windows는 국기 이모지를 그림으로 그리지 못하고 "KR" 글자로 보여 준다(성현 님 PC가 Windows).
 * 그래서 그림 파일을 쓴다 — flag-icons(MIT 라이선스) 정사각형 판을 버전을 못 박아 jsdelivr에서 받고, 동그랗게 잘라 보여 준다.
 * 새 나라를 넣을 땐 TP_RADAR_COUNTRY_NAME에도 같이 적는다(마우스를 올리면 나라 이름이 뜬다).
 */
function tp_radar_flag( $source, $class = 'tprd-flag' ) {
	$c = tp_radar_country( $source );
	if ( '' === $c ) {
		return '';
	}
	$name = TP_RADAR_COUNTRY_NAME[ $c ] ?? strtoupper( $c );
	return '<img class="' . esc_attr( $class ) . '" src="https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/flags/1x1/' . esc_attr( $c ) . '.svg" alt="' . esc_attr( $name ) . '" title="' . esc_attr( $name ) . '" width="17" height="17" loading="lazy">';
}

// ── 실시간 피드: 정보원 새 글을 사이트가 직접 모은다(토큰 0) ──────────────
/*
 * 10/20 사용자 A안: 탭의 "📰 실시간 피드" 칸. Claude 없이 PHP가 RSS를 읽으니 몇 번을 열어도 비용이 없다.
 * 외국어는 원제목 그대로 — 모의 글로 만들 글만 체크해서 "모의 글 부탁"(아래)으로 특파원을 부른다.
 * 20곳 넘게 차례로 받으면 탭이 십몇 초 멈춰서 한꺼번에(병렬) 받고, 결과는 30분 보관한다.
 */
const TP_RADAR_FEED_TTL  = 30 * MINUTE_IN_SECONDS;
// 10/22 사용자: "새로고침해도 7일 안에 찾은 글은 남아 있게, 지난 글 칸 없이 피드에 그냥" → 피드 = 보관함의 최근 7일.
// 신문사 RSS는 최신 몇 십 개만 주기 때문에 새로고침 때 받은 글만 보여 주면 7일 안의 글도 밀려난다.
// 그래서 받을 때마다 보관함(옵션)에 합쳐 두고, 피드는 보관함에서 읽는다. 보관 기간 = 화면 기간이라 지난 글 칸이 필요 없다.
const TP_RADAR_FEED_DAYS = 7; // 이보다 오래된 글은 피드에 안 보이고 보관함에서도 지운다
// 정보원별 예외 — Link in Bio는 주 1~2편이라 7일 창에 0개일 때가 많다(10/20 사용자: "최근 2주")
const TP_RADAR_FEED_DAYS_BY = array( 'Link in Bio' => 14 );
const TP_RADAR_FEED_EACH = 20; // 정보원마다 최신 몇 개까지(받을 때·보여 줄 때 모두)
const TP_RADAR_IMG_DAYS  = 15; // 찾아 둔 대표 사진 보관 — 번역·접기 기록 보관(15일)과 같다
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
	// .gz 주소는 서버가 Content-Encoding으로 풀어 주기도, 압축 파일 그대로 주기도 한다 → 압축 표식(1f 8b)이 남아 있으면 직접 푼다
	if ( 'sitemap' === $kind && 0 === strpos( $body, "\x1f\x8b" ) ) {
		$body = (string) gzdecode( $body );
	}
	$prev = libxml_use_internal_errors( true );
	$x    = simplexml_load_string( $body );
	libxml_use_internal_errors( $prev );
	if ( ! $x ) {
		return $out;
	}
	if ( 'sitemap' === $kind ) {
		// 구글 뉴스 사이트맵: <url><loc> + <news:news> 안에 발행 시각·제목. 요약·사진은 없다
		foreach ( $x->children( 'http://www.sitemaps.org/schemas/sitemap/0.9' )->url as $u ) {
			$loc   = (string) $u->loc;
			$news  = $u->children( 'http://www.google.com/schemas/sitemap-news/0.9' )->news;
			$out[] = array( (string) $news->title, $loc, tp_radar_when( (string) $news->publication_date ), '', '' );
		}
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
 * $force = 🔄 새로고침 버튼(30분 보관본을 버리고 다시 받기). 받은 글은 보관함에 합쳐지고, 피드는 보관함의 최근 7일이다.
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
		$url = 'youtube' === $s[2] ? 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $s[3] : $s[3];
		$reqs[ $name ] = array(
			'url'     => $url,
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

	$store  = array();
	$errors = array();
	foreach ( $resps as $name => $r ) {
		if ( ! is_object( $r ) || ! isset( $r->status_code ) || $r->status_code >= 400 ) {
			$errors[ $name ] = is_object( $r ) && isset( $r->status_code ) ? 'HTTP ' . $r->status_code : '연결 실패';
			continue; // 못 읽은 곳의 옛 글은 보관함에 그대로 남아 피드에 계속 보인다
		}
		$skip = TP_RADAR_SKIP[ $name ] ?? '';
		$keep = time() - tp_radar_days( $name ) * DAY_IN_SECONDS;
		$got  = array();
		foreach ( tp_radar_parse( TP_RADAR_SOURCES[ $name ][2], $r->body ) as list( $orig, $link, $t, $text, $img ) ) {
			$orig = tp_radar_plain( $orig );
			if ( $t < $keep || '' === $link || ( $skip && preg_match( '~' . $skip . '~i', $orig ) ) ) {
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
		$store = array_merge( $store, array_slice( $got, 0, TP_RADAR_FEED_EACH ) );
	}
	$arc = tp_radar_archive_save( $store );

	// 화면 = 보관함 중 정보원당 최신 TP_RADAR_FEED_EACH개. 정보원 표에서 빠진 곳의 글은 보관함에 남아 있어도 보여 주지 않는다
	usort( $arc, fn( $a, $b ) => $b['t'] <=> $a['t'] );
	$items = array();
	$n     = array();
	foreach ( $arc as $it ) {
		$src = $it['source'];
		if ( ! isset( TP_RADAR_SOURCES[ $src ] ) || ( $n[ $src ] ?? 0 ) >= TP_RADAR_FEED_EACH ) {
			continue;
		}
		$n[ $src ] = ( $n[ $src ] ?? 0 ) + 1;
		$items[]   = $it;
	}
	$feed = array( 'at' => time(), 'items' => tp_radar_mix( $items ), 'errors' => $errors );
	set_transient( 'tp_radar_feed', $feed, TP_RADAR_FEED_TTL );
	return $feed;
}

/** 정보원의 화면·보관 기간(일). 정보원별 예외가 없으면 TP_RADAR_FEED_DAYS */
function tp_radar_days( $name ) {
	return TP_RADAR_FEED_DAYS_BY[ $name ] ?? TP_RADAR_FEED_DAYS;
}

/** 보관해 둔 글: 링크 => 피드 글 하나(source·orig·link·t·date·text·image) */
function tp_radar_archive() {
	$a = get_option( 'tp_radar_archive', array() );
	return is_array( $a ) ? $a : array();
}

/**
 * 이번에 받은 글을 보관함에 합치고 기간(정보원별 tp_radar_days)이 지난 글을 지운다. 합치기만 하니까 읽지 못한 정보원의 옛 글은 그대로 남는다.
 * 같은 글이 다시 오면 새로 받은 쪽으로 덮어 사진 주소 같은 게 최신이 되게 한다. 합친 결과를 돌려준다(링크 => 글).
 */
function tp_radar_archive_save( $items ) {
	$arc = tp_radar_archive();
	foreach ( $items as $it ) {
		$arc[ $it['link'] ] = $it;
	}
	$arc = array_filter( $arc, fn( $it ) => ( $it['t'] ?? 0 ) > time() - tp_radar_days( $it['source'] ?? '' ) * DAY_IN_SECONDS );
	update_option( 'tp_radar_archive', $arc, false );
	return $arc;
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

/** 이미 찾아 둔 대표 사진(TP_RADAR_IMG_DAYS일 보관). 없으면 false, 찾았는데 사진이 없는 글이면 '' */
function tp_radar_img_cached( $link ) {
	// 키의 2 = 400px 판(옛 192px 보관본은 버린다)
	return get_transient( 'tp_radar_img2_' . md5( $link ) );
}

/*
 * 피드 사진 B안(10/20 사용자): RSS에 사진이 없는 글(15곳 중 9곳)은 그 줄이 화면에 보일 때만 탭이 이 창구를 부른다.
 * 서버가 기사 페이지를 열어 대표 사진(og:image)을 찾고 15일 보관 — 스크롤한 만큼만 비용이 들고, 한 번 찾은 글은 다시 안 연다.
 * 피드·보관에 있는 글 주소만 받는다(아무 주소나 서버가 열지 않게).
 */
add_action(
	'wp_ajax_tp_radar_img',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'tp_radar_img' );
		$feed  = get_transient( 'tp_radar_feed' );
		$known = array_merge( is_array( $feed ) ? wp_list_pluck( $feed['items'], 'link' ) : array(), array_keys( tp_radar_archive() ) ); // 보관함도
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
			set_transient( 'tp_radar_img2_' . md5( $l ), $img, TP_RADAR_IMG_DAYS * DAY_IN_SECONDS );
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
 * routine이 깨어날 때마다(아래 "번역·거르기 부탁") 아직 안 훑은 글만 본다.
 * tp_radar_titles: 링크 => array( 한국어 제목(한국어 글은 ''), 저장 시각, 접기 true|false, 새 규칙으로 훑음 true, 사람 냄새 true|false )  — 가장 긴 피드 창(Link in Bio 14일)보다 넉넉히 15일 보관
 *   접기 칸이 없는 것 = 번역만 하고 아직 안 훑은 글(10/20 저녁 전 번역분)
 *   다섯째 칸(10/22 사용자: "사람 냄새 필터") 자체가 없는 것 = 사람 냄새를 아직 안 본 글. true/false로 "봤다"를 남겨야 같은 글을 다시 안 보낸다
 */
function tp_radar_titles() {
	$t = get_option( 'tp_radar_titles', array() );
	return is_array( $t ) ? $t : array();
}

/*
 * 피드에서 특파원이 아직 안 훑은 글(번역할 외국어 글 + 접을지 볼 한국어 글).
 * 10/21 사용자: 접힌 글도 번역해 달라 — 전엔 토큰을 아끼려고 접은 외국어 글은 번역하지 않았다.
 * 그때 접혀 번역 없이 남은 글도 한 번만 다시 보낸다. 새 규칙으로 훑은 글은 넷째 칸(true)이 있어서,
 * 특파원이 번역을 빠뜨려도 다시 안 보낸다 — 안 그러면 그 글 때문에 자동 부탁이 30분마다 되풀이된다.
 */
function tp_radar_unjudged( $feed ) {
	$tr = tp_radar_titles();
	return array_values(
		array_filter(
			$feed['items'],
			function ( $it ) use ( $tr ) {
				$t = $tr[ $it['link'] ] ?? null;
				return ! isset( $t[2] ) || ! isset( $t[4] ) || ( $t[2] && '' === $t[0] && empty( $t[3] ) && '' !== tp_radar_lang( $it['orig'] ) );
			}
		)
	);
}

/** 특파원이 "사람 냄새"로 표시한 글인가 */
function tp_radar_warm( $tr, $link ) {
	return ! empty( $tr[ $link ][4] );
}

/** 특파원이 접어 둔 글인가 */
function tp_radar_folded( $tr, $link ) {
	return ! empty( $tr[ $link ][2] );
}

/** 번역·거르기 부탁 한 번 — 버튼과 자동 부탁이 같이 쓴다. 성공하면 true, 실패하면 이유 문장 */
function tp_radar_ask_titles() {
	$rang = tp_radar_ring( 'titles' );
	if ( true === $rang ) {
		update_option( 'tp_radar_tr_at', time(), false );
	}
	return $rang;
}

/** 번역을 부탁했는데 아직 결과가 안 온 채 20분이 안 지났으면 '훑는 중' */
function tp_radar_titles_busy() {
	$asked = (int) get_option( 'tp_radar_tr_at', 0 );
	return $asked > (int) get_option( 'tp_radar_titles_at', 0 ) && time() - $asked < 20 * MINUTE_IN_SECONDS;
}

/*
 * 자동 부탁 — 10/20 사용자: 피드 탭을 열면 번역·거르기를 알아서 해 달라(전엔 버튼을 눌러야 했다).
 * routine은 한 번 깨울 때마다 고정 비용이 있어서(1~2분·토큰) 열 때마다 부르면 낭비다 → 세 가지로 막는다:
 *   ① 안 훑은 글이 TP_RADAR_AUTO_MIN개 이상일 때만(한두 개 때문에 부르지 않는다. 모자란 글은 다음 기회에 묶인다)
 *   ② 마지막 부탁(자동·버튼 모두)에서 TP_RADAR_AUTO_GAP이 지났을 때만 — 피드 보관(30분)과 같은 간격
 *   ③ 훑는 중이면 안 부른다
 * 실패해도 시각을 먼저 적어서, 열쇠가 틀린 채로 탭을 열 때마다 초인종을 두드리지 않는다(실패 이유는 탭에 한 번 보여 주고, 버튼은 그대로 쓸 수 있다).
 * 초인종이 최대 20초 걸려서 페이지 안에서 부르지 않고, 탭이 열린 뒤 이 창구를 부른다. 검사는 여기서 다시 한다 — 탭의 판단을 믿지 않는다.
 */
const TP_RADAR_AUTO_MIN = 5;
const TP_RADAR_AUTO_GAP = 30 * MINUTE_IN_SECONDS;

/** 지금 자동으로 부탁해도 되는 상태인가(안 훑은 글 수는 $todo_n으로 받는다) */
function tp_radar_auto_due( $todo_n ) {
	$last = max( (int) get_option( 'tp_radar_auto_at', 0 ), (int) get_option( 'tp_radar_tr_at', 0 ) );
	return $todo_n >= TP_RADAR_AUTO_MIN && ! tp_radar_titles_busy() && time() - $last >= TP_RADAR_AUTO_GAP;
}

add_action(
	'wp_ajax_tp_radar_auto',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'tp_radar_auto' );
		$feed = get_transient( 'tp_radar_feed' );
		if ( ! is_array( $feed ) || ! tp_radar_auto_due( count( tp_radar_unjudged( $feed ) ) ) ) {
			wp_send_json_success( array( 'rang' => false, 'msg' => '' ) );
		}
		update_option( 'tp_radar_auto_at', time(), false );
		$rang = tp_radar_ask_titles();
		wp_send_json_success( array( 'rang' => true === $rang, 'msg' => true === $rang ? '' : '자동 번역·거르기를 부탁하지 못했어요 — ' . $rang ) );
	}
);

add_action(
	'admin_post_tp_radar_tr',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 부탁할 수 있어요' );
		}
		check_admin_referer( 'tp_radar_tr' );
		$rang = tp_radar_ask_titles();
		$msg = true === $rang ? '특파원에게 번역·거르기를 부탁했어요. 몇 분 뒤 저절로 바뀌어요' : $rang;
		wp_safe_redirect( add_query_arg( array( 'view' => 'feed', 'tprd_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) ) );
		exit;
	}
);

// ── 모의 글 부탁: 피드에서 고른 글을 특파원이 카드뉴스 형식의 비공개 임시글로 ─────────────────
/*
 * 10/20 사용자: 피드에서 여러 개 체크 → 한 번에 특파원 호출. routine은 한 번 깨울 때마다 고정 비용이 커서 묶어서 부른다.
 * 10/28 사용자: 글자 요약 리포트 대신 카드뉴스 형식의 임시글(모의 글)로 — 검토하고 마음에 들면 바로 발행. 글은 Opus 전용 routine이 만든다.
 * 결과는 탭의 모의 글 칸에 링크로 쌓인다(부탁한 사람이 탭을 보고 있으니 따로 알리지 않는다).
 * 초인종은 셰프와 같은 방식: routine의 API 트리거 주소로 POST 한 번. 특파원은 신호에 딸린 글을 따르지 않고,
 * REST /asks에서 '기다리는 중' 부탁만 가져간다(tools/radar/PROMPT-mock.md) — 열쇠가 새도 할 수 있는 건 "특파원 깨우기"뿐.
 *
 * tp_radar_asks: id => { at, by, status(waiting·cooking·done·failed), items, rang, took, result, err }  최근 TP_RADAR_ASKS_KEEP개
 * 한 번에 3개까지: Opus는 글 하나를 만드는 데 사진 받기·초안·화면 확인까지 해서 길고 비싸다(10/28 사용자 추천안)
 */
const TP_RADAR_ASK_MAX        = 3; // 한 번에 부탁할 수 있는 글 수 — 한 번 실행에서 만드는 글의 상한
const TP_RADAR_ASKS_KEEP      = 40;
const TP_RADAR_ASK_STALE      = 25 * MINUTE_IN_SECONDS; // 이만큼 지나도 안 끝나면(Opus가 글 3개를 만들면 길다) "다시 부르기"를 보여 준다

function tp_radar_asks() {
	$a = get_option( 'tp_radar_asks', array() );
	return is_array( $a ) ? $a : array();
}

function tp_radar_save_asks( $asks ) {
	krsort( $asks );
	update_option( 'tp_radar_asks', array_slice( $asks, 0, TP_RADAR_ASKS_KEEP, true ), false );
}

/*
 * 2.0.0으로 올릴 때 한 번: 글자 요약 리포트(옛 정리 부탁 결과)와 1.6.0 때 옮겨 둔 아침 리포트를 지운다.
 * 10/28 사용자: 지난 리포트는 지우기 — 새 화면에는 글자 리포트를 그리는 코드가 없고, 옛 모양을 새 REST가 받으면 섞이기 때문.
 * 옵션 하나(tp_radar_mock_v)로 한 번만 돌게 한다.
 */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'tp_radar_mock_v' ) ) {
			return;
		}
		delete_option( 'tp_radar_asks' );
		update_option( 'tp_radar_mock_v', 1, false );
	}
);

/**
 * 초인종: routine을 지금 깨운다. $note는 실행 기록에서 알아보는 표시일 뿐. 성공하면 true, 실패하면 이유 문장.
 * $which: 'main' = 번역·거르기 routine(Sonnet), 'mock' = 모의 글 routine(Opus). 열쇠가 서로 달라서 옵션을 나눴다
 */
function tp_radar_ring( $note, $which = 'main' ) {
	$bell = get_option( 'mock' === $which ? 'tp_radar_bell_mock' : 'tp_radar_bell', array() );
	if ( empty( $bell['url'] ) || empty( $bell['token'] ) ) {
		return 'mock' === $which
			? '모의 글 초인종이 아직 설정되지 않았어요 (피드 칸 맨 아래 "모의 글 초인종 설정")'
			: '초인종이 아직 설정되지 않았어요 (피드 칸 맨 아래 "특파원 초인종 설정")';
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
			// routine은 이 글을 따르지 않는다. 실행 기록에서 어느 부탁 때 깨웠는지 알아보는 표시일 뿐
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

/** 부탁 하나를 '기다리는 중'으로 두고 초인종 — 새 부탁과 "다시 부르기"가 같이 쓴다. 모의 글 routine을 부른다 */
function tp_radar_ring_ask( &$asks, $id ) {
	$asks[ $id ]['status'] = 'waiting';
	$asks[ $id ]['rang']   = time();
	$rang                  = tp_radar_ring( "ask {$id}", 'mock' );
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
		// 글 정보는 폼이 아니라 사이트가 받아 둔 피드·보관함에서 찾는다 — 폼으로 엉뚱한 주소를 넣어도 특파원이 읽지 않게
		$pool = tp_radar_archive();
		foreach ( tp_radar_feed()['items'] as $it ) {
			$pool[ $it['link'] ] = $it;
		}
		$pick = array();
		foreach ( array_unique( $want ) as $l ) {
			if ( isset( $pool[ $l ] ) ) {
				$it = $pool[ $l ];
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

/**
 * 모의 글 부탁 하나 지우기(10/28 사용자: 주문내역 삭제와 임시글 삭제를 묶어서).
 * 만든 글은 비공개 초안(draft)일 때만 휴지통으로 보내고, 이미 발행했거나 예약한 글은 건드리지 않는다 — 사람이 검토해 발행한 글이 여기서 지워지면 안 된다.
 * 사진은 셰프 코드가 올릴 때 이름 앞에 "<부탁id>-<번호>-"를 붙이므로(cardnews.py upload) 그 이름으로 찾아 완전 삭제한다.
 * 발행된 글이 남는 기사는 그 글이 사진을 쓰고 있어서 사진도 남긴다.
 * 돌려주는 값: drafts(휴지통으로 보낸 글), kept(발행돼서 남긴 글), media(지운 사진). $asks에서 그 부탁 기록도 뺀다
 */
function tp_radar_delete_ask( &$asks, $id ) {
	$out = array( 'drafts' => 0, 'kept' => 0, 'media' => 0 );
	foreach ( array_values( (array) ( $asks[ $id ]['result'] ?? array() ) ) as $n => $it ) {
		$keep = false;
		$pid  = (int) ( $it['post_id'] ?? 0 );
		$post = $pid ? get_post( $pid ) : null;
		if ( $post && 'post' === $post->post_type ) {
			if ( 'draft' === $post->post_status ) {
				wp_trash_post( $pid );
				++$out['drafts'];
			} elseif ( 'trash' !== $post->post_status ) {
				$keep = true;
				++$out['kept'];
			}
		}
		if ( $keep ) {
			continue;
		}
		$files = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_wp_attached_file', 'value' => '/' . (int) $id . '-' . ( $n + 1 ) . '-', 'compare' => 'LIKE' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $files as $aid ) {
			if ( wp_delete_attachment( $aid, true ) ) {
				++$out['media'];
			}
		}
	}
	unset( $asks[ $id ] );
	return $out;
}

/** 지울 수 있는 부탁인가: 끝났거나 실패했거나, 너무 오래 안 끝난 것(만드는 중인 부탁을 지우면 곧 올라올 글이 주인 없이 남는다) */
function tp_radar_ask_deletable( $a ) {
	$st = $a['status'] ?? 'waiting';
	return in_array( $st, array( 'done', 'failed' ), true ) || ! empty( $a['err'] ) || time() - (int) ( $a['rang'] ?? $a['at'] ?? 0 ) > TP_RADAR_ASK_STALE;
}

add_action(
	'admin_post_tp_radar_delete',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 지울 수 있어요' );
		}
		check_admin_referer( 'tp_radar_delete' );
		$id   = (int) ( $_POST['id'] ?? 0 );
		$asks = tp_radar_asks();
		$back = admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report' );
		// 탭의 판단을 믿지 않고 여기서 다시 검사한다
		if ( ! isset( $asks[ $id ] ) || ! tp_radar_ask_deletable( $asks[ $id ] ) ) {
			wp_safe_redirect( add_query_arg( 'tprd_msg', rawurlencode( '지울 수 없는 부탁이에요 (아직 만드는 중이거나 이미 지웠어요)' ), $back ) );
			exit;
		}
		$res = tp_radar_delete_ask( $asks, $id );
		tp_radar_save_asks( $asks );
		$msg = sprintf( '지웠어요: 임시글 %d개(휴지통), 사진 %d장', $res['drafts'], $res['media'] );
		if ( $res['kept'] ) {
			$msg .= sprintf(' · 이미 발행한 글 %d개는 그대로 뒀어요', $res['kept'] );
		}
		wp_safe_redirect( add_query_arg( 'tprd_msg', rawurlencode( $msg ), $back ) );
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

// 초인종 설정 저장(둘 다 같은 창구, which로 구분). 열쇠 칸을 비워 두면 기존 열쇠를 그대로 둔다(화면에 열쇠를 다시 보여 주지 않아서)
add_action(
	'admin_post_tp_radar_bell',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 바꿀 수 있어요' );
		}
		check_admin_referer( 'tp_radar_bell' );
		$mock  = 'mock' === ( $_POST['which'] ?? '' );
		$opt   = $mock ? 'tp_radar_bell_mock' : 'tp_radar_bell';
		$bell  = get_option( $opt, array() );
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
		update_option( $opt, $bell, false );
		wp_safe_redirect( add_query_arg( 'tprd_msg', rawurlencode( $mock ? '모의 글 초인종을 저장했어요. 이제 모의 글을 부탁하면 특파원이 바로 와요' : '초인종을 저장했어요. 이제 번역·거르기를 부탁하면 특파원이 바로 와요' ), $back ) );
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
						$warm = array_flip( array_map( 'esc_url_raw', (array) $req->get_param( 'warm' ) ) );
						$n    = 0;
						$w    = 0;
						// judged = 이번에 훑은 글 전부(접지 않은 글도 "훑었다"로 남겨야 다음에 다시 안 읽는다)
						foreach ( array_map( 'esc_url_raw', (array) $req->get_param( 'judged' ) ) as $link ) {
							$title         = '' !== ( $ko[ $link ] ?? '' ) ? $ko[ $link ] : ( $tr[ $link ][0] ?? '' );
							// 사람 냄새로 표시한 글은 접지 않는다 — 특파원이 둘 다 달아 와도 표시가 이긴다
							$is_warm       = isset( $warm[ $link ] );
							$is_fold       = isset( $fold[ $link ] ) && ! $is_warm;
							$tr[ $link ]   = array( $title, time(), $is_fold, true, $is_warm ); // 넷째 칸: 접은 글도 번역하는 규칙으로 훑음, 다섯째 칸: 사람 냄새를 봄
							$n            += $is_fold ? 1 : 0;
							$w            += $is_warm ? 1 : 0;
						}
						update_option( 'tp_radar_titles', $tr, false );
						update_option( 'tp_radar_titles_at', time(), false );
						return array( 'saved' => count( (array) $req->get_param( 'judged' ) ), 'folded' => $n, 'warm' => $w );
					},
				),
			)
		);

		// 모의 글 부탁 가져가기: '기다리는 중'인 부탁을 '만드는 중'으로 바꾸며 돌려준다(두 번 깨어나도 한 번만 정리하게)
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

		// 모의 글 결과 받기(items) 또는 실패 알리기(error)
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
						$items = array_values( array_map( 'tp_radar_clean_draft', (array) $req->get_param( 'items' ) ) );
						if ( ! $items ) {
							return new WP_Error( 'tp_radar_empty', '만든 글이 없어요', array( 'status' => 400 ) );
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

/** 모의 글 한 줄 — 만든 임시글의 제목·미리보기·편집 링크, 발행 전에 볼 것(셰프와 같은 '꼭 확인'·'확인 필요' 표시) */
function tp_radar_draft_row( $it ) {
	$must  = array();
	$check = array();
	$rest  = array();
	foreach ( $it['changes'] as $c ) {
		if ( 0 === strpos( $c, '꼭 확인:' ) ) {
			$must[] = $c;
		} elseif ( 0 === strpos( $c, '확인 필요:' ) ) {
			$check[] = $c;
		} else {
			$rest[] = $c;
		}
	}
	?>
<li class="tprd-draft">
<div class="tprd-dhead"><?php tp_radar_chip( $it['source'], $it['date'] ); ?>
	<?php if ( $it['error'] ) : ?>
<b><?php echo esc_html( $it['orig'] ); ?></b> <span class="tprd-state bad">못 만들었어요 — <?php echo esc_html( $it['error'] ); ?></span>
	<?php else : ?>
<b><?php echo esc_html( $it['title'] ); ?></b> <span class="tprd-state">임시글(비공개)</span>
	<?php endif; ?>
</div>
	<?php if ( ! $it['error'] ) : ?>
<p class="tprd-dlinks">
		<?php if ( $it['preview'] ) : ?>
<a class="tprd-btn" href="<?php echo esc_url( $it['preview'] ); ?>" target="_blank" rel="noopener">👀 미리보기</a>
		<?php endif; ?>
		<?php if ( $it['edit'] ) : ?>
<a class="tprd-btn ghost" href="<?php echo esc_url( $it['edit'] ); ?>" target="_blank" rel="noopener">✏️ 편집기에서 열기·발행</a>
		<?php endif; ?>
<a class="tprd-orig" href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener">원문: <?php echo esc_html( $it['orig'] ); ?></a>
</p>
		<?php if ( $must || $check ) : ?>
<ul class="tprd-todo">
			<?php foreach ( $must as $c ) : ?>
<li class="must">❗ <?php echo esc_html( $c ); ?></li>
			<?php endforeach; ?>
			<?php foreach ( $check as $c ) : ?>
<li class="check">🟡 <?php echo esc_html( $c ); ?></li>
			<?php endforeach; ?>
</ul>
		<?php endif; ?>
		<?php if ( $rest ) : ?>
<details class="tprd-rest"><summary>특파원이 손본 것 <?php echo count( $rest ); ?>건</summary><ul>
			<?php foreach ( $rest as $c ) : ?>
<li><?php echo esc_html( $c ); ?></li>
			<?php endforeach; ?>
</ul></details>
		<?php endif; ?>
	<?php endif; ?>
</li>
	<?php
}

/** 사진 위 자막 띠: 갈래 색 바탕에 흰 글씨 "나라 · 정보원"(10/20 사용자: 지금 분류하는 색으로) */
function tp_radar_where( $source ) {
	$fg = tp_radar_chip_color( $source )[2]; // 갈래 색 중 가장 진한 것 — 흰 글씨가 읽히게
	return '<span class="tprd-where" style="background:' . esc_attr( $fg ) . '">' . tp_radar_flag( $source, 'tprd-flag tprd-flag-s' ) . esc_html( $source ) . '</span>';
}

/** 정보원 알약: 나라 동그라미 + 이름 · 날짜(월-일) */
function tp_radar_chip( $source, $date ) {
	list( $bg, $line, $fg ) = tp_radar_chip_color( $source );
	echo '<span class="tprd-chip" style="background:' . esc_attr( $bg ) . ';border-color:' . esc_attr( $line ) . ';color:' . esc_attr( $fg ) . '">'
		. tp_radar_flag( $source )
		. esc_html( $source . ' · ' . substr( $date, 5 ) ) . '</span>';
}

/** "3시간 전"처럼 — 피드에서 얼마나 따끈한지 */
function tp_radar_ago( $t ) {
	$m = max( 0, (int) ( ( time() - $t ) / 60 ) );
	return $m < 60 ? $m . '분 전' : ( $m < 1440 ? (int) ( $m / 60 ) . '시간 전' : (int) ( $m / 1440 ) . '일 전' );
}

function tp_radar_page() {
	// 첫 화면은 피드(10/20 사용자: 피드를 둘러보고 골라서 글로 — 들어오면 바로 고를 수 있게)
	$view = 'report' === ( $_GET['view'] ?? '' ) ? 'report' : 'feed';
	$asks = tp_radar_asks();
	// 모의 글 칸은 처음엔 부탁 6번만. 더 보기를 누를 때마다 6번씩
	$show = max( 6, (int) ( $_GET['show'] ?? 6 ) );
	$msg  = sanitize_text_field( wp_unslash( $_GET['tprd_msg'] ?? '' ) );
	// 피드는 피드 칸을 열 때만 받는다(모의 글 칸만 볼 때 여러 곳을 부르지 않게). 보관본이 있으면 개수만 보여 준다
	$feed = 'feed' === $view ? tp_radar_feed() : get_transient( 'tp_radar_feed' );
	?>
<div class="tprd">
<style>
/* 10/20 사용자 결정: "스티커 주방" 대신 "현장 연결"(시안 3) — 트란시스가 현장 기자로 나오는 뉴스 화면.
   LIVE 표시 · 흐르는 자막 · 사진 아래 자막 띠(갈래 색)에 나라와 정보원. 귀여운 쪽(Jua·둥근 모서리)은 그대로.
   10/21 사용자: 이름은 "현장 연결" 말고 "취재 일지"로 — 이름·말투만 바꾸고 화면 꾸밈은 그대로 둠 */
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
/* 날짜·모의 글 부탁 머리 = 방송 코너 제목 */
.tprd-day { margin: 0 0 34px; scroll-margin-top: 50px; }
.tprd-day h2 { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 14px; font: 400 21px/1.2 "Jua", sans-serif; color: var(--ink); }
.tprd-corner { padding: 3px 10px; border-radius: 6px; background: var(--live); color: #fff; font: 400 14px/1.3 "Do Hyeon", sans-serif; }
.tprd-corner.ask { background: #1f5fc4; }
.tprd-state { padding: 2px 10px; border-radius: 999px; background: #d9f1e3; color: #13784f; font: 700 12px/1.5 "IBM Plex Sans KR", sans-serif; }
.tprd-state.bad { background: #fde3da; color: #b42333; }
.tprd-state.wait { background: #fff1c2; color: #8a6400; }
.tprd-wait { margin: 0; padding: 12px 16px 12px 32px; border-radius: 12px; background: var(--card); box-shadow: 0 2px 10px rgba(59,47,42,.08); }
.tprd-wait li { margin: 3px 0; }
.tprd-recall { display: inline; margin-left: 4px; }
.tprd-recall.tprd-del button { background: #fde3da; box-shadow: 0 2px 0 #e9b3a3; color: #8f1d2c; }
.tprd-recall button, .tprd-btn { padding: 6px 14px; border: 0; border-radius: 999px; background: #ffe45c; color: var(--ink); font: 700 13px "IBM Plex Sans KR", sans-serif; cursor: pointer; box-shadow: 0 2px 0 #d9b800; }
.tprd-btn:disabled { background: var(--soft); color: var(--muted); box-shadow: none; cursor: default; }
/* 사진 위 자막 띠: 갈래 색 바탕 + 흰 글씨(나라 · 정보원), 그 아래 제목 */
.tprd-screen { position: relative; display: block; overflow: hidden; background: #2b2320; }
.tprd-screen img.ph { display: block; width: 100%; height: 100%; object-fit: cover; }
.tprd-cap { position: absolute; left: 0; right: 0; bottom: 0; padding: 26px 11px 9px; background: linear-gradient(transparent, rgba(0,0,0,.72)); color: #fff; }
.tprd-where { display: inline-block; margin-bottom: 4px; padding: 1px 8px; border-radius: 4px; color: #fff; font: 400 13px/1.45 "Do Hyeon", sans-serif; }
.tprd-cap .tt { display: block; font: 400 17px/1.3 "Do Hyeon", sans-serif; color: #fff; text-decoration: none; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.tprd-cap a.tt:hover { text-decoration: underline; }
/* 모의 글 줄: 임시글 제목 · 미리보기·편집 버튼 · 발행 전에 볼 것 */
.tprd-chip { display: inline-block; padding: 2px 10px; border-radius: 999px; border: 1.5px solid; font-size: 11.5px; font-weight: 700; }
.tprd-flag { display: inline-block; width: 17px; height: 17px; margin: 0 5px 0 -5px; border-radius: 50%; box-shadow: 0 0 0 1px rgba(0,0,0,.18); vertical-align: -3px; object-fit: cover; }
.tprd-flag-s { width: 14px; height: 14px; margin: 0 5px 0 -3px; vertical-align: -2px; box-shadow: 0 0 0 1px rgba(255,255,255,.55); }
.tprd-tr { display: inline-block; padding: 0 7px; border-radius: 6px; background: #ebe5ff; color: #5b3fd0; font-size: 11px; font-weight: 700; }
.tprd-tr.wait { background: var(--soft); color: var(--muted); }
.tprd-drafts { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
.tprd-draft { padding: 14px 16px; border-radius: 14px; background: var(--card); box-shadow: 0 3px 12px rgba(59,47,42,.10); }
.tprd-dhead { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 16px; }
.tprd-dlinks { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 10px 0 0; }
.tprd-dlinks .tprd-btn { text-decoration: none; display: inline-block; }
.tprd-btn.ghost { background: var(--soft); box-shadow: none; }
.tprd-dlinks .tprd-orig { font-size: 12px; color: var(--muted); }
.tprd-todo { list-style: none; margin: 10px 0 0; padding: 0; }
.tprd-todo li { margin: 4px 0 0; padding: 5px 10px; border-radius: 8px; font-size: 13px; }
.tprd-todo .must { background: #ffe0e6; border-left: 4px solid #d6335b; }
.tprd-todo .check { background: #fff7d1; border-left: 4px solid #e0b100; }
.tprd-rest { margin: 10px 0 0; font-size: 12.5px; color: var(--muted); }
.tprd-rest summary { cursor: pointer; }
.tprd-rest ul { margin: 6px 0 0; padding-left: 18px; }
.tprd-more { display: inline-block; padding: 8px 18px; border-radius: 999px; background: var(--ink); color: #fff; font-weight: 700; text-decoration: none; }
/* 실시간 피드: 거르기 → 카드 격자(체크) → 아래에 붙는 모의 글 부탁 막대 */
.tprd-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 14px; }
.tprd-filters button { padding: 4px 12px; border: 2px solid var(--ink); border-radius: 999px; background: var(--card); color: var(--ink); font: 700 12.5px "IBM Plex Sans KR", sans-serif; cursor: pointer; }
.tprd-filters button[aria-pressed="true"] { background: var(--ink); color: #fff; }
.tprd-warm { display: inline-block; padding: 1px 8px; border-radius: 999px; background: #ffe0e6; color: #b3123b; font-weight: 700; }
.tprd-filters .tprd-refresh { margin-left: auto; font-size: 12px; color: var(--muted); }
.tprd-filters .tprd-refresh button { background: #ffe45c; border-color: #ffe45c; }
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
/* 체크박스는 카드 오른쪽 아래 — 글이 밑으로 들어가지 않게 .bd 오른쪽을 비워 둔다 */
.tprd-feed input { position: absolute; right: 11px; bottom: 11px; z-index: 2; width: 20px; height: 20px; margin: 0; accent-color: var(--live); }
.tprd-feed .bd { display: grid; gap: 4px; padding: 8px 40px 11px 11px; }
.tprd-feed .meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
.tprd-feed .sub { font-size: 12px; color: var(--muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.tprd-feed .st { padding: 0 8px; border-radius: 999px; background: var(--soft); color: var(--ink); font-weight: 700; font-size: 11px; text-decoration: none; }
.tprd-bar { position: sticky; bottom: 12px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 16px 0 0; padding: 12px 16px; border-radius: 14px; background: var(--ink); color: #fff; box-shadow: 0 8px 24px rgba(59,47,42,.25); }
.tprd-bar span { color: #e9e2d8; font-size: 13px; }
/* 폰에서는 막대가 카드를 너무 가려서 한 줄로 줄인다 — 버튼 옆에 안내를 붙이고 길면 두 줄까지만 */
@media (max-width: 600px) {
	.tprd-bar { flex-wrap: nowrap; gap: 8px; bottom: 8px; padding: 7px 10px; border-radius: 12px; }
	.tprd-bar .tprd-btn { flex: none; padding: 4px 10px; font-size: 12px; }
	.tprd-bar span { flex: 1; min-width: 0; font-size: 11px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
}
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
<span class="tprd-live">📓 취재 일지</span>
<img src="<?php echo esc_url( plugins_url( 'press.png', __FILE__ ) ); ?>" alt="트란시스 특파원">
<div><h1>트란시스 특파원 취재 일지</h1><p>정보원 새 글을 둘러보고, 마음에 드는 글을 체크하면 특파원이 카드뉴스 형식의 임시글로 만들어 와요</p></div>
</div>
	<?php
	// 흐르는 자막: 받아 둔 피드의 최신 제목 8개(번역이 있으면 한국어). 피드를 아직 안 받았으면 없음
	$trs  = tp_radar_titles();
	$tick = is_array( $feed ) ? array_slice( array_values( array_filter( $feed['items'], fn( $it ) => ! tp_radar_folded( $trs, $it['link'] ) ) ), 0, 8 ) : array();
	?>
	<?php if ( $tick ) : ?>
<div class="tprd-ticker" aria-label="최신 소식"><div>
		<?php foreach ( array_merge( $tick, $tick ) as $it ) : // 두 번 이어 붙여야 끊김 없이 돈다 ?>
<span><b><?php echo tp_radar_flag( $it['source'], 'tprd-flag tprd-flag-s' ) . esc_html( $it['source'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- 함수가 이미 이스케이프함 ?></b><?php echo esc_html( $trs[ $it['link'] ][0] ?? $it['orig'] ); ?></span>
		<?php endforeach; ?>
</div></div>
	<?php endif; ?>
<nav class="tprd-nav">
<a class="tprd-tab<?php echo 'feed' === $view ? ' on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG ) ); ?>">📰 실시간 피드<?php echo is_array( $feed ) ? ' <small>' . count( $feed['items'] ) . '</small>' : ''; ?></a>
<a class="tprd-tab<?php echo 'report' === $view ? ' on' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report' ) ); ?>">🃏 모의 글<?php echo $asks ? ' <small>' . count( $asks ) . '</small>' : ''; ?></a>
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

/** 🃏 모의 글 칸: 모의 글 부탁과 그 결과(임시글 링크), 최근 것부터 */
function tp_radar_report_view( $asks, $show ) {
	$pending = false;
	if ( ! $asks ) :
		?>
<p style="text-align:center">아직 모의 글이 없어요. 📰 실시간 피드에서 마음에 드는 글을 체크하고 🃏 모의 글 부탁을 눌러 보세요.</p>
		<?php
	endif;
	foreach ( array_slice( $asks, 0, $show, true ) as $id => $a ) :
		$st       = $a['status'] ?? 'waiting';
		$pending  = $pending || in_array( $st, array( 'waiting', 'cooking' ), true );
		$late     = in_array( $st, array( 'waiting', 'cooking' ), true ) && time() - (int) ( $a['rang'] ?? $a['at'] ) > TP_RADAR_ASK_STALE;
		$says     = array(
			'waiting' => '특파원 부르는 중',
			'cooking' => '글 만드는 중',
			'done'    => '임시글 완성',
			'failed'  => '만들기 실패',
		)[ $st ] ?? $st;
		$cls      = 'done' === $st ? '' : ( 'failed' === $st || ! empty( $a['err'] ) ? ' bad' : ' wait' );
		?>
<section class="tprd-day" id="ask-<?php echo (int) $id; ?>">
<h2><span class="tprd-corner ask">🃏 모의 글 부탁</span><?php echo esc_html( tp_radar_weekday( wp_date( 'Y-m-d', (int) $a['at'] ) ) . ' ' . wp_date( 'H:i', (int) $a['at'] ) ); ?> · <?php echo count( (array) $a['items'] ); ?>개
<span class="tprd-state<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $says . ( ! empty( $a['err'] ) ? ' — ' . $a['err'] : '' ) ); ?></span>
		<?php if ( $late || ! empty( $a['err'] ) ) : ?>
<form class="tprd-recall" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_recall' ); ?><input type="hidden" name="action" value="tp_radar_recall"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"><button type="submit">🔔 다시 부르기</button></form>
		<?php endif; ?>
		<?php if ( tp_radar_ask_deletable( $a ) ) : ?>
<form class="tprd-recall tprd-del" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( '이 부탁의 기록과, 특파원이 만든 임시글·그 글에 올린 사진을 지워요. 이미 발행한 글은 안 지워요. 되돌릴 수 없어요. 지울까요?' ) ); ?>);"><?php wp_nonce_field( 'tp_radar_delete' ); ?><input type="hidden" name="action" value="tp_radar_delete"><input type="hidden" name="id" value="<?php echo (int) $id; ?>"><button type="submit">🗑 지우기 (기록·임시글)</button></form>
		<?php endif; ?>
</h2>
		<?php if ( 'done' === $st ) : ?>
<ul class="tprd-drafts">
			<?php foreach ( array_values( (array) $a['result'] ) as $it ) : ?>
				<?php tp_radar_draft_row( $it ); ?>
			<?php endforeach; ?>
</ul>
		<?php else : ?>
<ul class="tprd-wait">
			<?php foreach ( (array) $a['items'] as $it ) : ?>
<li><?php tp_radar_chip( $it['source'], $it['date'] ); ?> <?php echo esc_html( $it['orig'] ); ?></li>
			<?php endforeach; ?>
</ul>
		<?php endif; ?>
</section>
	<?php endforeach; ?>
	<?php if ( $pending ) : // 만드는 중인 부탁이 있으면 30초마다 새로 읽어 끝난 결과가 저절로 보이게 ?>
<script>setTimeout(function () { location.reload(); }, 30000);</script>
	<?php endif; ?>
	<?php if ( count( $asks ) > $show ) : ?>
<p style="text-align:center"><a class="tprd-more" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&view=report&show=' . ( $show + 6 ) ) ); ?>">✈️ 지난 모의 글 더 보기</a></p>
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
<li data-g="<?php echo esc_attr( TP_RADAR_SOURCES[ $it['source'] ][1] ?? '' ); ?>" data-w="<?php echo tp_radar_warm( $tr, $it['link'] ) ? '1' : '0'; ?>"><label for="tprd-f<?php echo (int) $k; ?>">
<input type="checkbox" id="tprd-f<?php echo (int) $k; ?>" name="links[]" value="<?php echo esc_attr( $it['link'] ); ?>">
<span class="thumb tprd-screen"<?php echo $need ? ' data-need="' . esc_attr( $it['link'] ) . '"' : ''; ?>><?php echo $img ? '<img class="ph" src="' . esc_url( $img ) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : ''; ?>
<span class="tprd-cap"><?php echo tp_radar_where( $it['source'] ); // phpcs:ignore -- 이미 이스케이프 ?><a class="tt" href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ko ? $ko : $it['orig'] ); ?></a></span></span>
<span class="bd">
<span class="meta"><?php echo tp_radar_warm( $tr, $it['link'] ) ? '<span class="tprd-warm">🫶 사람 냄새</span> ' : ''; ?><?php echo esc_html( substr( $it['date'], 5 ) . ' · ' . tp_radar_ago( $it['t'] ) ); ?> 송출
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

/** 📰 실시간 피드 칸: 갈래 거르기 → 글 목록(체크) → 모의 글 부탁 막대 → 초인종 설정 */
function tp_radar_feed_view( $feed, $asks ) {
	// 이미 모의 글을 부탁한 글은 표시 — 같은 글을 또 부탁하지 않게
	$state = array();
	foreach ( array_reverse( $asks, true ) as $id => $a ) {
		foreach ( (array) $a['items'] as $it ) {
			$state[ $it['link'] ] = array( 'done' === ( $a['status'] ?? '' ) ? '🃏 글 만듦' : '⏳ 만드는 중', '#ask-' . $id );
		}
	}
	$bell  = get_option( 'tp_radar_bell', array() );
	$mbell = get_option( 'tp_radar_bell_mock', array() );
	$tr    = tp_radar_titles();
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
	$warm  = 0;
	foreach ( $show as $it ) {
		$g           = TP_RADAR_SOURCES[ $it['source'] ][1] ?? '';
		$count[ $g ] = ( $count[ $g ] ?? 0 ) + 1;
		$warm       += tp_radar_warm( $tr, $it['link'] ) ? 1 : 0;
	}
	// 번역 부탁을 보냈는데 그 뒤로 아직 저장이 없고 20분이 안 지났으면 '번역하는 중'
	$busy = tp_radar_titles_busy();
	?>
<div class="tprd-filters" role="group" aria-label="갈래 거르기">
<button type="button" data-g="" aria-pressed="true">전체 <?php echo count( $show ); ?></button>
<button type="button" data-g="warm" aria-pressed="false" style="border-color:#e0457b">🫶 사람 냄새 <?php echo (int) $warm; ?></button>
	<?php foreach ( TP_RADAR_GROUP_NAME as $g => $name ) : ?>
		<?php list( $bg, $line, $fg ) = TP_RADAR_GROUP_COLOR[ $g ]; ?>
<button type="button" data-g="<?php echo esc_attr( $g ); ?>" aria-pressed="false" style="border-color:<?php echo esc_attr( $line ); ?>"><?php echo esc_html( $name . ' ' . ( $count[ $g ] ?? 0 ) ); ?></button>
	<?php endforeach; ?>
<form class="tprd-refresh" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_refresh' ); ?><input type="hidden" name="action" value="tp_radar_refresh"><?php echo esc_html( tp_radar_ago( $feed['at'] ) ); ?> 받음 <button type="submit" title="새 글을 받아 와요. 이미 받은 7일 안의 글은 사라지지 않아요">🔄 새로고침</button></form>
	<?php if ( $todo ) : ?>
<form class="tprd-refresh tprd-trb" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'tp_radar_tr' ); ?><input type="hidden" name="action" value="tp_radar_tr"><button type="submit"<?php echo $busy ? ' disabled' : ''; ?>><?php echo $busy ? '🌐 특파원이 훑는 중…' : '🌐 번역·거르기 부탁 ' . count( $todo ) . '개'; ?></button></form>
		<?php if ( $busy ) : // 끝나면 저절로 바뀌게 30초마다 새로 읽는다 ?>
<script>setTimeout(function () { location.reload(); }, 30000);</script>
		<?php elseif ( tp_radar_auto_due( count( $todo ) ) ) : // 자동 부탁: 탭이 열린 뒤 백그라운드로. 성공하면 새로 읽어 '훑는 중' 표시로 ?>
<script>
fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'tp_radar_auto', _ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( 'tp_radar_auto' ) ); ?> }) })
	.then(function (r) { return r.json(); })
	.then(function (res) {
		if (!res || !res.success) return;
		if (res.data.rang) { location.reload(); return; }
		if (res.data.msg) { var p = document.createElement('p'); p.className = 'tprd-errs'; p.textContent = res.data.msg; document.querySelector('.tprd-filters').after(p); }
	}).catch(function () {});
</script>
		<?php endif; ?>
	<?php endif; ?>
</div>
<p class="tprd-rule">🙈 <b>특파원 거르기</b> — <b>남김</b> 🫶 사람 냄새 나는 글(필터 버튼), 재밌는 광고·캠페인, 화제에 숟가락 얹은 브랜드 패러디, 밈·X 화제, 게임·젊은 세대 마케팅, 신기한 사이트·앱, 앱 새 기능, 팝업·콜라보, 트렌드 데이터 · <b>접음</b> 대놓고 뉴스인 글(정치·사건사고, 경기 결과·부상, 업계 인사·실적·수상, 테크·AI 산업), 같은 이야기 두 번째부터</p>
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
<button type="submit" class="tprd-btn" id="tprd-ask-btn" disabled>🃏 모의 글 부탁</button>
<span id="tprd-ask-n">모의 글로 만들 글을 골라 주세요 · 한 번에 <?php echo (int) TP_RADAR_ASK_MAX; ?>개까지 · 끝나면 모의 글 칸에 쌓여요</span>
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
		btn.textContent = n ? '🃏 고른 ' + n + '개 모의 글 부탁' : '🃏 모의 글 부탁';
		say.textContent = n > max ? '한 번에 ' + max + '개까지예요 — ' + (n - max) + '개를 빼 주세요' : (n ? '특파원이 5~15분 안에 비공개 임시글로 만들어 모의 글 칸에 올려요' : '모의 글로 만들 글을 골라 주세요 · 한 번에 ' + max + '개까지 · 끝나면 모의 글 칸에 쌓여요');
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
			form.querySelectorAll('.tprd-feed li').forEach(function (li) { li.hidden = b.dataset.g !== '' && (b.dataset.g === 'warm' ? li.dataset.w !== '1' : li.dataset.g !== b.dataset.g); });
		});
	});
})();
</script>
<?php // 초인종 설정 두 개(번역·거르기 routine / 모의 글 routine): 처음 한 번과 열쇠를 바꿀 때만 쓰니 접어 둔다. 열쇠는 저장 뒤 다시 보여 주지 않는다
foreach ( array(
	'main' => array( $bell, '🔔 특파원 초인종 설정 (번역·거르기)', '번역·거르기를 부탁해도 특파원이 오지 않아요', '트란시스 특파원 routine' ),
	'mock' => array( $mbell, '🔔 모의 글 초인종 설정', '모의 글을 부탁해도 특파원이 오지 않아요', '모의 글 routine(Opus)' ),
) as $which => $cfg ) :
	list( $b, $label, $off, $rname ) = $cfg;
	?>
<details class="tprd-bell"<?php echo empty( $b['token'] ) ? ' open' : ''; ?>>
<summary><?php echo esc_html( $label ); ?> <small><?php echo empty( $b['token'] ) ? '아직 설정 안 됨 — ' . esc_html( $off ) : '연결됨'; ?></small></summary>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'tp_radar_bell' ); ?>
<input type="hidden" name="action" value="tp_radar_bell">
<input type="hidden" name="which" value="<?php echo esc_attr( $which ); ?>">
<p class="tprd-orig">claude.ai/code/routines → <?php echo esc_html( $rname ); ?> → Edit → API 트리거에서 받은 주소와 열쇠를 넣어 주세요.</p>
<label for="tprd-bell-url-<?php echo esc_attr( $which ); ?>">주소</label>
<input type="url" id="tprd-bell-url-<?php echo esc_attr( $which ); ?>" name="bell_url" required value="<?php echo esc_attr( $b['url'] ?? '' ); ?>" placeholder="https://api.anthropic.com/v1/claude_code/routines/trig_…/fire">
<label for="tprd-bell-token-<?php echo esc_attr( $which ); ?>">열쇠<?php echo empty( $b['token'] ) ? '' : ' (바꿀 때만 넣으세요)'; ?></label>
<input type="password" id="tprd-bell-token-<?php echo esc_attr( $which ); ?>" name="bell_token" autocomplete="off"<?php echo empty( $b['token'] ) ? ' required' : ''; ?>>
<p><button type="submit" class="tprd-btn" style="margin-top:10px">저장</button></p>
</form>
</details>
<?php endforeach; ?>
	<?php
}
