<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use customiesdevs\customies\block\CustomiesBlockFactory;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\Experiments;
use function array_merge;
use function count;
use function hash;
use function strcmp;
use function usort;

final class CustomiesListener implements Listener {
	/** @var BlockPaletteEntry[] */
	private array $cachedBlockPalette = [];
	private Experiments $experiments;

	public function __construct() {
		$this->experiments = new Experiments([
			// "data_driven_items" is required for custom blocks to render in-game. With this disabled, they will be
			// shown as the UPDATE texture block.
			"data_driven_items" => true,
		], true);
	}

	public function onDataPacketSend(DataPacketSendEvent $event): void {
		foreach($event->getPackets() as $packet){
			if($packet instanceof StartGamePacket) {
				if(count($this->cachedBlockPalette) === 0) {
					// Wait for the data to be needed before it is actually cached. Allows for all blocks and items to be
					// registered before they are cached for the rest of the runtime.
					$merged = array_merge(
						StaticPacketCache::getInstance()->getBlockDefinitions(),
						CustomiesBlockFactory::getInstance()->getBlockPaletteEntries()
					);
					// 1.20.60 added a new "block_id" field which depends on the order of the block palette entries, so the
					// vanilla data driven blocks and ours have to be sorted together to stay in sync with the client.
					usort($merged, static function(BlockPaletteEntry $a, BlockPaletteEntry $b): int {
						return strcmp(hash("fnv164", $a->getName()), hash("fnv164", $b->getName()));
					});
					$this->cachedBlockPalette = $merged;
				}
				$packet->levelSettings->experiments = $this->experiments;
				$packet->blockPalette = $this->cachedBlockPalette;
			} elseif($packet instanceof ResourcePackStackPacket) {
				$packet->experiments = $this->experiments;
			}
		}
	}
}
