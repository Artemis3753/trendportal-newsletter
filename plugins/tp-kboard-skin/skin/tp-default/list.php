<div id="kboard-default-list">
	
	<!-- 게시판 정보 시작 -->
	<div class="kboard-list-header">
		<?php if(!$board->isPrivate()):?>
			<div class="kboard-total-count">
				<?php echo __('Total', 'kboard')?> <?php echo number_format($board->getListTotal())?>
			</div>
		<?php endif?>
		
		<div class="kboard-sort">
			<form id="kboard-sort-form-<?php echo $board->id?>" method="get" action="<?php echo esc_url($url->toString())?>">
				<?php echo $url->set('pageid', '1')->set('category1', '')->set('category2', '')->set('target', '')->set('keyword', '')->set('mod', 'list')->set('kboard_list_sort_remember', $board->id)->toInput()?>
				
				<?php
				if(isset($_GET['kboard_search_option']) && is_array($_GET['kboard_search_option'])){
					foreach($_GET['kboard_search_option'] as $key=>$value){
						foreach($value as $k=>$v){
							echo '<input type="hidden" name="kboard_search_option['.esc_attr($key).']['.esc_attr($k).']" value="'.esc_attr($v).'">';
						}
					}
				}
				?>
				
				<select name="kboard_list_sort" onchange="jQuery('#kboard-sort-form-<?php echo $board->id?>').submit();">
					<option value="newest"<?php if($list->getSorting() == 'newest'):?> selected<?php endif?>><?php echo __('Newest', 'kboard')?></option>
					<option value="viewed"<?php if($list->getSorting() == 'viewed'):?> selected<?php endif?>><?php echo __('Viewed', 'kboard')?></option>
					<option value="updated"<?php if($list->getSorting() == 'updated'):?> selected<?php endif?>><?php echo __('Updated', 'kboard')?></option>
				</select>
			</form>
		</div>
	</div>
	<!-- 게시판 정보 끝 -->
	
	<!-- 카테고리 시작 -->
	<?php
	if($board->use_category == 'yes'){
		if($board->isTreeCategoryActive()){
			$category_type = 'tree-select';
		}
		else{
			$category_type = 'default';
		}
		$category_type = apply_filters('kboard_skin_category_type', $category_type, $board, $boardBuilder);
		echo $skin->load($board->skin, "list-category-{$category_type}.php", $vars);
	}
	?>
	<!-- 카테고리 끝 -->
	
	<!-- 리스트 시작 -->
	<?php
	// 1.5.0: 표 대신 카드 격자(10/29 사용자가 시안 C를 골랐다). 공지는 맨 위 한 줄, 제보는 정사각 사진 카드.
	// 번호·조회수 칸은 없앴고, 답글 줄(builderReply)도 그리지 않는다 — 제보 게시판은 답글을 쓰지 않는다.
	// 카드는 PC에서 약 220px라 레티나 화면에서도 선명하도록 400px 썸네일을 쓴다(홈 목록은 56px라 160px).
	$tp_cards = array();
	while($content = $list->hasNextPopular()) $tp_cards[] = $content;
	while($content = $list->hasNext()) $tp_cards[] = $content;
	$tp_has_notice = false;
	?>
	<div class="tp-board">
		<?php while($content = $list->hasNextNotice()): $tp_has_notice = true; ?>
		<a class="tp-board-notice" href="<?php echo esc_url($url->getDocumentURLWithUID($content->uid))?>">
			<span class="tp-pill is-notice">공지</span>
			<span class="tp-board-notice-title"><?php echo $content->title?></span>
		</a>
		<?php endwhile?>

		<?php if($tp_cards):?>
		<div class="tp-board-grid">
			<?php foreach($tp_cards as $content):?>
			<?php $tp_thumb = tp_kboard_thumb($content, 400)?>
			<a class="tp-card" href="<?php echo esc_url($url->getDocumentURLWithUID($content->uid))?>">
				<span class="tp-card-thumb<?php if(!$tp_thumb):?> is-empty<?php endif?><?php if($content->secret):?> is-secret<?php endif?>">
					<?php if($content->isNew()):?><span class="tp-pill is-new">NEW</span><?php endif?>
					<?php if($tp_thumb):?><img src="<?php echo esc_url($tp_thumb)?>" alt="" loading="lazy"><?php endif?>
				</span>
				<span class="tp-card-title"><?php echo $content->title?><span class="kboard-comments-count"><?php echo $content->getCommentsCount()?></span></span>
				<span class="tp-card-meta"><?php echo esc_html($content->member_display)?> · <?php echo tp_kboard_date($content)?></span>
			</a>
			<?php endforeach?>
		</div>
		<?php elseif(!$tp_has_notice):?>
		<p class="tp-board-empty">아직 제보가 없어요. 첫 제보를 남겨 주세요.</p>
		<?php endif?>
	</div>
	<!-- 리스트 끝 -->
	
	<!-- 페이징 시작 -->
	<div class="kboard-pagination">
		<ul class="kboard-pagination-pages">
			<?php echo kboard_pagination($list->page, $list->total, $list->rpp)?>
		</ul>
	</div>
	<!-- 페이징 끝 -->
	
	<!-- 검색폼 시작 -->
	<div class="kboard-search">
		<form id="kboard-search-form-<?php echo $board->id?>" method="get" action="<?php echo esc_url($url->toString())?>">
			<?php echo $url->set('pageid', '1')->set('target', '')->set('keyword', '')->set('mod', 'list')->toInput()?>
			
			<select name="target">
				<option value=""><?php echo __('All', 'kboard')?></option>
				<option value="title"<?php if(kboard_target() == 'title'):?> selected<?php endif?>><?php echo __('Title', 'kboard')?></option>
				<option value="content"<?php if(kboard_target() == 'content'):?> selected<?php endif?>><?php echo __('Content', 'kboard')?></option>
				<option value="member_display"<?php if(kboard_target() == 'member_display'):?> selected<?php endif?>><?php echo __('Author', 'kboard')?></option>
			</select>
			<input type="text" name="keyword" value="<?php echo esc_attr(kboard_keyword())?>">
			<button type="submit" class="kboard-default-button-small"><?php echo __('Search', 'kboard')?></button>
		</form>
	</div>
	<!-- 검색폼 끝 -->
	
	<?php if($board->isWriter()):?>
	<!-- 버튼 시작 -->
	<div class="kboard-control">
		<a href="<?php echo esc_url($url->getContentEditor())?>" class="kboard-default-button-small"><?php echo __('New', 'kboard')?></a>
	</div>
	<!-- 버튼 끝 -->
	<?php endif?>
	
	<?php if($board->contribution()):?>
	<div class="kboard-default-poweredby">
		<a href="https://www.cosmosfarm.com/products/kboard" onclick="window.open(this.href);return false;" title="<?php echo __('KBoard is the best community software available for WordPress', 'kboard')?>">Powered by KBoard</a>
	</div>
	<?php endif?>
</div>
<script>
	function kboardToggleAuthorMenu(element) {
		const menu = element.nextElementSibling;
		const allMenus = document.querySelectorAll('.kboard-author-menu');
		allMenus.forEach(m => {
			if (m !== menu) m.style.display = 'none';
		});
		menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
	}

	// 바깥 클릭 시 닫힘
	document.addEventListener('click', function(e) {
		const target = e.target;
		if (!target.closest('.kboard-author-dropdown')) {
			document.querySelectorAll('.kboard-author-menu').forEach(menu => {
				menu.style.display = 'none';
			});
		}
	});
</script>