<?php
if(!defined('PLX_ROOT')) {
	exit;
}

/*
 * Pour supprimer articles, commentaires et medias, à la racine de PluXml :
 * sudo rm -R data/medias data/medias/.thumbs data/{article,commentaire}s/*.xml data/configuration/tags.xml
 * */

class kzEklablog extends plxPlugin {
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

	public function __construct($default_lang) {
		parent::__construct($default_lang);
		$this->setAdminProfil(PROFIL_ADMIN);
		$this->setAdminMenu('Eklablog', false, 'Importation des données du blog');

		if(defined('PLX_ADMIN')) {
			$hook= 'AdminIndexPrepend';
			$this->addHook($hook, $hook);
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

	/* ========= hooks ========== */

	public function AdminIndexPrepend() {
		global $plxAdmin;
		$hostname = $this->getParam('hostname'); # utilisé dans include

		if(!isset($_SESSION[__CLASS__]) or $_SESSION[__CLASS__] != $hostname) {
			return;
		}

		# On met à jour les liens internes dans les articles
		unset($_SESSION[__CLASS__]);
		include 'inc/update-links.php';
	}

	public function ThemeEndBody() {
?>
<script src="<?= PLX_PLUGINS . __CLASS__ . '/' . __CLASS__ ?>.js"></script>
<?php
	}
}
