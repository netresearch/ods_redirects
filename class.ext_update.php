<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Robert Heel (typo3@bobosch.de)
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

class ext_update {
	protected $messageArray = array();

	public function access() {
		$realurl_version = TYPO3\CMS\Core\Utility\VersionNumberUtility::convertVersionNumberToInteger(TYPO3\CMS\Core\Utility\ExtensionManagementUtility::getExtensionVersion('realurl'));
		if($realurl_version > 0 && $realurl_version < 2000000) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Main update function called by the extension manager.
	 *
	 * @return string
	 */
	public function main() {
		$this->processUpdates();
		return $this->generateOutput();
	}
	
	/**
	 * Generates output by using flash messages
	 *
	 * @return string
	 */
	protected function generateOutput() {
		$output = '';
		foreach ($this->messageArray as $messageItem) {
			$flashMessage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
				'TYPO3\CMS\Core\Messaging\FlashMessage',
				$messageItem[2],
				$messageItem[1],
				$messageItem[0]);
			$output .= $flashMessage->render();
		}

		return $output;
	}

	/**
	 * The actual update function. Add your update task in here.
	 *
	 * @return void
	 */
	protected function processUpdates() {
		if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('realurl')) {
			$this->importRealurlRedirects();
		}
	}
	
	protected function importRealurlRedirects() {
		$title = 'Import redirects from RealURL';
		$message = 'Nothing to import.';
		$status = \TYPO3\CMS\Core\Messaging\FlashMessage::OK;

		$GLOBALS['TYPO3_DB']->sql_query('REPLACE INTO tx_odsredirects_redirects(mode,url,destination,last_referer,counter,tstamp,has_moved) SELECT 1,url,destination,last_referer,counter,tstamp,has_moved FROM tx_realurl_redirects');
		$count=$GLOBALS['TYPO3_DB']->sql_affected_rows();
		
		if($count) {
			$message='Imported ' . $count . ' redirects.';
		}

		$this->messageArray[] = array($status, $title, $message);
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ods_redirects/class.ext_update.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ods_redirects/class.ext_update.php']);
}
?>
