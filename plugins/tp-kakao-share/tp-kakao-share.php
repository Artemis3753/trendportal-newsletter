<?php
/**
 * Plugin Name: TP Kakao Share
 * Description: Adds a KakaoTalk share button to Trendportal posts. Place the [tp_kakao_share] shortcode in a template to render the button there.
 * Version: 1.1.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TP_KAKAO_SHARE_VERSION', '1.1.0' );

// The JavaScript key is meant to be public in page source.
// It only works on the domain registered in Kakao Developers (trendportal.kr), so it is safe to keep here.
define( 'TP_KAKAO_SHARE_JS_KEY', '51afd89e93346eda207b3fad7849a021' );

// The SDK version is pinned so a new Kakao release cannot change our site's behavior without us noticing.
// The integrity value was computed with sha384 from the downloaded 2.8.3 file. Recompute it when bumping the version.
define( 'TP_KAKAO_SDK_URL', 'https://t1.kakaocdn.net/kakao_js_sdk/2.8.3/kakao.min.js' );
define( 'TP_KAKAO_SDK_INTEGRITY', 'sha384-oroumrnFVE0xtgqyDZJARgERibXg2C28380uaUZz2kHDS5CR7tu20eGiOU6GkTpy' );

/**
 * Only register the scripts here. They are loaded only on pages that use the shortcode,
 * so the home and archive pages never download the Kakao SDK (about 87KB).
 */
function tp_kakao_share_register_scripts() {
	wp_register_script( 'kakao-sdk', TP_KAKAO_SDK_URL, array(), null, true );

	wp_register_script(
		'tp-kakao-share',
		plugins_url( 'tp-kakao-share.js', __FILE__ ),
		array( 'kakao-sdk' ),
		TP_KAKAO_SHARE_VERSION,
		true
	);

	// Passes the key from PHP to JS. Runs before tp-kakao-share.js.
	wp_add_inline_script(
		'tp-kakao-share',
		'window.tpKakaoShare = ' . wp_json_encode( array( 'jsKey' => TP_KAKAO_SHARE_JS_KEY ) ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'tp_kakao_share_register_scripts' );

/**
 * wp_register_script has no option for the integrity attribute,
 * so it is added right before the <script> tag is printed.
 * integrity: the browser refuses to run the CDN file if it has been changed or tampered with.
 */
function tp_kakao_share_sdk_integrity( $tag, $handle ) {
	if ( 'kakao-sdk' !== $handle ) {
		return $tag;
	}
	return str_replace(
		' src=',
		' integrity="' . esc_attr( TP_KAKAO_SDK_INTEGRITY ) . '" crossorigin="anonymous" src=',
		$tag
	);
}
add_filter( 'script_loader_tag', 'tp_kakao_share_sdk_integrity', 10, 2 );

/**
 * [tp_kakao_share] shortcode -> share button HTML.
 * The button is rendered hidden. If the SDK fails to load (e.g. an ad blocker),
 * a visible button would do nothing when tapped, so JS reveals it only once it is ready.
 */
function tp_kakao_share_render() {
	if ( ! is_singular( 'post' ) ) {
		return '';
	}

	wp_enqueue_script( 'tp-kakao-share' );

	// kakao-talk-icon.svg is Kakao's official share icon, used unmodified
	// (Kakao Developers > Design resources > KakaoTalk > KakaoTalk Share).
	// alt is empty because the visible label already says what the button does.
	return sprintf(
		'<button type="button" class="tp-kakao-share" data-url="%s" hidden><img src="%s" alt="" width="20" height="20"><span>카카오톡 공유</span></button>',
		esc_url( get_permalink() ),
		esc_url( plugins_url( 'kakao-talk-icon.svg', __FILE__ ) )
	);
}
add_shortcode( 'tp_kakao_share', 'tp_kakao_share_render' );
