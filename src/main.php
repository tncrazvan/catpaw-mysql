<?php

namespace {

	use CatPaw\Attributes\StartWebServer;
	use CatPaw\Tools\Helpers\Route;
	use Razshare\CatPaw\MYSQL\Attributes\Repository;
	use Razshare\CatPaw\MYSQL\Service\DatabaseService;

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

		Route::get("/plain", fn(
			#[Repository("account")] $findByEmail
		) => $findByEmail(["email" => "tangent.jotey@gmail.com"]));
		echo Route::describe();
	}
}