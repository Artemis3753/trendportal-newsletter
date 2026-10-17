<?php
/**
 * Plugin Name: TP Cardnews Helper
 * Description: 관리자 전용 탭 "트란시스 카드뉴스 도우미". 피그마 플러그인(트란시스 카드뉴스 읽기)이 만든 zip과 인스타 캡션·영상 주소를 주문으로 받고, 처리 결과(초안·미리보기·고친 것 목록)를 보여 준다. 처리는 사이트 밖(tools/cardnews/cardnews.py)이 REST로 주문을 가져가서 한다.
 * Version: 1.18.0
 * Author: Trendportal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 10/17 사용자 결정(A, 탭 먼저): 주문 창구는 사이트 관리자 탭, 요리는 사이트 밖.
 * 워드프레스(PHP) 안에서는 큰 사진 처리에 시간·메모리 제한이 있고, alt·제목·오타 같은 판단을 할 Claude도 없어서다.
 * 그래서 이 플러그인은 주문을 받아 두고 상태만 보여 준다. 가져가는 쪽은 REST(아래 tp-cardnews/v1)로 온다.
 *
 * 저장: 주문 = 비공개 글 유형(tp_cn_order) 하나 + 메타. 따로 DB 표를 만들지 않아 지우기·백업이 글과 같다.
 * zip = uploads/tp-cardnews/ 아래 무작위 이름. 미디어 라이브러리에 섞이지 않게 하고, 주소를 추측할 수 없게 한다.
 */

const TP_CN_TYPE = 'tp_cn_order';
const TP_CN_DIR  = 'tp-cardnews';
const TP_CN_SLUG = 'tp-cardnews';
const TP_CN_MAX  = 200 * 1024 * 1024; // zip 상한. 764(카드 17장)가 16MB였다

// 상태 이름은 영어로 저장하고, 화면에는 한국어로 (REST로 오가는 값이 글자 깨짐 걱정 없게)
const TP_CN_STATUS = array(
	'new'     => '주문 접수',
	'revise'  => '수정 접수', // 완성된 초안에 "고쳐 주세요"가 들어온 주문 (10/17 사용자: 관리자 창에서 메모로 고치기)
	'working' => '요리 중',
	'done'    => '완성',
	'failed'  => '주방 멈춤',
	// 셰프가 추측하면 틀릴 것 같아 멈추고 묻는 상태(10/17 사용자: 웹폼 결과도 대화처럼). 사람이 답하면 answered → 셰프가 이어서
	'question' => '질문 있어요',
	'answered' => '답 접수',
);

add_action(
	'init',
	function () {
		register_post_type(
			TP_CN_TYPE,
			array(
				'label'    => '카드뉴스 주문',
				'public'   => false,
				'show_ui'  => false, // 목록은 도우미 탭이 직접 그린다
				'supports' => array( 'title' ),
			)
		);
	}
);

// ── 파일 ────────────────────────────────────────────────

function tp_cn_dir() {
	$dir = wp_upload_dir()['basedir'] . '/' . TP_CN_DIR;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		// 폴더 목록이 열리지 않게 빈 index.php
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

// 주문에 딸린 파일(zip + 영상 카드 파일 + 인터뷰 대상 사진)을 지운다. 주문을 지울 때, 그리고 글이 발행·휴지통으로 갈 때
function tp_cn_unlink_files( $order_id ) {
	$names = array( get_post_meta( $order_id, '_tp_cn_zip', true ) );
	foreach ( (array) get_post_meta( $order_id, '_tp_cn_files', true ) as $f ) {
		$names[] = is_array( $f ) ? $f['file'] : '';
	}
	// 인터뷰 대상은 이름·주소는 남기고(목록·수정 때 필요) 사진 파일만 지운다
	$people = get_post_meta( $order_id, '_tp_cn_people', true );
	if ( is_array( $people ) ) {
		foreach ( $people as $i => $p ) {
			$names[]              = $p['file'] ?? '';
			$people[ $i ]['file'] = '';
		}
		update_post_meta( $order_id, '_tp_cn_people', $people );
	}
	foreach ( array_filter( $names ) as $name ) {
		if ( is_file( tp_cn_dir() . '/' . $name ) ) {
			unlink( tp_cn_dir() . '/' . $name );
		}
	}
	update_post_meta( $order_id, '_tp_cn_zip', '' );
	update_post_meta( $order_id, '_tp_cn_files', array() );
}

add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( get_post_type( $post_id ) === TP_CN_TYPE ) {
			tp_cn_unlink_files( $post_id );
		}
	}
);

/*
 * 영상 카드 칸(1.6.0, 10/17 사용자 A): 한 줄 = 카드 번호 + 파일(선택) + 원본 주소(선택).
 * 셰프는 "주소가 있느냐"로 할 일을 정한다 — 주소 있음 = 남의 영상(정지 화면 + ▶ 링크, 영상은 사이트에 안 올림),
 * 주소 없음 + 영상 파일 = 우리가 찍은 영상(소리 빼고 사이트에서 바로 재생). 사진 파일은 정지 화면이나 카드 사진으로 쓴다.
 */
const TP_CN_FILE_TYPES = array(
	'mp4'  => 'video',
	'mov'  => 'video',
	'm4v'  => 'video',
	'webm' => 'video',
	'jpg'  => 'image',
	'jpeg' => 'image',
	'png'  => 'image',
	'webp' => 'image',
);

// 확장자만 바꾼 엉뚱한 파일을 셰프(ffmpeg)에게 넘기지 않게, 파일 머리 몇 바이트로 종류를 한 번 더 본다
function tp_cn_sniff( $path ) {
	$head = (string) file_get_contents( $path, false, null, 0, 16 );
	if ( 'ftyp' === substr( $head, 4, 4 ) || "\x1a\x45\xdf\xa3" === substr( $head, 0, 4 ) ) {
		return 'video'; // mp4·mov·m4v는 4번째 바이트부터 ftyp, webm은 EBML 머리
	}
	if ( "\xff\xd8\xff" === substr( $head, 0, 3 ) || "\x89PNG" === substr( $head, 0, 4 ) || ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) ) {
		return 'image';
	}
	return '';
}

/**
 * 영상 카드 줄들 → array( 'videos' => [ 번호 => 주소 ], 'files' => [ 번호 => 올린 파일 정보 ] ) 또는 WP_Error.
 * 파일은 아직 옮기지 않는다(다른 칸 검사가 다 끝난 뒤에 옮긴다).
 */
function tp_cn_parse_video_rows( $cards, $urls, $files ) {
	$out = array(
		'videos' => array(),
		'files'  => array(),
	);
	foreach ( (array) $cards as $i => $card ) {
		$raw  = trim( (string) ( $urls[ $i ] ?? '' ) );
		$url  = esc_url_raw( $raw, array( 'http', 'https' ) );
		$file = ( isset( $files['error'][ $i ] ) && UPLOAD_ERR_NO_FILE !== $files['error'][ $i ] )
			? array(
				'name'     => $files['name'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			)
			: null;
		$card = trim( (string) $card );
		if ( '' === $raw && ! $file ) {
			continue; // 빈 줄 (카드 번호만 적은 줄도 넣을 게 없으니 넘긴다)
		}
		if ( ! preg_match( '/^\d{1,2}$/', $card ) || (int) $card < 2 ) {
			return new WP_Error( 'tp_cn_video', '영상 카드 줄에 카드 번호를 적어 주세요 (표지가 1번이라 2번부터)' );
		}
		$no = (string) (int) $card;
		if ( isset( $out['videos'][ $no ] ) || isset( $out['files'][ $no ] ) ) {
			return new WP_Error( 'tp_cn_video', "{$no}번 카드가 영상 카드 칸에 두 번 있어요" );
		}
		if ( '' === $url && '' !== $raw ) {
			return new WP_Error( 'tp_cn_video', "{$no}번 주소를 못 읽었어요 — https://로 시작하는 주소를 붙여 넣어 주세요" );
		}
		if ( '' !== $url ) {
			$out['videos'][ $no ] = $url;
		}
		if ( $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return new WP_Error( 'tp_cn_video', "{$no}번 파일을 올리다 멈췄어요 (오류 {$file['error']}). 파일이 너무 크면 이렇게 될 수 있어요" );
			}
			$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
			$kind = TP_CN_FILE_TYPES[ $ext ] ?? '';
			if ( ! $kind || tp_cn_sniff( $file['tmp_name'] ) !== $kind ) {
				return new WP_Error( 'tp_cn_video', "{$no}번 파일은 영상(mp4·mov·m4v·webm)이나 사진(jpg·png·webp)만 받아요" );
			}
			$out['files'][ $no ] = array(
				'tmp'  => $file['tmp_name'],
				'name' => sanitize_file_name( $file['name'] ),
				'ext'  => $ext,
				'kind' => $kind,
			);
		}
	}
	return $out;
}

/*
 * 인터뷰 폼(1.8.0, 10/17 사용자: 도우미 = 카드뉴스 폼 + 인터뷰 폼). 형식은 주문하는 사람이 고른다 —
 * 카피 모음형 때처럼 카드 모양으로 자동으로 고르다 엉뚱한 틀이 붙는 일을 막으려고. 값은 cardnews.py의 order.json "layout"과 같다
 */
const TP_CN_LAYOUTS = array(
	'qa'    => '질문 여러 개',
	'panel' => '패널',
);

/**
 * 인터뷰 대상 줄들(이름 · 인스타 주소 · 프로필 사진, 모두 선택) → 목록 또는 WP_Error.
 * 글 시작·끝 프로필 카드 재료다. 비워도 주문은 된다(사용자 결정: 항상 선택) — 셰프가 프로필 카드를 빼고 알린다.
 * 파일은 아직 옮기지 않는다(다른 칸 검사가 다 끝난 뒤에 옮긴다).
 * 광고 폼(1.11.0)도 같은 칸을 광고 브랜드 칸으로 쓴다 — $label은 오류 문구에 들어갈 이름.
 */
function tp_cn_parse_people( $names, $instas, $files, $label = '인터뷰 대상' ) {
	$out = array();
	foreach ( (array) $names as $i => $name ) {
		$name  = trim( sanitize_text_field( (string) $name ) );
		$raw   = trim( (string) ( $instas[ $i ] ?? '' ) );
		$insta = esc_url_raw( $raw, array( 'https', 'http' ) );
		$has   = isset( $files['error'][ $i ] ) && UPLOAD_ERR_NO_FILE !== $files['error'][ $i ];
		if ( '' === $name && '' === $raw && ! $has ) {
			continue;
		}
		$who = '' !== $name ? $name : ( '' !== $raw ? $raw : '사진만 넣은 줄' );
		if ( '' !== $raw && ! preg_match( '#^https?://(www\.)?instagram\.com/[A-Za-z0-9._]+/?#', $insta ) ) {
			return new WP_Error( 'tp_cn_people', "{$label} '{$who}'의 인스타 주소를 못 읽었어요 — https://www.instagram.com/계정/ 모양으로 붙여 넣어 주세요" );
		}
		if ( '' === $name && '' === $raw ) {
			return new WP_Error( 'tp_cn_people', "{$label} 사진만 있고 이름·인스타 주소가 없는 줄이 있어요" );
		}
		$p = array(
			'name'  => $name,
			'insta' => $insta,
			'tmp'   => '',
			'ext'   => '',
		);
		if ( $has ) {
			if ( UPLOAD_ERR_OK !== $files['error'][ $i ] ) {
				return new WP_Error( 'tp_cn_people', "{$label} '{$who}' 사진을 올리다 멈췄어요 (오류 {$files['error'][ $i ]})" );
			}
			$ext = strtolower( pathinfo( $files['name'][ $i ], PATHINFO_EXTENSION ) );
			if ( 'image' !== ( TP_CN_FILE_TYPES[ $ext ] ?? '' ) || 'image' !== tp_cn_sniff( $files['tmp_name'][ $i ] ) ) {
				return new WP_Error( 'tp_cn_people', "{$label} '{$who}' 사진은 jpg·png·webp만 받아요" );
			}
			$p['tmp'] = $files['tmp_name'][ $i ];
			$p['ext'] = $ext;
		}
		$out[] = $p;
	}
	return $out;
}

/**
 * 셰프가 손본 것 한 줄 → [단계, 글자]. 단계는 셰프가 붙인 이름표로 정한다(10/18 사용자: 중요도에 따라 강조, 시안 1018-1 C).
 * 3 = '꼭 확인:'(발행하면 곤란할 수 있음) · 2 = '확인 필요:'(한 번 봐야 함) · 1 = 셰프가 한 일 기록.
 * '꼭 확인'은 1.12.0부터 생긴 이름표라 그 전 주문은 '확인 필요'까지만 나온다
 */
function tp_cn_level( $line ) {
	if ( preg_match( '/^\s*꼭\s*확인\s*:\s*(.*)$/su', $line, $m ) ) {
		return array( 3, $m[1] );
	}
	if ( preg_match( '/^\s*확인\s*필요\s*:\s*(.*)$/su', $line, $m ) ) {
		return array( 2, $m[1] );
	}
	return array( 1, $line );
}

/**
 * 손본 것 한 줄을 <li>로. 확인 항목은 이모지를 앞에 달고 이름표는 뗀다(이모지가 그 뜻을 대신한다)
 */
function tp_cn_change_li( $line ) {
	list( $lv, $text ) = tp_cn_level( $line );
	$mark = array( 3 => '❗ ', 2 => '🟡 ', 1 => '' )[ $lv ];
	return '<li class="lv' . $lv . '">' . esc_html( $mark . $text ) . '</li>';
}

/**
 * 받은 zip이 피그마 플러그인 결과인지 본다 (figma/scan.json이 있어야 한다).
 * 돌려주는 값: 묶음 이름(scan.json의 group.name) 또는 WP_Error. ZipArchive가 없는 서버면 검사를 건너뛴다.
 */
function tp_cn_check_zip( $path, $filename ) {
	$fallback = preg_replace( '/\.zip$/i', '', $filename );
	if ( ! class_exists( 'ZipArchive' ) ) {
		return $fallback;
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'tp_cn_zip', 'zip 파일을 열 수 없어요. 내려받다 끊긴 파일일 수 있어요 — 다시 받아 주세요' );
	}
	$scan = $zip->getFromName( 'figma/scan.json' );
	$zip->close();
	if ( false === $scan ) {
		return new WP_Error( 'tp_cn_zip', "이 zip에는 figma/scan.json이 없어요. 피그마 플러그인 '트란시스 카드뉴스 읽기'로 받은 zip인지 확인해 주세요" );
	}
	$data = json_decode( $scan, true );
	return ! empty( $data['group']['name'] ) ? $data['group']['name'] : $fallback;
}

// ── 초인종: 주문이 들어오면 클라우드의 셰프(Claude Code routine)를 바로 깨운다 ──────────
/*
 * 10/17 사용자 결정 A(즉시 신호). 매시간 알람으로 깨우면 최대 1시간을 기다리고 주문 없는 날에도 빈손으로 깨어나서다.
 * routine의 API 트리거 주소로 POST 한 번 = 셰프 한 번 출근. 열쇠(토큰)는 이 사이트 설정에만 두고 화면에 다시 보여 주지 않는다.
 * 열쇠가 새도 할 수 있는 건 "셰프 깨우기"뿐이다: 셰프는 신호에 딸린 글을 무시하고 탭의 '접수' 주문만 처리한다(tools/cardnews/PROMPT.md).
 */
const TP_CN_BELL = 'tp_cn_bell'; // 옵션 이름: array( 'url' => …, 'token' => … )

function tp_cn_ring( $id ) {
	$bell = get_option( TP_CN_BELL, array() );
	if ( empty( $bell['url'] ) || empty( $bell['token'] ) ) {
		$err = '초인종이 아직 설정되지 않았어요 (아래 "셰프 초인종 설정")';
	} else {
		$res = wp_remote_post(
			$bell['url'],
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'     => 'Bearer ' . $bell['token'],
					// routine 문서(10/17 확인)가 요구하는 머리글. 실험 기능이라 바뀌면 이 날짜만 고친다
					'anthropic-beta'    => 'experimental-cc-routine-2026-04-01',
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				// 셰프는 이 글을 따르지 않는다. 실행 기록에서 어느 주문 때 깨웠는지 알아보는 표시일 뿐
				'body'    => wp_json_encode( array( 'text' => "order {$id}" ) ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			update_post_meta( $id, '_tp_cn_rang_at', time() );
			delete_post_meta( $id, '_tp_cn_bell_err' );
			return true;
		}
		$err = is_wp_error( $res )
			? '셰프에게 연결하지 못했어요: ' . $res->get_error_message()
			: "셰프가 응답하지 않았어요 (오류 {$code}" . ( 401 === $code ? ', 열쇠가 틀렸거나 바뀌었어요' : '' ) . ')';
	}
	update_post_meta( $id, '_tp_cn_bell_err', $err );
	return false;
}

// 초인종 설정 저장 (관리자만). 열쇠 칸을 비워 두면 기존 열쇠를 그대로 둔다 — 화면에 열쇠를 다시 채워 보여 주지 않아서
add_action(
	'admin_post_tp_cn_bell',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 바꿀 수 있어요' );
		}
		check_admin_referer( 'tp_cn_bell' );
		$bell  = get_option( TP_CN_BELL, array() );
		$url   = esc_url_raw( trim( wp_unslash( $_POST['bell_url'] ?? '' ) ) );
		$token = trim( sanitize_text_field( wp_unslash( $_POST['bell_token'] ?? '' ) ) );
		$back  = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		// 엉뚱한 곳으로 열쇠를 보내지 않게, routine 주소 모양만 받는다
		if ( ! preg_match( '#^https://api\.anthropic\.com/v1/claude_code/routines/trig_\w+/fire$#', $url ) ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_err', rawurlencode( '초인종 주소는 https://api.anthropic.com/v1/claude_code/routines/trig_…/fire 모양이에요' ), $back ) );
			exit;
		}
		$bell['url'] = $url;
		if ( '' !== $token ) {
			$bell['token'] = $token;
		}
		update_option( TP_CN_BELL, $bell, false ); // autoload 끔: 탭에서만 쓰는 값이라 모든 페이지에서 불러올 필요 없다
		wp_safe_redirect( add_query_arg( 'tp_cn_info', 'bell', $back ) );
		exit;
	}
);

// "셰프 다시 부르기": 초인종이 실패했거나, 주방이 멈췄거나, 너무 오래 요리 중일 때
add_action(
	'admin_post_tp_cn_recall',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 부를 수 있어요' );
		}
		$id = (int) ( $_POST['order'] ?? 0 );
		check_admin_referer( 'tp_cn_recall_' . $id );
		$back = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		// 처음 요리는 zip이 있어야 하고, 수정은 지난번 작업 기록(state)만 있어도 된다
		$can = get_post_meta( $id, '_tp_cn_zip', true ) || ( get_post_meta( $id, '_tp_cn_feedback', true ) && get_post_meta( $id, '_tp_cn_state', true ) );
		if ( TP_CN_TYPE !== get_post_type( $id ) || ! $can ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_err', rawurlencode( '이 주문은 zip이 없어서 다시 요리할 수 없어요. 새로 주문해 주세요' ), $back ) );
			exit;
		}
		// 셰프는 '접수' 주문만 가져가니 상태를 되돌린 뒤 깨운다. 수정 요청이 남아 있으면 '수정 접수'로
		$again = get_post_meta( $id, '_tp_cn_answer', true ) ? 'answered' : ( get_post_meta( $id, '_tp_cn_feedback', true ) ? 'revise' : 'new' );
		update_post_meta( $id, '_tp_cn_status', $again );
		delete_post_meta( $id, '_tp_cn_error' );
		$ok = tp_cn_ring( $id );
		wp_safe_redirect( $ok ? add_query_arg( 'tp_cn_ok', $id, $back ) : add_query_arg( 'tp_cn_err', rawurlencode( get_post_meta( $id, '_tp_cn_bell_err', true ) ), $back ) );
		exit;
	}
);

// "고쳐 주세요": 완성된 초안에 메모로 수정을 요청한다. 셰프가 지난번 작업 기록을 이어받아 같은 초안을 고친다
add_action(
	'admin_post_tp_cn_revise',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 요청할 수 있어요' );
		}
		$id = (int) ( $_POST['order'] ?? 0 );
		check_admin_referer( 'tp_cn_revise_' . $id );
		$back = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		$fail = function ( $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_err', rawurlencode( $msg ), $back ) );
			exit;
		};
		$text = trim( sanitize_textarea_field( wp_unslash( $_POST['feedback'] ?? '' ) ) );
		if ( TP_CN_TYPE !== get_post_type( $id ) || 'done' !== get_post_meta( $id, '_tp_cn_status', true ) ) {
			$fail( '완성된 주문만 고칠 수 있어요' );
		}
		if ( '' === $text ) {
			$fail( '무엇을 고칠지 적어 주세요' );
		}
		if ( ! get_post_meta( $id, '_tp_cn_state', true ) ) {
			// 1.4.0 전에 완성된 주문은 작업 기록이 없어서 이어서 고칠 수 없다
			$fail( '이 주문은 작업 기록이 없어서 고칠 수 없어요. 편집기에서 직접 고치거나 새로 주문해 주세요' );
		}
		update_post_meta( $id, '_tp_cn_feedback', $text );
		update_post_meta( $id, '_tp_cn_status', 'revise' );
		delete_post_meta( $id, '_tp_cn_error' );
		if ( ! tp_cn_ring( $id ) ) {
			$fail( '수정 요청은 받았는데 셰프를 깨우지 못했어요. ' . get_post_meta( $id, '_tp_cn_bell_err', true ) );
		}
		wp_safe_redirect( add_query_arg( 'tp_cn_rev', $id, $back ) );
		exit;
	}
);

// 발행: 셰프가 만든 초안을 탭에서 바로 공개한다(10/18 사용자 — 편집기에서 열어 발행하던 걸 버튼 하나로).
// 발행되면 원래 있던 transition_post_status 훅이 zip·영상·대상 사진을 지운다
add_action(
	'admin_post_tp_cn_publish',
	function () {
		$id = (int) ( $_POST['order'] ?? 0 );
		check_admin_referer( 'tp_cn_publish_' . $id );
		$back  = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		$draft = (int) get_post_meta( $id, '_tp_cn_post', true );
		$fail  = function ( $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_err', rawurlencode( $msg ), $back ) );
			exit;
		};
		if ( TP_CN_TYPE !== get_post_type( $id ) || ! $draft || 'post' !== get_post_type( $draft ) ) {
			$fail( '발행할 초안을 찾지 못했어요' );
		}
		if ( ! current_user_can( 'publish_post', $draft ) ) {
			$fail( '이 계정은 글을 발행할 권한이 없어요' );
		}
		if ( 'publish' === get_post_status( $draft ) ) {
			$fail( '이미 발행된 글이에요' );
		}
		if ( in_array( get_post_meta( $id, '_tp_cn_status', true ), array( 'working', 'revise', 'answered' ), true ) ) {
			$fail( '셰프가 이 초안을 고치는 중이에요. 끝난 뒤에 발행해 주세요' );
		}
		$r = wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $r ) ) {
			$fail( '발행하지 못했어요: ' . $r->get_error_message() );
		}
		wp_safe_redirect( add_query_arg( 'tp_cn_info', '발행했어요! 사이트에 공개됐어요.', $back ) );
		exit;
	}
);

// 셰프의 질문에 답하기: 답을 남기고 셰프를 다시 부른다. 셰프는 질문할 때 맡긴 작업 기록을 되살려 이어서 만든다
add_action(
	'admin_post_tp_cn_answer',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 답할 수 있어요' );
		}
		$id = (int) ( $_POST['order'] ?? 0 );
		check_admin_referer( 'tp_cn_answer_' . $id );
		$back = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		$text = trim( sanitize_textarea_field( wp_unslash( $_POST['answer'] ?? '' ) ) );
		if ( TP_CN_TYPE !== get_post_type( $id ) || 'question' !== get_post_meta( $id, '_tp_cn_status', true ) || '' === $text ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_err', rawurlencode( '셰프가 질문한 주문에만, 답을 적어서 보낼 수 있어요' ), $back ) );
			exit;
		}
		update_post_meta( $id, '_tp_cn_answer', $text );
		update_post_meta( $id, '_tp_cn_status', 'answered' );
		delete_post_meta( $id, '_tp_cn_error' );
		$ok = tp_cn_ring( $id );
		wp_safe_redirect( $ok ? add_query_arg( 'tp_cn_info', '답을 전했어요! 셰프 트란시스가 이어서 만들어요.', $back ) : add_query_arg( 'tp_cn_err', rawurlencode( '답은 받았는데 셰프를 깨우지 못했어요. ' . get_post_meta( $id, '_tp_cn_bell_err', true ) ), $back ) );
		exit;
	}
);

// "주문 지우기": 주문 + 그 임시글 + 셰프가 그 주문 때 올린 사진을 함께 버린다(10/17 사용자).
// 발행된 글은 절대 안 지우고 주문 기록만 지운다. 요리 중인 주문은 셰프가 일하는 중이라 막는다
add_action(
	'admin_post_tp_cn_delete',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 지울 수 있어요' );
		}
		$id = (int) ( $_POST['order'] ?? 0 );
		check_admin_referer( 'tp_cn_delete_' . $id );
		$back = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		$say  = function ( $kind, $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_' . $kind, rawurlencode( $msg ), $back ) );
			exit;
		};
		if ( TP_CN_TYPE !== get_post_type( $id ) ) {
			$say( 'err', '없는 주문이에요' );
		}
		if ( 'working' === get_post_meta( $id, '_tp_cn_status', true ) ) {
			$say( 'err', '셰프가 요리 중이라 지금은 못 지워요. 끝난 뒤에 지워 주세요' );
		}
		$draft = get_post( (int) get_post_meta( $id, '_tp_cn_post', true ) );
		$note  = '';
		if ( $draft && 'post' === $draft->post_type && 'publish' !== $draft->post_status ) {
			// 셰프가 올린 사진 = 이 글이 쓰는 사진 중 파일 이름이 "order-<주문 번호>-"로 시작하는 것(cardnews.py upload가 붙이는 이름).
			// 다른 글과 같이 쓰는 사진일 수 있는 나머지는 건드리지 않는다.
			// 본문 사진(wp-image-N)·영상 블록({"id":N})·대표 이미지에 더해, 셰프 작업 기록(media.json)도 본다 —
			// 영상 포스터는 주소로만 쓰여서 본문에 번호가 없고, 수정 때 바꾼 정지 화면은 본문에서 빠져 있다
			preg_match_all( '/wp-image-(\d+)|<!-- wp:video \{"id":(\d+)/', $draft->post_content, $m );
			$ids   = array_map( 'intval', array_filter( array_merge( $m[1], $m[2] ) ) );
			$state = json_decode( (string) get_post_meta( $id, '_tp_cn_state', true ), true );
			foreach ( (array) json_decode( (string) ( $state['media.json'] ?? '' ), true ) as $item ) {
				$ids[] = (int) ( $item['id'] ?? 0 );
			}
			$media = array_unique( array_merge( $ids, array( (int) get_post_thumbnail_id( $draft ) ) ) );
			$gone  = 0;
			foreach ( $media as $mid ) {
				$file = $mid ? get_attached_file( $mid ) : '';
				if ( $file && 0 === strpos( wp_basename( $file ), "order-{$id}-" ) && wp_delete_attachment( $mid, true ) ) {
					++$gone;
				}
			}
			wp_delete_post( $draft->ID, true );
			$note = " 임시글 {$draft->ID}과 사진 {$gone}장도 지웠어요.";
		} elseif ( $draft && 'publish' === $draft->post_status ) {
			$note = " 글 {$draft->ID}은 이미 발행돼서 그대로 뒀어요.";
		}
		wp_delete_post( $id, true ); // zip은 before_delete_post에서 같이 지워진다
		$say( 'info', "주문 {$id}을 지웠어요.{$note}" );
	}
);

// zip·영상 카드 파일은 글이 발행되거나 휴지통에 갈 때 지운다(10/17 사용자: 수정 요청 때 "사진 바꿔"·"다른 장면으로"에 원본이 필요해서 완성 때 지우던 것을 바꿈).
// 발행 전까지는 주문당 수~수십 MB가 남는다. 남의 영상 원본도 여기서 지워져서 사이트에 오래 남지 않는다
function tp_cn_drop_zip_for_post( $post_id ) {
	$orders = get_posts(
		array(
			'post_type'   => TP_CN_TYPE,
			'post_status' => 'private',
			'numberposts' => 5,
			'meta_key'    => '_tp_cn_post',
			'meta_value'  => (int) $post_id,
		)
	);
	foreach ( $orders as $order ) {
		tp_cn_unlink_files( $order->ID );
	}
}
add_action(
	'transition_post_status',
	function ( $new, $old, $post ) {
		if ( 'post' === $post->post_type && 'publish' === $new && 'publish' !== $old ) {
			tp_cn_drop_zip_for_post( $post->ID );
		}
	},
	10,
	3
);
add_action( 'wp_trash_post', 'tp_cn_drop_zip_for_post' );

// ── 주문 받기 (탭의 폼) ──────────────────────────────────

add_action(
	'admin_post_tp_cn_order',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '관리자만 주문할 수 있어요' );
		}
		check_admin_referer( 'tp_cn_order' );
		$back = admin_url( 'admin.php?page=' . TP_CN_SLUG );
		$fail = function ( $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'tp_cn_err', rawurlencode( $msg ), $back ) );
			exit;
		};

		$file = isset( $_FILES['zip'] ) ? $_FILES['zip'] : null;
		if ( ! $file || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			$fail( 'zip 파일을 골라 주세요' );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			$fail( "zip을 올리다 멈췄어요 (오류 {$file['error']}). 파일이 너무 크면 이렇게 될 수 있어요" );
		}
		if ( ! preg_match( '/\.zip$/i', $file['name'] ) || $file['size'] > TP_CN_MAX ) {
			$fail( 'zip 파일만, 200MB까지 받을 수 있어요' );
		}
		$caption = trim( sanitize_textarea_field( wp_unslash( $_POST['caption'] ?? '' ) ) );
		if ( '' === $caption ) {
			$fail( '인스타 캡션을 붙여 넣어 주세요' );
		}
		$rows = tp_cn_parse_video_rows( wp_unslash( $_POST['vcard'] ?? array() ), wp_unslash( $_POST['vurl'] ?? array() ), $_FILES['vfile'] ?? array() );
		if ( is_wp_error( $rows ) ) {
			$fail( $rows->get_error_message() );
		}
		// 인터뷰 폼: 형식은 꼭 고르고, 대상 칸은 비워도 된다. 카드뉴스 폼이면 인터뷰 칸에 뭐가 남아 있어도 안 본다
		$interview = 'interview' === ( $_POST['kind'] ?? '' );
		$layout    = $interview ? sanitize_key( wp_unslash( $_POST['layout'] ?? '' ) ) : '';
		if ( $interview && ! isset( TP_CN_LAYOUTS[ $layout ] ) ) {
			$fail( '인터뷰 형식을 골라 주세요 (질문 여러 개 / 패널)' );
		}
		// 광고 폼(1.11.0, 10/18 사용자): 인터뷰처럼 글 시작·끝에 광고 브랜드 프로필 카드를 단다. 같은 칸(pname·pinsta·pfile)을 쓴다
		$ad     = 'ad' === ( $_POST['kind'] ?? '' );
		$label  = $ad ? '광고 브랜드' : '인터뷰 대상';
		$people = ( $interview || $ad ) ? tp_cn_parse_people( wp_unslash( $_POST['pname'] ?? array() ), wp_unslash( $_POST['pinsta'] ?? array() ), $_FILES['pfile'] ?? array(), $label ) : array();
		if ( is_wp_error( $people ) ) {
			$fail( $people->get_error_message() );
		}
		// 광고 글은 브랜드 계정 링크가 핵심이라 인스타 주소는 꼭 받는다(사진은 없어도 카드는 만들어진다)
		if ( $ad && ! array_filter( array_column( $people, 'insta' ) ) ) {
			$fail( '광고 브랜드의 인스타 주소를 넣어 주세요' );
		}
		$group = tp_cn_check_zip( $file['tmp_name'], $file['name'] );
		if ( is_wp_error( $group ) ) {
			$fail( $group->get_error_message() );
		}

		// wp_handle_upload는 허용 형식 목록에 zip이 없으면 막는다 — 관리자만 쓰는 칸이라 직접 옮기고 위에서 확장자·내용을 본다.
		// 영상 카드 파일도 미디어 라이브러리가 아니라 zip 옆에 둔다: 남의 영상 원본은 사이트에 공개되면 안 되고, 셰프만 REST로 받아 간다
		$name = bin2hex( random_bytes( 16 ) ) . '.zip';
		if ( ! move_uploaded_file( $file['tmp_name'], tp_cn_dir() . '/' . $name ) ) {
			$fail( 'zip을 저장하지 못했어요. 잠시 뒤 다시 해 주세요' );
		}
		$stored = array();
		foreach ( $rows['files'] as $no => $f ) {
			$fname = bin2hex( random_bytes( 16 ) ) . '.' . $f['ext'];
			if ( ! move_uploaded_file( $f['tmp'], tp_cn_dir() . '/' . $fname ) ) {
				// 반쯤 저장된 주문을 남기지 않게 이미 옮긴 파일까지 치운다
				foreach ( array_merge( array( $name ), array_column( $stored, 'file' ) ) as $gone ) {
					unlink( tp_cn_dir() . '/' . $gone );
				}
				$fail( "{$no}번 파일을 저장하지 못했어요. 잠시 뒤 다시 해 주세요" );
			}
			$stored[ $no ] = array(
				'file' => $fname,
				'name' => $f['name'],
				'kind' => $f['kind'],
			);
		}
		foreach ( $people as $i => $p ) {
			$people[ $i ]['file'] = '';
			if ( $p['tmp'] ) {
				$pname = bin2hex( random_bytes( 16 ) ) . '.' . $p['ext'];
				if ( ! move_uploaded_file( $p['tmp'], tp_cn_dir() . '/' . $pname ) ) {
					foreach ( array_merge( array( $name ), array_column( $stored, 'file' ), array_filter( array_column( $people, 'file' ) ) ) as $gone ) {
						unlink( tp_cn_dir() . '/' . $gone );
					}
					$fail( "{$label} '{$p['name']}' 사진을 저장하지 못했어요. 잠시 뒤 다시 해 주세요" );
				}
				$people[ $i ]['file'] = $pname;
			}
			unset( $people[ $i ]['tmp'], $people[ $i ]['ext'] );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => TP_CN_TYPE,
				'post_status' => 'private',
				'post_title'  => sanitize_text_field( $group ),
			)
		);
		update_post_meta( $id, '_tp_cn_status', 'new' );
		update_post_meta( $id, '_tp_cn_zip', $name );
		update_post_meta( $id, '_tp_cn_caption', $caption );
		update_post_meta( $id, '_tp_cn_videos', $rows['videos'] );
		update_post_meta( $id, '_tp_cn_files', $stored );
		update_post_meta( $id, '_tp_cn_memo', trim( sanitize_textarea_field( wp_unslash( $_POST['memo'] ?? '' ) ) ) );
		update_post_meta( $id, '_tp_cn_kind', $interview ? 'interview' : ( $ad ? 'ad' : 'cardnews' ) );
		update_post_meta( $id, '_tp_cn_layout', $layout );
		update_post_meta( $id, '_tp_cn_people', array_values( $people ) );

		if ( ! tp_cn_ring( $id ) ) {
			// 주문은 저장됐으니 다시 쓸 필요는 없다. 목록의 "셰프 다시 부르기"로 이어서 할 수 있게 알려 준다
			$fail( '주문은 받았는데 셰프를 깨우지 못했어요. ' . get_post_meta( $id, '_tp_cn_bell_err', true ) . ' — 주문 현황에서 "셰프 다시 부르기"를 눌러 주세요' );
		}
		wp_safe_redirect( add_query_arg( 'tp_cn_ok', $id, $back ) );
		exit;
	}
);

// 저장된 영상 카드 파일 목록. 발행 뒤 지운 주문은 빈 값('' 또는 빈 배열)이라 배열인 것만 센다
function tp_cn_file_list( $id ) {
	$files = get_post_meta( $id, '_tp_cn_files', true );
	$out   = array();
	foreach ( is_array( $files ) ? $files : array() as $no => $f ) {
		$out[ (string) $no ] = array(
			'name' => $f['name'],
			'kind' => $f['kind'],
			'url'  => rest_url( "tp-cardnews/v1/orders/{$id}/files/{$no}" ),
		);
	}
	return $out;
}

// 인터뷰 대상: 이름·인스타 주소·사진 받는 주소(관리자 인증 필요, 발행 뒤엔 사진이 지워져 null)
function tp_cn_people_list( $id ) {
	$people = get_post_meta( $id, '_tp_cn_people', true );
	$out    = array();
	foreach ( is_array( $people ) ? $people : array() as $i => $p ) {
		$out[] = array(
			'name'  => $p['name'],
			'insta' => $p['insta'],
			'photo' => ! empty( $p['file'] ) ? rest_url( "tp-cardnews/v1/orders/{$id}/people/{$i}" ) : null,
		);
	}
	return $out;
}

/**
 * 주문 하나를 화면·REST에서 같이 쓰는 모양으로.
 */
function tp_cn_order( $post, $with_state = false ) {
	$id      = $post->ID;
	$draft   = (int) get_post_meta( $id, '_tp_cn_post', true );
	$changes = get_post_meta( $id, '_tp_cn_changes', true );
	$out     = array(
		'id'      => $id,
		'group'   => $post->post_title,
		'created' => get_post_time( 'c', true, $post ),
		'status'  => get_post_meta( $id, '_tp_cn_status', true ) ?: 'new',
		'caption' => get_post_meta( $id, '_tp_cn_caption', true ),
		'videos'  => (object) ( get_post_meta( $id, '_tp_cn_videos', true ) ?: array() ),
		// 영상 카드 파일: 카드 번호 → 원래 파일 이름·종류(video|image)·받는 주소(관리자 인증 필요)
		'files'   => (object) tp_cn_file_list( $id ),
		'memo'    => get_post_meta( $id, '_tp_cn_memo', true ),
		// 인터뷰 폼(1.8.0): 1.8.0 전 주문은 값이 없어서 카드뉴스로 본다
		'kind'    => get_post_meta( $id, '_tp_cn_kind', true ) ?: 'cardnews',
		'layout'  => get_post_meta( $id, '_tp_cn_layout', true ),
		'people'  => tp_cn_people_list( $id ),
		'zip'     => rest_url( "tp-cardnews/v1/orders/{$id}/zip" ),
		'post_id' => $draft ?: null,
		// 초안이 발행됐는지(탭에 발행 버튼 대신 '글 보기'를 보이려고). 지워진 초안이면 빈 값
		'post_status' => $draft ? (string) get_post_status( $draft ) : '',
		'title'   => get_post_meta( $id, '_tp_cn_title', true ),
		'changes' => is_array( $changes ) ? $changes : array(),
		'error'   => get_post_meta( $id, '_tp_cn_error', true ),
		// 요리가 끝난(완성·멈춤) 시각. 메뉴 배지와 "방금 완성" 표시가 이걸로 "아직 안 본 결과"를 센다
		'finished' => (int) max( (int) get_post_meta( $id, '_tp_cn_done_at', true ), (int) get_post_meta( $id, '_tp_cn_failed_at', true ), (int) get_post_meta( $id, '_tp_cn_question_at', true ) ),
		// 초인종 기록: "셰프 다시 부르기" 버튼을 언제 보여 줄지 정하는 데 쓴다
		'rang_at'  => (int) get_post_meta( $id, '_tp_cn_rang_at', true ),
		'bell_err' => get_post_meta( $id, '_tp_cn_bell_err', true ),
		'started'  => (int) get_post_meta( $id, '_tp_cn_working_at', true ),
		'has_zip'  => (bool) get_post_meta( $id, '_tp_cn_zip', true ),
		// 수정 요청: 지금 들어온 요청, 지난 수정들(요청 + 셰프가 손본 것), 셰프가 이어받을 작업 기록(파일 이름 → 내용)
		'feedback' => get_post_meta( $id, '_tp_cn_feedback', true ),
		'question' => get_post_meta( $id, '_tp_cn_question', true ),
		'answer'   => get_post_meta( $id, '_tp_cn_answer', true ),
		'rounds'   => get_post_meta( $id, '_tp_cn_rounds', true ) ?: array(),
	);
	// 작업 기록은 수십 KB라 셰프가 주문을 가져갈 때(상태를 골라 물을 때)만 싣는다 — 탭이 20초마다 묻는 목록엔 빼서 가볍게
	if ( $with_state ) {
		$out['state'] = (object) ( json_decode( (string) get_post_meta( $id, '_tp_cn_state', true ), true ) ?: array() );
	}
	return $out;
}

function tp_cn_orders( $status = null, $limit = 30 ) {
	$args = array(
		'post_type'      => TP_CN_TYPE,
		'post_status'    => 'private',
		'posts_per_page' => $limit,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $status ) {
		$args['meta_key']   = '_tp_cn_status';
		$args['meta_value'] = $status;
		$args['order']      = 'ASC'; // 가져가는 쪽은 먼저 들어온 주문부터
	}
	$with_state = (bool) $status;
	return array_map(
		function ( $p ) use ( $with_state ) {
			return tp_cn_order( $p, $with_state );
		},
		get_posts( $args )
	);
}

// ── REST: 사이트 밖 주방이 주문을 가져가고 결과를 적는 곳 ───────────

add_action(
	'rest_api_init',
	function () {
		$admin = function () {
			return current_user_can( 'manage_options' ); // 애플리케이션 비밀번호로 들어온 관리자만
		};
		$find  = function ( $id ) {
			$post = get_post( (int) $id );
			return ( $post && TP_CN_TYPE === $post->post_type ) ? $post : null;
		};

		register_rest_route(
			'tp-cardnews/v1',
			'/orders',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) {
					$status = $req->get_param( 'status' );
					return tp_cn_orders( isset( TP_CN_STATUS[ $status ] ) ? $status : null, 50 );
				},
			)
		);

		register_rest_route(
			'tp-cardnews/v1',
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) use ( $find ) {
					$post = $find( $req['id'] );
					if ( ! $post ) {
						return new WP_Error( 'tp_cn_none', '없는 주문이에요', array( 'status' => 404 ) );
					}
					$id = $post->ID;
					if ( null !== $req->get_param( 'status' ) ) {
						if ( ! isset( TP_CN_STATUS[ $req['status'] ] ) ) {
							return new WP_Error( 'tp_cn_status', '상태는 new·revise·working·done·failed·question·answered 중 하나예요', array( 'status' => 400 ) );
						}
						update_post_meta( $id, '_tp_cn_status', $req['status'] );
						update_post_meta( $id, '_tp_cn_' . $req['status'] . '_at', time() );
						// 수정 요청을 끝냈으면 그 요청과 손본 것을 지난 수정 목록에 쌓고 요청 칸을 비운다
						$feedback = get_post_meta( $id, '_tp_cn_feedback', true );
						if ( 'done' === $req['status'] && $feedback ) {
							$rounds   = get_post_meta( $id, '_tp_cn_rounds', true ) ?: array();
							$rounds[] = array(
								'feedback' => $feedback,
								'changes'  => is_array( $req->get_param( 'changes' ) ) ? array_values( array_map( 'sanitize_text_field', $req['changes'] ) ) : array(),
								'at'       => time(),
							);
							update_post_meta( $id, '_tp_cn_rounds', $rounds );
							delete_post_meta( $id, '_tp_cn_feedback' );
							$revised = true;
						}
					}
					// 셰프의 작업 기록: 다음 수정 때 빈 컴퓨터에서 이어받는다. 글 HTML까지 수십 KB라 메타 하나에 JSON으로
					if ( is_array( $req->get_param( 'state' ) ) ) {
						update_post_meta( $id, '_tp_cn_state', wp_slash( wp_json_encode( $req['state'], JSON_UNESCAPED_UNICODE ) ) );
					}
					if ( null !== $req->get_param( 'post_id' ) ) {
						update_post_meta( $id, '_tp_cn_post', (int) $req['post_id'] );
					}
					if ( null !== $req->get_param( 'title' ) ) {
						update_post_meta( $id, '_tp_cn_title', sanitize_text_field( $req['title'] ) );
					}
					// 처음 요리 때 손본 것만 여기에. 수정 때 손본 것은 위에서 그 회차 기록에 들어갔다
					if ( is_array( $req->get_param( 'changes' ) ) && empty( $revised ) ) {
						update_post_meta( $id, '_tp_cn_changes', array_values( array_map( 'sanitize_text_field', $req['changes'] ) ) );
					}
					if ( null !== $req->get_param( 'error' ) ) {
						update_post_meta( $id, '_tp_cn_error', sanitize_text_field( $req['error'] ) );
					}
					if ( null !== $req->get_param( 'question' ) ) {
						update_post_meta( $id, '_tp_cn_question', sanitize_textarea_field( $req['question'] ) );
						delete_post_meta( $id, '_tp_cn_answer' );
					}
					// 질문에 답을 받아 끝까지 만들었으면 질문·답은 비운다(셰프가 손본 것 목록에 남긴다)
					if ( 'done' === $req->get_param( 'status' ) ) {
						delete_post_meta( $id, '_tp_cn_question' );
						delete_post_meta( $id, '_tp_cn_answer' );
					}
					return tp_cn_order( get_post( $id ) );
				},
			)
		);

		// zip은 공개 주소로 열어 두지 않고, 관리자 인증을 거쳐 여기서 내려준다
		register_rest_route(
			'tp-cardnews/v1',
			'/orders/(?P<id>\d+)/zip',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) use ( $find ) {
					$post = $find( $req['id'] );
					$path = $post ? tp_cn_dir() . '/' . get_post_meta( $post->ID, '_tp_cn_zip', true ) : '';
					if ( ! $post || ! is_file( $path ) ) {
						return new WP_Error( 'tp_cn_none', 'zip이 없어요', array( 'status' => 404 ) );
					}
					header( 'Content-Type: application/zip' );
					header( 'Content-Length: ' . filesize( $path ) );
					readfile( $path );
					exit;
				},
			)
		);

		// 영상 카드 파일도 zip처럼 관리자 인증을 거쳐서만 내려준다
		register_rest_route(
			'tp-cardnews/v1',
			'/orders/(?P<id>\d+)/files/(?P<no>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) use ( $find ) {
					$post  = $find( $req['id'] );
					$files = $post ? get_post_meta( $post->ID, '_tp_cn_files', true ) : '';
					$f     = is_array( $files ) ? ( $files[ (string) (int) $req['no'] ] ?? null ) : null;
					$path  = $f ? tp_cn_dir() . '/' . $f['file'] : '';
					if ( ! $f || ! is_file( $path ) ) {
						return new WP_Error( 'tp_cn_none', '파일이 없어요', array( 'status' => 404 ) );
					}
					header( 'Content-Type: application/octet-stream' );
					header( 'Content-Length: ' . filesize( $path ) );
					readfile( $path );
					exit;
				},
			)
		);

		// 인터뷰 대상 사진도 같은 방식으로 (번호 = 대상 줄 순서, 0부터)
		register_rest_route(
			'tp-cardnews/v1',
			'/orders/(?P<id>\d+)/people/(?P<no>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function ( $req ) use ( $find ) {
					$post   = $find( $req['id'] );
					$people = $post ? get_post_meta( $post->ID, '_tp_cn_people', true ) : '';
					$p      = is_array( $people ) ? ( $people[ (int) $req['no'] ] ?? null ) : null;
					$path   = ! empty( $p['file'] ) ? tp_cn_dir() . '/' . $p['file'] : '';
					if ( ! $path || ! is_file( $path ) ) {
						return new WP_Error( 'tp_cn_none', '사진이 없어요', array( 'status' => 404 ) );
					}
					header( 'Content-Type: application/octet-stream' );
					header( 'Content-Length: ' . filesize( $path ) );
					readfile( $path );
					exit;
				},
			)
		);
	}
);

// ── 탭 화면 ──────────────────────────────────────────────

/*
 * 알림 B(10/17 사용자 결정 A+B): wp-admin 어느 화면에서든 메뉴에 "아직 안 본 결과" 수를 배지로 (업데이트 수가 뜨는 것과 같은 모양).
 * "봤다" = 사람마다 도우미 탭을 마지막으로 연 시각(사용자 메타). 탭을 여는 순간 배지가 사라지고,
 * 그 전 시각은 남겨 두었다가 그 뒤에 끝난 주문에 "방금 완성" 표시를 붙인다.
 */
add_action(
	'admin_menu',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid  = get_current_user_id();
		$seen = (int) get_user_meta( $uid, '_tp_cn_seen', true );
		$on   = isset( $_GET['page'] ) && TP_CN_SLUG === $_GET['page'];
		$GLOBALS['tp_cn_prev_seen'] = $seen;
		if ( $on ) {
			update_user_meta( $uid, '_tp_cn_seen', time() );
		}
		$unseen = $on ? 0 : count(
			array_filter(
				tp_cn_orders(),
				function ( $o ) use ( $seen ) {
					return $o['finished'] > $seen;
				}
			)
		);
		$title = '카드뉴스 도우미' . ( $unseen ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $unseen ) : '' );
		add_menu_page( '트란시스 카드뉴스 도우미', $title, 'manage_options', TP_CN_SLUG, 'tp_cn_page', 'dashicons-pets', 3 );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'toplevel_page_' . TP_CN_SLUG !== $hook ) {
			return;
		}
		// 일정표와 같은 글꼴(둘 다 SIL OFL 1.1 — 10/17 라이선스 원문 확인)
		wp_enqueue_style( 'tp-cn-fonts', 'https://fonts.googleapis.com/css2?family=Jua&family=IBM+Plex+Sans+KR:wght@400;500;700&display=swap', array(), null );
	}
);

function tp_cn_page() {
	$msg = array();
	if ( isset( $_GET['tp_cn_err'] ) ) {
		$msg = array( 'bad', sanitize_text_field( wp_unslash( $_GET['tp_cn_err'] ) ) );
	} elseif ( isset( $_GET['tp_cn_info'] ) ) {
		$info = sanitize_text_field( wp_unslash( $_GET['tp_cn_info'] ) );
		$msg  = array( 'ok', 'bell' === $info ? '초인종 설정을 저장했어요. 다음 주문부터 셰프가 바로 와요.' : $info );
	} elseif ( isset( $_GET['tp_cn_ok'] ) && 'new' === get_post_meta( (int) $_GET['tp_cn_ok'], '_tp_cn_status', true ) ) {
		// 아직 접수 상태일 때만. 요리가 시작·끝난 뒤엔 목록이 상태를 말해 주니 "곧 시작해요"가 남아 있으면 헷갈린다
		$msg = array( 'ok', '주문 받았어요! 셰프 트란시스가 곧 요리를 시작해요.' );
	} elseif ( isset( $_GET['tp_cn_rev'] ) && 'revise' === get_post_meta( (int) $_GET['tp_cn_rev'], '_tp_cn_status', true ) ) {
		$msg = array( 'ok', '수정 요청 받았어요! 셰프 트란시스가 같은 초안을 고쳐 둘게요.' );
	}
	tp_cn_render(
		tp_cn_orders(),
		$msg,
		array(
			'face'   => plugins_url( 'mascot.jpg', __FILE__ ),
			'rest'   => plugins_url( 'mascot-rest.png', __FILE__ ),
			'pcap'   => plugins_url( 'profile-capture.mp4', __FILE__ ),
			// 피그마 플러그인(tools/figma-plugin)을 묶은 zip. 플러그인을 고치면 이 zip도 다시 묶는다
			'fplug'  => plugins_url( 'transis-figma-plugin.zip', __FILE__ ),
			'action' => admin_url( 'admin-post.php' ),
			'nonce'  => wp_nonce_field( 'tp_cn_order', '_wpnonce', true, false ),
			// 알림 A: 탭이 스스로 상태를 확인할 때 쓰는 주소와 로그인 확인 값(쿠키 인증에는 wp_rest nonce가 필요)
			'poll'   => rest_url( 'tp-cardnews/v1/orders' ),
			'rnonce' => wp_create_nonce( 'wp_rest' ),
			'seen'   => isset( $GLOBALS['tp_cn_prev_seen'] ) ? (int) $GLOBALS['tp_cn_prev_seen'] : time(),
			'bell'   => get_option( TP_CN_BELL, array() ),
			'now'    => time(),
			// 한 번 보낼 수 있는 크기(서버 upload_max_filesize·post_max_size 중 작은 값). 탭이 미리 막고 안내한다
			'max'    => wp_max_upload_size(),
		)
	);
}

/**
 * 이 주문에 "셰프 다시 부르기"가 필요한지와 그 이유. 필요 없으면 null.
 * 기다리는 시간: 초인종 뒤 셰프가 주문을 가져가기까지 보통 1~2분, 요리는 10분 안팎(711 실측 전 추정)이라 넉넉히.
 */
function tp_cn_recall_reason( $o, $now ) {
	$waiting = in_array( $o['status'], array( 'new', 'revise', 'answered' ), true );
	if ( $waiting && $o['bell_err'] ) {
		return $o['bell_err'];
	}
	if ( $waiting && $o['rang_at'] && $now - $o['rang_at'] > 15 * MINUTE_IN_SECONDS ) {
		return '초인종을 눌렀는데 15분이 지나도 셰프가 주문을 가져가지 않았어요.';
	}
	if ( 'working' === $o['status'] && $o['started'] && $now - $o['started'] > 45 * MINUTE_IN_SECONDS ) {
		return '요리가 45분 넘게 끝나지 않아요. 셰프가 중간에 멈췄을 수 있어요.';
	}
	if ( 'failed' === $o['status'] && ( $o['has_zip'] || $o['feedback'] ) ) {
		return ''; // 멈춘 이유는 이미 위에 나온다 — 버튼만
	}
	return null;
}

/**
 * 화면 그리기. 주문 목록·알림·주소를 넘겨받기만 해서, 워드프레스 없이도 가짜 주문으로 그려 볼 수 있다(시안·검증용).
 */
function tp_cn_render( $orders, $msg, $u ) {
	$fmt = function ( $iso ) {
		return $iso ? wp_date( 'n월 j일 H:i', strtotime( $iso ) ) : '';
	};
	?>
<div class="tpcn" id="tpcn" data-poll="<?php echo esc_url( $u['poll'] ); ?>" data-nonce="<?php echo esc_attr( $u['rnonce'] ); ?>">
<style>
/* 셰프 트란시스의 주방(10/17 사용자: "요리사 컨셉, 빵모자, 테마·배경도"). 둥근 패널·파스텔 칩·Jua 제목은 일정표와 같은 말투,
   색은 주방 쪽으로: 크림 바탕, 갈색 글자, 상태 = 주문서 파랑 · 버터(요리 중) · 바질(완성) · 토마토(멈춤) */
body.toplevel_page_tp-cardnews #wpcontent, body.toplevel_page_tp-cardnews #wpfooter { background: #fbf4e6; }
.tpcn { --bg: #fdf8ee; --card: #fffdf8; --ink: #3b2f2a; --muted: #8c7f73; --line: #efe3cf; --line-strong: #dcc9ab;
  --new: #3a86ff; --new-soft: #dde9ff; --revise: #7a5af8; --revise-soft: #ebe5ff; --working: #d08c12; --working-soft: #fbedc9;
  --done: #2f9e6b; --done-soft: #d9f1e3; --failed: #e4572e; --failed-soft: #fde3da; --fresh: #b8572e; --fresh-soft: #fbe6da;
  --tomato: #e4572e;
  --font-display: "Jua", "Apple SD Gothic Neo", "Malgun Gothic", sans-serif;
  --font-body: "IBM Plex Sans KR", "Apple SD Gothic Neo", "Malgun Gothic", system-ui, sans-serif;
  max-width: 980px; margin: 18px 20px 40px 0; display: grid; gap: 18px; color: var(--ink); font-family: var(--font-body); font-size: 14px; line-height: 1.55; word-break: keep-all; }
.tpcn * { box-sizing: border-box; }
.tpcn [hidden] { display: none !important; }
/* 맨 위 행주 띠: 토마토색 깅엄 체크 */
.tpcn-towel { height: 12px; border-radius: 999px; opacity: .85;
  background: repeating-linear-gradient(90deg, rgba(228, 87, 46, .55) 0 12px, transparent 12px 24px), repeating-linear-gradient(0deg, rgba(228, 87, 46, .55) 0 6px, #fff6ea 6px 12px); }
.tpcn-head { position: relative; display: flex; align-items: center; gap: 14px; padding-top: 18px; }
.tpcn-face { position: relative; flex: none; border: 0; padding: 0; background: none; border-radius: 50%; cursor: pointer; }
.tpcn-face img { display: block; width: 56px; height: 56px; border-radius: 50%; object-fit: cover; box-shadow: 0 0 0 3px var(--card), 0 4px 12px rgba(59, 47, 42, .18); }
.tpcn-face:focus-visible { outline: 2px solid var(--new); outline-offset: 2px; }
/* 셰프 모자: 사진은 그대로 두고 그림을 머리 위에 얹는다 */
.tpcn-hat { position: absolute; left: 6px; top: -30px; width: 46px; height: auto; transform: rotate(-10deg); pointer-events: none; filter: drop-shadow(0 2px 2px rgba(59, 47, 42, .15)); }
/* 오른쪽 위 반짝이: 눌러 볼 수 있다는 작은 신호(일정표와 같음) */
.tpcn-spark { position: absolute; top: 0; right: -10px; font-size: 16px; line-height: 1; pointer-events: none; }
.tpcn-head h1 { margin: 0; padding: 0; font-family: var(--font-display); font-weight: 400; font-size: 27px; line-height: 1.15; color: var(--ink); }
.tpcn-head p { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
/* 얼굴을 누르면 쓰는 법이 말풍선으로 */
.tpcn-bubble { position: absolute; z-index: 5; top: 86px; left: 0; width: min(380px, 100%); margin: 0; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); font-size: 13px; box-shadow: 0 8px 24px rgba(20, 22, 40, .18); }
.tpcn-bubble::before { content: ""; position: absolute; top: -7px; left: 20px; width: 12px; height: 12px; background: var(--card); border: solid var(--line); border-width: 1px 0 0 1px; transform: rotate(45deg); border-radius: 2px; }
.tpcn-bubble ol { margin: 0; padding-left: 18px; }
.tpcn-bubble li { margin: 2px 0; }
@media (prefers-reduced-motion: no-preference) {
  .tpcn-spark { animation: tpcn-twinkle 2.4s ease-in-out infinite; }
  .tpcn-face.wag { animation: tpcn-wag .5s ease; }
  @keyframes tpcn-twinkle { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .35; transform: scale(.75); } }
  @keyframes tpcn-wag { 25% { transform: rotate(-10deg); } 75% { transform: rotate(10deg); } }
}
.tpcn-msg { margin: 0; padding: 12px 16px; border-radius: 14px; font-weight: 700; }
.tpcn-msg.ok { background: var(--done-soft); color: #13784f; }
.tpcn-msg.bad { background: var(--failed-soft); color: #b42333; }
.tpcn-panel { background: var(--card); border-radius: 22px; padding: 18px; border: 1px solid var(--line); box-shadow: 0 1px 2px rgba(59, 47, 42, .04), 0 8px 24px rgba(59, 47, 42, .06); min-width: 0; }
.tpcn-panel h2 { margin: 0 0 14px; font-family: var(--font-display); font-weight: 400; font-size: 20px; display: flex; align-items: baseline; gap: 8px; color: var(--ink); }
.tpcn-panel h2 small { font-family: var(--font-body); font-size: 12px; color: var(--muted); font-weight: 500; }
.tpcn-form { display: grid; gap: 14px; }
.tpcn-field label { display: block; margin-bottom: 6px; font-weight: 700; }
.tpcn-field label em { margin-left: 4px; font-style: normal; font-weight: 500; font-size: 12px; color: var(--muted); }
/* 필수 칸: 노란 형광펜을 아래쪽 절반에만 칠한 것처럼 (10/17 사용자) */
.tpcn-field label em.tpcn-must { padding: 0 3px; font-weight: 700; color: var(--ink); background: linear-gradient(transparent 45%, #ffe45c 45%, #ffe45c 92%, transparent 92%); }
.tpcn-field .tpcn-hint { margin: 6px 0 0; font-size: 12px; color: var(--muted); }
.tpcn-field .tpcn-hint b { color: var(--ink); }
.tpcn-how { margin-top: 6px; font-size: 12.5px; }
/* 10/18 사용자: 접을 수 있다는 게 보여야 해서 글자 대신 알약 버튼 + 오른쪽에 '접기 ▴ / 펼치기 ▾' */
.tpcn-how summary { display: inline-flex; align-items: center; gap: 8px; padding: 5px 12px; border-radius: 999px; background: var(--fresh-soft); color: var(--fresh); font-weight: 700; cursor: pointer; list-style: none; user-select: none; }
.tpcn-how summary::-webkit-details-marker { display: none; }
.tpcn-how summary::after { content: "펼치기 ▾"; padding-left: 8px; border-left: 1px solid currentColor; font-size: 11.5px; font-weight: 500; }
.tpcn-how[open] summary::after { content: "접기 ▴"; }
.tpcn-how summary:hover { background: #f7d5c4; }
.tpcn-how summary:focus-visible { outline: 2px solid var(--fresh); outline-offset: 2px; }
.tpcn-how ul { margin: 8px 0 0; padding: 10px 14px 10px 28px; border-radius: 12px; background: var(--bg); border: 1px dashed var(--line-strong); }
.tpcn-how li { margin: 3px 0; }
.tpcn-plug { margin-top: 8px; padding: 10px 14px; border-radius: 12px; background: var(--bg); border: 1px dashed var(--line-strong); }
.tpcn-plug ol { margin: 8px 0 0; padding-left: 18px; }
.tpcn-plug li { margin: 3px 0; }
.tpcn-dl { display: inline-block; padding: 6px 14px; border-radius: 999px; background: var(--ink); color: #fff; font-weight: 700; text-decoration: none; }
.tpcn-dl:hover, .tpcn-dl:focus { color: #fff; background: #5a4a42; }
.tpcn-cap { display: flex; gap: 14px; align-items: center; margin-top: 10px; padding: 10px; border-radius: 12px; background: var(--bg); border: 1px dashed var(--line-strong); font-size: 12.5px; }
.tpcn-capvid { flex: none; width: 150px; height: 150px; border-radius: 10px; background: #0d1014; }
.tpcn-cap ol { margin: 4px 0 0; padding-left: 18px; }
.tpcn-cap li { margin: 2px 0; }
.tpcn-cap code { padding: 1px 6px; border-radius: 6px; background: var(--card); border: 1px solid var(--line); font-size: 12px; }
.tpcn-how code { padding: 1px 6px; border-radius: 6px; background: var(--card); border: 1px solid var(--line); font-size: 12px; }
.tpcn textarea { width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--bg); font: inherit; color: var(--ink); resize: vertical; }
.tpcn textarea:focus { border-color: var(--new); outline: none; box-shadow: 0 0 0 3px var(--new-soft); }
/* zip 넣는 칸: 점선 상자. 고르면 파일 이름이 상자 안에 뜬다 */
.tpcn-drop { position: relative; display: flex; align-items: center; gap: 12px; padding: 16px; border: 2px dashed var(--line-strong); border-radius: 16px; background: var(--bg); cursor: pointer; }
.tpcn-drop:hover, .tpcn-drop.is-over { border-color: var(--new); background: var(--new-soft); }
.tpcn-drop.has-file { border-style: solid; border-color: var(--done); background: var(--done-soft); }
.tpcn-drop input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.tpcn-drop .tpcn-ico { flex: none; width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: var(--card); font-size: 20px; }
.tpcn-drop b { display: block; font-size: 14px; }
.tpcn-drop span { font-size: 12px; color: var(--muted); }
/* 영상 카드 줄: 카드 번호 · 파일 · 주소 · 지우기 */
.tpcn-vrows { display: grid; gap: 8px; }
.tpcn-vrow { display: grid; grid-template-columns: 72px minmax(0, 200px) minmax(0, 1fr) 32px; gap: 8px; align-items: center; }
.tpcn-vrow input[type=number], .tpcn-vrow input[type=url] { width: 100%; padding: 8px 10px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--bg); font: inherit; color: var(--ink); }
.tpcn-vrow input:focus { border-color: var(--new); outline: none; box-shadow: 0 0 0 3px var(--new-soft); }
.tpcn-vfile { position: relative; display: block; padding: 8px 10px; border: 1.5px dashed var(--line-strong); border-radius: 12px; background: var(--bg); font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
.tpcn-vfile.has-file { border-style: solid; border-color: var(--done); background: var(--done-soft); }
.tpcn-vfile input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.tpcn-vdel { width: 32px; height: 32px; border: 0; border-radius: 50%; background: none; color: var(--muted); font-size: 18px; cursor: pointer; }
.tpcn-vdel:hover { background: var(--failed-soft); color: var(--failed); }
.tpcn-vadd { margin-top: 8px; padding: 4px 12px; border: 1.5px solid var(--line); border-radius: 999px; background: var(--card); color: var(--ink); font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
/* 주문 종류 전환: 알약 두 개 중 하나가 토마토색으로 */
.tpcn-kind { display: inline-flex; justify-self: start; gap: 4px; padding: 4px; border-radius: 999px; background: var(--bg); border: 1.5px solid var(--line); }
.tpcn-kind input, .tpcn-layouts input { position: absolute; opacity: 0; pointer-events: none; }
.tpcn-kind span { display: block; padding: 7px 18px; border-radius: 999px; font-weight: 700; cursor: pointer; }
.tpcn-kind input:checked + span { background: var(--tomato); color: #fff; }
.tpcn-kind input:focus-visible + span, .tpcn-layouts input:focus-visible + span { box-shadow: 0 0 0 3px var(--new-soft); }
/* 인터뷰 칸: 형식 카드 두 장 + 대상 줄 */
.tpcn-iv { display: grid; gap: 16px; padding: 14px; border-radius: 16px; border: 1.5px dashed var(--line-strong); }
.tpcn-iv[hidden] { display: none; }
.tpcn-layouts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.tpcn-layouts label { position: relative; margin: 0; font-weight: 500; }
.tpcn-layouts span { display: block; height: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--bg); font-size: 12.5px; color: var(--muted); cursor: pointer; }
.tpcn-layouts span b { display: block; margin-bottom: 2px; font-size: 14px; color: var(--ink); }
.tpcn-layouts input:checked + span { border-color: var(--tomato); background: var(--card); box-shadow: 0 0 0 3px rgba(226, 88, 50, .15); }
.tpcn-prows { display: grid; gap: 8px; }
.tpcn-prow { display: grid; grid-template-columns: minmax(0, 140px) minmax(0, 1fr) minmax(0, 160px) 32px; gap: 8px; align-items: center; }
.tpcn-prow input[type=text], .tpcn-prow input[type=url] { width: 100%; padding: 8px 10px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--bg); font: inherit; color: var(--ink); }
.tpcn-prow input:focus { border-color: var(--new); outline: none; box-shadow: 0 0 0 3px var(--new-soft); }
.tpcn-tag { display: inline-block; margin-right: 6px; padding: 1px 8px; border-radius: 999px; background: var(--bg); border: 1px solid var(--line); font-size: 11px; font-weight: 700; vertical-align: 2px; }
.tpcn-tag.is-iv { background: #fde9e2; border-color: #f4b8a5; color: #a8401f; }
/* 광고 딱지 이름표는 is-ad를 쓰면 안 된다: 광고 차단기(EasyList ##.is-ad)가 숨겨 버린다(10/18 896 주문에서 딱지가 안 보임) */
.tpcn-tag.is-sponsor { background: #e6effd; border-color: #a9c4f2; color: #1f4fa8; }
.tpcn-go { justify-self: start; padding: 11px 22px; border: 0; border-radius: 999px; background: var(--tomato); color: #fff; font-family: var(--font-display); font-size: 17px; cursor: pointer; box-shadow: 0 4px 0 #b8401d; }
.tpcn-go:hover { background: #ec6a43; }
.tpcn-go:active { transform: translateY(2px); box-shadow: 0 2px 0 #b8401d; }
.tpcn-go:disabled { opacity: .5; cursor: wait; }
.tpcn-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 10px; }
.tpcn-item { display: grid; grid-template-columns: 44px 1fr auto; gap: 4px 12px; align-items: start; padding: 12px; border: 1px solid var(--line); border-radius: 16px; }
.tpcn-no { min-width: 44px; height: 36px; padding: 0 6px; border-radius: 12px; display: grid; place-items: center; font-family: var(--font-display); font-size: 15px; color: #fff; background: var(--s); }
.tpcn-item h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--ink); }
.tpcn-item .tpcn-when { margin: 0; font-size: 12px; color: var(--muted); }
.tpcn-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 700; white-space: nowrap; background: var(--s-soft); color: var(--s); }
.tpcn-chip::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.tpcn-item.is-working .tpcn-chip::before { animation: tpcn-blink 1.2s ease-in-out infinite; }
/* 마지막으로 탭을 본 뒤에 끝난 주문: 테두리를 상태 색으로, 이름 옆에 작은 표 */
.tpcn-item.is-fresh { border-color: var(--s); box-shadow: 0 0 0 3px var(--s-soft); }
.tpcn-fresh { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: var(--s); color: #fff; font-size: 11px; font-weight: 700; vertical-align: 2px; }
.tpcn-live-btn { margin-left: 8px; padding: 3px 12px; border: 0; border-radius: 999px; background: var(--done); color: #fff; font: inherit; font-size: 12.5px; cursor: pointer; }
@keyframes tpcn-blink { 50% { opacity: .2; } }
.s-new { --s: var(--new); --s-soft: var(--new-soft); }
.s-revise { --s: var(--revise); --s-soft: var(--revise-soft); }
.s-working { --s: var(--working); --s-soft: var(--working-soft); }
/* 고쳐 주세요: 완성된 주문 아래 메모 칸 + 지난 수정 기록 */
.tpcn-revise { margin-top: 10px; display: grid; gap: 6px; }
.tpcn-revise textarea { font-size: 13px; }
.tpcn-revise button { justify-self: start; padding: 6px 16px; border: 0; border-radius: 999px; background: var(--revise); color: #fff; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
.tpcn-rounds { margin: 8px 0 0; padding: 0; list-style: none; display: grid; gap: 6px; }
.tpcn-rounds > li { padding: 8px 10px; border-radius: 10px; background: var(--bg); border: 1px dashed var(--line-strong); }
.tpcn-rounds b { color: var(--revise); }
.tpcn-rounds ul { margin: 4px 0 0; padding-left: 18px; color: var(--muted); }
.s-done { --s: var(--done); --s-soft: var(--done-soft); }
.s-question, .s-answered { --s: #d6336c; --s-soft: #fde2ec; }
.tpcn-q { padding: 8px 10px; border-radius: 10px; background: var(--s-soft); }
.tpcn-answer button { background: #d6336c; }
.s-failed { --s: var(--failed); --s-soft: var(--failed-soft); }
.tpcn-body { grid-column: 2 / -1; font-size: 13px; }
.tpcn-body p { margin: 4px 0 0; }
.tpcn-body .tpcn-title { font-weight: 700; }
.tpcn-links { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.tpcn-links a { display: inline-block; padding: 5px 12px; border-radius: 999px; border: 1.5px solid var(--line); color: var(--ink); text-decoration: none; font-weight: 700; font-size: 12.5px; }
.tpcn-links a.main { background: var(--ink); border-color: var(--ink); color: #fff; }
.tpcn-links a:hover { border-color: var(--muted); }
/* 발행된 주문: 버터색 카드(10/18 사용자, 시안 1018-1 P3). 번호 상자·딱지도 같은 색, 글 보기 버튼은 흰 바탕 */
.tpcn-item.is-pub { --s: #d08c12; --s-soft: #fbedc9; background: linear-gradient(135deg, #fff1d6, #fff8ea); border-color: #f2d394; }
.tpcn-chip.is-pub { background: #c27c0e; color: #fff; }
.tpcn-item.is-pub .tpcn-links a.main { background: #fff; color: var(--ink); border-color: var(--ink); }
/* 셰프가 손본 것의 중요도(시안 1018-1 C): 확인 항목은 늘 펼친 상자로, ❗는 분홍 띠 */
.tpcn-check { margin-top: 8px; padding: 10px 12px; border-radius: 12px; background: #fff3d6; border: 1.5px solid #f2cf7c; }
.tpcn-check > b { display: block; margin-bottom: 4px; }
.tpcn-check ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 4px; }
.tpcn-check li { padding: 0 8px; }
.tpcn-check li.lv3 { padding: 4px 8px; border-radius: 8px; background: #ffdbe4; color: #9b1c3d; font-weight: 700; }
.tpcn-changes li.lv2, .tpcn-changes li.lv3 { color: var(--ink); font-weight: 700; }
.tpcn-publish { margin: 0; }
.tpcn-publish button { padding: 5px 14px; border: 0; border-radius: 999px; background: var(--tomato); color: #fff; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 0 #b8401d; }
.tpcn-publish button:hover { background: #ec6a43; }
.tpcn-body details { margin-top: 8px; }
.tpcn-body summary { cursor: pointer; color: var(--fresh); font-weight: 700; }
.tpcn-body details ul { margin: 6px 0 0; padding-left: 18px; }
.tpcn-recall { margin-top: 8px; }
.tpcn-recall p { margin: 0 0 6px; color: var(--muted); }
.tpcn-recall button { padding: 5px 14px; border: 0; border-radius: 999px; background: var(--tomato); color: #fff; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
.tpcn-bell summary { cursor: pointer; font-family: var(--font-display); font-size: 18px; }
.tpcn-bell summary small { margin-left: 6px; font-family: var(--font-body); font-size: 12px; color: var(--muted); }
.tpcn-bell form { display: grid; gap: 12px; margin-top: 12px; }
.tpcn-bell input[type=url], .tpcn-bell input[type=password] { width: 100%; padding: 9px 12px; border: 1.5px solid var(--line); border-radius: 12px; background: var(--bg); font: inherit; }
/* 주문 지우기: 눈에 덜 띄게 오른쪽 아래 작은 글씨 */
.tpcn-del { margin-top: 8px; text-align: right; }
.tpcn-del button { padding: 0; border: 0; background: none; color: var(--muted); font: inherit; font-size: 12px; cursor: pointer; }
.tpcn-del button:hover { color: var(--failed); text-decoration: underline; }
.tpcn-empty { display: grid; justify-items: center; gap: 6px; padding: 18px 0 8px; color: var(--muted); text-align: center; }
.tpcn-empty img { width: 180px; height: auto; }
@media (max-width: 782px) {
  .tpcn-cap { flex-direction: column; align-items: flex-start; }
  .tpcn { margin-right: 10px; }
  .tpcn-item { grid-template-columns: 44px 1fr; }
  .tpcn-item .tpcn-chip { grid-column: 2; justify-self: start; }
  /* 폰: 번호·파일·지우기 한 줄, 주소는 아래 줄 전체 */
  .tpcn-vrow { grid-template-columns: 64px minmax(0, 1fr) 32px; }
  .tpcn-vrow input[type=url] { grid-column: 1 / -1; grid-row: 2; }
  /* 폰: 이름·사진·지우기 한 줄, 인스타 주소는 아래 줄 전체. 형식 카드는 세로로 */
  .tpcn-prow { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 32px; }
  .tpcn-prow input[type=url] { grid-column: 1 / -1; grid-row: 2; }
  .tpcn-layouts { grid-template-columns: 1fr; }
}
/* ── 스티커 테마(10/18 사용자, 시안 1018-8 A): 땡땡이 바탕 + 굵은 갈색 테두리·블록 그림자 스티커 패널,
   10/18 사용자: 주문 카드도 그라데이션 없이 모두 A 모양으로.
   앞의 기본 모양 위에 덮어쓰는 묶음이라 .tpcn을 앞에 붙여 이긴다(테마만 통째로 갈아 끼울 수 있게) ── */
body.toplevel_page_tp-cardnews #wpcontent { background: #fbf4e6 radial-gradient(#ecd3b0 1.3px, transparent 1.5px) 0 0 / 18px 18px; }
.tpcn .tpcn-towel { height: 16px; border: 2px solid var(--ink); }
.tpcn .tpcn-panel { border: 2.5px solid var(--ink); border-radius: 26px; box-shadow: 6px 6px 0 var(--ink); background: var(--card); }
.tpcn .tpcn-panel h2 { font-size: 25px; }
.tpcn .tpcn-panel:has(#tpcn-form) h2::before { content: "🧾"; margin-right: 2px; }
.tpcn .tpcn-panel:has(.tpcn-list) h2::before { content: "🍳"; margin-right: 2px; }
.tpcn .tpcn-kind { border: 2px solid var(--ink); box-shadow: 3px 3px 0 var(--ink); background: #fff; }
.tpcn .tpcn-kind input:checked + span { box-shadow: inset 0 -3px 0 rgba(0, 0, 0, .18); }
.tpcn .tpcn-drop { border: 2.5px dashed var(--ink); background: #fff8ec; }
.tpcn .tpcn-form textarea, .tpcn .tpcn-form input[type=text], .tpcn .tpcn-form input[type=url], .tpcn .tpcn-form input[type=number] { border: 2px solid var(--ink); border-radius: 14px; background: #fff; }
.tpcn .tpcn-go { border: 2.5px solid var(--ink); border-radius: 16px; box-shadow: 5px 5px 0 var(--ink); }
.tpcn .tpcn-go:active { transform: translate(3px, 3px); box-shadow: 2px 2px 0 var(--ink); }
.tpcn .tpcn-tag { border: 1.5px solid var(--ink); }
/* 주문 현황도 스티커: 흰 카드 + 갈색 테두리 + 상태 색 블록 그림자, 번호는 기울어진 동그라미 도장 */
.tpcn .tpcn-item { border: 2px solid var(--ink); border-radius: 20px; box-shadow: 4px 4px 0 var(--s); background: #fff; }
.tpcn .tpcn-item.is-pub { background: #fff3d6; border-color: var(--ink); }
.tpcn .tpcn-item.is-fresh { border-color: var(--ink); box-shadow: 4px 4px 0 var(--s), 0 0 0 4px var(--s-soft); }
.tpcn .tpcn-no { width: 48px; height: 48px; min-width: 48px; border-radius: 50%; border: 2px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); transform: rotate(-8deg); font-size: 16px; }
.tpcn .tpcn-chip { border: 2px solid currentColor; padding: 2px 10px; }
.tpcn .tpcn-chip.is-pub { border-color: var(--ink); }
.tpcn .tpcn-links a, .tpcn .tpcn-publish button, .tpcn .tpcn-revise button, .tpcn .tpcn-recall button { border: 2px solid var(--ink); box-shadow: 3px 3px 0 var(--ink); }
.tpcn .tpcn-check { border: 2px solid var(--ink); box-shadow: 3px 3px 0 #f2cf7c; }
.tpcn .tpcn-revise textarea { border: 2px solid var(--ink); border-radius: 14px; background: #fff; }
</style>

<div class="tpcn-towel" aria-hidden="true"></div>
<header class="tpcn-head">
	<button type="button" class="tpcn-face" id="tpcn-face" aria-label="쓰는 법 보기" aria-controls="tpcn-bubble">
		<img src="<?php echo esc_url( $u['face'] ); ?>" width="56" height="56" alt="셰프 모자를 쓴 트란시스 마스코트, 허스키 인형">
		<svg class="tpcn-hat" viewBox="0 0 64 50" aria-hidden="true"><path d="M15 35C4 35 2 20 13 18 12 6 27 2 32 10c5-8 21-5 19 8 11 2 9 17-2 17Z" fill="#fff" stroke="#dcc9ab" stroke-width="2.2" stroke-linejoin="round"/><rect x="15" y="32" width="34" height="13" rx="3.5" fill="#fff" stroke="#dcc9ab" stroke-width="2.2"/><path d="M24 34v9M32 34v9M40 34v9" stroke="#efe3cf" stroke-width="2.2" stroke-linecap="round"/></svg>
		<span class="tpcn-spark" aria-hidden="true">✨</span>
	</button>
	<div>
		<h1>트란시스 카드뉴스 도우미</h1>
		<p>피그마 zip을 주문서로 넣어 주면, 셰프 트란시스가 사이트 초안으로 요리해 둘게요</p>
	</div>
	<div class="tpcn-bubble" id="tpcn-bubble" role="status" hidden>
		<ol>
			<li>피그마에서 카드 묶음을 선택하고 플러그인 <b>트란시스 카드뉴스 읽기</b> → zip 내려받기</li>
			<li>여기에 zip · 인스타 캡션 · (있으면) 영상 카드 넣고 <b>주문 넣기</b></li>
			<li><b>완성</b>이 뜨면 미리보기로 맛보고, 셰프가 손본 것 확인한 뒤 발행!</li>
		</ol>
	</div>
</header>

	<?php if ( $msg ) : ?>
<p class="tpcn-msg <?php echo esc_attr( $msg[0] ); ?>" id="tpcn-msg"><?php echo esc_html( $msg[1] ); ?></p>
	<?php endif; ?>
<?php // 알림 A: 요리가 끝나면 여기에 뜬다 (주문서를 쓰는 중이면 새로고침 대신 이 줄로 알린다) ?>
<p class="tpcn-msg ok" id="tpcn-live" role="status" hidden></p>

<section class="tpcn-panel">
	<h2>새 주문서 <small>카드뉴스·인터뷰·광고 하나 = 주문 하나</small></h2>
	<form class="tpcn-form" id="tpcn-form" method="post" action="<?php echo esc_url( $u['action'] ); ?>" enctype="multipart/form-data" data-max="<?php echo (int) $u['max']; ?>">
		<input type="hidden" name="action" value="tp_cn_order">
		<?php echo $u['nonce']; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_nonce_field 결과 ?>
		<?php // 주문 종류(1.8.0, 사용자 A): 맨 위에서 고르면 인터뷰·광고일 때만 대상(브랜드) 칸이 나타난다 ?>
		<div class="tpcn-kind" role="radiogroup" aria-label="주문 종류">
			<label><input type="radio" name="kind" value="cardnews" checked><span>📰 카드뉴스</span></label>
			<label><input type="radio" name="kind" value="interview"><span>🎤 인터뷰</span></label>
			<label><input type="radio" name="kind" value="ad"><span>📢 광고</span></label>
		</div>
		<div class="tpcn-field">
			<label for="tpcn-zip">피그마 zip <em class="tpcn-must">필수</em></label>
			<div class="tpcn-drop" id="tpcn-drop">
				<span class="tpcn-ico" aria-hidden="true">📦</span>
				<div><b id="tpcn-zipname">zip을 여기에 끌어다 놓거나 눌러서 고르기</b><span>'트란시스 카드뉴스 읽기'에서 받은 파일 그대로</span></div>
				<input type="file" id="tpcn-zip" name="zip" accept=".zip,application/zip" required title=""><?php // title을 비워야 브라우저 기본 말풍선("No file chosen")이 안 뜬다 ?>
			</div>
			<?php // 10/18 사용자: 다른 컴퓨터(성현 님)에서도 피그마 플러그인을 쉽게 받게. 처음 한 번만 필요해서 접어 둔다 ?>
			<details class="tpcn-how">
				<summary>피그마 플러그인이 없다면</summary>
				<div class="tpcn-plug">
					<a class="tpcn-dl" href="<?php echo esc_url( $u['fplug'] ); ?>" download>🧩 플러그인 받기 (zip)</a>
					<ol>
						<li>받은 zip을 더블클릭해서 압축을 풀어요 → <code>transis-cardnews-reader</code> 폴더가 생겨요. 지우지 말고 둬요</li>
						<li><b>피그마 데스크톱 앱</b>에서 아무 디자인 파일이나 열어요 (예시: 2026 트렌드포털). 웹 브라우저 피그마에선 안 돼요</li>
						<li>메뉴 <b>플러그인 → 개발 → 매니페스트에서 플러그인 가져오기…</b> → 그 폴더 안 <code>manifest.json</code> 선택</li>
						<li>이제 카드뉴스 파일에서 <b>플러그인 → 개발 → 트란시스 카드뉴스 읽기</b>로 zip을 받아 여기에 올려요</li>
					</ol>
				</div>
			</details>
		</div>
		<div class="tpcn-field">
			<label for="tpcn-caption">인스타 캡션 <em class="tpcn-must">필수</em></label>
			<textarea id="tpcn-caption" name="caption" rows="7" required placeholder="인스타 게시물 본문을 그대로 붙여 넣어 주세요 (해시태그 줄은 알아서 빼요)"></textarea>
		</div>
		<?php // 인터뷰·광고가 같이 쓰는 칸. 광고면 형식 칸을 숨기고 글자만 바꾼다(아래 showKind) ?>
		<div class="tpcn-iv" id="tpcn-iv" hidden>
			<div class="tpcn-field" id="tpcn-layout-field">
				<label>인터뷰 형식 <em class="tpcn-must">필수</em></label>
				<div class="tpcn-layouts">
					<label><input type="radio" name="layout" value="qa"><span><b>질문 여러 개</b>한 브랜드·사람에게 여러 질문</span></label>
					<label><input type="radio" name="layout" value="panel"><span><b>패널</b>질문 하나에 여러 명이 답함</span></label>
				</div>
			</div>
			<div class="tpcn-field">
				<label><span id="tpcn-plabel">인터뷰 대상</span> <em id="tpcn-pmust">선택</em></label>
				<div class="tpcn-prows" id="tpcn-prows">
					<div class="tpcn-prow">
						<input type="text" name="pname[0]" placeholder="이름 (예: 짐빔)" aria-label="이름">
						<input type="url" name="pinsta[0]" placeholder="인스타 주소 (https://www.instagram.com/계정/)" aria-label="인스타 주소">
						<label class="tpcn-vfile"><input type="file" name="pfile[0]" accept="image/jpeg,image/png,image/webp" title=""><span>🖼 프로필 사진</span></label>
						<button type="button" class="tpcn-vdel" aria-label="이 줄 지우기">×</button>
					</div>
				</div>
				<button type="button" class="tpcn-vadd" id="tpcn-padd">+ 한 명 더</button>
				<p class="tpcn-hint" id="tpcn-adhint" hidden>글 맨 위 <b>[광고] 표시 바로 아래와 글 끝</b>에 브랜드 프로필 카드가 들어가요. <b>인스타 주소는 꼭</b> 넣어 주세요. 프로필 사진은 브랜드 인스타 프로필 화면을 캡처해 그대로 올리면 셰프가 동그란 사진만 잘라서 써요</p>
				<p class="tpcn-hint" id="tpcn-ivhint">글 <b>시작과 끝의 프로필 카드</b>에 들어가요. 프로필 사진은 인스타 프로필 화면을 캡처해 그대로 올려 주세요. 셰프가 동그란 프로필 사진만 잘라서 써요. 비우면 프로필 카드 없이 만들고 알려 드려요. 패널형은 카드에 계정·얼굴이 있어서 보통 비워도 돼요</p>
				<?php
				// 10/18 사용자: 프로필 캡처를 어떻게 하는지 움직이는 그림으로, 접지 않고 늘 보이게. 인터뷰·광고가 같은 칸이라 여기 하나만.
				// GIF(635KB) 대신 소리 없는 mp4(73KB): 9분의 1 크기에 영상 처리 기능으로 재생돼 가볍다.
				// autoplay를 안 거는 건 카드뉴스 주문 땐 받지도 않으려고 — 인터뷰·광고 칸이 열릴 때 showKind가 재생한다
				?>
				<div class="tpcn-cap">
					<video class="tpcn-capvid" id="tpcn-capvid" src="<?php echo esc_url( $u['pcap'] ); ?>" width="400" height="400" muted loop playsinline preload="none" aria-label="인스타 프로필 화면에서 동그란 프로필 사진에 상자를 치고 그 사진으로 확대하는 영상"></video>
					<div>
						<b>프로필 사진 캡처하는 법</b>
						<ol>
							<li>인스타에서 그 계정의 <b>프로필 화면</b>을 열어요 (PC·폰 다 돼요)</li>
							<li><b>동그란 프로필 사진만 나오면 돼요.</b> Mac은 <code>⌘ Shift 4</code>로 원 둘레를 끌어서, 폰은 스크린샷 그대로 올려도 돼요</li>
							<li>스토리 테두리(무지개 고리)가 있어도 괜찮아요. 셰프가 동그란 사진만 잘라서 써요</li>
						</ol>
					</div>
				</div>
			</div>
		</div>
		<div class="tpcn-field">
			<label>영상 카드 <em>있을 때만</em></label>
			<div class="tpcn-vrows" id="tpcn-vrows">
				<?php // 줄 번호(i)를 이름에 박아 둔다 — 파일을 안 고른 줄이 있어도 번호·파일·주소가 같은 줄끼리 짝지어지게 ?>
				<div class="tpcn-vrow">
					<input type="number" name="vcard[0]" min="2" max="99" placeholder="카드" aria-label="카드 번호">
					<label class="tpcn-vfile"><input type="file" name="vfile[0]" accept="video/mp4,video/quicktime,video/x-m4v,video/webm,image/jpeg,image/png,image/webp" title=""><span>🎬 파일 고르기</span></label>
					<input type="url" name="vurl[0]" placeholder="원본 영상 주소 (남의 영상일 때)" aria-label="원본 영상 주소">
					<button type="button" class="tpcn-vdel" aria-label="이 줄 지우기">×</button>
				</div>
			</div>
			<button type="button" class="tpcn-vadd" id="tpcn-vadd">+ 한 줄 더</button>
			<p class="tpcn-hint"><b>주소가 있으면</b> 남의 영상 → 정지 화면 + ▶ 링크 · <b>주소 없이 영상만</b> 넣으면 우리가 찍은 영상 → 사이트에서 바로 재생</p>
			<?php // 10/17 사용자: 쓰는 법을 도우미 화면에서도 알 수 있게. 10/18: 들어오면 펼쳐진 채로 두고, 필요 없으면 접는다 ?>
			<details class="tpcn-how" open>
				<summary>자세히 보기</summary>
				<ul>
					<li><b>카드 번호</b> = 피그마 프레임 이름 끝 숫자예요. 예: <code>트렌드포털 802-4</code> → <b>4</b> (표지가 1번이라, 카드뉴스를 넘겨 보는 순서와 같아요)</li>
					<li><b>주소</b> = 원래 영상이 올라간 곳의 주소. 인스타 릴스·게시물, 유튜브(일반·youtu.be·쇼츠), X를 받아요. <code>?igsh=…</code> 같은 꼬리는 알아서 떼요</li>
					<li><b>주소 + 영상 파일</b>: 셰프가 영상에서 잘 나온 장면을 골라 정지 화면으로 써요 (인스타 정지 화면보다 선명). 영상 자체는 사이트에 안 올려요</li>
					<li><b>주소 + 사진 파일</b>: 그 사진(직접 캡처한 장면)을 정지 화면으로 써요</li>
					<li><b>주소만</b>: 셰프가 원본 게시물의 정지 화면을 가져와요</li>
					<li><b>영상 파일만</b>: 우리가 찍은 영상으로 보고, 소리를 빼서 사이트에서 자동 재생돼요</li>
					<li>장면이 마음에 안 들면 완성 뒤 "고쳐 주세요"에 <code>3번 정지 화면 5초 장면으로</code>처럼 적어 주세요</li>
					<li>한 번에 올릴 수 있는 크기는 zip과 파일을 합쳐 <b><?php echo esc_html( size_format( $u['max'] ) ); ?></b>까지예요</li>
				</ul>
			</details>
		</div>
		<div class="tpcn-field">
			<label for="tpcn-memo">메모 <em>선택</em></label>
			<textarea id="tpcn-memo" name="memo" rows="2" placeholder="예: 카드 배경처럼 어둡게 물들여서 / 제목에 '팝업' 넣어 주세요"></textarea>
			<?php // 10/17 사용자: 메모로 무엇까지 시킬 수 있는지 화면에서 알 수 있게. 형식 이름은 docs/post-formats.md와 같게 ?>
			<details class="tpcn-how" open>
				<summary>메모로 할 수 있는 것</summary>
				<ul>
					<li><b>인터뷰</b>는 메모 대신 맨 위 <b>🎤 인터뷰</b>를 눌러 형식·대상을 골라 주세요</li>
					<li><b>디자인</b>: <code>카드 배경처럼 어둡게 물들여서</code>처럼 적으면 이 글에만 CSS를 입혀요. 셰프는 화면을 못 보니 미리보기로 확인해 주세요</li>
					<li><b>제목·카테고리</b>: <code>제목에 '팝업' 넣어 주세요</code>, <code>카테고리는 브랜드</code></li>
					<li><b>카드에 없는 사실</b>: 인스타 계정, 바른 이름처럼 셰프가 알 수 없는 것</li>
					<li>아직 못 하는 것: 카피 모음형 디자인(지금은 일본 카피 글에만 써요)</li>
					<li>완성 뒤 고칠 것은 "고쳐 주세요"에 같은 식으로 적으면 돼요</li>
				</ul>
			</details>
		</div>
		<button type="submit" class="tpcn-go" id="tpcn-go">주문 넣기</button>
	</form>
</section>

<section class="tpcn-panel">
	<h2>주문 현황 <small>최근 30개</small></h2>
	<?php if ( ! $orders ) : ?>
	<div class="tpcn-empty">
		<img src="<?php echo esc_url( $u['rest'] ); ?>" width="180" alt="쉬고 있는 트란시스">
		<p>아직 주문이 없어요. 셰프 트란시스는 쉬는 중!</p>
	</div>
	<?php else : ?>
	<ol class="tpcn-list">
		<?php
		foreach ( $orders as $o ) :
			$s = isset( TP_CN_STATUS[ $o['status'] ] ) ? $o['status'] : 'new';
			// 발행된 주문은 '완성' 대신 '발행됨' 딱지(10/18 사용자). 화면 표시만 바꾸고 저장된 상태는 그대로 done
			$pub = 'done' === $s && 'publish' === $o['post_status'];
			?>
		<li class="tpcn-item s-<?php echo esc_attr( $s ); ?> is-<?php echo esc_attr( $s ); ?><?php echo $pub ? ' is-pub' : ''; ?><?php echo $o['finished'] > $u['seen'] ? ' is-fresh' : ''; ?>" data-id="<?php echo (int) $o['id']; ?>" data-status="<?php echo esc_attr( $s ); ?>">
			<span class="tpcn-no" title="주문 번호"><?php echo (int) $o['id']; ?></span>
			<div>
				<h3><?php // 주문 종류는 모든 주문에 붙인다(10/17 사용자). 1.8.0 전 주문은 kind가 없어 카드뉴스로 나온다 ?><?php if ( 'interview' === $o['kind'] ) : ?><span class="tpcn-tag is-iv">🎤 인터뷰 · <?php echo esc_html( TP_CN_LAYOUTS[ $o['layout'] ] ?? '형식 미정' ); ?></span><?php elseif ( 'ad' === $o['kind'] ) : ?><span class="tpcn-tag is-sponsor">📢 광고</span><?php else : ?><span class="tpcn-tag">📰 카드뉴스</span><?php endif; ?><?php echo esc_html( $o['group'] ); ?><?php if ( $o['finished'] > $u['seen'] ) : ?> <span class="tpcn-fresh">방금 나왔어요</span><?php endif; ?></h3>
				<p class="tpcn-when"><?php echo esc_html( $fmt( $o['created'] ) ); ?> 주문</p>
			</div>
			<span class="tpcn-chip<?php echo $pub ? ' is-pub' : ''; ?>"><?php echo esc_html( $pub ? '발행됨' : TP_CN_STATUS[ $s ] ); ?></span>
			<div class="tpcn-body">
			<?php if ( 'new' === $s ) : ?>
				<?php if ( null === tp_cn_recall_reason( $o, $u['now'] ) ) : // 초인종이 실패했으면 "곧 가져가요"는 틀린 말이라 아래 이유만 ?>
				<p>셰프 트란시스가 곧 주문서를 가져가요.</p>
				<?php endif; ?>
			<?php elseif ( 'revise' === $s ) : ?>
				<p>✏️ <?php echo esc_html( $o['feedback'] ); ?></p>
				<?php if ( null === tp_cn_recall_reason( $o, $u['now'] ) ) : ?>
				<p>셰프 트란시스가 곧 와서 같은 초안을 고쳐요.</p>
				<?php endif; ?>
			<?php elseif ( 'question' === $s ) : ?>
				<?php // 셰프가 추측하지 않고 물은 것. 답하면 셰프가 하던 데서 이어 만든다 ?>
				<p class="tpcn-q">🙋 <b>셰프가 물어봐요</b><br><?php echo nl2br( esc_html( $o['question'] ) ); ?></p>
				<form class="tpcn-revise tpcn-answer" method="post" action="<?php echo esc_url( $u['action'] ); ?>">
					<input type="hidden" name="action" value="tp_cn_answer">
					<input type="hidden" name="order" value="<?php echo (int) $o['id']; ?>">
					<?php wp_nonce_field( 'tp_cn_answer_' . (int) $o['id'] ); ?>
					<textarea name="answer" rows="2" required placeholder="답을 적어 주세요"></textarea>
					<button type="submit">💬 답하고 이어서 요리</button>
				</form>
			<?php elseif ( 'answered' === $s ) : ?>
				<p class="tpcn-q">🙋 <?php echo nl2br( esc_html( $o['question'] ) ); ?><br>💬 <?php echo esc_html( $o['answer'] ); ?></p>
				<?php if ( null === tp_cn_recall_reason( $o, $u['now'] ) ) : ?>
				<p>셰프 트란시스가 곧 와서 이어서 만들어요.</p>
				<?php endif; ?>
			<?php elseif ( 'working' === $s ) : ?>
				<p><?php echo $o['feedback'] ? '✏️ ' . esc_html( $o['feedback'] ) . ' — 고치는 중이에요.' : '사진을 고르고 글을 굽는 중이에요.'; ?></p>
			<?php elseif ( 'failed' === $s ) : ?>
				<p><?php echo esc_html( $o['error'] ?: '이유를 남기지 못하고 주방이 멈췄어요.' ); ?></p>
				<?php if ( $o['post_id'] ) : // 수정 중에 멈춰도 지난 초안은 그대로 있다 ?>
				<div class="tpcn-links">
					<a href="<?php echo esc_url( home_url( '/?p=' . (int) $o['post_id'] . '&preview=true' ) ); ?>" target="_blank" rel="noopener">지난 초안 미리보기</a>
					<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $o['post_id'] . '&action=edit' ) ); ?>">편집기에서 열기</a>
				</div>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( $o['title'] ) : ?>
				<p class="tpcn-title"><?php echo esc_html( $o['title'] ); ?></p>
				<?php endif; ?>
				<?php if ( $o['post_id'] ) : ?>
				<div class="tpcn-links">
					<?php if ( 'publish' === $o['post_status'] ) : ?>
					<a class="main" href="<?php echo esc_url( get_permalink( (int) $o['post_id'] ) ); ?>" target="_blank" rel="noopener">✅ 발행됨 · 글 보기</a>
					<?php else : ?>
					<a class="main" href="<?php echo esc_url( home_url( '/?p=' . (int) $o['post_id'] . '&preview=true' ) ); ?>" target="_blank" rel="noopener">미리보기</a>
					<?php // 10/18 사용자: 편집기를 거치지 않고 탭에서 바로 발행. 누르기 전에 한 번 묻는다 ?>
					<form class="tpcn-publish" method="post" action="<?php echo esc_url( $u['action'] ); ?>" onsubmit="return confirm('이 초안을 사이트에 발행할까요? 발행하면 바로 공개돼요.');">
						<input type="hidden" name="action" value="tp_cn_publish">
						<input type="hidden" name="order" value="<?php echo (int) $o['id']; ?>">
						<?php wp_nonce_field( 'tp_cn_publish_' . (int) $o['id'] ); ?>
						<button type="submit">🚀 발행</button>
					</form>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<?php
				if ( $o['changes'] ) :
					// 확인 항목(❗·🟡)은 위로 모은다. 발행 전엔 "발행 전에 봐 주세요" 상자로 늘 펼쳐 두고(시안 1018-1 C),
					// 발행 뒤엔 다 본 것이니 상자 없이 접힌 목록 맨 위에 둔다
					$checks = array_values( array_filter( $o['changes'], fn( $c ) => tp_cn_level( $c )[0] > 1 ) );
					usort( $checks, fn( $a, $b ) => tp_cn_level( $b )[0] <=> tp_cn_level( $a )[0] );
					$rest = array_values( array_filter( $o['changes'], fn( $c ) => 1 === tp_cn_level( $c )[0] ) );
					$box  = $checks && ! $pub;
					$fold = $box ? $rest : array_merge( $checks, $rest );
					?>
				<?php if ( $box ) : ?>
				<div class="tpcn-check">
					<b>👀 발행 전에 봐 주세요 (<?php echo count( $checks ); ?>)</b>
					<ul><?php foreach ( $checks as $c ) { echo tp_cn_change_li( $c ); } // phpcs:ignore WordPress.Security.EscapeOutput -- 안에서 esc_html ?></ul>
				</div>
				<?php endif; ?>
				<?php if ( $fold ) : ?>
				<details>
					<summary><?php echo $box ? '셰프가 알아서 고친 것' : '셰프가 손본 것'; ?> <?php echo count( $fold ); ?>건</summary>
					<ul class="tpcn-changes"><?php foreach ( $fold as $c ) { echo tp_cn_change_li( $c ); } // phpcs:ignore WordPress.Security.EscapeOutput -- 안에서 esc_html ?></ul>
				</details>
				<?php endif; ?>
				<?php endif; ?>
				<?php if ( $o['rounds'] ) : ?>
				<ol class="tpcn-rounds">
					<?php foreach ( $o['rounds'] as $i => $r ) : ?>
					<li><b><?php echo (int) $i + 1; ?>차 수정</b> · <?php echo esc_html( $r['feedback'] ); ?>
						<?php if ( ! empty( $r['changes'] ) ) : ?>
						<ul class="tpcn-changes"><?php foreach ( $r['changes'] as $c ) { echo tp_cn_change_li( $c ); } // phpcs:ignore WordPress.Security.EscapeOutput -- 안에서 esc_html ?></ul>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ol>
				<?php endif; ?>
				<?php if ( $o['post_id'] && ! $pub ) : // 발행된 글은 셰프가 고치지 않는다(draft의 guard가 막음) ?>
				<?php // 10/17 사용자: Claude Code 없이 관리자 창에서 메모로 고치기. 셰프가 같은 초안을 고치고 위 기록에 쌓인다 ?>
				<form class="tpcn-revise" method="post" action="<?php echo esc_url( $u['action'] ); ?>">
					<input type="hidden" name="action" value="tp_cn_revise">
					<input type="hidden" name="order" value="<?php echo (int) $o['id']; ?>">
					<?php wp_nonce_field( 'tp_cn_revise_' . (int) $o['id'] ); ?>
					<textarea name="feedback" rows="2" required placeholder="고쳐 주세요 — 예: 제목을 더 짧게 / 3번 설명 두 문단으로 / 카테고리는 브랜드로"></textarea>
					<button type="submit">✏️ 다시 요리</button>
				</form>
				<?php endif; ?>
			<?php endif; ?>
			<?php
			$why = tp_cn_recall_reason( $o, $u['now'] );
			if ( null !== $why ) :
				?>
				<form class="tpcn-recall" method="post" action="<?php echo esc_url( $u['action'] ); ?>">
					<?php if ( '' !== $why ) : ?>
					<p><?php echo esc_html( $why ); ?></p>
					<?php endif; ?>
					<input type="hidden" name="action" value="tp_cn_recall">
					<input type="hidden" name="order" value="<?php echo (int) $o['id']; ?>">
					<?php wp_nonce_field( 'tp_cn_recall_' . (int) $o['id'] ); ?>
					<button type="submit">🔔 셰프 다시 부르기</button>
				</form>
			<?php endif; ?>
			<?php if ( 'working' !== $s ) : // 요리 중엔 못 지운다(셰프가 일하는 중) ?>
				<form class="tpcn-del" method="post" action="<?php echo esc_url( $u['action'] ); ?>" onsubmit="return confirm('주문 <?php echo (int) $o['id']; ?>을 지울까요?\n아직 발행 안 한 임시글과 셰프가 올린 사진도 같이 지워져요. 되돌릴 수 없어요.');">
					<input type="hidden" name="action" value="tp_cn_delete">
					<input type="hidden" name="order" value="<?php echo (int) $o['id']; ?>">
					<?php wp_nonce_field( 'tp_cn_delete_' . (int) $o['id'] ); ?>
					<button type="submit">🗑 주문 지우기</button>
				</form>
			<?php endif; ?>
			</div>
		</li>
		<?php endforeach; ?>
	</ol>
	<?php endif; ?>
</section>

<?php // 초인종 설정: 처음 한 번과 열쇠를 바꿀 때만 쓰니 접어 둔다. 열쇠는 저장 뒤 다시 보여 주지 않는다 ?>
<details class="tpcn-panel tpcn-bell"<?php echo empty( $u['bell']['token'] ) ? ' open' : ''; ?>>
	<summary>🔔 셰프 초인종 설정 <small><?php echo empty( $u['bell']['token'] ) ? '아직 설정 안 됨 — 주문해도 셰프가 오지 않아요' : '연결됨'; ?></small></summary>
	<form method="post" action="<?php echo esc_url( $u['action'] ); ?>">
		<input type="hidden" name="action" value="tp_cn_bell">
		<?php wp_nonce_field( 'tp_cn_bell' ); ?>
		<p class="tpcn-hint">claude.ai/code/routines → 셰프 routine → Edit → API 트리거에서 받은 주소와 열쇠를 넣어 주세요.</p>
		<div class="tpcn-field">
			<label for="tpcn-bell-url">주소</label>
			<input type="url" id="tpcn-bell-url" name="bell_url" required value="<?php echo esc_attr( $u['bell']['url'] ?? '' ); ?>" placeholder="https://api.anthropic.com/v1/claude_code/routines/trig_…/fire">
		</div>
		<div class="tpcn-field">
			<label for="tpcn-bell-token">열쇠 <em><?php echo empty( $u['bell']['token'] ) ? '필수' : '바꿀 때만 — 비워 두면 그대로'; ?></em></label>
			<input type="password" id="tpcn-bell-token" name="bell_token" autocomplete="off" <?php echo empty( $u['bell']['token'] ) ? 'required' : ''; ?> placeholder="sk-ant-…">
		</div>
		<button type="submit" class="tpcn-go">저장</button>
	</form>
</details>

<script>
(() => {
	const $ = id => document.getElementById(id);
	// 얼굴을 누르면 꼬리 흔들기 + 쓰는 법 말풍선 (8초 뒤 저절로 닫힘)
	let t;
	$('tpcn-face').addEventListener('click', () => {
		const open = $('tpcn-bubble').hidden;
		$('tpcn-bubble').hidden = !open;
		$('tpcn-face').classList.remove('wag');
		void $('tpcn-face').offsetWidth;
		$('tpcn-face').classList.add('wag');
		clearTimeout(t);
		if (open) t = setTimeout(() => { $('tpcn-bubble').hidden = true; }, 8000);
	});
	// zip 칸: 고른 파일 이름을 보여 주고, 끌어다 놓을 때 상자 색을 바꾼다
	const drop = $('tpcn-drop'), zip = $('tpcn-zip');
	zip.addEventListener('change', () => {
		const f = zip.files[0];
		drop.classList.toggle('has-file', !!f);
		$('tpcn-zipname').textContent = f ? `${f.name} · ${(f.size / 1048576).toFixed(1)}MB` : 'zip을 여기에 끌어다 놓거나 눌러서 고르기';
	});
	['dragenter', 'dragover'].forEach(e => zip.addEventListener(e, () => drop.classList.add('is-over')));
	['dragleave', 'drop'].forEach(e => zip.addEventListener(e, () => drop.classList.remove('is-over')));
	// 줄 칸(영상 카드 줄, 인터뷰 대상 줄): 파일 이름 보여 주기, 줄 더하기·지우기. 새 줄은 첫 줄을 복사해 이름의 [번호]만 바꾼다
	const lines = (list, add, label) => {
		let next = 1;
		const fresh = row => {
			row.querySelectorAll('input').forEach(i => { i.value = ''; });
			const box = row.querySelector('.tpcn-vfile');
			box.classList.remove('has-file');
			box.querySelector('span').textContent = label;
		};
		list.addEventListener('change', e => {
			if (e.target.type !== 'file') return;
			const f = e.target.files[0], box = e.target.closest('.tpcn-vfile');
			box.classList.toggle('has-file', !!f);
			box.querySelector('span').textContent = f ? `${f.name} · ${(f.size / 1048576).toFixed(1)}MB` : label;
		});
		list.addEventListener('click', e => {
			if (!e.target.classList.contains('tpcn-vdel')) return;
			const row = e.target.parentElement;
			// 마지막 한 줄은 지우지 않고 비우기만 한다(칸이 사라지면 다시 넣을 곳이 없어서)
			if (list.children.length > 1) row.remove(); else fresh(row);
		});
		add.addEventListener('click', () => {
			const row = list.firstElementChild.cloneNode(true);
			row.querySelectorAll('input').forEach(i => { i.name = i.name.replace(/\[\d+\]/, `[${next}]`); });
			next++;
			fresh(row);
			list.append(row);
			row.querySelector('input').focus();
		});
	};
	const rows = $('tpcn-vrows'), people = $('tpcn-prows');
	lines(rows, $('tpcn-vadd'), '🎬 파일 고르기');
	lines(people, $('tpcn-padd'), '🖼 프로필 사진');
	// 주문 종류: 인터뷰면 형식(꼭 고름) + 대상 칸, 광고면 같은 칸을 광고 브랜드 칸으로(인스타 주소 필수) 보여 준다
	const kind = () => $('tpcn-form').querySelector('input[name=kind]:checked').value;
	const showKind = () => {
		const iv = kind() === 'interview', ad = kind() === 'ad';
		$('tpcn-iv').hidden = !(iv || ad);
		$('tpcn-layout-field').hidden = !iv;
		$('tpcn-form').querySelector('input[name=layout]').required = iv;
		$('tpcn-plabel').textContent = ad ? '광고 브랜드' : '인터뷰 대상';
		$('tpcn-pmust').textContent = ad ? '필수' : '선택';
		$('tpcn-pmust').className = ad ? 'tpcn-must' : '';
		$('tpcn-padd').textContent = ad ? '+ 브랜드 하나 더' : '+ 한 명 더';
		$('tpcn-adhint').hidden = !ad;
		$('tpcn-ivhint').hidden = ad;
		// 첫 줄 인스타 주소만 브라우저에서 바로 막는다(서버도 같은 검사를 한다)
		$('tpcn-prows').querySelector('input[type=url]').required = ad;
		// 캡처 안내 영상: 칸이 보일 때만 재생(소리 없는 영상이라 자동 재생이 막히지 않는다)
		const vid = $('tpcn-capvid');
		if (iv || ad) vid.play().catch(() => {}); else vid.pause();
	};
	$('tpcn-form').querySelectorAll('input[name=kind]').forEach(r => r.addEventListener('change', showKind));
	showKind();
	// 큰 zip은 올리는 데 시간이 걸려서, 두 번 누르지 않게 버튼을 잠근다.
	// 서버 한도를 넘으면 다 올린 뒤에야 실패해서, 보내기 전에 합계를 재서 막는다
	$('tpcn-form').addEventListener('submit', e => {
		const files = [...$('tpcn-form').querySelectorAll('input[type=file]')].flatMap(i => [...i.files]);
		const total = files.reduce((s, f) => s + f.size, 0), max = Number($('tpcn-form').dataset.max);
		if (max && total > max) {
			e.preventDefault();
			alert(`파일을 합쳐 ${(total / 1048576).toFixed(1)}MB라 한 번에 못 보내요 (최대 ${(max / 1048576).toFixed(0)}MB).\n영상을 짧게 자르거나, 정지 화면 사진으로 바꿔 주세요.`);
			return;
		}
		$('tpcn-go').disabled = true;
		$('tpcn-go').textContent = '올리는 중…';
	});

	// ── 알림 A(10/17 사용자 결정 A+B): 접수·요리 중인 주문이 있으면 20초마다 상태를 확인한다 ──
	// 바뀐 게 있으면: 주문서를 쓰는 중이 아니면 새로고침해서 목록을 다시 그리고, 쓰는 중이면 알림 줄만 띄운다(쓰던 내용을 날리지 않게)
	const base = document.title;
	const items = [...document.querySelectorAll('.tpcn-item')];
	const known = Object.fromEntries(items.map(li => [li.dataset.id, li.dataset.status]));
	const waiting = () => Object.values(known).some(s => s === 'new' || s === 'revise' || s === 'answered' || s === 'working');
	const typing = () => zip.files.length || ['tpcn-caption', 'tpcn-memo'].some(id => $(id).value.trim())
		|| [...rows.querySelectorAll('input'), ...people.querySelectorAll('input')].some(i => (i.files ? i.files.length : i.value.trim()));
	const FLASH = 'tpcn-flash', LAST = 'tpcn-reloaded';
	const done = { done: '✅ 완성', failed: '⚠️ 주방 멈춤', question: '🙋 셰프가 물어봐요' };
	const clean = location.pathname + '?page=<?php echo esc_js( TP_CN_SLUG ); ?>';
	// 주문 직후 붙은 ?tp_cn_ok= 꼬리를 주소창에서 지운다 — 그대로 새로고침하면 "곧 시작해요" 알림이 또 뜨던 문제(10/17)
	if (/[?&]tp_cn_(ok|err|info|rev)=/.test(location.search)) history.replaceState(null, '', clean);

	// 새로고침 뒤: 무엇이 끝났는지 위에 띄우고, 다른 탭을 보고 있었다면 탭 제목으로도 알린다
	try {
		const f = sessionStorage.getItem(FLASH);
		if (f) {
			sessionStorage.removeItem(FLASH);
			$('tpcn-live').textContent = f;
			$('tpcn-live').hidden = false;
			if (document.hidden) document.title = f.split(' · ')[0] + ' · ' + base;
		}
	} catch (e) {}
	document.addEventListener('visibilitychange', () => { if (!document.hidden) setTimeout(() => { document.title = base; }, 2500); });

	async function check() {
		let list;
		try {
			const r = await fetch(document.getElementById('tpcn').dataset.poll, { headers: { 'X-WP-Nonce': document.getElementById('tpcn').dataset.nonce }, credentials: 'same-origin' });
			if (!r.ok) return;
			list = await r.json();
		} catch (e) { return; }
		const changed = list.filter(o => known[o.id] !== undefined && known[o.id] !== o.status);
		if (!changed.length) return;
		changed.forEach(o => { known[o.id] = o.status; });
		const ends = changed.filter(o => done[o.status]);
		const text = ends.length
			? ends.map(o => `${done[o.status]} · 주문 ${o.id} ${o.group}`).join('  /  ') + (ends.some(o => o.status === 'done') ? ' — 미리보기로 맛보세요!' : '')
			: `🍳 주문 ${changed[0].id} 요리를 시작했어요`;
		if (ends.length && document.hidden) document.title = done[ends[0].status] + ' · ' + base;
		// 30초 안에 또 새로고침하지 않는다: 혹시 화면과 서버 상태가 계속 어긋나도 새로고침이 끝없이 돌지 않게
		let recent = false;
		try { recent = Date.now() - Number(sessionStorage.getItem(LAST) || 0) < 30000; } catch (e) {}
		if (!typing() && !recent) {
			try { sessionStorage.setItem(FLASH, text); sessionStorage.setItem(LAST, String(Date.now())); } catch (e) {}
			location.replace(clean);
			return;
		}
		$('tpcn-live').innerHTML = '';
		$('tpcn-live').append(text, Object.assign(document.createElement('button'), { type: 'button', className: 'tpcn-live-btn', textContent: '목록 새로 보기', onclick: () => location.reload() }));
		$('tpcn-live').hidden = false;
	}
	(function loop() {
		if (!waiting()) return; // 기다릴 주문이 없으면 확인하지 않는다
		setTimeout(async () => { await check(); loop(); }, 20000);
	})();
})();
</script>
</div>
	<?php
}
