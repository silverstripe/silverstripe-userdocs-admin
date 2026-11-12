<?php

namespace SilverStripe\UserDocs\Tests\Search;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client as PredisClient;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Search\TntSearchFactory;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Engines\RedisEngine;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\TNTSearch;

class TntSearchFactoryTest extends SapphireTest
{
    private function createTntSearch(array $config): TNTSearch
    {
        // Storage is only used to load the config. Nothing is written to it.
        $config['storage'] ??= Path::join(TEMP_PATH, 'userdocs-tntsearchfactory-test');
        return (new TntSearchFactory())->create(TNTSearch::class, ['config' => $config]);
    }

    private function skipIfNoPredis(): void
    {
        if (!class_exists(PredisClient::class)) {
            $this->markTestSkipped('The predis/predis package is not installed.');
        }
    }

    public static function provideCreateRedisDsn(): array
    {
        return [
            'tcp' => [
                'dsn' => 'redis://secret@redis.example.com:1234/3',
                'expectedScheme' => 'tcp',
                'expectedPort' => 1234,
            ],
            'tls' => [
                'dsn' => 'rediss://redis.example.com',
                'expectedScheme' => 'tls',
                'expectedPort' => 6379,
            ],
        ];
    }

    #[DataProvider('provideCreateRedisDsn')]
    public function testCreateRedisDsn(string $dsn, string $expectedScheme, int $expectedPort): void
    {
        $this->skipIfNoPredis();
        $tnt = $this->createTntSearch([
            'engine' => RedisEngine::class,
            'redis_dsn' => $dsn,
            'redis_scheme' => 'unix',
            'redis_host' => 'ignored.example.com',
            'redis_port' => 9999,
            'redis_password' => 'ignored',
            'redis_ssl_options' => ['verify_peer' => false],
        ]);
        $parameters = $tnt->engine->redis->getConnection()->getParameters();
        $this->assertSame('redis.example.com', $parameters->host);
        $this->assertSame($expectedPort, $parameters->port);
        $expectedConfig = [
            'redis_scheme' => $expectedScheme,
            'redis_host' => 'redis.example.com',
            'redis_port' => $expectedPort,
        ];
        foreach ([$tnt->config, $tnt->engine->config] as $config) {
            $this->assertEquals($expectedConfig, array_intersect_key($config, $expectedConfig));
            $this->assertArrayNotHasKey('redis_dsn', $config);
            $this->assertArrayNotHasKey('redis_password', $config);
            $this->assertArrayNotHasKey('redis_ssl_options', $config);
        }
    }

    public static function provideCreateRedisDsnNoSingleHost(): array
    {
        return [
            'unix socket' => [
                'dsn' => 'redis:///var/run/redis.sock',
            ],
            'cluster' => [
                'dsn' => 'redis:?host[redis1.example.com:6379]&host[redis2.example.com:6380]&redis_cluster=1',
            ],
        ];
    }

    #[DataProvider('provideCreateRedisDsnNoSingleHost')]
    public function testCreateRedisDsnNoSingleHost(string $dsn): void
    {
        $this->skipIfNoPredis();
        $tnt = $this->createTntSearch([
            'engine' => RedisEngine::class,
            'redis_dsn' => $dsn,
            'redis_scheme' => 'tcp',
            'redis_host' => 'ignored.example.com',
            'redis_port' => 9999,
        ]);
        foreach ([$tnt->config, $tnt->engine->config] as $config) {
            $this->assertArrayNotHasKey('redis_scheme', $config);
            $this->assertArrayNotHasKey('redis_host', $config);
            $this->assertArrayNotHasKey('redis_port', $config);
        }
    }

    public function testCreateRedisDsnUsesRedisOptions(): void
    {
        $this->skipIfNoPredis();
        $tnt = $this->createTntSearch([
            'engine' => RedisEngine::class,
            'redis_dsn' => 'redis://redis.example.com',
            'redis_options' => ['prefix' => 'docs:'],
        ]);
        $options = $tnt->engine->redis->getOptions();
        $this->assertSame('docs:', $options->prefix->getPrefix());
        $this->assertTrue($options->exceptions);
    }

    public static function provideCreateMysqlDsn(): array
    {
        return [
            'all parts' => [
                'dsn' => 'mysql://user:pass@db.example.com/docs',
                'config' => [],
                'expected' => [
                    'host' => 'db.example.com',
                    'database' => 'docs',
                    'username' => 'user',
                    'password' => 'pass',
                ],
            ],
            'port added to host' => [
                'dsn' => 'mysql://user:pass@db.example.com:3307/docs',
                'config' => [],
                'expected' => [
                    'host' => 'db.example.com;port=3307',
                    'database' => 'docs',
                    'username' => 'user',
                    'password' => 'pass',
                ],
            ],
            'url-encoded values' => [
                'dsn' => 'mysql://us%40er:p%40ss%3Bw%2Ford@127.0.0.1/my%20docs',
                'config' => [],
                'expected' => [
                    'host' => '127.0.0.1',
                    'database' => 'my docs',
                    'username' => 'us@er',
                    'password' => 'p@ss;w/ord',
                ],
            ],
            'ipv6 host' => [
                'dsn' => 'mariadb://user@[::1]:3306/docs',
                'config' => [],
                'expected' => [
                    'host' => '::1;port=3306',
                    'database' => 'docs',
                    'username' => 'user',
                    'password' => null,
                ],
            ],
            'dsn overrides config' => [
                'dsn' => 'MySQL://user:pass@localhost/docs',
                'config' => [
                    'host' => 'ignored.example.com',
                    'database' => 'ignored',
                    'username' => 'ignored',
                    'password' => 'ignored',
                ],
                'expected' => [
                    'host' => 'localhost',
                    'database' => 'docs',
                    'username' => 'user',
                    'password' => 'pass',
                ],
            ],
            'config credentials kept if dsn has none' => [
                'dsn' => 'mysql://localhost/docs',
                'config' => [
                    'username' => 'configuser',
                    'password' => 'configpass',
                ],
                'expected' => [
                    'host' => 'localhost',
                    'database' => 'docs',
                    'username' => 'configuser',
                    'password' => 'configpass',
                ],
            ],
        ];
    }

    #[DataProvider('provideCreateMysqlDsn')]
    public function testCreateMysqlDsn(string $dsn, array $config, array $expected): void
    {
        $tnt = $this->createTntSearch(array_merge($config, [
            'engine' => MysqlEngine::class,
            'mysql_dsn' => $dsn,
        ]));
        $engineConfig = $tnt->engine->config;
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $engineConfig[$key] ?? null, "Config key $key");
        }
        $this->assertArrayNotHasKey('mysql_dsn', $engineConfig);
    }

    public static function provideCreateMysqlDsnInvalid(): array
    {
        return [
            'empty string' => ['dsn' => ''],
            'not a string' => ['dsn' => ['mysql://localhost/docs']],
            'malformed' => ['dsn' => 'mysql://:0'],
            'wrong scheme' => ['dsn' => 'pgsql://localhost/docs'],
            'no scheme' => ['dsn' => 'localhost/docs'],
            'no host' => ['dsn' => 'mysql:///docs'],
            'no database' => ['dsn' => 'mysql://localhost'],
            'empty database' => ['dsn' => 'mysql://localhost/'],
            'extra slash before database' => ['dsn' => 'mysql://localhost//docs'],
            'query string' => ['dsn' => 'mysql://localhost/docs?charset=utf8mb4'],
            'fragment' => ['dsn' => 'mysql://localhost/docs#frag'],
            'semicolon in host' => ['dsn' => 'mysql://localhost%3Bunix_socket=x/docs'],
            'invalid host' => ['dsn' => 'mysql://local_host!/docs'],
            'invalid ipv6 host' => ['dsn' => 'mysql://[not-ipv6]/docs'],
            'semicolon in database' => ['dsn' => 'mysql://localhost/docs%3Bunix_socket%3D%2Ftmp%2Fx'],
            'equals in database' => ['dsn' => 'mysql://localhost/docs%3Dx'],
            'slash in database' => ['dsn' => 'mysql://localhost/docs/more'],
            'control character in database' => ['dsn' => 'mysql://localhost/docs%00'],
        ];
    }

    #[DataProvider('provideCreateMysqlDsnInvalid')]
    public function testCreateMysqlDsnInvalid(mixed $dsn): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createTntSearch([
            'engine' => MysqlEngine::class,
            'mysql_dsn' => $dsn,
        ]);
    }

    public static function provideCreateDsnWrongEngine(): array
    {
        return [
            'redis dsn with default engine' => [
                'config' => ['redis_dsn' => 'redis://localhost'],
            ],
            'redis dsn with mysql engine' => [
                'config' => ['engine' => MysqlEngine::class, 'redis_dsn' => 'redis://localhost'],
            ],
            'mysql dsn with sqlite engine' => [
                'config' => ['engine' => SqliteEngine::class, 'mysql_dsn' => 'mysql://localhost/docs'],
            ],
            'mysql dsn with redis engine' => [
                'config' => ['engine' => RedisEngine::class, 'mysql_dsn' => 'mysql://localhost/docs'],
            ],
        ];
    }

    #[DataProvider('provideCreateDsnWrongEngine')]
    public function testCreateDsnWrongEngine(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be used with the .+ engine/');
        $this->createTntSearch($config);
    }
}
