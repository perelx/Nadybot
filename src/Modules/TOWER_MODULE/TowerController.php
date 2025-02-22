<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use function Amp\{asyncCall, call};
use Amp\Promise;
use Closure;
use DateTime;
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;
use Exception;
use Generator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\ParamClass\{
	PDuration,
	PNonGreedy,
	PPlayfield,
	PTowerSite,
	PWord,
};
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	CommandReply,
	ConfigFile,
	DB,
	DBSchema\Player,
	EventManager,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	QueryBuilder,
	Routing\RoutableMessage,
	Routing\Source,
	SettingEvent,
	Text,
	UserException,
	Util,
};
use Nadybot\Modules\{
	HELPBOT_MODULE\Playfield,
	HELPBOT_MODULE\PlayfieldController,
	LEVEL_MODULE\LevelController,
	ORGLIST_MODULE\FindOrgController,
	ORGLIST_MODULE\Organization,
	ORGLIST_MODULE\OrglistController,
	TIMERS_MODULE\Alert,
	TIMERS_MODULE\TimerController,
};
use Throwable;

#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "towerstats",
		accessLevel: "guest",
		description: "Show how many towers each faction has lost",
	),
	NCA\DefineCommand(
		command: "attacks",
		accessLevel: "guest",
		description: "Show the last Tower Attack messages",
		alias: "battles"
	),
	NCA\DefineCommand(
		command: "lc",
		accessLevel: "member",
		description: "Show status of towers",
	),
	NCA\DefineCommand(
		command: "sites",
		accessLevel: "member",
		description: "Show sites of an org",
	),
	NCA\DefineCommand(
		command: "penalty",
		accessLevel: "member",
		description: "Show orgs in penalty",
	),
	NCA\DefineCommand(
		command: "remscout",
		accessLevel: "guild",
		description: "Remove tower info from watch list",
	),
	NCA\DefineCommand(
		command: "scout",
		accessLevel: "guild",
		description: "Add tower info to watch list",
	),
	NCA\DefineCommand(
		command: "needsscout",
		accessLevel: "guild",
		description: "Check which tower sites need scouting",
		alias: "needscout"
	),
	NCA\DefineCommand(
		command: "hot",
		accessLevel: "member",
		description: "Check which sites are or will be attackable soon",
	),
	NCA\DefineCommand(
		command: "victory",
		accessLevel: "guest",
		description: "Show the last Tower Battle results",
		alias: "victories"
	),
	NCA\DefineCommand(
		command: "towertype",
		accessLevel: "guest",
		description: "Show the level ranges for tower types",
		alias: "towertypes"
	),
	NCA\DefineCommand(
		command: "towerqty",
		accessLevel: "guest",
		description: "Show how many towers each level is allowed to plant",
		alias: "numtowers"
	),
	NCA\ProvidesEvent("tower(attack)"),
	NCA\ProvidesEvent("tower(win)"),
	NCA\ProvidesEvent(
		event: "sync(scout)",
		desc: "Triggered whenever someone manually scouts a site",
	),
	NCA\ProvidesEvent(
		event: "sync(remscout)",
		desc: "Triggered when marking a site as in need of scouting",
	)
]
class TowerController extends ModuleInstance {
	public const DB_HOT = "tower_site_hot_<myname>";
	public const DB_TOWER_ATTACK = "tower_attack_<myname>";
	public const DB_TOWER_VICTORY = "tower_victory_<myname>";

	public const TYPE_LEGACY = 0;
	public const FIXED_TIMES = [
		1 => 4,
		2 => 20,
	];

	public const TOWER_TYPE_QLS = [
		34 => 2,
		82 => 3,
		129 => 4,
		177 => 5,
		201 => 6,
		226 => 7,
	];

	public const TIMER_NAME = "Towerbattles";

	#[NCA\Inject]
	public PlayfieldController $playfieldController;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public TowerApiController $towerApiController;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public LevelController $levelController;

	#[NCA\Inject]
	public FindOrgController $findOrgController;

	#[NCA\Inject]
	public OrglistController $orglistController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public TimerController $timerController;

	/** Layout types when displaying tower attacks */
	#[NCA\Setting\Options(options: [
		'off' => 0,
		'compact' => 1,
		'normal' => 2,
	])]
	public int $towerAttackSpam = 2;

	/** Number of results to display for victory/attacks */
	#[NCA\Setting\Options(options: [
		'5' => 5,
		'10' => 10,
		'15' => 15,
		'20' => 20,
		'25' => 25,
	])]
	public int $towerPageSize = 15;

	/** Start a timer for planting whenever a tower site goes down */
	#[NCA\Setting\Options(options: [
		'off' => 0,
		'priv' => 1,
		'org' => 2,
	])]
	public int $towerPlantTimer = 0;

	/** By what to group hot/penaltized sites */
	#[NCA\Setting\Options(options: [
		'Playfield' => 1,
		'Title level' => 2,
		'Org' => 3,
	])]
	public int $towerHotGroup = 1;

	/** Message for system(tower-attack-own) when the own field is being attacked */
	#[NCA\Setting\Text(options: [
		"off",
		"@here Our field in {location} is being attacked by {player}",
	])]
	public string $discordNotifyOrgAttacks = "off";

	public int $lastDiscordNotify = 0;

	/** @var AttackListener[] */
	protected array $attackListeners = [];

	/** Adds listener callback which will be called when tower attacks occur. */
	public function registerAttackListener(callable $callback, mixed $data=null): void {
		$listener = new AttackListener();
		$listener->callback = $callback;
		$listener->data = $data;
		$this->attackListeners []= $listener;
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/tower_site.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/tower_site_bounds.csv');

		$attack = new class () implements MessageEmitter {
			public function getChannelName(): string {
				return Source::SYSTEM . "(tower-attack)";
			}
		};
		$attackOwn = new class () implements MessageEmitter {
			public function getChannelName(): string {
				return Source::SYSTEM . "(tower-attack-own)";
			}
		};
		$victory = new class () implements MessageEmitter {
			public function getChannelName(): string {
				return Source::SYSTEM . "(tower-victory)";
			}
		};
		$this->messageHub->registerMessageEmitter($attack)
			->registerMessageEmitter($attackOwn)
			->registerMessageEmitter($victory);
	}

	#[NCA\Event(
		name: "setting(discord_notify_org_attacks)",
		description: "Check if a route is already in place"
	)]
	public function remindIfNoRouteForAttackMsg(SettingEvent $event): void {
		if ($event->newValue->value === "off") {
			return;
		}
		$routeName = Source::SYSTEM . "(tower-attack-own)";
		$hasRoute = $this->messageHub->hasRouteFor($routeName);
		if ($hasRoute) {
			return;
		}
		$msg = "In order to actually see warning about your own towers being ".
			"attack on Discord, you have to add a route similar to ".
			"<highlight><symbol>route add {$routeName} -> discordpriv(example channel)".
			"<end>";
		if (isset($this->config->orgId)) {
			$this->chatBot->sendGuild($msg, true);
		} else {
			$this->chatBot->sendPrivate($msg, true);
		}
	}

	#[NCA\Event(
		name: "timer(24h)",
		description: "Clean list of outdated hot sites"
	)]
	public function cleanHotSites(): void {
		$this->db->table(static::DB_HOT)
			->where("close_time_override", "<", time() - 3600)
			->delete();
	}

	public function readTowerSiteById(int $pfId, int $siteId): ?TowerSite {
		/** @var ?TowerSite */
		$site = $this->db->table("tower_site AS t")
			->where("t.playfield_id", $pfId)
			->where("t.site_number", $siteId)
			->asObj(SiteInfo::class)
			->first();
		return $site;
	}

	/** Show the last tower attack messages */
	#[NCA\HandlesCommand("attacks")]
	public function attacksCommand(CmdContext $context, ?int $page): void {
		$this->attacksCommandHandler($page??1, null, '', $context);
	}

	/** Show the last tower attack messages for a site */
	#[NCA\HandlesCommand("attacks")]
	public function attacks2Command(CmdContext $context, PTowerSite $site, ?int $page): void {
		$playfield = $this->playfieldController->getPlayfieldByName($site->pf);
		if ($playfield === null) {
			$msg = "<highlight>{$site->pf}<end> is not a valid playfield.";
			$context->reply($msg);
			return;
		}

		$towerInfo = $this->getTowerInfo($playfield->id, $site->site);
		if ($towerInfo === null) {
			$msg = "<highlight>{$playfield->long_name}<end> doesn't have a site <highlight>X{$site->site}<end>.";
			$context->reply($msg);
			return;
		}

		$cmd = "{$site->pf} {$site->site} ";
		$search = function (QueryBuilder $query) use ($towerInfo): void {
			$query->where("playfield_id", $towerInfo->playfield_id)
				->where("site_number", $towerInfo->site_number);
		};
		$this->attacksCommandHandler($page??1, $search, $cmd, $context);
	}

	/** Show the last tower attack messages involving a specific organization */
	#[NCA\HandlesCommand("attacks")]
	#[NCA\Help\Example("<symbol>attacks org %sneak%")]
	#[NCA\Help\Example("<symbol>attacks org Komodo")]
	#[NCA\Help\Epilogue("Note: you can use '%' as a wildcard in org and character names")]
	public function attacksOrgCommand(
		CmdContext $context,
		#[NCA\Str("org")] string $action,
		PNonGreedy $orgName,
		?int $page
	): void {
		$cmd = "org {$orgName} ";
		$search = function (QueryBuilder $query) use ($orgName): void {
			$query->whereIlike("att_guild_name", $orgName())
				->orWhereIlike("def_guild_name", $orgName());
		};
		$this->attacksCommandHandler($page??1, $search, $cmd, $context);
	}

	/** Show the last tower attack messages involving a given character */
	#[NCA\HandlesCommand("attacks")]
	#[NCA\Help\Example("<symbol>attacks char nady%")]
	#[NCA\Help\Example("<symbol>attacks char nadyita")]
	public function attacksPlayerCommand(
		CmdContext $context,
		#[NCA\Str("char", "character", "player")] string $action,
		PWord $char,
		?int $page
	): void {
		$cmd = "player {$char} ";
		$search = function (QueryBuilder $query) use ($char): void {
			$query->whereIlike("att_player", $char());
		};
		$this->attacksCommandHandler($page??1, $search, $cmd, $context);
	}

	/** Show all unplanted towerfields */
	#[NCA\HandlesCommand("sites")]
	public function unplantedSitesCommand(CmdContext $context): Generator {
		if ($this->towerApiController->isActive()) {
			$params = ["enabled" => "1", "planted" => "false"];
			try {
				/** @var ?ApiResult */
				$result = yield $this->towerApiController->call2($params);
				if (!isset($result)) {
					$context->reply("Invalid data received from the Tower API. Please try again later.");
					return;
				}
				$result = $this->removeScoutedSitesWhichAreRemoved($result);
			} catch (Throwable $e) {
				$context->reply("Unable to contact the Tower API. Please try again later.");
				return;
			}
		} else {
			$query = $this->getScoutPlusQuery()
				->whereNull("s.ql");
			$sites = $query->asObj(ScoutInfoPlus::class);
			$this->addPlusToScout($sites);
			$result = $this->scoutToAPI($sites);
		}
		if ($result->count === 0) {
			$context->reply("No unplanted sites found.");
			return;
		}
		$blob = $this->renderUnplantedSites($result);

		$msg = $this->makeBlob(
			"All unplanted sites ({$result->count})",
			$blob
		);
		$context->reply($msg);
	}

	/** Show all unplanted towerfields that can hold towers of a given QL */
	#[NCA\HandlesCommand("sites")]
	public function unplantedSitesForQLCommand(
		CmdContext $context,
		#[NCA\Str("ql")] string $action,
		#[NCA\SpaceOptional] int $ql,
	): Generator {
		if ($this->towerApiController->isActive()) {
			$params = ["enabled" => "1", "planted" => "false"];

			try {
				/** @var ?ApiResult */
				$result = yield $this->towerApiController->call2($params);
				if (!isset($result)) {
					$context->reply("Invalid data received from the Tower API. Please try again later.");
					return;
				}
				$result = $this->removeScoutedSitesWhichAreRemoved($result);
			} catch (Throwable $e) {
				$context->reply("Unable to contact the Tower API. Please try again later.");
				return;
			}
		} else {
			$query = $this->getScoutPlusQuery()
				->whereNull("s.ql");
			$sites = $query->asObj(ScoutInfoPlus::class);
			$this->addPlusToScout($sites);
			$result = $this->scoutToAPI($sites);
		}
		$matchingSites = (new Collection($result->results))
			->filter(fn (ApiSite $site): bool => $site->min_ql <= $ql && $site->max_ql >= $ql);
		$result->results = $matchingSites->toArray();
		$result->count = $matchingSites->count();
		if ($result->count === 0) {
			$context->reply("No unplanted sites found that can hold a <highlight>QL{$ql}<end> tower.");
			return;
		}
		$blob = $this->renderUnplantedSites($result);

		$msg = $this->makeBlob(
			"All unplanted sites for a QL{$ql} tower ({$result->count})",
			$blob
		);
		$context->reply($msg);
	}

	/** Render a list of unplanted sites */
	public function renderUnplantedSites(ApiResult $result): string {
		if ($result->count === 0) {
			throw new UserException("No unplanted sites found.");
		}
		$blob = [];
		foreach ($result->results as $site) {
			$blob []= "<pagebreak>" . $this->formatApiSiteInfo($site, null, false);
		}
		return join("\n\n", $blob);
	}

	/** Show all towerfields of a single org */
	#[NCA\HandlesCommand("sites")]
	#[NCA\Help\Example("<symbol>sites athen paladins")]
	#[NCA\Help\Example("<symbol>sites 4736")]
	public function sitesByNameCommand(CmdContext $context, string $search): Generator {
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		if (preg_match("/^\d+$/", $search)) {
			/** @var string[] */
			$msg = yield $this->fetchAndRenderSitesOfOrg((int)$search);
			$context->reply($msg);
			return;
		}

		/** @var Organization[] */
		$orgs = yield $this->orglistController->getOrgsMatchingSearch($search);
		$count = count($orgs);

		if ($count === 0) {
			$msg = "Could not find any orgs (or players in orgs) that match <highlight>{$search}<end>.";
			$context->reply($msg);
		} elseif ($count === 1) {
			/** @var string[] */
			$msg = yield $this->fetchAndRenderSitesOfOrg($orgs[0]->id);
			$context->reply($msg);
			return;
		} else {
			$blob = $this->formatOrglist($orgs);
			$msg = $this->makeBlob("Org Search Results for '{$search}' ({$count})", $blob);
			$context->reply($msg);
		}
	}

	/**
	 * Show a list of links, generated from the orglist
	 *
	 * @param Organization[] $orgs
	 */
	public function formatOrglist(array $orgs): string {
		$blob = '';
		foreach ($orgs as $org) {
			$sites = $this->text->makeChatcmd('Sites', "/tell <myname> sites {$org->id}");
			$whoisorg = $this->text->makeChatcmd('Whoisorg', "/tell <myname> whoisorg {$org->id}");
			$orglist = $this->text->makeChatcmd('Orglist', "/tell <myname> orglist {$org->id}");
			$orgmembers = $this->text->makeChatcmd('Orgmembers', "/tell <myname> orgmembers {$org->id}");
			$blob .= "<{$org->faction}>{$org->name}<end> ({$org->id}) - {$org->num_members} members [{$sites}] [{$orglist}] [{$whoisorg}] [{$orgmembers}]\n\n";
		}
		return $blob;
	}

	/** Show a list of playfield with tower fields */
	#[NCA\HandlesCommand("lc")]
	public function lcCommand(CmdContext $context): void {
		/** @var Collection<Playfield> */
		$playfields = $this->db->table("tower_site")
			->asObj(TowerSite::class)
			->pluck("playfield_id")->unique()
			->map(function (int $id): ?Playfield {
				return $this->playfieldController->getPlayfieldById($id);
			})->filter()->sortBy("long_name");

		$blob = "<header2>Playfields with notum fields<end>\n";
		foreach ($playfields as $pf) {
			$baseLink = $this->text->makeChatcmd($pf->long_name, "/tell <myname> lc {$pf->short_name}");
			$blob .= "<tab>{$baseLink} <highlight>({$pf->short_name})<end>\n";
		}
		$msg = $this->text->makeBlob('Land Control Index', $blob);
		$context->reply($msg);
	}

	/** Show the status of all tower sites in a playfield */
	#[NCA\HandlesCommand("lc")]
	#[NCA\Help\Example("<symbol>lc pw")]
	public function lc2Command(CmdContext $context, PPlayfield $pf): Generator {
		$playfieldName = $pf();
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Playfield <highlight>{$playfieldName}<end> could not be found.";
			$context->reply($msg);
			return;
		}

		/** @var Collection<SiteInfo> */
		$data = $this->db->table("tower_site AS t")
			->where("playfield_id", $playfield->id)
			->asObj(SiteInfo::class)
			->each(function (SiteInfo $info) use ($playfield): void {
				$info->id = $playfield->id;
				$info->short_name = $playfield->short_name;
				$info->long_name = $playfield->long_name;
			});
		if ($data->isEmpty()) {
			$msg = "Playfield <highlight>{$playfield->long_name}<end> does not have any tower sites.";
			$context->reply($msg);
			return;
		}
		$sites = $this->getScoutInfoPlus($playfield->id);
		if ($this->towerApiController->isActive()) {
			$params = ["enabled" => "1", "playfield_id" => $playfield->id];

			/** @var ?ApiResult */
			$apiResult = yield $this->towerApiController->call2($params);
		} else {
			$apiResult = $this->scoutToAPI($sites);
			$sites = null;
		}
		$msg = $this->renderArea($apiResult, $sites, $data, $playfield);
		$context->reply($msg);
	}

	/** @return Collection<ScoutInfoPlus> */
	public function getScoutInfoPlus(int $playfieldId): Collection {
		$pf = $this->playfieldController->getPlayfieldById($playfieldId);
		if (!isset($pf)) {
			return new Collection();
		}
		$sites = $this->db->table("tower_site", "t")
			->where("t.playfield_id", $playfieldId)
			->leftJoin("scout_info AS s", function (JoinClause $join): void {
				$join->on("s.playfield_id", "=", "t.playfield_id")
					->on("s.site_number", "=", "t.site_number");
			})
			->select([
				"s.*",
				"t.playfield_id", "t.site_number",
				"t.min_ql", "t.max_ql", "t.x_coord", "t.y_coord", "t.site_name",
			])
			->where("t.enabled", 1)
			->asObj(ScoutInfoPlus::class);
		$orgNames = $sites->pluck("org_name")->filter()->toArray();
		$orgs = $this->findOrgController->getOrgsByName(...$orgNames)->keyBy("name");
		$sites->each(function (ScoutInfoPlus $info) use ($orgs, $pf): void {
			$info->org_id = 0;
			if (isset($info->org_name)) {
				$info->org_id = $orgs->get($info->org_name)?->id??null;
			}
			$info->playfield_short_name = $pf->short_name;
			$info->playfield_long_name = $pf->long_name;
		});
		return $sites;
	}

	/** Show the status of a single tower site */
	#[NCA\HandlesCommand("lc")]
	#[NCA\Help\Example("<symbol>lc pw8")]
	#[NCA\Help\Example("<symbol>lc mort 6")]
	public function lc3Command(CmdContext $context, PTowerSite $site): Generator {
		$playfieldName = $site->pf;
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Playfield <highlight>{$playfieldName}<end> could not be found.";
			$context->reply($msg);
			return;
		}

		/** @var ?SiteInfo */
		$site = $this->db->table("tower_site AS t")
			->where("t.playfield_id", $playfield->id)
			->where("t.site_number", $site->site)
			->asObj(SiteInfo::class)
			->each(function (SiteInfo $info) use ($playfield): void {
				$info->id = $playfield->id;
				$info->short_name = $playfield->short_name;
				$info->long_name = $playfield->long_name;
			})->first();
		if ($site === null) {
			$msg = "Invalid site number.";
			$context->reply($msg);
			return;
		}
		$sites = $this->getScoutInfoPlus($playfield->id);
		if ($this->towerApiController->isActive()) {
			$params = ["enabled" => "1", "playfield_id" => $playfield->id];

			/** @var ?ApiResult */
			$apiResult = yield $this->towerApiController->call2($params);
		} else {
			$apiResult = $this->scoutToAPI($sites);
			$sites = null;
		}
		$msg = $this->renderSite($apiResult, $sites, $site, $playfield);
		$context->reply($msg);
	}

	/**
	 * Show tower sites which are hot because the owning orgs are in penalty for attacking.
	 * This can be limited to a given org name or faction
	 */
	#[NCA\HandlesCommand("penalty")]
	#[NCA\Help\Example("<symbol>penalty neutral")]
	#[NCA\Help\Example("<symbol>penalty Obeya")]
	public function penaltySitesApiCommand(CmdContext $context, ?string $orgName): Generator {
		$sites = $this->getScoutPlusQuery()
			->where("s.penalty_until", ">=", time())
			->asObj(ScoutInfoPlus::class);
		$this->addPlusToScout($sites);
		if ($this->towerApiController->isActive()) {
			$params = ["enabled" => "true", "penalty" => "true"];
			if (strlen($orgName??"")) {
				if (strcasecmp($orgName??"", "neut") === 0) {
					$orgName = "neutral";
				}
				if (preg_match("/^(clan|omni|neutral)$/", $orgName??"")) {
					$params["faction"] = $orgName;
				} else {
					$params["org_name"] = $orgName;
				}
			}

			/** @var ?ApiResult */
			$result = yield $this->towerApiController->call2($params);
		} else {
			$result = $this->scoutToAPI($sites);
			$sites = null;
		}
		$msg = $this->renderPenaltySites($result, $sites);
		$context->reply($msg);
	}

	/**
	 * Show tower sites for which you have no local scout information
	 * Give a playfield to only show those in this given playfield
	 *
	 * Note that you only need to do local scouting, if you don't want
	 * to use the tower API, or want to add information that API hasn't
	 * caught up with yet.
	 */
	#[NCA\HandlesCommand("needsscout")]
	#[NCA\Help\Group("scout")]
	public function needsScoutCommand(CmdContext $context, ?PPlayfield $playfield): void {
		$query = $this->db->table("tower_site AS t")
			->leftJoin("scout_info AS s", function (JoinClause $join): void {
				$join->on("t.playfield_id", "s.playfield_id")
					->on("s.site_number", "t.site_number");
			})
			->select("t.*")
			->where("t.enabled", 1)
			->where(function (QueryBuilder $where): void {
				$where->whereNull("s.playfield_id")
				->orWhere(function (QueryBuilder $where): void {
					$where->whereNull("s.ql")
						->where("s.scouted_on", "<", time() - 20*60);
				});
			});
		if (isset($playfield)) {
			$pf = $this->playfieldController->getPlayfieldByName($playfield());
			if (!isset($pf)) {
				$context->reply("Unable to find playfield <highlight>{$playfield}<end>.");
				return;
			}
			$query->where("t.playfield_id", $pf->id);
		}
		$data = $query->asObj(SiteInfo::class);
		if ($data->count() === 0) {
			if (isset($pf)) {
				$context->reply("No sites in {$pf->long_name} need scouting right now.");
			} else {
				$context->reply("No sites need scouting right now.");
			}
			return;
		}
		$pfIds = $data->pluck("playfield_id")->filter()->toArray();
		$playfields = $this->playfieldController->searchPlayfieldsByIds(...$pfIds)
			->keyBy("id");
		$data->each(function (SiteInfo $info) use ($playfields): void {
			/** @var Playfield */
			$pf = $playfields->get($info->playfield_id);
			$info->id = $pf->id;
			$info->short_name = $pf->short_name;
			$info->long_name = $pf->long_name;
		});
		$groups = $data->sortBy("site_number")->sortBy("long_name")->groupBy("short_name");
		$blob = $groups->map([$this, "formatSiteGroup"])
			->join("\n\n");

		$context->reply(
			$groups->count() > 1
				? $this->text->makeBlob(
					"Sites in need of scouting (" . $data->count() . ")",
					$blob
				)
				: str_replace("<pagebreak>", "", $blob)
		);
	}

	/** @param Collection<SiteInfo> $siteGroup */
	public function formatSiteGroup(Collection $siteGroup, string $shortName): string {
		$siteLinks = $siteGroup->map(function (SiteInfo $site): string {
			$shortName = ($site->short_name??"UNKNOWN") . " " . $site->site_number;
			$siteLink = $this->text->makeChatcmd(
				$shortName,
				"/tell <myname> <symbol>lc {$shortName}"
			);
			return $siteLink;
		})->join(", ");
		return "<pagebreak><header2>{$siteGroup[0]->long_name}<end>\n".
			"<tab>{$siteLinks}";
	}

	/**
	 * See which sites are currently hot.
	 * You can limit this by any combination of
	 * faction, playfield, ql, level range, and time in the future
	 */
	#[NCA\HandlesCommand("hot")]
	#[NCA\Help\Example("<symbol>hot clan")]
	#[NCA\Help\Example("<symbol>hot pw")]
	#[NCA\Help\Example("<symbol>hot 60", "Only those in PvP-range for a level 60 char")]
	#[NCA\Help\Example("<symbol>hot 99-110", "Only where the CT is between QL 99 and 110")]
	#[NCA\Help\Example("<symbol>hot 6h")]
	#[NCA\Help\Example("<symbol>hot omni 3h pw 180-300")]
	public function hotSitesCommand(CmdContext $context, ?string $search): Generator {
		$search ??= "";
		if (substr($search, 0, 1) !== " ") {
			$search = " {$search}";
		}
		$params = [
			"enabled" => "true",
			"min_close_time" => time(),
			"max_close_time" => time() + 6 * 3600,
		];
		$pf = null;
		if (preg_match("/\s+(neutral|omni|clan|neut)\b/i", $search, $matches)) {
			$faction = strtolower($matches[1]);
			$search = preg_replace("/\s+(neutral|omni|clan|neut)\b/i", "", $search);
			if ($faction === "neut") {
				$faction = "neutral";
			}
			$params["faction"] = $faction;
		}
		if (preg_match("/\s+(\d+)\s*-\s*(\d+)\b/", $search, $matches)) {
			$params["min_ql"] = $matches[1];
			$params["max_ql"] = $matches[2];
			$search = preg_replace("/\s+(\d+)\s*-\s*(\d+)\b/", "", $search);
		}
		if (preg_match("/\s+(\d+)\b/", $search, $matches)) {
			$lvlInfo = $this->levelController->getLevelInfo((int)$matches[1]);
			if (!isset($lvlInfo)) {
				$context->reply("<highlight>{$matches[1]}<end> is an invalid level.");
				return;
			}
			$params["min_ql"] = (string)$lvlInfo->pvpMin;
			$params["max_ql"] = (string)$lvlInfo->pvpMax;
			if ($params["max_ql"] === "220") {
				$params["max_ql"] = "300";
			}
			$search = preg_replace("/\s+(\d+)\b/", "", $search);
		}
		if (preg_match("/\s+([a-z]{2,}|\d[a-z]{2,})\b/i", $search, $matches)) {
			$pf = $this->playfieldController->getPlayfieldByName($matches[1]);
			if (!isset($pf)) {
				$context->reply("Unable to find playfield <highlight>{$matches[1]}<end>.");
				return;
			}
			$params["playfield_id"] = (string)$pf->id;
			$search = preg_replace("/\s+([a-z]{2,}|\d[a-z]{2,})\b/i", "", $search);
		}
		$search = trim($search);
		$time = $this->util->parseTime($search);
		if ($time !== 0) {
			$params["min_close_time"] += $time;
			$params["max_close_time"] += $time;
		}
		$params["min_close_time"] %= 86400;
		$params["max_close_time"] %= 86400;
		$hotSites = $this->getScoutedHotSites($params, $time + time(), $context);
		if (!isset($hotSites)) {
			return;
		}
		if ($this->towerApiController->isActive()) {
			/** @var ?ApiResult */
			$apiResult = yield $this->towerApiController->call2($params);
			if ($apiResult === null) {
				$context->reply("Invalid data received from tower API. Try again later.");
				return;
			}
			$hotSites = $this->mergeLocalToAPI($hotSites, $apiResult);
		} else {
			$hotSites = $this->scoutToAPI($hotSites);
		}
		if ($hotSites->count === 0) {
			$context->reply("No sites are currently hot.");
			return;
		}
		$blob = $this->renderHotSites($hotSites, $params, $time);
		$faction = isset($faction) ? " " . strtolower($faction) : "";
		$timeString = \Safe\date("H:i:s", $params["min_close_time"]);
		$msg = $this->text->makeBlob("Hot{$faction} sites at {$timeString} UTC ({$hotSites->count})", $blob);

		$context->reply($msg);
	}

	public function getScoutPlusQuery(): QueryBuilder {
		return $this->db->table("scout_info", "s")
			->join("tower_site AS t", function (JoinClause $join): void {
				$join->on("s.playfield_id", "=", "t.playfield_id")
					->on("s.site_number", "=", "t.site_number");
			})
			->select([
				"s.*",
				"t.min_ql", "t.max_ql", "t.x_coord", "t.y_coord", "t.site_name",
			])
			->where("t.enabled", 1);
	}

	/**
	 * @param array<string,mixed> $params
	 *
	 * @return null|Collection<ScoutInfoPlus>
	 */
	public function getScoutedHotSites(array $params, int $time, CommandReply $sendto): ?Collection {
		$query = $this->getScoutPlusQuery()
			->whereNotNull("close_time");
		$sites = $query->asObj(ScoutInfoPlus::class);
		$this->addPlusToScout($sites);
		$sites = $sites->filter(function (ScoutInfoPlus $site) use ($time): bool {
			$gas = $this->getGasLevel($site->close_time??0, $time ?: time());
			return $gas->gas_level < 75
				|| ($site->penalty_until > 0 && $site->penalty_until <= $time);
		});
		if (isset($params['faction'])) {
			$sites = $sites->filter(function (ScoutInfoPlus $site) use ($params): bool {
				return $site->faction === ucfirst(strtolower($params['faction']));
			});
		}
		if (isset($params['min_ql'])) {
			$sites = $sites->filter(function (ScoutInfoPlus $site) use ($params): bool {
				return $site->ql >= $params['min_ql'];
			});
		}
		if (isset($params['max_ql'])) {
			$sites = $sites->filter(function (ScoutInfoPlus $site) use ($params): bool {
				return $site->ql <= $params['max_ql'];
			});
		}
		if (isset($params["playfield_id"])) {
			$pf = $this->playfieldController->getPlayfieldById((int)$params["playfield_id"]);
			if (!isset($pf)) {
				$sendto->reply("Unable to find playfield <highlight>{$params['playfield_id']}<end>.");
				return null;
			}
			$sites = $sites->filter(function (ScoutInfoPlus $site) use ($pf): bool {
				return $site->playfield_id === $pf->id;
			});
		}
		return $sites;
	}

	/**
	 * Convert the locally scouted data into an API result
	 *
	 * @param \Illuminate\Support\Collection<ScoutInfoPlus> $scoutInfos
	 */
	public function scoutToAPI(Collection $scoutInfos): ApiResult {
		$data = [];
		$mapper = new ObjectMapperUsingReflection();
		foreach ($scoutInfos as $info) {
			$data []= $mapper->hydrateObject(ApiSite::class, (array)$info);
		}
		return new ApiResult(
			count: $scoutInfos->count(),
			results: $data
		);
	}

	public function qlToSiteType(int $qlCT): int {
		foreach (static::TOWER_TYPE_QLS as $ql => $level) {
			if ($qlCT < $ql) {
				return $level - 1;
			}
		}
		return 7;
	}

	/** Check if the data from $apiSite is more up-to-date than $localSite */
	public function isApiVersionNewer(?ApiSite $apiSite, ?ScoutInfo $localSite): bool {
		// Unplanted API data cannot override local data, because there is no
		// way of knowing how old it is
		if (!isset($apiSite) || !isset($apiSite->created_at)) {
			return false;
		}
		if (!isset($localSite)) {
			return true;
		}
		// If the local data is empty, then whatever the API has, must be newer
		if (!isset($localSite->scouted_on)) {
			return true;
		}
		// If we have both, local and api data, check the CT's plant time.
		// If the local data is marked unplanted, compare the local scout date
		// to the API CT's plant time
		return $apiSite->created_at > ($localSite->created_at ?? $localSite->scouted_on);
	}

	/** See how many tower sites each faction has taken and lost in the past 24 hours or &lt;duration&gt; */
	#[NCA\HandlesCommand("towerstats")]
	public function towerStatsCommand(CmdContext $context, ?PDuration $duration): void {
		$duration = isset($duration) ? $duration->toSecs() : 86400;
		if ($duration < 1) {
			$msg = "You must enter a valid time parameter.";
			$context->reply($msg);
			return;
		}

		$timeString = $this->util->unixtimeToReadable($duration);

		$blob = '';

		$query = $this->db->table(self::DB_TOWER_ATTACK)
			->where("time", ">=", time() - $duration)
			->groupBy("att_faction")
			->orderBy("att_faction");

		/** @var Collection<FactionCount> */
		$data = $query->orderBy($query->colFunc("COUNT", "att_faction"))
			->select(
				"att_faction AS faction",
				$query->colFunc("COUNT", "att_faction", "num")
			)
			->asObj(FactionCount::class);
		foreach ($data as $row) {
			$blob .= "<{$row->faction}>{$row->faction}s<end> have attacked <highlight>{$row->num}<end> ".
				$this->text->pluralize("time", $row->num) . ".\n";
		}
		if ($data->isNotEmpty()) {
			$blob .= "\n";
		}

		$query = $this->db->table(self::DB_TOWER_VICTORY)
			->where("time", ">=", time() - $duration)
			->groupBy("lose_faction")
			->orderByDesc("num")
			->select("lose_faction as faction");

		/** @var Collection<FactionCount> */
		$data = $query->addSelect($query->colFunc("COUNT", "lose_faction", "num"))
			->asObj(FactionCount::class);
		foreach ($data as $row) {
			$blob .= "<{$row->faction}>{$row->faction}s<end> have lost <highlight>{$row->num}<end> tower ".
				$this->text->pluralize("site", $row->num) . ".\n";
		}

		if ($blob == '') {
			$msg = "No tower attacks or victories have been recorded.";
		} else {
			$msg = $this->text->makeBlob("Tower Stats for the Last {$timeString}", $blob);
		}
		$context->reply($msg);
	}

	/** See the last tower battle results */
	#[NCA\HandlesCommand("victory")]
	public function victoryCommand(CmdContext $context, ?int $page): void {
		$this->victoryCommandHandler($page??1, null, "", $context);
	}

	/** See the last tower battle results for a given tower site */
	#[NCA\HandlesCommand("victory")]
	public function victory2Command(CmdContext $context, PTowerSite $site, ?int $page): void {
		$playfield = $this->playfieldController->getPlayfieldByName($site->pf);
		if ($playfield === null) {
			$msg = "Invalid playfield.";
			$context->reply($msg);
			return;
		}

		$towerInfo = $this->getTowerInfo($playfield->id, $site->site);
		if ($towerInfo === null) {
			$msg = "Invalid site number.";
			$context->reply($msg);
			return;
		}

		$cmd = "{$site->pf} {$site->site} ";
		$search = function (QueryBuilder $query) use ($towerInfo): void {
			$query->where("a.playfield_id", $towerInfo->playfield_id)
				->where("a.site_number", $towerInfo->site_number);
		};
		$this->victoryCommandHandler($page??1, $search, $cmd, $context);
	}

	/** See the last tower battle results for a given organization */
	#[NCA\HandlesCommand("victory")]
	#[NCA\Help\Epilogue("Note: you can use '%' as a wildcard in org and character names")]
	#[NCA\Help\Example("<symbol>victory org %sneak%")]
	#[NCA\Help\Example("<symbol>victory org Komodo")]
	public function victoryOrgCommand(CmdContext $context, #[NCA\Str("org")] string $action, PNonGreedy $orgName, ?int $page): void {
		$cmd = "org {$orgName} ";
		$search = function (QueryBuilder $query) use ($orgName): void {
			$query->whereIlike("v.win_guild_name", $orgName())
				->orWhereIlike("v.lose_guild_name", $orgName());
		};
		$this->victoryCommandHandler($page??1, $search, $cmd, $context);
	}

	/** See the last tower battle results for a given character */
	#[NCA\HandlesCommand("victory")]
	#[NCA\Help\Example("<symbol>victory char nady%")]
	#[NCA\Help\Example("<symbol>victory char nadyita")]
	public function victoryPlayerCommand(
		CmdContext $context,
		#[NCA\Str("char", "character", "player")] string $action,
		PWord $char,
		?int $page
	): void {
		$cmd = "player {$char} ";
		$search = function (QueryBuilder $query) use ($char): void {
			$query->whereIlike("a.att_player", $char());
		};
		$this->victoryCommandHandler($page??1, $search, $cmd, $context);
	}

	#[NCA\Event(
		name: "orgmsg",
		description: "Notify if org's towers are attacked"
	)]
	public function attackOwnOrgMessageEvent(AOChatEvent $eventObj): void {
		if ($this->util->isValidSender($eventObj->sender)) {
			return;
		}
		if (
			!preg_match(
				"/^The tower (.+?) in (.+?) was just reduced to \d+ % health ".
				"by ([^ ]+) from the (.+?) organization!$/",
				$eventObj->message,
				$matches
			)
			&& !preg_match(
				"/^The tower (.+?) in (.+?) was just reduced to \d+ % health by ([^ ]+)!$/",
				$eventObj->message,
				$matches
			)
			&& !preg_match(
				"/^Your (.+?) tower in (?:.+?) in (.+?) has had its ".
				"defense shield disabled by ([^ ]+) \(.+?\)\.\s*".
				"The attacker is a member of the organization (.+?)\.$/",
				$eventObj->message,
				$matches
			)
		) {
			return;
		}
		$discordMessage = $this->discordNotifyOrgAttacks;
		if (empty($discordMessage) || $discordMessage === "off") {
			return;
		}
		// One notification every 5 minutes seems enough
		if (time() - $this->lastDiscordNotify < 300) {
			return;
		}
		asyncCall(function () use ($matches, $discordMessage): Generator {
			/** @var ?Player */
			$whois = yield $this->playerManager->byName($matches[3]);
			$attGuild = $matches[4] ?? null;
			$attPlayer = $matches[3];
			$playfieldName = $matches[2];
			if ($whois === null) {
				$whois = new Player();
				$whois->name = $attPlayer;
				$whois->faction = 'Neutral';
			}
			$playerName = "<highlight>{$whois->name}<end> ({$whois->faction}";
			if (isset($attGuild)) {
				$playerName .= " org \"{$attGuild}\"";
			}
			$playerName .= ")";
			$discordMessage = str_replace(
				["{player}", "{location}"],
				[$playerName, $playfieldName],
				$discordMessage
			);
			$r = new RoutableMessage($discordMessage);
			$r->appendPath(new Source(Source::SYSTEM, "tower-attack-own"));
			$this->messageHub->handle($r);
			$this->lastDiscordNotify = time();
		});
	}

	/** This event handler record attack messages. */
	#[NCA\Event(
		name: "towers",
		description: "Record attack messages"
	)]
	public function attackMessagesEvent(AOChatEvent $eventObj): void {
		$attack = new Attack();
		if (preg_match(
			"/^The (Clan|Neutral|Omni) organization ".
			"(.+) just entered a state of war! ".
			"(.+) attacked the (Clan|Neutral|Omni) organization ".
			"(.+)'s tower in ".
			"(.+) at location \\((\\d+),(\\d+)\\)\\.$/i",
			$eventObj->message,
			$arr
		)) {
			$attack->attSide = ucfirst(strtolower($arr[1]));  // comes across as a string instead of a reference, so convert to title case
			$attack->attGuild = $arr[2];
			$attack->attPlayer = $arr[3];
			$attack->defSide = ucfirst(strtolower($arr[4]));  // comes across as a string instead of a reference, so convert to title case
			$attack->defGuild = $arr[5];
			$attack->playfieldName = $arr[6];
			$attack->xCoords = (int)$arr[7];
			$attack->yCoords = (int)$arr[8];
		} elseif (preg_match(
			"/^(.+) just attacked the (Clan|Neutral|Omni) organization ".
			"(.+)'s tower in ".
			"(.+) at location \(([0-9]+), ([0-9]+)\).(.*)$/i",
			$eventObj->message,
			$arr
		)) {
			$attack->attPlayer = $arr[1];
			$attack->defSide = ucfirst(strtolower($arr[2]));  // comes across as a string instead of a reference, so convert to title case
			$attack->defGuild = $arr[3];
			$attack->playfieldName = $arr[4];
			$attack->xCoords = (int)$arr[5];
			$attack->yCoords = (int)$arr[6];
		} else {
			return;
		}

		// regardless of what the player lookup says, we use the information from the
		// attack message where applicable because that will always be most up to date
		asyncCall(function () use ($attack): Generator {
			/** @var ?Player */
			$player = yield $this->playerManager->byName($attack->attPlayer);
			$this->handleAttack($attack, $player);
		});
	}

	public function handleAttack(Attack $attack, ?Player $whois): void {
		$type = 'player';
		if ($whois === null) {
			$whois = new Player();
			$type = 'npc';

			// in case it's not a player who causes attack message (pet, mob, etc)
			$whois->name = $attack->attPlayer;
			$whois->faction = 'Neutral';
		}
		$factionGuess = false;
		if (isset($attack->attSide)) {
			$whois->faction = $attack->attSide;
		} else {
			$factionGuess = true;
			$originalGuild = $whois->guild;
		}
		$whois->guild = $attack->attGuild ?? null;

		$playfield = $this->playfieldController->getPlayfieldByName($attack->playfieldName);
		if ($playfield === null) {
			$this->logger->error("ERROR! Could not find Playfield \"{$attack->playfieldName}\"");
			return;
		}
		$closestSite = $this->getClosestSite($playfield->id, $attack->xCoords, $attack->yCoords);

		$defender = new Defender();
		$defender->faction   = $attack->defSide;
		$defender->guild     = $attack->defGuild;
		$defender->playfield = $playfield;
		$defender->site      = $closestSite;

		foreach ($this->attackListeners as $listener) {
			$callback = $listener->callback;
			$callback($whois, $defender, $listener->data);
		}

		if ($closestSite === null) {
			$this->logger->error("ERROR! Could not find closest site: ({$attack->playfieldName}) '{$playfield->id}' '{$attack->xCoords}' '{$attack->yCoords}'");
			$more = "[<red>UNKNOWN AREA!<end>]";
		} else {
			$this->recordAttack($whois, $attack, $closestSite);
			$this->towerApiController->wipeApiCache();
			$this->logger->info("Site being attacked: ({$attack->playfieldName}) '{$closestSite->playfield_id}' '{$closestSite->site_number}'");

			// Beginning of the 'more' window
			$link = "";
			if ($factionGuess) {
				$link .= "<highlight>Warning:<end> The attacker could also be a pet with a fake name!\n\n";
			}
			$link .= "Attacker: <highlight>";
			if (isset($whois->firstname) && strlen($whois->firstname)) {
				$link .= $whois->firstname . " ";
			}

			$link .= '"' . $attack->attPlayer . '"';
			if (isset($whois->lastname) && strlen($whois->lastname)) {
				$link .= " " . $whois->lastname;
			}
			$link .= "<end>\n";

			if (isset($whois->breed) && strlen($whois->breed)) {
				$link .= "Breed: <highlight>{$whois->breed}<end>\n";
			}
			if (isset($whois->gender) && strlen($whois->gender)) {
				$link .= "Gender: <highlight>{$whois->gender}<end>\n";
			}

			if (isset($whois->profession) && strlen($whois->profession)) {
				$link .= "Profession: <highlight>{$whois->profession}<end>\n";
			}
			if (isset($whois->level)) {
				$level_info = $this->levelController->getLevelInfo($whois->level);
				if (isset($level_info)) {
					$link .= "Level: <highlight>{$whois->level}/<green>{$whois->ai_level}<end> ({$level_info->pvpMin}-{$level_info->pvpMax})<end>\n";
				}
			}

			$link .= "Alignment: <" . strtolower($whois->faction) . ">{$whois->faction}<end>\n";

			if (isset($whois->guild)) {
				$link .= "Organization: <highlight>{$whois->guild}<end>\n";
				if (isset($whois->guild_rank)) {
					$link .= "Organization Rank: <highlight>{$whois->guild_rank}<end>\n";
				}
			}

			$link .= "\n";

			$link .= "Defender: <highlight>{$attack->defGuild}<end>\n";
			$link .= "Alignment: <" . strtolower($attack->defSide) . ">{$attack->defSide}<end>\n\n";

			$baseLink = $this->text->makeChatcmd("{$playfield->short_name} {$closestSite->site_number}", "/tell <myname> lc {$playfield->short_name} {$closestSite->site_number}");
			$attackWaypoint = $this->text->makeChatcmd("{$attack->xCoords}x{$attack->yCoords}", "/waypoint {$attack->xCoords} {$attack->yCoords} {$playfield->id}");
			$link .= "Playfield: <highlight>{$baseLink} ({$closestSite->min_ql}-{$closestSite->max_ql})<end>\n";
			$link .= "Location: <highlight>{$closestSite->site_name} ({$attackWaypoint})<end>\n";

			$more = $this->text->makeBlob("{$playfield->short_name} {$closestSite->site_number}", $link, 'Advanced Tower Info');
		}

		$targetOrg = "<".strtolower($attack->defSide).">{$attack->defGuild}<end>";

		// Starting tower message to org/private chat
		$msg = "";
		$likelyFake = $factionGuess && isset($originalGuild) && strlen($originalGuild);
		if ($whois->guild) {
			$msg .= "<".strtolower($whois->faction).">{$whois->guild}<end>";
		} else {
			$msg .= "<".strtolower($whois->faction).">{$attack->attPlayer}<end>";
		}
		$msg .= " attacked {$targetOrg}";

		$s = $this->towerAttackSpam;
		// tower_attack_spam >= 2 (normal) includes attacker stats
		if ($s >= 2 && $type !== 'npc' && !$likelyFake) {
			$msg .= " - ".preg_replace(
				"/, <(omni|neutral|clan)>(omni|neutral|clan)<end>/i",
				'',
				preg_replace(
					"/ of <(omni|neutral|clan)>.+?<end>/i",
					'',
					$this->playerManager->getInfo($whois, false)
				)
			);
		} elseif ($s >= 2 && $type !== 'npc') {
			$msg .= " (<highlight>{$whois->level}<end>/<green>{$whois->ai_level}<end> <" . strtolower($whois->faction) . ">{$whois->faction}<end> <highlight>{$whois->profession}<end> or fake name)";
		}

		$msg = $this->text->blobWrap("{$msg} [", $more, "]");

		if ($s === 0) {
			return;
		}
		foreach ($msg as $page) {
			$r = new RoutableMessage($page);
			$r->appendPath(new Source(Source::SYSTEM, "tower-attack"));
			$this->messageHub->handle($r);
		}
	}

	/** This event handler record victory messages. */
	#[NCA\Event(
		name: "towers",
		description: "Record victory messages"
	)]
	public function victoryMessagesEvent(AOChatEvent $eventObj): void {
		if (preg_match("/^The (Clan|Neutral|Omni) organization (.+) attacked the (Clan|Neutral|Omni) (.+) at their base in (.+). The attackers won!!$/i", $eventObj->message, $arr)) {
			$winnerFaction = $arr[1];
			$winnerOrgName = $arr[2];
			$loserFaction  = $arr[3];
			$loserOrgName  = $arr[4];
			$playfieldName = $arr[5];
		} elseif (preg_match("/^Notum Wars Update: The (clan|neutral|omni) organization (.+) lost their base in (.+).$/i", $eventObj->message, $arr)) {
			$winnerFaction = '';
			$winnerOrgName = '';
			$loserFaction  = ucfirst($arr[1]);  // capitalize the faction name to match the other messages
			$loserOrgName  = $arr[2];
			$playfieldName = $arr[3];
		} else {
			return;
		}

		$event = new TowerVictoryEvent();

		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$this->logger->error("Could not find playfield for name '{$playfieldName}'");
			return;
		}

		if (!$winnerFaction) {
			$msg = "<" . strtolower($loserFaction) . ">{$loserOrgName}<end> ".
				"abandoned their field";
		} else {
			$msg = "<".strtolower($winnerFaction).">{$winnerOrgName}<end>".
				" won against " .
				"<" . strtolower($loserFaction) . ">{$loserOrgName}<end>";
		}

		$lastAttack = $this->getLastAttack($winnerFaction, $winnerOrgName, $loserFaction, $loserOrgName, $playfield->id);
		$siteNumber = null;
		if ($lastAttack !== null) {
			$siteNumber = $lastAttack->site_number;
		}
		if (isset($siteNumber) && ($towerInfo = $this->getTowerInfo($playfield->id, $siteNumber)) !== null) {
			$event->site = $towerInfo;
			$waypointLink = $this->text->makeChatcmd("Get a waypoint", "/waypoint {$towerInfo->x_coord} {$towerInfo->y_coord} {$playfield->id}");
			$timerLocation = ((array)$this->text->makeBlob(
				"{$playfield->short_name} {$siteNumber}",
				"Name: <highlight>{$towerInfo->site_name}<end><br>".
				"QL: <highlight>{$towerInfo->min_ql}<end> - <highlight>{$towerInfo->max_ql}<end><br>".
				"Action: {$waypointLink}",
				"Information about {$playfield->short_name} {$siteNumber}"
			))[0];
			$msg .= " in " . $timerLocation;
		} else {
			$msg .= " in {$playfield->short_name}";
			$timerLocation = "unknown field in {$playfield->short_name}";
		}

		if ($this->towerPlantTimer !== 0) {
			$this->setPlantTimer($timerLocation);
		}

		$r = new RoutableMessage($msg);
		$r->appendPath(new Source(Source::SYSTEM, "tower-victory"));
		$this->messageHub->handle($r);

		if (!isset($lastAttack)) {
			$lastAttack = new TowerAttack();
			$lastAttack->att_guild_name = $winnerOrgName;
			$lastAttack->def_guild_name = $loserOrgName;
			$lastAttack->att_faction = $winnerFaction;
			$lastAttack->def_faction = $loserFaction;
			$lastAttack->playfield_id = $playfield->id;
			$lastAttack->id = -1;
			if (isset($siteNumber)) {
				$lastAttack->site_number = $siteNumber;
			}
		}

		$this->recordVictory($lastAttack);
		$this->towerApiController->wipeApiCache();
		$event->attack = $lastAttack;
		$this->eventManager->fireEvent($event);
	}

	public function getTowerInfo(int $playfieldID, int $siteNumber): ?TowerSite {
		return $this->db->table("tower_site AS t")
			->where("playfield_id", $playfieldID)
			->where("site_number", $siteNumber)
			->limit(1)
			->asObj(TowerSite::class)
			->first();
	}

	#[NCA\Event(
		name: "sync(scout)",
		description: "Sync external scout information"
	)]
	public function processScoutSyncEvent(SyncScoutEvent $event): void {
		if (!$event->isLocal()) {
			$this->addScoutSite($event->toScoutInfo());
		}
	}

	#[NCA\Event(
		name: "sync(remscout)",
		description: "Sync external scout information"
	)]
	public function processRemscoutSyncEvent(SyncRemscoutEvent $event): void {
		if (!$event->isLocal()) {
			$this->remScoutSite($event->playfield_id, $event->site_number);
		}
	}

	public function getSitesInPenalty(?int $time=null): ApiResult {
		/** @var Collection<HotApiSite> */
		$penalties = $this->db->table(static::DB_HOT)
			->where("close_time_override", ">", $time??time())
			->orderByDesc("close_time_override")
			->asObj(HotApiSite::class);
		$groups = $penalties->groupBy(function (HotApiSite $site): string {
			return "{$site->playfield_id}x{$site->site_number}";
		});
		$flatSites = $groups->map(function (Collection $value, string $key): HotApiSite {
			return $value->first();
		});
		$apiSites = $flatSites->flatten()->map(function (HotApiSite $site): array {
			$hash = (array)$site;
			$hash["close_time"] = $hash["close_time_override"] % 86400;
			unset($hash["close_time_override"]);
			unset($hash["id"]);
			$pf = $this->playfieldController->getPlayfieldById($site->playfield_id);
			if (isset($pf)) {
				$hash["playfield_short_name"] = $pf->short_name;
				$hash["playfield_long_name"] = $pf->long_name;
			}
			$towerInfo = $this->getTowerInfo($site->playfield_id, $site->site_number);
			if (isset($towerInfo)) {
				$hash["min_ql"] = $towerInfo->min_ql;
				$hash["max_ql"] = $towerInfo->max_ql;
				$hash["x_coord"] = $towerInfo->x_coord;
				$hash["y_coord"] = $towerInfo->y_coord;
				$hash["site_name"] = $towerInfo->site_name;
				$hash["enabled"] = 1;
			}
			return $hash;
		});
		$mapper = new ObjectMapperUsingReflection();

		/** @var ApiResult */
		$apiResult = $mapper->hydrateObject(
			ApiResult::class,
			[
				"count" => $apiSites->count(),
				"results" => $apiSites->toArray(),
			]
		);
		return $apiResult;
	}

	public function getFaction(string $input): string {
		$faction = ucfirst(strtolower($input));
		if ($faction == "Neut") {
			$faction = "Neutral";
		}
		return $faction;
	}

	/** Remove local scout info for a site and mark it unscouted */
	#[NCA\HandlesCommand("remscout")]
	#[NCA\Help\Group("scout")]
	public function remscoutCommand(CmdContext $context, PTowerSite $site): void {
		$playfield = $this->playfieldController->getPlayfieldByName($site->pf);
		if ($playfield === null) {
			$msg = "Invalid playfield.";
			$context->reply($msg);
			return;
		}

		$towerInfo = $this->getTowerInfo($playfield->id, $site->site);
		if ($towerInfo === null) {
			$msg = "Invalid site number.";
			$context->reply($msg);
			return;
		}

		$numDeleted = $this->remScoutSite($playfield->id, $site->site);

		if ($numDeleted === 0) {
			$msg = "Could not find a scout record for <highlight>{$playfield->short_name} {$site->site}<end>.";
		} else {
			$msg = "<highlight>{$playfield->short_name} {$site->site}<end> removed successfully.";
			$rEvent = new SyncRemscoutEvent();
			$rEvent->playfield_id = $playfield->id;
			$rEvent->site_number = $site->site;
			$rEvent->scouted_by = $context->char->name;
			$rEvent->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($rEvent);
		}
		$context->reply($msg);
	}

	/**
	 * Scout a tower site by pasting the CT blob info.
	 * If the field is unplanted, use 'none'
	 */
	#[NCA\HandlesCommand("scout")]
	#[NCA\Help\Group("scout")]
	#[NCA\Help\Example(
		"<symbol>scout PW 4 Control Tower - Clan ".
		"Level: 190 ".
		"Danger level: Killing it poses no danger. ".
		"Might attack you on sight. ".
		"Alignment: clan  ".
		"Organization: Dark Ninjas ".
		"Created at UTC: 2014-05-19 12:34:56"
	)]
	#[NCA\Help\Example("<symbol>scout PW 4 none")]
	public function scoutCommand(CmdContext $context, PTowerSite $site, string $text): void {
		$this->scoutInputHandler($context, $site->pf, $site->site, $text);
	}

	/** Show the tower types by QL of the tower */
	#[NCA\HandlesCommand("towertype")]
	#[NCA\Help\Group("tower")]
	public function towerTypeCommand(CmdContext $context): void {
		$blob = "<header2>Tower types by QL<end>";
		$minQL = 1;
		$roman = ["I", "II", "III", "IV", "V", "VI", "VII"];
		$qls = static::TOWER_TYPE_QLS;
		$qls[301] = 8;
		foreach ($qls as $ql => $type) {
			$maxQL = $ql - 1;
			$blob .= "\n<tab>" . $this->text->alignNumber($minQL, 3).
				" - ".
				$this->text->alignNumber($maxQL, 3).
				": Type " . $roman[$type-2];
			$minQL = $ql;
		}
		$msg = $this->text->makeBlob("Tower types by QL", $blob);
		$context->reply($msg);
	}

	/** Show how many towers you are allowed to plant. Add 'all' to show it generally */
	#[NCA\HandlesCommand("towerqty")]
	#[NCA\Help\Group("tower")]
	public function towerQtyCommand(CmdContext $context, #[NCA\Str("all")] ?string $all): Generator {
		if (isset($all)) {
			$msg = $this->text->makeBlob("Allowed number of towers", $this->getAllTowerQuantitiesBlob());
			$context->reply($msg);
			return;
		}

		/** @var ?Player */
		$player = yield $this->playerManager->byName($context->char->name);
		$blob = $this->getAllTowerQuantitiesBlob();
		if (!isset($player)) {
			$msg = $this->text->makeBlob("Allowed number of towers", $blob);
			$context->reply($msg);
			return;
		}
		if ($player->level < 15) {
			$msg = "Your level is too low to have any towers.";
		} elseif ($player->level < 75) {
			$msg = "Your level ({$player->level}) allows you to have <highlight>1<end> tower.";
		} elseif ($player->level < 150) {
			$msg = "Your level ({$player->level}) allows you to have <highlight>2<end> towers.";
		} elseif ($player->level < 200) {
			$msg = "Your level ({$player->level}) allows you to have <highlight>3<end> towers.";
		} else {
			$msg = "Your level ({$player->level}) allows you to have <highlight>4<end> towers.";
		}
		$msg = $this->text->blobWrap(
			$msg . " ",
			$this->text->makeBlob("See full list", $blob, "Towers by level")
		);
		$context->reply($msg);
	}

	#[
		NCA\NewsTile(
			name: "tower-own",
			description: "Show the last 5 attacks on your org's towers from the last 3\n".
				"days - or nothing, if no attacks occurred.",
			example: "<header2>Notum Wars [<u>see more</u>]<end>\n".
				"<tab>22-Oct-2021 18:20 UTC - Nady (<clan>Team Rainbow<end>) attacked <u>CLON 6</u> (QL 35-50):"
		)
	]
	public function towerOwnTile(string $sender, callable $callback): void {
		asyncCall(function () use ($sender, $callback): Generator {
			try {
				/** @var ?Player */
				$whois = yield $this->playerManager->byName($sender);
				$text = $this->getTowerSelfTile($whois);
			} catch (Throwable) {
				$text = null;
			}
			$callback($text);
		});
	}

	protected function formatApiSiteInfo(ApiSite $site, ?Playfield $pf=null, bool $showOrgLinks=true): string {
		if (!isset($pf)) {
			$pf = new Playfield();
			$pf->id = $site->playfield_id;
			$pf->long_name = $site->playfield_long_name;
			$pf->short_name = $site->playfield_short_name;
		}
		$waypointLink = $this->text->makeChatcmd($site->x_coord . "x" . $site->y_coord, "/waypoint {$site->x_coord} {$site->y_coord} {$pf->id}");
		$attacksLink = $this->text->makeChatcmd("Recent attacks", "/tell <myname> attacks {$pf->short_name} {$site->site_number}");
		$victoryLink = $this->text->makeChatcmd("Recent victories", "/tell <myname> victory {$pf->short_name} {$site->site_number}");

		$blob = "<header2>{$pf->short_name} {$site->site_number} ({$site->site_name})<end>\n";
		$blob .= "<tab>Level range: <highlight>{$site->min_ql}-{$site->max_ql}<end>\n";
		if (isset($site->ql)) {
			$blob .= "<tab>Planted: <highlight>".
				(isset($site->created_at) ? $this->util->date($site->created_at) : "Unknown") . "<end>\n".
				"<tab>CT: QL <highlight>{$site->ql}<end>, Type " . $this->qlToSiteType($site->ql) . " ".
				"(<" . strtolower($site->faction??"neutral") .">{$site->org_name}<end>)";
			if ($showOrgLinks) {
				$orgLink = $this->text->makeChatcmd(
					"show sites",
					"/tell <myname> sites {$site->org_id}"
				);
				$blob .= " [{$orgLink}]";
			}
			$blob .= "\n";
			if (isset($site->close_time)) {
				$gas = $this->getGasLevel($site->close_time);
				if ($gas->gas_level === "75%" && ($site->penalty_until??0) >= time() && $site->penalty_until >= $gas->time_until_close_time) {
					$blob .= "<tab>Gas: <green>25%<end>, closes in ".
						$this->util->unixtimeToReadable(($site->penalty_until??0) - time()) . "\n";
				} else {
					$blob .= "<tab>Gas: {$gas->color}{$gas->gas_level}<end>, {$gas->next_state} in ".
						$this->util->unixtimeToReadable($gas->gas_change, false) . "\n";
				}
			}
		} elseif ($site->source === "api" || $site->source === "empty") {
			$blob .= "<tab>Planted: <highlight>No<end>\n";
		} else {
			$blob .= "<tab>Planted: <highlight>Unknown<end>\n";
		}
		$blob .= "<tab>Center coordinates: {$waypointLink}\n".
			"<tab>{$attacksLink}\n".
			"<tab>{$victoryLink}";

		return $blob;
	}

	/**
	 * Merge local scout data into API results and vice versa
	 *
	 * @param Collection<ScoutInfoPlus> $local
	 */
	protected function mergeLocalToAPI(Collection $local, ApiResult $api): ApiResult {
		$result = [];

		/** @var array<int,array<int,ApiSite>> */
		$apiSites = [];
		foreach ($api->results as $apiSite) {
			$apiSites[$apiSite->playfield_id] ??= [];
			$apiSites[$apiSite->playfield_id][$apiSite->site_number] = $apiSite;
		}
		foreach ($local as $localSite) {
			/** @var ?ApiSite */
			$apiSite = $apiSites[$localSite->playfield_id][$localSite->site_number] ?? null;
			if (isset($apiSite) && $this->isApiVersionNewer($apiSite, $localSite)) {
				$this->remScoutSite($apiSite->playfield_id, $apiSite->site_number);
				continue;
			}
			unset($apiSites[$localSite->playfield_id][$localSite->site_number]);
			$mapper = new ObjectMapperUsingReflection();
			$result []= $mapper->hydrateObject(ApiSite::class, (array)$localSite);
		}
		foreach ($apiSites as $pfId => $siteList) {
			foreach ($siteList as $siteId => $apiSite) {
				$result []= $apiSite;

				/** @var ?ScoutInfo */
				$localSite = $this->db->table("scout_info")
					->where("playfield_id", $apiSite->playfield_id)
					->where("site_number", $apiSite->site_number)
					->asObj(ScoutInfo::class)
					->first();
				if ($this->isApiVersionNewer($apiSite, $localSite)) {
					$this->remScoutSite($apiSite->playfield_id, $apiSite->site_number);
				}
			}
		}
		return new ApiResult(
			count: count($result),
			results: $result
		);
	}

	/** @param array<string,mixed> $params */
	protected function renderHotSites(ApiResult $result, array $params, int $time): string {
		$time += time();
		$sites = new Collection($result->results);
		$fromTime = (new DateTime())->setTimestamp($params["min_close_time"]);
		$toTime = (new DateTime())->setTimestamp($params["max_close_time"]);
		if ($fromTime > $toTime) {
			$toTime->modify("+1 day");
		}
		$sites = $sites->filter(function (ApiSite $site) use ($fromTime, $toTime, $time): bool {
			$i = (new DateTime())->setTimestamp($site->close_time??0);
			return ($fromTime <= $i  && $i <= $toTime)
				|| ($fromTime <= $i->modify('+1 day') && $i <= $toTime)
				|| ($site->penalty_until >= $time);
		});
		$result->count = $sites->count();
		$grouping = $this->towerHotGroup;
		if ($grouping === 1) {
			$sites = $sites->sortBy("site_number");
			$grouped = $sites->groupBy("playfield_long_name");
		} elseif ($grouping === 2) {
			$sites = $sites->sortBy("ql");
			$grouped = $sites->groupBy(function (ApiSite $site): string {
				return "TL" . $this->util->levelToTL($site->ql??1);
			});
		} elseif ($grouping === 3) {
			$sites = $sites->sortBy("ql");
			$grouped = $sites->groupBy("org_name");
		} else {
			throw new Exception("Invalid grouping found");
		}
		$grouped = $grouped->sortKeys();
		$blob = $grouped->map(function (Collection $sites, string $short) use ($params, $time): string {
			return "<pagebreak><header2>{$short}<end>\n".
				$sites->map(function (ApiSite $site) use ($params, $time): string {
					$shortName = $site->playfield_short_name . " " . $site->site_number;
					$line = "<tab>".
						$this->text->makeChatcmd(
							$shortName,
							"/tell <myname> <symbol>lc {$shortName}"
						);
					$line .= " QL {$site->min_ql}/<highlight>{$site->ql}<end>/{$site->max_ql} -";
					$factionColor = "";
					if (isset($site->faction)) {
						$factionColor = "<" . strtolower($site->faction) . ">";
						$org = $site->org_name ?? $site->faction;
						$line .= " {$factionColor}{$org}<end>";
					} else {
						$line .= " &lt;Free or unknown planter&gt;";
					}
					if (isset($site->close_time)) {
						$gas = $this->getGasLevel($site->close_time, (int)$params["min_close_time"]);
						if (isset($site->penalty_until) && $gas->gas_level === "75%" && $site->penalty_until >= $time && $site->penalty_until >= $gas->time_until_close_time) {
							$line .= " <green>25%<end>, closes in " . $this->util->unixtimeToReadable($site->penalty_until - $time);
						} else {
							$line .= " {$gas->color}{$gas->gas_level}<end>, {$gas->next_state} in ".
								$this->util->unixtimeToReadable($gas->gas_change, false);
						}
					} else {
						$line .= " unknown gas level";
					}
					return $line;
				})->join("\n");
		})->join("\n\n");
		if ($result->count === 50) {
			$blob .= "\n\n\n<i>Number of matches limited to 50. ".
				"Please use filtering to see the rest.</i>";
		}
		return $blob;
	}

	/** Set a timer to warn 1m, 5s and 0s before you can plant */
	protected function setPlantTimer(string $timerLocation): void {
		$start = time();

		/** @var Alert[] */
		$alerts = [];

		$alert = new Alert();
		$alert->time = $start;
		$alert->message = "Started countdown for planting {$timerLocation}";
		$alerts []= $alert;

		$alert = new Alert();
		$alert->time = $start + 19*60;
		$alert->message = "<highlight>1 minute<end> remaining to plant {$timerLocation}";
		$alerts []= $alert;

		$countdown = [5, 4, 3, 2, 1];
		if ($this->towerPlantTimer === 2) {
			$countdown = [5];
		}
		foreach ($countdown as $remaining) {
			$alert = new Alert();
			$alert->time = $start + 20*60-$remaining;
			$alert->message = "<highlight>{$remaining}s<end> remaining to plant ".strip_tags($timerLocation);
			$alerts []= $alert;
		}

		$alertPlant = new Alert();
		$alertPlant->time = $start + 20*60;
		$alertPlant->message = "Plant {$timerLocation} <highlight>NOW<end>";
		$alerts []= $alertPlant;

		// Sometimes, they overlap, so make sure any previous timer
		// is removed first
		$this->timerController->remove(
			"Plant " . strip_tags($timerLocation)
		);

		$this->timerController->add(
			"Plant " . strip_tags($timerLocation),
			$this->chatBot->char->name,
			$this->towerPlantTimer === 1 ? "priv" : "guild",
			$alerts,
			'timercontroller.timerCallback'
		);
	}

	protected function attacksCommandHandler(?int $pageLabel, ?Closure $where, string $cmd, CommandReply $sendto): void {
		if ($pageLabel === null) {
			$pageLabel = 1;
		} elseif ($pageLabel < 1) {
			$msg = "You must choose a page number greater than 0";
			$sendto->reply($msg);
			return;
		}

		$pageSize = $this->towerPageSize;
		$startRow = ($pageLabel - 1) * $pageSize;

		$query = $this->db->table(self::DB_TOWER_ATTACK)
			->orderByDesc("time")
			->limit($pageSize)
			->offset($startRow);
		if (isset($where)) {
			$query->where($where);
		}
		$sites = $this->db->table("tower_site")
			->asObj(TowerSite::class)
			->groupBy("playfield_id")
			->map(function (Collection $sites2): Collection {
				return $sites2->keyBy("site_number");
			});

		/** @var Collection<AttackPlus> */
		$attacks = $query->asObj(AttackPlus::class)
			->each(function (AttackPlus $att) use ($sites): void {
				if (!isset($att->playfield_id) || !isset($att->site_number)) {
					return;
				}
				$att->site = $sites->get($att->playfield_id)?->get($att->site_number)??null;
			});
		if ($attacks->isEmpty()) {
			$msg = "No tower attacks found.";
			$sendto->reply($msg);
			return;
		}
		$pfs = $this->playfieldController->searchPlayfieldsByIds(
			...$attacks->pluck("playfield_id")->filter()->toArray()
		)->keyBy("id");
		$attacks->each(function (AttackPlus $att) use ($pfs): void {
			$att->pf = $pfs->get($att->playfield_id);
		});
		$links = [];
		if ($pageLabel > 1) {
			$links['Previous Page'] = '/tell <myname> attacks ' . ($pageLabel - 1);
		}
		$links['Next Page'] = "/tell <myname> attacks {$cmd}" . ($pageLabel + 1);

		$blob = "The last {$pageSize} Tower Attacks (page {$pageLabel})\n\n";
		$blob .= $this->text->makeHeaderLinks($links) . "\n\n";

		foreach ($attacks as $attack) {
			$timeString = $this->util->unixtimeToReadable(time() - $attack->time);
			$blob .= "Time: " . $this->util->date($attack->time) . " (<highlight>{$timeString}<end> ago)\n";
			if ($attack->att_faction == '') {
				$att_faction = "unknown";
			} else {
				$att_faction = strtolower($attack->att_faction);
			}

			if ($attack->def_faction == '') {
				$def_faction = "unknown";
			} else {
				$def_faction = strtolower($attack->def_faction);
			}

			if ($attack->att_profession == 'Unknown') {
				$blob .= "Attacker: <{$att_faction}>{$attack->att_player}<end> ({$attack->att_faction})\n";
			} elseif (!isset($attack->att_guild_name) || $attack->att_guild_name === '') {
				$blob .= "Attacker: <{$att_faction}>{$attack->att_player}<end>";
				if (isset($attack->att_level)) {
					$blob .= " ({$attack->att_level}/<green>{$attack->att_ai_level}<end> ".
						"{$attack->att_profession})";
				}
				$blob .= "\n";
			} else {
				$blob .= "Attacker: <{$att_faction}>{$attack->att_player}<end> ".
					"({$attack->att_level}/<green>{$attack->att_ai_level}<end> ".
					"{$attack->att_profession}) ".
					"<{$att_faction}>{$attack->att_guild_name}<end>\n";
			}

			$blob .= "Defender: <{$def_faction}>{$attack->def_guild_name}<end> ({$attack->def_faction})\n";

			if (isset($attack->pf, $attack->site_number, $attack->site)) {
				$base = $this->text->makeChatcmd("{$attack->pf->short_name} {$attack->site_number}", "/tell <myname> lc {$attack->pf->short_name} {$attack->site_number}");
				$base .= " ({$attack->site->min_ql}-{$attack->site->max_ql})";
				$blob .= "Site: {$base}\n\n";
			} else {
				$blob .= "\n";
			}
		}
		$msg = $this->text->makeBlob("Tower Attacks", $blob);

		$sendto->reply($msg);
	}

	protected function victoryCommandHandler(int $pageLabel, ?Closure $search, string $cmd, CommandReply $sendto): void {
		if ($pageLabel < 1) {
			$msg = "You must choose a page number greater than 0";
			$sendto->reply($msg);
			return;
		}

		$pageSize = $this->towerPageSize;
		$startRow = ($pageLabel - 1) * $pageSize;

		$query = $this->db->table(self::DB_TOWER_VICTORY, "v")
			->leftJoin(self::DB_TOWER_ATTACK . " AS a", "a.id", "v.attack_id")
			->orderByDesc("victory_time")
			->limit($pageSize)
			->offset($startRow)
			->select("*", "v.time AS victory_time", "a.time AS attack_time");
		if ($search) {
			$query->where($search);
		}

		/** @var Collection<TowerVictoryPlus> */
		$data = $query->asObj(TowerVictoryPlus::class);
		if (count($data) == 0) {
			$msg = "No Tower results found.";
			$sendto->reply($msg);
			return;
		}
		$links = [];
		if ($pageLabel > 1) {
			$links['Previous Page'] = '/tell <myname> victory ' . ($pageLabel - 1);
		}
		$links['Next Page'] = "/tell <myname> victory {$cmd}" . ($pageLabel + 1);

		$blob = "The last {$pageSize} Tower Results (page {$pageLabel})\n\n";
		$blob .= $this->text->makeHeaderLinks($links) . "\n\n";
		$pfs = $this->playfieldController->searchPlayfieldsByIds(
			...$data->pluck("playfield_id")->filter()->toArray()
		)->keyBy("id");
		$sites = $this->db->table("tower_site")
			->asObj(TowerSite::class)
			->groupBy("playfield_id")
			->map(function (Collection $sites2): Collection {
				return $sites2->keyBy("site_number");
			});
		foreach ($data as $row) {
			$row->pf = $pfs->get($row->playfield_id);
			$row->site = $sites->get($row->playfield_id)?->get($row->site_number)??null;
			$timeString = $this->util->unixtimeToReadable(time() - $row->victory_time);
			$blob .= "Time: " . $this->util->date($row->victory_time) . " (<highlight>{$timeString}<end> ago)\n";

			if (!strlen($win_side = strtolower($row->win_faction??""))) {
				$win_side = "unknown";
			}
			if (!strlen($lose_side = strtolower($row->lose_faction??""))) {
				$lose_side = "unknown";
			}

			if ($row->playfield_id !== null && $row->site_number !== null) {
				$base = $this->text->makeChatcmd("{$row->pf->short_name} {$row->site_number}", "/tell <myname> lc {$row->pf->short_name} {$row->site_number}");
				$base .= " ({$row->site->min_ql}-{$row->site->max_ql})";
			} else {
				$base = "Unknown";
			}

			$blob .= "Winner: <{$win_side}>{$row->win_guild_name}<end>\n";
			$blob .= "Loser: <{$lose_side}>{$row->lose_guild_name}<end>\n";
			$blob .= "Site: {$base}\n\n";
		}
		$msg = $this->text->makeBlob("Tower Victories", $blob);

		$sendto->reply($msg);
	}

	protected function getClosestSite(int $playfieldID, int $xCoords, int $yCoords): ?TowerSite {
		/** @var ?int */
		$bbMatch = $this->db->table("tower_site_bounds")
			->where("playfield_id", $playfieldID)
			->where("x_coord1", "<=", $xCoords)
			->where("x_coord2", ">=", $xCoords)
			->where("y_coord1", ">=", $yCoords)
			->where("y_coord2", "<=", $yCoords)
			->select("site_number")
			->pluckInts("site_number")
			->first();
		if (isset($bbMatch)) {
			return $this->getTowerInfo($playfieldID, $bbMatch);
		}
		$zoneSites = $this->db->table("tower_site")
			->where("playfield_id", $playfieldID)
			->select("*")
			->asObj(TowerSite::class);
		if ($zoneSites->isEmpty()) {
			return null;
		}

		return $zoneSites->sort(
			function (TowerSite $site1, TowerSite $site2) use ($xCoords, $yCoords): int {
				return pow(abs($site1->x_coord - $xCoords), 2)
					+ pow(abs($site1->y_coord - $yCoords), 2)
					<=>
					pow(abs($site2->x_coord - $xCoords), 2)
					+ pow(abs($site2->y_coord - $yCoords), 2);
			}
		)->first();
	}

	protected function getLastAttack(string $attackFaction, string $attackOrgName, string $defendFaction, string $defendOrgName, int $playfieldID): ?TowerAttack {
		$time = time() - (7 * 3600);

		return $this->db->table(self::DB_TOWER_ATTACK)
			->where("att_guild_name", $attackOrgName)
			->where("att_faction", $attackFaction)
			->where("def_guild_name", $defendOrgName)
			->where("def_faction", $defendFaction)
			->where("playfield_id", $playfieldID)
			->where("time", ">=", $time)
			->limit(1)
			->asObj(TowerAttack::class)
			->first();
	}

	protected function recordAttack(Player $whois, Attack $attack, TowerSite $closestSite): int {
		$event = new TowerAttackEvent();
		$event->attacker = $whois;
		$event->defender = new TowerDefender();
		$event->defender->org = $attack->defGuild;
		$event->defender->faction = $attack->defSide;
		$event->site = $closestSite;
		$event->type = "tower(attack)";
		$result = $this->db->table(self::DB_TOWER_ATTACK)
			->insert([
				"time" => time(),
				"att_guild_name" => $whois->guild ?? null,
				"att_faction" => $whois->faction ?? null,
				"att_player" => $whois->name ?? null,
				"att_level" => $whois->level ?? null,
				"att_ai_level" => $whois->ai_level ?? null,
				"att_profession" => $whois->profession ?? null,
				"def_guild_name" => $attack->defGuild,
				"def_faction" => $attack->defSide,
				"playfield_id" => $closestSite->playfield_id,
				"site_number" => $closestSite->site_number,
				"x_coords" => $attack->xCoords,
				"y_coords" => $attack->yCoords,
			]) ? 1 : 0;
		$this->eventManager->fireEvent($event);
		// Mark all of this org's sites as in penalty
		if (isset($whois->guild)) {
			$this->db->table("scout_info")
				->where("org_name", $whois->guild)
				->asObj(ScoutInfo::class)
				->each(function (ScoutInfo $info): void {
					if (!isset($info->close_time)) {
						return;
					}
					$duration = 3600 + $info->close_time % 3600;
					$this->db->table("scout_info")
						->where("playfield_id", $info->playfield_id)
						->where("site_number", $info->site_number)
						->update([
							"penalty_duration" => $duration,
							"penalty_until" => time() + $duration,
						]);
				});
		}

		return $result;
	}

	protected function getLastVictory(int $playfieldID, int $siteNumber): ?TowerAttackAndVictory {
		return $this->db->table(self::DB_TOWER_VICTORY, "v")
			->join(self::DB_TOWER_ATTACK . " AS a", "a.id", "v.attack_id")
			->where("a.playfield_id", $playfieldID)
			->where("a.site_number", "=", $siteNumber)
			->orderByDesc("v.time")
			->limit(1)
			->asObj(TowerAttackAndVictory::class)
			->first();
	}

	protected function recordVictory(TowerAttack $attack): int {
		if (isset($attack->site_number, $attack->playfield_id)) {
			// If we know which field was destroyed, mark it unplanted
			$scout = new ScoutInfo();
			$scout->scouted_on = time();
			$scout->scouted_by = $this->chatBot->char->name;
			$scout->playfield_id = $attack->playfield_id;
			$scout->site_number = $attack->site_number;
			$this->addScoutSite($scout);
			$event = SyncScoutEvent::fromScoutInfo($scout);
			$this->eventManager->fireEvent($event);
		} elseif (isset($attack->playfield_id)) {
			// If we don't know the exact field, mark all the fields of this org
			// in that playfield as unscouted
			$this->db->table("scout_info")
				->where("playfield_id", $attack->playfield_id)
				->where("org_name", $attack->def_guild_name)
				->where("faction", $attack->def_faction)
				->delete();
		}
		return $this->db->table(self::DB_TOWER_VICTORY)
			->insertGetId([
				"time" => time(),
				"win_guild_name" => $attack->att_guild_name,
				"win_faction" => $attack->att_faction,
				"lose_guild_name" => $attack->def_guild_name,
				"lose_faction" => $attack->def_faction,
				"attack_id" => $attack->id,
			]);
	}

	protected function addScoutSite(ScoutInfo $scoutInfo): bool {
		if ($this->db->update("scout_info", ["playfield_id", "site_number"], $scoutInfo) > 0) {
			return true;
		}
		return $this->db->insert("scout_info", $scoutInfo, null) > 0;
	}

	protected function remScoutSite(int $playfield_id, int $site_number): int {
		return $this->db->table("scout_info")
			->where("playfield_id", $playfield_id)
			->where("site_number", $site_number)
			->delete();
	}

	protected function checkGuildName(string $guildName): bool {
		return $this->db->table(self::DB_TOWER_ATTACK)
			->whereIlike("att_guild_name", $guildName)
			->orWhereIlike("def_guild_name", $guildName)
			->exists();
	}

	protected function getGasLevel(int $closeTime, ?int $time=null): GasInfo {
		$time ??= time();
		$currentTime = $time % 86400;

		$site = new GasInfo();
		$site->current_time = $currentTime;
		$site->close_time = $closeTime;

		if ($closeTime < $currentTime) {
			$closeTime += 86400;
		}

		$timeUntilCloseTime = $closeTime - $currentTime;
		$site->time_until_close_time = $timeUntilCloseTime;

		if ($timeUntilCloseTime < 3600 * 1) {
			$site->gas_change = $timeUntilCloseTime;
			$site->gas_level = '5%';
			$site->next_state = 'closes';
			$site->color = "<orange>";
		} elseif ($timeUntilCloseTime < 3600 * 6) {
			$site->gas_change = $timeUntilCloseTime;
			$site->gas_level = '25%';
			$site->next_state = 'closes';
			$site->color = "<green>";
		} else {
			$site->gas_change = $timeUntilCloseTime - (3600 * 6);
			$site->gas_level = '75%';
			$site->next_state = 'opens';
			$site->color = "<red>";
		}

		return $site;
	}

	protected function formatSiteInfo(SiteInfo $row, ?ApiSite $site=null): string {
		$waypointLink = $this->text->makeChatcmd($row->x_coord . "x" . $row->y_coord, "/waypoint {$row->x_coord} {$row->y_coord} {$row->playfield_id}");
		$attacksLink = $this->text->makeChatcmd("Recent attacks", "/tell <myname> attacks {$row->short_name} {$row->site_number}");
		$victoryLink = $this->text->makeChatcmd("Recent victories", "/tell <myname> victory {$row->short_name} {$row->site_number}");

		$blob = "<pagebreak><header2>{$row->short_name} {$row->site_number} ({$row->site_name})<end>\n".
			"<tab>Level range: <highlight>{$row->min_ql}-{$row->max_ql}<end>\n";
		if (isset($site->ql, $site->close_time)) {
			if (isset($site->created_at)) {
				$blob .= "<tab>Planted: <highlight>" . $this->util->date($site->created_at) . "<end>\n";
			}
			$blob .= "<tab>CT: QL <highlight>{$site->ql}<end>, Type " . $this->qlToSiteType($site->ql) . " ".
				"(<" . strtolower($site->faction??"neutral") .">{$site->org_name}<end>)";
			$orgLink = $this->text->makeChatcmd(
				"show sites",
				"/tell <myname> sites {$site->org_id}"
			);
			$blob .= " [{$orgLink}]\n";
			$gas = $this->getGasLevel($site->close_time);
			if (isset($site->penalty_until) && $gas->gas_level === "75%" && $site->penalty_until >= time() && $site->penalty_until >= $gas->time_until_close_time) {
				$blob .= "<tab>Gas: <green>25%<end>, closes in ".
					$this->util->unixtimeToReadable($site->penalty_until - time()) . "\n";
			} else {
				$blob .= "<tab>Gas: {$gas->color}{$gas->gas_level}<end>, {$gas->next_state} in ".
					$this->util->unixtimeToReadable($gas->gas_change, false) . "\n";
			}
		} elseif (isset($site)) {
			if ($site->source === "api" || $site->source === "empty") {
				$blob .= "<tab>Planted: <highlight>No<end>\n";
			} else {
				$blob .= "<tab>Planted: <highlight>Unknown<end>\n";
			}
		} elseif (!$row->enabled) {
			$blob .= "<tab>Planted: <highlight>This site is disabled<end>\n";
		}
		$blob .= "<tab>Center coordinates: {$waypointLink}\n".
			"<tab>{$attacksLink}\n".
			"<tab>{$victoryLink}";

		return $blob;
	}

	/** @return string[] */
	protected function makeBlob(string $name, string $content): array {
		$content = trim($content);
		$lines = explode("\n", $content);
		$content .= "\n\n";
		if (strpos($lines[count($lines)-1], "<i>") === false) {
			$content .= "\n";
		}
		$content .= "<i>Tower API provided by Tyrence, ".
			"tower information provided by Draex and Unk</i>";
		return (array)$this->text->makeBlob($name, $content);
	}

	protected function scoutInputHandler(CmdContext $context, string $playfieldName, int $siteNumber, string $tower): void {
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$context->reply("Invalid playfield <highlight>{$playfieldName}<end>.");
			return;
		}
		$towerInfo = $this->getTowerInfo($playfield->id, $siteNumber);
		if ($towerInfo === null) {
			$context->reply("Invalid site number <highlight>{$playfield->long_name} {$siteNumber}<end>.");
			return;
		}
		$ctPattern = "@".
			"Control Tower - (?<faction>[^ ]+)\s+".
			"Level: (?<ql>\d+)\s+".
			"Danger level:\s+(.+)\s+".
			"Alignment:\s+([^ ]+)\s+".
			"Organization:\s+(?<org_name>.+)\s+".
			"Created at UTC:\s+(?<created>[^ ]+ [^ ]+)".
			"@si";
		if (!preg_match($ctPattern, $tower, $arr)) {
			if (preg_match("/^(empty|free|un-?planted|clea[rn]|none)$/i", $tower)) {
				$scoutInfo = new ScoutInfo();
				$scoutInfo->scouted_on = time();
				$scoutInfo->scouted_by = $context->char->name;
				$scoutInfo->playfield_id = $playfield->id;
				$scoutInfo->site_number = $siteNumber;
				$scoutInfo->source = "empty";
				$this->addScoutSite($scoutInfo);
				$context->reply("<highlight>{$playfield->short_name} {$siteNumber}<end> marked as unplanted.");
				return;
			}
			$context->reply("Please capture the whole tower string.");
			return;
		}
		$scoutInfo = new ScoutInfo();
		$scoutInfo->playfield_id = $playfield->id;
		$scoutInfo->site_number = $siteNumber;
		$scoutInfo->scouted_on = time();
		$scoutInfo->scouted_by = $context->char->name;
		$scoutInfo->created_at = (new DateTime($arr['created']))->getTimestamp();
		$scoutInfo->close_time = $scoutInfo->created_at % 86400;
		if ($towerInfo->timing > 0) {
			$scoutInfo->close_time = static::FIXED_TIMES[$towerInfo->timing] * 3600
				+ $scoutInfo->created_at % 3600;
		}
		$scoutInfo->org_name = $arr['org_name'];
		$scoutInfo->ql = (int)$arr['ql'];
		$scoutInfo->faction = $this->getFaction($arr['faction']);
		if ($scoutInfo->ql < $towerInfo->min_ql || $scoutInfo->ql > $towerInfo->max_ql) {
			$context->reply(
				"<highlight>{$playfield->short_name} {$towerInfo->site_number}<end> ".
				"can only accept Control Tower of a ql between ".
				"<highlight>{$towerInfo->min_ql}<end> and <highlight>{$towerInfo->max_ql}<end>."
			);
			return;
		}
		if (!in_array($scoutInfo->faction, ['Omni', 'Neutral', 'Clan'])) {
			$context->reply("Valid values for faction are: 'Omni', 'Neutral', and 'Clan'.");
			return;
		}

		if ($this->addScoutSite($scoutInfo)) {
			$context->reply("Scout information recorded for {$playfield->short_name} {$towerInfo->site_number}.");
			$event = SyncScoutEvent::fromScoutInfo($scoutInfo);
			$event->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($event);
			return;
		}
		$context->reply("There was an unknown error recording this scout information, please check the logs.");
	}

	/**
	 * Query the API for a list of all sites of an org and return them rendered
	 *
	 * @return Promise<string[]>
	 */
	private function fetchAndRenderSitesOfOrg(int $orgId): Promise {
		return call(function () use ($orgId): Generator {
			$sites = $this->getScoutPlusQuery()
				->asObj(ScoutInfoPlus::class);
			$this->addPlusToScout($sites);
			$sites = $sites->where("org_id", $orgId);
			if ($this->towerApiController->isActive()) {
				$params = ["enabled" => "1", "org_id" => $orgId];

				/** @var ?ApiResult */
				$apiResult = yield $this->towerApiController->call2($params);
			} else {
				$apiResult = $this->scoutToAPI($sites);
				$sites = null;
			}
			return $this->renderOrgSites($apiResult, $sites, $orgId);
		});
	}

	/**
	 * Show the result of the sites of org query to $sendto
	 *
	 * @param null|Collection<ScoutInfoPlus> $local
	 *
	 * @return string[]
	 */
	private function renderOrgSites(?ApiResult $result, ?Collection $local, int $orgId): array {
		if (isset($result, $local)) {
			$result = $this->mergeLocalToAPI($local, $result);
		} elseif (isset($local)) { // @phpstan-ignore-line
			$result = $this->scoutToAPI($local);
		}
		if (!isset($result)) {
			throw new UserException("Invalid data received from the tower API. Try again later.");
		}
		if ($result->count === 0) {
			$org = $this->findOrgController->getByID($orgId);
			if (isset($org)) {
				return ["No sites found for <" . strtolower($org->faction) . ">{$org->name}<end>."];
			}
			return ["No sites found for this org."];
		}
		usort($result->results, fn (ApiSite $a, ApiSite $b) => $a->ql <=> $b->ql);
		$blob = '';
		$totalQL = 0;
		usort($result->results, fn (ApiSite $a, ApiSite $b) => $a->ql <=> $b->ql);
		foreach ($result->results as $site) {
			$totalQL += $site->ql ?? 0;
			$blob .= "<pagebreak>" . $this->formatApiSiteInfo($site, null, false) . "\n\n";
		}
		$blob .= "\nTotal: QL <highlight>{$totalQL}<end>, allowing ".
			"contracts up to QL <highlight>" . ($totalQL * 2) . "<end>.";

		return $this->makeBlob("All bases of {$result->results[0]->org_name}", $blob);
	}

	/**
	 * @param null|Collection<ScoutInfoPlus> $local
	 *
	 * @return string[]
	 */
	private function renderPenaltySites(?ApiResult $result, ?Collection $local): array {
		if (isset($result, $local)) {
			$result = $this->mergeLocalToAPI($local, $result);
		} elseif (isset($local)) { // @phpstan-ignore-line
			$result = $this->scoutToAPI($local);
		}
		if (!isset($result)) {
			throw new UserException("Invalid data received from the tower API. Try again later.");
		}
		if ($result->count === 0) {
			return ["No sites are currently in penalty."];
		}
		$params = [
			"enabled" => "true",
			"min_close_time" => time() % 84600,
			"max_close_time" => (time() + 6 * 3600) % 86400,
		];
		if ($result->results[0]->penalty_until === 0) {
			throw new UserException("The API currently doesn't support penalty queries. Try again later.");
		}
		$blob = $this->renderHotSites($result, $params, 0);
		return $this->makeBlob("Sites in penalty (" . $result->count . ")", $blob);
	}

	/**
	 * @param null|Collection<ScoutInfoPlus> $local
	 *
	 * @return string[]
	 */
	private function renderSite(?ApiResult $result, ?Collection $local, SiteInfo $site, Playfield $playfield): array {
		$details = null;
		if (isset($result, $local)) {
			$result = $this->mergeLocalToAPI($local, $result);
		} elseif (isset($local)) { // @phpstan-ignore-line
			$result = $this->scoutToAPI($local);
		}
		if (isset($result)) {
			$results = new Collection($result->results);
			$details = $results->firstWhere("site_number", "===", $site->site_number);
		}
		$blob = $this->formatSiteInfo($site, $details) . "\n\n";

		// show last attacks and victories
		$query = $this->db->table(self::DB_TOWER_ATTACK, "a")
			->leftJoin(self::DB_TOWER_VICTORY . " AS v", "v.attack_id", "a.id")
			->where("a.playfield_id", $playfield->id)
			->where("a.site_number", $site->site_number);
		$query->orderByDesc($query->colFunc("COALESCE", ["v.time", "a.time"]))
			->limit(10)
			->select("a.*", "v.*");

		/** @var Collection<TowerAttackAndVictory> */
		$attacks = $query->asObj(TowerAttackAndVictory::class);
		if ($attacks->isNotEmpty()) {
			$blob .= "<header2>Recent Attacks<end>\n";
		}
		foreach ($attacks as $attack) {
			if (empty($attack->attack_id)) {
				// attack
				if (!empty($attack->att_guild_name)) {
					$name = $attack->att_guild_name;
				} else {
					$name = $attack->att_player ?? "Unknown player";
				}
				$attFaction = strtolower($attack->att_faction ?? "highlight");
				$defFaction = strtolower($attack->def_faction ?? "highlight");
				$blob .= "<tab><{$attFaction}>{$name}<end> attacked <{$defFaction}>{$attack->def_guild_name}<end>\n";
			} else {
				// victory
				$blob .= "<tab><{$attack->win_faction}>{$attack->win_guild_name}<end> won against <{$attack->lose_faction}>{$attack->lose_guild_name}<end>\n";
			}
		}

		if (isset($details)) {
			$msg = $this->makeBlob("{$playfield->short_name} {$site->site_number}", $blob);
		} else {
			$msg = (array)$this->text->makeBlob("{$playfield->short_name} {$site->site_number}", $blob);
		}

		return $msg;
	}

	/**
	 * Render the API-result of a whole playfield
	 *
	 * @param null|Collection<ScoutInfoPlus> $local
	 * @param Collection<SiteInfo>           $data
	 *
	 * @return string[]
	 */
	private function renderArea(?ApiResult $result, ?Collection $local, Collection $data, Playfield $pf): array {
		$blob = '';
		if (isset($result, $local)) {
			$result = $this->mergeLocalToAPI($local, $result);
		} elseif (isset($local)) { // @phpstan-ignore-line
			$result = $this->scoutToAPI($local);
		}
		if (isset($result)) {
			usort($result->results, function (ApiSite $a, ApiSite $b): int {
				return $a->site_number <=> $b->site_number;
			});
		}
		if ($result === null || $result->count === 0) {
			foreach ($data as $row) {
				$blob .= "<pagebreak>" . $this->formatSiteInfo($row) . "\n\n";
			}
		} else {
			foreach ($result->results as $site) {
				$blob .= "<pagebreak>" . $this->formatApiSiteInfo($site, $pf) . "\n\n";
			}
		}

		return $this->makeBlob("All Bases in {$pf->long_name}", $blob);
	}

	private function removeScoutedSitesWhichAreRemoved(ApiResult $result): ApiResult {
		$result = clone $result;
		// Remove all sites for which we have local scout data
		$sites = array_values(
			array_filter($result->results, function (ApiSite $site): bool {
				$query = $this->getScoutPlusQuery()
					->where("s.playfield_id", $site->playfield_id)
					->where("s.site_number", $site->site_number)
					->limit(1);
				$scoutedInfo = $query->asObj(ScoutInfoPlus::class);
				$this->addPlusToScout($scoutedInfo);

				/** @var ?ScoutInfoPlus */
				$scoutedInfo = $scoutedInfo->first();
				if (!isset($scoutedInfo) || !isset($scoutedInfo->ql)) {
					return true;
				}
				return false;
			})
		);
		$result->results = $sites;
		$result->count = count($sites);
		return $result;
	}

	/** @param Collection<ScoutInfoPlus> $data */
	private function addPlusToScout(Collection $data): void {
		$pfs = $this->playfieldController->searchPlayfieldsByIds(
			...$data->pluck("playfield_id")->filter()->toArray()
		)->keyBy("id");
		$orgs = $this->findOrgController->getOrgsByName(
			...$data->pluck("org_name")->filter()->toArray()
		)->keyBy("name");
		foreach ($data as $scout) {
			/** @var ?Playfield */
			$pf = $pfs->get($scout->playfield_id);
			if (isset($pf)) {
				$scout->playfield_long_name = $pf->long_name;
				$scout->playfield_short_name = $pf->short_name;
			}

			/** @var ?Organization */
			$org = $orgs->get($scout->org_name);
			$scout->org_id = $org->id ?? null;
		}
	}

	private function getAllTowerQuantitiesBlob(): string {
		return "<header2>Number of towers by level<end>\n".
			"<tab>Level <black>00<end>1 - <black>0<end>14: <highlight>0<end> towers\n".
			"<tab>Level <black>0<end>15 - <black>0<end>74: <highlight>1<end> tower\n".
			"<tab>Level <black>0<end>75 - 149: <highlight>2<end> towers\n".
			"<tab>Level 150 - 199: <highlight>3<end> towers\n".
			"<tab>Level 200 - 220: <highlight>4<end> towers\n";
	}

	private function getTowerSelfTile(?Player $whois): ?string {
		if (!isset($whois) || !isset($whois->guild)) {
			return null;
		}
		$query = $this->db->table(self::DB_TOWER_ATTACK, "a")
			->leftJoin(self::DB_TOWER_VICTORY . " AS v", "v.attack_id", "a.id")
			->where("a.def_guild_name", $whois->guild)
			->where("a.time", ">", time() - 3600*72)
			->select("a.*", "v.*", "a.time AS attack_time", "v.time AS victory_time");
		$attacks = $query->orderByDesc($query->colFunc("COALESCE", ["v.time", "a.time"]))
			->limit(5)
			->asObj(TowerVictoryPlus::class);
		if ($attacks->isEmpty()) {
			return null;
		}
		$pfs = $this->playfieldController->searchPlayfieldsByIds(
			...$attacks->pluck("playfield_id")->filter()->toArray()
		)->keyBy("id");
		$sites = $this->db->table("tower_site")
			->asObj(TowerSite::class)
			->groupBy("playfield_id")
			->map(function (Collection $sites2): Collection {
				return $sites2->keyBy("site_number");
			});
		$blob = $attacks->map(
			function (TowerVictoryPlus $attack) use ($sites, $pfs): string {
				$attack->pf = $pfs->get($attack->playfield_id);
				$attack->site = $sites->get($attack->playfield_id)?->get($attack->site_number)??null;
				$line = "<tab>" . $this->util->date($attack->attack_time) . " - ";
				if (empty($attack->attack_id)) {
					// attack
					$attFaction = strtolower($attack->att_faction ?? "highlight");
					if (!empty($attack->att_guild_name)) {
						$line .= "{$attack->att_player} (<{$attFaction}>{$attack->att_guild_name}<end>)";
					} else {
						$line .= "<{$attFaction}>" . ($attack->att_player ?? "Unknown player") . "<end>";
					}
					$line .= " attacked ";
				} else {
					// victory
					$line .= "<{$attack->win_faction}>{$attack->win_guild_name}<end> won in ";
				}
				if (isset($attack->pf)) {
					$line .= $this->text->makeChatcmd(
						"{$attack->pf->short_name} {$attack->site_number}",
						"/waypoint {$attack->x_coords} {$attack->y_coords} {$attack->playfield_id}"
					);
				} else {
					$line .= "unknown";
				}
				if (isset($attack->site)) {
					$line .= " (QL {$attack->site->min_ql}-{$attack->site->max_ql})";
				}
				return $line;
			}
		)->join("\n");
		$moreLink = $this->text->makeChatcmd("see more", "/tell <myname> attacks org {$whois->guild}");
		return "<header2>Notum Wars [{$moreLink}]<end>\n{$blob}";
	}
}
