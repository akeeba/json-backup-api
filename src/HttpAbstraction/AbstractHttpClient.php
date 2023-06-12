<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Exception\ApiException;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\InvalidEncapsulatedJSON;
use Akeeba\BackupJsonApi\Exception\InvalidJSONBody;
use Akeeba\BackupJsonApi\Exception\InvalidSecretWord;
use Akeeba\BackupJsonApi\Exception\NotImplemented;
use Akeeba\BackupJsonApi\Exception\UnknownMethod;
use Akeeba\BackupJsonApi\Options;
use Akeeba\BackupJsonApi\Uri\Uri;
use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Throwable;

abstract class AbstractHttpClient implements HttpClientInterface
{
	use LoggerAwareTrait;

	public function __construct(
		protected Options $options,
	)
	{
		$this->applyOptions();
	}

	/** @inheritDoc */
	final public function doQuery(string $apiMethod, array $data = []): object
	{
		try
		{
			$raw = $this->getRawResponse($this->options->verb, $apiMethod, $data);
		}
		catch (ClientExceptionInterface $e)
		{
			throw new CommunicationError($e->getCode(), $e->getMessage(), previous: $e);
		}

		// Extract the encapsulated response (placed between ### markers) from whatever the server sent back to us.
		$encapsulatedResponse = $this->removeResponseJunk($raw);

		if ($this->options->verbose)
		{
			$this->logger->debug('<< Response: ' . PHP_EOL . $encapsulatedResponse);
		}

		// Expose the encapsulated data
		switch ($this->options->view ?? 'json')
		{
			case 'json':
				$apiResult = $this->exposeDataAPIv1($encapsulatedResponse ?? '');
				break;

			default:
			case 'api':
				$apiResult = $this->exposeDataAPIv2($encapsulatedResponse ?? '');
				break;
		}

		if ($apiResult->body->status !== 200)
		{
			$this->logger->notice(
				sprintf('Error status %d received from the API.', $apiResult->body->status)
			);
		}

		if ($apiResult->body->status === 405)
		{
			throw new UnknownMethod(
				sprintf('Server responded it does not know of API method %s. Is your installation broken or your Akeeba Backup / Solo version too old?', $apiMethod),
				127
			);
		}

		if ($apiResult->body->status === 501)
		{
			throw new NotImplemented($apiMethod);
		}

		if ($apiResult->body->status === 503)
		{
			throw new InvalidSecretWord();
		}

		return $apiResult;
	}

	/** @inheritDoc */
	final public function makeURL(string $apiMethod, array $data = [], ?string $verb = null): string
	{
		// Extract options. DO NOT REMOVE. empty() does NOT work on magic properties!
		$url         = rtrim($this->options->host, '/');
		$endpoint    = $this->options->endpoint;
		$verb        ??= $this->options->verb;
		$isWordPress = $this->options->isWordPress;

		if (!empty($endpoint))
		{
			$url .= '/' . $endpoint;
		}

		// For v2 URLs we need to add the authentication as a GET parameter
		$uri = new Uri($url);

		if ($this->options->view == 'api')
		{
			$uri->setVar('_akeebaAuth', $this->options->secret);
		}

		if ($isWordPress)
		{
			$uri->setVar('action', 'akeebabackup_api');
		}

		// If we're doing POST requests there's nothing more to do
		if ($verb == 'POST')
		{
			return $uri->toString();
		}

		// For GET requests we have to add the entire payload as query string parameters
		foreach ($this->getQueryStringParameters($apiMethod, $data) as $k => $v)
		{
			$uri->setVar($k, $v);
		}

		if ($isWordPress)
		{
			$uri->delVar('option');
			$uri->delVar('view');
			$uri->delVar('format');
		}

		return $uri->toString();
	}

	/** @inheritDoc */
	final public function getOptions(array $overrides = []): Options
	{
		return $this->options->getModifiedClone($overrides);
	}

	/**
	 * Sets the connector options
	 *
	 * @param   Options  $options
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	final public function setOptions(Options $options): void
	{
		$this->options = $options;

		$this->applyOptions();
	}

	/**
	 * Applies the connector options.
	 *
	 * Override in child classes if you need object-level changes every time new connector options are applied.
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	protected function applyOptions()
	{
		$this->setLogger($this->options->logger ?? new NullLogger());
	}

	/**
	 * Get the raw Akeeba Backup JSON API response (encapsulated string).
	 *
	 * Overridden by child classes to implement adapter-specific functionality.
	 *
	 * @param   string  $verb       The HTTP verb (GET or POST).
	 * @param   string  $apiMethod  The JSON API method to execute.
	 * @param   array   $data       Any data to provide to the JSON API.
	 *
	 * @return  string  The raw text reply. We assume it's a JSON reply mixed with junk.
	 * @throws  Throwable  Throw on HTTP error. The code is the HTTP status, the message is the error explanation.
	 * @since   1.0.0
	 */
	abstract protected function getRawResponse(string $verb, string $apiMethod, array $data = []): string;

	/**
	 * Apply the necessary query string parameters for an API call
	 *
	 * @param   string  $apiMethod  The API method we will be executing
	 * @param   array   $data       Any data we are sending to the API
	 *
	 * @return  array  The query string parameters you will need.
	 * @since   1.0.0
	 */
	final protected function getQueryStringParameters(string $apiMethod, array $data = []): array
	{
		switch ($this->options->view ?? 'json')
		{
			// API v1
			case 'json':
			default:
				$params = [
					'view' => 'json',
					'json' => $this->encapsulateData($apiMethod, $data),
				];
				break;

			// API v2
			case 'api':
				$params = array_merge($data, ['view' => 'Api', 'method' => $apiMethod]);
				break;
		}

		// DO NOT REMOVE. empty() does NOT work on magic properties!
		$component = $this->options->component;
		$format    = $this->options->format;

		if (!empty($component))
		{
			$params['option'] = $component;
		}

		if (!empty($format))
		{
			$params['format'] = $format;

			/**
			 * If it's Joomla! we have to set tmpl=component to avoid template interference if the format is set to
			 * 'html' on an empty string (which is equivalent to 'html' as it's the default).
			 */
			if (($format == 'html') && !empty($component))
			{
				$params['tmpl'] = 'component';
			}
		}

		return $params;
	}

	/**
	 * Encapsulates data for API v1.
	 *
	 * @param   string  $apiMethod  The API method we are calling.
	 * @param   array   $data       The data we are sending to the API.
	 *
	 * @return  string  The encapsulated string.
	 *
	 * @since       1.0.0
	 * @deprecated  APIv1 is deprecated since December 2019
	 */
	private function encapsulateData(string $apiMethod, array $data): string
	{
		$body = [
			'method' => $apiMethod,
			'data'   => $data,
		];

		$salt              = $this->randomString();
		$challenge         = $salt . ':' . md5($salt . $this->options->secret);
		$body['challenge'] = $challenge;

		$bodyData = json_encode($body);

		$jsonSource = [
			'encapsulation' => 1,
			'body'          => $bodyData,
		];

		return json_encode($jsonSource);
	}

	/**
	 * Unwraps the encapsulated API v1 data.
	 *
	 * @param   string  $encapsulated  The encapsulated string
	 *
	 * @return  object  The object extracted from the encapsulated JSON data.
	 *
	 * @since       1.0.0
	 * @deprecated  APIv1 is deprecated since December 2019
	 */
	private function unwrapAPIv1Data(string $encapsulated): object
	{
		$result = json_decode($encapsulated, false);

		if (is_null($result) || !property_exists($result, 'body') || !property_exists($result->body, 'data'))
		{
			throw new InvalidEncapsulatedJSON($encapsulated);
		}

		return $result;
	}

	/**
	 * Tries to find the JSON response of the API within any junk potentially returned by the server.
	 *
	 * This is especially important talking to WordPress sites. They tend to have display_errors set to 1 in their PHP
	 * configuration, with an error reporting level that is way too verbose.
	 *
	 * @param   string  $raw  The raw response string, assumed to contain loads of junk.
	 *
	 * @return  string|null  The (hopefully) cleaned-up data, null if we could not clean it.
	 * @since   1.0.0
	 */
	private function removeResponseJunk(string $raw): ?string
	{
		// Older implementations put the response between triple hashes. Try that first.
		$startPos = strpos($raw, '###');
		$endPos   = strrpos($raw, '###');

		if (($startPos !== false) && ($endPos !== false))
		{
			return substr($raw, $startPos + 3, $endPos - $startPos - 3);
		}

		// Newer implementations don't use triple hashes. Try to figure out what to do instead.
		try
		{
			$test = @json_decode($raw);

			if ($test !== null)
			{
				return $raw;
			}
		}
		catch (Exception $e)
		{
			// No worries
		}

		// Remove obvious garbage
		$openBrace  = strpos($raw, '{');
		$closeBrace = strrpos($raw, '}');

		if ($openBrace === false || $closeBrace === false)
		{
			return null;
		}

		$raw   = substr($raw, $openBrace, $closeBrace);
		$tries = 0;

		do
		{
			$tries++;

			if (empty($raw) || $tries > 1000)
			{
				break;
			}

			try
			{
				$test = @json_decode($raw);
			}
			catch (Exception $e)
			{
				// No worries
			}

			if ($test !== null)
			{
				return $raw;
			}

			$openBrace = strpos($raw, '{', 1);

			if ($openBrace === false)
			{
				break;
			}

			$raw = substr($raw, $openBrace);
		} while (true);

		return null;
	}

	/**
	 * Create a 32-character random string
	 *
	 * @return  string
	 * @throws  Exception
	 * @since   1.0.0
	 */
	private function randomString(): string
	{
		$sourceString = str_split('abcdefghijklmnopqrstuvwxyz-ABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789');
		$ret          = '';

		$bytes     = ceil(32 / 4) * 3;
		$randBytes = random_bytes($bytes);

		for ($i = 0; $i < $bytes; $i += 3)
		{
			$subBytes = substr($randBytes, $i, 3);
			$subBytes = str_split($subBytes);
			$subBytes = ord($subBytes[0]) * 65536 + ord($subBytes[1]) * 256 + ord($subBytes[2]);
			$subBytes = $subBytes & bindec('00000000111111111111111111111111');

			$b    = [];
			$b[0] = $subBytes >> 18;
			$b[1] = ($subBytes >> 12) & bindec('111111');
			$b[2] = ($subBytes >> 6) & bindec('111111');
			$b[3] = $subBytes & bindec('111111');

			$ret .= $sourceString[$b[0]] . $sourceString[$b[1]] . $sourceString[$b[2]] . $sourceString[$b[3]];
		}

		return substr($ret, 0, 32);
	}

	/**
	 * Extracts the data encapsulated in an API v1 response.
	 *
	 * @param   string  $encapsulatedResponse  The JSON data to parse.
	 *
	 * @return  object
	 * @since   1.0.0
	 */
	private function exposeDataAPIv1(string $encapsulatedResponse): object
	{
		// Legacy v1 API: unwrap the data
		$result = $this->unwrapAPIv1Data($encapsulatedResponse);

		if ($this->options->verbose)
		{
			$this->logger->debug('Parsed Response: ' . PHP_EOL . print_r($result, true));
		}

		// Decode the JSON encoded body
		try
		{
			$result->body->data = @json_decode($result->body->data, false);
		}
		catch (Exception $e)
		{
			$result->body->data = null;
		}

		if ($result->body->data === null)
		{
			throw new InvalidJSONBody();
		}

		return $result;
	}

	/**
	 * Extracts the data encapsulated in an API v2 response.
	 *
	 * Technically, there is no encapsulation in v2, but we transform the data in a way that gives it a similar shape to
	 * v1 data, simplifying our code.
	 *
	 * @param   string  $encapsulatedResponse  The JSON data to parse.
	 *
	 * @return  object
	 * @since   1.0.0
	 */
	private function exposeDataAPIv2(string $encapsulatedResponse): object
	{
		// JSON API v2: Get the JSON data and construct a result similar to what was returned by v1
		$result = json_decode($encapsulatedResponse, false);

		if (is_null($result) || !property_exists($result, 'status') || !property_exists($result, 'data'))
		{
			throw new InvalidEncapsulatedJSON($encapsulatedResponse);
		}

		if ($this->options->verbose)
		{
			$this->logger->debug('Parsed Response: ' . print_r($result, true));
		}

		return (object) [
			'body' => $result,
		];
	}
}