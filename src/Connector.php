<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi;

use Akeeba\BackupJsonApi\DataShape\BackupOptions;
use Akeeba\BackupJsonApi\DataShape\DownloadOptions;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;

/**
 * Akeeba Backup JSON API connector
 *
 * @method  void    autodetect()  Auto-detect best connection options
 * @method  object  information()  Get API information
 * @method  object  backup(BackupOptions $backupOptions, ?callable $progressCallback = null) Start a backup
 * @method  array   getBackups(int $from = 0, $limit = 200)  List backups
 * @method  object  getBackup(int $id = 0)  Get a backup record
 * @method  void    deleteFiles(int $id) Delete the files of a backup record
 * @method  void    delete(int $id) Delete a backup record
 * @method  void    download(DownloadOptions $options) Download a backup record
 * @method  array   getProfiles() Get the backup profiles
 * @method  array   importConfiguration(string $jsonData) Import a backup profile from JSON
 * @method  object  exportConfiguration(int $id) Export a backup profile to JSON
 * @method  object  getUpdateInformation(bool $force = false) Get the update information of the backup product
 * @method  void    downloadUpdate() Download the update package to the server
 * @method  void    extractUpdate() Extracts the update package to the server
 * @method  void    installUpdate() Performs the necessary installation steps for the update on the server
 * @method  void    cleanupUpdate() Cleans up the download update package on the server
 *
 * @since   1.0.0
 */
class Connector
{
	private array $callables = [];

	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __call(string $name, array $arguments)
	{
		if (!isset($this->callables[$name]))
		{
			$class = __NAMESPACE__ . '\\HighLevel\\' . ucfirst($name);

			if (!(class_exists($class, true)))
			{
				throw new \BadMethodCallException(
					sprintf(
						'Unknown method %s->%s()',
						__CLASS__,
						$name
					),
					255
				);
			}

			$this->callables[$name] = new $class($this->httpClient);
		}

		return call_user_func($this->callables[$name], ...$arguments);
	}
}
