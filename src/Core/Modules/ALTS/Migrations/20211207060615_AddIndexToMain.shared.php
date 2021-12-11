<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\ALTS\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;

class AddIndexToMain implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = "alts";
		$db->schema()->table($table, function(Blueprint $table) {
			$table->string("main", 25)->index()->change();
		});
	}
}
