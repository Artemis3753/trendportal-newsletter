<?php
/**
 * Plugin Name: TP Trend Radar
 * Description: 관리자 전용 탭 "트렌드 레이더". 매일 아침 클라우드 routine(tools/radar/radar.py)이 고른 카드뉴스 거리를 REST로 받아 날짜별로 쌓고, 트란시스 발신메일봇으로 알림 메일을 보낸다.
 * Version: 1.0.0
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
	);
}

// ── 메일 ────────────────────────────────────────────────

function tp_radar_weekday( $ymd ) {
	$t = strtotime( $ymd . ' 12:00:00' );
	return wp_date( 'n월 j일', $t ) . ' (' . array( '일', '월', '화', '수', '목', '금', '토' )[ (int) wp_date( 'w', $t ) ] . ')';
}

/**
 * 메일 본문. 트란시스 메일에서 배운 것(10/14): Gmail은 배경색·바깥 그림을 지우는 일이 잦다
 * → 테두리·글자색만으로 읽히게 하고 그림은 넣지 않는다.
 */
function tp_radar_mail_html( $ymd, $items, $tab ) {
	$rows = '';
	foreach ( $items as $i => $it ) {
		$rows .= '<tr><td style="padding:14px 0;border-top:1px solid #e5e5e5;">'
			. '<div style="font-size:12px;color:#888;">' . esc_html( ( $i + 1 ) . '. ' . $it['source'] . ' · ' . $it['date'] ) . '</div>'
			. '<div style="margin:4px 0;font-size:16px;font-weight:700;"><a href="' . esc_url( $it['link'] ) . '" style="color:#111;text-decoration:none;">' . esc_html( $it['title'] ) . '</a></div>'
			. '<div style="font-size:14px;color:#333;line-height:1.6;">' . esc_html( $it['note'] ) . '</div>'
			. '</td></tr>';
	}
	return '<div style="max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Apple SD Gothic Neo\',sans-serif;color:#111;">'
		. '<p style="margin:0 0 4px;font-size:13px;color:#888;">📡 트렌드 레이더 · ' . esc_html( tp_radar_weekday( $ymd ) ) . '</p>'
		. '<h1 style="margin:0 0 12px;font-size:20px;">오늘의 카드뉴스 거리 ' . count( $items ) . '개</h1>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
		. '<p style="margin:20px 0 0;"><a href="' . esc_url( $tab ) . '" style="display:inline-block;padding:8px 16px;border:1.5px solid #111;border-radius:999px;color:#111;text-decoration:none;font-weight:700;">탭에서 지난 거리까지 보기</a></p>'
		. '</div>';
}

function tp_radar_mail_text( $ymd, $items, $tab ) {
	$out = '트렌드 레이더 · ' . tp_radar_weekday( $ymd ) . "\n\n";
	foreach ( $items as $i => $it ) {
		$out .= ( $i + 1 ) . '. ' . $it['title'] . ' (' . $it['source'] . ")\n" . $it['note'] . "\n" . $it['link'] . "\n\n";
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
	$subject = '📡 오늘의 거리 ' . count( $items ) . '개 · ' . tp_radar_weekday( $ymd );
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
		add_menu_page( '트렌드 레이더', '트렌드 레이더', 'manage_options', TP_RADAR_SLUG, 'tp_radar_page', 'dashicons-rss', '3.1' );
	}
);

function tp_radar_page() {
	$days = tp_radar_days();
	// 처음엔 2주만. 더 보기를 누를 때마다 2주씩
	$show = min( TP_RADAR_KEEP, max( 14, (int) ( $_GET['show'] ?? 14 ) ) );
	$more = count( $days ) > $show;
	$days = array_slice( $days, 0, $show, true );
	?>
<div class="wrap tprd">
<style>
.tprd { max-width: 860px; }
.tprd h1 { display: flex; align-items: baseline; gap: 10px; }
.tprd h1 small { font-size: 13px; font-weight: 400; color: #646970; }
.tprd-day { margin: 18px 0; padding: 16px 18px; background: #fff; border: 1px solid #dcdcde; border-radius: 12px; }
.tprd-day h2 { margin: 0 0 6px; font-size: 16px; display: flex; gap: 8px; align-items: center; }
.tprd-day h2 .tprd-mail { font-size: 12px; font-weight: 400; color: #646970; }
.tprd-day h2 .tprd-mail.bad { color: #b32d2e; }
.tprd-item { padding: 10px 0; border-top: 1px solid #f0f0f1; }
.tprd-item:first-of-type { border-top: 0; }
.tprd-src { display: inline-block; margin-right: 6px; padding: 1px 8px; border-radius: 999px; background: #f0f0f1; font-size: 12px; color: #50575e; }
.tprd-item a { font-size: 15px; font-weight: 600; text-decoration: none; }
.tprd-item p { margin: 4px 0 0; font-size: 13.5px; color: #1d2327; }
.tprd-item .tprd-orig { color: #8c8f94; font-size: 12px; }
</style>
<h1>📡 트렌드 레이더 <small>매일 아침 9시, 카드뉴스 거리 5개 안팎</small></h1>
	<?php if ( ! $days ) : ?>
<p>아직 받은 거리가 없어요. 내일 아침 9시에 첫 거리가 와요.</p>
	<?php endif; ?>
	<?php foreach ( $days as $ymd => $day ) : ?>
		<?php
		$mail = (string) ( $day['mail'] ?? '' );
		$ok   = in_array( $mail, array( 'sent', 'duplicate' ), true ) || 0 === strpos( $mail, 'preview:sent' );
		$says = 'sent' === $mail ? '메일 보냄' : ( 'duplicate' === $mail ? '메일은 이미 보냄' : ( 0 === strpos( $mail, 'preview:' ) ? '미리보기 메일만' : $mail ) );
		?>
<section class="tprd-day">
<h2><?php echo esc_html( tp_radar_weekday( $ymd ) ); ?> <span class="tprd-mail<?php echo $ok ? '' : ' bad'; ?>">· <?php echo esc_html( $says ); ?></span></h2>
		<?php foreach ( (array) $day['items'] as $it ) : ?>
<div class="tprd-item">
<span class="tprd-src"><?php echo esc_html( $it['source'] ); ?></span><a href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a>
<p><?php echo esc_html( $it['note'] ); ?></p>
<p class="tprd-orig">원문: <?php echo esc_html( $it['orig'] . ' · ' . $it['date'] ); ?></p>
</div>
		<?php endforeach; ?>
</section>
	<?php endforeach; ?>
	<?php if ( $more ) : ?>
<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TP_RADAR_SLUG . '&show=' . ( $show + 14 ) ) ); ?>">지난 거리 더 보기</a></p>
	<?php endif; ?>
</div>
	<?php
}
