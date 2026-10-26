<?php
/**
 * Plugin Name: TP Admin Menu
 * Description: wp-admin 왼쪽 메뉴를 "내 홈 · 통계 · 트란시스 일정표 · 카드뉴스 도우미 · 트란시스 특파원" 순으로 맨 위에 모으고, 나머지 항목은 구분선 아래로 보낸다. 트란시스 일정표 탭(일정표를 관리자 화면 안에 띄움)도 여기서 만든다.
 * Version: 1.0.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TP_AM_SLUG      = 'tp-transis-schedule';
const TP_AM_SEPARATOR = 'separator-tp-admin-menu';

/*
 * 일정표 주소는 TP Transis Frame이 쓰는 설정값(tp_transis_frame)을 그대로 읽는다.
 * 저장소가 공개라 주소를 코드에 적을 수 없고, 같은 값을 두 군데에 두면 한쪽만 바뀔 수 있어서다.
 * 설정이 비었거나 구글 주소가 아니면 탭은 만들되 안내 문구만 보여 준다.
 */
function tp_am_schedule_url() {
	$opt = get_option( 'tp_transis_frame' );
	$url = is_array( $opt ) && isset( $opt['url'] ) ? $opt['url'] : '';
	return 0 === strpos( $url, 'https://script.google.com/' ) ? $url : '';
}

add_action(
	'admin_menu',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_menu_page( '트란시스 일정표', '트란시스 일정표', 'manage_options', TP_AM_SLUG, 'tp_am_schedule_page', 'dashicons-calendar-alt', 3.05 );

		// 위쪽 묶음과 나머지 사이를 가르는 구분선. 코어의 separator1과 같은 모양(wp-menu-separator)이다
		global $menu;
		$menu[] = array( '', 'read', TP_AM_SEPARATOR, '', 'wp-menu-separator' );
	},
	999
);

function tp_am_schedule_page() {
	$url = tp_am_schedule_url();
	if ( '' === $url ) {
		echo '<div class="wrap"><p>일정표 주소가 설정되어 있지 않아요. (TP Transis Frame의 설정값 tp_transis_frame)</p></div>';
		return;
	}
	?>
<style>
	#wpcontent { padding-left: 0; }
	#wpbody-content { padding-bottom: 0; }
	#wpfooter { display: none; }
	.tp-am-frame { display: block; width: 100%; height: calc(100vh - 32px); border: 0; background: #fff; }
	@media screen and ( max-width: 782px ) { .tp-am-frame { height: calc(100vh - 46px); } }
</style>
<?php
/*
 * credentialless: 이 창 안의 요청엔 구글 쿠키를 싣지 않는다. 관리자 PC엔 구글 계정이 여럿 로그인돼 있는 경우가 많고,
 * 쿠키가 실리면 주소에 /u/N/이 붙어 "현재 파일을 열 수 없습니다"가 난다(TP Transis Frame 1.0.1에서 겪은 것과 같은 문제).
 */
?>
<iframe class="tp-am-frame" src="<?php echo esc_url( $url ); ?>" title="트란시스 일정표" allow="clipboard-write" referrerpolicy="no-referrer" credentialless></iframe>
	<?php
}

/*
 * 메뉴 순서. custom_menu_order를 켜면 WP가 menu_order 필터가 돌려준 슬러그 순서를 앞에 세우고,
 * 목록에 없는 항목은 원래 순서 그대로 그 뒤에 붙인다. 그래서 "위쪽 5개 + 구분선"만 알려 주면
 * 나머지(알림판·호스팅·업그레이드·KBoard·Jetpack 등)는 따로 건드릴 필요 없이 구분선 아래로 내려간다.
 *
 * 내 홈·통계는 워드프레스닷컴이 만든 항목이라 슬러그가 사이트 주소에 따라 달라진다(바깥 링크).
 * 그래서 제목이 정확히 같은 항목을 먼저 찾고, 없으면 슬러그의 모양(/home/, /stats/)으로 찾는다. 못 찾으면 그 칸만 조용히 건너뛴다.
 * 제목을 먼저 보는 이유: 통계가 둘('통계', '통계 v2')이라 슬러그만 보면 v2를 집을 수 있어서다.
 */
function tp_am_find( $titles, $slug_pattern ) {
	global $menu;
	$by_slug = null;
	foreach ( (array) $menu as $item ) {
		if ( empty( $item[2] ) ) {
			continue;
		}
		$title = trim( wp_strip_all_tags( preg_replace( '#<span.*?</span>#s', '', (string) $item[0] ) ) );
		if ( in_array( $title, $titles, true ) ) {
			return $item[2];
		}
		if ( null === $by_slug && preg_match( $slug_pattern, (string) $item[2] ) ) {
			$by_slug = $item[2];
		}
	}
	return $by_slug;
}

add_filter( 'custom_menu_order', '__return_true' );

add_filter(
	'menu_order',
	function ( $order ) {
		$head = array(
			tp_am_find( array( '내 홈', 'My Home' ), '#wordpress\.com/home(/|$)#' ),
			tp_am_find( array( '통계', 'Stats' ), '#wordpress\.com/stats(/|$)#' ),
			TP_AM_SLUG,
			defined( 'TP_CN_SLUG' ) ? TP_CN_SLUG : null,
			defined( 'TP_RADAR_SLUG' ) ? TP_RADAR_SLUG : null,
		);
		$head   = array_values( array_unique( array_filter( $head ) ) );
		$head[] = TP_AM_SEPARATOR;
		return array_merge( $head, array_diff( $order, $head ) );
	}
);
