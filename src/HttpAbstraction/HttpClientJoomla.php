<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Joomla\Http\Exception\InvalidResponseCodeException;
use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * An HTTP client using Joomla Framework
 *
 * @since 1.0.0
 */
class HttpClientJoomla extends AbstractHttpClient
{
	private ?Http $http;

	/**
	 * @inheritDoc
	 */
	public function downloadToFile(string $url, mixed $fp, int $from = 0, int $to = 0): void
	{
		if ($to < $from)
		{
			[$to, $from] = [$from, $to];
		}

		if (!is_resource($fp))
		{
			$fp = fopen($fp, 'w+');
		}

		$http = (new HttpFactory())->getHttp(
			[
				'curl.certpath'   => $this->options->capath,
				'userAgent'       => $this->options->ua,
				'follow_location' => true,
				'transport.curl'  => [
					CURLOPT_AUTOREFERER    => 1,
					CURLOPT_FAILONERROR    => true,
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_HEADER         => false,
					CURLOPT_FILE           => $fp,
				],
			],
			['curl']
		);

		/**
		 * The authentication headers are not optional here: the v3 API is authenticated by header alone, so a
		 * downloadDirect URL carries no credential of its own.
		 */
		$headers = $this->getRequestHeaders();

		if (!empty($from) || !empty($to))
		{
			$headers['Range'] = sprintf('bytes=%d=%d', $from, $to);
		}


		try
		{
			$http->get($url, $headers);
		}
		catch (\RuntimeException $e)
		{
			if ($e->getMessage() !== 'No HTTP response received')
			{
				throw $e;
			}
		}
		catch (InvalidResponseCodeException)
		{
			// No worries, this is expected.
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getRawResponse(string $verb, string $apiMethod, array $data = []): string
	{
		$url = $this->makeURL($apiMethod, $data);

		$this->logger->debug(sprintf('Sending Akeeba Backup / Akeeba Solo JSON API request for method %s with %s', $apiMethod, $verb));
		$this->logger->debug('URL: ' . $url);
		$this->logger->debug('>> Data:' . PHP_EOL . print_r($data, true));

		$headers = $this->getRequestHeaders();

		if ($verb == 'POST')
		{
			$payload = http_build_query($this->getQueryStringParameters($apiMethod, $data));

			return $this->getResponseBody($this->http->post($url, $payload, $headers));
		}

		return $this->getResponseBody($this->http->get($url, $headers));
	}

	/**
	 * Extracts the body from a Joomla Framework HTTP response.
	 *
	 * The response object changed shape between major versions of joomla/http. Up to and including 2.x it was a plain
	 * data object with a public $body string. From 3.x on it extends a PSR-7 response, where the body is a stream
	 * reached through getBody() and the old property does not exist at all — reading it yields a warning and NULL,
	 * which surfaces as a TypeError from this method's return type rather than as anything diagnostic.
	 *
	 * @param   object  $response  The response returned by the Joomla Framework HTTP client
	 *
	 * @return  string
	 * @since   1.1.0
	 */
	private function getResponseBody(object $response): string
	{
		if ($response instanceof ResponseInterface)
		{
			return (string) $response->getBody();
		}

		return (string) ($response->body ?? '');
	}

	protected function applyOptions()
	{
		parent::applyOptions();

		$this->http = (new HttpFactory())->getHttp(
			[
				'curl.certpath'   => $this->options->capath,
				'userAgent'       => $this->options->ua,
				'follow_location' => true,
			],
			['curl']
		);
	}


}