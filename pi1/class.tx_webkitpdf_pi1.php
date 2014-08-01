<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2009 Dev-Team Typoheads <dev@typoheads.at>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
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
/**
 * Plugin 'WebKit PDFs' for the 'webkitpdf' extension.
 *
 * @author Reinhard Führicht <rf@typoheads.at>
 */

require_once(t3lib_extMgm::extPath('webkitpdf') . 'res/class.tx_webkitpdf_cache.php');
require_once(t3lib_extMgm::extPath('webkitpdf') . 'res/class.tx_webkitpdf_utils.php');

class tx_webkitpdf_pi1 extends tslib_pibase {
	var $prefixId = 'tx_webkitpdf_pi1';
	var $scriptRelPath = 'pi1/class.tx_webkitpdf_pi1.php';	
	var $extKey = 'webkitpdf';	

	// Disable caching: Don't check cHash, because the plugin is a USER_INT object
	public $pi_checkCHash = FALSE;
	public $pi_USER_INT_obj = 1;

	/**
	 * @var tx_webkitpdf_cache
	 */
	protected $cacheManager;
	protected $scriptPath;
	protected $outputPath;
	protected $paramName;
	protected $filename;
	protected $filenameOnly;
	protected $contentDisposition;

	/**
	 * Init parameters. Reads TypoScript settings.
	 *
	 * @param	array		$conf: The PlugIn configuration
	 * @return	void
	 */
	protected function init($conf) {
		
		// Process stdWrap properties
		$temp = $conf['scriptParams.'];
		unset($conf['scriptParams.']);
		$this->conf = $this->processStdWraps($conf);
		if(is_array($temp)) {
			$this->conf['scriptParams'] = $this->processStdWraps($temp);
		}

		$this->pi_setPiVarDefaults();

		$this->scriptPath = t3lib_extMgm::extPath('webkitpdf') . 'res/';
		if($this->conf['customScriptPath']) {
			$this->scriptPath = $this->conf['customScriptPath'];
		}

		if ($this->conf['customTempOutputPath']) {
			$this->outputPath = tx_webkitpdf_utils::sanitizePath($this->conf['customTempOutputPath']);
		} else {
			$this->outputPath = '/typo3temp/tx_webkitpdf/';
		}

		$documentRoot = t3lib_div::getIndpEnv('TYPO3_DOCUMENT_ROOT');
		$absoluteOutputPath = $documentRoot . $this->outputPath;
		if (!@is_dir($absoluteOutputPath)) {
			\TYPO3\CMS\Core\Utility\GeneralUtility::mkdir_deep($documentRoot, $this->outputPath);
		}
		$this->outputPath = $absoluteOutputPath;

		$this->paramName = 'urls';
		if($this->conf['customParameterName']) {
			$this->paramName = $this->conf['customParameterName'];
		}

		$this->filename = $this->outputPath . $this->conf['filePrefix'] . tx_webkitpdf_utils::generateHash() . '.pdf';		
		$this->filenameOnly = basename($this->filename);
		if($this->conf['staticFileName']) {
			$this->filenameOnly = $this->conf['staticFileName'];
		}

		if(substr($this->filenameOnly, strlen($this->filenameOnly) - 4) !== '.pdf') {
			$this->filenameOnly .= '.pdf';
		}

		$this->readScriptSettings();
		$this->cacheManager = t3lib_div::makeInstance('tx_webkitpdf_cache', $this->conf);

		$this->contentDisposition = 'attachment';
		if(intval($this->conf['openFilesInline']) === 1) {
			$this->contentDisposition = 'inline';
		}
	}

	/**
	 * The main method of the PlugIn
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	The content that is displayed on the website
	 */
	public function main($content,$conf)	{
		$this->init($conf);

		$urls = $this->piVars[$this->paramName];
		if(!$urls) {
			if (isset($this->conf['urls.'])) {
				$urls = $this->conf['urls.'];
			} else {
				$urls = array($this->conf['urls']);
			}
		}

		$content = '';
		if(!empty($urls)) {
			if(count($urls) > 0) {
				
				$origUrls = implode(' ', $urls);
				$loadFromCache = TRUE;
				
				$allowedHosts = FALSE;
				if($this->conf['allowedHosts']) {
					$allowedHosts = t3lib_div::trimExplode(',', $this->conf['allowedHosts']);
				}
				
				foreach($urls as &$url) {
					if($GLOBALS['TSFE']->loginUser) {

						// Do not cache access restricted pages
						$loadFromCache = FALSE;
						$url = tx_webkitpdf_utils::appendFESessionInfoToURL($url);
					}
					$url = tx_webkitpdf_utils::sanitizeURL($url, $allowedHosts);
				}
				
				// not in cache. generate PDF file
				if(!$this->cacheManager->isInCache($origUrls) || $this->conf['debugScriptCall'] === '1' || !$loadFromCache) {
					
					$scriptCall = 	$this->scriptPath. 'wkhtmltopdf ' .
									$this->buildScriptOptions() . ' ' .
									implode(' ', $urls) . ' ' .
									$this->filename;

					if (isset($this->conf['runInBackground']) && $this->conf['runInBackground']) {
						$this->createPdfInBackground($scriptCall);
					} else {
						$this->createPdfInForeground($scriptCall);
					}

					if ($loadFromCache) {
						$this->cacheManager->store($origUrls, $this->filename);
					}
					
				} else {
					
					//read filepath from cache
					$this->filename = $this->cacheManager->get($origUrls);
				}
				
				if($this->conf['fileOnly'] == 1) {
					return $this->filename;
				}
				
				$filesize = filesize($this->filename);
				
				header('Content-type: application/pdf');
				header('Content-Transfer-Encoding: Binary');
				header('Content-Length: ' . $filesize);
				header('Content-Disposition: ' . $this->contentDisposition . '; filename="' . $this->filenameOnly . '"');
				header('X-Robots-Tag: noindex');
				readfile($this->filename);

				if(!$this->cacheManager->isCachingEnabled()) {
					unlink($this->filename);
				}
				exit(0);
			}
		}
		
		return $this->pi_wrapInBaseClass($content);
	}

	/**
	 * Runs the given PDF generation command in the background and writes the ouput to a log file.
	 * If the process does not stop after a configurable wait time the process is killed and an Exeption is thrown.
	 *
	 * @param string $scriptCall
	 * @return void
	 */
	protected function createPdfInBackground($scriptCall) {
		$logFile = isset($this->conf['logFile']) ? t3lib_div::getFileAbsFileName($this->conf['logFile'], TRUE) : '';
		$logFile = substr($logFile, strlen(PATH_site));
		$logDir = dirname($logFile);
		if ($logFile === '') {
			$logFile = '/dev/null';
		} else if (!@is_dir(PATH_site . $logDir)) {
			t3lib_div::mkdir_deep(PATH_site, $logDir);
		}

		$scriptCall .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
		$output = array();
		exec($scriptCall, $output);
		$processId = isset($output[0]) ? (int)$output[0] : 0;

		if ($processId === 0) {
			$this->throwException(new \RuntimeException('Process ID of PDF generator could not be determined.'));
		}

		tx_webkitpdf_utils::debugLogging('Executed shell command in background with process ID ' . $processId, -1, array($scriptCall));

		$waitTimeInSeconds = isset($this->conf['waitTimeInSeconds']) ? (int)$this->conf['waitTimeInSeconds'] : 0;
		if ($waitTimeInSeconds === 0) {
			$waitTimeInSeconds = 10;
		}
		$secondsWaited = 0;
		while ($this->processIsRunning($processId)) {
			sleep(1);
			$secondsWaited++;
			if ($secondsWaited > $waitTimeInSeconds) {
				exec('kill ' . $processId);
				$this->throwException(new \RuntimeException('PDF generation did not finish in a reasonable amount of time: ' . $scriptCall));
			}
		}

		if (!file_exists($this->filename)) {
			$this->throwException(new \RuntimeException('PDF generator did not create a PDF file'));
		}
	}

	/**
	 * Executes the given Script call in the foreground and writes the output to the log.
	 *
	 * @param string $scriptCall
	 * @return void
	 */
	protected function createPdfInForeground($scriptCall) {
		$output = array();
		$scriptCall .= ' 2>&1';
		exec($scriptCall, $output);
		tx_webkitpdf_utils::debugLogging('Executed shell command in foreground', -1, array($scriptCall));
		tx_webkitpdf_utils::debugLogging('Output of shell command', -1, $output);
	}
	
	protected function readScriptSettings() {
		$defaultSettings = array(
			'footer-right' => '[page]/[toPage]',
			'footer-font-size' => '6',
			'header-font-size' => '6',
			'margin-left' => '15mm',
			'margin-right' => '15mm',
			'margin-top' => '15mm',
			'margin-bottom' => '15mm',
		);
		
		$tsSettings = $this->conf['scriptParams'];
		foreach($defaultSettings as $param => $value) {
			if(!isset($tsSettings[$param])) {
				$tsSettings[$param] = $value;
			}
		}
		
		$finalSettings = array();
		foreach($tsSettings as $param => $value) {
			$value = trim($value);
			if(substr($param, 0, 2) !== '--') {
				$param = '--' . $param;
			}
			$finalSettings[$param] = $value;
		}
		return $finalSettings;
	}

	/**
	 * Creates the parameters for the wkhtmltopdf call.
	 *
	 * @return string The parameter string
	 */
	protected function buildScriptOptions() {
		$options = array();
		if($this->conf['pageURLInHeader']) {
			$options['--header-center'] = '[webpage]';
		}
		
		if($this->conf['copyrightNotice']) {
			$options['--footer-left'] = '© ' . date('Y', time()) . $this->conf['copyrightNotice'] . '';
		}
		
		if($this->conf['additionalStylesheet']) {
			$this->conf['additionalStylesheet'] = $this->sanitizePath($this->conf['additionalStylesheet'], FALSE);
			$options['--user-style-sheet'] = t3lib_div::getIndpEnv('TYPO3_REQUEST_HOST') . $this->conf['additionalStylesheet'];
				
		}

		$userSettings = $this->readScriptSettings();
		$options = array_merge($options, $userSettings);

		$paramsString = '';
		foreach($options as $param => $value) {
			if(strlen($value) > 0) {
				$value = '"' . $value . '"';
			}
			$paramsString .= ' ' . $param . ' ' . $value; 
		}

		if (!empty($this->conf['overrideUserAgent'])) {
			$paramsString = ' --custom-header-propagation --custom-header \'User-Agent\' \'' . $this->conf['overrideUserAgent'] . '\' ';
		}

		return $paramsString;
	}

	/**
	 * Makes sure that given path has a slash as first and last character
	 *
	 * @param	string		$path: The path to be sanitized
	 * @return	Sanitized path
	 */
	protected function sanitizePath($path, $trailingSlash = TRUE) {
		
		// slash as last character
		if($trailingSlash && substr($path, (strlen($path) - 1)) !== '/') {
			$path .= '/';
		}
		
		//slash as first character
		if(substr($path, 0, 1) !== '/') {
			$path = '/' . $path;
		}
		
		return $path;
	}

	/**
	 * Checks if the process with the given ID is still running.
	 *
	 * @param int $processId
	 * @return bool
	 */
	protected function processIsRunning($processId) {
		try{
			$result = shell_exec(sprintf("ps %d", $processId));
			if(count(preg_split("/\n/", $result)) > 2){
				return true;
			}
		} catch(Exception $e){
			$this->throwException($e);
		}

		return false;
	}
	
	/**
	 * Processes the stdWrap properties of the input array
	 *
	 * @param	array	The TypoScript array
	 * @return	array	The processed values
	 */
	protected function processStdWraps($tsSettings) {
		
		// Get TS values and process stdWrap properties
		if(is_array($tsSettings)) {
			foreach ($tsSettings as $key => $value) {
				$process = TRUE;			
				if (substr($key, -1) === '.') {
					$key = substr($key, 0, -1);
					if (array_key_exists($key, $tsSettings)) {
						$process = FALSE;
					}
				}
				
				if ((substr($key, -1) === '.' && !array_key_exists(substr($key, 0, -1), $tsSettings)) ||
					(substr($key, -1) !== '.' && array_key_exists($key . '.', $tsSettings)) && !strstr($key, 'scriptParams')) {
					
					$tsSettings[$key] = $this->cObj->stdWrap($value, $tsSettings[$key . '.']);
	
					// Remove the additional TS properties after processing, otherwise they'll be translated to pdf properties
					if (isset($tsSettings[$key . '.'])) {
						unset($tsSettings[$key . '.']);
					}
				}
			}
		}
		return $tsSettings;
	}

	/**
	 * Resets the Content-Type header and throws the given Exception.
	 *
	 * @param Exception $exception
	 * @throws Exception
	 */
	protected function throwException($exception) {
		header('Content-Type: text/html');
		throw $exception;
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/webkitpdf/pi1/class.tx_webkitpdf_pi1.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/webkitpdf/pi1/class.tx_webkitpdf_pi1.php']);
}

?>