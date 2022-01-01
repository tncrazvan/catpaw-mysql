<?php

namespace CatPaw\MYSQL\Attribute;

use Amp\LazyPromise;
use Amp\Promise;
use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Misc\HttpContext;
use CatPaw\MYSQL\Tools\Page;
use CatPaw\Tools\StringStack;
use Closure;
use CatPaw\MYSQL\Service\DatabaseService;
use ReflectionParameter;
use ReflectionUnionType;

#[Attribute]
class Repository implements AttributeInterface {
	use CoreAttributeDefinition;

	/**
	 * @param string $table table to query.
	 */
	public function __construct(
		private string $table,
	) {
		$this->table = strtolower($this->table);
	}

	private DatabaseService $db;

	#[Entry]
	public function main(DatabaseService $db) {
		$this->db = $db;
	}

	private function buildUpdate(): Closure {
		return function(array|object $args, false|string $poolName = false) {
			$params = [];
			$keys = [];
			foreach($args as $key => $value) {
				$params[strtolower($key)] = $value;
				$keys[] = strtolower($key);
			}

			$columns = join(",", $keys);
			$values = join(",:", $keys);

			return $this->db->send(
				query   : <<<SQL
				insert into $this->table ($columns) values(:$values)
				SQL,
				params  : is_object($params) ? (array)$params : $params,
				poolName: $poolName
			);
		};
	}

	private function build(string $name): false|Closure {

		$page = false;
		$selectOrDelete = '';
		$updateOrInsert = '';
		$clause = '';
		$adding = "add" === $name;

		if(!$adding) {
			$stack = StringStack::of($name);

			$list = $stack->expect("findBy", "pageBy", "removeBy", "updateBy", "And", "Or");

			for($list->rewind(); $list->valid(); $list->next()) {
				[$prec, $token] = $list->current();
				$token = lcfirst($token);
				if("removeBy" === $token) {
					$selectOrDelete = <<<SQL
					delete from `$this->table`
					SQL;
					$page = false;
				} else if("pageBy" === $token) {
					$selectOrDelete = <<<SQL
					select * from `$this->table`
					SQL;
					$page = true;
				} else if("findBy" === $token) {
					$selectOrDelete = <<<SQL
					select * from `$this->table`
					SQL;
					$page = false;
				} else if("updateBy" === $token) {
					$updateOrInsert = <<<SQL
					update $this->table set
					SQL;
					$page = false;
				} else if($prec) {
					$operation = '=';
					if(str_starts_with($prec, "Between")) {
						$prec = substr($prec, 7);
						$operation = 'between';
					} else if(str_starts_with($prec, "Like")) {
						$operation = 'like';
						$prec = substr($prec, 4);
					}
					$prec = strtolower($prec);
					$where = ('' === $clause ? 'where' : '');
					$extra = strtolower($token??'');
					$clause .= <<<SQL
					 $where `$prec` $operation :$prec $extra
					SQL;
				}
			}
			if($page) {
				return function(Page $page, array $args, false|string $poolName = false) use (
					$selectOrDelete,
					$clause,
				) {
					$params = [];
					if(is_object($args))
						$args = (array)$args;
					foreach($args as $key => $value)
						$params[strtolower($key)] = $value;

					return $this->db->send(
						query   : "$selectOrDelete $clause $page",
						params  : $params,
						poolName: $poolName
					);
				};
			}
		} else {
			$updateOrInsert = "insert into $this->table";
		}

		return function(array|object $args, false|string $poolName = false) use (
			$updateOrInsert,
			$selectOrDelete,
			$adding,
			$clause,
		) {
			$params = [];
			if(is_object($args))
				$args = (array)$args;

			if($updateOrInsert) {
				$groups = [];
				foreach($args as $key => $value) {
					$key = strtolower($key);
					$params[$key] = $value;
					if($adding)
						$groups[] = $key;
					else
						$groups[] = "$key = :$key";
				}

				if($adding)
					$updateOrInsert .= '('.join(',', $groups).') values (:'.join(',:', $groups).')';
				else
					$updateOrInsert .= ' '.join(',', $groups);

				return $this->db->send(
					query   : "$updateOrInsert $clause",
					params  : $params,
					poolName: $poolName
				);
			}

			foreach($args as $key => $value)
				$params[strtolower($key)] = $value;

			return $this->db->send(
				query   : "$selectOrDelete $clause",
				params  : $params,
				poolName: $poolName
			);
		};
	}

	private
	static array $cache = [];

	/**
	 * @inheritDoc
	 */
	public function onParameter(ReflectionParameter $parameter, mixed &$value, HttpContext|false $http): Promise {
		return new LazyPromise(function() use (
			$parameter,
			&$value,
			$http
		) {
			$name = $parameter->getName();
			if(!isset(self::$cache["$this->table:$name"])) {
				$type = $parameter->getType();
				if($type !== null) {
					if($type instanceof ReflectionUnionType)
						$type = $type->getTypes()[0];
					if(Closure::class !== $type->getName())
						die("Repository action must either specify no type or a Closure type.");
				}
				self::$cache["$this->table:$name"] = $this->build($name);
			}
			$value = self::$cache["$this->table:$name"];
		});
	}
}