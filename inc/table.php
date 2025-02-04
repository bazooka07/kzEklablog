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
				if($tag != 'content') {
					$cell = trim($post->$tag);
					$title = (strlen($cell) < 50) ? '' : ' title="' . $cell . '"';
					if($tag == 'slug' and !empty($hostname)) {
						$href = $hostname . '/' . $cell;
						$cell = '<a href="' . $href . '" target="_blank">' . $cell . '</a>';
					}
				} else {
					$title = '';
					$cell = preg_match($plxPlugin::EMPTY_CONTENT, trim($post->content)) ? '❌' : '✅';
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
	<div class="in-action-bar <?= $plugin ?>">
		<span><strong><?= $p ?></strong></span> <a class="button" href="plugin.php?p=<?= $plugin ?>"><?= $plxPlugin->getLang('BACK') ?></a>
	</div>
</div>
<?php
