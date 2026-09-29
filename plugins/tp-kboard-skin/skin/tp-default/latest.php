<div id="kboard-default-latest">
	<table>
		<thead>
			<tr>
				<th class="kboard-latest-title"><?php echo __('Title', 'kboard')?></th>
				<th class="kboard-latest-date"><?php echo __('Date', 'kboard')?></th>
			</tr>
		</thead>
		<tbody>
			<?php while($content = $list->hasNext()):?>
			<?php
			// 대표 이미지가 없으면 KBoard가 본문 첫 사진을 대신 돌려준다(getThumbnail).
			// 비밀글 사진이 홈에 노출되면 안 되므로 비밀글은 썸네일을 만들지 않는다.
			$tp_thumb = $content->secret ? '' : $content->getThumbnail(160, 160);
			?>
			<tr>
				<td class="kboard-latest-title">
					<a href="<?php echo $url->getDocumentURLWithUID($content->uid)?>">
						<span class="tp-latest-thumb<?php if(!$tp_thumb):?> is-empty<?php endif?>"><?php if($tp_thumb):?><img src="<?php echo esc_url($tp_thumb)?>" alt="" loading="lazy"><?php endif?></span>
						<div class="kboard-default-cut-strings">
							<?php if($content->isNew()):?><span class="kboard-default-new-notify">N</span><?php endif?>
							<?php if($content->secret):?><span class="kboard-icon-lock"></span><?php endif?>
							<?php echo $content->title?>
							<span class="kboard-comments-count"><?php echo $content->getCommentsCount()?></span>
						</div>
					</a>
				</td>
				<td class="kboard-latest-date"><?php echo $content->getDate()?></td>
			</tr>
			<?php endwhile?>
		</tbody>
	</table>
</div>
