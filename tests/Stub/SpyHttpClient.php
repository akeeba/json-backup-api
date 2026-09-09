<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\Tests\Stub;

use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Akeeba\BackupJsonApi\Options;
use LogicException;
use Throwable;

/**
 * An HTTP client which talks to nobody.
 *
 * It answers each doQuery() from a queue the test set up beforehand, and records what it was asked, so a test of the
 * high level operations can assert on the conversation without a server anywhere in sight.
 *
 * @since 1.1.0
 */
class SpyHttpClient implements HttpClientInterface
{
	/**
	 * The API calls made so far, as ['method' => string, 'data' => array].
	 *
	 * @var   array[]
	 * @since 1.1.0
	 */
	public array $calls = [];

	/**
	 * The files downloadToFile() was asked for, as ['url' => string, 'from' => int, 'to' => int].
	 *
	 * @var   array[]
	 * @since 1.1.0
	 */
	public array $downloads = [];

	/**
	 * The answers to give, in order. An entry which is a Throwable is thrown instead of returned.
	 *
	 * @var   array
	 * @since 1.1.0
	 */
	private array $responses = [];

	/**
	 * The bytes downloadToFile() writes into the file it is given, in order.
	 *
	 * @var   string[]
	 * @since 1.1.0
	 */
	private array $downloadPayloads = [];

	public function __construct(private Options $options)
	{
	}

	/**
	 * Queue an API answer.
	 *
	 * @param   int    $status  The API status to report
	 * @param   mixed  $data    The data member of the response
	 *
	 * @return  $this
	 * @since   1.1.0
	 */
	public function willAnswer(int $status, mixed $data = null): static
	{
		$this->responses[] = (object) [
			'body' => (object) [
				'status' => $status,
				'data'   => $data,
			],
		];

		return $this;
	}

	/**
	 * Queue a raw answer object, for the cases where the response shape itself is what is under test.
	 *
	 * @param   object  $response  The object doQuery() should return
	 *
	 * @return  $this
	 * @since   1.1.0
	 */
	public function willAnswerWith(object $response): static
	{
		$this->responses[] = $response;

		return $this;
	}

	/**
	 * Queue an exception for doQuery() to throw.
	 *
	 * @param   Throwable  $exception  The exception to throw
	 *
	 * @return  $this
	 * @since   1.1.0
	 */
	public function willThrow(Throwable $exception): static
	{
		$this->responses[] = $exception;

		return $this;
	}

	/**
	 * Queue the bytes the next downloadToFile() call should write.
	 *
	 * @param   string  $payload  The bytes to write
	 *
	 * @return  $this
	 * @since   1.1.0
	 */
	public function willDownload(string $payload): static
	{
		$this->downloadPayloads[] = $payload;

		return $this;
	}

	/** @inheritDoc */
	public function doQuery(string $apiMethod, array $data = []): object
	{
		$this->calls[] = [
			'method'  => $apiMethod,
			'data'    => $data,
			// A snapshot of the options in force for this call. Autodetect varies them from one call to the next.
			'options' => $this->options,
		];

		if (empty($this->responses))
		{
			throw new LogicException(
				sprintf('The test did not queue an answer for the API call to ‘%s’.', $apiMethod)
			);
		}

		$response = array_shift($this->responses);

		if ($response instanceof Throwable)
		{
			throw $response;
		}

		return $response;
	}

	/** @inheritDoc */
	public function makeURL(string $apiMethod, array $data = [], ?string $verb = null): string
	{
		return rtrim($this->options->host, '/') . '/' . $apiMethod . '?' . http_build_query($data);
	}

	/** @inheritDoc */
	public function getOptions(array $overrides = []): Options
	{
		return $this->options->getModifiedClone($overrides);
	}

	/** @inheritDoc */
	public function setOptions(Options $options): void
	{
		$this->options = $options;
	}

	/** @inheritDoc */
	public function downloadToFile(string $url, mixed $fp, int $from = 0, int $to = 0): void
	{
		$this->downloads[] = [
			'url'  => $url,
			'from' => $from,
			'to'   => $to,
		];

		$payload = array_shift($this->downloadPayloads) ?? '';

		if (is_resource($fp))
		{
			fwrite($fp, $payload);

			return;
		}

		file_put_contents($fp, $payload);
	}

	/**
	 * The options in force for each API call made so far, in order.
	 *
	 * @return  Options[]
	 * @since   1.1.0
	 */
	public function calledWithOptions(): array
	{
		return array_column($this->calls, 'options');
	}

	/**
	 * Describes each API call's options by the given option names, in order.
	 *
	 * @param   string[]  $properties  The options to read off each call
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	public function calledWithOptionValues(array $properties): array
	{
		return array_map(
			fn(Options $options) => array_combine(
				$properties,
				array_map(fn(string $property) => $options->{$property}, $properties)
			),
			$this->calledWithOptions()
		);
	}

	/**
	 * The API methods called so far, in order.
	 *
	 * @return  string[]
	 * @since   1.1.0
	 */
	public function calledMethods(): array
	{
		return array_column($this->calls, 'method');
	}
}
