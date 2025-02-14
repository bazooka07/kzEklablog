<?php
# inclus dans le hook AdminIndexPrepend

# Dans ce contexte, $this est le plugin

# Pour afficher tous les categories (main-tag) utilisées dans les posts et pages de Eklablog :
# sed  '/main-tag/!d; s/^[0-9]*\s*.*main-tag\///' kzEklablog-links.log | sort | uniq

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

	$links = array(
		'article' => array_flip(array_map(
			function ($filename) {
				return preg_replace('#.*\.([\w-]*)\.xml$#', '$1', $filename);
			},
			$plxAdmin->plxGlob_arts->aFiles
		)),
		'categorie' => array_map(function($value) {
				return intval($value); # On réduit l'id de la catégorie à un entier
			},
			array_flip(array_map(
				function($infos) {
					return $infos['url'];
				},
				$plxAdmin->aCats
			)
		)),
	);

	foreach(array_keys($links) as $k) {
		ksort($links[$k]);
	}

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
					$msg = $artId . "\t" . $href ."\t";
					$isArticle = empty($group[2]); # sinon categorie
					$url = $isArticle ? $this->shorten_url($group[3]) : $this->shorten_main_tag($group[3]);
					$item = $isArticle ? 'article' : 'categorie';
					if(array_key_exists($url, $links[$item])) {
						$id = $links[$item][$url];
						$replaces[$group[0]] = 'href="index.php?' . $item . $id . '/' . $url . '"';
						$msg .= '✅';
					} elseif($isArticle) {
						# url d'un main-tag invalide. On suppose que c'est une categorie
						$url = $this->shorten_main_tag($group[3]);
						$item = 'categorie';
						if(array_key_exists($url, $links[$item])) {
							$id = $links[$item][$url];
							$replaces[$group[0]] = 'href="index.php?' . $item . $id . '/' . $url . '"';
							$msg .= '✅';
						} else {
							$msg .= $isArticle ? '❌' : '❓';
						}
					}
					else {
						$msg .= $isArticle ? '❌' : '❓';
					}
					error_log($msg . PHP_EOL, 3, $log_file);
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
