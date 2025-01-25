<?php
/*
 * https://overblog.uservoice.com/knowledgebase/articles/1852513-r%C3%A9diger-une-page
 * */

# Control du token du formulaire
plxToken::validateFormToken($_POST);

# Control de l'accès à la page en fonction du profil de l'utilisateur connecté
$plxAdmin->checkProfil(PROFIL_ADMIN);

function getTemplates($dir, $pattern) {
	# On récupère les templates des articles
	$files = glob($dir . 'article*.php');
	if (empty($files)) {
		return array('' => L_NONE1);
	}

	$aTemplates = array('' => '...');
	foreach($files as $v) {
		$aTemplates[basename($v)] = basename($v, '.php');
	}
	asort($aTemplates);
	return $aTemplates;
}

$root = PLX_ROOT . $plxAdmin->aConf['racine_themes'] . $plxAdmin->aConf['style'] . '/' ;
$pattern = '#^article(?:-[\w-]+)?\.php$#';
$aArticleTemplates = getTemplates($root, $pattern);

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
		$artId = ''; # parametre par référence pour plxAdmin::editArticle()

		foreach(array('post', 'page',) as $p) {
			$chk = 'import-' . $p;
			if(empty($_POST[$chk]) or $_POST[$chk] != '1') {
				continue;
			}

			$template = htmlspecialchars($_POST['template-' . $p]);
			if(!preg_match('#^article\b.*\.php$#', $template)) {
				$template = 'article.php';
			}

			$isPage = ($p == 'page');

			# Categories de PluXml
			$cats = array();

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

			# pages de Eklablog
			if($isPage) {
				$name = 'Ek_pages';
				if(!in_array($name, $plx_cats)) {
					# Nouvelle catégorie
					$plxAdmin->editCategories(array(
						'new_category'	=> '1',
						'new_catname'	=> $name,
					));
					# On recharge la nouvelle liste
					$plxAdmin->getCategories(path('XMLFILE_CATEGORIES'));
				}
			}

			# On parcourt les posts et pages du blog Eklablog
			foreach($eklablog->xpath($p . 's/' . $p) as $post) {
				# On recharge les catégories
				$plx_cats = array_map(function($value) {
					return $value['name'];
				}, $plxAdmin->aCats);

				$ek_tags =trim($post->tags);
				if($isPage) {
					if(empty('Ek_pages')) {
						$ek_tags = 'Ek_pages';
					} else {
						$ek_tags .= ',' . 'Ek_pages';
					}
				}
				if(!empty($ek_tags)) {
					$pattern = '#(?:' . str_replace(',', '|', preg_replace('#\d::#', 'Ek_', $ek_tags)) . ')#i';
					$aCats = array_filter($plxAdmin->aCats, function($value) use($pattern) {
						return preg_match($pattern, $value['name']);
					});
					if(!empty($aCats)) {
						$aCats = array_keys($aCats);
						sort($aCats);
					}
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

				# Pour récupeerer les urls des images :
				# preg_match_all('#<img\ssrc="([^"]+)"#', $content, $matches);

				$article = array(
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

				$artId = str_pad(intval($artId) + 1, 4, '0', STR_PAD_LEFT);
			}
		}
		plxMsg::Info('Importation terminée');
	} else {
		plxMsg::Error('Erreur inconnue');
	}
	header('Location: index.php');
	exit;
}

?>
<style>
.<?= $plugin ?>-container {
	max-width: 64rem;
}

.<?= $plugin ?>-infos {
	border: 1px solid #333;
	padding: 0 1rem;
	margin-bottom: 0.5rem;
	border-radius: 1rem;
}

.<?= $plugin ?>-infos span:first-of-type {
	display: inline-block;
	width: 45%;
}

.<?= $plugin ?>-infos span:last-of-type {
	background-color: #eee;
	padding: 0.25rem 1rem;
}

#<?= $plugin ?>-frm p {
	margin: 0.25rem 0.5rem;
}

#<?= $plugin ?>-frm fieldset {
	padding: 0.25rem 1.25rem;
	border: 1px solid #333;
	border-radius: 1rem;
}

#<?= $plugin ?>-frm fieldset:not(:last-of-type) {
	margin-bottom: 1rem;
}

#<?= $plugin ?>-frm fieldset:last-of-type {
	display: flex;
}

#<?= $plugin ?>-frm fieldset legend {
	margin: 0;
	padding: 0 1rem;
}

#<?= $plugin ?>-frm label {
	display: inline-block;
	width: 45%;
}
#<?= $plugin ?>-frm input[type="file"] {
	flex-grow: 1;
}
</style>
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
		<p><span>Taille maxi du fichier</span><span><?= preg_replace('#^(\d+)(M|G|K)#', '\1 \2o', ini_get('upload_max_filesize')) ?></span></p>
		<p><span>Dossier pour téléverser</span><span><?= sys_get_temp_dir() ?></span></p>
	</div>
	<form id="<?= $plugin ?>-frm" method="post" enctype="multipart/form-data">
		<?= plxToken::getTokenPostMethod() ?>
		<fieldset>
			<legend>Importer :</legend>
			<p>
				<label>
					<input type="checkbox" name="import-post" value="1" checked>
					<span>Articles</span>
				</label>
				<label>
					<span>Template</span>
					<?php plxUtils::printSelect('template-post', $aArticleTemplates); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="import-page" value="1" checked>
					<span>Pages</span>
				</label>
				<label>
					<span>Template</span>
					<?php plxUtils::printSelect('template-page', $aArticleTemplates); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="import-comments" checked>
					<span>Commentaires</span>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="import-images" disabled>
					<span>Images</span> ( <em>Not implemented</em> )
				</label>
			</p>
		</fieldset>
		<fieldset>
			<input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxFileSize ?>" />
			<input name="archive" type="file" accept="application/zip, text/xml" placeholder="Sélectionner la sauvegarde Eklablog" required>
			<input type="submit">
		</fieldset>
	</form>
</div>
<?php
}
