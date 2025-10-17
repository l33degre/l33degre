<?php

declare(strict_types=1);

namespace Roulette;

use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\DeterministicInvMenuTransaction;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\world\Position;
use Roulette\session\RouletteSession;
use Roulette\region\Region;
use Roulette\region\RegionManager;

final class Main extends PluginBase implements Listener
{
    /** @var array<string, true> */
    private array $activeTables = [];

    /** @var array<string, RouletteSession> */
    private array $sessions = [];

    /** @var array<string, FloatingTextParticle> */
    private array $titleParticles = [];

    private RegionManager $regionManager;

    protected function onEnable() : void
    {
        if(!InvMenuHandler::isRegistered()){
            InvMenuHandler::register($this);
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->regionManager = new RegionManager($this);
        $this->regionManager->load();
    }

    protected function onDisable() : void
    {
        if(isset($this->regionManager)){
            $this->regionManager->save();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool
    {
        if($command->getName() === 'adminroulette'){
            if(!$sender instanceof Player){
                $sender->sendMessage("§c» Cette commande doit être exécutée en jeu.");
                return true;
            }
            if(!$sender->hasPermission('roulette.admin')){
                $sender->sendMessage("§c» Vous n'avez pas la permission.");
                return true;
            }

            $this->spawnRouletteTableInFrontOf($sender);
            $sender->sendMessage("§a» Table de roulette créée devant vous.");
            return true;
        }
        if($command->getName() === 'rg'){
            if(!$sender instanceof Player){
                $sender->sendMessage("§c» Cette commande doit être exécutée en jeu.");
                return true;
            }
            $this->openRgMain($sender);
            return true;
        }
        if($command->getName() === 'rgbypass'){
            if(!$sender instanceof Player){
                $sender->sendMessage("§c» Cette commande doit être exécutée en jeu.");
                return true;
            }
            if(!$sender->hasPermission('rg.bypass')){
                $sender->sendMessage("§c» Vous n'avez pas la permission.");
                return true;
            }
            $enabled = $this->regionManager->toggleBypass($sender->getName());
            $sender->sendMessage(($enabled ? "§a» " : "§c» ") . ($enabled ? "RGBypass activé." : "RGBypass désactivé."));
            return true;
        }
        return false;
    }

    private function spawnRouletteTableInFrontOf(Player $player) : void
    {
        $world = $player->getWorld();
        $base = $player->getPosition();
        $dir = $player->getDirectionVector();
        $dx = (int) round($dir->getX());
        $dz = (int) round($dir->getZ());
        if($dx === 0 && $dz === 0){
            $dz = 1; // fallback forward
        }

        $target = (new Vector3((int) floor($base->getX()) + $dx, (int) floor($base->getY()), (int) floor($base->getZ()) + $dz));

        $world->setBlock($target, VanillaBlocks::DIAMOND_BLOCK());

        $titlePos = (new Vector3($target->getX() + 0.5, $target->getY() + 1.8, $target->getZ() + 0.5));
        $particle = new FloatingTextParticle("roulette");
        $world->addParticle($titlePos, $particle);

        $key = $this->posKey($world->getFolderName(), $target);
        $this->activeTables[$key] = true;
        $this->titleParticles[$key] = $particle;
    }

    public function onInteract(PlayerInteractEvent $event) : void
    {
        $block = $event->getBlock();
        $pos = $block->getPosition();
        $key = $this->posKey($pos->getWorld()->getFolderName(), $block->getPosition());

        if(!isset($this->activeTables[$key])){
            return; // not one of our tables
        }

        // prevent multiple concurrent sessions on same table
        if(isset($this->sessions[$key])){
            $event->getPlayer()->sendMessage(TF::YELLOW . "La roulette est actuellement utilisée, veuillez patienter.");
            return;
        }

        // Open number selection menu
        $this->openNumberSelectionMenu($event->getPlayer(), $pos);
        $event->cancel();
    }

    private function openNumberSelectionMenu(Player $player, Position $tablePos) : void
    {
        $menu = InvMenu::create(InvMenuTypeIds::TYPE_DOUBLE_CHEST);
        $menu->setName(TF::BOLD . TF::AQUA . "Choisissez un numéro (1-30)");

        $inv = $menu->getInventory();
        for($i = 1; $i <= 30; $i++){
            $is_red = ($i % 2) === 1; // 1=red, 2=black, ...
            $wool_block = VanillaBlocks::WOOL()->setColor($is_red ? DyeColor::RED() : DyeColor::BLACK());
            $item = $wool_block->asItem();
            $item->setCustomName("numéro {$i}\nx 2");
            $inv->setItem($i - 1, $item);
        }

        $menu->setListener(InvMenu::readonly(function(DeterministicInvMenuTransaction $tx) use ($tablePos) : void {
            $player = $tx->getPlayer();
            $clicked = $tx->getItemClicked();
            $name = $clicked->getCustomName();
            if($name === ''){
                return;
            }
            if(!preg_match('/numéro\s+(\d+)/ui', $name, $m)){
                return;
            }
            $chosen = (int) $m[1];
            $isRed = ($chosen % 2) === 1; // consistent with display

            // proceed to bet selection
            $this->openBetMenu($player, $tablePos, $chosen, $isRed ? 'red' : 'black');
        }));

        $menu->send($player);
    }

    private function openBetMenu(Player $player, Position $tablePos, int $chosenNumber, string $chosenColor) : void
    {
        $menu = InvMenu::create(InvMenuTypeIds::TYPE_HOPPER);
        $menu->setName(TF::BOLD . TF::GOLD . "Sélectionnez votre mise (diamants)");

        $inv = $menu->getInventory();
        $slotAmounts = [0 => 1, 2 => 5, 4 => 10];
        foreach($slotAmounts as $slot => $amount){
            $paper = VanillaItems::PAPER();
            $paper->setCustomName((string) $amount);
            $inv->setItem($slot, $paper);
        }

        $menu->setListener(InvMenu::readonly(function(DeterministicInvMenuTransaction $tx) use ($tablePos, $chosenNumber, $chosenColor, $slotAmounts) : void {
            $player = $tx->getPlayer();
            $clicked = $tx->getItemClicked();
            $label = $clicked->getCustomName();
            if($label === ''){
                return;
            }
            if(!ctype_digit($label)){
                return;
            }

            $bet = (int) $label;
            if(!$this->tryRemoveDiamonds($player, $bet)){
                $player->sendMessage(TF::RED . "Pas assez de diamand.");
                return;
            }

            // Close the menu then start the spin
            $player->removeCurrentWindow();

            $this->startSpin($player, $tablePos, $chosenNumber, $chosenColor, $bet);
        }));

        $menu->send($player);
    }

    private function startSpin(Player $player, Position $tablePos, int $chosenNumber, string $chosenColor, int $bet) : void
    {
        $world = $tablePos->getWorld();
        $key = $this->posKey($world->getFolderName(), $tablePos);

        $session = new RouletteSession($player->getName(), $tablePos, $chosenNumber, $chosenColor, $bet);
        $this->sessions[$key] = $session;

        // Animation: alternate red/black wool quickly for 5s
        $toggle = true;
        $handler = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use ($world, $tablePos, &$toggle) : void {
            $block = VanillaBlocks::WOOL()->setColor($toggle ? DyeColor::RED() : DyeColor::BLACK());
            $world->setBlock($tablePos, $block);
            $toggle = !$toggle;
        }), 5); // every 5 ticks

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $world, $tablePos, $chosenNumber, $chosenColor, $bet, $handler, $key) : void {
            // stop animation and resolve
            if($handler instanceof TaskHandler){
                $handler->cancel();
            }

            // Pick winning number 1..30
            $winningNumber = mt_rand(1, 30);
            $winningColor = ($winningNumber % 2) === 1 ? 'red' : 'black';

            // Restore diamond block
            $world->setBlock($tablePos, VanillaBlocks::DIAMOND_BLOCK());

            $win = 0;
            if($winningNumber === $chosenNumber){
                $win = $bet * 3;
                if($win > 0){
                    $player->getInventory()->addItem(VanillaItems::DIAMOND()->setCount($win));
                }
                $player->sendMessage(TF::GREEN . "jackpot, vous avez gagné votre gain x3 puisque vous avez choisi le bon numéro");
            }elseif($winningColor === $chosenColor){
                $win = $bet * 2;
                if($win > 0){
                    $player->getInventory()->addItem(VanillaItems::DIAMOND()->setCount($win));
                }
                $player->sendMessage(TF::AQUA . "Vous avez choisi la bonne couleur, vous avez donc x2 votre mise");
            }else{
                $player->sendMessage(TF::RED . "Vous avez perdu, le numéro tombé est " . $winningNumber . ".");
            }

            unset($this->sessions[$key]);
        }), 20 * 5); // 5 seconds
    }

    private function tryRemoveDiamonds(Player $player, int $amount) : bool
    {
        $inv = $player->getInventory();
        $cost = VanillaItems::DIAMOND()->setCount($amount);
        if(!$inv->contains($cost)){
            return false;
        }
        $inv->removeItem($cost);
        return true;
    }

    private function posKey(string $worldFolder, Vector3 $pos) : string
    {
        return $worldFolder . ':' . ((int) floor($pos->getX())) . ':' . ((int) floor($pos->getY())) . ':' . ((int) floor($pos->getZ()));
    }

    /**
     * =====================
     * Region system (FormAPI UI + enforcement)
     * =====================
     */

    private function openRgMain(Player $player) : void
    {
        $form = new SimpleForm(function(Player $player, ?int $data) : void {
            if($data === null){
                return;
            }
            switch($data){
                case 0:
                    $this->openRegionForm($player, null);
                    break;
                case 1:
                    $this->openDeleteRegionForm($player);
                    break;
                case 2:
                    $this->openListRegionsForm($player);
                    break;
            }
        });
        $form->setTitle("§6Protection • Menu");
        $form->setContent("§7Choisissez une action.");
        $form->addButton("Créer une région");
        $form->addButton("Supprimer une région");
        $form->addButton("Liste des régions");
        $player->sendForm($form);
    }

    private function openRegionForm(Player $player, ?Region $region) : void
    {
        $editing = $region !== null;
        $pos = $player->getPosition();
        $worldName = $editing ? $region->world : $player->getWorld()->getFolderName();

        $defaults = [
            'name' => $editing ? $region->name : '',
            'x1' => $editing ? $region->minX : (int) floor($pos->getX()),
            'y1' => $editing ? $region->minY : (int) floor($pos->getY()),
            'z1' => $editing ? $region->minZ : (int) floor($pos->getZ()),
            'x2' => $editing ? $region->maxX : (int) floor($pos->getX()),
            'y2' => $editing ? $region->maxY : (int) floor($pos->getY()),
            'z2' => $editing ? $region->maxZ : (int) floor($pos->getZ()),
            'fire' => $editing ? $region->allowFireDamage : false,
            'pearl' => $editing ? $region->allowEnderPearl : true,
            'pvp' => $editing ? $region->allowPvp : true,
            'break' => $editing ? $region->allowBreak : false,
            'place' => $editing ? $region->allowPlace : false,
            'priority' => $editing ? $region->priority : false
        ];

        $form = new CustomForm(function(Player $player, ?array $data) use ($editing, $region, $defaults, $worldName) : void {
            if($data === null){
                return;
            }
            // indices mapping
            // 0 label, 1 name, 2 x1,3 y1,4 z1, 5 x2,6 y2,7 z2, 8 fire,9 pearl,10 pvp,11 break,12 place,13 priority
            $name = trim((string) ($data[1] ?? ''));
            $x1 = (int) ($data[2] ?? 0);
            $y1 = (int) ($data[3] ?? 0);
            $z1 = (int) ($data[4] ?? 0);
            $x2 = (int) ($data[5] ?? 0);
            $y2 = (int) ($data[6] ?? 0);
            $z2 = (int) ($data[7] ?? 0);
            $fire = (bool) ($data[8] ?? false);
            $pearl = (bool) ($data[9] ?? true);
            $pvp = (bool) ($data[10] ?? true);
            $break = (bool) ($data[11] ?? false);
            $place = (bool) ($data[12] ?? false);
            $priority = (bool) ($data[13] ?? false);

            if($name === ''){
                $player->sendMessage("§c» Veuillez entrer un nom valide.");
                return;
            }

            // normalize
            $minX = min($x1, $x2); $maxX = max($x1, $x2);
            $minY = min($y1, $y2); $maxY = max($y1, $y2);
            $minZ = min($z1, $z2); $maxZ = max($z1, $z2);

            if($editing){
                $oldName = $region->name;
                $region->name = $name;
                $region->minX = $minX; $region->minY = $minY; $region->minZ = $minZ;
                $region->maxX = $maxX; $region->maxY = $maxY; $region->maxZ = $maxZ;
                $region->allowFireDamage = $fire;
                $region->allowEnderPearl = $pearl;
                $region->allowPvp = $pvp;
                $region->allowBreak = $break;
                $region->allowPlace = $place;
                $region->priority = $priority;
                $this->regionManager->updateRegion($region, $oldName);
                $player->sendMessage("§a» Région mise à jour: {$this->formatRegionSummary($region)}");
            }else{
                $creator = $player->getName();
                $newRegion = new Region($name, $worldName, $creator, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $fire, $pearl, $pvp, $break, $place, $priority);
                if(!$this->regionManager->addRegion($newRegion)){
                    $player->sendMessage("§c» Une région portant ce nom existe déjà.");
                    return;
                }
                $player->sendMessage("§a» Région créée: {$this->formatRegionSummary($newRegion)}");
            }
        });

        $form->setTitle($editing ? "§6Modifier une région" : "§6Créer une région");
        $form->addLabel("Monde: {$worldName}"); // 0
        $form->addInput("Nom de la région", "ex: spawn", (string) $defaults['name']); // 1
        $form->addInput("X1", "", (string) $defaults['x1']); // 2
        $form->addInput("Y1", "", (string) $defaults['y1']); // 3
        $form->addInput("Z1", "", (string) $defaults['z1']); // 4
        $form->addInput("X2", "", (string) $defaults['x2']); // 5
        $form->addInput("Y2", "", (string) $defaults['y2']); // 6
        $form->addInput("Z2", "", (string) $defaults['z2']); // 7
        $form->addToggle("Dégâts de feu", (bool) $defaults['fire']); // 8
        $form->addToggle("Perle (ender pearl)", (bool) $defaults['pearl']); // 9
        $form->addToggle("PvP", (bool) $defaults['pvp']); // 10
        $form->addToggle("Casser des blocs", (bool) $defaults['break']); // 11
        $form->addToggle("Poser des blocs", (bool) $defaults['place']); // 12
        $form->addToggle("Prioritaire", (bool) $defaults['priority']); // 13
        $player->sendForm($form);
    }

    private function openDeleteRegionForm(Player $player) : void
    {
        $regions = $this->regionManager->getAllRegions();
        $form = new SimpleForm(function(Player $player, ?int $data) use ($regions) : void {
            if($data === null){
                return;
            }
            $region = $regions[$data] ?? null;
            if(!$region instanceof Region){
                return;
            }
            $this->regionManager->removeRegion($region->name);
            $player->sendMessage("§a» region {$region->name} bien supprimé");
        });
        $form->setTitle("§6Supprimer une région");
        if(empty($regions)){
            $form->setContent("§7Aucune région.");
        }else{
            $form->setContent("§7Cliquez pour supprimer.");
            foreach($regions as $r){
                $form->addButton($r->name . " (" . $r->creator . ")");
            }
        }
        $player->sendForm($form);
    }

    private function openListRegionsForm(Player $player) : void
    {
        $regions = $this->regionManager->getAllRegions();
        $form = new SimpleForm(function(Player $player, ?int $data) use ($regions) : void {
            if($data === null){
                return;
            }
            $region = $regions[$data] ?? null;
            if(!$region instanceof Region){
                return;
            }
            $this->openRegionForm($player, $region);
        });
        $form->setTitle("§6Liste des régions");
        if(empty($regions)){
            $form->setContent("§7Aucune région.");
        }else{
            $form->setContent("§7Cliquez pour modifier.");
            foreach($regions as $r){
                $form->addButton($r->name . " (" . $r->creator . ")");
            }
        }
        $player->sendForm($form);
    }

    private function formatRegionSummary(Region $r) : string
    {
        return "{$r->name} (par {$r->creator}) monde={$r->world} [{$r->minX},{$r->minY},{$r->minZ}] -> [{$r->maxX},{$r->maxY},{$r->maxZ}], feu=" . ($r->allowFireDamage ? 'on' : 'off') . ", pearl=" . ($r->allowEnderPearl ? 'on' : 'off') . ", pvp=" . ($r->allowPvp ? 'on' : 'off') . ", casser=" . ($r->allowBreak ? 'on' : 'off') . ", poser=" . ($r->allowPlace ? 'on' : 'off') . ", prioritaire=" . ($r->priority ? 'oui' : 'non');
    }

    // ================= Enforcement =================

    /** @priority LOWEST */
    public function onBlockBreak(BlockBreakEvent $event) : void
    {
        $player = $event->getPlayer();
        if($this->regionManager->hasBypass($player->getName())){
            return;
        }
        $pos = $event->getBlock()->getPosition();
        if(!$this->regionManager->isAllowedAt($pos, RegionManager::FLAG_BREAK)){
            $event->cancel();
            $player->sendMessage("§c» Vous ne pouvez pas effectué cet action.");
        }
    }

    /** @priority LOWEST */
    public function onBlockPlace(BlockPlaceEvent $event) : void
    {
        $player = $event->getPlayer();
        if($this->regionManager->hasBypass($player->getName())){
            return;
        }
        $pos = $event->getBlock()->getPosition();
        if(!$this->regionManager->isAllowedAt($pos, RegionManager::FLAG_PLACE)){
            $event->cancel();
            $player->sendMessage("§c» Vous ne pouvez pas effectué cet action.");
        }
    }

    /** @priority LOWEST */
    public function onDamage(EntityDamageEvent $event) : void
    {
        $entity = $event->getEntity();
        $pos = $entity->getPosition();
        if($event instanceof EntityDamageByEntityEvent){
            $damager = $event->getDamager();
            if($damager instanceof Player && $entity instanceof Player){
                if($this->regionManager->hasBypass($damager->getName())){
                    return;
                }
                if(!$this->regionManager->isAllowedAt($pos, RegionManager::FLAG_PVP)){
                    $event->cancel();
                    $damager->sendMessage("§c» Vous ne pouvez pas effectué cet action.");
                }
            }
            return;
        }

        $cause = $event->getCause();
        if(in_array($cause, [EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FIRE_TICK, EntityDamageEvent::CAUSE_LAVA], true)){
            if(!$this->regionManager->isAllowedAt($pos, RegionManager::FLAG_FIRE)){
                $event->cancel();
            }
        }
    }

    /** @priority LOWEST */
    public function onPearlUse(PlayerItemUseEvent $event) : void
    {
        $player = $event->getPlayer();
        if($this->regionManager->hasBypass($player->getName())){
            return;
        }
        $item = $event->getItem();
        if($item->equals(VanillaItems::ENDER_PEARL(), false, false)){
            $pos = $player->getPosition();
            if(!$this->regionManager->isAllowedAt($pos, RegionManager::FLAG_PEARL)){
                $event->cancel();
                $player->sendMessage("§c» Vous ne pouvez pas effectué cet action.");
            }
        }
    }
}
