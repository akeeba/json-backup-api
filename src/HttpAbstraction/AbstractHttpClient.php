<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HttpAbstraction;

use Akeeba\BackupJsonApi\Exception\ApiException;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\InvalidEncapsulatedJSON;
use Akeeba\BackupJsonApi\Exception\InvalidJSONBody;
use Akeeba\BackupJsonApi\Exception\InvalidSecretWord;
use Akeeba\BackupJsonApi\Exception\NotAuthorised;
use Akeeba\BackupJsonApi\Exception\NotImplemented;
use Akeeba\BackupJsonApi\Exception\UnknownMethod;
use Akeeba\BackupJsonApi\Exception\UnsafeRedirect;
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

		// Expose the encapsulated data. Only the v1 API encapsulates its response; v2 and v3 share a response shape.
		$apiResult = $this->getApiVersion() === 1
			? $this->exposeDataAPIv1($encapsulatedResponse ?? '')
			: $this->exposeDataModernApi($encapsulatedResponse ?? '');

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

		/**
		 * We are authenticated, but not authorised for this method.
		 *
		 * Only the v3 API, authenticating with a Joomla! API token, can answer this. It is deliberately distinct from
		 * the 503 above: 503 means we never established who we are, 403 means we did and we may not do this.
		 */
		if ($apiResult->body->status === 403)
		{
			throw new NotAuthorised($apiMethod);
		}

		return $apiResult;
	}

	/** @inheritDoc */
	final public function makeURL(string $apiMethod, array $data = [], ?string $verb = null): string
	{
		$verb ??= $this->options->verb;

		if ($this->getApiVersion() === 3)
		{
			return $this->makeURLv3($apiMethod, $data, $verb);
		}

		// Extract options. DO NOT REMOVE. empty() does NOT work on magic properties!
		$url         = rtrim($this->options->host, '/');
		$endpoint    = $this->options->endpoint;
		$isWordPress = $this->options->isWordPress;

		if (!empty($endpoint))
		{
			$url .= '/' . $endpoint;
		}

		// For v2 URLs we need to add the authentication as a GET parameter
		$uri = new Uri($url);

		if ($this->getApiVersion() === 2)
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

	/**
	 * Create a JSON API v3 URL.
	 *
	 * The v3 API is a route in Joomla's API application, not a view in the site's frontend. The API method is a path
	 * segment of the route, which is why neither the method nor the option, view, format, and tmpl parameters the
	 * older versions need appear anywhere in the URL.
	 *
	 * Note what is conspicuously absent: the credential. The v3 API is authenticated with request headers — see
	 * getRequestHeaders() — so a Secret Word or an API token never ends up in a URL, and therefore never ends up in
	 * the server's access log, a proxy's cache, or a browser's history.
	 *
	 * @param   string  $apiMethod  The API method to execute on the remote server.
	 * @param   array   $data       Any data to send to the remote server.
	 * @param   string  $verb       The HTTP verb the request will be made with.
	 *
	 * @return  string
	 * @since   1.1.0
	 */
	private function makeURLv3(string $apiMethod, array $data, string $verb): string
	{
		// DO NOT REMOVE the local variables. empty() does NOT work on magic properties!
		$host        = rtrim($this->options->host, '/');
		$apiEndpoint = trim($this->options->apiEndpoint ?: Options::DEFAULT_API_ENDPOINT, '/');

		$uri = new Uri($host . '/' . $apiEndpoint . '/v3/akeebabackup/' . rawurlencode($apiMethod));

		// POST requests carry their payload in the request body; there is nothing more to add to the URL.
		if ($verb == 'POST')
		{
			return $uri->toString();
		}

		foreach ($this->getQueryStringParameters($apiMethod, $data) as $k => $v)
		{
			$uri->setVar($k, $v);
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
		/**
		 * The v3 API takes the payload and nothing else: the API method is a path segment of its route, and none of
		 * the Joomla! frontend parameters below mean anything to Joomla's API application. In fact the webservices
		 * plugin actively strips option, view, format, and tmpl from a v2 request, because their presence confuses
		 * the API application's router — so do not be tempted to send them "just in case".
		 */
		if ($this->getApiVersion() === 3)
		{
			return $data;
		}

		switch ($this->getApiVersion())
		{
			// API v1
			case 1:
			default:
				$params = [
					'view' => 'json',
					'json' => $this->encapsulateData($apiMethod, $data),
				];
				break;

			// API v2
			case 2:
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
	 * How many redirects we are willing to follow before giving up.
	 *
	 * @return  int
	 * @since   1.1.0
	 */
	final protected function getMaxRedirects(): int
	{
		return 20;
	}

	/**
	 * Is this HTTP status a redirect we should follow?
	 *
	 * @param   int  $status  The HTTP status of the response
	 *
	 * @return  bool
	 * @since   1.1.0
	 */
	final protected function isRedirectStatus(int $status): bool
	{
		return in_array($status, [301, 302, 303, 307, 308], true);
	}

	/**
	 * May we follow a redirect to this URL?
	 *
	 * Redirects have to be followed. A Joomla! site will redirect an API URL for entirely mundane reasons — adding the
	 * language prefix its SEF configuration calls for, moving between www and non-www, upgrading HTTP to HTTPS — and
	 * refusing to follow those would make the library unusable on a large share of real sites.
	 *
	 * They cannot be followed blindly, though. Each request carries a credential, in a header on the v3 API and in the
	 * query string on v2, and the HTTP clients forward those along the chain. A redirect to a host outside the domain
	 * the caller gave us would therefore disclose that credential to a third party — which is what an open redirect on
	 * the site, or a hostile site, would exploit.
	 *
	 * The rule is that a redirect may not leave the domain the caller gave us. Concretely, the target must be either:
	 *
	 * 1. the caller's host with any leading `www.` removed — the “anchor” — or anything beneath it. This is what allows
	 *    www.example.com to redirect to example.com, to foobar.example.com, or to itself; or
	 * 2. an ancestor of the caller's host, which is what allows api.example.com to redirect to example.com.
	 *
	 * Both comparisons are made on label boundaries, so example.com cannot be matched by notexample.com, and neither
	 * clause can reach a *sibling* of an ancestor. That last point is what makes this safe without consulting a public
	 * suffix list: with a caller host of www.example.co.uk the anchor is example.co.uk, so evil.co.uk satisfies neither
	 * clause, even though it shares the co.uk suffix. A naive “compare the last two labels” rule would have allowed it.
	 *
	 * The one thing this does not allow is a redirect sideways from a host which is neither the anchor nor beneath it —
	 * api.example.com to shop.example.com, say. Identifying those as related needs a public suffix list to do safely,
	 * and this library is deliberately free of that dependency, so it fails closed.
	 *
	 * @param   string  $targetUrl  The URL the server wants us to go to
	 *
	 * @return  bool
	 * @since   1.1.0
	 */
	final protected function isRedirectAllowed(string $targetUrl): bool
	{
		/**
		 * A URL we cannot even parse is a URL we will not follow. Uri throws on a malformed one, and a server which is
		 * redirecting us somewhere we should not go is exactly the kind of server which would send us a malformed
		 * Location header — so this has to be a refusal, not an exception of an unrelated type escaping to the caller.
		 */
		try
		{
			$target = new Uri($targetUrl);
			$origin = new Uri($this->options->host);
		}
		catch (Throwable)
		{
			return false;
		}

		$targetScheme = strtolower((string) ($target->scheme ?? ''));
		$originScheme = strtolower((string) ($origin->scheme ?? ''));

		// We only speak HTTP and HTTPS. Anything else is not a redirect we could follow even if we wanted to.
		if (!in_array($targetScheme, ['http', 'https'], true))
		{
			return false;
		}

		/**
		 * Never let an HTTPS connection be downgraded to plaintext HTTP. The credential travels with every request, so
		 * a downgrade puts it on the wire in the clear — and a downgrade is exactly what an attacker in a position to
		 * rewrite the response would ask for.
		 */
		if ($originScheme === 'https' && $targetScheme !== 'https')
		{
			return false;
		}

		$targetHost = $this->normaliseHost((string) ($target->host ?? ''));
		$originHost = $this->normaliseHost((string) ($origin->host ?? ''));

		if ($targetHost === '' || $originHost === '')
		{
			return false;
		}

		/**
		 * An IP address has no domain hierarchy to reason about: 1.2.3.4 is not "beneath" 2.3.4 in any sense. Require
		 * an exact match whenever either end is a literal address, which also stops a hostname from being matched
		 * against an address or the other way round.
		 */
		if ($this->isIpAddress($targetHost) || $this->isIpAddress($originHost))
		{
			return $targetHost === $originHost;
		}

		$anchor = str_starts_with($originHost, 'www.') ? substr($originHost, 4) : $originHost;

		// The anchor itself, or anything beneath it
		if ($targetHost === $anchor || str_ends_with($targetHost, '.' . $anchor))
		{
			return true;
		}

		// An ancestor of the caller's host
		return str_ends_with($originHost, '.' . $targetHost);
	}

	/**
	 * Throws unless we may follow a redirect to this URL.
	 *
	 * @param   string  $fromUrl    The URL which issued the redirect
	 * @param   string  $targetUrl  The URL the server wants us to go to
	 *
	 * @return  void
	 * @throws  UnsafeRedirect
	 * @since   1.1.0
	 */
	final protected function assertRedirectAllowed(string $fromUrl, string $targetUrl): void
	{
		if ($this->isRedirectAllowed($targetUrl))
		{
			$this->logger->debug(sprintf('Following a redirect from %s to %s', $fromUrl, $targetUrl));

			return;
		}

		$this->logger->error(sprintf('Refusing to follow a redirect from %s to %s', $fromUrl, $targetUrl));

		throw new UnsafeRedirect($fromUrl, $targetUrl);
	}

	/**
	 * Turns the Location header of a redirect response into an absolute URL.
	 *
	 * The header is allowed to be a relative reference, and real servers use every shape of one, so we cannot simply
	 * hand its value to the next request.
	 *
	 * @param   string  $currentUrl  The URL we requested, which the Location is relative to
	 * @param   string  $location    The raw value of the Location response header
	 *
	 * @return  string|null  The absolute URL, or NULL if the header was unusable
	 * @since   1.1.0
	 */
	final protected function resolveRedirectUrl(string $currentUrl, string $location): ?string
	{
		$location = trim($location);

		if ($location === '')
		{
			return null;
		}

		// Already absolute
		if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location))
		{
			return $location;
		}

		// As in isRedirectAllowed(): a URL we cannot parse is not one we can resolve against.
		try
		{
			$base = new Uri($currentUrl);
		}
		catch (Throwable)
		{
			return null;
		}

		$scheme = (string) ($base->scheme ?? '');

		// Scheme-relative, e.g. //www.example.com/foo
		if (str_starts_with($location, '//'))
		{
			return $scheme . ':' . $location;
		}

		$authority = $base->toString(['scheme', 'host', 'port']);

		// Root-relative, e.g. /en/index.php
		if (str_starts_with($location, '/'))
		{
			return $authority . $location;
		}

		// Relative to the directory of the current path, e.g. index.php
		$path      = (string) ($base->path ?? '');
		$lastSlash = strrpos($path, '/');
		$directory = $lastSlash === false ? '' : substr($path, 0, $lastSlash);

		return $authority . '/' . ltrim($directory . '/' . $location, '/');
	}

	/**
	 * Normalises a host name for comparison.
	 *
	 * @param   string  $host  The host, as parsed out of a URL
	 *
	 * @return  string
	 * @since   1.1.0
	 */
	private function normaliseHost(string $host): string
	{
		// A trailing dot makes a name fully qualified. It is the same name; drop it so it compares equal.
		return strtolower(trim(trim($host), '.'));
	}

	/**
	 * Is this host a literal IP address rather than a name?
	 *
	 * @param   string  $host  The host, as parsed out of a URL
	 *
	 * @return  bool
	 * @since   1.1.0
	 */
	private function isIpAddress(string $host): bool
	{
		// An IPv6 literal appears in a URL wrapped in square brackets, which are not part of the address.
		return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
	}

	/**
	 * Returns the Akeeba Backup JSON API version we are talking.
	 *
	 * The options may not say — a zero `apiVersion` means nobody specified one, and Autodetect has not run either.
	 * In that case we assume the newest version the configured endpoint could possibly speak. The v3 API is a route
	 * in Joomla's API application, so it only exists on Joomla! sites; Akeeba Solo and Akeeba Backup for WordPress,
	 * both of which are identified by their frontend endpoint, can only be talked to over the v2 API.
	 *
	 * @return  int  1, 2, or 3
	 * @since   1.1.0
	 */
	final protected function getApiVersion(): int
	{
		$apiVersion = (int) ($this->options->apiVersion ?: 0);

		if (in_array($apiVersion, Options::API_VERSIONS, true))
		{
			return $apiVersion;
		}

		return ($this->options->isWordPress || $this->options->endpoint === 'remote.php') ? 2 : 3;
	}

	/**
	 * Returns the HTTP headers which must accompany an API request.
	 *
	 * This is where the v3 API is authenticated. There are two credentials, and the server treats them as mutually
	 * exclusive: presenting a Secret Word makes it ignore any API token in the same request, and a *wrong* Secret
	 * Word is a hard failure rather than a fall-through to token authentication. We must therefore send exactly one
	 * of them, and we prefer the token — it identifies a Joomla! user whose privileges the server enforces per
	 * method, whereas the Secret Word is a blanket grant over the whole component and is itself deprecated.
	 *
	 * @return  array  Header name => header value
	 * @since   1.1.0
	 */
	final protected function getRequestHeaders(): array
	{
		$headers = [
			'User-Agent' => $this->options->ua,
		];

		if ($this->getApiVersion() !== 3)
		{
			return $headers;
		}

		/**
		 * Joomla's API application cannot accept a missing Accept header — it answers HTTP 406. Akeeba Backup's
		 * webservices plugin papers over that for its own routes, but only for its own routes: a request which fails
		 * to match one gets the raw 406. Send the header and the failure mode stays legible.
		 */
		$headers['Accept'] = 'application/json';

		// DO NOT REMOVE the local variables. empty() does NOT work on magic properties!
		$token  = trim((string) ($this->options->token ?? ''));
		$secret = trim((string) ($this->options->secret ?? ''));

		if ($token !== '')
		{
			$headers['X-Joomla-Token'] = $token;
		}
		elseif ($secret !== '')
		{
			$headers['X-Akeeba-Auth'] = $secret;
		}

		return $headers;
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

		$md5func = function ($string)
		{
			static $shouldUseHash = null;

			if ($shouldUseHash === null)
			{
				$shouldUseHash = function_exists('hash')
				                 && function_exists('hash_algos')
				                 && in_array('md5', hash_algos());
			}

			return $shouldUseHash ? hash('md5', $string) : md5($string);
		};

		$salt              = $this->randomString();
		$challenge         = $salt . ':' . $md5func($salt . $this->options->secret);
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
	 * Extracts the data returned by the v2 and v3 APIs.
	 *
	 * Technically, there is no encapsulation in either, but we transform the data in a way that gives it a similar
	 * shape to v1 data, simplifying our code. The two versions share a response shape exactly — a status member and a
	 * data member — which is the one thing about the v3 API a client does not have to change.
	 *
	 * @param   string  $encapsulatedResponse  The JSON data to parse.
	 *
	 * @return  object
	 * @since   1.0.0
	 */
	private function exposeDataModernApi(string $encapsulatedResponse): object
	{
		// JSON API v2 and v3: Get the JSON data and construct a result similar to what was returned by v1
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