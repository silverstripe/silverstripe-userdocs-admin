---
title: Index storage
summary: Store the search index in Redis or MySQL
---

# Index storage

By default, the search index is stored in SQLite files in your temporary folder where Silverstripe CMS cache files are stored.

You can store the index in Redis or MySQL instead. Set the TNTSearch engine and the connection details in the injector configuration. You can give the connection details as separate configuration values, or as a single DSN. Use environment variables for the connection details, so that credentials are not stored in your codebase.

A DSN can only be used with the engine it is for. If you set `redis_dsn` or `mysql_dsn` with a different engine, an exception is thrown.

## Redis

To use Redis, install the `predis/predis` package and set the engine to `RedisEngine`. The `redis_host` and `redis_port` configuration values are required.

```yml
SilverStripe\Core\Injector\Injector:
  TeamTNT\TNTSearch\TNTSearch.userdocs:
    constructor:
      config:
        engine: 'TeamTNT\TNTSearch\Engines\RedisEngine'
        redis_host: '`SS_USERDOCS_REDIS_HOST`'
        redis_port: '`SS_USERDOCS_REDIS_PORT`'
        redis_password: '`SS_USERDOCS_REDIS_PASSWORD`'
```

These configuration values are available:

- `redis_host`: The host name or IP address of the Redis server.
- `redis_port`: The port of the Redis server.
- `redis_scheme`: The connection scheme, such as `tcp` or `tls`. The default is `tcp`.
- `redis_password`: The password for the Redis server.
- `redis_ssl_options`: An array of [SSL context options](https://www.php.net/manual/en/context.ssl.php) for `tls` connections.
- `redis_options`: An array of [Predis client options](https://github.com/predis/predis#client-configuration), such as a key `prefix`.

Instead of the separate connection values, you can set `redis_dsn`. The DSN can use any format that Symfony supports (see [configure the connection](https://symfony.com/doc/current/components/cache/adapters/redis_adapter.html#configure-the-connection)). This lets you use connections that the separate values do not support, such as a database number, a unix socket, Redis Sentinel, or a Redis cluster.

```yml
SilverStripe\Core\Injector\Injector:
  TeamTNT\TNTSearch\TNTSearch.userdocs:
    constructor:
      config:
        engine: 'TeamTNT\TNTSearch\Engines\RedisEngine'
        redis_dsn: '`SS_USERDOCS_REDIS_DSN`'
```

```bash
SS_USERDOCS_REDIS_DSN="redis://password@redis.example.com:6379/1"
```

When `redis_dsn` is set, `redis_options` is used, and the other `redis_*` configuration values are ignored.

## MySQL

To use MySQL or MariaDB, make sure the `pdo_mysql` PHP extension is installed and set the engine to `MysqlEngine`.

```yml
SilverStripe\Core\Injector\Injector:
  TeamTNT\TNTSearch\TNTSearch.userdocs:
    constructor:
      config:
        engine: 'TeamTNT\TNTSearch\Engines\MysqlEngine'
        host: '`SS_USERDOCS_MYSQL_HOST`'
        database: '`SS_USERDOCS_MYSQL_DATABASE`'
        username: '`SS_USERDOCS_MYSQL_USERNAME`'
        password: '`SS_USERDOCS_MYSQL_PASSWORD`'
```

`MysqlEngine` does not use a port configuration value, so it always connects to the default port. To connect to a different port, use `mysql_dsn`.

Instead of the separate connection values, you can set `mysql_dsn` in the format `mysql://username:password@host:port/database`. The scheme can be `mysql` or `mariadb`, and the port is optional.

```yml
SilverStripe\Core\Injector\Injector:
  TeamTNT\TNTSearch\TNTSearch.userdocs:
    constructor:
      config:
        engine: 'TeamTNT\TNTSearch\Engines\MysqlEngine'
        mysql_dsn: '`SS_USERDOCS_MYSQL_DSN`'
```

```bash
SS_USERDOCS_MYSQL_DSN="mysql://username:password@db.example.com:3306/userdocs"
```

The username and password are optional in `mysql_dsn`. If they are not in the DSN, the `username` and `password` configuration values are used. The DSN cannot have a query string, because the engine cannot use the options in it.

> [!NOTE]
> If the username, password, or database name has special characters such as `@`, `:`, `/`, or `#`, you must URL-encode them in the DSN. For example, the password `p@ss` becomes `p%40ss`.
