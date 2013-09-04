<?php
namespace sitecake;

use Zend\Http\PhpEnvironment\Request,
	Zend\Http\PhpEnvironment\Response,
	phpQuery\phpQuery;

use \Exception as Exception;

class renderer {
	static function process() {
		try {
			http::send(renderer::response(http::request()));
			renderer::purge();
			meta::save();
		} catch (Exception $e) {
			http::send(http::errorResponse('<h2>Exception: </h2><b>' . 
				$e->getMessage() . "</b><br/>" .
				$e->getFile() . '(' . $e->getLine() . '): <br/>' . 
				implode("<br/>", explode("\n", $e->getTraceAsString()))));
		}
	}
	
	/**
	 * 
	 * Enter description here ...
	 * @param Request $req
	 */
	static function response($req) {
		$pageFiles = renderer::pageFiles();		
		$pageUri = renderer::pageUri($req->query());
		if (array_key_exists($pageUri, $pageFiles)) {
			return renderer::assemble(
				$pageFiles[$pageUri], 
				!renderer::isLoggedin());
		} else {
			return http::notFoundResponse($req->getBasePath() . '/' . $pageUri);
		}
	}
		
	static function isLoggedin() {
		if ( isset($_COOKIE[ session::sessionName() ]) ) {
			session_start();
			return (isset($_SESSION['loggedin']) && 
				$_SESSION['loggedin'] === true);
		}
		else {
			return false;
		}
	}
	
	static function pageUri($params) {
		return isset($params['page']) ? $params['page'] : 'index.html';
	}
		
	static function isExternalLink($url) {
		return strpos($url, '/') || strpos($url, 'http://') || 
			(substr($url, -5) != '.html');
	}
	
	static function pageFiles() {
		$path = SC_ROOT;
		
		$htmlFiles = io::glob($path . '/' . '*.html');
	
		if ($htmlFiles === false || empty($htmlFiles)) {
			throw new Exception(
				resources::message('NO_PAGE_EXISTS', $path));
		}
		
		$pageFiles = array();
		foreach ($htmlFiles as $htmlFile) {
			$pageFiles[basename($htmlFile)] = $htmlFile;
		}
		
		if (!array_key_exists('index.html', $pageFiles)) {
			throw new Exception(
				resources::message('INDEX_PAGE_NOT_EXISTS', $path));
		}
				
		return $pageFiles;
	}
	
	static function loadPageFile($path) {
		if (!io::is_readable($path))
			throw new Exception(resources::message('PAGE_NOT_EXISTS', $path));
		return io::file_get_contents($path);
	}
	
	static function savePageFile($path, $content) {
		io::file_put_contents($path, $content);
	}
	
	/**
	 * 
	 * Enter description here ...
	 * @param string $pageFile
	 * @param boolean $isLogin
	 * @return Response
	 */
	static function assemble($pageFile, $isLogin) {
		$tpl = phpQuery::newDocument(renderer::loadPageFile($pageFile));
		renderer::adjustNavMenu($tpl);
		renderer::normalizeContainerNames($tpl);
		if (!$isLogin) {
			renderer::injectDraftContent($tpl, 
				draft::get(renderer::pageId($tpl, $pageFile)));
		}
		renderer::injectClientCode($tpl, $pageFile, $isLogin);
		return http::response($tpl);
	}
	
	static function normalizeContainerNames($tpl) {
		$cnt = 0;
		foreach (phpQuery::pq('[class*="sc-content"], [class*="sc-repeater-"]', 
				$tpl) as $node) {
			$container = phpQuery::pq($node, $tpl);
			$class = $container->attr('class');
			if (preg_match('/(^|\s)sc\-content($|\s)/', $class, $matches)) {
				$container->addClass('sc-content-_cnt_' . $cnt++);
			} else if (preg_match('/(^|\s)sc\-repeater-([^\s]+)($|\s)/', 
					$class, $matches)) {
				$container->addClass('sc-content-_rep_' . $matches[2]);
			}
		}
		return $tpl;		
	}
	
	static function cleanupContainerNames($tpl) {
		foreach (phpQuery::pq('[class*="sc-content-"], [class*="sc-repeater-"]',
				$tpl) as $node) {
			$container = phpQuery::pq($node, $tpl);
			$class = $container->attr('class');
			if (preg_match('/(^|\s)(sc\-content\-(_cnt_|_rep_)[^\s]+)/', 
					$class, $matches)) {
				$container->removeClass($matches[2]);
			}
		}
		return $tpl;		
	}
	
	static function containers($tpl) {
		$containers = array();
		foreach (phpQuery::pq('[class*="sc-content-"], [class*="sc-repeater-"]',
				$tpl) as $node) {
			$cNode = phpQuery::pq($node, $tpl);
			if (preg_match('/(^|\s)(sc-content-_rep_[^\s]+)/', $cNode->attr('class'),
					$matches)) {
				$containers[$matches[2]] = true;
			}
			else {
				preg_match('/(^|\s)(sc-content-[^\s]+)/', 
					$cNode->attr('class'), $matches);
				$containers[$matches[2]] = false;
			}
		}
		return $containers;			
	}
	
	static function adjustNavMenu($tpl) {
		foreach (phpQuery::pq('ul.sc-nav li a', $tpl) as $navNode) {
			$node = phpQuery::pq($navNode, $tpl);
			$href = $node->attr('href');
			if (!renderer::isExternalLink($href)) {
				$node->attr('href', 'sc-admin.php?page=' . $href);
			}
		}
		return $tpl;
	}
	
	static function injectClientCode($tpl, $pageFile, $isLogin) {
		$pageId = renderer::pageId($tpl, $pageFile);
		phpQuery::pq('head', $tpl)->append(
			renderer::clientCode($isLogin, draft::exists($pageId)));	
		return $tpl;
	}
	
	static function clientCode($isLogin, $isDraft) {
		return $isLogin ? 
			renderer::clientCodeLogin() : renderer::clientCodeEdit($isDraft);
	}
	
	static function clientCodeLogin() {
		$globals = "var sitecakeGlobals = {".
			"editMode: false, " .
			"sessionId: '<session id>', " .
			"serverVersionId: 'SiteCake CMS ${project.version}', " .
			"sessionServiceUrl:'" . SERVICE_URL . "', " .
			"configUrl:'" . CONFIG_URL . "', " .
			"forceLoginDialog: true" .
		"};";
				
		return 
			renderer::wrapToScriptTag($globals) .
			renderer::scriptTag(SITECAKE_EDITOR_LOGIN_URL);
	}
	
	static function clientCodeEdit($isDraft) {
		$globals = "var sitecakeGlobals = {".
			"editMode: true, " .
			"sessionId: '<session id>', " .
			"serverVersionId: 'SiteCake CMS ${project.version}', " .
			"sessionServiceUrl:'" . SERVICE_URL . "', " .
			"uploadServiceUrl:'" . SERVICE_URL . "', " .
			"contentServiceUrl:'" . SERVICE_URL . "', " .
			"configUrl:'" . CONFIG_URL . "', " .				
			"draftPublished: " . ($isDraft ? 'false' : 'true') .
		"};";
				
		return
			'<meta http-equiv="X-UA-Compatible" content="chrome=1">' .
			renderer::wrapToScriptTag($globals) .
			renderer::scriptTag(SITECAKE_EDITOR_EDIT_URL);
	}
	
	static function wrapToScriptTag($code) {
		return '<script type="text/javascript">' . $code . '</script>';
	}
	
	static function scriptTag($url) {
		return '<script type="text/javascript" language="javascript" src="' .
			$url . '"></script>';	
	}
	
	static function injectDraftContent($tpl, $content) {
		$containers = renderer::containers($tpl);
		foreach ($containers as $container => $repeater) {
			if (array_key_exists($container, $content)) {
				renderer::setContent($tpl, $container, $content[$container]);
			}
		}
		return $tpl;
	}
	
	static function pageId($tpl, $pageFile) {
		if (preg_match('/\\s+scpageid="([^"]+)"/', 
				(string)(phpQuery::pq('head', $tpl)->html()), $matches)) {
			return $matches[1];
		} else {
			$id = util::id();
			$origTpl = phpQuery::newDocument(renderer::loadPageFile($pageFile));
			phpQuery::pq('head', $origTpl)->append(
				renderer::wrapToScriptTag('var scpageid="' . $id . '";'));
			phpQuery::pq('head', $tpl)->append(
				renderer::wrapToScriptTag('var scpageid="' . $id . '";'));
			renderer::savePageFile($pageFile, (string)$origTpl);
			return $id;
		}
	}
	
	static function setContent($tpl, $container, $content) {
		phpQuery::pq('.' . $container, $tpl)->html($content);
	}
	
	static function purge() {
		$used = renderer::used_references();
		foreach (meta::ids() as $id) {
			if (!in_array($id, $used) && !meta::find('oid', $id)) {
				renderer::purge_res($id);
			}
		}
	}
	
	static function purge_res($id) {
		$meta = meta::get($id);
		meta::remove($id);
		$path = util::apath($meta['path']);
		if (io::file_exists($path)) io::unlink($path);
		$fpath = PUBLIC_FILES_DIR . '/' . $meta['name'];
		if (io::file_exists($fpath)) io::unlink($fpath);
		$ipath = PUBLIC_IMAGES_DIR . '/' . $meta['name'];
		if (io::file_exists($ipath)) io::unlink($ipath);		
	}
	
	static function used_references() {
		$refs = array();
		foreach (renderer::pageFiles() as $path) {
			$refs = array_merge($refs, 
				renderer::extract_refs(io::file_get_contents($path)));
		}
		
		foreach (draft::getAll(true) as $drf) {
			$refs = array_merge($refs, renderer::extract_refs($drf));
		}
		return $refs;
	}
	
	static function extract_refs($text) {
		preg_match_all('/\/([0-9abcdef]{40})\./', $text, $matches);
		return $matches[1];
	}

}