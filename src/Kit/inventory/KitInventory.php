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
use benzo\ArcticKits\kit\Category;

class KitInventory extends BaseInventory {

    /** @var Position */
    public $holder;

    /** @var Category */
    protected $category;

    /**
     * KitInventory constructor.
     * @param Position $holder
     * @param Category $category
     */
    public function __construct(Position $holder, Category $category) {
        $this->slots = new \SplFixedArray($this->getDefaultSize());
        $this->title = $category->getName();

        $this->setContents([], false);

        $holder->x = (int)$holder->x;
        $holder->y = (int)$holder->y;
        $holder->z = (int)$holder->z;

        $this->holder = $holder;
        $this->category = $category;

        foreach($category->getKits() as $kit){
            $this->addItem(clone $kit->getDisplayItem());
        }
    }

    /**
     * @return Category
     */
    public function getCategory(): Category {
        return $this->category;
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
        return $this->category->getName();
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
