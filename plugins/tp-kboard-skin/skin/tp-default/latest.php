<?php
/*
 * 홈 '독자 제보' 칸 (1.4.0, 10/28 사용자 결정 / 1.4.1 본문에 붙여 넣은 외부 사진도 썸네일로)
 * - 맨 위: 공지 줄(시안 G1) — 공지글 중 가장 최근 것 하나. 확성기 칸은 제보 썸네일과 같은 56px라 '공지'·'NEW' 알약이 같은 세로줄에 선다
 * - 그 아래: 제보 줄(시안 H1) — 공지가 아닌 제보 중 최근 것 최대 10개. 작성자·시간은 제목 아래 회색 한 줄
 * 10개를 넘게 쌓이면 오래된 제보는 홈에서만 빠진다(글은 지워지지 않고 '제보 전체 보기'에서 그대로 보인다)
 * KBoard 최신글 목록은 공지와 일반 글을 섞어 최신순으로 넘겨준다. 그래서 공지가 제보 자리를 차지하지 않게 여기서 나눈다.
 * (홈 템플릿의 rpp=20 안에서 고른다 — 공지가 10개를 넘게 쌓이면 제보 줄이 10개 미만으로 보일 수 있다)
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

	<?php foreach($tp_reports as $tp_report):?>
	<?php
	// 썸네일 찾는 규칙은 tp-kboard-skin.php의 tp_kboard_thumb()에 모아 두었다(목록 화면과 같이 쓴다)
	$tp_thumb = tp_kboard_thumb($tp_report, 160);
	?>
	<a class="tp-latest-report" href="<?php echo $url->getDocumentURLWithUID($tp_report->uid)?>">
		<span class="tp-latest-thumb<?php if(!$tp_thumb):?> is-empty<?php endif?>"><?php if($tp_thumb):?><img src="<?php echo esc_url($tp_thumb)?>" alt="" loading="lazy"><?php endif?></span>
		<span class="tp-latest-text">
			<span class="tp-latest-title">
				<?php if($tp_report->isNew()):?><span class="tp-latest-pill is-new">NEW</span><?php endif?>
				<?php if($tp_report->secret):?><span class="kboard-icon-lock"></span><?php endif?>
				<?php echo $tp_report->title?>
			</span>
			<span class="tp-latest-meta"><?php echo $tp_report->getUserDisplay()?> · <?php echo tp_kboard_date($tp_report)?></span>
		</span>
	</a>
	<?php endforeach?>
	<?php if(!$tp_reports):?>
	<?php // 공지만 있고 제보가 없을 때도 빈 칸 대신 안내를 보여 준다 (KBoard는 목록이 아예 비었을 때만 안내를 띄운다) ?>
	<div class="kboard-latest-empty-list-message">아직 제보가 없어요. 첫 제보를 남겨 주세요.</div>
	<?php endif?>
</div>
<?php echo preg_replace('/>\s+</', '><', preg_replace('/\s+/', ' ', ob_get_clean()));