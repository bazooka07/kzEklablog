<?php
			// $eklablog->blog
			$artId = ''; # parametre par référence pour plxAdmin::editArticle

			$log_file = realpath(PLX_ROOT . PLX_CONFIG_PATH . 'plugins') . '/' . $plugin . '-img.log';
			if(file_exists($log_file)) {
				# reset
				unlink($log_file);
			}

			# Mémorisattion des paramètres du plugin
			foreach(kzEklablog::CHECKBOXES as $k) {
				$fieldname = 'import-' . $k;
				$v = filter_input(INPUT_POST, $fieldname, FILTER_VALIDATE_INT, kzEklablog::FILTER_OPTIONS_INT);
				$plxPlugin->setParam($fieldname, $v, 'numeric');
			}

			if(!empty($plxPlugin->getParam('import-delete_all_before'))) {
				# Delete all articles and comments
				foreach(array('racine_articles', 'racine_commentaires',) as $k) {
					array_map('unlink', glob(PLX_ROOT . $plxAdmin->aConf[$k] . '*.xml'));
				}
				$artId = '0001';
			}

			/* ---------- Gestion des catégories et main-tag ----------- */

			# On supprime toutes les catégories existantes
			$plxAdmin->aCats= array();

			$newCats = array();
			foreach($eklablog->xpath('//tags[contains(text(), "' . $plxPlugin::MAIN_TAG . '")]') as $tag) {
				$parts = explode(',', trim($tag));
				if(($k = array_search($plxPlugin::MAIN_TAG, $parts)) !== false) {
					$title = trim($tag->xpath('../title')[0]);
					unset($parts[$k]);
					if(!empty($parts)) {
						$url = $plxPlugin->shorten_main_tag(array_values($parts)[0]);
						# On évite les doublons avec la même url
						if(!array_key_exists($url, $newCats)) {
							$newCats[$url] = trim($tag->xpath('../title')[0]);
						}
					}
				}
			}

			foreach(array('page', 'post') as $k) {
				$url = 'ek_' . $k . 's';
				$newCats[$url] = ucfirst($url);
			}

			# Quelques tags spéciaux de EKlablog : 2::pinned 2::carousel
			foreach(array('2::pinned', '2::carousel') as $k) {
				$url = preg_replace('#^\d::#', 'Ek_', $k);
				$newCats[$url] = $plxPlugin->getLang(strtoupper($url));
			}

			# Catégories pour les status des posts et pages
			for($i=1; $i<8; $i++) {
				$url = 'ek_status_' . $i;
				$newCats[$url] = $plxPlugin->getLang(strtoupper($url));
			}

			if(!empty($plxAdmin->aCats)) {
				# On récupère les urls des catégories existantes
				$urls = array_flip(array_map(
					function($value) {
						return $value['url'];
					},
					$plxAdmin->aCats
				));

				$newCats = array_filter(
					$newCats,
					function($url) use($urls) {
						return !array_key_exists($url, $urls);
					},
					ARRAY_FILTER_USE_KEY
				);
			}

			$plxPlugin->newCats = $newCats;
			unset($newCats);

			# On ajoute une nouvelle catégorie quelconque pour déclencher le hook plxAdminEditCategoriesNew
			$plxAdmin->editCategories(array(
				'new_category'	=> '1',
				'new_catname'	=> $plugin,
			));

			/* ---------- importation des post et pages depuisla sauvegarde de Eklablog --- */

			$imgListing =  array();
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

				# On parcourt les posts et pages du blog Eklablog
				foreach($eklablog->xpath($p . 's/' . $p) as $post) {
					if(preg_match($plxPlugin::EMPTY_CONTENT, trim($post->content))) {
						# empty content
						continue;
					}

					$ek_tags =trim($post->tags);
					$name = 'ek_' . $p . 's';
					if(empty($ek_tags)) {
						$ek_tags = $name;
					} else {
						$ek_tags .= ',' . $name;
					}

					$pattern = '#(?:' . str_replace(',', '|', preg_replace('#\d::#', 'Ek_', $ek_tags)) . '|ek_status_' . intval($post->status) .  ')#i';
					$aCats = array_filter($plxAdmin->aCats, function($value) use($pattern) {
						return preg_match($pattern, $value['url']);
					});
					if(!empty($aCats)) {
						$aCats = array_keys($aCats);
						sort($aCats);
					}

					// status : 1 brouillon - 2 publié - 3 modéré ? - 4 programmé - 7 mot de passe
					$draft = (intval($post->status) == 1);
					if($draft) {
						# Voir article.php
						if(empty($aCats)) {
							$aCats[] = '000';
						}
						array_unshift($aCats, 'draft');
					}

					$content = preg_replace(array_keys(kzEklablog::CLEANUP_HTML), array_values(kzEklablog::CLEANUP_HTML), html_entity_decode($post->content));

					/* === Gestion des images === */

					$images = array();
					$chkImg = 'import-images';
					if(!empty($_POST[$chkImg]) and $_POST[$chkImg] == '1') {
						# Pour récupérer les urls des images :
						$mediasRoot = $plxAdmin->aConf['medias'];
						$content = preg_replace_callback(
							$plxPlugin::PATTERN_IMG, # '#<img\b([^>]*)\ssrc="(https?://[^/]+/([^"]*))"#'
							function($matches) use(&$images, $mediasRoot) {
								/*
								 * $matches[2] : original value of src attribute - Possible values :
								 * https://www.laviedessaints.com/wp-content/uploads/2023/12/Illustration-de-sainte-Barbe.webp
								 * https://ekladata.com/LzhQH9O4J2R2jISnmjG1Eeekit0@755x488.jpg
								 * https://image.eklablog.com/tAQGyWSeHaT1dI_4HxYtCH8xMEM=/filters:no_upscale()/image%2F0651865%2F20250126%2Fob_349548_hiver.jpg
								 * https://cdn.pflanzmich.de/produkt/37889/Rosa-corymbifera2_origin_img.jpg?progressive=1
								 * */
								$parts = parse_url($matches[2]);
								if(preg_match('#filters:[^/]*/(.*)$#', $parts['path'], $groups)) {
									$parts['path'] = urldecode($groups[1]);
								}

								$pathname = preg_match('#\bekladata.com$#', $parts['host']) ? ltrim($parts['path'], '/') : $parts['host'] . $parts['path'];
								$target = $mediasRoot . $pathname;
								$images[$target] = $matches[2];
								return '<img' . $matches[1] .' src="' . $target . '"';
							},
							$content
						);

						if(!empty($images)) {
							foreach($images as $target=>$url) {
								$imgListing[] = $target . "\t" . $url;
							}
						}
					}

					$url = str_replace('/', '_', preg_replace('#\.html?$#', '', trim($post->slug)));

					$article = array(
						'artId'					=> $artId,
						'title'					=> trim($post->title), # cast String
						'url'					=> $plxPlugin->shorten_url($url),
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

					/* === Importation immédiate des images === */

					$chkDirectImg = 'import-direct_images';
					if(!empty($images) and !empty($_POST[$chkDirectImg]) and $_POST[$chkDirectImg] == '1') {
						include 'import-medias.php';
					}

					$artId = str_pad(intval($artId) + 1, 4, '0', STR_PAD_LEFT);
				}
			} // End of : foreach(array('post', 'page',) as $p)

			if(!empty($imgListing)) {
				# Listing de toutes les images du site à importer
				usort($imgListing, function($a, $b) {
					$ta = explode("\t", $a);
					$tb = explode("\t", $b);
					return strcmp($ta[0], $tb[0]);
				});
				file_put_contents(realpath(PLX_ROOT . PLX_CONFIG_PATH . 'plugins') . '/' . $plugin . '-img.lst', implode(PHP_EOL, array_unique($imgListing)) . PHP_EOL);
			}

			$plxPlugin->saveParams();

			plxMsg::Info('Importation terminée');
			$_SESSION[$plugin] = $plxPlugin->getParam('hostname');
			header('Location: index.php');
			exit;
