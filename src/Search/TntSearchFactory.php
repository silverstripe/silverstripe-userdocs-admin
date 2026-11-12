<?php

namespace SilverStripe\UserDocs\Search;

use InvalidArgumentException;
use Predis\Client as PredisClient;
use Predis\Connection\ConnectionInterface;
use Predis\Connection\NodeConnectionInterface;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Path;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Filesystem\Path as SymfonyPath;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Engines\RedisEngine;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\TNTSearch;

/**
 * Creates a TNTSearch instance with appropriate configuration
 *
 * In addition to the TNTSearch configuration, the config can have these keys:
 * - redis_dsn: A DSN for RedisEngine, in any format Symfony's RedisAdapter supports.
 *   When set, the redis_options key is used. The redis_scheme, redis_host, and redis_port keys are set from the DSN
 *   if it is for a single Redis server over TCP or TLS, and removed if not. The other redis_* keys are removed.
 * - mysql_dsn: A DSN for MysqlEngine in the format mysql://user:password@host:port/database.
 *   When set, it replaces the host and database keys, and the username and password keys if the DSN has them.
 */
class TntSearchFactory implements Factory
{
    private const array MYSQL_DSN_SCHEMES = ['mysql', 'mariadb'];

    /**
     * Create a TNTSearch object with appropriate configuration
     *
     * @param array $params Must have a "config" key with an array of TnTsearch configuration
     */
    public function create(string $service, array $params = []): TNTSearch
    {
        $config = $params['config'];
        // Relative storage paths are relative to the cache dir
        if (!empty($config['storage']) && SymfonyPath::isRelative($config['storage'])) {
            // Use framework's Path class here to avoid path traversal
            $config['storage'] = Path::join(TEMP_PATH, $config['storage']);
        }
        $redisDsn = $this->getDsn($config, 'redis_dsn', RedisEngine::class);
        $mysqlDsn = $this->getDsn($config, 'mysql_dsn', MysqlEngine::class);
        unset($config['redis_dsn'], $config['mysql_dsn']);
        $redisConnection = null;
        $redisNodeConfig = null;
        if ($redisDsn !== null) {
            // The DSN replaces these keys. Remove them so the config doesn't have values that aren't used.
            unset($config['redis_scheme'], $config['redis_password'], $config['redis_ssl_options']);
            // Without Predis, RedisEngine throws the error that SearchIndex::isSupported() checks for.
            if (class_exists(PredisClient::class)) {
                $redisConnection = $this->createRedisConnection($redisDsn);
                $redisNodeConfig = $this->getRedisNodeConfig($redisConnection);
            }
            // RedisEngine needs a host and port to load its config. Use placeholders if the DSN has no single host.
            // RedisEngine's own client never connects, because the client made from the DSN replaces it.
            $config = array_merge($config, $redisNodeConfig ?? ['redis_host' => '127.0.0.1', 'redis_port' => 6379]);
        }
        if ($mysqlDsn !== null) {
            $config = array_merge($config, $this->parseMysqlDsn($mysqlDsn));
        }
        $tnt = new TNTSearch();
        $tnt->loadConfig($config);
        if ($redisConnection !== null) {
            $tnt->engine->redis = new PredisClient($redisConnection, $config['redis_options'] ?? null);
            if ($redisNodeConfig === null) {
                // Remove the placeholders, so nothing reads an incorrect host and port.
                unset($tnt->config['redis_host'], $tnt->config['redis_port']);
                unset($tnt->engine->config['redis_host'], $tnt->engine->config['redis_port']);
            }
        }
        return $tnt;
    }

    /**
     * Create a Predis connection from a DSN in any format Symfony's RedisAdapter supports.
     */
    private function createRedisConnection(string $dsn): ConnectionInterface
    {
        // RedisEngine::$redis must be a Predis client, so don't let Symfony select the redis extension.
        // Symfony's client does not throw exceptions for Redis errors, and it does not use redis_options.
        // Only its connection is used, in a client that has the same options as the one RedisEngine makes.
        return RedisAdapter::createConnection($dsn, ['class' => PredisClient::class])->getConnection();
    }

    /**
     * Get the RedisEngine config keys for a connection to a single Redis server over TCP or TLS.
     * Returns null for other connections, such as clusters and unix sockets, which have no single host and port.
     */
    private function getRedisNodeConfig(ConnectionInterface $connection): ?array
    {
        if (!$connection instanceof NodeConnectionInterface) {
            return null;
        }
        $parameters = $connection->getParameters();
        if (!in_array($parameters->scheme, ['tcp', 'tls'], true)) {
            return null;
        }
        return [
            'redis_scheme' => $parameters->scheme,
            'redis_host' => $parameters->host,
            'redis_port' => $parameters->port,
        ];
    }

    /**
     * Get a DSN from the config, and validate that it applies to the configured engine.
     * Returns null if the DSN is not set.
     */
    private function getDsn(array $config, string $key, string $engineClass): ?string
    {
        $dsn = $config[$key] ?? null;
        if ($dsn === null) {
            return null;
        }
        if (!is_string($dsn) || $dsn === '') {
            throw new InvalidArgumentException("The $key config value must be a non-empty string.");
        }
        $engine = $config['engine'] ?? SqliteEngine::class;
        if (!is_string($engine) || !is_a($engine, $engineClass, true)) {
            $engineName = is_string($engine) ? $engine : get_debug_type($engine);
            throw new InvalidArgumentException("The $key config value cannot be used with the $engineName engine.");
        }
        return $dsn;
    }

    /**
     * Parse a MySQL DSN into the config keys that MysqlEngine uses.
     * Error messages do not include the DSN, because it can contain a password.
     */
    private function parseMysqlDsn(string $dsn): array
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new InvalidArgumentException('The mysql_dsn config value is not a valid DSN.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, TntSearchFactory::MYSQL_DSN_SCHEMES, true)) {
            throw new InvalidArgumentException(sprintf(
                'The mysql_dsn config value must use one of these schemes: %s.',
                implode(', ', TntSearchFactory::MYSQL_DSN_SCHEMES)
            ));
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(
                'The mysql_dsn config value cannot have a query string or fragment. MysqlEngine cannot use them.'
            );
        }
        $host = $this->validateMysqlHost($parts['host'] ?? '');
        $database = rawurldecode(substr($parts['path'] ?? '', 1));
        if ($database === '' || preg_match('#[;=/\\\\\x00-\x1F\x7F]#', $database)) {
            throw new InvalidArgumentException('The mysql_dsn config value must have a valid database name.');
        }
        if (isset($parts['port'])) {
            if ($parts['port'] < 1 || $parts['port'] > 65535) {
                throw new InvalidArgumentException('The mysql_dsn config value has an invalid port.');
            }
            // MysqlEngine::selectIndex() makes its PDO DSN as "mysql:dbname=<database>;host=<host>"
            // and does not use a port. Adding ";port=<port>" to the host puts the port in that PDO DSN.
            // This is safe only because the host is validated and cannot contain ";" or "=".
            $host .= ';port=' . $parts['port'];
        }
        $result = [
            'host' => $host,
            'database' => $database,
        ];
        // Keep the username and password from the config if the DSN doesn't have them
        if (isset($parts['user'])) {
            $result['username'] = rawurldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $result['password'] = rawurldecode($parts['pass']);
        }
        return $result;
    }

    /**
     * Validate a host from a MySQL DSN so it is safe to add to a PDO DSN.
     * Returns IPv6 addresses without the square brackets.
     */
    private function validateMysqlHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipv6 = substr($host, 1, -1);
            if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return $ipv6;
            }
        } elseif ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
            return $host;
        }
        throw new InvalidArgumentException('The mysql_dsn config value must have a valid host.');
    }
}
