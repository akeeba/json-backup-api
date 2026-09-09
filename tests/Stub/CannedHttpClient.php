<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Stub;

use Akeeba\BackupJsonApi\Exception\UnsafeRedirect;
use Akeeba\BackupJsonApi\HttpAbstraction\AbstractHttpClient;
use LogicException;
use Throwable;

/**
 * A concrete AbstractHttpClient which never opens a socket.
 *
 * Everything AbstractHttpClient does other than moving bytes — building a URL, choosing the request headers, deciding
 * whether a redirect may be followed, making sense of a response — is testable in isolation as long as something
 * supplies the raw response. That is all this does, plus expose the protected helpers so a test can call them directly.
 *
 * @since 1.1.0
 */
class CannedHttpClient extends AbstractHttpClient
{
	/**
	 * The requests made so far, as ['verb' => string, 'method' => string, 'data' => array, 'url' => string].
	 *
	 * @var   array[]
	 * @since 1.1.0
	 */
	public array $requests = [];

	/**
	 * The raw responses to hand back, in order. A Throwable entry is thrown instead.
	 *
	 * @var   array
	 * @since 1.1.0
	 */
	private array $rawResponses = [];

	/**
	 * Queue a raw response, exactly as a server would have sent it.
	 *
	 * @param   string  $raw  The raw response body
	 *
	 * @return  $this
	 * @since   1.1.0
	 */
	public function willRespond(string $raw): static
	{
		$this->rawResponses[] = $raw;

		return $this;
	}

	/**
	 * Queue an exception for the transport to throw.
	 *
	 * @param   Throwable  $exception  The exception to throw
	 *
	 * @return  $this
	 * @since   1.1.0
	 */
	public function willThrow(Throwable $exception): static
	{
		$this->rawResponses[] = $exception;

		return $this;
	}

	/** @inheritDoc */
	public function downloadToFile(string $url, mixed $fp, int $from = 0, int $to = 0): void
	{
		throw new LogicException('CannedHttpClient cannot download anything.');
	}

	/**
	 * @see AbstractHttpClient::getApiVersion()
	 * @since 1.1.0
	 */
	public function exposedApiVersion(): int
	{
		return $this->getApiVersion();
	}

	/**
	 * @see AbstractHttpClient::getRequestHeaders()
	 * @since 1.1.0
	 */
	public function exposedRequestHeaders(): array
	{
		return $this->getRequestHeaders();
	}

	/**
	 * @see AbstractHttpClient::getQueryStringParameters()
	 * @since 1.1.0
	 */
	public function exposedQueryStringParameters(string $apiMethod, array $data = []): array
	{
		return $this->getQueryStringParameters($apiMethod, $data);
	}

	/**
	 * @see AbstractHttpClient::isRedirectAllowed()
	 * @since 1.1.0
	 */
	public function exposedIsRedirectAllowed(string $targetUrl): bool
	{
		return $this->isRedirectAllowed($targetUrl);
	}

	/**
	 * @see AbstractHttpClient::assertRedirectAllowed()
	 * @throws UnsafeRedirect
	 * @since  1.1.0
	 */
	public function exposedAssertRedirectAllowed(string $fromUrl, string $targetUrl): void
	{
		$this->assertRedirectAllowed($fromUrl, $targetUrl);
	}

	/**
	 * @see AbstractHttpClient::resolveRedirectUrl()
	 * @since 1.1.0
	 */
	public function exposedResolveRedirectUrl(string $currentUrl, string $location): ?string
	{
		return $this->resolveRedirectUrl($currentUrl, $location);
	}

	/**
	 * @see AbstractHttpClient::isRedirectStatus()
	 * @since 1.1.0
	 */
	public function exposedIsRedirectStatus(int $status): bool
	{
		return $this->isRedirectStatus($status);
	}

	/**
	 * @see AbstractHttpClient::getMaxRedirects()
	 * @since 1.1.0
	 */
	public function exposedMaxRedirects(): int
	{
		return $this->getMaxRedirects();
	}

	/** @inheritDoc */
	protected function getRawResponse(string $verb, string $apiMethod, array $data = []): string
	{
		$this->requests[] = [
			'verb'   => $verb,
			'method' => $apiMethod,
			'data'   => $data,
			'url'    => $this->makeURL($apiMethod, $data),
		];

		if (empty($this->rawResponses))
		{
			throw new LogicException(
				sprintf('The test did not queue a raw response for the API call to ‘%s’.', $apiMethod)
			);
		}

		$response = array_shift($this->rawResponses);

		if ($response instanceof Throwable)
		{
			throw $response;
		}

		return $response;
	}
}
