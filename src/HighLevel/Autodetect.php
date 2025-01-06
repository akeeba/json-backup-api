<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi\HighLevel;

use Akeeba\BackupJsonApi\Exception\ApiException;
use Akeeba\BackupJsonApi\Exception\CommunicationError;
use Akeeba\BackupJsonApi\Exception\InvalidSecretWord;
use Akeeba\BackupJsonApi\Exception\NoWayToConnect;
use Akeeba\BackupJsonApi\Exception\RemoteApiVersionTooLow;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Akeeba\BackupJsonApi\Options;

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
		$views           = $this->getViews($originalOptions);
		$verbs           = $this->getVerbs($originalOptions);
		$formats         = $this->getFormats($originalOptions);
		$endpoints       = $this->getEndpoints($originalOptions);
		$components      = $this->getComponents($originalOptions);

		$apiResult     = null;
		$lastException = null;

		foreach ($components as $component)
		{
			foreach ($views as $view)
			{
				foreach ($verbs as $verb)
				{
					foreach ($formats as $format)
					{
						foreach ($endpoints as $endpoint)
						{
							$lastException = null;

							$options = $this->httpClient->getOptions([
								'component' => $component,
								'verb'      => $verb,
								'view'      => $view,
								'format'    => $format,
								'endpoint'  => $endpoint,
							]);

							try
							{
								$this->httpClient->setOptions($options);
								$apiResult = $this->httpClient->doQuery('getVersion');

								break 5;
							}
							catch (CommunicationError $communicationError)
							{
								/**
								 * We might get this kind of exception if the endpoint is wrong or results in endless
								 * redirections. Of course it's also raised when it's a genuine network issue but, hey, what can
								 * you do?
								 */

								$options->logger->warning(sprintf(
										'Communication error with verb “%s”, view “%s”, format “%s”, endpoint “%s”. The error was ‘%s’.',
										$verb,
										$view,
										$format,
										$endpoint,
										$communicationError->getMessage()
									)
								);

								$lastException = $communicationError;

								continue;
							}
							catch (InvalidSecretWord $apiException)
							{
								// Invalid secret word exception gets re-thrown
								throw $apiException;
							}
							catch (ApiException $apiException)
							{
								$lastException = $apiException;

								/**
								 * We got corrupt data back. This could be because, e.g. using the format=html on a Joomla! site
								 * with a broken third party plugin results in the output being ovewritten. So let's retry with
								 * another way to connect to the site.
								 */
								$options->logger->warning(sprintf(
										'Remote API error with verb “%s”, format “%s”, endpoint “%s”. The error was ‘%s’.',
										$verb,
										$format,
										$endpoint,
										$apiException->getMessage()
									)
								);

								continue;
							}
						}
					}
				}
			}
		}

		if (is_null($apiResult))
		{
			throw new NoWayToConnect(36, $lastException);
		}

		// Check the response
		if ($apiResult->body->status != 200)
		{
			throw new RemoteError($apiResult->body->status . " - " . $apiResult->body->data, 101, $lastException);
		}

		// Check the API version
		/** @noinspection PhpUndefinedConstantInspection */
		$minApiLevel = defined('ARCCLI_MINAPI') ? ARCCLI_MINAPI : AKEEBA_JSON_BACKUP_API_MINIMUM_API_LEVEL;

		if ($apiResult->body->data->api < $minApiLevel)
		{
			throw new RemoteApiVersionTooLow(102, $lastException);
		}

		/** @noinspection PhpUndefinedVariableInspection */
		$options->logger->debug(
			sprintf(
				'Found a connection method. Verb: %s, Component: %s, View: %s, Format: %s, Endpoint: %s',
				$options->verb,
				$options->component,
				$options->view,
				$options->format,
				$options->endpoint
			)
		);

		$this->httpClient->setOptions($options);
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

	private function getViews(Options $originalOptions): array
	{
		$defaultList = ['api', 'json'];
		$view        = strtolower($originalOptions->view ?? '');

		if (empty($view))
		{
			return $defaultList;
		}

		return [$view];
	}
}