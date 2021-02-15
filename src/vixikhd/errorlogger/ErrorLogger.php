<?php

declare(strict_types=1);

namespace vixikhd\errorlogger;

use Exception;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\UUID;
use ReflectionClass;
use Throwable;
use vixikhd\errorlogger\network\SessionAdapter;

/**
 * Class ErrorLogger
 * @package vixikhd\errorlogger
 */
class ErrorLogger extends PluginBase implements Listener {

    /** @var ErrorLogger $instance */
    private static $instance;

    /** @var array $errors */
    private $errors = [];
    /** @var array $pluginData */
    private $pluginData = [];

    public function onEnable() {
        self::$instance = $this;

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function (int $i): void {
            foreach ($this->getServer()->getPluginManager()->getPlugins() as $plugin) {
                $this->pluginData[pathinfo($plugin->getDescription()->getMain())["dirname"]] = $plugin->getName() . "_v" . $plugin->getDescription()->getVersion() . " by " . implode(", ", $plugin->getDescription()->getAuthors());
            }
        }), 2);


        if(!is_dir($this->getDataFolder())) {
            mkdir($this->getDataFolder());
        }
        if(!is_dir($this->getDataFolder() . "fixed")) {
            mkdir($this->getDataFolder() . "fixed");
        }

        foreach (glob($this->getDataFolder() . "/*.json") as $error) {
            $this->errors[basename($error, ".json")] = json_decode(file_get_contents($error), true);
        }
    }

    public function onDisable() {
        $this->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($command->getName() != "err" || !$command->testPermission($sender)) {
            return false;
        }

        switch ($args[0] ?? "") {
            case "list":
                if(count($this->errors) == 0) {
                    $sender->sendMessage("§7[ErrorLogger] §aCongrats! You have not any errors logged!");
                    break;
                }

                $i = 0;
                $sender->sendMessage("§7[ErrorLogger] §aLogged Internal Server Errors:");
                $sender->sendMessage(implode("\n", array_map(function (array $error) use (&$i) {
                    return "§7" . ($i++) . ": " . substr($error["error"]["message"], 0, 50) . " - " . ($error["duplicates"] + 1) . " occurs";
                }, $this->errors)));
                break;
            case "show":
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/err show <id>");
                    break;
                }

                $error = array_values($this->errors)[(int)$args[1]] ?? null;
                if($error === null) {
                    $sender->sendMessage("§7[ErrorLogger] §cError {$args[1]} not found");
                    break;
                }

                $sender->sendMessage("§a" . $error["uuid"] . ":");
                $sender->sendMessage("§7Message: {$error["error"]["message"]}");
                $sender->sendMessage("§7File: {$error["error"]["file"]}");
                $sender->sendMessage("§7Line: {$error["error"]["line"]}");
                $sender->sendMessage("§7Involved Plugins: " . $error["involved_plugins"]);
                $sender->sendMessage("§7Loaded Plugins: " . $error["loaded_plugins"]);
                $sender->sendMessage("§7Duplicates: {$error["duplicates"]}");
                $sender->sendMessage("§7Trace: " . str_replace("\n", "\n§7", $error["error"]["trace"]));
                break;
            case "save":
                $this->save();
                $sender->sendMessage("§7[ErrorLogger] §aAll the errors saved!");
                break;
            case "remove":
                if(!isset($args[1])) {
                    $sender->sendMessage("§cUsage: §7/err remove <id>");
                    break;
                }

                $error = array_values($this->errors)[(int)$args[1]] ?? null;
                if($error === null) {
                    $sender->sendMessage("§cError {$args[1]} not found");
                    break;
                }

                if(is_file($this->getDataFolder() . "/{$error["uuid"]}.json")) {
                    copy($this->getDataFolder() . "/{$error["uuid"]}.json", $this->getDataFolder() . "/fixed/{$error["uuid"]}.json");
                    unlink($this->getDataFolder() . "/{$error["uuid"]}.json");
                }

                unset($this->errors[$error["uuid"]]);
                $sender->sendMessage("§7[ErrorLogger] §aError marked as fixed");
                break;
            case "cause":
                if(!$sender instanceof Player) {
                    $sender->sendMessage("§7[ErrorLogger] §cError must be caused by player!");
                    return false;
                }

                throw new Exception("Test error caused by ErrorLogger");
            default:
                $sender->sendMessage("§cUsage: §7/err <list|show|save|remove|cause>");
                break;
        }

        return false;
    }

    public function onLogin(PlayerLoginEvent $event) {
        $class = new ReflectionClass(Player::class);

        $property = $class->getProperty("sessionAdapter");
        $property->setAccessible(true);
        $property->setValue($event->getPlayer(), new SessionAdapter($this->getServer(), $event->getPlayer()));
    }

    public function saveError(Player $player, Throwable $error) {
        $fixPlayerName = function ($val) use ($player) {
            return str_replace($player->getName(), "%player_name%", $val);
        };

        $errorData = [
            "type" => get_class($error),
            "message" => $fixPlayerName($error->getMessage()),
            "file" => $error->getFile(),
            "line" => (string)$error->getLine()
        ];

        $uuid = UUID::fromData(...array_values($errorData));
        if(isset($this->errors[$uuid->toString()])) {
            $this->errors[$uuid->toString()]["duplicates"]++;
            return;
        }

        $plugins = array_values(array_filter($this->pluginData, function ($key, $value) use ($error) {
            return strpos($error->getTraceAsString(), $key) !== false && substr($value, 0, strpos($value, "_")) != "ErrorLogger";
        }, ARRAY_FILTER_USE_BOTH));

        $errorData["trace"] = $fixPlayerName($error->getTraceAsString());

        $this->errors[$uuid->toString()] = [
            "player" => $player->getName(),
            "duplicates" => 0,
            "uuid" => $uuid->toString(),
            "involved_plugins" => implode("; ", $plugins),
            "loaded_plugins" => implode("; ", array_map(function (Plugin $plugin) {
                return $plugin->getName() . "_v" . $plugin->getDescription()->getVersion() . " by " . implode(", ", $plugin->getDescription()->getAuthors());
            }, $this->getServer()->getPluginManager()->getPlugins())),
            "error" => $errorData
        ];
    }

    private function save() {
        foreach ($this->errors as $uuid => $errorData) {
            file_put_contents("{$this->getDataFolder()}/{$uuid}.json", json_encode($errorData, JSON_PRETTY_PRINT));
        }
    }

    public static function getInstance(): ErrorLogger {
        return self::$instance;
    }
}
