<?php

namespace CatPaw\MySQL\Attributes;

use function Amp\call;
use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\MySQL\Services\DatabaseService;
use CatPaw\MySQL\Utilities\Page;
use CatPaw\Utilities\StringStack;
use CatPaw\Web\HttpContext;
use Closure;
use ReflectionParameter;

use ReflectionUnionType;

#[Attribute]
class Repository implements AttributeInterface {
    use CoreAttributeDefinition;

    /**
     * @param string $tableName table to query.
     */
    public function __construct(
        private string $tableName,
    ) {
        $this->tableName = strtolower($this->tableName);
    }

    private DatabaseService $db;

    #[Entry]
    public function main(DatabaseService $db) {
        $this->db = $db;
    }

    private const CREATE     = 0;
    private const READ       = 1;
    private const READ_PAGE  = 2;
    private const UPDATE     = 3;
    private const DELETE     = 4;
    private const READ_FIRST = 5;

    private function build(string $name): false|Closure {
        $base = '';
        // $selectOrDelete = '';
        $clause = '';
        $action = self::READ;
        if ("add" !== $name && "add".ucfirst($this->tableName) !== $name) {
            $stack = StringStack::of($name);

            $list = $stack->expect(
                "findBy",
                "findFirstBy",
                "findFirst".ucfirst($this->tableName)."By",
                "find".ucfirst($this->tableName)."By",
                "pageBy",
                "page".ucfirst($this->tableName)."By",
                "removeBy",
                "remove".ucfirst($this->tableName)."By",
                "updateBy",
                "update".ucfirst($this->tableName)."By",
                "And",
                "Or"
            );

            for ($list->rewind(); $list->valid(); $list->next()) {
                [$prec, $token] = $list->current();
                $token          = lcfirst($token);
                
                if ("removeBy" === $token || "remove".ucfirst($this->tableName)."By" === $token) {
                    $base = <<<SQL
                        delete from `$this->tableName`
                        SQL;
                    $action = self::DELETE;
                } else {
                    if ("pageBy" === $token || "page".ucfirst($this->tableName)."By" === $token) {
                        $base = <<<SQL
                            select * from `$this->tableName`
                            SQL;
                        $action = self::READ_PAGE;
                    } else {
                        if ("findBy" === $token || "find".ucfirst($this->tableName)."By" === $token) {
                            $base = <<<SQL
                                select * from `$this->tableName`
                                SQL;
                        // $page = false;
                        } else {
                            if ("findFirstBy" === $token || "findFirst".ucfirst($this->tableName)."By" === $token) {
                                $base = <<<SQL
                                    select * from `$this->tableName`
                                    SQL;
                                $action = self::READ_FIRST;
                            // $page = false;
                            } else {
                                if ("updateBy" === $token || "update".ucfirst($this->tableName)."By" === $token) {
                                    $base = <<<SQL
                                        update $this->tableName set
                                        SQL;
                                    $action = self::UPDATE;
                                } else {
                                    if ($prec) {
                                        $operation = '=';
                                        if (str_starts_with($prec, "Between")) {
                                            $prec      = substr($prec, 7);
                                            $operation = 'between';
                                        } else {
                                            if (str_starts_with($prec, "Like")) {
                                                $operation = 'like';
                                                $prec      = substr($prec, 4);
                                            }
                                        }

                                        $prec  = strtolower($prec);
                                        $where = ('' === $clause ? 'where' : '');
                                        $extra = strtolower($token ?? ''); 
                                        $clause .= <<<SQL
                                             $where `$prec` $operation :$prec $extra
                                            SQL;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $base   = "insert into $this->tableName";
            $action = self::CREATE;
        }

        if (self::UPDATE === $action && '' === $clause) {
            die("No clause set for update action on repository \"$this->tableName\".\n");
        } else {
            if (self::DELETE === $action && '' === $clause) {
                die("No clause set for delete action on repository \"$this->tableName\".\n");
            }
        }


        return match ($action) {
            self::READ, self::DELETE => function(array|object $args, false|string $poolName = false) use ($base, $clause) {
                $params = [];
                if (is_object($args)) {
                    $args = (array)$args;
                }
                foreach ($args as $key => $value) {
                    $params[strtolower($key)] = $value;
                }
                return $this->db->send(
                    query: "$base $clause",
                    params: $params,
                    poolName: $poolName
                );
            },
            self::READ_FIRST, => function(array|object $args, false|string $poolName = false) use ($base, $clause) {
                $params = [];
                if (is_object($args)) {
                    $args = (array)$args;
                }
                foreach ($args as $key => $value) {
                    $params[strtolower($key)] = $value;
                }
                return call(function() use ($base, $clause, $params, $poolName) {
                    $page      = Page::length(1);
                    $resultset = yield $this->db->send(
                        query: "$base $clause $page",
                        params: $params,
                        poolName: $poolName
                    );
                    return $resultset[0] ?? false;
                });
            },
            self::READ_PAGE => function(Page $page, array|object $args, false|string $poolName = false) use ($base, $clause) {
                $params = [];
                if (is_object($args)) {
                    $args = (array)$args;
                }
                foreach ($args as $key => $value) {
                    $params[strtolower($key)] = $value;
                }
                return $this->db->send(
                    query: "$base $clause $page",
                    params: $params,
                    poolName: $poolName
                );
            },
            self::UPDATE => function(
                array|object $payload,
                array|object $matcher,
                false|string $poolName = false
            ) use ($base, $clause) {
                $params = [];
                if (is_object($payload)) {
                    $payload = (array)$payload;
                }
                if (is_object($matcher)) {
                    $matcher = (array)$matcher;
                }

                $list = [];
                foreach ($payload as $key => $value) {
                    $key             = strtolower($key);
                    $list[]          = "$key = :v$key";
                    $params["v$key"] = $value;
                }

                foreach ($matcher as $key => $value) {
                    $params[$key] = strtolower($value);
                }

                $base .= ' '.join(',', $list);

                return $this->db->send(
                    query: "$base $clause",
                    params: $params,
                    poolName: $poolName
                );
            },
            self::CREATE => function(array|object $args, false|string $poolName = false) use ($base, $clause) {
                $params = [];
                if (is_object($args)) {
                    $args = (array)$args;
                }
                $groups = [];
                foreach ($args as $key => $value) {
                    $key          = strtolower($key);
                    $params[$key] = $value;
                    $groups[]     = $key;
                }
                $base .= '('.join(',', $groups).') values (:'.join(',:', $groups).')';

                return $this->db->send(
                    query: "$base $clause",
                    params: $params,
                    poolName: $poolName
                );
            },
            default => die("Invalid action (\"$name\") on repository \"$this->tableName\".")
        };
    }

    private
    static array $cache = [];

    /**
     * @inheritDoc
     */
    public function onParameter(ReflectionParameter $parameter, mixed &$value, mixed $http): Promise {
        /** @var false|HttpContext $http */
        return new LazyPromise(function() use (
            $parameter,
            &$value,
            $http
        ) {
            $name = $parameter->getName();
            if (!isset(self::$cache["$this->tableName:$name"])) {
                $type = $parameter->getType();
                if (null !== $type) {
                    if ($type instanceof ReflectionUnionType) {
                        $type = $type->getTypes()[0];
                    }
                    if (Closure::class !== $type->getName()) {
                        die("Repository action must either specify no type or a Closure type.");
                    }
                }
                self::$cache["$this->tableName:$name"] = $this->build($name);
            }
            $value = self::$cache["$this->tableName:$name"];
        });
    }
}
