<?php

namespace {

	use CatPaw\MYSQL\Attribute\Repository;
	use CatPaw\MYSQL\Service\DatabaseService;
	use CatPaw\Web\Attribute\StartWebServer;
	use CatPaw\Web\Utility\Route;

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
				#[Repository("account")]
				$updateByLikeEmail
			) => $updateByLikeEmail(
				[
					"email" => "new@gmail.com",    //payload
				],
				[
					"email" => "my@gmail.com",    //lookup
				],
			)
		);

		echo Route::describe();
	}
}