	public function __construct( array $params ) {
		$params['redisConfig']['serializer'] = 'none'; // make it easy to use Lua
		$this->server = $params['redisServer'];
		$this->compression = $params['compression'] ?? 'none';
		$this->redisPool = RedisConnectionPool::singleton( $params['redisConfig'] );
		if ( !isset( $params['daemonized'] ) ) {
			throw new InvalidArgumentException(
				"Non-daemonized mode is no longer supported. Please install " .
				"mediawiki/services/jobrunner service and update \$wgJobTypeConf as needed." );
		}
		$this->logger = LoggerFactory::getInstance( 'redis' );
	}
