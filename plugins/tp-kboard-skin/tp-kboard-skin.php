<?php
/**
 * Plugin Name: TP KBoard Skin
 * Description: 트렌드포털 제보 게시판용 KBoard 스킨. default 스킨 복사본. 최신글 썸네일을 추가하고 추천(좋아요) 기능을 뺐다.
 * Version: 1.1.0
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
