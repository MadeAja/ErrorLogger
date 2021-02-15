<?php

declare(strict_types=1);

namespace vixikhd\errorlogger\network;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\PlayerNetworkSessionAdapter;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\timings\Timings;
use Throwable;
use vixikhd\errorlogger\ErrorLogger;

/**
 * Class NetworkSession
 * @package vixikhd\errorlogger\network
 */
class SessionAdapter extends PlayerNetworkSessionAdapter {

    /** @var Server $server */
    private $server;
    /** @var Player $player */
    private $player;

    /**
     * NetworkSession constructor.
     *
     * @param Server $server
     * @param Player $player
     */
    public function __construct(Server $server, Player $player) {
        parent::__construct($server, $player);

        $this->server = $server;
        $this->player = $player;
    }

    /**
     * @param DataPacket $packet
     * @throws Throwable
     */
    public function handleDataPacket(DataPacket $packet) {
        if(!$this->player->isConnected()){
            return;
        }

        $timings = Timings::getReceiveDataPacketTimings($packet);
        $timings->startTiming();

        $packet->decode();
        if(!$packet->feof() and !$packet->mayHaveUnreadBytes()){
            $remains = substr($packet->buffer, $packet->offset);
            $this->server->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": 0x" . bin2hex($remains));
        }

        $ev = new DataPacketReceiveEvent($this->player, $packet);
        $ev->call();
        if(!$ev->isCancelled()){
            try {
                if(!$packet->handle($this)) {
                    $this->server->getLogger()->debug("Unhandled " . $packet->getName() . " received from " . $this->player->getName() . ": " . base64_encode($packet->buffer));
                }
            } catch (Throwable $throwable) {
                if(!$packet instanceof BatchPacket) { // Removes duplicates
                    ErrorLogger::getInstance()->saveError($this->player, $throwable);
                }
                throw $throwable;
            }
        }

        $timings->stopTiming();
    }
}