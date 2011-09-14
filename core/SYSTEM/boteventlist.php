<?php

if (preg_match("/^boteventlist$/i", $message) || preg_match("/^boteventlist (.+)$/i", $message, $arr)) {
	if ($arr) {
		$eventType = str_replace("'", "''", $arr[1]);
		$cmdSearchSql = "WHERE type LIKE '{$eventType}'";
	}

	$sql = "
		SELECT
			type,
			description,
			module,
			file,
			status
		FROM
			eventcfg_<myname>
		$cmdSearchSql
		ORDER BY
			type ASC";
	$db->query($sql);
	$data = $db->fObject('all');
	
	if (count($data) > 0) {
		$blob  = "<header> :::::: Bot Event List :::::: <end>\n\n";
		forEach ($data as $row) {
			$on = Text::make_chatcmd('ON', "/tell <myname> config event $row->type $row->file enable all");
			$off = Text::make_chatcmd('OFF', "/tell <myname> config event $row->type $row->file disable all");

			if ($row->status == 1) {
				$status = "<green>Enabled<end>";
			} else {
				$status = "<red>Disabled<end>";
			}

			if ($row->description != "") {
				$blob .= "$row->type [$row->file] ($status): $on  $off - ($row->description)\n";
			} else {
				$blob .= "$row->type [$row->file] ($status): $on  $off\n";
			}
		}

		$msg = Text::make_blob("Bot Event List", $blob);
	} else {
		$msg = "No events could be found for event type '$arr[1]'.";
	}
 	$chatBot->send($msg, $sendto);
} else {
	$syntax_error = true;
}

?>