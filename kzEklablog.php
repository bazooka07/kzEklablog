<?php
if(!defined('PLX_ROOT')) {
	exit;
}

/*
 * Pour supprimer articles, commentaires et medias, à la racine de PluXml :
 * sudo rm -R data/medias data/medias/.thumbs data/{article,commentaire}s/*.xml data/configuration/tags.xml
 * */

class kzEklablog extends plxPlugin {
	const BEGIN_CODE = '<?php # ' . __CLASS__ . ' plugin' . PHP_EOL;
	const END_CODE = PHP_EOL . '?>';
	const TEMPLATE_PATTERN = '#^article(?:-[\w-]+)?\.php$#';
	const FILTER_OPTIONS_INT = array(
		'default' => 0,
		'min_range' => 0,
		'max_range' => 1
	);
	const CHECKBOXES = array('post', 'page', 'comments', 'images', 'direct_images', 'delete_all_before');
	const DATES_DICT = array(
		'date_creation'	=> 'created_at',
		'date_update'	=> 'modified_at',
		'date_publication'	=> 'published_at',
	);
	const EMPTY_CONTENT = '#^<div\b[^>]*>\s*<div\b[^>]*>\s*</div>\s*</div>$#';
	const CLEANUP_HTML = array(
		# Suppression lignes vides
		'#^\s*[\r\n]+#m' => '',
		# suppression des attributs style
		# '#\s*style="[^"]*"#' => '',
		# Suppression du javascript
		'#\s*<script\b.*?</script>#si' => '',
		# Reformatage <div> de plusieurs lignes
		'#^\s*<div[\s\r\n]+(\w[^>]+)[\s\r\n]*>#mi'	=> '<div \1>',
		# Suppression image quote au format svg
		'#\s*<svg\s+class="ob-quote-\w+"[^>]*>.*?</svg>#si' => '',
		# suppression espaces début ligne
		'#^\s+#m'=> '',
		# double <div> ou <p> sur une ligne
		'#</(div|p)>\s*<\1#' => '</$1>' . PHP_EOL . '<$1',
		#
		'#[\r\n]+\s*<#m' => '<',
	);

	/*
	 * https://cdn.pflanzmich.de/produkt/37889/Rosa-corymbifera2_origin_img.jpg?progressive=1
	 * https://image.eklablog.com/4bu-S5zS_l2SJLntj8i09U188po=/filters:no_upscale()/image%2F0651865%2F20241212%2Fob_561223_51252505-1856305574496723-201861927890.jpg
	 * https://www.bhg.com/thmb/bTPZuLeYLUFgomVjhxip3XRXjtQ=/1561x0/filters:no_upscale():strip_icc():format(webp)/floral-pumpkin-f0438af6-8d570101e8c84f98aadde4e97185d715.jpg
	 * https://upload.wikimedia.org/wikipedia/commons/thumb/b/bb/Ghanaian_kid_playing_Blind_Fold_game_often_referred_to_as_%22Jack_Where_are_you%3F%22_with_friends._The_blind_folded_kid_moves_around_hoping_to_catch_any_of_his_friends_but_his_friends_run_around_to_avert_his_grips_04.jpg/800px-thumbnail.jpg
	 * */
	const PATTERN_IMG = '#<img\b([^>]*)\ssrc="(https?://[^"]*\.(?:jpe?g|png|gif|webp|svg)(?:\?[^"]*)?)"#i';

	const EK_TAGS = array('title', 'slug', 'status', 'tags', 'content', 'origin', 'created_at', 'published_at', 'modified_at', /* 'author', */ );

	const HEBERGEUR = 'eklablog\.com'; # Employé dans une Regex
	const MAIN_TAG = '2::main-tag';

	const HOOKS = array(
		'AdminIndexPrepend',
		'plxAdminEditCategoriesNew',
		'AdminIndexTop',
		'AdminIndexFoot',
	);

	public $newCats = null;

	public function __construct($default_lang) {
		parent::__construct($default_lang);
		$this->setAdminProfil(PROFIL_ADMIN);

		if(defined('PLX_AUTH')) {
			return;
		}

		$this->setAdminMenu('Eklablog', false, 'Importation des données du blog');
		if(defined('PLX_ADMIN')) {
			if(isset($_SESSION['profil']) and $_SESSION['profil'] == PROFIL_ADMIN) {
				foreach(self::HOOKS as $hook) {
					$this->addHook($hook, $hook);
				}
			}
		} else {
			$hook = 'ThemeEndBody';
			$this->addHook($hook, $hook);
		}
	}

	public function getTemplates($dir, $pattern) {
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

	public function printCheckbox($name) {
		$fieldname = 'import-' . $name;
		$checked = !empty($this->getParam($fieldname)) ? 'checked' : '';
?>
				<label>
					<input type="checkbox" name="<?= $fieldname ?>" value="1" <?= $checked ?>>
					<span><?= $this->getLang(strtoupper($fieldname)) ?></span>
				</label>
<?php
	}

/*
<comments>
	<comment>
		<published_at>2025-01-19T01:04:07+01:00</published_at>
		<status>1</status>
		<author_name>Toto</author_name>
		<author_email/>
		<author_url/>
		<author_ip/>
		<content>Le beau Danuble bleu</content>
		<replies>
			<comment>
				<published_at>2025-01-23T00:39:56+01:00</published_at>
				<status>1</status>
				<author_name/>
				<author_email>ptc1@free.fr</author_email>
				<author_url/>
				<author_ip>0</author_ip>
				<content>Oui ce fleuve est magnifique</content>
			</comment>
			<comment>
				<published_at>2025-01-23T10:13:32+01:00</published_at>
				<status>0</status>
				<author_name>Emile</author_name>
				<author_email/>
				<author_url>https://www.hell.com</author_url>
				<author_ip/>
				<content>
				On peut faire de très belles balades en péniche sur ce fleuve.
				</content>
			</comment>
		</replies>
	</comment>
</comments>
* */
	public function addComment($aComments, $parent='') {
		global $plxAdmin, $artId;

		foreach($aComments as $xmlComment) {
			$content = trim($xmlComment->content);
			if(!empty($content)) {
				$idx = $plxAdmin->nextIdArtComment($artId);
				$dateComment = trim($xmlComment->published_at);
				$dt = new DateTime($dateComment);
				$mod = (intval($xmlComment->status) == 1) ? '' : '_';
				$filename = $mod . $artId . '.' . $dt->getTimestamp() . '-' . $idx . '.xml';
				$author = htmlspecialchars(trim($xmlComment->author_name));
				if(empty($author)) {
					$author = $plxAdmin->aUsers['001']['name'] . ' (' . L_PROFIL_ADMIN . ')'; // login or name
				}
				$com = array(
					'type'		=> 'normal',
					'author'	=> $author,
					'content'	=> htmlspecialchars($content),
					'parent'	=> $parent,
					'filename'	=> $filename,
				);

				foreach(array(
					'mail'	=> array('author_email', FILTER_VALIDATE_EMAIL, ''),
					'site'	=> array('author_url', FILTER_VALIDATE_URL, ''),
					'ip'	=> array('author_ip', FILTER_VALIDATE_IP, '127.0.0.1'),
				) as $k=>$options) {
					list($entry, $filter, $default) = $options;
					$v = filter_var(trim($xmlComment->$entry, $filter));
					$com[$k] = is_string($v) ? $v : $default;
				}

				if($plxAdmin->addCommentaire($com)); {
					$this->addComment($xmlComment->xpath('replies/comment'), $idx);
				}
			}
		}

	}

	/*
	 * Limite les urls pour les posts et pages à moins de 64 caractères
	 *
	 * */
	public function shorten_url($url) {
		if(strlen($url) > 64) {
			# Shrinking $url
			$parts = explode('-', $url); # E.G.: tableau-vivant-la-pedagogie-au-service-des-sens-et-du-sens-a216234061
			$last = array_pop($parts); # ressemble à a112994248
			$n = strlen($last);
			$tmp = '';
			foreach($parts as $p) {
				if(strlen($tmp) + strlen($p) + 1 > 64) {
					break;
				}
				$tmp .= $p . '-';
			}
			return $tmp . $last;
		}

		return $url;
	}

	/*
	 * Pour les main-tag supprime la dernièe partie de l'ur. Peu de risque de doublon comme pour les posts et pages
	 * */
	public function shorten_main_tag($url) {
		return preg_replace('#-[a-z]\d+$#', '', $url);
	}

	/* ========= hooks ========== */

	public function AdminIndexPrepend() {
		global $plxAdmin;
		$hostname = $this->getParam('hostname'); # utilisé dans include

		if(isset($_SESSION[__CLASS__]) and $_SESSION[__CLASS__] == $hostname) {
			# On met à jour les liens internes dans les articles
			unset($_SESSION[__CLASS__]);
			include 'inc/update-links.php';
		} elseif(isset($_POST[__CLASS__ . '_add'])) {
			if(empty($_POST['idArt'])) {
				plxMsg::Error($this->getLang('MISSING_ARTICLE'));
				return;
			}

			$query = '#^(?:' . implode('|', $_POST['idArt']) . ')\..*\.xml$#';
			echo self::BEGIN_CODE;
?>
$newCat = $_POST['<?= __CLASS__ ?>_cat'];
if(!array_key_exists($newCat, $plxAdmin->aCats)) {
	# Catégorie inconnue
	return;
}

$artsRoot = PLX_ROOT . $plxAdmin->aConf['racine_articles'];
foreach($plxAdmin->plxGlob_arts->query('<?= $query ?>') as $filename) {
	# On renomme les  fichiers articles concernés
	$parts = explode('.', $filename);
	if(isset($parts[1])) {
		$artCats = explode(',', $parts[1]);
		$unclassified = array_search('000', $artCats);
		if($unclassified !== false) {
			unset($artCats[$unclassified]);
		}
		if(array_search($newCat, $artCats) === false) {
			$artCats[] = $newCat;
			# trier $artCats
			$parts[1] = implode(',', $artCats);
			$newFilename = implode('.' , $parts);
			rename($artsRoot . $filename, $artsRoot . $newFilename);
		}
	}
}

header('Location: index.php');
exit;
<?php
			echo self::END_CODE;
		}
	}

	public function plxAdminEditCategoriesNew() {
		echo self::BEGIN_CODE;
?>
$kzPlugin = $this->plxPlugins->aPlugins['<?= __CLASS__ ?>'];
# Pour récupérer des valeurs par défaut
$kzTemplate = $this->aCats[$cat_id];
$kzTemplate['description'] = 'Add by <?= __CLASS__ ?> plugin';
foreach($kzPlugin->newCats as $url=>$name) {
	$this->aCats[$cat_id] = $kzTemplate;
	$this->aCats[$cat_id]['name'] = $name;
	if(strpos($url, 'ek_status_') === 0) {
		$this->aCats[$cat_id]['menu'] = 'non';
	}
	$this->aCats[$cat_id]['url'] = $url;
	$cat_id = $this->nextIdCategory();
}
<?php
		echo self::END_CODE;
	}

	public function AdminIndexTop() {
		ob_start();
		ob_start(); # Against plxPlugins::callHook()
	}

	public function AdminIndexFoot() {
		global $plxAdmin;

		ob_get_clean(); # Against plxPlugins::callHook()
		$output = ob_get_clean();
		ob_start();
?>
<div class="in-action-bar <?= __CLASS__ ?>" method="post">
	<?php plxToken::getTokenPostMethod() . PHP_EOL ?>
	<span><?= $this->getLang('ADD_TO_CATEGORY') ?></span>
	<select name="<?= __CLASS__?>_cat">
		<option value="">....</option>
<?php
	foreach($plxAdmin->aCats as $id=>$infos) {
?>
		<option value="<?= $id ?>"><?= $infos['name'] ?></option>
<?php
	}
?>
	</select>
	<input type="submit" name="<?= __CLASS__ ?>_add">
</div>
<?php
		echo preg_replace('#(</div>)#', ob_get_clean() . '$1' , $output, 1);
	}

	public function ThemeEndBody() {
?>
<script src="<?= PLX_PLUGINS . __CLASS__ . '/' . __CLASS__ ?>.js"></script>
<?php
	}
}
