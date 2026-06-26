<?php

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Sets `busy_timeout` on every fresh SQLite connection opened by Doctrine
 * for the app DB (`var/data.db`).
 *
 * Without this, two writers racing for the DB — typically the Symfony
 * Messenger worker handling a sync / rematch while the web container
 * also writes (a Messenger dispatch from a controller, a flush from a
 * Twig request) — fail immediately with `SQLSTATE[HY000]: General
 * error: 5 database is locked`. SQLite normally returns SQLITE_BUSY
 * the moment a write lock is contended; `busy_timeout` makes the
 * blocked caller poll for the lock for up to N ms instead.
 *
 * 30000 ms mirrors the Navidrome read connection (see
 * {@see \App\Navidrome\NavidromeRepository::connection()}) — chosen
 * empirically to cover the largest batches the matcher cascade
 * commits without flagging genuine deadlocks too late.
 *
 * Registered via the `doctrine.middleware` tag on every DBAL
 * connection ({@see config/services.yaml}). The PRAGMA is harmless
 * on non-SQLite drivers (the statement just errors and is ignored),
 * but in practice every connection in this project is SQLite.
 */
final class SqliteBusyTimeoutMiddleware implements Middleware
{
    public function __construct(
        private readonly int $busyTimeoutMs = 30000,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->busyTimeoutMs) extends AbstractDriverMiddleware {
            public function __construct(Driver $wrappedDriver, private readonly int $busyTimeoutMs)
            {
                parent::__construct($wrappedDriver);
            }

            #[\Override]
            public function connect(
                #[\SensitiveParameter] array $params,
            ): Connection {
                $connection = parent::connect($params);
                // PRAGMA is no-op on connections opened in immutable
                // read-only mode (the Navidrome connection sets that
                // path itself), so calling it unconditionally is safe.
                $connection->exec(sprintf('PRAGMA busy_timeout = %d', $this->busyTimeoutMs));

                return $connection;
            }
        };
    }
}
