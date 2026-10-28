<?php
/*
 * 홈 '독자 제보' 칸 (1.4.0, 10/28 사용자 결정 / 1.4.1 본문에 붙여 넣은 외부 사진도 썸네일로 / 1.6.0 카드 격자로)
 * - 맨 위: 공지 줄(시안 G1) — 공지글 중 가장 최근 것 하나
 * - 그 아래: 제보 카드(1.6.0, 10/29 — 제보 게시판 목록과 같은 정사각 사진 카드) — 공지가 아닌 제보 중 최근 것 최대 10개
 *   몇 장이 보이는지는 home.css가 화면 폭마다 정한다(PC 5열 10장 / 중간 4열 8장 / 폰 2열 6장)
 * 10개를 넘게 쌓이면 오래된 제보는 홈에서만 빠진다(글은 지워지지 않고 '제보 전체 보기'에서 그대로 보인다)
 * KBoard 최신글 목록은 공지와 일반 글을 섞어 최신순으로 넘겨준다. 그래서 공지가 제보 자리를 차지하지 않게 여기서 나눈다.
 * (홈 템플릿의 rpp=20 안에서 고른다 — 공지가 10개를 넘게 쌓이면 제보 카드가 10장 미만으로 보일 수 있다)
 */
$tp_report_limit = 10;
$tp_notice = null;
$tp_reports = array();
while($content = $list->hasNext()){
	if($content->notice){
		if(!$tp_notice) $tp_notice = $content;
	}
	else if(count($tp_reports) < $tp_report_limit){
		$tp_reports[] = $content;
	}
	if($tp_notice && count($tp_reports) >= $tp_report_limit) break;
}
// 홈 템플릿이 이 출력에 자동 줄바꿈(wpautop)을 걸어서 줄마다 <br>·<p>가 끼어든다 → 다 그린 뒤 줄바꿈을 접어서 내보낸다(맨 아래)
ob_start();
?>
<div id="kboard-default-latest">
	<?php if($tp_notice):?>
	<a class="tp-latest-notice" href="<?php echo $url->getDocumentURLWithUID($tp_notice->uid)?>">
		<span class="tp-latest-slot"><img src="<?php echo esc_url("{$skin_path}/images/notice-megaphone.png")?>" alt="" width="28" height="28"></span>
		<span class="tp-latest-pill is-notice">공지</span>
		<span class="tp-latest-notice-title"><?php echo $tp_notice->title?></span>
	</a>
	<?php endif?>

	<?php if($tp_reports):?>
	<div class="tp-latest-grid">
		<?php foreach($tp_reports as $tp_report):?>
		<?php $tp_thumb = tp_kboard_thumb($tp_report, 400)?>
		<a class="tp-latest-card" href="<?php echo $url->getDocumentURLWithUID($tp_report->uid)?>">
			<span class="tp-latest-card-thumb<?php if(!$tp_thumb):?> is-empty<?php endif?><?php if($tp_report->secret):?> is-secret<?php endif?>">
				<?php if($tp_report->isNew()):?><span class="tp-pill is-new">NEW</span><?php endif?>
				<?php if($tp_thumb):?><img src="<?php echo esc_url($tp_thumb)?>" alt="" loading="lazy"><?php endif?>
			</span>
			<span class="tp-latest-card-title"><?php echo $tp_report->title?></span>
			<span class="tp-latest-card-meta"><?php echo esc_html($tp_report->member_display)?> · <?php echo tp_kboard_date($tp_report)?></span>
		</a>
		<?php endforeach?>
	</div>
	<?php endif?>
	<?php if(!$tp_reports):?>
	<?php // 공지만 있고 제보가 없을 때도 빈 칸 대신 안내를 보여 준다 (KBoard는 목록이 아예 비었을 때만 안내를 띄운다) ?>
	<div class="kboard-latest-empty-list-message">아직 제보가 없어요. 첫 제보를 남겨 주세요.</div>
	<?php endif?>
</div>
<?php echo preg_replace('/>\s+</', '><', preg_replace('/\s+/', ' ', ob_get_clean()));