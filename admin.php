<?php
/*
 * https://overblog.uservoice.com/knowledgebase/articles/1852513-r%C3%A9diger-une-page
 * */

# Control du token du formulaire
plxToken::validateFormToken($_POST);

# Control de l'accès à la page en fonction du profil de l'utilisateur connecté
$plxAdmin->checkProfil(PROFIL_ADMIN);

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

		// status

		# Categories de PluXml
		$cats = array();
		foreach($eklablog->xpath('posts/post/tags') as $tags) {
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
		# On recharge les catégories
		$plx_cats = array_map(function($value) {
			return $value['name'];
		}, $plxAdmin->aCats);

		# articles
		$artId = ''; # parametre par référence pour plxAdmin::editArticle()
		foreach($eklablog->xpath('posts/post') as $post) {

			$pattern = '#(?:' . str_replace(',', '|', preg_replace('#\d::#', 'Ek_', $post->tags)) . ')#i';
			$aCats = array_filter($plxAdmin->aCats, function($value) use($pattern) {
				return preg_match($pattern, $value['name']);
			});
			if(!empty($aCats)) {
				$aCats = array_keys($aCats);
			}

			$draft = (intval($post->status) == 1);
			// status : 1 brouillon - 2 publié
			if($draft) {
				# Voir article.php
				if(empty($aCats)) {
					$aCats[] = '000';
				}
				array_unshift($aCats, 'draft');
			}

			/*
			 * A supprimer dans $post->content :
			 *
			 * Gérer <div   class="ob-section ob-section-images ...
			 *  - </div><div class="ob-row-2-col">
			 * */
			$patterns = array(
				# Reformatage <div> de plusieurs lignes
				'#^\s*<div[\s\r\n]+(\w[^>]+)[\s\r\n]*>#mi'	=> '<div \1>',
				# Suppression image quote au format svg
				'#\s*<svg\s+class="ob-quote-\w+"[^>]*>.*?</svg>#si' => '',
				# Suppression lignes vides
				'#^\s*[\r\n]+#m' => '',
				# suppression espaces début ligne
				'#^\s+#m'	=> '',
				# double <div> sur une ligne
				'#</div>\s+<div#'	=> "</div>\n<div",
			);
			# $content = html_entity_decode($post->content);
			$content = preg_replace(array_keys($patterns), array_values($patterns), html_entity_decode($post->content));

			# Pour récupeerer les urls des images :
			# preg_match_all('#<img\ssrc="([^"]+)"#', $content, $matches);

			$article = array(
				'title'					=> trim($post->title), # cast String
				'chapo'					=> '',
				'content'				=> $content,
				'catId'					=> !empty($aCats) ? $aCats : '', # $aCats est un tableau. Peut-être vide
				'tags'					=> '',
				'author'				=> '001',
				'allow_com'				=> '0',
				'template'				=> 'article.php',

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
				break;
			};
			$artId = str_pad(intval($artId) + 1, 4, '0', STR_PAD_LEFT);
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

.<?= $plugin ?>-infos span {
	background-color: #eee;
	padding: 0.25rem 1rem;
}

#<?= $plugin ?>-frm fieldset {
	display: flex;
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
		<p>Taille maxi du fichier : <span><?= preg_replace('#^(\d+)(M|G|K)#', '\1 \2o', ini_get('upload_max_filesize')) ?></span></p>
		<p>Dossier pour téléverser : <span><?= sys_get_temp_dir() ?></span></p>
	</div>
	<form id="<?= $plugin ?>-frm" method="post" enctype="multipart/form-data">
		<?= plxToken::getTokenPostMethod() ?>
		<fieldset>
			<input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxFileSize ?>" />
			<input name="archive" type="file" accept="application/zip, text/xml" placeholder="Sélectionner la sauvegarde Eklablog" required>
			<input type="submit">
		</fieldset>
	</form>
</div>
<?php
}
