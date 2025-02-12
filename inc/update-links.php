<?php
# inclus dans le hook AdminIndexPrepend

if(preg_match('#^(?:www\.)?([^\.]+)\.(' . self::HEBERGEUR . '|[a-z]+)$#', parse_url($hostname)['host'], $matches)) {
	$name = $matches[1];
	$ext = ($matches[2] == self::HEBERGEUR) ? '\.' : '\.(?:' . self::HEBERGEUR . '|' .  $matches[2] . ')';
	$query = $query = '#href="(https?://(?:www\.)?' . $name . $ext . '(/main-tag)?/(.*?))"#';
	# href="http://lalutiniere.eklablog.com/exemple-de-lien-a114224266"
	# href="http://www.lalutiniere.com/tous-les-chemins-p949928"
	# href="http://www.lalutiniere.com/onde-verte-a214984021"
	# href="https://lalutiniere.com/main-tag/animations-c21559219"
	# href="https://lalutiniere.com/2025/01/bienvenue-a-la-lutiniere.html"
	# 1358.004,014.001.202412211902.2025_01_bienvenue-a-la-lutiniere.xml

	$root = PLX_ROOT . $plxAdmin->aConf['racine_articles'];
	$artsRoot = $plxAdmin->aConf['racine_articles'];

	$links = array_flip(array_map(
		function ($filename) {
			return preg_replace('#.*\.([\w-]*)\.xml$#', '$1', $filename);
		},
		$plxAdmin->plxGlob_arts->aFiles
	));
	ksort($links);

	$log_file = realpath(PLX_ROOT . PLX_CONFIG_PATH . 'plugins') . '/' . get_class($this) . '-links.log';
	if(file_exists($log_file)) {
		unlink($log_file);
	}

	foreach($plxAdmin->plxGlob_arts->aFiles as $artId=>$filename) {
		$target = $root . $filename;
		$art = simplexml_load_file($target);

		$save = false;
		foreach(array('chapo', 'content') as $name) {
			$content = trim($art->{$name});
			if(empty($content)) {
				continue;
			}

			if(preg_match_all($query, $content, $matches, PREG_SET_ORDER)) {
				$replaces = array();
				foreach($matches as $group) {
					$href = $group[1];
					if(empty($group[2])) {
						# C'est un article
						$url = $group[3];
						$msg = $artId . "\t" . $href ."\t";
						if(array_key_exists($url, $links)) {
							$id = $links[$url];
							$replaces[$group[0]] = 'href="index.php?article' . $id . '/' . $url . '"';
							$msg .= '✅';
						} else {
							$msg .= '❌';
						}
						error_log($msg . PHP_EOL, 3, $log_file);
					}
				}

				if(!empty($replaces)) {
					$art->{$name} = strtr($content, $replaces);
					$save = true;
				}
			}
		}

		if($save) {
			$art->asXML($target);
		}
	}
}
