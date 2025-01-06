<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Options;
use Akeeba\BackupJsonApi\Uri\Uri;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\RequestOptions;

/**
 * An HTTP client using Guzzle 7
 *
 * @since 1.0.0
 */
class HttpClientGuzzle extends AbstractHttpClient
{
	public function __construct(
		Options       $options,
		private int   $connectionTimeout = 5,
		private int   $readTimeout = 300,
		private int   $timeout = 300,
		private array $proxyOptions = [],
	)
	{
		parent::__construct($options);
	}

	/**
	 * @inheritDoc
	 */
	public function downloadToFile(string $url, mixed $fp, int $from = 0, int $to = 0): void
	{
		if ($to < $from)
		{
			[$to, $from] = [$from, $to];
		}

		$headers = [];

		if (!empty($from) || !empty($to))
		{
			$headers['Range'] = sprintf('bytes=%d=%d', $from, $to);
		}

		$options = $this->getRequestOptions($headers);

		if (!is_resource($fp))
		{
			$fp = Psr7Utils::tryFopen($fp, 'w+');
		}

		$options[RequestOptions::SINK] = $fp;

		$this->getClient()->get($url, $options);
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
			$options  = $this->getRequestOptions();
			$options[RequestOptions::FORM_PARAMS] = $this->getQueryStringParameters($apiMethod, $data);
			$response = $this->getClient()->post($url, $options);
		}
		else
		{
			$response = $this->getClient()->get($url, $this->getRequestOptions());
		}

		return (string) $response->getBody();
	}

	private function getClient(): Client
	{
		return new Client();
	}

	private function getRequestOptions(array $headers = []): array
	{
		$options = [
			RequestOptions::ALLOW_REDIRECTS => [
				'max'     => 20,
				'strict'  => true,
				'referer' => true,
			],
			RequestOptions::CONNECT_TIMEOUT => $this->connectionTimeout,
			RequestOptions::HEADERS         => array_merge([
				'User-Agent' => $this->options->ua,
			], $headers),
			RequestOptions::READ_TIMEOUT    => $this->readTimeout,
			RequestOptions::SYNCHRONOUS     => true,
			RequestOptions::TIMEOUT         => $this->timeout,
			RequestOptions::VERIFY          => $this->options->capath,
		];

		$proxySettings = $this->getProxySettings();

		if (!empty($proxySettings))
		{
			$options[RequestOptions::PROXY] = $proxySettings;
		}

		return $options;
	}

	private function getProxySettings(): ?array
	{
		// Get the application configuration variables
		$enabled = (bool) ($this->proxyOptions['proxy_enabled'] ?? '');
		$host    = trim($this->proxyOptions['proxy_host'] ?? '');
		$port    = (int) ($this->proxyOptions['proxy_port'] ?? 0);
		$user    = $this->proxyOptions['proxy_user'] ?? '';
		$pass    = $this->proxyOptions['proxy_pass'] ?? '';
		$noProxy = $this->proxyOptions['proxy_no'] ?? '';

		// Are we really enabled and ready to use a proxy server?
		$enabled = $enabled && !empty($host) && is_int($port) && $port > 0 && $port < 65536;

		if (!$enabled)
		{
			return null;
		}

		// Construct the proxy URL out of the individual components
		$proxyUri = new Uri('http://' . $host);
		$proxyUri->port = $port;

		if (!empty($user) && !empty($pass))
		{
			$proxyUri->user = $user;
			$proxyUri->pass = $pass;
		}

		$proxyUrl = $proxyUri->toString(['scheme', 'user', 'pass', 'host', 'port']);

		// Get the no proxy domain names
		if (!is_array($noProxy))
		{
			$noProxy = explode(',', $noProxy);
			$noProxy = array_map('trim', $noProxy);
			$noProxy = array_filter($noProxy);
		}

		// Construct and return the Guzzle proxy settings
		$proxySettings = [
			'http'  => $proxyUrl,
			'https' => $proxyUrl,
		];

		if (!empty($noProxy))
		{
			$proxySettings['no'] = $noProxy;
		}

		return $proxySettings;
	}
}