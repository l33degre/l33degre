<?php

declare(strict_types=1);

namespace Rank;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;

final class Main extends PluginBase implements Listener
{
    private RankManager $rankManager;

    protected function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->rankManager = new RankManager($this);

        // periodic expiration check every 30 seconds
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->rankManager->tickExpirements();
            $this->rankManager->refreshOnlinePlayers();
        }), 20 * 30);
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $this->rankManager->ensureDefaultOnFirstJoin($player->getName());
        $rank = $this->rankManager->getPlayerRank($player->getName());
        $this->rankManager->applyRankIfOnline($player->getName(), $rank);
    }

    public function onChat(PlayerChatEvent $event): void
    {
        $player = $event->getPlayer();
        $rank = $this->rankManager->getPlayerRank($player->getName());
        $render = $this->rankManager->getRankRender($rank);
        $name = str_replace(['{name}', '{rank}'], [$player->getName(), $rank], $render);
        $event->setFormat($name . TF::WHITE . ': ' . $event->getMessage());
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        switch($command->getName()){
            case 'rank':
                return $this->handleRank($sender, $args);
            case 'ranktime':
                return $this->handleRankTime($sender, $args);
            case 'rankremove':
                return $this->handleRankRemove($sender, $args);
        }
        return false;
    }

    private function requireAdmin(CommandSender $sender): bool
    {
        if(!$sender->hasPermission('rank.admin')){
            $sender->sendMessage(TF::RED . "Vous n'avez pas la permission.");
            return false;
        }
        return true;
    }

    private function handleRank(CommandSender $sender, array $args): bool
    {
        if(!$this->requireAdmin($sender)) return true;
        $sub = strtolower($args[0] ?? '');
        switch($sub){
            case 'create':
                $rank = strtolower($args[1] ?? '');
                if($rank === ''){
                    $sender->sendMessage(TF::YELLOW . 'Usage: /rank create <grade>');
                    return true;
                }
                if($this->rankManager->createRank($rank)){
                    $sender->sendMessage(TF::GREEN . 'Grade créé: ' . $rank);
                }else{
                    $sender->sendMessage(TF::RED . 'Ce grade existe déjà.');
                }
                return true;
            case 'list':
                $list = $this->rankManager->listRanks();
                $sender->sendMessage(TF::AQUA . 'Grades: ' . implode(', ', $list));
                return true;
            case 'set':
                $rank = strtolower($args[1] ?? '');
                $player = $args[2] ?? '';
                if($rank === '' || $player === ''){
                    $sender->sendMessage(TF::YELLOW . 'Usage: /rank set <grade> <joueur>');
                    return true;
                }
                if(!$this->rankManager->rankExists($rank)){
                    $sender->sendMessage(TF::RED . 'Ce grade n\'existe pas.');
                    return true;
                }
                if($this->rankManager->setPlayerRank($player, $rank)){
                    $sender->sendMessage(TF::GREEN . "Le joueur {$player} a reçu le grade {$rank}.");
                }else{
                    $sender->sendMessage(TF::RED . 'Erreur lors de l\'attribution du grade.');
                }
                return true;
            case 'addperm':
                $rank = strtolower($args[1] ?? '');
                $perm = $args[2] ?? '';
                if($rank === '' || $perm === ''){
                    $sender->sendMessage(TF::YELLOW . 'Usage: /rank addperm <grade> <permission>');
                    return true;
                }
                if(!$this->rankManager->rankExists($rank)){
                    $sender->sendMessage(TF::RED . 'Ce grade n\'existe pas.');
                    return true;
                }
                if($this->rankManager->addPermissionToRank($rank, $perm)){
                    $sender->sendMessage(TF::GREEN . "Permission ajoutée au grade {$rank}: {$perm}");
                    $this->rankManager->refreshOnlinePlayers();
                }else{
                    $sender->sendMessage(TF::RED . 'Impossible d\'ajouter la permission.');
                }
                return true;
            default:
                $sender->sendMessage(TF::YELLOW . 'Usage: /rank create <grade> | /rank list | /rank set <grade> <joueur> | /rank addperm <grade> <permission>');
                return true;
        }
    }

    private function handleRankTime(CommandSender $sender, array $args): bool
    {
        if(!$this->requireAdmin($sender)) return true;
        $sub = strtolower($args[0] ?? '');
        if($sub !== 'set'){
            $sender->sendMessage(TF::YELLOW . 'Usage: /ranktime set <grade> <joueur> <durée ex: 1d2h30m>');
            return true;
        }
        $rank = strtolower($args[1] ?? '');
        $player = $args[2] ?? '';
        $durationStr = $args[3] ?? '';
        if($rank === '' || $player === '' || $durationStr === ''){
            $sender->sendMessage(TF::YELLOW . 'Usage: /ranktime set <grade> <joueur> <durée ex: 1d2h30m>');
            return true;
        }
        if(!$this->rankManager->rankExists($rank)){
            $sender->sendMessage(TF::RED . 'Ce grade n\'existe pas.');
            return true;
        }
        $seconds = TimeParser::parseDuration($durationStr);
        if($seconds === null || $seconds <= 0){
            $sender->sendMessage(TF::RED . 'Durée invalide. Exemple: 1d2h30m');
            return true;
        }
        if($this->rankManager->setPlayerRank($player, $rank, $seconds)){
            $sender->sendMessage(TF::GREEN . "Le joueur {$player} a reçu le grade {$rank} pour {$durationStr}.");
        }else{
            $sender->sendMessage(TF::RED . 'Erreur lors de l\'attribution du grade.');
        }
        return true;
    }

    private function handleRankRemove(CommandSender $sender, array $args): bool
    {
        if(!$this->requireAdmin($sender)) return true;
        $player = $args[0] ?? '';
        if($player === ''){
            $sender->sendMessage(TF::YELLOW . 'Usage: /rankremove <joueur>');
            return true;
        }
        $this->rankManager->removePlayerRank($player);
        $sender->sendMessage(TF::GREEN . "Le joueur {$player} a été remis au grade par défaut.");
        return true;
    }
}
