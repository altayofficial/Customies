<?php
declare(strict_types=1);

namespace customiesdevs\customies\block;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use ReflectionProperty;
use RuntimeException;
use function count;
use function hash;
use function hexdec;
use function reset;

final class BlockPalette {
	use SingletonTrait;

	/** @var BlockStateDictionaryEntry[] */
	private array $states;
	/** @var BlockStateDictionaryEntry[] */
	private array $customStates = [];

	private BlockTranslator $translator;
	private ReflectionProperty $bedrockKnownStates;
	private ReflectionProperty $stateDataToStateIdLookup;
	private ReflectionProperty $idMetaToStateIdLookupCache;
	private ReflectionProperty $fallbackStateId;

	public function __construct() {
		$this->translator = $instance = TypeConverter::getInstance()->getBlockTranslator();
		$dictionary = $instance->getBlockStateDictionary();
		$this->states = $dictionary->getStates();

		$this->bedrockKnownStates = new ReflectionProperty($dictionary, "states");
		$this->stateDataToStateIdLookup = new ReflectionProperty($dictionary, "stateDataToStateIdLookup");
		$this->idMetaToStateIdLookupCache = new ReflectionProperty($dictionary, "idMetaToStateIdLookupCache");
		$this->fallbackStateId = new ReflectionProperty($instance, "fallbackStateId");
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 */
	public function getStates(): array {
		return $this->states;
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 */
	public function getCustomStates(): array {
		return $this->customStates;
	}

	/**
	 * Inserts the provided state in to the palette under the state ID the client derives for it.
	 */
	public function insertState(CompoundTag $state, int $meta = 0): void {
		if(($name = $state->getString(BlockStateData::TAG_NAME, "")) === "") {
			throw new RuntimeException("Block state must contain a StringTag called 'name'");
		}
		if(($properties = $state->getCompoundTag(BlockStateData::TAG_STATES)) === null) {
			throw new RuntimeException("Block state must contain a CompoundTag called 'states'");
		}
		$entry = new BlockStateDictionaryEntry($name, $properties->getValue(), $meta);
		$stateId = self::computeStateId($entry);
		if(isset($this->states[$stateId])) {
			throw new RuntimeException("Block state $name is already registered");
		}
		$this->states[$stateId] = $entry;
		$this->customStates[] = $entry;
		$this->apply();
	}

	/**
	 * Block runtime IDs are a fnv1a32 hash of the little endian NBT of the state, which is how the client derives them
	 * as well. The order of the palette does not matter because of that, only the ID each state ends up under.
	 */
	private static function computeStateId(BlockStateDictionaryEntry $entry): int {
		$states = CompoundTag::create();
		foreach(BlockStateDictionaryEntry::decodeStateProperties($entry->getRawStateProperties()) as $name => $value) {
			$states->setTag($name, $value);
		}
		$nbt = (new LittleEndianNbtSerializer())->write(new TreeRoot(CompoundTag::create()
			->setString(BlockStateData::TAG_NAME, $entry->getStateName())
			->setTag(BlockStateData::TAG_STATES, $states)));
		$hash = (int) hexdec(hash("fnv1a32", $nbt));
		return $hash >= 0x80000000 ? $hash - 0x100000000 : $hash;
	}

	/**
	 * Writes the palette back in to the dictionary, rebuilding the lookups that depend on it.
	 */
	private function apply(): void {
		$table = [];
		foreach($this->states as $stateId => $state) {
			$table[$state->getStateName()][$state->getRawStateProperties()] = $stateId;
		}

		$stateDataToStateIdLookup = [];
		foreach($table as $name => $stateIds) {
			// Stateless blocks skip the inner array, the dictionary relies on that fast path.
			$stateDataToStateIdLookup[$name] = count($stateIds) === 1 ? reset($stateIds) : $stateIds;
		}

		$dictionary = $this->translator->getBlockStateDictionary();
		$this->bedrockKnownStates->setValue($dictionary, $this->states);
		$this->stateDataToStateIdLookup->setValue($dictionary, $stateDataToStateIdLookup);
		$this->idMetaToStateIdLookupCache->setValue($dictionary, null); //set this to null so pm can create a new cache
		$this->fallbackStateId->setValue($this->translator, $stateDataToStateIdLookup[BlockTypeNames::INFO_UPDATE] ??
			throw new AssumptionFailedError(BlockTypeNames::INFO_UPDATE . " should always exist")
		);
	}
}
