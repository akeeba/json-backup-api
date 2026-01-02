<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\DataShape\DownloadOptions;
use Akeeba\BackupJsonApi\Exception\CannotDownloadFile;
use Akeeba\BackupJsonApi\Exception\CannotWriteFile;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\NoBackupID;
use Akeeba\BackupJsonApi\Exception\NoFilesInBackupRecord;
use Akeeba\BackupJsonApi\Exception\NoSuchBackupRecord;
use Akeeba\BackupJsonApi\Exception\NoSuchPart;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Download backup archive files from the server
 *
 * @since  1.0.0
 */
class Download
{
	private LoggerInterface $logger;

	public function __construct(private HttpClientInterface $httpClient)
	{
		$this->logger = $this->httpClient->getOptions()->logger;
	}

	public function __invoke(DownloadOptions $options): void
	{
		if ($options->id <= 0)
		{
			throw new NoBackupID();
		}

		switch ($options->mode)
		{
			case 'http':
				$this->downloadHTTP($options);
				break;

			case 'chunk':
				$this->downloadChunk($options);
				break;

			case 'curl':
				$this->downloadCURL($options);
				break;
		}
	}

	private function downloadHTTP(DownloadOptions $params): void
	{
		// Get the backup info
		[, $parts, $fileInformation] = $this->getBackupArchiveInformation($params);

		$path       = $params->path;
		$part_start = 1;
		$part_end   = $parts;

		// Was I asked to download only one specific part?
		if ($params->part > 0)
		{
			$part_start = $params->part;
			$part_end   = $params->part;
		}

		for ($part = $part_start; $part <= $part_end; $part++)
		{
			// Open file pointer
			$name = $fileInformation[$part]?->name;
			$size = $fileInformation[$part]?->size;

			if (empty($name))
			{
				throw new NoSuchPart();
			}

			$filePath = $path . DIRECTORY_SEPARATOR . $name;
			$fp       = @fopen($filePath, 'w');

			if ($fp === false)
			{
				throw new CannotWriteFile($filePath);
			}

			try
			{
				// Get the signed URL
				$url = $this->httpClient->makeURL('downloadDirect', [
					'backup_id' => $params->id,
					'part_id'   => $part,
				], 'GET');

				$this->httpClient->downloadToFile($url, $fp, 0, 0);
			}
			catch (CommunicationError $e)
			{
				throw new CannotDownloadFile(
					sprintf(
						'Could not download file ‘%s’ -- Network error “%s”',
						$filePath,
						$e->getMessage()
					),
					105,
					$e
				);
			}
			catch (\Throwable $e)
			{
				throw new CannotDownloadFile(
					sprintf(
						'Could not download file ‘%s’ -- Uncaught error “%s”',
						$filePath,
						$e->getMessage()
					),
					105,
					$e
				);
			}
			finally
			{
				if (is_resource($fp))
				{
					@fclose($fp);
				}
			}

			// Check file size
			clearstatcache();
			$filesize = @filesize($filePath);

			if ($filesize !== false && $filesize != $size)
			{
				$this->logger->warning(
					sprintf(
						'Filesize mismatch on %s -- Expected %d, got %d',
						$filePath,
						$filesize,
						$size
					)
				);

				throw new CannotDownloadFile(
					sprintf(
						'Could not download file ‘%s’ -- Expected file size %d, got %d',
						$filePath,
						$filesize,
						$size
					),
					105
				);
			}

			$filename = $params->filename;

			// Try renaming
			if (strlen($filename))
			{
				@rename($filePath, $path . DIRECTORY_SEPARATOR . $filename);

				if (file_exists($path . DIRECTORY_SEPARATOR . $filename))
				{
					$this->logger->debug(sprintf("Successfully renamed %s to %s", $name, $filename));
				}
				else
				{
					$this->logger->debug(sprintf("Failed to rename %s to %s", $name, $filename));
				}
			}

			$this->logger->debug(sprintf("Successfully downloaded %s", $name));
		}
	}

	private function downloadChunk(DownloadOptions $params): void
	{
		// Get the backup info
		[, $parts, $fileInformation] = $this->getBackupArchiveInformation($params);

		$path       = $params->path;
		$chunk_size = $params->chunkSize;
		$part_start = 1;
		$part_end   = $parts;

		// Was I asked to download only one specific part?
		if ($params->part > 0)
		{
			$part_start = $params->part;
			$part_end   = $params->part;
		}

		for ($part = $part_start; $part <= $part_end; $part++)
		{
			// Open file pointer
			$name     = $fileInformation[$part]->name;
			$size     = $fileInformation[$part]->size;
			$filePath = $path . DIRECTORY_SEPARATOR . $name;
			$fp       = @fopen($filePath, 'w');

			if ($fp === false)
			{
				throw new CannotWriteFile($filePath);
			}

			$frag = 0;
			$done = false;

			while (!$done)
			{
				$data = $this->httpClient->doQuery('download', [
					'backup_id'  => $params->id,
					'part'       => $part,
					'segment'    => ++$frag,
					'chunk_size' => $chunk_size,
				]);

				switch ($data->body->status)
				{
					case 200:
						$rawData = base64_decode($data->body->data);
						$len     = strlen($rawData); //echo "\tWriting $len bytes\n";
						$this->logger->debug(sprintf('Writing a chunk of %d bytes', $len));
						fwrite($fp, $rawData);
						unset($rawData);
						unset($data);
						break;

					case 404:
						if ($frag === 1)
						{
							throw new NoFilesInBackupRecord($params->id);
						}

						$done = true;

						break;

					default:
						throw new CannotDownloadFile(sprintf("Could not download chunk #%02u of file ‘%s’ -- Remote API error %d : %s", $frag, $filePath, $data->body->status, $data->body->data));
						break;
				}
			}

			if (is_resource($fp))
			{
				@fclose($fp);
			}

			// Check file size
			clearstatcache();
			$filesize = @filesize($filePath);

			if ($filesize !== false && $filesize != $size)
			{
				$this->logger->warning(
					sprintf(
						'Filesize mismatch on %s -- Expected %d, got %d',
						$filePath,
						$filesize,
						$size
					)
				);

				throw new CannotDownloadFile(
					sprintf(
						'Could not download file ‘%s’ -- Expected file size %d, got %d',
						$filePath,
						$filesize,
						$size
					),
					105
				);
			}

			$filename = $params->filename;

			// Try renaming
			if (strlen($filename))
			{
				@rename($filePath, $path . DIRECTORY_SEPARATOR . $filename);

				if (file_exists($path . DIRECTORY_SEPARATOR . $filename))
				{
					$this->logger->debug(sprintf("Successfully renamed %s to %s", $name, $filename));
				}
				else
				{
					$this->logger->debug(sprintf("Failed to rename %s to %s", $name, $filename));
				}
			}

			$this->logger->debug(sprintf("Successfully downloaded %s", $name));
		}
	}

	private function downloadCURL(DownloadOptions $params): void
	{
		// Get the backup info
		[, $parts, $fileInformation] = $this->getBackupArchiveInformation($params);

		$path           = $params->path;
		$url            = $params->url;
		$authentication = $params->authentication;
		$part_start     = 1;
		$part_end       = $parts;

		// Was I asked to download only one specific part?
		if ($params->part > 0)
		{
			$part_start = $params->part;
			$part_end   = $params->part;
		}

		for ($part = $part_start; $part <= $part_end; $part++)
		{
			// Open file pointer
			$name     = $fileInformation[$part]->name;
			$size     = $fileInformation[$part]->size;
			$filePath = $path . DIRECTORY_SEPARATOR . $name;
			$fp       = @fopen($filePath, 'w');

			if ($fp === false)
			{
				throw new CannotWriteFile($filePath);
			}

			// Get the target path
			$url = $url . '/' . $name;

			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64; rv:2.0.1) Gecko/20110506 Firefox/4.0.1');
			curl_setopt($ch, CURLOPT_CAINFO, $this->httpClient->getOptions()->capath);

			if (!empty($authentication))
			{
				curl_setopt($ch, CURLOPT_USERPWD, $authentication);
			}

			$status = curl_exec($ch);

			if (is_resource($fp))
			{
				@fclose($fp);
			}

			$errno      = curl_errno($ch);
			$errmessage = curl_error($ch);

			if (version_compare(PHP_VERSION, '8.5.0', 'lt'))
			{
				curl_close($ch);
			}

			if ($errno !== 0)
			{
				throw new CannotDownloadFile(
					sprintf(
						'Could not download ‘%s’ over cURL -- cURL error #%d : %s',
						$filePath,
						$errno,
						$errmessage
					)
				);
			}

			if ($status === false)
			{
				throw new NoFilesInBackupRecord($params->id);
			}

			// Check file size
			clearstatcache();
			$filesize  = @filesize($filePath);

			if ($filesize !== false && $filesize != $size)
			{
				$this->logger->warning(
					sprintf(
						'Filesize mismatch on %s -- Expected %d, got %d',
						$filePath,
						$filesize,
						$size
					)
				);

				throw new CannotDownloadFile(
					sprintf(
						'Could not download file ‘%s’ -- Expected file size %d, got %d',
						$filePath,
						$filesize,
						$size
					),
					105
				);
			}

			$filename = $params->filename;

			// Try renaming
			if (strlen($filename))
			{
				@rename($filePath, $path . DIRECTORY_SEPARATOR . $filename);

				if (file_exists($path . DIRECTORY_SEPARATOR . $filename))
				{
					$this->logger->debug(sprintf("Successfully renamed %s to %s", $name, $filename));
				}
				else
				{
					$this->logger->debug(sprintf("Failed to rename %s to %s", $name, $filename));
				}
			}

			$this->logger->debug(sprintf("Successfully downloaded %s", $name));
		}
	}

	private function getBackupArchiveInformation(DownloadOptions $params): array
	{
		$data            = $this->httpClient->doQuery(
			'getBackupInfo', [
				'backup_id' => $params->id,
			]
		);

		if ($data->body->status == 404)
		{
			throw new NoSuchBackupRecord();
		}

		$parts           = $data->body->data->multipart;
		$fileDefinitions = $data->body->data->filenames;
		$fileRecords     = [];

		foreach ($fileDefinitions as $fileDefinition)
		{
			$fileRecords[$fileDefinition->part] = (object) [
				'name' => $fileDefinition->name,
				'size' => $fileDefinition->size,
			];
		}

		$parts = max($parts, 1);

		if (!(is_array($fileDefinitions) || $fileDefinitions instanceof \Countable ? count($fileDefinitions) : 0))
		{
			throw new NoFilesInBackupRecord($params->id);
		}

		return [
			$data,
			$parts,
			$fileRecords,
		];
	}
}
