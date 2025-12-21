<?php
/**
 * S3-compatible external storage backend.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup ExternalStorage
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;

/**
 * S3-compatible external storage backend for MediaWiki.
 *
 * This class provides external storage support for any S3-compatible
 * object storage service including:
 * - Amazon S3
 * - MinIO
 * - Ceph RADOS Gateway
 * - DigitalOcean Spaces
 * - Backblaze B2
 * - Wasabi
 *
 * URL format: S3://<bucket>/<key>
 *
 * Configuration is done via $wgExternalStoreS3Config in LocalSettings.php.
 *
 * @see ExternalStoreAccess
 * @ingroup ExternalStorage
 * @since 1.46
 */
class ExternalStoreS3 extends ExternalStoreMedium {

	/** @var Client|null */
	private ?Client $httpClient = null;

	/** @var string */
	private string $endpoint;

	/** @var string */
	private string $region;

	/** @var string */
	private string $accessKey;

	/** @var string */
	private string $secretKey;

	/** @var bool */
	private bool $usePathStyle;

	/**
	 * @param array $params Configuration parameters:
	 *   - domain: the DB domain ID (required, from parent)
	 *   - endpoint: S3 endpoint URL (required)
	 *   - region: S3 region (default: 'us-east-1')
	 *   - accessKey: S3 access key (required)
	 *   - secretKey: S3 secret key (required)
	 *   - usePathStyle: Use path-style URLs (default: true, required for MinIO/Ceph)
	 */
	public function __construct( array $params ) {
		parent::__construct( $params );

		if ( !isset( $params['endpoint'] ) ) {
			throw new InvalidArgumentException( 'Missing required "endpoint" parameter for S3 store.' );
		}
		if ( !isset( $params['accessKey'] ) ) {
			throw new InvalidArgumentException( 'Missing required "accessKey" parameter for S3 store.' );
		}
		if ( !isset( $params['secretKey'] ) ) {
			throw new InvalidArgumentException( 'Missing required "secretKey" parameter for S3 store.' );
		}

		$this->endpoint = rtrim( $params['endpoint'], '/' );
		$this->region = $params['region'] ?? 'us-east-1';
		$this->accessKey = $params['accessKey'];
		$this->secretKey = $params['secretKey'];
		$this->usePathStyle = $params['usePathStyle'] ?? true;
	}

	/**
	 * Get HTTP client instance.
	 *
	 * @return Client
	 */
	private function getHttpClient(): Client {
		if ( $this->httpClient === null ) {
			$this->httpClient = new Client( [
				'timeout' => 30,
				'connect_timeout' => 10,
			] );
		}
		return $this->httpClient;
	}

	/**
	 * Build the full URL for an S3 object.
	 *
	 * @param string $bucket Bucket name
	 * @param string $key Object key
	 * @return string Full URL
	 */
	private function buildObjectUrl( string $bucket, string $key ): string {
		if ( $this->usePathStyle ) {
			return "{$this->endpoint}/{$bucket}/{$key}";
		}
		// Virtual-hosted style
		$parsedEndpoint = parse_url( $this->endpoint );
		$scheme = $parsedEndpoint['scheme'] ?? 'https';
		$host = $parsedEndpoint['host'] ?? '';
		$port = isset( $parsedEndpoint['port'] ) ? ":{$parsedEndpoint['port']}" : '';
		return "{$scheme}://{$bucket}.{$host}{$port}/{$key}";
	}

	/**
	 * Generate AWS Signature Version 4 authorization header.
	 *
	 * @param string $method HTTP method
	 * @param string $url Full URL
	 * @param string $payload Request body
	 * @param array $headers Additional headers
	 * @return array Headers with authorization
	 */
	private function signRequest(
		string $method,
		string $url,
		string $payload,
		array $headers = []
	): array {
		$parsedUrl = parse_url( $url );
		$host = $parsedUrl['host'] ?? '';
		if ( isset( $parsedUrl['port'] ) ) {
			$host .= ':' . $parsedUrl['port'];
		}
		$path = $parsedUrl['path'] ?? '/';
		$query = $parsedUrl['query'] ?? '';

		$timestamp = gmdate( 'Ymd\THis\Z' );
		$datestamp = gmdate( 'Ymd' );

		$payloadHash = hash( 'sha256', $payload );

		// Canonical headers
		$canonicalHeaders = [
			'host' => $host,
			'x-amz-content-sha256' => $payloadHash,
			'x-amz-date' => $timestamp,
		];

		// Add content-type for PUT requests
		if ( $method === 'PUT' && isset( $headers['Content-Type'] ) ) {
			$canonicalHeaders['content-type'] = $headers['Content-Type'];
		}

		ksort( $canonicalHeaders );

		$signedHeaderNames = implode( ';', array_keys( $canonicalHeaders ) );
		$canonicalHeadersStr = '';
		foreach ( $canonicalHeaders as $name => $value ) {
			$canonicalHeadersStr .= "{$name}:{$value}\n";
		}

		// Canonical request
		$canonicalRequest = implode( "\n", [
			$method,
			$path,
			$query,
			$canonicalHeadersStr,
			$signedHeaderNames,
			$payloadHash,
		] );

		// String to sign
		$algorithm = 'AWS4-HMAC-SHA256';
		$credentialScope = "{$datestamp}/{$this->region}/s3/aws4_request";
		$stringToSign = implode( "\n", [
			$algorithm,
			$timestamp,
			$credentialScope,
			hash( 'sha256', $canonicalRequest ),
		] );

		// Calculate signature
		$kDate = hash_hmac( 'sha256', $datestamp, 'AWS4' . $this->secretKey, true );
		$kRegion = hash_hmac( 'sha256', $this->region, $kDate, true );
		$kService = hash_hmac( 'sha256', 's3', $kRegion, true );
		$kSigning = hash_hmac( 'sha256', 'aws4_request', $kService, true );
		$signature = hash_hmac( 'sha256', $stringToSign, $kSigning );

		// Authorization header
		$authorization = "{$algorithm} " .
			"Credential={$this->accessKey}/{$credentialScope}, " .
			"SignedHeaders={$signedHeaderNames}, " .
			"Signature={$signature}";

		return [
			'Host' => $host,
			'x-amz-date' => $timestamp,
			'x-amz-content-sha256' => $payloadHash,
			'Authorization' => $authorization,
		];
	}

	/**
	 * Fetch data from given external store URL.
	 *
	 * @param string $url URL in the form S3://bucket/key
	 * @return string|false The stored data or false on error
	 */
	public function fetchFromURL( $url ) {
		$parsed = $this->parseURL( $url );
		if ( $parsed === null ) {
			$this->logger->error( "ExternalStoreS3: Invalid URL format: {$url}" );
			return false;
		}

		[ $bucket, $key ] = $parsed;
		$objectUrl = $this->buildObjectUrl( $bucket, $key );

		try {
			$headers = $this->signRequest( 'GET', $objectUrl, '' );
			$request = new Request( 'GET', $objectUrl, $headers );
			$response = $this->getHttpClient()->send( $request );

			if ( $response->getStatusCode() === 200 ) {
				return (string)$response->getBody();
			}

			$this->logger->error(
				"ExternalStoreS3: Failed to fetch {$url}, status: {$response->getStatusCode()}"
			);
			return false;
		} catch ( GuzzleException $e ) {
			$this->logger->error( "ExternalStoreS3: Failed to fetch {$url}: {$e->getMessage()}" );
			return false;
		}
	}

	/**
	 * Fetch multiple URLs from the external store.
	 *
	 * @param array $urls List of URLs to fetch
	 * @return array Map of (url => content) for successful fetches
	 */
	public function batchFetchFromURLs( array $urls ) {
		// For S3, we just iterate since each object is a separate request
		// Future optimization: use S3 batch operations if available
		$results = [];
		foreach ( $urls as $url ) {
			$data = $this->fetchFromURL( $url );
			if ( $data !== false ) {
				$results[$url] = $data;
			}
		}
		return $results;
	}

	/**
	 * Store data to S3.
	 *
	 * @param string $location Bucket name
	 * @param string $data Content to store
	 * @return string|false URL on success, false on failure
	 */
	public function store( $location, $data ) {
		$key = $this->generateKey( $data );
		$objectUrl = $this->buildObjectUrl( $location, $key );

		try {
			$headers = $this->signRequest( 'PUT', $objectUrl, $data, [
				'Content-Type' => 'application/octet-stream',
			] );
			$headers['Content-Type'] = 'application/octet-stream';
			$headers['Content-Length'] = strlen( $data );

			$request = new Request( 'PUT', $objectUrl, $headers, $data );
			$response = $this->getHttpClient()->send( $request );

			$statusCode = $response->getStatusCode();
			if ( $statusCode === 200 || $statusCode === 201 ) {
				$this->logger->debug( "ExternalStoreS3: Stored blob to S3://{$location}/{$key}" );
				return "S3://{$location}/{$key}";
			}

			$this->logger->error(
				"ExternalStoreS3: Failed to store to {$location}, status: {$statusCode}"
			);
			return false;
		} catch ( GuzzleException $e ) {
			$this->logger->error(
				"ExternalStoreS3: Failed to store to {$location}: {$e->getMessage()}"
			);
			return false;
		}
	}

	/**
	 * Generate a unique key for the blob.
	 *
	 * Uses content hash + timestamp to ensure uniqueness while allowing
	 * easy debugging. Keys are sharded by hash prefix for better S3 performance.
	 *
	 * @param string $data The data to generate a key for
	 * @return string The generated key
	 */
	private function generateKey( string $data ): string {
		$hash = sha1( $data );
		$timestamp = wfTimestampNow();
		// Shard by first 2 chars of hash for better S3 performance
		// with many objects (spreads across partitions)
		return substr( $hash, 0, 2 ) . '/' . $timestamp . '-' . $hash;
	}

	/**
	 * Parse an S3 URL into bucket and key.
	 *
	 * @param string $url URL in the form S3://bucket/key
	 * @return array|null [bucket, key] or null if invalid
	 */
	private function parseURL( string $url ): ?array {
		// Match S3://bucket/key (key may contain slashes)
		if ( !preg_match( '#^S3://([^/]+)/(.+)$#i', $url, $matches ) ) {
			return null;
		}
		return [ $matches[1], $matches[2] ];
	}

	/**
	 * Check if a given location is read-only.
	 *
	 * @param string $location The bucket name
	 * @return bool Whether this location is read-only
	 */
	public function isReadOnly( $location ) {
		return $this->params['readOnly'] ?? false;
	}
}
