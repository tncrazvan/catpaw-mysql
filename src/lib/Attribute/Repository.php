<?php

namespace CatPaw\MYSQL\Attribute;

use Amp\LazyPromise;
use Amp\Promise;
use App\Tools\Page;
use Attribute;
use CatPaw\Attributes\Entry;
use CatPaw\Attributes\Interfaces\AttributeInterface;
use CatPaw\Attributes\Traits\CoreAttributeDefinition;
use CatPaw\Misc\HttpContext;
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

	private function build(string $name): false|Closure {

		$stack = StringStack::of($name);

		$list = $stack->expect("findBy", "pageBy", "removeBy", "And", "Or");

		$page = false;
		$select = '';
		$clause = '';
		for($list->rewind(); $list->valid(); $list->next()) {
			[$prec, $token] = $list->current();
			if("removeBy" === $token) {
				$select = <<<SQL
				 delete from `$this->table`
				SQL;
				$page = false;
			} else if("pageBy" === $token) {
				$select = <<<SQL
				 select * from `$this->table`
				SQL;
				$page = true;
			} else if("findBy" === $token) {
				$select = <<<SQL
				 select * from `$this->table`
				SQL;
				$page = false;
			} else if($prec) {
				$operation = '=';
				if(str_starts_with($prec, "Between")) {
					$prec = substr($prec, 7);
					$operation = 'between';
				}else if(str_starts_with($prec, "Like")) {
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
				$select,
				$clause,
			) {
				$params = [];
				foreach($args as $key => $value)
					$params[strtolower($key)] = $value;

				return $this->db->send(
					query   : "$select $clause $page",
					params  : $params,
					poolName: $poolName
				);
			};
		}

		return function(array $args, false|string $poolName = false) use (
			$select,
			$clause,
		) {
			$params = [];
			foreach($args as $key => $value)
				$params[strtolower($key)] = $value;

			return $this->db->send(
				query   : "$select $clause",
				params  : $params,
				poolName: $poolName
			);
		};
	}

	private static array $cache = [];

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