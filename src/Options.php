<?php
/**
 * @package    AkeebaJsonBackupAPI
 * @copyright  Copyright (c)2008-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
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
 * @property-read   string               $secret         Secret Word to use in communications (used for authentication)
 * @property-read   string               $token          Joomla! API token to authenticate with (API v3 only)
 * @property-read   int                  $apiVersion     JSON API version: 1, 2, or 3. Zero means “not specified”.
 * @property-read   string               $endpoint       Endpoint file, e.g. index.php.
 * @property-read   string               $apiEndpoint    Path from the site's root to the Joomla! API application
 * @property-read   string               $component      Component used in Joomla! sites, defaults to com_akeeba
 * @property-read   string               $verb           HTTP verb to use in the API, default: GET
 * @property-read   string               $format         Format used for Joomla! sites, default: html
 * @property-read   string               $ua             User Agent string
 * @property-read   string               $capath         Certificate Authority cache path
 * @property-read   bool                 $verbose        Enable verbose (debug) mode.
 * @property-read   string               $view           Deprecated alias of $apiVersion: 'json' is v1, 'api' is v2.
 * @property-read   bool                 $isWordPress    Is this a WordPress site using admin-ajax.php as entry point?
 * @property-read   LoggerInterface|null $logger         PSR-3 compatible logger
 */
class Options extends ImmutableDataObject
{
	/**
	 * The Akeeba Backup JSON API versions this library can speak, newest first.
	 *
	 * @var   int[]
	 * @since 1.1.0
	 */
	public const API_VERSIONS = [3, 2, 1];

	/**
	 * The default path, relative to the site's root, of the Joomla! API application.
	 *
	 * This is the form which does not require URL rewriting. See Options::API_ENDPOINTS for the alternatives which
	 * Autodetect will also try.
	 *
	 * @var   string
	 * @since 1.1.0
	 */
	public const DEFAULT_API_ENDPOINT = 'api/index.php';

	/**
	 * The paths, relative to the site's root, where the Joomla! API application may be reachable.
	 *
	 * The first entry works on any server. The second only works when the API application's URL rewriting is in
	 * effect, which is why it is tried second, not first.
	 *
	 * @var   string[]
	 * @since 1.1.0
	 */
	public const API_ENDPOINTS = ['api/index.php', 'api'];

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
			'apiEndpoint' => self::DEFAULT_API_ENDPOINT,
			'component'   => '',
			'view'        => '',
			'apiVersion'  => 0,
			'format'      => '',
			'secret'      => '',
			'token'       => '',
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

		/**
		 * Make sure we have something to authenticate with.
		 *
		 * Either credential is enough on its own. The API v3 accepts a Joomla! API token instead of the Secret Word,
		 * which is the only credential the v1 and v2 APIs understand.
		 */
		if (empty($appliedOptions['secret']) && empty($appliedOptions['token']))
		{
			throw new NoConfiguredSecret();
		}

		// Normalize the host definition
		$this->parseHost($appliedOptions);

		if (empty($appliedOptions['host']))
		{
			throw new NoConfiguredHost();
		}

		/**
		 * Is this Akeeba Backup for WordPress?
		 *
		 * This is derived from the endpoint, not remembered: Autodetect reaches an option set by cloning it with
		 * overrides, over and over, and a flag which is only ever set to true would latch on the first WordPress
		 * candidate and stay on for every candidate tried after it.
		 *
		 * The endpoint is matched by suffix, not for equality, because it arrives in two shapes. parseHost() splits a
		 * pasted URL into a host and a bare endpoint file, giving 'admin-ajax.php', while Autodetect tries the
		 * endpoint as the path 'wp-admin/admin-ajax.php'. Both are WordPress; matching only the former left the
		 * WordPress action parameter off the URL for the latter.
		 */
		$appliedOptions['isWordPress'] = str_ends_with($appliedOptions['endpoint'], 'admin-ajax.php');

		// Akeeba Solo or Akeeba Backup for WordPress endpoint; do not use format and component parameters in the URL
		if ($appliedOptions['endpoint'] == 'remote.php' || $appliedOptions['isWordPress'])
		{
			$appliedOptions['format']    = '';
			$appliedOptions['component'] = '';
		}

		$this->parseApiVersion($appliedOptions);

		$appliedOptions['apiEndpoint'] = trim($appliedOptions['apiEndpoint'] ?: self::DEFAULT_API_ENDPOINT, '/');

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
	 * Work out which JSON API version was asked for, if any.
	 *
	 * Before the v3 API existed the version was expressed indirectly, through the name of the Joomla! view the request
	 * was addressed to: 'json' for v1, 'api' for v2. The v3 API is not addressed by a view name at all — it is a route
	 * in Joomla's API application — so the version had to become an option of its own. The `view` option is kept as an
	 * alias of it, both because it is what a user pastes when they copy a v1 or v2 API URL out of their site's
	 * configuration, and because it is still the query parameter those two versions are addressed by.
	 *
	 * An explicitly specified `apiVersion` wins over `view`. Zero, the default, means “nobody said”, which is what
	 * Autodetect looks for to decide whether it may try every version or has to respect a pinned one.
	 *
	 * @param   array  $options  The options being applied. Operated upon directly.
	 *
	 * @return  void
	 * @since   1.1.0
	 */
	private function parseApiVersion(array &$options): void
	{
		$apiVersion = (int) ($options['apiVersion'] ?? 0);

		if (in_array($apiVersion, self::API_VERSIONS, true))
		{
			$options['apiVersion'] = $apiVersion;

			return;
		}

		// The view name is matched case-insensitively: the v2 API is addressed as view=Api, with a capital A.
		$options['apiVersion'] = match (strtolower(trim((string) ($options['view'] ?? ''))))
		{
			'json'  => 1,
			'api'   => 2,
			default => 0,
		};
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
