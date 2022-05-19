<?php

namespace CatPaw\MYSQL\Utilities;

class Page {
    private function __construct(
        private string $limit
    ) {
    }

    public function __toString(): string {
        return $this->limit;
    }

    public static function of(
        int $offset,
        int $length
    ): self {
        return new Page("limit $offset, $length");
    }

    public static function length(
        int $length
    ): self {
        return new Page("limit $length");
    }
}