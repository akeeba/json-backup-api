<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Joomla\Http\Exception\InvalidResponseCodeException;
use Joomla\Http\Http;
use Joomla\Http\HttpFactory;

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

		$headers = [];

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

		if ($verb == 'POST')
		{
			$payload = http_build_query($this->getQueryStringParameters($apiMethod, $data));

			return $this->http->post($url, $payload)->body;
		}

		return $this->http->get($url)->body;
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