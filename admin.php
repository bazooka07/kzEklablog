<?php
/*
 * https://overblog.uservoice.com/knowledgebase/articles/1852513-r%C3%A9diger-une-page
 * */

# Control du token du formulaire
plxToken::validateFormToken($_POST);

# Control de l'accès à la page en fonction du profil de l'utilisateur connecté
$plxAdmin->checkProfil(PROFIL_ADMIN);

$root = PLX_ROOT . $plxAdmin->aConf['racine_themes'] . $plxAdmin->aConf['style'] . '/' ;
$aArticleTemplates = $plxPlugin->getTemplates($root, $plxPlugin::TEMPLATE_PATTERN);

if(!empty($_FILES['archive'])) {
	$filename = $_FILES['archive']['tmp_name'];
	if(empty($filename)) {
		# Laragon bug
		# See https://stackoverflow.com/questions/73145407/unisharp-laravel-filemanager-cant-upload-on-laragon-server
		# See https://www.php.net/manual/fr/ini.core.php#ini.upload-tmp-dir
		$filename = sys_get_temp_dir() . '/' . $_FILES['archive']['full_path'];
		header('Content-Type: text/plain;charset=utf-8');
		var_dump($_FILES);
	}

	switch(mime_content_type($filename)) {
		case 'application/x-zip-compressed' : # Xampp
		case 'application/zip':
			$zip = new ZipArchive();
			if($zip->open($filename)) {
				$eklablog = simplexml_load_string($zip->getFromIndex(0));
				$zip->close();
			}
			break;
		case 'text/xml':
			$eklablog = simplexml_load_file($filename);
			break;
		default:
			plxMsg::Error('Format de fichier inconnu : ' . mime_content_type($filename) . ' (' . $filename . ')');
			header('Location: plugin.php?p=' . $plugin);
			exit;
	}

	if(!empty($eklablog)) {
		// $eklablog->blog
		$artId = ''; # parametre par référence pour plxAdmin::editArticle

		foreach(kzEklablog::CHECKBOXES as $k) {
			$fieldname = 'import-' . $k;
			$v = filter_input(INPUT_POST, $fieldname, FILTER_VALIDATE_INT, kzEklablog::FILTER_OPTIONS_INT);
			$plxPlugin->setParam($fieldname, $v, 'numeric');
		}

		foreach(array('post', 'page',) as $p) {
			$chk = 'import-' . $p;
			if(empty($_POST[$chk]) or $_POST[$chk] != '1') {
				continue;
			}

			$template = htmlspecialchars($_POST['template-' . $p]);
			if(!array_key_exists($template, $aArticleTemplates)) {
				$template = array_keys($template)[0];
			}
			$plxPlugin->setParam('template-' . $p, $template, 'string');

			$isPage = ($p == 'page');

			# Categories de PluXml
			$cats = array();
			if(!empty($defaultCat)) {
				$cats[] = $defaultCat;
			}


			foreach($eklablog->xpath($p . 's/' . $p . '/tags') as $tags) {
				foreach(explode(',', $tags) as $part) {
					/*
					 * tags spéciaux :
					 *
					 * 2::pinned
					 * 2::carousel
					 * */
					$cats[] = preg_replace('#^\d::#', 'Ek_', $part);
				}
			}
			sort($cats);
			$cats = array_unique($cats);
			$plx_cats = array_map(function($value) {
				return $value['name'];
			}, $plxAdmin->aCats);
			foreach($cats as $k) {
				if(!in_array($k, $plx_cats)) {
					# Nouvelle catégorie
					$plxAdmin->editCategories(array(
						'new_category'	=> '1',
						'new_catname'	=> ucfirst(strtolower($k)),
					));
					# On recharge la nouvelle liste
					$plxAdmin->getCategories(path('XMLFILE_CATEGORIES'));
				}
			}

			# catégories Ek_posts et Ek_pages de Eklablog
			$name = 'Ek_' . $p . 's';
			if(!in_array($name, $plx_cats)) {
				# Nouvelle catégorie
				$plxAdmin->editCategories(array(
					'new_category'	=> '1',
					'new_catname'	=> $name,
				));
				# On recharge la nouvelle liste
				$plxAdmin->getCategories(path('XMLFILE_CATEGORIES'));
			}

			# On parcourt les posts et pages du blog Eklablog
			foreach($eklablog->xpath($p . 's/' . $p) as $post) {
				# On recharge les catégories
				$plx_cats = array_map(function($value) {
					return $value['name'];
				}, $plxAdmin->aCats);

				$ek_tags =trim($post->tags);
				$name = 'Ek_' . $p . 's';
				if(empty($ek_tags)) {
					$ek_tags = $name;
				} else {
					$ek_tags .= ',' . $name;
				}

				$pattern = '#(?:' . str_replace(',', '|', preg_replace('#\d::#', 'Ek_', $ek_tags)) . ')#i';
				$aCats = array_filter($plxAdmin->aCats, function($value) use($pattern) {
					return preg_match($pattern, $value['name']);
				});
				if(!empty($aCats)) {
					$aCats = array_keys($aCats);
					sort($aCats);
				}

				// status : 1 brouillon - 2 publié - 3 modéré ? - 4 programmé
				$draft = (intval($post->status) == 1);
				if($draft) {
					# Voir article.php
					if(empty($aCats)) {
						$aCats[] = '000';
					}
					array_unshift($aCats, 'draft');
				}

				# $content = html_entity_decode($post->content);
				$content = preg_replace(array_keys(kzEklablog::CLEANUP_HTML), array_values(kzEklablog::CLEANUP_HTML), html_entity_decode($post->content));

				$images = array();
				$chkImg = 'import-images';
				if(!empty($_POST[$chkImg]) and $_POST[$chkImg] == '1') {
					# Pour récupérer les urls des images :
					$root = $plxAdmin->aConf['medias'];
					$content = preg_replace_callback(
						$plxPlugin::PATTERN_MEDIA,
						function($matches) use(&$images, $root) {
							$target = $root . ltrim(urldecode($matches[1]), '/');
							$images[$target] = trim($matches[0], '"');
							return '"' . $target . '"';
						},
						$content
					);
				}

				$article = array(
					'artId'					=> $artId,
					'title'					=> trim($post->title), # cast String
					'chapo'					=> '',
					'content'				=> $content,
					'catId'					=> !empty($aCats) ? $aCats : '', # $aCats est un tableau. Peut-être vide
					'tags'					=> '',
					'author'				=> '001',
					'allow_com'				=> $isPage ? '0' : '1',
					'template'				=> $template,

					'meta_description'		=> '',
					'meta_keywords'			=> '',
					'title_htmltag'			=> '',

					'thumbnail'				=> '',
					'thumbnail_alt'			=> '',
					'thumbnail_title'		=> '',

					'date_update_old'		=> '',
				);

				// $article['moderate'] = 'ok';
				if($draft) {
					$article[($draft ? 'draft' : 'publish')] = 'ok';
				}

				foreach(kzEklablog::DATES_DICT as $k=>$v) {
					if(preg_match('#^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}).*#', $post->$v, $matches)) {
						 // 2025-01-18T23:00:12+01:00
						$article[$k . '_year'] = $matches[1];
						$article[$k . '_month'] = $matches[2];
						$article[$k . '_day'] = $matches[3];
						$article[$k . '_time'] = $matches[4];
					}
				}

				if(!$plxAdmin->editArticle($article, $artId)) {
					plxMsg::Error('Article non enregistré : ' . $article['title']);
					break;
				};

				if(!$isPage and !empty($_POST['import-comments'])) {
					# Import des commentaires

					# Recursive function
					$plxPlugin->addComment($post->xpath('comments/comment'));
				}

				if(!empty($images)) {
					# On rapatrie les images
					foreach($images as $target=>$url) {
						$path = PLX_ROOT . $plxAdmin->aConf['racine_medias'] . pathinfo($target, PATHINFO_DIRNAME);
						if(!is_dir($path)) {
							mkdir($path, 0775, true);
						}
						$ch = curl_init($url);
						curl_setopt_array($ch, array(
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_FOLLOWLOCATION => true,
						));
						$img = curl_exec($ch);
						if($img === false or file_put_contents(PLX_ROOT . $target, $img) === false) {
							plxMsg::Error($this->getLang('DENIED_IMAGE_STORAGE'));
						}
						unset($img);
						curl_close($ch);

					}
				}

				$artId = str_pad(intval($artId) + 1, 4, '0', STR_PAD_LEFT);
			}
		}
		$plxPlugin->saveParams();

		plxMsg::Info('Importation terminée');
	} else {
		plxMsg::Error('Erreur inconnue');
	}
	header('Location: index.php');
	exit;
}
?>
<div class="action-bar">
	<h2>Importation sauvegarde Eklablog</h2>
</div>
<?php
if(!class_exists('ZipArchive')) {
	plxMsg::Error('Librairie Zip manquante');
} elseif(!function_exists('simplexml_load_file')) {
	plxMsg::Error('Librairie XML manquante');
} else {
	$maxFileSize = ini_get('upload_max_filesize');
	if(preg_match('#^(\d+)M$#', $maxFileSize, $matches)) {
		$maxFileSize = intval($matches[1]) * 1024 * 1024;
	} elseif(preg_match('#^(\d+)K$#', $maxFileSize, $matches)) {
		$maxFileSize = intval($matches[1]) * 1024;
	}
?>
<div class="in-action-bar">
	<a href="https://lalutiniere.eklablog.com/" target="_blank">La Lutinière</a>
</div>
<div class="<?= $plugin ?>-container">
	<div class="<?= $plugin ?>-infos">
		<p><span><?= $plxPlugin->getLang('UPLOAD_MAX_FILESIZE') ?></span><span><?= preg_replace('#^(\d+)(M|G|K)#', '\1 \2o', ini_get('upload_max_filesize')) ?></span></p>
		<p><span><?= $plxPlugin->getLang('UPLOAD_FOLDER') ?></span><span><?= sys_get_temp_dir() ?></span></p>
	</div>
	<form id="<?= $plugin ?>-frm" method="post" enctype="multipart/form-data">
		<?= plxToken::getTokenPostMethod() ?>
		<fieldset>
			<legend>Importer :</legend>
<?php
foreach(kzEklablog::CHECKBOXES as $k=>$f) {
?>
			<p>
<?php
$plxPlugin->printCheckbox($f);

	if($k < 2) {
?>
				<label>
					<span>Template</span>
					<?php plxUtils::printSelect('template-' . $f, $aArticleTemplates, $plxPlugin->getParam('template-' . $f)); ?>
				</label>
<?php
	}
?>
			</p>
<?php
}
?>
		</fieldset>
		<fieldset>
			<input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxFileSize ?>" />
			<input name="archive" type="file" accept="application/zip, text/xml" placeholder="Sélectionner la sauvegarde Eklablog" required>
			<input type="submit">
		</fieldset>
	</form>
<?php
$plugins = array_filter(
	$plxAdmin->plxPlugins->aPlugins,
	function($name) use($plugin) {
		return $name != $plugin;
	},
	ARRAY_FILTER_USE_KEY
);
if(!empty($plugins)) {
?>
	<h3><?php $plxPlugin->lang('ACTIVE_PLUGINS'); ?> :</h3>
	<ul>
<?php
	foreach($plugins as $name=>$plxPlugin) {
?>
		<li><?= $name ?> - version: <?= $plxPlugin->getInfo('version') ?> ( <em><?= $plxPlugin->getInfo('date') ?></em> )</li>
<?php
	}
?>
	</ul>
<?php
}
?>
</div>
<?php
}
