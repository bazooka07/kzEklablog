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
					$parts = array_map('trim', explode(',', $tags));
					if(in_array('2::main-tag', $parts)) {
						$parents = $tags->xpath('../title');
						$contents = $tags->xpath('../content');
						if(!empty($parents) and !empty($contents)) {
							if(!preg_match($plxPlugin::EMPTY_CONTENT, trim($contents[0]))) {
								$cats[] = trim($parents[0]);
							}
						}
					} else {
						foreach($parts as $part) {
							/*
							 * tags spéciaux :
							 *
							 * 2::pinned
							 * 2::carousel
							 * */
							$cats[] = preg_replace('#^\d::#', 'Ek_', $part);
						}
					}
				}
				sort($cats);
				$cats = array_unique($cats);

				# catégories supplémentaire pour étudier les status ds posts et page sde Eklablog
				for($i=0; $i<10; $i++) {
					$cats[] = 'Ek_status_' . $i;
				}

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
					if(preg_match($plxPlugin::EMPTY_CONTENT, trim($post->content))) {
						# empty content
						continue;
					}

					$ek_tags =trim($post->tags);
					$name = 'Ek_' . $p . 's';
					if(empty($ek_tags)) {
						$ek_tags = $name;
					} else {
						$ek_tags .= ',' . $name;
					}

					$pattern = '#(?:' . str_replace(',', '|', preg_replace('#\d::#', 'Ek_', $ek_tags)) . '|Ek_status_' . intval($post->status) .  ')#i';
					$aCats = array_filter($plxAdmin->aCats, function($value) use($pattern) {
						return preg_match($pattern, $value['name']);
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
								# $matches[3] : $matches[2] without hostname
								$pathname = parse_url($matches[2], PHP_URL_PATH);
								if(preg_match('#^.*/image(.*)$#', $pathname, $groups)) {
									$pathname = urldecode($groups[1]);
								}
								$pathname = ltrim($pathname, '/');
								$target = $mediasRoot . $pathname;
								$images[$target] = $matches[2];
								return '<img' . $matches[1] .' src="' . $target . '"';
							},
							$content
						);
					}

					$url = str_replace('/', '_', preg_replace('#\.html?$#', '', trim($post->slug)));
					if(strlen($url) > 64) {
						# Shrinking $url
						$parts = explode('-', $url); # E.G.: tableau-vivant-la-pedagogie-au-service-des-sens-et-du-sens-a216234061
						$last = array_pop($parts);
						$n = strlen($last);
						$tmp = '';
						foreach($parts as $p) {
							if(strlen($tmp) + strlen($p) + 1 > 64) {
								break;
							}
							$tmp .= $p . '-';
						}
						$tmp .= $last;
						$url = $tmp;
					}

					$article = array(
						'artId'					=> $artId,
						'title'					=> trim($post->title), # cast String
						'url'					=> $url,
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
							$filename = PLX_ROOT . $target;
							if(file_exists($filename)) {
								# L'image existe déjà
								continue;
							}

							$path = PLX_ROOT . $plxAdmin->aConf['medias'] . pathinfo($target, PATHINFO_DIRNAME);
							if(!is_dir($path)) {
								mkdir($path, 0775, true);
							}
							$ch = curl_init($url);
							curl_setopt_array($ch, array(
								CURLOPT_RETURNTRANSFER => true,
								CURLOPT_FOLLOWLOCATION => true,
							));
							$img = curl_exec($ch);
							error_log($target . ' : ' . $url . PHP_EOL, 3, $log_file);
							if($img === false or file_put_contents($filename, $img) === false) {
								$msg = $plxPlugin->getLang('DENIED_IMAGE_STORAGE');
								plxMsg::Error($msg);
								error_log($msg . PHP_EOL, 3, $log_file);
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
			header('Location: index.php');
			exit;
