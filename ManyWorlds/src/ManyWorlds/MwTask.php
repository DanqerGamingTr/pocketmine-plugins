<?php
namespace ManyWorlds;

use pocketmine\scheduler\PluginTask;
use pocketmine\Server;

class MwTask extends PluginTask {
  private $method;
  private $args;

  public function __construct($plugin,$method,$args) {
    parent::__construct($plugin);
    $this->method = $method;
    $this->args = serialize($args);
  }
  public function onRun($ticks) {
    //echo "Callback: $this->method\n";
    call_user_func([$this->getOwner(),$this->method],unserialize($this->args));
  }
}