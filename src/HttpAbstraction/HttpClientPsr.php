<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Options;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A generic HTTP client using PSR-17, and PSR-18 objects.
 *
 * This implementation can be used with any compatible library such as Guzzle, PHP-HTTP, HTTPlug, whatever have you.
 *
 * IMPORTANT! This is primarily meant as an example of a custom implementation, not the end-all-be-all client
 * implementation. There are specific implementations using Guzzle 7 and Joomla Framework. Most importantly, this is not
 * a great way to download large backup archives as the contents need to fit entirely into the available PHP memory
 * before being written out to disk.
 */
class HttpClientPsr extends AbstractHttpClient
{
	/**
	 * Public constructor.
	 *
	 * Please note that the PSR-18 HTTP Client object ($http) MUST respect the $options->capath filepath to the
	 * Certification Authority cache file (cacert.pem). Since this is implementation-specific it is outside the scope of
	 * this class.
	 *
	 * Special care must be taken to override the applyOptions method. Whenever it's called, you will need to reapply
	 * the $options->capath filepath as it MAY have changed.
	 *
	 * @param   Options                  $options         The configuration options object
	 * @param   RequestFactoryInterface  $requestFactory  PSR-17 HTTP Request Factory
	 * @param   StreamFactoryInterface   $streamFactory   PSR-17 Stream Factory (returns PSR-7 StreamInterface objects)
	 * @param   ClientInterface          $http            PSR-18 HTTP Client Object
	 */
	public function __construct(
		protected Options               $options,
		private RequestFactoryInterface $requestFactory,
		private StreamFactoryInterface  $streamFactory,
		private ClientInterface         $http
	)
	{
		parent::__construct($this->options);
	}

	/** @inheritDoc */
	public function downloadToFile(string $url, mixed $fp, int $from = 0, int $to = 0): void
	{
		if ($to < $from)
		{
			[$to, $from] = [$from, $to];
		}

		$request = $this->requestFactory
			->createRequest('GET', $url)
			->withHeader('User-Agent', $this->options->ua);

		if (!empty($from) || !empty($to))
		{
			$request->withHeader('Range', sprintf('bytes=%d=%d', $from, $to));
		}

		$response = $this->http->sendRequest($request);

		if ($response->getStatusCode() < 200 || $response->getStatusCode() > 200)
		{
			throw new CommunicationError(
				$response->getStatusCode(),
				sprintf('Unexpected HTTP status %d', $response->getStatusCode())
			);
		}

		if (is_resource($fp))
		{
			$fileStream = $this->streamFactory->createStreamFromResource($fp);
		}
		else
		{
			$fileStream = $this->streamFactory->createStreamFromFile($fp);
		}

		$fileStream->seek($from);
		$fileStream->write((string) $response->getBody());
	}

	/** @inheritDoc */
	protected function getRawResponse(string $verb, string $apiMethod, array $data = []): string
	{
		$url = $this->makeURL($apiMethod, $data);

		$this->logger->debug(sprintf('Sending Akeeba Backup / Akeeba Solo JSON API request for method %s with %s', $apiMethod, $verb));
		$this->logger->debug('URL: ' . $url);
		$this->logger->debug('>> Data:' . PHP_EOL . print_r($data, true));

		if ($verb == 'POST')
		{
			$payload = http_build_query($this->getQueryStringParameters($apiMethod, $data));
			$request = $this->requestFactory
				->createRequest('POST', $url)
				->withBody($this->streamFactory->createStream($payload));
		}
		else
		{
			$request = $this->requestFactory
				->createRequest('GET', $url);
		}

		$request->withHeader('User-Agent', $this->options->ua);

		$response = $this->http->sendRequest($request);

		if ($response->getStatusCode() < 200 || $response->getStatusCode() > 200)
		{
			throw new CommunicationError(
				$response->getStatusCode(),
				sprintf('Unexpected HTTP status %d', $response->getStatusCode())
			);
		}

		return (string) $response->getBody();
	}
}