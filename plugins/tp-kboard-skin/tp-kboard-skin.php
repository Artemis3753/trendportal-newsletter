<?php
/**
 * Plugin Name: TP KBoard Skin
 * Description: 트렌드포털 제보 게시판용 KBoard 스킨. default 스킨 복사본. 최신글 썸네일을 추가하고 추천(좋아요) 기능을 뺐다. 글쓰기 화면은 검색엔진에서 뺀다.
 * Version: 1.4.2
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
