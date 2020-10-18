<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Modules\DISCORD\DiscordController;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\GuildMember;

class DiscordMessageCommandReply implements CommandReply {
	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public DiscordRelayController $discordRelayController;

	/** @Inject */
	public Nadybot $chatBot;

	protected string $channelId;

	public function __construct(string $channelId) {
		$this->channelId = $channelId;
	}

	public function reply($msg): void {
		if (!is_array($msg)) {
			$msg = [$msg];
		}
		$fakeGM = new GuildMember();
		$fakeGM->nick = $this->chatBot->vars["name"];
		foreach ($msg as $msgPack) {
			$this->discordRelayController->relayDiscordMessage($fakeGM, $msgPack, false);
			$messageObj = $this->discordController->formatMessage($msgPack);
			$this->discordAPIClient->sendToChannel($this->channelId, $messageObj->toJSON());
		}
	}
}
