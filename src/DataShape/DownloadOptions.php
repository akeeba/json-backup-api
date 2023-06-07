<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\DataShape;

use Akeeba\BackupJsonApi\DataObject\ImmutableDataObject;
use Akeeba\BackupJsonApi\Exception\NoDownloadMode;
use Akeeba\BackupJsonApi\Exception\NoDownloadPath;
use Akeeba\BackupJsonApi\Exception\NoDownloadURL;

/**
 * Backup download options
 *
 * @property   string $mode           Download mode: http, curl, chunk
 * @property   string $path           Path to download into
 * @property   int    $id             Backup ID to download
 * @property   string $filename       Filename to download into
 * @property   bool   $delete         Should I delete the remote files afterwards?
 * @property   int    $part           Which part to download (0-based)
 * @property   int    $chunkSize      Chunk size for HTTP downloads, corresponds to CLI option chunk_size
 * @property   string $url            cURL download URL, corresponds to CLI option dlurl
 * @property   string $authentication Authentication part of the cURL download URL
 *
 * @since 1.0.0
 */
class DownloadOptions extends ImmutableDataObject
{
	/** @inheritDoc */
	public function __construct($properties = [])
	{
		$properties = array_merge([
			'mode'      => 'http',
			'path'      => getcwd(),
			'id'        => 0,
			'filename'  => '',
			'delete'    => false,
			'part'      => -1,
			'chunkSize' => 0,
			'url'       => '',
		], $properties);

		$properties['part'] = ($properties['part'] < 0) ? $properties['part'] : $properties['part'];

		if (!in_array($properties['mode'], ['http', 'curl', 'chunk']))
		{
			throw new NoDownloadMode();
		}

		$properties['path'] = rtrim($properties['path'], '/');

		if (empty($properties['path']) || !is_dir($properties['path']))
		{
			throw new NoDownloadPath();
		}

		switch ($properties['mode'])
		{
			case 'http':
				break;

			case 'chunk':
				if ($properties['chunkSize'] <= 1)
				{
					$properties['chunkSize'] = 10;
				}
				break;

			case 'curl':
				$properties['url'] = rtrim($properties['url'], '/');

				if (empty($properties['url']))
				{
					throw new NoDownloadURL();
				}

				[$properties['url'], $properties['authentication']] = $this->processAuthenticatedUrl($properties['url']);
				break;
		}

		parent::__construct($properties);
	}

	/**
	 * Process a URL, extracting its authentication part as a separate string. Used for downloading with cURL.
	 *
	 * @param   string  $url  The URL to process e.g. "ftp://user:password@ftp.example.com/path/to/file.jpa"
	 *
	 * @return  array  [$url, $authentication]
	 * @since   1.0.0
	 */
	private function processAuthenticatedUrl(string $url): array
	{
		$url                 = rtrim($url, '/');
		$authentication      = '';
		$doubleSlashPosition = strpos($url, '//');

		if ($doubleSlashPosition === false)
		{
			return [$url, $authentication];
		}

		$offset         = $doubleSlashPosition + 2;
		$atSignPosition = strpos($url, '@', $offset);
		$colonPosition  = strpos($url, ':', $offset);

		if (($colonPosition === false) || ($atSignPosition === false))
		{
			return [$url, $authentication];
		}

		$offset = $colonPosition + 1;

		while ($atSignPosition !== false)
		{
			$atSignPosition = strpos($url, '@', $offset);

			if ($atSignPosition !== false)
			{
				$offset = $atSignPosition + 1;
			}
		}

		$atSignPosition = $offset - 1;
		$authentication = substr($url, $doubleSlashPosition + 2, $atSignPosition - $doubleSlashPosition - 2);
		$protocol       = substr($url, 0, $doubleSlashPosition + 2);
		$restOfURL      = substr($url, $atSignPosition + 1);
		$url            = $protocol . $restOfURL;

		return [$url, $authentication];
	}

}