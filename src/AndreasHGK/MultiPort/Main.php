<?php

declare(strict_types=1);

namespace AndreasHGK\MultiPort;

use pocketmine\plugin\PluginBase;

class Main extends PluginBase{

    public static $instance = null;

    public $registeredPorts = [];

    public function getInstance() : ?Main{
        return self::$instance;
    }

    public function addPort(int $port) : void {
        if(!in_array($port, $this->registeredPorts)){
            $in = new MultiPortInterface($this->getServer(), $port);
            $this->getServer()->getNetwork()->registerInterface($in);
            $this->getLogger()->debug("Now listening on port ".$port);
            array_push($this->registeredPorts, $port);
        }
    }

	public function onLoad() : void{
        self::$instance = $this;
	    $this->saveDefaultConfig();
	    $ports = $this->getConfig()->get('ports');
	    if(isset($ports) && !empty($ports) && $ports !== false){
            foreach ($ports as $port){
                if($port === $this->getServer()->getPort()){
                    $this->getLogger()->debug("Tried to assign the default port. (".$port.")");
                }else{
                    $in = new MultiPortInterface($this->getServer(), $port);
                    $this->getServer()->getNetwork()->registerInterface($in);
                    $this->getLogger()->debug("Now listening on port ".$port);
                    array_push($this->registeredPorts, $port);
                }

            }
        }

	}
}
