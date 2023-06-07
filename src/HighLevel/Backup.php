<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\DataObject\DataObject;
use Akeeba\BackupJsonApi\DataShape\BackupOptions;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Take a backup
 *
 * @since  1.0.0
 */
class Backup
{
	private LoggerInterface $logger;

	public function __construct(private HttpClientInterface $httpClient)
	{
		$this->logger = $this->httpClient->getOptions()->logger;
	}

	public function __invoke(BackupOptions $backupOptions, ?callable $progressCallback = null): object
	{
		$info           = $this->startBackup($backupOptions);
		$data           = $info->data;
		$backupID       = $info->backupID ?? null;
		$backupRecordID = $info->backupRecordID ?? 0;
		$archive        = $info->archive ?? '';

		while ($data?->body?->data?->HasRun)
		{
			if ($progressCallback)
			{
				$progressCallback($data?->body?->data);
			}

			$backupID       = ($info->backupID ?? null) ?: $backupID;
			$backupRecordID = ($info->backupRecordID ?? 0) ?: $backupRecordID;
			$archive        = ($info->archive ?? '') ?: $archive;
			$info           = $this->stepBackup($backupID);
			$data           = $info->data;
		}

		if ($progressCallback)
		{
			$progressCallback($data?->body?->data);
		}

		if ($data->body->status != 200)
		{
			throw new RemoteError('Error ' . $data->body->status . ": " . $data->body->data);
		}

		return new DataObject([
			'id'      => $backupRecordID,
			'archive' => $archive,
		]);
	}

	private function handleAPIResponse(object $data): object
	{
		$backupID       = null;
		$backupRecordID = 0;
		$archive        = '';

		if ($data->body?->status != 200)
		{
			throw new RemoteError('Error ' . $data->body->status . ": " . $data->body->data);
		}

		if (isset($data->body->data->BackupID))
		{
			$backupRecordID = $data->body->data->BackupID;
			$this->logger->debug('Got backup record ID: ' . $backupRecordID);
		}

		if (isset($data->body->data->backupid))
		{
			$backupID = $data->body->data->backupid;
			$this->logger->debug('Got backupID: ' . $backupID);
		}

		if (isset($data->body->data->Archive))
		{
			$archive = $data->body->data->Archive;
			$this->logger->debug('Got archive name: ' . $archive);
		}

		return (object) [
			'backupID'       => $backupID,
			'backupRecordID' => $backupRecordID,
			'archive'        => $archive,
		];
	}

	private function startBackup(BackupOptions $backupOptions): object
	{
		$data = $this->httpClient->doQuery('startBackup', [
			'profile'     => (int) $backupOptions->profile,
			'description' => $backupOptions->description ?: 'Remote backup',
			'comment'     => $backupOptions->comment,
		]);
		$info = $this->handleAPIResponse($data);

		$info->data = $data;

		return $info;
	}

	private function stepBackup(?string $backupID): object
	{
		$params = [];

		if ($backupID)
		{
			$params['backupid'] = $backupID;
		}

		$data = $this->httpClient->doQuery('stepBackup', $params);
		$info = $this->handleAPIResponse($data);

		$info->data = $data;

		return $info;
	}
}
