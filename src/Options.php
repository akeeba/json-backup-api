<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    AGPL-3.0-or-later
 */

namespace Akeeba\BackupJsonApi;

use Akeeba\BackupJsonApi\DataObject\ImmutableDataObject;
use Akeeba\BackupJsonApi\Exception\NoConfiguredHost;
use Akeeba\BackupJsonApi\Exception\NoConfiguredSecret;
use Akeeba\BackupJsonApi\Uri\Uri;
use Composer\CaBundle\CaBundle;
use Composer\InstalledVersions;
use LogicException;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Immutable options for the Akeeba Backup JSON API Connector
 *
 * @property-read   string               $host           Protocol, hostname and path to the endpoint
 * @property-read   string               $secret         Secret key to use in communications (used for authentication)
 * @property-read   string               $endpoint       Endpoint file, e.g. index.php.
 * @property-read   string               $component      Component used in Joomla! sites, defaults to com_akeeba
 * @property-read   string               $verb           HTTP verb to use in the API, default: GET
 * @property-read   string               $format         Format used for Joomla! sites, default: html
 * @property-read   string               $ua             User Agent string
 * @property-read   string               $capath         Certificate Authority cache path
 * @property-read   bool                 $verbose        Enable verbose (debug) mode.
 * @property-read   string               $view           View name. 'json' is v1 API, 'api' is v2 API.
 * @property-read   bool                 $isWordPress    Is this a WordPress site using admin-ajax.php as entry point?
 * @property-read   LoggerInterface|null $logger         PSR-3 compatible logger
 */
class Options extends ImmutableDataObject
{
	/**
	 * OutputOptions constructor. The options you pass initialize the immutable object.
	 *
	 * @param   array  $options  The options to initialize the object with
	 * @param   bool   $strict   When enabled, unknown $options keys will throw an exception instead of silently
	 *                           skipped.
	 *
	 * @since   1.0.0
	 */
	public function __construct(array $options, bool $strict = false)
	{
		$appliedOptions = [
			'capath'      => '',
			'host'        => '',
			'verb'        => 'GET',
			'endpoint'    => 'index.php',
			'component'   => '',
			'view'        => '',
			'format'      => '',
			'secret'      => '',
			'ua'          => $this->getUserAgent(),
			'verbose'     => false,
			'isWordPress' => false,
			'logger'      => new NullLogger(),
		];

		foreach ($options as $k => $v)
		{
			if (array_key_exists($k, $appliedOptions))
			{
				$appliedOptions[$k] = $v;

				continue;
			}

			if ($strict)
			{
				throw new LogicException(
					sprintf(
						'Class %s does not have property ‘%s’',
						__CLASS__,
						$k
					)
				);
			}
		}

		unset($options);

		if ($appliedOptions['debug'] ?? false)
		{
			$appliedOptions['verbose'] = true;
		}

		// Make sure we have a secret
		if (empty($appliedOptions['secret']))
		{
			throw new NoConfiguredSecret();
		}

		// Normalize the host definition
		$this->parseHost($appliedOptions);

		if (empty($appliedOptions['host']))
		{
			throw new NoConfiguredHost();
		}

		// Akeeba Solo or Akeeba Backup for WordPress endpoint; do not use format and component parameters in the URL
		if ($appliedOptions['endpoint'] == 'remote.php')
		{
			$appliedOptions['format']    = '';
			$appliedOptions['component'] = '';
		}
		// Akeeba Solo or Akeeba Backup for WordPress endpoint; do not use format and component parameters in the URL
		elseif ($appliedOptions['endpoint'] == 'admin-ajax.php')
		{
			$appliedOptions['format']      = '';
			$appliedOptions['component']   = '';
			$appliedOptions['isWordPress'] = true;
		}

		// Make sure I have a valid CA cache path
		if (empty($appliedOptions['capath']) || !CaBundle::validateCaFile($appliedOptions['capath']))
		{
			$appliedOptions['capath'] = CaBundle::getSystemCaRootBundlePath();
		}

		parent::__construct($appliedOptions);
	}

	public function toArray(): array
	{
		return $this->properties;
	}

	/**
	 * Get the default user agent
	 *
	 * @return  string
	 */
	private function getUserAgent(): string
	{
		if (defined('ARCCLI_VERSION'))
		{
			return 'AkeebaRemoteCLI/' . ARCCLI_VERSION;
		}

		try
		{
			$version = InstalledVersions::getVersion('akeeba/json-backup-api');
		}
		catch (OutOfBoundsException)
		{
			$version = '0.0.0-dev' . gmdate('Ymd');
		}

		return 'AkeebaBackupJsonApiClient/' . $version;
	}

	/**
	 * Normalize the host. Make sure there is an HTTP or HTTPS scheme. Also extract the endpoint if it's specified.
	 *
	 * @return  void  Operates directly to the host and endpoint properties of this object.
	 * @since   1.0.0
	 */
	private function parseHost(array &$options): void
	{
		if (empty($options['host']))
		{
			return;
		}

		$uri = new Uri($options['host']);

		if (!in_array($uri->scheme, ['http', 'https']))
		{
			$uri->scheme = 'http';
		}

		$component = $uri->getVar('option', '');

		if (!empty($component))
		{
			$options['component'] = $component;
		}

		$format = $uri->getVar('format', '');

		if (!empty($format))
		{
			$options['format'] = $format;
		}

		$view = $uri->getVar('view', '');

		if (!empty($view))
		{
			$options['view'] = $view;
		}

		$originalPath = $uri->path;
		[$path, $endpoint] = $this->parsePath($originalPath);
		$uri->path = '/' . ltrim($path, '/ ');

		if (str_ends_with($endpoint ?? '', '.php'))
		{
			$options['endpoint'] = $endpoint;
		}

		$options['host'] = $uri->toString(['scheme', 'user', 'pass', 'host', 'port', 'path']);
	}

	/**
	 * Parse the path of a URL and either extract a .php endpoint or strip a misplaced index.html or other useless bit.
	 *
	 * @param   string|null  $originalPath  The original UTL path
	 *
	 * @return  array  [$path, $endpoint]. The endpoint may be empty.
	 * @since   1.0.0
	 */
	private function parsePath(?string $originalPath): array
	{
		$originalPath = trim($originalPath ?? '', "/");

		// The path is "/"
		if (empty($originalPath))
		{
			return ['', ''];
		}

		$lastSlashPost = strrpos($originalPath, '/');

		// Normally should not happen since I've stripped the slashes.
		if ($lastSlashPost === 0)
		{
			throw new LogicException("I found a misplaced slash in a path. Notify the developer. This must never happen.");
		}

		$endpoint = $originalPath;
		$path     = '';

		if ($lastSlashPost !== false)
		{
			$endpoint = substr($originalPath, $lastSlashPost + 1);
			$path     = substr($originalPath, 0, $lastSlashPost);
		}

		// The path is "some/thing/or/another"
		if (!str_contains($endpoint, '.'))
		{
			return [$originalPath, ''];
		}

		// The path was "some/thing/whatever.ext". If .ext is .php I have an endpoint. Otherwise, I will strip it.
		if (str_ends_with($endpoint, '.php'))
		{
			return [$path, $endpoint];
		}

		return [$path, ''];
	}
}
