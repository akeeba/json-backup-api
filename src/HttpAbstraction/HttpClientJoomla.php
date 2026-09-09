<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Exception\CommunicationError;
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

		/**
		 * The authentication headers are not optional here: the v3 API is authenticated by header alone, so a
		 * downloadDirect URL carries no credential of its own.
		 */
		$headers = $this->getRequestHeaders();

		if (!empty($from) || !empty($to))
		{
			$headers['Range'] = sprintf('bytes=%d=%d', $from, $to);
		}

		$hops = 0;

		while (true)
		{
			$rawHeaders = [];

			$http = (new HttpFactory())->getHttp(
				[
					'curl.certpath'   => $this->options->capath,
					'userAgent'       => $this->options->ua,
					'follow_location' => false,
					'transport.curl'  => [
						CURLOPT_AUTOREFERER    => 1,
						CURLOPT_FAILONERROR    => true,
						CURLOPT_RETURNTRANSFER => false,
						CURLOPT_HEADER         => false,
						CURLOPT_FILE           => $fp,
						CURLOPT_FOLLOWLOCATION => false,
						/**
						 * Collect the response headers ourselves.
						 *
						 * This request streams its body straight into a file — an archive part can be hundreds of
						 * megabytes, so buffering it is not an option — which means CURLOPT_RETURNTRANSFER is off and
						 * the Joomla Framework transport has nothing to parse. It gives up with a "No HTTP response
						 * received" exception, swallowed below, and hands us no response object at all. Capturing the
						 * headers as cURL reads them is therefore the only way to see the status and the Location of a
						 * redirect while still streaming the body.
						 */
						CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$rawHeaders) {
							$rawHeaders[] = $line;

							return strlen($line);
						},
					],
				],
				['curl']
			);

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

			[$status, $location] = $this->parseRawHeaders($rawHeaders);

			if ($status === null || !$this->isRedirectStatus($status) || $location === null)
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

			$targetUrl = $this->resolveRedirectUrl($url, $location);

			if ($targetUrl === null)
			{
				return;
			}

			$this->assertRedirectAllowed($url, $targetUrl);

			/**
			 * The redirect response had a body of its own — usually a short courtesy page — and cURL has already
			 * written it into our file. Throw it away, or it would be prepended to the archive we are here for.
			 */
			ftruncate($fp, 0);
			rewind($fp);

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

		$headers = $this->getRequestHeaders();
		$payload = $verb == 'POST'
			? http_build_query($this->getQueryStringParameters($apiMethod, $data))
			: null;

		$hops = 0;

		while (true)
		{
			$response = $payload === null
				? $this->http->get($url, $headers)
				: $this->http->post($url, $payload, $headers);

			$status = $this->getResponseStatus($response);

			if (!$this->isRedirectStatus($status))
			{
				return $this->getResponseBody($response);
			}

			$location = $this->getResponseHeader($response, 'Location');

			// A redirect with nowhere to go. Hand the body back and let the caller make of it what it will.
			if ($location === null || trim($location) === '')
			{
				return $this->getResponseBody($response);
			}

			if (++$hops > $this->getMaxRedirects())
			{
				throw new CommunicationError(
					$status,
					sprintf('The server sent us through more than %d redirects', $this->getMaxRedirects())
				);
			}

			$targetUrl = $this->resolveRedirectUrl($url, $location);

			if ($targetUrl === null)
			{
				return $this->getResponseBody($response);
			}

			$this->assertRedirectAllowed($url, $targetUrl);

			// A 303 is defined to turn the follow-up request into a bodiless GET.
			if ($status === 303)
			{
				$payload = null;
			}

			$url = $targetUrl;
		}
	}

	/**
	 * Extracts the HTTP status from a Joomla Framework HTTP response.
	 *
	 * @param   object  $response  The response returned by the Joomla Framework HTTP client
	 *
	 * @return  int
	 * @since   1.1.0
	 */
	private function getResponseStatus(object $response): int
	{
		if ($response instanceof ResponseInterface)
		{
			return $response->getStatusCode();
		}

		return (int) ($response->code ?? 0);
	}

	/**
	 * Extracts a single response header from a Joomla Framework HTTP response.
	 *
	 * @param   object  $response  The response returned by the Joomla Framework HTTP client
	 * @param   string  $name      The header to read, case-insensitively
	 *
	 * @return  string|null  NULL when the response does not carry the header
	 * @since   1.1.0
	 */
	private function getResponseHeader(object $response, string $name): ?string
	{
		if ($response instanceof ResponseInterface)
		{
			return $response->hasHeader($name) ? $response->getHeaderLine($name) : null;
		}

		foreach ((array) ($response->headers ?? []) as $header => $value)
		{
			if (strtolower((string) $header) !== strtolower($name))
			{
				continue;
			}

			// A repeated header arrives as an array. Only the first value can be meant.
			return is_array($value) ? (string) reset($value) : (string) $value;
		}

		return null;
	}

	/**
	 * Pulls the status and the Location out of raw response header lines, as cURL handed them to us.
	 *
	 * @param   string[]  $lines  The raw header lines, in the order they were received
	 *
	 * @return  array  [int|null $status, string|null $location]
	 * @since   1.1.0
	 */
	private function parseRawHeaders(array $lines): array
	{
		$status   = null;
		$location = null;

		foreach ($lines as $line)
		{
			$line = trim($line);

			if ($line === '')
			{
				continue;
			}

			/**
			 * A status line starts a new response, and there can be more than one — an informational 1xx, or a
			 * "100 Continue". Reset what we gathered for the previous one so we end up describing the last.
			 */
			if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $matches))
			{
				$status   = (int) $matches[1];
				$location = null;

				continue;
			}

			if (preg_match('#^Location:\s*(.+)$#i', $line, $matches))
			{
				$location = trim($matches[1]);
			}
		}

		return [$status, $location];
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

		/**
		 * Redirects are followed by getRawResponse(), not by cURL.
		 *
		 * cURL offers no way to veto an individual hop, and every request carries a credential which it would happily
		 * forward to whatever host the redirect names. Following the chain ourselves is what lets us check each hop
		 * against the domain the caller gave us.
		 */
		$this->http = (new HttpFactory())->getHttp(
			[
				'curl.certpath'   => $this->options->capath,
				'userAgent'       => $this->options->ua,
				'follow_location' => false,
			],
			['curl']
		);
	}


}