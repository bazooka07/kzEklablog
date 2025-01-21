<?php
// include 'prepend.php';

/*
 * https://overblog.uservoice.com/knowledgebase/articles/1852513-r%C3%A9diger-une-page
 * */

# Control du token du formulaire
plxToken::validateFormToken($_POST);

# Control de l'accès à la page en fonction du profil de l'utilisateur connecté
$plxAdmin->checkProfil(PROFIL_ADMIN);

if(!empty($_FILES['archive'])) {
	# switch(mime_content_type($_FILES['archive']['tmp_name'])) {
	switch($_FILES['archive']['type']) {
		case 'application/zip':
			$zip = new ZipArchive();
			if($zip->open($_FILES['archive']['tmp_name'])) {
				$eklablog = simplexml_load_string($zip->getFromIndex(0));
				$zip->close();
			}
			break;
		case 'text/xml':
			$eklablog = simplexml_load_file($_FILES['archive']['tmp_name']);
			break;
		default:
			plxMsg::Error('Mauvais format de fichier');
			header('Location: ' . basename(__FILE__));
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
			 * - <svg class="ob-quote-left"...>...</svg>
			 * - <svg class="ob-quote-right"...>...</svg>
			 *
			 * Gérer <div   class="ob-section ob-section-images ...
			 *  - </div><div class="ob-row-2-col">
			 * */
			$content = trim(html_entity_decode($post->content));

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

// include 'top.php';

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
?>
<form method="post" enctype="multipart/form-data">
	<?= plxToken::getTokenPostMethod() ?>
	<input type="hidden" name="MAX_FILE_SIZE" value="30000" />
	<input name="archive" type="file" accept="application/zip, text/xml" placeholder="Sélectionner la sauvegarde Eklablog" required>
	<input type="submit">
</form>
<?php
}

// include 'foot.php';
