<?php

declare(strict_types=1);

namespace Rank;

use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\Server;

final class RankManager
{
    private PluginBase $plugin;
    private Config $ranksConfig; // ranks.yml
    private Config $playersConfig; // players.yml

    /**
     * Structure ranks.yml
     * ranks:
     *   joueur:
     *     render: "§7[Joueur] {name}"
     *     permissions:
     *       - example.permission
     * default: joueur
     */

    /**
     * Structure players.yml
     * players:
     *   Steve:
     *     rank: vip
     *     until: 0 | 1734567890 (epoch seconds)
     */

    public function __construct(PluginBase $plugin)
    {
        $this->plugin = $plugin;
        @mkdir($plugin->getDataFolder());

        $this->ranksConfig = new Config($plugin->getDataFolder() . 'ranks.yml', Config::YAML, [
            'default' => 'joueur',
            'ranks' => [
                'joueur' => [
                    'render' => '§7[Joueur] {name}',
                    'permissions' => []
                ]
            ]
        ]);

        $this->playersConfig = new Config($plugin->getDataFolder() . 'players.yml', Config::YAML, [
            'players' => []
        ]);
    }

    public function getDefaultRank(): string
    {
        return (string) $this->ranksConfig->get('default', 'joueur');
    }

    public function setDefaultRank(string $rank): void
    {
        $this->ranksConfig->set('default', $rank);
        $this->ranksConfig->save();
    }

    public function rankExists(string $rank): bool
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        return isset($ranks[$rank]);
    }

    public function createRank(string $rank): bool
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        if(isset($ranks[$rank])){
            return false;
        }
        $ranks[$rank] = [
            'render' => '§7[' . ucfirst($rank) . '] {name}',
            'permissions' => []
        ];
        $this->ranksConfig->set('ranks', $ranks);
        $this->ranksConfig->save();
        return true;
    }

    public function addPermissionToRank(string $rank, string $permission): bool
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        if(!isset($ranks[$rank])){
            return false;
        }
        $perms = $ranks[$rank]['permissions'] ?? [];
        if(!in_array($permission, $perms, true)){
            $perms[] = $permission;
            $ranks[$rank]['permissions'] = $perms;
            $this->ranksConfig->set('ranks', $ranks);
            $this->ranksConfig->save();
        }
        return true;
    }

    public function getRankRender(string $rank): string
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        return (string) ($ranks[$rank]['render'] ?? '{name}');
    }

    public function setRankRender(string $rank, string $render): bool
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        if(!isset($ranks[$rank])){
            return false;
        }
        $ranks[$rank]['render'] = $render;
        $this->ranksConfig->set('ranks', $ranks);
        $this->ranksConfig->save();
        return true;
    }

    public function listRanks(): array
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        return array_keys($ranks);
    }

    /**
     * Assign a rank to a user.
     * $durationSeconds = null for permanent, otherwise epoch seconds from now.
     */
    public function setPlayerRank(string $playerName, string $rank, ?int $durationSeconds = null): bool
    {
        if(!$this->rankExists($rank)){
            return false;
        }
        $players = $this->playersConfig->get('players', []);
        $until = 0;
        if($durationSeconds !== null){
            $until = time() + max(0, $durationSeconds);
        }
        $players[$playerName] = [
            'rank' => $rank,
            'until' => $until
        ];
        $this->playersConfig->set('players', $players);
        $this->playersConfig->save();

        $this->applyRankIfOnline($playerName, $rank);

        // Broadcast
        $this->plugin->getServer()->broadcastMessage(TF::GREEN . $playerName . ' vient d\'obtenir le rank ' . TF::YELLOW . $rank . TF::GREEN . ' !');
        return true;
    }

    public function removePlayerRank(string $playerName): void
    {
        $default = $this->getDefaultRank();
        $players = $this->playersConfig->get('players', []);
        $players[$playerName] = [
            'rank' => $default,
            'until' => 0
        ];
        $this->playersConfig->set('players', $players);
        $this->playersConfig->save();

        $this->applyRankIfOnline($playerName, $default);

        $this->plugin->getServer()->broadcastMessage(TF::RED . $playerName . ' vient de perdre son rank');
    }

    public function getPlayerRank(string $playerName): string
    {
        $players = $this->playersConfig->get('players', []);
        $info = $players[$playerName] ?? null;
        if($info === null){
            return $this->getDefaultRank();
        }
        $rank = (string) ($info['rank'] ?? $this->getDefaultRank());
        $until = (int) ($info['until'] ?? 0);
        if($until > 0 && $until <= time()){
            // expired
            $this->removePlayerRank($playerName);
            return $this->getDefaultRank();
        }
        return $rank;
    }

    public function ensureDefaultOnFirstJoin(string $playerName): void
    {
        $players = $this->playersConfig->get('players', []);
        if(isset($players[$playerName])){
            return;
        }
        $players[$playerName] = [
            'rank' => $this->getDefaultRank(),
            'until' => 0
        ];
        $this->playersConfig->set('players', $players);
        $this->playersConfig->save();
    }

    public function applyRankIfOnline(string $playerName, string $rank): void
    {
        $player = $this->plugin->getServer()->getPlayerExact($playerName);
        if($player instanceof Player){
            $this->applyPermissions($player, $rank);
            $this->updateNametag($player, $rank);
        }
    }

    public function refreshOnlinePlayers(): void
    {
        foreach(Server::getInstance()->getOnlinePlayers() as $player){
            $rank = $this->getPlayerRank($player->getName());
            $this->applyPermissions($player, $rank);
            $this->updateNametag($player, $rank);
        }
    }

    private function applyPermissions(Player $player, string $rank): void
    {
        $ranks = $this->ranksConfig->get('ranks', []);
        $perms = (array)($ranks[$rank]['permissions'] ?? []);

        // Clear old attachments by storing one and resetting
        static $attachments = [];
        $name = $player->getName();
        if(isset($attachments[$name])){
            $player->removeAttachment($attachments[$name]);
            unset($attachments[$name]);
        }
        $attachment = $player->addAttachment($this->plugin);
        foreach($perms as $p){
            $attachment->setPermission($p, true);
        }
        $attachments[$name] = $attachment;
    }

    private function updateNametag(Player $player, string $rank): void
    {
        $render = $this->getRankRender($rank);
        $formatted = str_replace(['{name}', '{rank}'], [$player->getName(), $rank], $render);
        $player->setNameTag($formatted);
        $player->setDisplayName($formatted);
    }

    public function tickExpirements(): void
    {
        $players = $this->playersConfig->get('players', []);
        $changedAny = false;
        foreach($players as $name => $info){
            $until = (int) ($info['until'] ?? 0);
            if($until > 0 && $until <= time()){
                $changedAny = true;
                $this->removePlayerRank($name);
            }
        }
        if($changedAny){
            $this->playersConfig->save();
        }
    }
}
