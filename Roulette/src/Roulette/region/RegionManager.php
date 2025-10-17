<?php

declare(strict_types=1);

namespace Roulette\region;

use pocketmine\math\Vector3;
use pocketmine\plugin\Plugin;
use pocketmine\utils\Config;
use pocketmine\world\Position;

final class RegionManager
{
    public const FLAG_FIRE = 'fire';
    public const FLAG_PEARL = 'pearl';
    public const FLAG_PVP = 'pvp';
    public const FLAG_BREAK = 'break';
    public const FLAG_PLACE = 'place';

    /** @var array<string, Region> name => region */
    private array $regions = [];

    /** @var array<string, true> */
    private array $bypass = [];

    private Config $config;

    public function __construct(private Plugin $plugin)
    {
        $this->config = new Config($plugin->getDataFolder() . 'regions.json', Config::JSON);
    }

    public function load() : void
    {
        @mkdir($this->plugin->getDataFolder());
        $data = $this->config->getAll();
        $regions = [];
        foreach(($data['regions'] ?? []) as $row){
            $region = Region::fromArray((array) $row);
            if($region->name !== ''){
                $regions[$region->name] = $region;
            }
        }
        $this->regions = $regions;
        $this->bypass = array_fill_keys((array) ($data['bypass'] ?? []), true);
    }

    public function save() : void
    {
        $this->config->set('regions', array_map(fn(Region $r) => $r->toArray(), array_values($this->regions)));
        $this->config->set('bypass', array_keys($this->bypass));
        $this->config->save();
    }

    /** @return list<Region> */
    public function getAllRegions() : array
    {
        return array_values($this->regions);
    }

    public function addRegion(Region $region) : bool
    {
        if(isset($this->regions[$region->name])){
            return false;
        }
        $this->regions[$region->name] = $region;
        $this->save();
        return true;
    }

    public function updateRegion(Region $region, ?string $oldName = null) : void
    {
        if($oldName !== null && $oldName !== $region->name){
            unset($this->regions[$oldName]);
        }
        $this->regions[$region->name] = $region;
        $this->save();
    }

    public function removeRegion(string $name) : void
    {
        unset($this->regions[$name]);
        $this->save();
    }

    public function hasBypass(string $playerName) : bool
    {
        return isset($this->bypass[strtolower($playerName)]);
    }

    public function toggleBypass(string $playerName) : bool
    {
        $key = strtolower($playerName);
        if(isset($this->bypass[$key])){
            unset($this->bypass[$key]);
            $this->save();
            return false;
        }
        $this->bypass[$key] = true;
        $this->save();
        return true;
    }

    public function isAllowedAt(Position|Vector3 $pos, string $flag) : bool
    {
        $world = $pos instanceof Position ? $pos->getWorld()->getFolderName() : '';
        $x = (int) floor($pos->getX());
        $y = (int) floor($pos->getY());
        $z = (int) floor($pos->getZ());

        $matched = [];
        foreach($this->regions as $region){
            if($region->contains($world, $x, $y, $z)){
                $matched[] = $region;
            }
        }
        if(empty($matched)){
            return true; // no region => allowed
        }

        // Apply priority rule: if a priority region exists, only it decides
        $priorityRegions = array_values(array_filter($matched, fn(Region $r) => $r->priority));
        $effectiveRegions = !empty($priorityRegions) ? $priorityRegions : $matched;

        // Reduce permission: deny if any region in effective set denies the action
        foreach($effectiveRegions as $r){
            $allowed = match($flag){
                self::FLAG_FIRE => $r->allowFireDamage,
                self::FLAG_PEARL => $r->allowEnderPearl,
                self::FLAG_PVP => $r->allowPvp,
                self::FLAG_BREAK => $r->allowBreak,
                self::FLAG_PLACE => $r->allowPlace,
                default => true
            };
            if(!$allowed){
                return false;
            }
        }
        return true;
    }
}
