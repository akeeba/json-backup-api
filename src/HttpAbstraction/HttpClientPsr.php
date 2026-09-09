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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

		/**
		 * The authentication headers are not optional here: the v3 API is authenticated by header alone, so a
		 * downloadDirect URL carries no credential of its own.
		 */
		$request = $this->applyHeaders($this->requestFactory->createRequest('GET', $url));

		if (!empty($from) || !empty($to))
		{
			$request = $request->withHeader('Range', sprintf('bytes=%d=%d', $from, $to));
		}

		$response = $this->sendFollowingRedirects($request);

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
			/**
			 * The mode is not optional. PSR-17 defines createStreamFromFile() to default to 'r', so leaving it out
			 * opens the download target read-only and the write below throws.
			 */
			$fileStream = $this->streamFactory->createStreamFromFile($fp, 'w+');
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
				->withBody($this->streamFactory->createStream($payload))
				->withHeader('Content-Type', 'application/x-www-form-urlencoded');
		}
		else
		{
			$request = $this->requestFactory
				->createRequest('GET', $url);
		}

		$request = $this->applyHeaders($request);

		$response = $this->sendFollowingRedirects($request);

		if ($response->getStatusCode() < 200 || $response->getStatusCode() > 200)
		{
			throw new CommunicationError(
				$response->getStatusCode(),
				sprintf('Unexpected HTTP status %d', $response->getStatusCode())
			);
		}

		return (string) $response->getBody();
	}

	/**
	 * Sends a request, following any redirects which do not leave the caller's domain.
	 *
	 * A PSR-18 client is not obliged to follow redirects, and the common ones do not: Guzzle's PSR-18 entry point
	 * explicitly disables its own redirect middleware. Since a Joomla! site will redirect an API URL for perfectly
	 * ordinary reasons — a SEF language prefix, www to non-www, HTTP to HTTPS — we have to follow them ourselves, and
	 * doing it ourselves is also what lets us apply the same domain check as the other clients.
	 *
	 * @param   RequestInterface  $request  The request to send
	 *
	 * @return  ResponseInterface  The first non-redirect response
	 * @since   1.1.0
	 */
	private function sendFollowingRedirects(RequestInterface $request): ResponseInterface
	{
		$hops = 0;

		while (true)
		{
			$response = $this->http->sendRequest($request);
			$status   = $response->getStatusCode();

			if (!$this->isRedirectStatus($status) || !$response->hasHeader('Location'))
			{
				return $response;
			}

			if (++$hops > $this->getMaxRedirects())
			{
				throw new CommunicationError(
					$status,
					sprintf('The server sent us through more than %d redirects', $this->getMaxRedirects())
				);
			}

			$currentUrl = (string) $request->getUri();
			$targetUrl  = $this->resolveRedirectUrl($currentUrl, $response->getHeaderLine('Location'));

			// A redirect we cannot make sense of. Hand it back and let the caller report the odd status.
			if ($targetUrl === null)
			{
				return $response;
			}

			$this->assertRedirectAllowed($currentUrl, $targetUrl);

			$request = $this->getRedirectedRequest($request, $status, $targetUrl);
		}
	}

	/**
	 * Builds the follow-up request for a redirect.
	 *
	 * The method is preserved, which is the RFC-compliant behaviour and matches the `strict` redirect mode the Guzzle
	 * client has always been configured with. Only a 303 turns into a GET, as it is defined to.
	 *
	 * @param   RequestInterface  $request    The request which was redirected
	 * @param   int               $status     The redirect status we received
	 * @param   string            $targetUrl  The absolute URL to go to
	 *
	 * @return  RequestInterface
	 * @since   1.1.0
	 */
	private function getRedirectedRequest(RequestInterface $request, int $status, string $targetUrl): RequestInterface
	{
		$method   = $request->getMethod();
		$toGet    = $status === 303 && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
		$redirect = $this->requestFactory->createRequest($toGet ? 'GET' : $method, $targetUrl);

		foreach ($request->getHeaders() as $name => $values)
		{
			/**
			 * Host is derived from the URI, and the request factory has already set it for the new one. Copying the
			 * old value over would send the previous host's name to the new one.
			 */
			if (strtolower($name) === 'host')
			{
				continue;
			}

			// A GET has no body, so the headers describing one would be a lie.
			if ($toGet && in_array(strtolower($name), ['content-type', 'content-length', 'transfer-encoding'], true))
			{
				continue;
			}

			$redirect = $redirect->withHeader($name, $values);
		}

		if (!$toGet)
		{
			$body = $request->getBody();

			if ($body->isSeekable())
			{
				$body->rewind();
			}

			$redirect = $redirect->withBody($body);
		}

		return $redirect;
	}

	/**
	 * Applies the API request headers to a PSR-7 request.
	 *
	 * PSR-7 requests are immutable: withHeader() returns a *new* request rather than modifying the one it was called
	 * on. Discarding that return value — which is easily done, since the call looks like a setter — silently drops the
	 * header, and with it the v3 API's only credential.
	 *
	 * @param   RequestInterface  $request  The request to apply the headers to
	 *
	 * @return  RequestInterface  A new request, carrying the headers
	 * @since   1.1.0
	 */
	private function applyHeaders(RequestInterface $request): RequestInterface
	{
		foreach ($this->getRequestHeaders() as $name => $value)
		{
			$request = $request->withHeader($name, $value);
		}

		return $request;
	}
}
