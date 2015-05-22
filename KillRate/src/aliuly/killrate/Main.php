<?php
namespace aliuly\killrate;

use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandExecutor;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\utils\TextFormat;

use pocketmine\Player;
use pocketmine\event\Listener;
use pocketmine\utils\Config;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\entity\Projectile;
use aliuly\common\mc;

class Main extends PluginBase implements CommandExecutor,Listener {
	protected $dbm;
	protected $cfg;
	protected $money;

	// Access and other permission related checks
	private function access(CommandSender $sender, $permission) {
		if($sender->hasPermission($permission)) return true;
		$sender->sendMessage(mc::_("You do not have permission to do that."));
		return false;
	}
	private function inGame(CommandSender $sender,$msg = true) {
		if ($sender instanceof Player) return true;
		if ($msg) $sender->sendMessage(mc::_("You can only use this command in-game"));
		return false;
	}
	//////////////////////////////////////////////////////////////////////
	//
	// Standard call-backs
	//
	//////////////////////////////////////////////////////////////////////
	public function onEnable(){
		if (!is_dir($this->getDataFolder())) mkdir($this->getDataFolder());
		$this->saveResource("messages.po");
		mc::load($this->getDataFolder()."messages.po");

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$defaults = [
			"version" => $this->getDescription()->getVersion(),
			"settings" => [
				"points" => true,
				"rewards" => true,
				"creative" => false,
			],
			"values" => [
				"*" => [ 1, 10 ],	// Default
				"Player" => [ 100, 100 ],
			],
			"backend" => "SQLiteMgr",
			"MySql" => [
				"host" => "localhost",
				"user" => "nobody",
				"password" => "secret",
				"database" => "KillRateDb",
				"port" => 3306,
			],
		];
		$this->cfg = (new Config($this->getDataFolder()."config.yml",
										 Config::YAML,$defaults))->getAll();
		$this->getLogger()->info(mc::_("Using %1% as backend",
												 $this->cfg["backend"]));
		$backend = __NAMESPACE__."\\".$this->cfg["backend"];
		$this->dbm = new $backend($this);
		$pm = $this->getServer()->getPluginManager();
		if(!($this->money = $pm->getPlugin("PocketMoney"))
			&& !($this->money = $pm->getPlugin("GoldStd"))
			&& !($this->money = $pm->getPlugin("EconomyAPI"))
			&& !($this->money = $pm->getPlugin("MassiveEconomy"))){
			$this->getLogger()->info(TextFormat::RED.
											 mc::_("# MISSING MONEY API PLUGIN"));
			$this->getLogger()->info(TextFormat::BLUE.
											 mc::_(". Please install one of the following:"));
			$this->getLogger()->info(TextFormat::WHITE.
											 mc::_("* GoldStd"));
			$this->getLogger()->info(TextFormat::WHITE.
											 mc::_("* PocketMoney"));
			$this->getLogger()->info(TextFormat::WHITE.
											 mc::_("* EconomyAPI or"));
			$this->getLogger()->info(TextFormat::WHITE.
											 mc::_("* MassiveEconomy"));
		} else {
			$this->getLogger()->info(TextFormat::BLUE.
											 mc::_("Using money API from %1%",
													 TextFormat::WHITE.$this->money->getName()." v".$this->money->getDescription()->getVersion()));
		}
	}
	public function getCfg($key) {
		return $this->cfg[$key];
	}

	public function onCommand(CommandSender $sender, Command $cmd, $label, array $args) {
		switch($cmd->getName()) {
			case "killrate":
				if (count($args) == 0) return $this->cmdStats($sender,[]);
				$scmd = strtolower(array_shift($args));
				switch ($scmd) {
					case "stats":
						if (!$this->access($sender,"killrate.cmd.stats")) return true;
						return $this->cmdStats($sender,$args);
					case "top":
					case "ranking":
						if (!$this->access($sender,"killrate.cmd.rank")) return true;
						return $this->cmdTops($sender,$args);
					case "help":
						return $this->cmdHelp($sender,$args);
					default:
						$sender->sendMessage(mc::_("Unknown command.  Try /killrate help"));
						return false;
				}
		}
		return false;
	}
	//////////////////////////////////////////////////////////////////////
	//
	// Command implementations
	//
	//////////////////////////////////////////////////////////////////////
	private function cmdHelp(CommandSender $sender,$args) {
		$cmds = [
			"stats" => ["[player ...]",mc::_("Show player scores")],
			"top" => ["[online]",mc::_("Show top players")],
		];
		if (count($args)) {
			foreach ($args as $c) {
				if (isset($cmds[$c])) {
					list($a,$b) = $cmds[$c];
					$sender->sendMessage(TextFormat::RED.
												mc::_("Usage: /killrate %1% %2%",$c,$a).
												TextFormat::RESET);
					$sender->sendMessage($b);
				} else {
					$sender->sendMessage(mc::_("Unknown command %1%",$c));
				}
			}
			return true;
		}
		$sender->sendMessage(mc::_("KillRate sub-commands"));
		foreach ($cmds as $a => $b) {
			$sender->sendMessage("- ".TextFormat::GREEN."/killrate ".$a.
										TextFormat::RESET." ".$b[0]);
		}
		return true;
	}
	public function getRankings($limit=10,$online=false,$col = "points") {
	  if ($online) {
		  // Online players only...
		  $plist = [];
		  foreach ($this->getServer()->getOnlinePlayers() as $p) {
			  $plist[] = $p->getName();
		  }
		  if (count($plist) < 2) return null;
	  } else {
		  $plist = null;
	  }
	  //print_r([$limit,$plist]);
	  return $this->dbm->getTops($limit,$plist,$col);
  }

	private function cmdTops(CommandSender $c,$args) {
		if (count($args) == 0) {
			$res = $this->getRankings(5);
		} else {
			$res = $this->getRankings(5,true);
			if ($res == null) {
				$c->sendMessage(mc::_("Not enough on-line players"));
				return true;
			}
		}
		$c->sendMessage(mc::_(". Player Points"));
		$i = 1;
		foreach ($res as $r) {
			$c->sendMessage(implode(" ",[$i++,$r["player"],$r["count"]]));
		}
		return true;
	}
	private function cmdStats(CommandSender $c,$args) {
		if (count($args) == 0) {
			if (!$this->inGame($c)) return true;
			$args = [ $c->getName() ];
		}
		foreach ($args as $pl) {
			if ($this->inGame($c,false) && $pl != $c->getName()) {
				if (!$this->access($c,"killrate.cmd.stats.other")) return true;
			}
			$score = $this->dbm->getScores($pl);
			if ($score == null) {
				$c->sendMessage(mc::_("No scores found for %1%",$pl));
				continue;
			} else {
				if (count($args) != 1) $c->sendMessage(TextFormat::BLUE.$pl);
				foreach ($score as $row) {
					$c->sendMessage(TextFormat::GREEN.$row['type'].": ".
										 TextFormat::WHITE.$row['count']);
				}
			}
		}
		return true;
	}

	//////////////////////////////////////////////////////////////////////
	//
	// Economy/Money handlers
	//
	//////////////////////////////////////////////////////////////////////
	public function grantMoney($p,$money) {
		if(!$this->money) return false;
		switch($this->money->getName()){
			case "GoldStd":
				$this->money->grantMoney($p, $money);
				break;
			case "PocketMoney":
				$this->money->grantMoney($p, $money);
				break;
			case "EconomyAPI":
				$this->money->setMoney($p,$this->money->mymoney($p)+$money);
				break;
			case "MassiveEconomy":
				$this->money->payPlayer($p,$money);
				break;
			default:
				return false;
		}
		return true;
	}
	public function getMoney($player) {
		if(!$this->money) return false;
		switch($this->money->getName()){
			case "GoldStd":
				$this->money->getMoney($p, $money);
				break;
			case "PocketMoney":
			case "MassiveEconomy":
				return $this->money->getMoney($player);
			case "EconomyAPI":
				return $this->money->mymoney($player);
			default:
				return false;
				break;
		}
	}
	//////////////////////////////////////////////////////////////////////
	//
	// Event handlers
	//
	//////////////////////////////////////////////////////////////////////
	public function announce($pp,$points,$money) {
		if ($points) {
			if ($points > 0) {
				$pp->sendMessage(mc::n(mc::_("one point awarded!"),
											  mc::_("%1% points awarded!",$points),
											  $points));
			} else {
				$pp->sendMessage(mc::n(mc::_("one point deducted!"),
											  mc::_("%1% points deducted!",$points),
											  $points));
			}
		}
		if ($money) {
			if ($money > 0) {
				$pp->sendMessage(mc::n(mc::_("You earn \$1"),
											  mc::_("You earn \$%1%", $money), $money));

			} else {
				$pp->sendMessage(mc::n(mc::_("You are fined \$1"),
											  mc::_("You are fined \$%1%",$money),
											  $money));
			}
		}
	}

	public function updateDb($perp,$vic,$incr = 1) {
		$score = $this->dbm->getScore($perp,$vic);
		if ($score) {
			$this->dbm->updateScore($perp,$vic,$score["count"]+$incr);
		} else {
			$this->dbm->insertScore($perp,$vic,$incr);
		}
	}
	public function getPrizes($vic) {
		if (isset($this->cfg["values"][$vic])) {
			return $this->cfg["values"][$vic];
		}
		if (isset($this->cfg["values"]["*"])) {
			return $this->cfg["values"]["*"];
		}
		return [0,0];
	}
	public function updateScores($perp,$vic) {
		//echo "VIC=$vic PERP=$perp\n";//##DEBUG
		$this->updateDb($perp,$vic);
		$awards = [ false,false];
		if (isset($this->cfg["settings"]["points"])) {
			// Add points...
			list($points,$money) = $this->getPrizes($vic);
			$this->updateDb($perp,"points",$points);
			$awards[0] = $points;
		}
		if (isset($this->cfg["settings"]["rewards"])) {
			// Add money...
			list($points,$money) = $this->getPrizes($vic);
			$this->grantMoney($perp,$money);
			$awards[1] = $money;
		}
		return $awards;
	}
	/**
	 * @priority MONITOR
	 */
	public function onPlayerDeath(PlayerDeathEvent $e) {
		//echo __METHOD__.",".__LINE__."\n";//##DEBUG
		$this->deadDealer($e->getEntity());
	}
	/**
	 * @priority MONITOR
	 */
	public function onDeath(EntityDeathEvent $e) {
		//echo __METHOD__.",".__LINE__."\n";//##DEBUG
		$this->deadDealer($e->getEntity());
	}
	public function deadDealer($pv) {
		//echo __METHOD__.",".__LINE__."\n";//##DEBUG
		if ($pv instanceof Player) {
			// Score that this player died!
			//echo __METHOD__.",".__LINE__."\n";//##DEBUG
			$this->updateDb($pv->getName(),"deaths");
		}
		$cause = $pv->getLastDamageCause();
		// If we don't know the real cause, we can score it!
		echo __METHOD__.",".__LINE__."-".get_class($cause)."\n";//##DEBUG
		if (!($cause instanceof EntityDamageEvent)) return;
		echo __METHOD__.",".__LINE__."\n";//##DEBUG

		switch ($cause->getCause()) {
			case EntityDamageEvent::CAUSE_PROJECTILE:
				$pp = $cause->getDamager();
				echo get_class($pp)." PROJECTILE\n";//##DEBUG
				break;
			case EntityDamageEvent::CAUSE_ENTITY_ATTACK:
				$pp = $cause->getDamager();
				break;
			case EntityDamageEvent::CAUSE_ENTITY_EXPLOSION:
				$pp = $cause->getDamager();
				if ($pp instanceof Projectile) {
					$pp = $pp->shootingEntity;
				}
				echo get_class($pp)." EXPLOSION\n";//##DEBUG
				break;
			default:
				echo "Cause: ".$cause->getCause()."\n";//##DEBUG
				return;
		}
		//echo __METHOD__.",".__LINE__."\n";//##DEBUG
		if (!($pp instanceof Player)) return; // Not killed by player...
		// No scoring for creative players...
		if ($pp->isCreative() && !isset($this->cfg["settings"]["creative"])) return;
		$perp = $pp->getName();
		$vic = $pv->getName();
		if ($pv instanceof Player) {
			$vic = "Player";
		}
		$perp = $pp->getName();
		list ($points,$money) = $this->updateScores($perp,$vic);
		$this->announce($pp,$points,$money);
	}

}
