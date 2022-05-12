<?php

namespace CatPaw\MYSQL\Utility;

use JetBrains\PhpStorm\Pure;

class Page {
	private function __construct(
		private string $limit
	) {
	}

	public function __toString(): string {
		return $this->limit;
	}

	#[Pure] public static function of(
		int $offset, int $length
	): self {
		return new Page("limit $offset, $length");
	}

	#[Pure] public static function length(
		int $length
	): self {
		return new Page("limit $length");
	}
}