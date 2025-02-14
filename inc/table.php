<?php
			# Sauvegarde de $hostname
			$plxPlugin->saveParams();
?>
<div class="scrollable-table <?= $plugin ?>">
	<table class="full-width" >
		<thead>
			<tr>
				<th>&nbsp;</th>
<?php
	foreach($plxPlugin::EK_TAGS as $tag) {
?>
				<th><?= $tag ?></th>
<?php
	}
?>
			</tr>
		</thead>
		<tbody>
<?php
		# $p = 'post';
		$p = $_POST['table'];
		foreach($eklablog->xpath($p . 's/' . $p) as $i=>$post) {
?>
			<tr>
				<td><?= ($i + 1) ?></td>
<?php
			foreach($plxPlugin::EK_TAGS as $tag) {
				$cell = trim($post->$tag);
				$title = '';
				if($tag == 'content') {
					$cell = preg_match($plxPlugin::EMPTY_CONTENT, $cell) ? '❌' : '✅';
				} elseif(!empty($cell) and $tag == 'tags') {
					if(!empty($cell)) {
						$mask = '2::main-tag';
						if(preg_match('#\b' . $mask . '\b#', $cell)) {
							$parts = array_filter(explode(',', $cell), function($value) use($mask) { return ($value != $mask); });
							if(!empty($parts)) {
								$href = $hostname . '/' . array_values($parts)[0];
								$cell = '<a href="' . $href . '" target="_blank">' . $cell . '</a>';
							}
						}
					}
				} else {
					$title = (strlen($cell) < 50) ? '' : ' title="' . $cell . '"';
					if($tag == 'slug' and !empty($hostname)) {
						$href = $hostname . '/' . $cell;
						$cell = '<a href="' . $href . '" target="_blank">' . $cell . '</a>';
					}
				}
?>
				<td<?= $title ?>><?= $cell ?></td>
<?php
			}
?>
			</tr>
<?php
		}
?>
		</tbody>
	</table>
</div>
<div class="in-action-bar <?= $plugin ?> preview">
	<span class="preview"><strong><?= $p ?></strong></span> <a class="button" href="plugin.php?p=<?= $plugin ?>"><?= $plxPlugin->getLang('BACK') ?></a>
</div>
<?php
