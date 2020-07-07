<?php

namespace Budabot\User\Modules;

use Budabot\Core\Event;
use stdClass;

/**
 * Authors:
 *  - Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'findorg',
 *		accessLevel = 'all',
 *		description = 'Find orgs by name',
 *		help        = 'findorg.txt'
 *	)
 */
class FindOrgController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;
	
	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;
	
	private $searches = array(
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
		'others'
	);

	/** @Setup */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "organizations");
	}
	
	/**
	 * @HandlesCommand("findorg")
	 * @Matches("/^findorg (.+)$/i")
	 */
	public function findOrgCommand($message, $channel, $sender, $sendto, $args) {
		$search = $args[1];
		
		$orgs = $this->lookupOrg($search);
		$count = count($orgs);

		if ($count > 0) {
			$blob = $this->formatResults($orgs);
			$msg = $this->text->makeBlob("Org Search Results for '{$search}' ($count)", $blob);
		} else {
			$msg = "No matches found.";
		}
		$sendto->reply($msg);
	}
	
	public function lookupOrg($search, $limit=50) {
		$tmp = explode(" ", $search);
		list($query, $params) = $this->util->generateQueryFromParams($tmp, 'name');
		
		$sql = "SELECT id, name, faction, num_members FROM organizations WHERE $query LIMIT 50";
		
		$orgs = $this->db->query($sql, $params);
		
		return $orgs;
	}
	
	public function formatResults($orgs) {
		$blob = '';
		foreach ($orgs as $row) {
			$whoisorg = $this->text->makeChatcmd('Whoisorg', "/tell <myname> whoisorg {$row->id}");
			$orglist = $this->text->makeChatcmd('Orglist', "/tell <myname> orglist {$row->id}");
			$orgmembers = $this->text->makeChatcmd('Orgmembers', "/tell <myname> orgmembers {$row->id}");
			$blob .= "<{$row->faction}>{$row->name}<end> ({$row->id}) - {$row->num_members} members [$orglist] [$whoisorg] [$orgmembers]\n\n";
		}
		return $blob;
	}
	
	/**
	 * @Event("timer(24hrs)")
	 * @Description("Parses all orgs from People of Rubi Ka")
	 */
	public function parseAllOrgsEvent(Event $eventObj) {
		$url = "http://people.anarchy-online.com/people/lookup/orgs.html";
		
		$this->logger->log("DEBUG", "Downloading all orgs from '$url'");
		try {
			$this->db->beginTransaction();
			$this->db->exec("DELETE FROM organizations");
			foreach ($this->searches as $search) {
				$response = $this->http->get($url)->withQueryParams(array('l' => $search))->waitAndReturnResponse();
			
				$pattern = '@<tr>\s*'.
					'<td align="left">\s*'.
						'<a href="http://people.anarchy-online.com/org/stats/d/(\d+)/name/(\d+)">\s*'.
							'([^<]+)'.
						'</a>'.
					'</td>\s*'.
					'<td align="right">(\d+)</td>\s*'.
					'<td align="right">(\d+)</td>\s*'.
					'<td align="left">([^<]+)</td>\s*'.
					'<td align="left">([^<]+)</td>\s*'.
					'<td align="left" class="dim">RK5</td>\s*'.
					'</tr>@s';

				preg_match_all($pattern, $response->body, $arr, PREG_SET_ORDER);
				foreach ($arr as $match) {
					$obj = new stdClass;
					//$obj->server = $match[1]; unused
					$obj->id = $match[2];
					$obj->name = trim($match[3]);
					$obj->num_members = $match[4];
					$obj->faction = $match[6];
					//$obj->governingForm = $match[7]; unused
				
					$this->db->exec("INSERT INTO organizations (id, name, faction, num_members) VALUES (?, ?, ?, ?)", $obj->id, $obj->name, $obj->faction, $obj->num_members);
				}
			}
			$this->db->commit();
		} catch (\Exception $e) {
			$this->logger->log("ERROR", "Error downloading orgs");
			$this->db->rollback();
		}
		$this->logger->log("DEBUG", "Finished downloading orgs");
	}
}
