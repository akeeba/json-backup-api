<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\ApiException;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\InvalidSecretWord;
use Akeeba\BackupJsonApi\Exception\NotAuthorised;
use Akeeba\BackupJsonApi\Exception\NoWayToConnect;
use Akeeba\BackupJsonApi\Exception\RemoteApiVersionTooLow;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Akeeba\BackupJsonApi\Options;
use Throwable;

/**
 * Auto-detect the best connection settings
 *
 * @since  1.0.0
 */
class Autodetect
{
	public function __construct(private HttpClientInterface $httpClient)
	{
	}

	public function __invoke(): void
	{
		$originalOptions = $this->httpClient->getOptions();
		$logger          = $originalOptions->logger;
		$candidates      = $this->getCandidates($originalOptions);

		$apiResult    = null;
		$foundOptions = null;

		/**
		 * Why several failure variables rather than just the last exception?
		 *
		 * Because the last candidate we try is the oldest, least likely API version, and its failure is the least
		 * informative one we will see. A site which rejected our credential on the v3 API — proving it speaks the API
		 * and only objects to who we are — then goes on to 404 every v1 request, because the v1 API no longer exists
		 * in current versions. Reporting that last 404 as “we cannot find a way to connect” would send the user
		 * looking at their host name when the problem is their credential. So we keep each *kind* of failure and pick
		 * the most informative one at the end. See getFailureException().
		 */
		$lastException          = null;
		$authException          = null;
		$notAuthorisedException = null;
		$lastErrorStatus        = null;

		foreach ($candidates as $candidate)
		{
			$options = $this->httpClient->getOptions($candidate);

			try
			{
				$this->httpClient->setOptions($options);
				$result = $this->httpClient->doQuery('getVersion');
			}
			catch (CommunicationError $communicationError)
			{
				/**
				 * We might get this kind of exception if the endpoint is wrong or results in endless redirections. Of
				 * course it's also raised when it's a genuine network issue but, hey, what can you do?
				 */
				$logger->warning(
					sprintf(
						'Communication error trying %s. The error was ‘%s’.',
						$this->describe($candidate),
						$communicationError->getMessage()
					)
				);

				$lastException = $communicationError;

				continue;
			}
			catch (ApiException $apiException)
			{
				/**
				 * The remote end said no. This includes an authentication failure (InvalidSecretWord), which is NOT
				 * fatal to the search: probing the v3 API means trying the same credential first as a Joomla! API
				 * token and then as a Secret Word, and exactly one of those two attempts is expected to fail. It also
				 * covers corrupt data — using format=html on a Joomla! site with a broken third party plugin results
				 * in the output being overwritten, for instance — so let's retry with another way to connect.
				 */
				$logger->warning(
					sprintf(
						'Remote API error trying %s. The error was ‘%s’.',
						$this->describe($candidate),
						$apiException->getMessage()
					)
				);

				$lastException = $apiException;

				if ($apiException instanceof NotAuthorised)
				{
					$notAuthorisedException = $apiException;
				}
				elseif ($apiException instanceof InvalidSecretWord)
				{
					$authException = $apiException;
				}

				continue;
			}

			/**
			 * A status other than 200 which doQuery() did not turn into an exception. Treat it the same way as an
			 * exception: this combination did not work, try the next one, and remember the status in case nothing does.
			 */
			if ($result->body->status != 200)
			{
				$logger->warning(
					sprintf(
						'Remote API status %d trying %s.',
						$result->body->status,
						$this->describe($candidate)
					)
				);

				$lastErrorStatus = $result->body->status . ' - ' . $result->body->data;

				continue;
			}

			$apiResult    = $result;
			$foundOptions = $options;

			break;
		}

		if (is_null($apiResult))
		{
			throw $this->getFailureException(
				$notAuthorisedException ?? $authException,
				$lastException,
				$lastErrorStatus
			);
		}

		// Check the API version
		/** @noinspection PhpUndefinedConstantInspection */
		$minApiLevel = defined('ARCCLI_MINAPI') ? ARCCLI_MINAPI : AKEEBA_JSON_BACKUP_API_MINIMUM_API_LEVEL;

		if ($apiResult->body->data->api < $minApiLevel)
		{
			throw new RemoteApiVersionTooLow(102, $lastException);
		}

		$logger->debug(
			sprintf(
				'Found a connection method. API version: %d, Verb: %s, Component: %s, Format: %s, Endpoint: %s, API endpoint: %s, Credential: %s',
				$foundOptions->apiVersion,
				$foundOptions->verb,
				$foundOptions->component,
				$foundOptions->format,
				$foundOptions->endpoint,
				$foundOptions->apiEndpoint,
				empty($foundOptions->token) ? 'Secret Word' : 'Joomla! API token'
			)
		);

		$this->httpClient->setOptions($foundOptions);
	}

	/**
	 * Returns every way of connecting we are prepared to try, in the order we will try them.
	 *
	 * Flattening the search into a list, instead of nesting a loop per axis, is what lets the axes differ between API
	 * versions. They genuinely do: the v3 API has no component, view, or format to vary, and varying them anyway would
	 * mean firing a dozen byte-identical requests at the site before moving on to the next version.
	 *
	 * @param   Options  $options  The options we were configured with
	 *
	 * @return  array[]  A list of option overrides, each describing one way of connecting
	 * @since   1.1.0
	 */
	private function getCandidates(Options $options): array
	{
		$candidates = [];

		foreach ($this->getApiVersions($options) as $apiVersion)
		{
			$candidates = array_merge(
				$candidates,
				$apiVersion === 3
					? $this->getV3Candidates($options)
					: $this->getLegacyCandidates($options, $apiVersion)
			);
		}

		return $candidates;
	}

	/**
	 * Returns the ways of connecting to the JSON API v3, in the order we will try them.
	 *
	 * The credential axis is the interesting one. The v3 API accepts either a Joomla! API token or the Secret Word,
	 * and we prefer the token: it identifies a Joomla! user account whose privileges the server enforces per API
	 * method, whereas the Secret Word is an unscoped grant over the whole component — and is itself deprecated.
	 *
	 * The subtlety is that a caller who has only ever had one credential field, as Akeeba Remote CLI and Akeeba
	 * Panopticon do, will have their user paste a token into the field labelled "secret". So a value given as the
	 * Secret Word is tried as a token first, and only then as a Secret Word. Nothing is lost by guessing wrong — the
	 * failed attempt costs one request — and pasting a token where a Secret Word used to go simply works.
	 *
	 * @param   Options  $options  The options we were configured with
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	private function getV3Candidates(Options $options): array
	{
		/**
		 * The v3 API is a route in Joomla's API application. Akeeba Solo and Akeeba Backup for WordPress do not have
		 * one, and they are identified by the frontend endpoint they were configured with, so there is no point
		 * asking them for it.
		 */
		if ($options->isWordPress || in_array($options->endpoint, ['remote.php', 'wp-admin/admin-ajax.php'], true))
		{
			return [];
		}

		$candidates = [];

		/**
		 * The endpoint is the outer axis, the credential the inner one, and the order is not arbitrary.
		 *
		 * The endpoint is a property of the site: at most one of the two ways of addressing Joomla's API application
		 * works, and which one that is does not depend on the credential. The credential is a property of the caller's
		 * configuration, and when a single credential field has to be tried both ways (see getV3Credentials) exactly
		 * one of those attempts is expected to fail. Trying every endpoint before moving to the second credential
		 * would therefore re-probe an endpoint we have already been answered by — with an authentication error, which
		 * is itself proof that the endpoint is the right one.
		 */
		foreach ($this->getApiEndpoints($options) as $apiEndpoint)
		{
			foreach ($this->getV3Credentials($options) as $credential)
			{
				foreach ($this->getVerbs($options) as $verb)
				{
					$candidates[] = array_merge(
						$credential,
						[
							'apiVersion'  => 3,
							'apiEndpoint' => $apiEndpoint,
							'verb'        => $verb,
							// None of these mean anything to the v3 API. Blank them so the options cannot mislead.
							'view'        => '',
							'component'   => '',
							'format'      => '',
						]
					);
				}
			}
		}

		return $candidates;
	}

	/**
	 * Returns the credentials to try against the JSON API v3, in the order we will try them.
	 *
	 * Each entry sets both credential options, because the server treats them as mutually exclusive: presenting a
	 * Secret Word makes it ignore any token in the same request. Leaving a stale value in the option the candidate
	 * does not mean to use would silently defeat the candidate.
	 *
	 * @param   Options  $options  The options we were configured with
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	private function getV3Credentials(Options $options): array
	{
		// DO NOT REMOVE the local variables. empty() does NOT work on magic properties!
		$token  = trim((string) ($options->token ?? ''));
		$secret = trim((string) ($options->secret ?? ''));

		$credentials = [];

		if ($token !== '')
		{
			$credentials[] = ['token' => $token, 'secret' => ''];
		}

		// A Secret Word we were given may in fact be a Joomla! API token the user pasted into the wrong field.
		if ($secret !== '' && $secret !== $token)
		{
			$credentials[] = ['token' => $secret, 'secret' => ''];
		}

		if ($secret !== '')
		{
			$credentials[] = ['token' => '', 'secret' => $secret];
		}

		return $credentials;
	}

	/**
	 * Returns the ways of connecting to the legacy v1 and v2 JSON APIs, in the order we will try them.
	 *
	 * @param   Options  $options     The options we were configured with
	 * @param   int      $apiVersion  1 or 2
	 *
	 * @return  array[]
	 * @since   1.1.0
	 */
	private function getLegacyCandidates(Options $options, int $apiVersion): array
	{
		/**
		 * Both legacy versions authenticate with the Secret Word and nothing else. A caller configured with a Joomla!
		 * API token alone has nothing to present to them.
		 */
		$secret = trim((string) ($options->secret ?? ''));

		if ($secret === '')
		{
			return [];
		}

		$candidates = [];

		foreach ($this->getComponents($options) as $component)
		{
			foreach ($this->getVerbs($options) as $verb)
			{
				foreach ($this->getFormats($options) as $format)
				{
					foreach ($this->getEndpoints($options) as $endpoint)
					{
						$candidates[] = [
							'apiVersion' => $apiVersion,
							'component'  => $component,
							'verb'       => $verb,
							'format'     => $format,
							'endpoint'   => $endpoint,
							'secret'     => $secret,
							// The legacy APIs have no idea what a Joomla! API token is.
							'token'      => '',
							/**
							 * The version is carried by apiVersion, and `view` is only its legacy alias. Blank it, or
							 * a stale value inherited from the caller's options would win over the candidate.
							 */
							'view'       => '',
						];
					}
				}
			}
		}

		return $candidates;
	}

	/**
	 * Works out which exception best describes a search that found nothing.
	 *
	 * The candidates are ordered newest API version first, so the *last* failure is the least informative one. What
	 * matters is the most informative one, and the order below is that of decreasing informativeness.
	 *
	 * @param   Throwable|null  $credentialException  A rejection which tells us the site did talk to us: it either
	 *                                                refused our credential or refused what we asked to do with it
	 * @param   Throwable|null  $lastException        The exception thrown by the last candidate we tried, if any
	 * @param   string|null     $lastErrorStatus      The last non-200 status we got back, if any
	 *
	 * @return  Throwable
	 * @since   1.1.0
	 */
	private function getFailureException(
		?Throwable $credentialException, ?Throwable $lastException, ?string $lastErrorStatus
	): Throwable
	{
		/**
		 * Some candidate got far enough to be told "no" by Akeeba Backup itself, rather than by the network or by
		 * Joomla's router. That is a far more actionable diagnosis than "we cannot find a way to connect": the site
		 * answered us, it just did not accept the credential we presented — or accepted it and refused the method.
		 */
		if ($credentialException !== null)
		{
			return $credentialException;
		}

		if ($lastErrorStatus !== null)
		{
			return new RemoteError($lastErrorStatus, 101, $lastException);
		}

		return new NoWayToConnect(36, $lastException);
	}

	/**
	 * Describes a candidate for the benefit of the log.
	 *
	 * @param   array  $candidate  The option overrides describing the candidate
	 *
	 * @return  string
	 * @since   1.1.0
	 */
	private function describe(array $candidate): string
	{
		$description = sprintf('API v%d with verb “%s”', $candidate['apiVersion'], $candidate['verb']);

		if ($candidate['apiVersion'] == 3)
		{
			return $description . sprintf(
					', API endpoint “%s”, authenticating with %s',
					$candidate['apiEndpoint'],
					empty($candidate['token']) ? 'the Secret Word' : 'a Joomla! API token'
				);
		}

		return $description . sprintf(
				', component “%s”, format “%s”, endpoint “%s”',
				$candidate['component'],
				$candidate['format'],
				$candidate['endpoint']
			);
	}

	/**
	 * Get the JSON API versions I will be testing for, newest first.
	 *
	 * @param   Options  $options  The parsed options
	 *
	 * @return  int[]
	 * @since   1.1.0
	 */
	private function getApiVersions(Options $options): array
	{
		$apiVersion = (int) ($options->apiVersion ?: 0);

		// A specific version was asked for — whether directly, or through the legacy `view` option. Respect it.
		return in_array($apiVersion, Options::API_VERSIONS, true) ? [$apiVersion] : Options::API_VERSIONS;
	}

	/**
	 * Get the paths to Joomla's API application I will be testing for.
	 *
	 * @param   Options  $options  The parsed options
	 *
	 * @return  string[]
	 * @since   1.1.0
	 */
	private function getApiEndpoints(Options $options): array
	{
		$apiEndpoint = trim((string) ($options->apiEndpoint ?? ''), '/');

		if (empty($apiEndpoint) || $apiEndpoint === Options::DEFAULT_API_ENDPOINT)
		{
			return Options::API_ENDPOINTS;
		}

		return [$apiEndpoint];
	}

	/**
	 * Get the component (option) list I will be testing for.
	 *
	 * @param   Options  $options  The parsed options
	 *
	 * @return  string[]
	 */
	private function getComponents(Options $options): array
	{
		$defaultComponents = ['com_akeebabackup', 'com_akeeba', ''];
		$component         = $options->component;

		if ($options->component == '')
		{
			return $defaultComponents;
		}

		return empty($component) ? $defaultComponents : [strtolower($options->component ?: null)];
	}

	/**
	 * Get the formats I will be testing for.
	 *
	 * @param   Options  $options  The application input object
	 *
	 * @return  array
	 */
	private function getEndpoints(Options $options): array
	{
		$defaultList = ['index.php', 'remote.php', 'wp-admin/admin-ajax.php'];
		$endpoint    = $options->endpoint;

		return empty($endpoint) ? $defaultList : [$endpoint];
	}

	/**
	 * Get the formats I will be testing for
	 *
	 * @param   Options  $options  The parsed options
	 *
	 * @return  array
	 */
	private function getFormats(Options $options): array
	{
		$defaultFormats = ['json', 'raw'];
		$format         = strtolower($options->format ?: '');
		$format         = in_array($format, $defaultFormats, true) ? $format : '';

		if (empty($format))
		{
			return $defaultFormats;
		}

		return [$format];
	}

	/**
	 * Get the verbs I will be testing for.
	 *
	 * @param   Options  $options  The parsed options
	 *
	 * @return  array
	 */
	private function getVerbs(Options $options): array
	{
		$defaultList = ['POST', 'GET'];
		$verb        = strtoupper($options->verb ?: '');

		if (!in_array($verb, $defaultList))
		{
			return $defaultList;
		}

		return [$verb];
	}
}
