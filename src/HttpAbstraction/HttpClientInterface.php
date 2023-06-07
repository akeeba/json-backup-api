<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Exception\ApiException;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Options;
use Throwable;

/**
 * Abstraction of an HTTP client for the very simple purposes we need it in this library
 */
interface HttpClientInterface
{
	/**
	 * Performs an Akeeba Backup JSON API request and returns the parsed result as an object
	 *
	 * @return  object  The parsed API response
	 *
	 * @throws  ApiException
	 * @throws  CommunicationError
	 * @throws  Throwable
	 *
	 * @since   1.0.0
	 */
	public function doQuery(string $apiMethod, array $data = []): object;

	/**
	 * Create an API URL
	 *
	 * @param   string       $apiMethod  The API method to execute on the remote server.
	 * @param   array        $data       Any data to send to the remote server.
	 * @param   string|null  $verb       The verb to use. Default: figure it out from the options.
	 *
	 * @return  string
	 */
	public function makeURL(string $apiMethod, array $data = [], ?string $verb = null): string;

	/**
	 * Returns a (modified) copy of the connector options
	 *
	 * @param   array  $overrides  Any overrides you'd like to apply
	 *
	 * @return  Options
	 * @since   1.0.0
	 */
	public function getOptions(array $overrides = []): Options;

	/**
	 * Sets the connector options
	 *
	 * @param   Options  $options
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function setOptions(Options $options): void;

	/**
	 * Download the raw binary data returned from a URL into a file
	 *
	 * @param   string           $url   The URL to download data from
	 * @param   string|resource  $fp    The filepath or file pointer to download the data into
	 * @param   int              $from  Starting position (0-based) of the data to retrieve
	 * @param   int              $to    Ending position (0-based) of the data to retrieve
	 *
	 * @return  void
	 */
	public function downloadToFile(string $url, mixed $fp, int $from = 0, int $to = 0): void;
}