	public function __construct( array $params ) {
		error_log( "JobQueueRedis CONSTRUCT - daemonized check: " . var_export($params['daemonized'] ?? 'NOT SET') . ", value is " . var_export(empty($params['daemonized'])) );
		error_log( "JobQueueRedis CONSTRUCT - all params: " . var_export($params) );

		$params['redisConfig']['serializer'] = 'none'; // make it easy to use Lua
		$this->server = $params['redisServer'];
		$this->compression = $params['compression'] ?? 'none';
		$this->redisPool = RedisConnectionPool::singleton( $params['redisConfig'] );
		if ( empty( $params['daemonized'] ) ) {
			throw new InvalidArgumentException(
				"Non-daemonized mode is no longer supported. Please install " .
				"mediawiki/services/jobrunner service and update \$wgJobTypeConf as needed." );
		}
		$this->logger = LoggerFactory::getInstance( 'redis' );
	}
