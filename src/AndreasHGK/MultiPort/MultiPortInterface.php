<?php

declare(strict_types=1);

namespace AndreasHGK\MultiPort;

use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\AdvancedSourceInterface;
use pocketmine\network\mcpe\CachedEncapsulatedPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\RakLibInterface;
use pocketmine\network\Network;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;
use raklib\utils\InternetAddress;

//just a copy of the RakLibInterface but with the ability to use a custom port
class MultiPortInterface implements ServerInstance, AdvancedSourceInterface {

    /**
     * Sometimes this gets changed when the MCPE-layer protocol gets broken to the point where old and new can't
     * communicate. It's important that we check this to avoid catastrophes.
     */
    private const MCPE_RAKNET_PROTOCOL_VERSION = 9;

    /** @var Server */
    protected $server;

    /** @var Network*/
    protected $network;

    /** @var RakLibServer */
    protected $rakLib;

    /** @var Player[] */
    protected $players = [];

    /** @var string[] */
    protected $identifiers = [];

    /** @var int[] */
    protected $identifiersACK = [];

    /** @var ServerHandler */
    protected $interface;

    /** @var SleeperNotifier */
    protected $sleeper;

    public function __construct(Server $server, int $port){
        $this->server = $server;

        $this->sleeper = new SleeperNotifier();
        $this->rakLib = new RakLibServer(
            $this->server->getLogger(),
            \pocketmine\COMPOSER_AUTOLOADER_PATH,
            new InternetAddress($this->server->getIp(), $port, 4),
            (int) $this->server->getProperty("network.max-mtu-size", 1492),
            self::MCPE_RAKNET_PROTOCOL_VERSION,
            $this->sleeper
        );
        $this->interface = new ServerHandler($this->rakLib, $this);
    }

    public function start(){
        $this->server->getTickSleeper()->addNotifier($this->sleeper, function() : void{
            $this->process();
        });
        $this->rakLib->start(PTHREADS_INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging
    }

    public function setNetwork(Network $network){
        $this->network = $network;
    }

    public function process() : void{
        while($this->interface->handlePacket()){}

        if(!$this->rakLib->isRunning() and !$this->rakLib->isShutdown()){
            throw new \Exception("RakLib Thread crashed");
        }
    }

    public function closeSession(string $identifier, string $reason) : void{
        if(isset($this->players[$identifier])){
            $player = $this->players[$identifier];
            unset($this->identifiers[spl_object_hash($player)]);
            unset($this->players[$identifier]);
            unset($this->identifiersACK[$identifier]);
            $player->close($player->getLeaveMessage(), $reason);
        }
    }

    public function close(Player $player, string $reason = "unknown reason"){
        if(isset($this->identifiers[$h = spl_object_hash($player)])){
            unset($this->players[$this->identifiers[$h]]);
            unset($this->identifiersACK[$this->identifiers[$h]]);
            $this->interface->closeSession($this->identifiers[$h], $reason);
            unset($this->identifiers[$h]);
        }
    }

    public function shutdown(){
        $this->server->getTickSleeper()->removeNotifier($this->sleeper);
        $this->interface->shutdown();
    }

    public function emergencyShutdown(){
        $this->server->getTickSleeper()->removeNotifier($this->sleeper);
        $this->interface->emergencyShutdown();
    }

    public function openSession(string $identifier, string $address, int $port, int $clientID) : void{
        $ev = new PlayerCreationEvent($this, Player::class, Player::class, $address, $port);
        $ev->call();
        $class = $ev->getPlayerClass();

        /**
         * @var Player $player
         * @see Player::__construct()
         */
        $player = new $class($this, $ev->getAddress(), $ev->getPort());
        $this->players[$identifier] = $player;
        $this->identifiersACK[$identifier] = 0;
        $this->identifiers[spl_object_hash($player)] = $identifier;
        $this->server->addPlayer($player);
    }

    public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags) : void{
        if(isset($this->players[$identifier])){
            //get this now for blocking in case the player was closed before the exception was raised
            $player = $this->players[$identifier];
            $address = $player->getAddress();
            try{
                if($packet->buffer !== ""){
                    $pk = new BatchPacket($packet->buffer);
                    $player->handleDataPacket($pk);
                }
            }catch(\Throwable $e){
                $logger = $this->server->getLogger();
                $logger->debug("Packet " . (isset($pk) ? get_class($pk) : "unknown") . " 0x" . bin2hex($packet->buffer));
                $logger->logException($e);

                $player->close($player->getLeaveMessage(), "Internal server error");
                $this->interface->blockAddress($address, 5);
            }
        }
    }

    public function blockAddress(string $address, int $timeout = 300){
        $this->interface->blockAddress($address, $timeout);
    }

    public function unblockAddress(string $address){
        $this->interface->unblockAddress($address);
    }

    public function handleRaw(string $address, int $port, string $payload) : void{
        $this->server->handlePacket($this, $address, $port, $payload);
    }

    public function sendRawPacket(string $address, int $port, string $payload){
        $this->interface->sendRaw($address, $port, $payload);
    }

    public function notifyACK(string $identifier, int $identifierACK) : void{

    }

    public function setName(string $name){
        $info = $this->server->getQueryInformation();

        $this->interface->sendOption("name", implode(";",
                [
                    "MCPE",
                    rtrim(addcslashes($name, ";"), '\\'),
                    ProtocolInfo::CURRENT_PROTOCOL,
                    ProtocolInfo::MINECRAFT_VERSION_NETWORK,
                    $info->getPlayerCount(),
                    $info->getMaxPlayerCount(),
                    $this->rakLib->getServerId(),
                    $this->server->getName(),
                    Server::getGamemodeName($this->server->getGamemode())
                ]) . ";"
        );
    }

    public function setPortCheck($name){
        $this->interface->sendOption("portChecking", (bool) $name);
    }

    public function handleOption(string $option, string $value) : void{
        if($option === "bandwidth"){
            $v = unserialize($value);
            $this->network->addStatistics($v["up"], $v["down"]);
        }
    }

    public function putPacket(Player $player, DataPacket $packet, bool $needACK = false, bool $immediate = true){
        if(isset($this->identifiers[$h = spl_object_hash($player)])){
            $identifier = $this->identifiers[$h];
            if(!$packet->isEncoded){
                $packet->encode();
            }

            if($packet instanceof BatchPacket){
                if($needACK){
                    $pk = new EncapsulatedPacket();
                    $pk->identifierACK = $this->identifiersACK[$identifier]++;
                    $pk->buffer = $packet->buffer;
                    $pk->reliability = PacketReliability::RELIABLE_ORDERED;
                    $pk->orderChannel = 0;
                }else{
                    if(!isset($packet->__encapsulatedPacket)){
                        $packet->__encapsulatedPacket = new CachedEncapsulatedPacket();
                        $packet->__encapsulatedPacket->identifierACK = null;
                        $packet->__encapsulatedPacket->buffer = $packet->buffer;
                        $packet->__encapsulatedPacket->reliability = PacketReliability::RELIABLE_ORDERED;
                        $packet->__encapsulatedPacket->orderChannel = 0;
                    }
                    $pk = $packet->__encapsulatedPacket;
                }

                $this->interface->sendEncapsulated($identifier, $pk, ($needACK ? RakLib::FLAG_NEED_ACK : 0) | ($immediate ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));
                return $pk->identifierACK;
            }else{
                $this->server->batchPackets([$player], [$packet], true, $immediate);
                return null;
            }
        }

        return null;
    }

    public function updatePing(string $identifier, int $pingMS) : void{
        if(isset($this->players[$identifier])){
            $this->players[$identifier]->updatePing($pingMS);
        }
    }

}