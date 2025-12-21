<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * @covers \ExternalStoreS3
 * @group ExternalStore
 */
class ExternalStoreS3Test extends MediaWikiIntegrationTestCase {

	/**
	 * Get a configured ExternalStoreS3 instance with a mocked HTTP client.
	 *
	 * @param MockHandler $mockHandler Guzzle mock handler with queued responses
	 * @param array $extraParams Additional params to pass to constructor
	 * @return ExternalStoreS3
	 */
	private function getStoreWithMockedClient( MockHandler $mockHandler, array $extraParams = [] ): ExternalStoreS3 {
		$handlerStack = HandlerStack::create( $mockHandler );
		$mockClient = new Client( [ 'handler' => $handlerStack ] );

		$params = array_merge( [
			'domain' => 'test-domain',
			'endpoint' => 'http://localhost:9000',
			'region' => 'us-east-1',
			'accessKey' => 'test-access-key',
			'secretKey' => 'test-secret-key',
			'usePathStyle' => true,
		], $extraParams );

		$store = new ExternalStoreS3( $params );

		// Inject mock client using reflection
		$reflection = new ReflectionClass( $store );
		$property = $reflection->getProperty( 'httpClient' );
		$property->setAccessible( true );
		$property->setValue( $store, $mockClient );

		return $store;
	}

	public function testConstructorRequiresEndpoint() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'endpoint' );

		new ExternalStoreS3( [
			'domain' => 'test-domain',
			'accessKey' => 'test',
			'secretKey' => 'test',
		] );
	}

	public function testConstructorRequiresAccessKey() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'accessKey' );

		new ExternalStoreS3( [
			'domain' => 'test-domain',
			'endpoint' => 'http://localhost:9000',
			'secretKey' => 'test',
		] );
	}

	public function testConstructorRequiresSecretKey() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'secretKey' );

		new ExternalStoreS3( [
			'domain' => 'test-domain',
			'endpoint' => 'http://localhost:9000',
			'accessKey' => 'test',
		] );
	}

	public function testFetchFromURL() {
		$expectedContent = 'Hello, World!';

		$mockHandler = new MockHandler( [
			new Response( 200, [], $expectedContent ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );
		$result = $store->fetchFromURL( 'S3://test-bucket/ab/20231215-abc123' );

		$this->assertSame( $expectedContent, $result );
	}

	public function testFetchFromURLReturnsfalseOn404() {
		$mockHandler = new MockHandler( [
			new Response( 404, [], 'Not Found' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );
		$result = $store->fetchFromURL( 'S3://test-bucket/nonexistent' );

		$this->assertFalse( $result );
	}

	public function testFetchFromURLWithInvalidURL() {
		$mockHandler = new MockHandler( [] );
		$store = $this->getStoreWithMockedClient( $mockHandler );

		// Invalid URL format - missing bucket/key
		$result = $store->fetchFromURL( 'S3://' );
		$this->assertFalse( $result );

		// Invalid URL format - missing key
		$result = $store->fetchFromURL( 'S3://bucket' );
		$this->assertFalse( $result );

		// Wrong protocol
		$result = $store->fetchFromURL( 'http://example.com/file' );
		$this->assertFalse( $result );
	}

	public function testStore() {
		$mockHandler = new MockHandler( [
			new Response( 200, [], '' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );
		$data = 'Test content to store';

		$url = $store->store( 'test-bucket', $data );

		$this->assertIsString( $url );
		$this->assertStringStartsWith( 'S3://test-bucket/', $url );
		// URL should contain hash-based sharding (2 char prefix)
		$this->assertMatchesRegularExpression( '#^S3://test-bucket/[a-f0-9]{2}/#', $url );
	}

	public function testStoreReturnsfalseOnError() {
		$mockHandler = new MockHandler( [
			new Response( 500, [], 'Internal Server Error' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );
		$result = $store->store( 'test-bucket', 'data' );

		$this->assertFalse( $result );
	}

	public function testBatchFetchFromURLs() {
		$mockHandler = new MockHandler( [
			new Response( 200, [], 'Content 1' ),
			new Response( 200, [], 'Content 2' ),
			new Response( 404, [], 'Not Found' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );

		$urls = [
			'S3://bucket/key1',
			'S3://bucket/key2',
			'S3://bucket/key3',
		];

		$results = $store->batchFetchFromURLs( $urls );

		$this->assertCount( 2, $results );
		$this->assertSame( 'Content 1', $results['S3://bucket/key1'] );
		$this->assertSame( 'Content 2', $results['S3://bucket/key2'] );
		$this->assertArrayNotHasKey( 'S3://bucket/key3', $results );
	}

	public function testIsReadOnly() {
		$mockHandler = new MockHandler( [] );

		// Default is not read-only
		$store = $this->getStoreWithMockedClient( $mockHandler );
		$this->assertFalse( $store->isReadOnly( 'test-bucket' ) );

		// Explicit read-only setting
		$store = $this->getStoreWithMockedClient( $mockHandler, [ 'readOnly' => true ] );
		$this->assertTrue( $store->isReadOnly( 'test-bucket' ) );
	}

	public function testURLParsingCaseInsensitive() {
		$mockHandler = new MockHandler( [
			new Response( 200, [], 'Content' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );

		// Protocol should be case-insensitive
		$result = $store->fetchFromURL( 's3://test-bucket/key' );
		$this->assertSame( 'Content', $result );
	}

	/**
	 * @covers \ExternalStoreS3::store
	 */
	public function testStoreGeneratesUniqueKeys() {
		// Queue multiple successful responses
		$mockHandler = new MockHandler( [
			new Response( 200, [], '' ),
			new Response( 200, [], '' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );

		$url1 = $store->store( 'bucket', 'data1' );
		$url2 = $store->store( 'bucket', 'data2' );

		$this->assertNotSame( $url1, $url2, 'Different data should produce different URLs' );
	}

	/**
	 * @covers \ExternalStoreS3::store
	 */
	public function testStoreSameDataProducesSameHash() {
		$mockHandler = new MockHandler( [
			new Response( 200, [], '' ),
			new Response( 200, [], '' ),
		] );

		$store = $this->getStoreWithMockedClient( $mockHandler );
		$data = 'identical content';

		$url1 = $store->store( 'bucket', $data );
		$url2 = $store->store( 'bucket', $data );

		// Extract hash from URL (last component after the timestamp)
		preg_match( '#-([a-f0-9]+)$#', $url1, $matches1 );
		preg_match( '#-([a-f0-9]+)$#', $url2, $matches2 );

		$this->assertSame(
			$matches1[1],
			$matches2[1],
			'Same data should produce same hash in URL'
		);
	}

	public static function providePathStyleUrls() {
		yield 'path-style enabled' => [
			true,
			'http://localhost:9000',
			'test-bucket',
			'test-key',
			'http://localhost:9000/test-bucket/test-key',
		];
	}

	/**
	 * @dataProvider providePathStyleUrls
	 */
	public function testBuildObjectUrlPathStyle(
		bool $usePathStyle,
		string $endpoint,
		string $bucket,
		string $key,
		string $expectedUrl
	) {
		$store = new ExternalStoreS3( [
			'domain' => 'test',
			'endpoint' => $endpoint,
			'region' => 'us-east-1',
			'accessKey' => 'test',
			'secretKey' => 'test',
			'usePathStyle' => $usePathStyle,
		] );

		$reflection = new ReflectionClass( $store );
		$method = $reflection->getMethod( 'buildObjectUrl' );
		$method->setAccessible( true );

		$result = $method->invoke( $store, $bucket, $key );
		$this->assertSame( $expectedUrl, $result );
	}
}
