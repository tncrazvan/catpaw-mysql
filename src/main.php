<?php

namespace {

	use CatPaw\Attributes\StartWebServer;
	use CatPaw\MYSQL\Attribute\Repository;
	use CatPaw\MYSQL\Service\DatabaseService;
	use CatPaw\Tools\Helpers\Route;

	#[StartWebServer]
	function main(
		DatabaseService $db
	) {
		$db->setPool(
			poolName: "main",
			host    : "127.0.0.1",
			user    : "razvan",
			password: "razvan",
			database: "genericstore"
		);

		Route::get(
			path    : "/",
			callback: fn(
				#[Repository("account")] $findByLikeEmail
			) => $findByLikeEmail([
								  "email" => "%@gmail%",
							  ])
		);

		echo Route::describe();
	}
}