<?php

namespace CatPaw\MYSQL\Services;

use Amp\LazyPromise;
use Amp\Mysql\CommandResult;
use Amp\Mysql\ConnectionConfig;
use Amp\Mysql\Pool;
use function Amp\Mysql\pool;
use Amp\Mysql\ResultSet;
use Amp\Mysql\Statement;
use Amp\Promise;
use CatPaw\Attributes\Service;
use CatPaw\MYSQL\Exceptions\PoolNotFoundException;

#[Service]
class DatabaseService {
    private array $cache = [];

    private false|string $defaultPoolName = false;

    public function getDefaultPoolName(): false|string {
        return $this->defaultPoolName;
    }

    /**
     * @throws PoolNotFoundException
     */
    public function setDefaultPoolName(string $poolName): void {
        if (!isset($this->cache[$poolName])) {
            throw new PoolNotFoundException("Pool $poolName not found.");
        }
        $this->defaultPoolName = $poolName;
    }

    public function issetPool(string $name): bool {
        return isset($this->cache[$name]);
    }

    public function getPool(string $name): false|Pool {
        return $this->cache[$name] ?? false;
    }


    /**
     * Creates a new pool.<br/>
     * If no default pool has been set, this pool will become the default.
     * @param  string $poolName
     * @param  string $host
     * @param  string $user
     * @param  string $password
     * @param  string $database
     * @return Pool
     */
    public function setPool(string $poolName, string $host, string $user, string $password, string $database = ''): Pool {
        if (!isset($this->cache[$poolName])) {
            $config = ConnectionConfig::fromString(
				"host=$host user=$user password=$password db=$database"
			);
            $this->cache[$poolName] = pool($config);
        }

        if (!$this->defaultPoolName) {
            $this->defaultPoolName = $poolName;
        }

        return $this->cache[$poolName];
    }

    /**
     * Executes a prepared statement using named parameters.
     * @param string $query  query to execute.
     * @param array  $params named parameters.<br/>
     *                       Example: "... where col1 like :value1 and col2 like :value2".
     * @param false|string name of the pool.<br/>
     * If false, will use the default pool.
     * @throws PoolNotFoundException
     * @return Promise<array|int|bool>
     *                                 <ul>
     *                                 <li>
     *                                 array of selected rows
     *                                 </li>
     *                                 <li>
     *                                 integer of the last inserted id
     *                                 </li>
     *                                 <li>
     *                                 bool when executing a command (update, delete, insert that does not return an auto incremented id).
     *                                 </li>
     *                                 </ul>
     */
    public function send(string $query, array $params = [], false|string $poolName = false): Promise {
        if (!$poolName) {
            $poolName = $this->defaultPoolName;
        }

        if (!$poolName) {
            throw new PoolNotFoundException("No default pool found. Please consider using \"\$databaseService->setDefaultPool\".");
        }

        return new LazyPromise(function() use ($query, $params, $poolName) {
            /** @var Statement $statement */
            $statement = yield $this->cache[$poolName]->prepare($query);
            $result = yield $statement->execute($params);
            if ($result instanceof CommandResult) {
                $id = $result->getLastInsertId();
                if ($id > 0) {
                    return $id;
                }

                return $result->getAffectedRowCount() > 0;
            }

            /** @var ResultSet $result */
            if (!$result) {
                return false;
            }
            $rows = [];
            while (yield $result->advance()) {
                $rows[] = $result->getCurrent();
            }

            return $rows;
        });
    }
}