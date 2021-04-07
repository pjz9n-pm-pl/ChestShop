<?php
declare(strict_types=1);
namespace ChestShop;

use pocketmine\block\Block;
use ErrorException;

class DatabaseManager
{
	private $database;

	/**
	 * @param string $path
	 */
	public function __construct($path)
	{
		$this->database = new \SQLite3($path);
		$sql = "CREATE TABLE IF NOT EXISTS ChestShop(
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					shopOwner TEXT NOT NULL,
					saleNum INTEGER NOT NULL,
					price INTEGER NOT NULL,
					productID INTEGER NOT NULL,
					productMeta INTEGER NOT NULL,
					signX INTEGER NOT NULL,
					signY INTEGER NOT NULL,
					signZ INTEGER NOT NULL,
					chestX INTEGER NOT NULL,
					chestY INTEGER NOT NULL,
					chestZ INTEGER NOT NULL
		)";
		$this->database->exec($sql);
		try {
			$this->database->exec("ALTER TABLE ChestShop ADD flag INTEGER");
		} catch (ErrorException $e) {
			//すでにカラムが存在している場合
		}
	}

	/**
	 * register shop to database
	 *
	 * @param string $shopOwner
	 * @param int $saleNum
	 * @param int $price
	 * @param int $productID
	 * @param int $productMeta
	 * @param Block $sign
	 * @param Block $chest
	 * @param bool $flag
	 * @return bool
	 */
	public function registerShop($shopOwner, $saleNum, $price, $productID, $productMeta, $sign, $chest, $flag = false) : bool
	{
		$flag = $flag ? 1 : "null";
		return $this->database->exec("INSERT OR REPLACE INTO ChestShop (id, shopOwner, saleNum, price, productID, productMeta, signX, signY, signZ, chestX, chestY, chestZ, flag) VALUES
			((SELECT id FROM ChestShop WHERE signX = $sign->x AND signY = $sign->y AND signZ = $sign->z),
			'$shopOwner', $saleNum, $price, $productID, $productMeta, $sign->x, $sign->y, $sign->z, $chest->x, $chest->y, $chest->z, $flag)");
	}

	/**
	 * @param array $condition
	 * @return \SQLite3Result|false
	 */
	public function selectByCondition(array $condition)
	{
		$where = $this->formatCondition($condition);
		$res = false;
		try{
			$res = $this->database->query("SELECT * FROM ChestShop WHERE $where");
		}finally{
			return $res;
		}
	}

	/**
	 * @param array $condition
	 * @return bool
	 */
	public function deleteByCondition(array $condition) : bool
	{
		$where = $this->formatCondition($condition);
		return $this->database->exec("DELETE FROM ChestShop WHERE $where");
	}

	private function formatCondition(array $condition) : string
	{
		$result = "";
		$first = true;
		foreach ($condition as $key => $val) {
			if ($first) $first = false;
			else $result .= "AND ";
			$result .= "$key = $val ";
		}
		return trim($result);
	}
} 