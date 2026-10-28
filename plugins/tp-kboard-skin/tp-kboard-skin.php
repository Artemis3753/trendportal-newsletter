<?php
/**
 * Plugin Name: TP KBoard Skin
 * Description: 트렌드포털 제보 게시판용 KBoard 스킨. default 스킨 복사본. 최신글 썸네일을 추가하고 추천(좋아요) 기능을 뺐다. 글쓰기 화면은 검색엔진에서 뺀다.
 * Version: 1.6.0
 * Author: Trendportal
 */

if (!defined('ABSPATH')) exit;

/*
 * KBoard는 자기 플러그인 폴더의 skin/ 만 읽지만, 목록을 만든 뒤 kboard_skin_list 필터를 거친다.
 * 여기에 우리 스킨을 끼워 넣으면 KBoard 파일을 건드리지 않고도 스킨 선택지에 나타나고,
 * KBoard를 업데이트해도 이 스킨은 지워지지 않는다.
 */
add_filter('kboard_skin_list', function ($list) {
	$skin = new stdClass();
	$skin->name = 'tp-default';
	$skin->dir  = __DIR__ . '/skin/tp-default';
	$skin->url  = plugins_url('skin/tp-default', __FILE__);
	$list['tp-default'] = $skin;
	return $list;
});

/*
 * 1.2.0: 글쓰기 화면(/report/?mod=editor)은 빈 입력 칸뿐이라 검색 결과에 나올 이유가 없다(10/15 보완점 7번).
 * 링크는 따라가도 되게(follow) 두고 색인만 막는다. 목록·제보 글 보기 화면은 그대로 색인된다.
 */
add_filter('wp_robots', function ($robots) {
	$mod = isset($_GET['mod']) ? sanitize_key(wp_unslash($_GET['mod'])) : '';
	if ('editor' === $mod) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
});

/*
 * 1.4.2: 제보 게시판은 날짜만 보여 주고 시간은 숨긴다(10/29 사용자 결정).
 * KBoard의 getDate()는 오늘 올라온 글이면 날짜 대신 '02:41' 같은 시각을 돌려줘서 목록·홈 어디서든 시간이 드러난다.
 * 그래서 스킨은 getDate()를 쓰지 않고 이 함수로 목록(2026.10.18)과 같은 모양의 날짜만 만든다.
 */
function tp_kboard_date($content) {
	return date('Y.m.d', strtotime($content->date));
}

/*
 * 1.5.0: 제보 썸네일 주소를 찾는 규칙을 한곳에 모았다(홈 목록 latest.php와 게시판 목록 list.php가 같이 쓴다).
 * - 비밀글 사진이 목록·홈에 노출되면 안 되므로 비밀글은 썸네일을 만들지 않는다.
 * - 대표 이미지가 없으면 KBoard가 본문 첫 사진을 대신 돌려준다(getThumbnail).
 * - getThumbnail은 첨부·사이트에 올린 사진만 찾는다. 기사 사진을 붙여 넣은 글(외부 주소 <img>)은 비어 나오므로
 *   본문 첫 <img>의 주소를 직접 꺼낸다. 원본 서버가 핫링크를 막을 수 있어 Jetpack 이미지 CDN(photon)을 거치고,
 *   없으면 원본 주소를 그대로 쓴다.
 * $size는 정사각 한 변의 픽셀이다.
 */
function tp_kboard_thumb($content, $size) {
	if ($content->secret) {
		return '';
	}
	$thumb = $content->getThumbnail($size, $size);
	if (!$thumb && preg_match('/<img[^>]+src=["\'](https?:\/\/[^"\']+)["\']/i', $content->content, $match)) {
		$src   = html_entity_decode($match[1]);
		$thumb = function_exists('jetpack_photon_url') ? jetpack_photon_url($src, array('resize' => $size . ',' . $size)) : $src;
	}
	return $thumb;
}

/*
 * 1.6.0: 홈 제보 칸도 카드 격자가 됐다(10/29). KBoard는 최신글(latest)을 그릴 때 스킨 style.css를 불러오지 않아서
 * (게시판 목록 화면에서만 불러온다) 홈 전용 CSS를 따로 달았다. 추가 CSS에 붙여 넣을 필요가 없도록 플러그인이 직접 낸다.
 * 버전 인자는 파일 수정 시각 — CSS만 고쳐도 브라우저 캐시가 새로 받는다.
 */
add_action('wp_enqueue_scripts', function () {
	if (!is_front_page()) {
		return;
	}
	$file = __DIR__ . '/skin/tp-default/home.css';
	wp_enqueue_style('tp-kboard-home', plugins_url('skin/tp-default/home.css', __FILE__), array(), file_exists($file) ? filemtime($file) : '1.6.0');
});
