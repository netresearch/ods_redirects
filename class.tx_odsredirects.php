<?php
class tx_odsredirects {
	protected $conf;

	function checkRedirect(&$params){
		$this->conf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ods_redirects']);
		$pObj=$params['pObj'];
		// URL parts
		$url=$pObj->siteScript;
		$path=strtok($pObj->siteScript,'?');

		// Create WHERE
		$quotedUrl = $GLOBALS['TYPO3_DB']->fullQuoteStr($url,'tx_odsredirects_redirects');
		$quotedPath = $GLOBALS['TYPO3_DB']->fullQuoteStr($path,'tx_odsredirects_redirects');
		$where=array(
			'(mode=0 AND url=LEFT(' . $quotedUrl . ',LENGTH(url)))', // Begins with URL
			'(mode=1 AND url=' . $quotedPath . ')', // Path match
			'(mode=2 AND url=' . $quotedUrl . ')', // Path and query string match
			'(mode=4 AND ' . $quotedUrl . ' REGEXP url)', // Path and query string regex match
		);
		if(substr($path,-1)!='/') $where[]='(mode=1 AND url='.$GLOBALS['TYPO3_DB']->fullQuoteStr($path.'/','tx_odsredirects_redirects').')'; // Path match if entered without trailing '/'
		$where = '(' . implode(' OR ', $where) . ') AND hidden=0';

		$redirect = $this->conf['sorting_is_priority']
			? $this->getRedirectBySorting($where)
			: $this->getRedirectByModePriority($where);

		// Do redirect
		if($redirect){
			$this->doRedirect($redirect, $url);
		}
	}

	/**
	 * Get the first matching redirect in order of the sorting
	 *
	 * @param string $where
	 * @return array array|null
	 */
	protected function getRedirectBySorting($where) {
		$domainIds = array(0);
		$domainId = $this->getDomainId();
		if ($domainId) {
			$domainIds[] = $domainId;
		}

		return $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow(
			'*',
			'tx_odsredirects_redirects',
			$where . ' AND domain_id IN (' . implode(',', $domainIds) . ')',
			'',
			'domain_id DESC, sorting ASC'
		);
	}

	/**
	 * Get first matching redirect in order of the priority of the modes.
	 *
	 * @todo Could be reduced to a single query with ORDER BY domain_id DESC, FIELD(mode, 2, 3, 1, 0, 4)
	 *
	 * Priorities (highest on top):
	 *   mode 2: Path and query string match
	 *   mode 3: Path and only given query parts match
	 *   mode 1: Path match
	 *   mode 0: Begins with path
	 *   mode 4: Path and query string regex match
	 *
	 *
	 * @param string $where
	 * @return array array|null
	 */
	protected function getRedirectByModePriority($where) {
		$prio = array('0' => 50, '1' => 100, '2' => 200, '3' => 150, '4' => 10);

		// Fetch redirects
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
			'*',
			'tx_odsredirects_redirects',
			$where,
			'',
			'domain_id DESC'
		);
		$redirect = null;
		$domain_id = null;
		$specific = false;
		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			// Check furhter requirements
			if ($row['domain_id']) {
				// Entries with a domain_id have priority
				if ($row['domain_id'] == $this->getDomainId()) {
					$specific = true;
					if (!$redirect || $prio[$row['mode']] > $prio[$redirect['mode']]) {
						$redirect = $row;
					}
				}
			} elseif (!$redirect || !$specific && $prio[$row['mode']] > $prio[$redirect['mode']]) {
				// All Domains
				$redirect = $row;
			}
		}
		return $redirect;
	}

	protected function getDomainId() {
		static $domainId = null;
		if ($domainId === null) {
			// Get domain record
			$res2 = $GLOBALS['TYPO3_DB']->exec_SELECTquery('uid', 'sys_domain', 'domainName=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($_SERVER['HTTP_HOST'], 'sys_domain') . ' AND hidden=0');
			$row2 = $GLOBALS['TYPO3_DB']->sql_fetch_row($res2);
			$domainId = $row2 ? $row2[0] : false;
		}
		return $domainId;
	}

	/**
	 * @param $redirect
	 * @param $currentUrl
	 */
	protected function doRedirect($redirect, $currentUrl) {
		// Update statistics
		$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
			'tx_odsredirects_redirects',
			'uid=' . $redirect['uid'],
			array(
				'counter' => 'counter+1',
				'tstamp' => time(),
				'last_referer' => \TYPO3\CMS\Core\Utility\GeneralUtility::getIndpEnv('HTTP_REFERER')
			),
			array('counter')
		);

		// Build destination URL
		if ($redirect['mode'] == 4) {
			$delimiter = $this->conf['regex_delimiter'] ?: '/';
			$redirect['destination'] = preg_replace(
				$delimiter . $redirect['url'] . $delimiter,
				$redirect['destination'],
				$currentUrl
			);
        }
		$destination = $this->getLink($redirect['destination'], $_SERVER['HTTP_HOST'] . '/' . $currentUrl);

		// Append trailing url
		if ($redirect['append']) {
			$append = substr($currentUrl, strlen($redirect['url']));
			// Replace ? by & if query parts appended to non speaking url
			if (strpos($destination, '?') && substr($append, 0, 1) == '?') $append = '&' . substr($append, 1);
			$destination .= $append;
		}

		// Redirect
		if ($redirect['has_moved']) {
			header('HTTP/1.1 301 Moved Permanently');
		}
		header('Location: ' . \TYPO3\CMS\Core\Utility\GeneralUtility::locationHeaderUrl($destination));
		header('X-Note: Redirect by ods_redirects [' . $redirect['uid'] . ']');
		header('Connection: close');
		exit();
	}

	function getLink($destination,$source=''){
		$L=$this->languageDetection($source);
		$url=$this->buildURL($destination,$L);
		return $url;
	}

	function languageDetection($source){
		// Language from L
		$L=is_numeric(\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('L')) ? intval(\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('L')) : false;

		// Language from speaking URL
		if(!$L && $this->conf['lang_detect'] && $source){
//			parse_str($conf['lang_detect'],$lang_detect); // not work with "."
			$lang_detect=array();
			foreach(explode(';',$this->conf['lang_detect']) as $pair){
				$parts=explode('=',$pair);
				$lang_detect[$parts[0]]=$parts[1];
			}
			foreach($lang_detect as $str=>$id){
				if(strpos($source,strval($str))!==false) $L=intval($id);
			}
		}
		return $L;
	}

	function buildURL($id,$L=false){
		if($id){
			// http://wissen.netzhaut.de/typo3/extensionentwicklung/typolink-realurl-in-scheduler-tasks/
			\TYPO3\CMS\Frontend\Utility\EidUtility::initTCA();
			$GLOBALS['TSFE'] = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Controller\\TypoScriptFrontendController',  $GLOBALS['TYPO3_CONF_VARS'], 0, 0);
			$GLOBALS['TSFE']->connectToDB();
			$GLOBALS['TSFE']->initFEuser();
			$GLOBALS['TSFE']->determineId();
			$GLOBALS['TSFE']->initTemplate();
			$GLOBALS['TSFE']->getConfigArray();
			$GLOBALS['TSFE']->calculateLinkVars();

			$cObj = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\ContentObject\\ContentObjectRenderer');
			$url = $cObj->getTypoLink_URL($id, $L!==false ? array('L'=>$L) : array());
			return $url;
		}
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ods_redirects/class.tx_odsredirects.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ods_redirects/class.tx_odsredirects.php']);
}
?>
