<?php
declare(strict_types=1);
namespace ChestShop;

use onebone\economyapi\EconomyAPI;
use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\ItemFactory;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\tile\Chest;
use pocketmine\utils\TextFormat;

class EventListener implements Listener
{
	private $plugin;
	private $databaseManager;

	public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
	{
		$this->plugin = $plugin;
		$this->databaseManager = $databaseManager;
	}

	public function onPlayerInteract(PlayerInteractEvent $event)
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		switch ($block->getID()) {
			case Block::SIGN_POST:
			case Block::WALL_SIGN:
				if (($shopInfo = $this->databaseManager->selectByCondition([
						"signX" => $block->getX(),
						"signY" => $block->getY(),
						"signZ" => $block->getZ()
					])) === false) return;
				$shopInfo = $shopInfo->fetchArray(SQLITE3_ASSOC);
				if($shopInfo === false)
					return;
				if ($shopInfo['shopOwner'] === $player->getName()) {
					$player->sendMessage("Cannot purchase from your own shop!");
					return;
				}else{
					$event->setCancelled();
				}
				$buyerMoney = EconomyAPI::getInstance()->myMoney($player->getName());
				if ($buyerMoney === false) {
					$player->sendMessage("Couldn't acquire your money data!");
					return;
				}
				if ($buyerMoney < $shopInfo['price']) {
					$player->sendMessage("Your money is not enough!");
					return;
				}
				/** @var Chest $chest */
				$chest = $player->getLevel()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
				$itemNum = 0;
				$pID = $shopInfo['productID'];
				$pMeta = $shopInfo['productMeta'];
				for ($i = 0; $i < $chest->getInventory()->getSize(); $i++) {
					$item = $chest->getInventory()->getItem($i);
					// use getDamage() method to get metadata of item
					if ($item->getID() === $pID and $item->getDamage() === $pMeta) $itemNum += $item->getCount();
				}
				if ($itemNum < $shopInfo['saleNum']) {
					$player->sendMessage("This shop is out of stock!");
					if (($p = $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("Your ChestShop is out of stock! Replenish Item: ".ItemFactory::get($pID, $pMeta)->getName());
					}
					return;
				}

				$item = ItemFactory::get((int)$shopInfo['productID'], (int)$shopInfo['productMeta'], (int)$shopInfo['saleNum']);
				$chest->getInventory()->removeItem($item);
				$player->getInventory()->addItem($item);
				$sellerMoney = EconomyAPI::getInstance()->myMoney($shopInfo['shopOwner']);
				if(EconomyAPI::getInstance()->reduceMoney($player->getName(), $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS and EconomyAPI::getInstance()->addMoney($shopInfo['shopOwner'], $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS) {
					$player->sendMessage("Completed transaction");
					if (($p = $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("{$player->getName()} purchased ".ItemFactory::get($pID, $pMeta)->getName()." for ".EconomyAPI::getInstance()->getMonetaryUnit().$shopInfo['price']);
					}
				}else{
					$player->getInventory()->removeItem($item);
					$chest->getInventory()->addItem($item);
					EconomyAPI::getInstance()->setMoney($player->getName(), $buyerMoney);
					EconomyAPI::getInstance()->setMoney($shopInfo['shopOwner'], $sellerMoney);
					$player->sendMessage("Transaction Failed");
				}
				break;

			case Block::CHEST:
				$shopInfo = $this->databaseManager->selectByCondition([
					"chestX" => $block->getX(),
					"chestY" => $block->getY(),
					"chestZ" => $block->getZ()
				]);
				if($shopInfo === false)
					break;
				$shopInfo = $shopInfo->fetchArray(SQLITE3_ASSOC);
				if ($shopInfo !== false and $shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
					$player->sendMessage("This chest has been protected!");
					$event->setCancelled();
				}
				break;

			default:
				break;
		}
	}

	public function onPlayerBreakBlock(BlockBreakEvent $event)
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		switch ($block->getID()) {
			case Block::SIGN_POST:
			case Block::WALL_SIGN:
				$condition = [
					"signX" => $block->getX(),
					"signY" => $block->getY(),
					"signZ" => $block->getZ()
				];
				$shopInfo = $this->databaseManager->selectByCondition($condition);
				if ($shopInfo !== false) {
					$shopInfo = $shopInfo->fetchArray();
					if($shopInfo === false)
						break;
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
						$player->sendMessage("This sign has been protected!");
						$event->setCancelled();
					} else {
						$this->databaseManager->deleteByCondition($condition);
						$player->sendMessage("Closed your ChestShop");
					}
				}
				break;

			case Block::CHEST:
				$condition = [
					"chestX" => $block->getX(),
					"chestY" => $block->getY(),
					"chestZ" => $block->getZ()
				];
				$shopInfo = $this->databaseManager->selectByCondition($condition);
				if ($shopInfo !== false) {
					$shopInfo = $shopInfo->fetchArray();
					if($shopInfo === false)
						break;
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
						$player->sendMessage("This chest has been protected!");
						$event->setCancelled();
					} else {
						$this->databaseManager->deleteByCondition($condition);
						$player->sendMessage("Closed your ChestShop");
					}
				}
				break;
		}
	}

	public function onSignChange(SignChangeEvent $event)
	{
		$shopOwner = $event->getPlayer()->getName();
		$saleNum = $event->getLine(1);
		$price = $event->getLine(2);
		$productData = explode(":", $event->getLine(3));
		/** @var int|bool $pID */
		$pID = $this->isItem($id = array_shift($productData)) ? (int)$id : false;
		$pMeta = ($meta = array_shift($productData)) ? (int)$meta : 0;

		$sign = $event->getBlock();

		$flag = $event->getPlayer()->isOp() && $event->getLine(0) === "enchant";

		// Check sign format...
		if ($event->getLine(0) !== "" && !$flag) return;
		if (!is_numeric($saleNum) or $saleNum <= 0) return;
		if (!is_numeric($price) or $price < 0) return;
		if ($pID === false) return;
		if (($chest = $this->getSideChest($sign)) === false) return;
		$shops = $this->databaseManager->selectByCondition(["shopOwner" => "'$shopOwner'"]);
		$res = true;
		$count = [];
		while ($res !== false) {
			$res = $shops->fetchArray(SQLITE3_ASSOC);
			if($res !== false) {
				$count[] = $res;
				if($res["signX"] === $event->getBlock()->getX() and $res["signY"] === $event->getBlock()->getY() and $res["signZ"] === $event->getBlock()->getZ()) {
					$productName = ItemFactory::get($pID, $pMeta)->getName();
					$event->setLine(0, $shopOwner);
					$event->setLine(1, "Amount: $saleNum");
					$event->setLine(2, "Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price);
					$event->setLine(3, $productName);

					$this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest, $flag);
					return;
				}
			}
		}
		if(empty($event->getLine(3))) return;
		if(count($count) >= $this->plugin->getMaxPlayerShops($event->getPlayer()) and !$event->getPlayer()->hasPermission("chestshop.admin")) {
			$event->getPlayer()->sendMessage(TextFormat::RED."You don't have permission to make more shops");
			return;
		}

		$productName = ItemFactory::get($pID, $pMeta)->getName();
		$event->setLine(0, $shopOwner);
		$event->setLine(1, "Amount: $saleNum");
		$event->setLine(2, "Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price);
		$event->setLine(3, $productName);

		$this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest, $flag);
	}

	private function getSideChest(Position $pos)
	{
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY() - 1, $pos->getZ()));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
		if ($block->getID() === Block::CHEST) return $block;
		return false;
	}

	private function isItem($id)
	{
		return ItemFactory::isRegistered((int) $id);
	}
} 
