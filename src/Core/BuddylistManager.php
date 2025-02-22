<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\call;
use function Amp\Promise\timeout;

use Amp\{Deferred, Promise, Success, TimeoutException};
use Generator;
use Nadybot\Core\Attributes as NCA;
use Throwable;

#[NCA\Instance]
class BuddylistManager {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Timer $timer;

	/**
	 * List of all players on the friendlist, real or just queued up
	 *
	 * @var array<int,BuddylistEntry>
	 */
	public array $buddyList = [];

	/**
	 * List of all characters currently queued for rebalancing
	 *
	 * @var array<int,bool>
	 */
	private array $inRebalance = [];

	/**
	 * List of all characters currently removed for rebalancing
	 *
	 * @var array<int,array<int,bool>>
	 */
	private array $pendingRebalance = [];

	private ?CommandReply $rebalancingCallback = null;

	/** Get the number of definitively used up buddy slots */
	public function getUsedBuddySlots(): int {
		return count(
			array_filter(
				$this->buddyList,
				function (BuddylistEntry $buddy): bool {
					return $buddy->known;
				}
			)
		);
	}

	/** Check if we are currently rebalancing (the given uid) */
	public function isRebalancing(?int $uid=null): bool {
		if (isset($uid)) {
			return isset($this->pendingRebalance[$uid]);
		}
		return count($this->pendingRebalance) > 0 || count($this->inRebalance) > 0;
	}

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isOnline(string $name): ?bool {
		if (strtolower($this->config->name) === strtolower($name)) {
			return true;
		}
		$workerNames = $this->chatBot->proxyCapabilities->workers ?? [];
		if (in_array(ucfirst(strtolower($name)), $workerNames, true)) {
			return true;
		}
		$buddy = $this->getBuddy($name);
		return $buddy?->online;
	}

	/**
	 * Check if $uid is online (true) or offline/inactive (false)
	 *
	 * @return Promise<bool>
	 */
	public function checkIsOnline(int $uid): Promise {
		$buddy = $this->buddyList[$uid] ?? null;
		$state = [];
		if (isset($buddy)) {
			if ($buddy->known) {
				$state []= 'known';
				if ($buddy->online) {
					$state []= 'online';
				} else {
					$state []= 'offline';
				}
			} else {
				$state []= 'unconfirmed on buddylist';
			}
		} else {
			$state []= 'not on the buddylist';
		}
		$this->logger->debug("Checking if UID {uid} is online. State: {state}", [
			"uid" => $uid,
			"state" => join(", ", $state),
		]);
		$buddyOnline = $this->isUidOnline($uid);
		if (isset($buddyOnline)) {
			return new Success($buddyOnline);
		}
		return call(function () use ($uid): Generator {
			$onlineKey = "is_online:" . microtime(true);
			$deferred = new Deferred();
			$resolver = function (UserStateEvent $event) use ($deferred, $uid, &$resolver): void {
				$this->logger->debug("Got {type}-event for UID {uid} ({name})", [
					"type" => $event->type,
					"uid" => $event->uid,
					"name" => $event->sender,
				]);
				if ($event->uid !== $uid) {
					$this->logger->debug("{uid} is not the UID we're waiting for {search}", [
						"uid" => $event->uid,
						"search" => $uid,
					]);
					return;
				}
				if (!$deferred->isResolved()) {
					$deferred->resolve($event->type === "logon");
				}
				$this->eventManager->unsubscribe("logon", $resolver);
				$this->eventManager->unsubscribe("logoff", $resolver);
			};
			$this->eventManager->subscribe("logon", $resolver);
			$this->eventManager->subscribe("logoff", $resolver);
			$this->addId($uid, $onlineKey);
			try {
				$this->logger->debug("UID {uid} added to the buddylist, waiting for online/offline event", [
					"uid" => $uid,
				]);

				/** @var bool */
				$isOnline = yield timeout($deferred->promise(), 30000);
				$this->logger->debug("UID {uid} is {state}", [
					"uid" => $uid,
					"state" => $isOnline ? "online" : "offline",
				]);
			} catch (TimeoutException $e) {
				$this->logger->warning("No reply for the online-state of UID {uid} for 30s", [
					"uid" => $uid,
				]);
				$isOnline = false;
			} catch (Throwable $e) {
				$isOnline = false;
			} finally {
				$this->removeId($uid, $onlineKey);
			}
			return $isOnline;
		});
	}

	/**
	 * Check if a friend is online
	 *
	 * @return bool|null null when online status is unknown, true when buddy is online, false when buddy is offline
	 */
	public function isUidOnline(int $uid): ?bool {
		if ($this->chatBot->char->id === $uid) {
			return true;
		}
		$workerUids = $this->chatBot->proxyCapabilities->worker_uids ?? [];
		if (in_array($uid, $workerUids, true)) {
			return true;
		}
		$buddy = $this->buddyList[$uid] ?? null;
		return ($buddy && $buddy->known) ? $buddy->online : null;
	}

	/**
	 * Get how many friends are really on the buddylist
	 * This ignores the ones that are only queued up for addition
	 */
	public function countConfirmedBuddies(): int {
		return count(
			array_filter(
				$this->buddyList,
				function (BuddylistEntry $entry): bool {
					return $entry->known;
				}
			)
		);
	}

	/** Get information stored about a friend */
	public function getBuddy(string $name): ?BuddylistEntry {
		/** Never trigger an actual ID lookup. If we don't have a buddy's ID, it's inactive */
		$uid = $this->chatBot->id[ucfirst(strtolower($name))] ?? false;
		if ($uid === false || !isset($this->buddyList[$uid])) {
			return null;
		}
		return $this->buddyList[$uid];
	}

	/**
	 * Get the names of all people in the friendlist who are online
	 *
	 * @return string[]
	 */
	public function getOnline(): array {
		$result = [];
		foreach ($this->buddyList as $uid => $data) {
			if ($data->online) {
				$result []= $data->name;
			}
		}
		return $result;
	}

	/**
	 * Add a user to the bot's friendlist for a given purpose
	 *
	 * @deprecated 6.1.0
	 *
	 * @param string $name The name of the player
	 * @param string $type The reason why to add ("member", "admin", "org", "onlineorg", "is_online", "tracking")
	 *
	 * @return bool true on success, otherwise false
	 */
	public function add(string $name, string $type): bool {
		$uid = $this->chatBot->get_uid($name);
		if ($uid === false || $type == '') {
			return false;
		}
		return $this->addId($uid, $type);
	}

	/** @return Promise<bool> */
	public function addAsync(string $name, string $type): Promise {
		return call(function () use ($name, $type): Generator {
			$uid = yield $this->chatBot->getUid2($name);
			if ($uid === null || $type === '') {
				return false;
			}
			return $this->addId($uid, $type);
		});
	}

	/** Add a user id to the bot's friendlist for a given purpose */
	public function addId(int $uid, string $type): bool {
		$name = (string)($this->chatBot->id[$uid] ?? $uid);
		if (!isset($this->buddyList[$uid])) {
			$this->logger->info("{name} buddy added", ["name" => $name]);
			if (!$this->config->useProxy && count($this->buddyList) > 999) {
				$this->logger->error("Error adding '{name}' to buddy list--buddy list is full", [
					"name" => $name,
				]);
			}
			$this->chatBot->buddy_add($uid);
			// Initialize with an unconfirmed entry
			$this->buddyList[$uid] = new BuddylistEntry();
			$this->buddyList[$uid]->uid = $uid;
			$this->buddyList[$uid]->name = $name;
			$this->buddyList[$uid]->known = false;
		} else {
			$oldEntry = $this->buddyList[$uid];
			// If the char is already on our buddylist, but we never received online/offline
			// events, check if the UID was added over 3s ago. If so, send the package (again),
			// because there might have been an error.
			if ($oldEntry->known === false && (time() - $oldEntry->added) >= 3) {
				$this->logger->info("Re-adding {name} to buddylist, because there was no reply yet", [
					"name" => $name,
				]);
				$this->buddyList[$uid]->added = time();
				$this->chatBot->buddy_add($uid);
			}
		}
		if (!$this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->setType($type);
			$this->logger->info("{$name} buddy added (type: {$type})");
		}

		return true;
	}

	/**
	 * Remove a user to the bot's friendlist for a given purpose
	 *
	 * This does not necessarily remove the user from the friendlist, because
	 * they might be on it for more than 1 reason. The user is only really removed
	 * when the last reason to be on the list was removed.
	 *
	 * @param string $name The name of the player
	 * @param string $type The reason for which to remove ("member", "admin", "org", "onlineorg", "is_online", "tracking")
	 *
	 * @return bool true on success, otherwise false
	 */
	public function remove(string $name, string $type=''): bool {
		/** Never trigger an actual ID lookup. If we don't have a buddy's ID, it's inactive */
		$uid = $this->chatBot->id[ucfirst(strtolower($name))] ?? false;
		if ($uid === false) {
			return false;
		}
		return $this->removeId((int)$uid, $type);
	}

	/**
	 * Remove a user from the bot's friendlist for a given purpose
	 *
	 * This does not necessarily remove the user from the friendlist, because
	 * they might be on it for more than 1 reason. The user is noly really removed
	 * when the last reason to be on the list was removed.
	 */
	public function removeId(int $uid, string $type=''): bool {
		$name = $this->chatBot->id[$uid] ?? (string)$uid;
		if (!isset($this->buddyList[$uid])) {
			return false;
		}
		if ($this->buddyList[$uid]->hasType($type)) {
			$this->buddyList[$uid]->unsetType($type);
			$this->logger->info("{$name} buddy type removed (type: {$type})");
		}

		if (count($this->buddyList[$uid]->types) === 0) {
			$this->logger->info("{$name} buddy removed");
			$this->chatBot->buddy_remove($uid);
		}

		return true;
	}

	/** Update the cached information in the friendlist */
	public function update(int $userId, bool $status, int $worker=0): void {
		if ($this->isRebalancing($userId)) {
			unset($this->pendingRebalance[$userId]);
			$this->logger->info("{$userId} is now on worker {$worker}");
			if (!empty($this->inRebalance)) {
				$uid = array_rand($this->inRebalance);
				$this->pendingRebalance[$uid] = $this->buddyList[$uid]->worker;
				unset($this->inRebalance[$uid]);
				$this->logger->info("Rebalancing {$uid}");
				$this->chatBot->buddy_remove($uid);
			} elseif (empty($this->pendingRebalance)) {
				$this->logger->notice("Rebalancing buddylist done.");
				if (isset($this->rebalancingCallback)) {
					$this->rebalancingCallback->reply("Rebalancing buddylist done.");
					$this->rebalancingCallback = null;
				}
			}
		}
		$sender = $this->chatBot->lookup_user($userId);

		// store buddy info
		$this->buddyList[$userId] ??= new BuddylistEntry();
		$this->buddyList[$userId]->uid = $userId;
		$this->buddyList[$userId]->name = (string)$sender;
		$this->buddyList[$userId]->online = $status;
		$this->buddyList[$userId]->known = true;
		$this->buddyList[$userId]->worker ??= [];
		$this->buddyList[$userId]->worker[$worker] = true;
	}

	/** Forcefully delete cached information in the friendlist */
	public function updateRemoved(int $uid): void {
		$this->logger->info("UID {uid} removed from buddylist", ["uid" => $uid]);
		if ($this->isRebalancing($uid)) {
			$worker = array_rand($this->pendingRebalance[$uid]);
			unset($this->pendingRebalance[$uid][$worker]);
			unset($this->buddyList[$uid]->worker[$worker]);
			if (!empty($this->pendingRebalance[$uid])) {
				return;
			}
			$this->logger->info("Re-adding {uid} to buddylist for rebalance", [
				"uid" => $uid,
			]);
			$this->chatBot->buddy_add($uid);
			return;
		}
		unset($this->buddyList[$uid]);
	}

	public function rebalance(CommandReply $callback): void {
		foreach ($this->buddyList as $uid => $buddy) {
			if ($buddy->known) {
				$this->inRebalance[$uid] = true;
			}
		}
		if (empty($this->inRebalance)) {
			return;
		}
		$this->rebalancingCallback = $callback;
		$parallel = (int)floor($this->chatBot->getBuddyListSize() / 100);
		for ($i = 0; $i < $parallel; $i++) {
			if (empty($this->inRebalance)) {
				return;
			}
			$uid = array_rand($this->inRebalance);
			foreach ($this->buddyList[$uid]->worker as $wid => $true) {
				$this->pendingRebalance[$uid] ??= [];
				$this->pendingRebalance[$uid][$wid] = true;
			}
			unset($this->inRebalance[$uid]);
			$this->logger->info("Rebalancing {$uid}");
			$this->chatBot->buddy_remove($uid);
		}
	}

	/** Check if a given UID is on the buddylist for a given type */
	public function buddyHasType(int $uid, string $type): bool {
		$buddy = $this->buddyList[$uid] ?? null;
		return isset($buddy) && $buddy->hasType($type);
	}
}
