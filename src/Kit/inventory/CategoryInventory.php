<?php
namespace Kit\inventory;

use pocketmine\block\BlockFactory;
use pocketmine\block\BlockIds;
use pocketmine\inventory\BaseInventory;
use pocketmine\level\Position;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\Player;
use kit\Category;

class CategoryInventory extends BaseInventory {

    /** @var Position */
    public $holder;

    /**
     * CategoryInventory constructor.
     * @param Position $holder
     * @param Category[] $categories
     */
    public function __construct(Position $holder, array $categories) {
        parent::__construct([]);

        $holder->x = (int)$holder->x;
        $holder->y = (int)$holder->y;
        $holder->z = (int)$holder->z;

        $this->holder = $holder;

        foreach($categories as $category){
            $this->addItem(clone $category->getDisplayItem());
        }
    }

    /**
     * @return null|Position
     */
    public function getHolder(): Position {
        return $this->holder;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return "§l§3» §r§bArctic §7Kits";
    }

    /**
     * @return int
     */
    public function getDefaultSize(): int {
        return 27;
    }

    /**
     * @return int
     */
    public function getNetworkType(): int {
        return WindowTypes::CONTAINER;
    }

    /**
     * @param Player $who
     */
    public function onOpen(Player $who): void {
        $holder = $this->holder;

        $block = BlockFactory::get(BlockIds::CHEST, 0, $holder);
        $block->getLevel()->sendBlocks([$who], [$block]);

        $pk = new BlockActorDataPacket();
        $pk->x = $holder->x;
        $pk->y = $holder->y;
        $pk->z = $holder->z;
        $pk->namedtag = (new NetworkLittleEndianNBTStream())->write(new CompoundTag("", [
            new StringTag("id", "Chest"),
            new StringTag("CustomName", $this->getName())
        ]));

        $who->sendDataPacket($pk);

        $pk = new ContainerOpenPacket();
        $pk->windowId = $who->getWindowId($this);
        $pk->type = $this->getNetworkType();
        $pk->x = $this->getHolder()->getX();
        $pk->y = $this->getHolder()->getY();
        $pk->z = $this->getHolder()->getZ();

        $who->sendDataPacket($pk);

        $this->sendContents($who);

        parent::onOpen($who);
    }

    /**
     * @param Player $who
     */
    public function onClose(Player $who): void {
        parent::onClose($who);

        $who->getLevel()->sendBlocks([$who], [$who->getLevel()->getBlock($this->holder)]);
    }
}
