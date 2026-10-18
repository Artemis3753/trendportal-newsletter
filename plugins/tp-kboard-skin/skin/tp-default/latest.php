<?php
/*
 * 홈 '독자 제보' 칸 (1.3.0, 10/18 사용자 결정)
 * - 맨 위: 공지 줄(시안 G1) — 공지글 중 가장 최근 것 하나. 확성기 칸은 제보 썸네일과 같은 56px라 '공지'·'NEW' 알약이 같은 세로줄에 선다
 * - 그 아래: 제보 줄(시안 H1) — 공지가 아닌 제보 중 가장 최근 것 하나. 작성자·시간은 제목 아래 회색 한 줄
 * KBoard 최신글 목록은 공지와 일반 글을 섞어 최신순으로 넘겨준다. 그래서 공지가 제보 자리를 차지하지 않게 여기서 나눈다.
 * (홈 템플릿의 rpp=5 안에서 고른다 — 공지가 5개를 넘게 쌓이면 제보 줄이 비어 보일 수 있다)
 */
$tp_notice = null;
$tp_report = null;
while($content = $list->hasNext()){
	if($content->notice){
		if(!$tp_notice) $tp_notice = $content;
	}
	else if(!$tp_report){
		$tp_report = $content;
	}
	if($tp_notice && $tp_report) break;
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

	<?php if($tp_report):?>
	<?php
	// 대표 이미지가 없으면 KBoard가 본문 첫 사진을 대신 돌려준다(getThumbnail).
	// 비밀글 사진이 홈에 노출되면 안 되므로 비밀글은 썸네일을 만들지 않는다.
	$tp_thumb = $tp_report->secret ? '' : $tp_report->getThumbnail(160, 160);
	?>
	<a class="tp-latest-report" href="<?php echo $url->getDocumentURLWithUID($tp_report->uid)?>">
		<span class="tp-latest-thumb<?php if(!$tp_thumb):?> is-empty<?php endif?>"><?php if($tp_thumb):?><img src="<?php echo esc_url($tp_thumb)?>" alt="" loading="lazy"><?php endif?></span>
		<span class="tp-latest-text">
			<span class="tp-latest-title">
				<?php if($tp_report->isNew()):?><span class="tp-latest-pill is-new">NEW</span><?php endif?>
				<?php if($tp_report->secret):?><span class="kboard-icon-lock"></span><?php endif?>
				<?php echo $tp_report->title?>
			</span>
			<span class="tp-latest-meta"><?php echo $tp_report->getUserDisplay()?> · <?php echo $tp_report->getDate()?></span>
		</span>
	</a>
	<?php else:?>
	<?php // 공지만 있고 제보가 없을 때도 빈 칸 대신 안내를 보여 준다 (KBoard는 목록이 아예 비었을 때만 안내를 띄운다) ?>
	<div class="kboard-latest-empty-list-message">아직 제보가 없어요. 첫 제보를 남겨 주세요.</div>
	<?php endif?>
</div>
<?php echo preg_replace('/>\s+</', '><', preg_replace('/\s+/', ' ', ob_get_clean()));