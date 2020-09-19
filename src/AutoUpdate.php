<?php namespace VisualAppeal;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;

use Desarrolla2\Cache\Cache;
use Desarrolla2\Cache\Adapter\NotCache;

use Monolog\Logger;
use Monolog\Handler\NullHandler;

use VisualAppeal\Exceptions\DownloadException;
use VisualAppeal\Exceptions\ParserException;

/**
 * Auto update class.
 */
class AutoUpdate {
    /**
     * No update available.
     */
    const NO_UPDATE_AVAILABLE = 0;
    /**
     * Zip file could not be opened.
     */
    const ERROR_INVALID_ZIP = 10;
    /**
     * Could not check for last version.
     */
    const ERROR_VERSION_CHECK = 20;
    /**
     * Temp directory does not exist or is not writable.
     */
    const ERROR_TEMP_DIR = 30;
    /**
     * Install directory does not exist or is not writable.
     */
    const ERROR_INSTALL_DIR = 35;
    /**
     * Could not download update.
     */
    const ERROR_DOWNLOAD_UPDATE = 40;
    /**
     * Could not delete zip update file.
     */
    const ERROR_DELETE_TEMP_UPDATE = 50;
    /**
     * Error while installing the update.
     */
    const ERROR_INSTALL = 60;
    /**
     * Error in simulated install.
     */
    const ERROR_SIMULATE = 70;
    /**
     * Create new folders with this privileges.
     *
     * @var int
     */
    public $dirPermissions = 0755;
    /**
     * Update script filename.
     *
     * @var string
     */
    public $updateScriptName = '_upgrade.php';
    /**
     * Url to the update folder on the server.
     *
     * @var string
     */
    protected $_updateUrl = 'https://example.com/updates/';
    /**
     * Version filename on the server.
     *
     * @var string
     */
    protected $_updateFile = '';
    /**
     * Current version.
     *
     * @var string
     */
    protected $_currentVersion = null;

    /*
     * Callbacks to be called when each update is finished
     */
    /**
     * The latest version.
     *
     * @var string
     */
    private $_latestVersion = null;

    /*
     * Callbacks to be called when all updates are finished
     */
    /**
     * Updates not yet installed.
     *
     * @var array
     */
    private $_updates = null;

    /**
     * Result of simulated install.
     *
     * @var array
     */
    private $_simulationResults = array();
    /**
     * Temporary download directory.
     *
     * @var string
     */
    private $_tempDir = '';
    /**
     * Install directory.
     *
     * @var string
     */
    private $_installDir = '';
    /**
     * Update branch.
     *
     * @var string
     */
    private $_branch = '';
    private $_log;
    private $_cache;


    private $onEachUpdateFinishCallbacks = [];
    private $onAllUpdateFinishCallbacks = [];

    /**
     * Create new instance
     *
     * @param string $tempDir
     * @param string $installDir
     * @param int $maxExecutionTime
     */
    public function __construct($tempDir = null, $installDir = null, $maxExecutionTime = 60) {
        // Init logger
        $this->_log = new Logger('auto-update');
        $this->_log->pushHandler(new NullHandler());

        $this->setTempDir(($tempDir !== null) ? $tempDir : __DIR__ . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR);
        $this->setInstallDir(($installDir !== null) ? $installDir : __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR);

        $this->_latestVersion = '0.0.0';
        $this->_currentVersion = '0.0.0';

        // Init cache
        $this->_cache = new Cache(new NotCache());

        ini_set('max_execution_time', $maxExecutionTime);
    }

    /**
     * Set the temporary download directory.
     *
     * @param string $dir
     * @return bool
     */
    public function setTempDir($dir) {
        $dir = $this->addTrailingSlash($dir);

        if (!is_dir($dir)) {
            $this->_log->addDebug(sprintf('Creating new temporary directory "%s"', $dir));

            if (!mkdir($dir, 0755, true)) {
                $this->_log->addCritical(sprintf('Could not create temporary directory "%s"', $dir));

                return false;
            }
        }

        $this->_tempDir = $dir;

        return true;
    }

    /**
     * Add slash at the end of the path.
     *
     * @param string $dir
     * @return string
     */
    public function addTrailingSlash($dir) {
        if (substr($dir, -1) != DIRECTORY_SEPARATOR) {
            $dir = $dir . DIRECTORY_SEPARATOR;
        }

        return $dir;
    }

    /**
     * Set the install directory.
     *
     * @param string $dir
     * @return bool
     */
    public function setInstallDir($dir) {
        $dir = $this->addTrailingSlash($dir);

        if (!is_dir($dir)) {
            $this->_log->addDebug(sprintf('Creating new install directory "%s"', $dir));

            if (!mkdir($dir, 0755, true)) {
                $this->_log->addCritical(sprintf('Could not create install directory "%s"', $dir));

                return false;
            }
        }

        $this->_installDir = $dir;

        return true;
    }

    /**
     * Set the update filename.
     *
     * @param string $updateFile
     * @return $this
     */
    public function setUpdateFile($updateFile) {
        $this->_updateFile = $updateFile;

        return $this;
    }

    /**
     * Set the update filename.
     *
     * @param string $updateUrl
     * @return $this
     */
    public function setUpdateUrl($updateUrl) {
        $this->_updateUrl = $updateUrl;

        return $this;
    }

    /**
     * Set the update branch.
     *
     * @param string branch
     * @return $this
     */
    public function setBranch($branch) {
        $this->_branch = $branch;

        return $this;
    }

    /**
     * Set the version of the current installed software.
     *
     * @param string $currentVersion
     * @return $this
     */
    public function setCurrentVersion($currentVersion) {
        $this->_currentVersion = $currentVersion;

        return $this;
    }

    /**
     * Add a new logging handler.
     *
     * @param \Monolog\Handler\HandlerInterface|Monolog\Handler\HandlerInterface $handler See https://github.com/Seldaek/monolog
     * @return $this
     */
    public function addLogHandler(\Monolog\Handler\HandlerInterface $handler) {
        $this->_log->pushHandler($handler);

        return $this;
    }

    /**
     * Get the name of the latest version.
     *
     * @return string
     */
    public function getLatestVersion() {
        return $this->_latestVersion;
    }

    /**
     * Get the results of the last simulation.
     *
     * @return array
     */
    public function getSimulationResults() {
        return $this->_simulationResults;
    }

    /**
     * Update to the latest version
     *
     * @param bool $simulateInstall Check for directory and file permissions before copying files (Default: true)
     * @param bool $deleteDownload Delete download after update (Default: true)
     * @return integer|bool
     * @throws DownloadException
     * @throws ParserException
     */
    public function update($simulateInstall = true, $deleteDownload = true) {
        $this->_log->addInfo('Trying to perform update');

        // Check for latest version
        if ($this->_latestVersion === null || count($this->_updates) === 0) {
            $this->checkUpdate();
        }

        if ($this->_latestVersion === null || count($this->_updates) === 0) {
            $this->_log->addError('Could not get latest version from server!');

            return self::ERROR_VERSION_CHECK;
        }

        // Check if current version is up to date
        if (!$this->newVersionAvailable()) {
            $this->_log->addWarning('No update available!');

            return self::NO_UPDATE_AVAILABLE;
        }

        foreach ($this->_updates as $update) {
            $this->_log->addDebug(sprintf('Update to version "%s"', $update['version']));

            // Check for temp directory
            if (empty($this->_tempDir) || !is_dir($this->_tempDir) || !is_writable($this->_tempDir)) {
                $this->_log->addCritical(sprintf('Temporary directory "%s" does not exist or is not writeable!', $this->_tempDir));

                return self::ERROR_TEMP_DIR;
            }

            // Check for install directory
            if (empty($this->_installDir) || !is_dir($this->_installDir) || !is_writable($this->_installDir)) {
                $this->_log->addCritical(sprintf('Install directory "%s" does not exist or is not writeable!', $this->_installDir));

                return self::ERROR_INSTALL_DIR;
            }

            $updateFile = $this->_tempDir . $update['version'] . '.zip';

            // Download update
            if (!is_file($updateFile)) {
                if (!$this->_downloadUpdate($update['url'], $updateFile)) {
                    $this->_log->addCritical(sprintf('Failed to download update from "%s" to "%s"!', $update['url'], $updateFile));

                    return self::ERROR_DOWNLOAD_UPDATE;
                }

                $this->_log->addDebug(sprintf('Latest update downloaded to "%s"', $updateFile));
            } else {
                $this->_log->addInfo(sprintf('Latest update already downloaded to "%s"', $updateFile));
            }

            // Install update
            $result = $this->_install($updateFile, $simulateInstall, $update['version']);
            if ($result === true) {
                $this->runOnEachUpdateFinishCallbacks($update['version']);
                if ($deleteDownload) {
                    $this->_log->addDebug(sprintf('Trying to delete update file "%s" after successfull update', $updateFile));
                    if (unlink($updateFile)) {
                        $this->_log->addInfo(sprintf('Update file "%s" deleted after successfull update', $updateFile));
                    } else {
                        $this->_log->addError(sprintf('Could not delete update file "%s" after successfull update!', $updateFile));

                        return self::ERROR_DELETE_TEMP_UPDATE;
                    }
                }
            } else {
                if ($deleteDownload) {
                    $this->_log->addDebug(sprintf('Trying to delete update file "%s" after failed update', $updateFile));
                    if (unlink($updateFile)) {
                        $this->_log->addInfo(sprintf('Update file "%s" deleted after failed update', $updateFile));
                    } else {
                        $this->_log->addError(sprintf('Could not delete update file "%s" after failed update!', $updateFile));
                    }
                }

                return $result;
            }
        }

        $this->runOnAllUpdateFinishCallbacks($this->getVersionsToUpdate());

        return true;
    }

    /**
     * Check for a new version
     *
     * @return int|bool
     *         true: New version is available
     *         false: Error while checking for update
     *         int: Status code (i.e. AutoUpdate::NO_UPDATE_AVAILABLE)
     * @throws DownloadException
     * @throws ParserException
     */
    public function checkUpdate() {
        $this->_log->addNotice('Checking for a new update...');

        // Reset previous updates
        $this->_latestVersion = '0.0.0';
        $this->_updates = [];

        $versions = $this->_cache->get('update-versions');

        // Create absolute url to update file
        $updateFile = $this->_updateUrl;
        $this->_log->info("Download URL: " . $updateFile);
        // Check if cache is empty
        if ($versions === null || $versions === false) {
            $this->_log->addDebug(sprintf('Get new updates from %s', $updateFile));

            // Read update file from update server
            if (function_exists('curl_version') && $this->_isValidUrl($updateFile)) {
                $update = $this->_downloadCurl($updateFile);
                $this->_log->addInfo("Downloading via Curl");
                if ($update === false) {
                    $this->_log->addError(sprintf('Could not download update file "%s" via curl!', $updateFile));

                    throw new DownloadException($updateFile);
                }
            } else {
                $update = @file_get_contents($updateFile, false);

                if ($update === false) {
                    $this->_log->addError(sprintf('Could not download update file "%s" via file_get_contents!', $updateFile));

                    throw new DownloadException($updateFile);
                }
            }


            if (!json_decode($update)) {
                $this->_log->addError('Unable to parse json update file!');
                throw new ParserException;
            }
            $versions = json_decode($update);
            $this->_log->addInfo("Downloaded & parsed update File!");

            $this->_cache->set('update-versions', $versions);
        } else {
            $this->_log->addDebug('Got updates from cache');
        }

        // Check for latest version
        foreach ($versions as $version => $updateUrl) {

            if (Comparator::greaterThan($version, $this->_currentVersion)) {
                if (Comparator::greaterThan($version, $this->_latestVersion)) {
                    $this->_latestVersion = $version;
                }

                $this->_updates[] = [
                    'version' => $version,
                    'url' => $updateUrl,
                ];
            }
        }

        // Sort versions to install
        usort($this->_updates, function ($a, $b) {
            if (Comparator::equalTo($a['version'], $b['version'])) {
                return 0;
            }

            return Comparator::lessThan($a['version'], $b['version']) ? -1 : 1;
        });
        if ($this->newVersionAvailable()) {
            $this->_log->addDebug(sprintf('New version "%s" available', $this->_latestVersion));

            return true;
        } else {
            $this->_log->addDebug('No new version available');

            return self::NO_UPDATE_AVAILABLE;
        }
    }

    /**
     * Check if url is valid.
     *
     * @param string $url
     * @return boolean
     */
    protected function _isValidUrl($url) {
        return (filter_var($url, FILTER_VALIDATE_URL) !== false);
    }

    /**
     * Download file via curl.
     *
     * @param string $url URL to file
     * @return string|false
     */
    protected function _downloadCurl($url) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $update = curl_exec($curl);

        $error = false;
        if (curl_error($curl)) {
            $error = true;
            $this->_log->addError(sprintf(
                'Could not download update "%s" via curl: %s!',
                $url,
                curl_error($curl)
            ));
        }
        curl_close($curl);

        if ($error === true) {
            return false;
        }

        return $update;
    }

    /**
     * Check if a new version is available.
     *
     * @return bool
     */
    public function newVersionAvailable() {
        return Comparator::greaterThan($this->_latestVersion, $this->_currentVersion);
    }

    /**
     * Download the update
     *
     * @param string $updateUrl Url where to download from
     * @param string $updateFile Path where to save the download
     * @return bool
     * @throws DownloadException
     */
    protected function _downloadUpdate($updateUrl, $updateFile) {
        $this->_log->addInfo(sprintf('Downloading update "%s" to "%s"', $updateUrl, $updateFile));
        if (function_exists('curl_version') && $this->_isValidUrl($updateUrl)) {
            $update = $this->_downloadCurl($updateUrl);
            if ($update === false) {
                return false;
            }
        } elseif (ini_get('allow_url_fopen')) {
            $update = @file_get_contents($updateUrl, false);

            if ($update === false) {
                $this->_log->addError(sprintf('Could not download update "%s"!', $updateUrl));

                throw new DownloadException($updateUrl);
            }
        }

        $handle = fopen($updateFile, 'w');
        if (!$handle) {
            $this->_log->addError(sprintf('Could not open file handle to save update to "%s"!', $updateFile));

            return false;
        }

        if (!fwrite($handle, $update)) {
            $this->_log->addError(sprintf('Could not write update to file "%s"!', $updateFile));
            fclose($handle);

            return false;
        }

        fclose($handle);

        return true;
    }

    /**
     * Install update.
     *
     * @param string $updateFile Path to the update file
     * @param bool $simulateInstall Check for directory and file permissions instead of installing the update
     * @param $version
     * @return bool
     */
    protected function _install($updateFile, $simulateInstall, $version) {
        $this->_log->addNotice(sprintf('Trying to install update "%s"', $updateFile));

        // Check if install should be simulated
        if ($simulateInstall) {
            if ($this->_simulateInstall($updateFile)) {
                $this->_log->addNotice(sprintf('Simulation of update "%s" process succeeded', $version));

                return true;
            }

            $this->_log->addCritical(sprintf('Simulation of update  "%s" process failed!', $version));

            return self::ERROR_SIMULATE;
        }

        clearstatcache();

        // Install only if simulateInstall === false

        // Check if zip file could be opened
        $zip = zip_open($updateFile);
        if (!is_resource($zip)) {
            $this->_log->addError(sprintf('Could not open zip file "%s", error: %d', $updateFile, $zip));

            return false;
        }

        // Read every file from archive
        while ($file = zip_read($zip)) {
            $filename = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, zip_entry_name($file));
            $foldername = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $this->_installDir . dirname($filename));
            $absoluteFilename = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $this->_installDir . $filename);
            $this->_log->addDebug(sprintf('Updating file "%s"', $filename));

            if (!is_dir($foldername)) {
                if (!mkdir($foldername, $this->dirPermissions, true)) {
                    $this->_log->addError(sprintf('Directory "%s" has to be writeable!', $foldername));

                    return false;
                }
            }

            // Skip if entry is a directory
            if (substr($filename, -1, 1) == DIRECTORY_SEPARATOR) {
                continue;
            }

            // Read file contents from archive
            $contents = zip_entry_read($file, zip_entry_filesize($file));

            if ($contents === false) {
                $this->_log->addError(sprintf('Coud not read zip entry "%s"', $file));
                continue;
            }

            // Write to file
            if (file_exists($absoluteFilename)) {
                if (!is_writable($absoluteFilename)) {
                    $this->_log->addError(sprintf('Could not overwrite "%s"!', $absoluteFilename));

                    zip_close($zip);

                    return false;
                }
            } else {
                // touch will fail if PHP is not the owner of the file, and file_put_contents is faster than touch.
                if (file_put_contents($absoluteFilename, '') === false) {
                    $this->_log->addError(sprintf('The file "%s" could not be created!', $absoluteFilename));
                    zip_close($zip);

                    return false;
                }

                $this->_log->addDebug(sprintf('File "%s" created', $absoluteFilename));
            }

            $updateHandle = fopen($absoluteFilename, 'w');

            if (!$updateHandle) {
                $this->_log->addError(sprintf('Could not open file "%s"!', $absoluteFilename));
                zip_close($zip);

                return false;
            }


            if (false === fwrite($updateHandle, $contents)) {
                $this->_log->addError(sprintf('Could not write to file "%s"!', $absoluteFilename));
                zip_close($zip);
                return false;
            }

            fclose($updateHandle);

            //If file is a update script, include
            if ($filename == $this->updateScriptName) {
                $this->_log->addDebug(sprintf('Try to include update script "%s"', $absoluteFilename));
                require($absoluteFilename);

                $this->_log->addInfo(sprintf('Update script "%s" included!', $absoluteFilename));
                if (!unlink($absoluteFilename)) {
                    $this->_log->addWarning(sprintf('Could not delete update script "%s"!', $absoluteFilename));
                }
            }
        }

        zip_close($zip);

        $this->_log->addNotice(sprintf('Update "%s" successfully installed', $version));

        return true;
    }

    /**
     * Simulate update process.
     *
     * @param string $updateFile
     * @return bool
     */
    protected function _simulateInstall($updateFile) {
        $this->_log->addNotice('[SIMULATE] Install new version');
        clearstatcache();

        // Check if zip file could be opened
        $zip = zip_open($updateFile);
        if (!is_resource($zip)) {
            $this->_log->addError(sprintf('Could not open zip file "%s", error: %d', $updateFile, $zip));

            return false;
        }

        $i = -1;
        $files = [];
        $simulateSuccess = true;

        while ($file = zip_read($zip)) {
            $i++;

            $filename = zip_entry_name($file);
            $foldername = $this->_installDir . dirname($filename);
            $absoluteFilename = $this->_installDir . $filename;

            $files[$i] = [
                'filename' => $filename,
                'foldername' => $foldername,
                'absolute_filename' => $absoluteFilename,
            ];

            $this->_log->addDebug(sprintf('[SIMULATE] Updating file "%s"', $filename));

            // Check if parent directory is writable
            if (!is_dir($foldername)) {
                mkdir($foldername);
                $this->_log->addDebug(sprintf('[SIMULATE] Create directory "%s"', $foldername));
                $files[$i]['parent_folder_exists'] = false;

                $parent = dirname($foldername);
                if (!is_writable($parent)) {
                    $files[$i]['parent_folder_writable'] = false;

                    $simulateSuccess = false;
                    $this->_log->addWarning(sprintf('[SIMULATE] Directory "%s" has to be writeable!', $parent));
                } else {
                    $files[$i]['parent_folder_writable'] = true;
                }
            }

            // Skip if entry is a directory
            if (substr($filename, -1, 1) == DIRECTORY_SEPARATOR) {
                continue;
            }

            // Read file contents from archive
            $contents = zip_entry_read($file, zip_entry_filesize($file));
            if ($contents === false) {
                $files[$i]['extractable'] = false;

                $simulateSuccess = false;
                $this->_log->addWarning(sprintf('[SIMULATE] Coud not read contents of file "%s" from zip file!', $filename));
            }

            // Write to file
            if (file_exists($absoluteFilename)) {
                $files[$i]['file_exists'] = true;
                if (!is_writable($absoluteFilename)) {
                    $files[$i]['file_writable'] = false;

                    $simulateSuccess = false;
                    $this->_log->addWarning(sprintf('[SIMULATE] Could not overwrite "%s"!', $absoluteFilename));
                }
            } else {
                $files[$i]['file_exists'] = false;

                if (is_dir($foldername)) {
                    if (!is_writable($foldername)) {
                        $files[$i]['file_writable'] = false;

                        $simulateSuccess = false;
                        $this->_log->addWarning(sprintf('[SIMULATE] The file "%s" could not be created!', $absoluteFilename));
                    } else {
                        $files[$i]['file_writable'] = true;
                    }
                } else {
                    $files[$i]['file_writable'] = true;

                    $this->_log->addDebug(sprintf('[SIMULATE] The file "%s" could be created', $absoluteFilename));
                }
            }

            if ($filename == $this->updateScriptName) {
                $this->_log->addDebug(sprintf('[SIMULATE] Update script "%s" found', $absoluteFilename));
                $files[$i]['update_script'] = true;
            } else {
                $files[$i]['update_script'] = false;
            }
        }

        $this->_simulationResults = $files;

        return $simulateSuccess;
    }

    /**
     * Run callbacks after each update finished.
     *
     * @param string $updateVersion
     * @return void
     */
    private function runOnEachUpdateFinishCallbacks($updateVersion) {
        foreach ($this->onEachUpdateFinishCallbacks as $callback) {
            call_user_func($callback, $updateVersion);
        }
    }

    /**
     * Run callbacks after all updates finished.
     *
     * @param string $updatedVersions ]
     * @return void
     */
    private function runOnAllUpdateFinishCallbacks($updatedVersions) {
        foreach ($this->onAllUpdateFinishCallbacks as $callback) {
            call_user_func($callback, $updatedVersions);
        }
    }

    /**
     * Get an array of versions which will be installed.
     *
     * @return array
     */
    public function getVersionsToUpdate() {
        if (count($this->_updates) > 0) {
            return array_map(function ($update) {
                return $update['version'];
            }, $this->_updates);
        }

        return [];
    }

    /**
     * Add callback which is executed after each update finished.
     *
     * @param callable $callback
     * @return $this
     */
    public function onEachUpdateFinish($callback) {
        $this->onEachUpdateFinishCallbacks[] = $callback;

        return $this;
    }

    /**
     * Add callback which is executed after all updates finished.
     *
     * @param callable $callback
     * @return $this
     */
    public function setOnAllUpdateFinishCallbacks($callback) {
        $this->onAllUpdateFinishCallbacks[] = $callback;

        return $this;
    }

    /**
     * Remove directory recursively.
     *
     * @param string $dir
     * @return bool
     */
    private function _removeDir($dir) {
        $this->_log->addDebug(sprintf('Remove directory "%s"', $dir));

        if (!is_dir($dir)) {
            $this->_log->addWarning(sprintf('"%s" is not a directory!', $dir));

            return false;
        }

        $objects = array_diff(scandir($dir), array('.', '..'));
        foreach ($objects as $object) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . $object)) {
                $this->_removeDir($dir . DIRECTORY_SEPARATOR . $object);
            } else {
                unlink($dir . DIRECTORY_SEPARATOR . $object);
            }
        }

        return rmdir($dir);
    }
}
