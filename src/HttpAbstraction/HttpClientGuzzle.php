<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Exception\CommunicationError;
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

		if (!is_resource($fp))
		{
			$fp = Psr7Utils::tryFopen($fp, 'w+');
		}

		/**
		 * Wrap the file pointer once, and keep the wrapper for as long as we are downloading.
		 *
		 * Handed a bare resource, Guzzle wraps it in a PSR-7 stream of its own for each request, and that wrapper
		 * closes the underlying resource when it is garbage collected — so the file pointer is dead by the time a
		 * second request would need it. Handed a stream, Guzzle uses it as it is. Holding the only reference to it
		 * here means it stays open across every hop of a redirect chain.
		 */
		$sink = Psr7Utils::streamFor($fp);
		$hops = 0;

		while (true)
		{
			/**
			 * getRequestOptions() adds the authentication headers. They are not optional here: the v3 API is
			 * authenticated by header alone, so a downloadDirect URL carries no credential of its own.
			 */
			$options = $this->getRequestOptions($headers);

			/**
			 * Follow redirects here rather than letting Guzzle's redirect middleware do it.
			 *
			 * A redirect response has a body of its own, and with a sink in play that body is written into the file
			 * we are downloading to. Someone has to throw it away before the next hop, or it ends up prepended to
			 * the archive — and the middleware gives us nowhere to do that. Doing the walking ourselves also means
			 * the download path enforces the redirect policy exactly the way the other two clients do.
			 */
			$options[RequestOptions::ALLOW_REDIRECTS] = false;
			$options[RequestOptions::SINK]            = $sink;

			$response = $this->getClient()->get($url, $options);
			$status   = $response->getStatusCode();

			if (!$this->isRedirectStatus($status) || !$response->hasHeader('Location'))
			{
				return;
			}

			if (++$hops > $this->getMaxRedirects())
			{
				throw new CommunicationError(
					$status,
					sprintf('The server sent us through more than %d redirects', $this->getMaxRedirects())
				);
			}

			$targetUrl = $this->resolveRedirectUrl($url, $response->getHeaderLine('Location'));

			if ($targetUrl === null)
			{
				return;
			}

			$this->assertRedirectAllowed($url, $targetUrl);

			/**
			 * The redirect response had a body of its own, and it has already gone into our file. Throw it away, or
			 * it would be prepended to the archive we are here for.
			 */
			ftruncate($fp, 0);
			$sink->rewind();

			$url = $targetUrl;
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
				'max'       => $this->getMaxRedirects(),
				'strict'    => true,
				'referer'   => true,
				'protocols' => ['http', 'https'],
				/**
				 * Vet every hop of a redirect chain before we walk it.
				 *
				 * Guzzle carries our request headers — including the v3 API's credential — across a redirect, and it
				 * does not care whose host it is redirected to. It calls this hook after working out the next request
				 * but before dispatching it, so throwing from here stops the credential from ever reaching a host we
				 * do not trust.
				 */
				'on_redirect' => function ($request, $response, $uri): void
				{
					$this->assertRedirectAllowed((string) $request->getUri(), (string) $uri);
				},
			],
			RequestOptions::CONNECT_TIMEOUT => $this->connectionTimeout,
			RequestOptions::HEADERS         => array_merge($this->getRequestHeaders(), $headers),
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