<?php

declare(strict_types=1);

namespace Roulette;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\DeterministicInvMenuTransaction;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
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

final class Main extends PluginBase implements Listener
{
    /** @var array<string, true> */
    private array $activeTables = [];

    /** @var array<string, RouletteSession> */
    private array $sessions = [];

    /** @var array<string, FloatingTextParticle> */
    private array $titleParticles = [];

    protected function onEnable() : void
    {
        if(!InvMenuHandler::isRegistered()){
            InvMenuHandler::register($this);
        }
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool
    {
        if($command->getName() === 'adminroulette'){
            if(!$sender instanceof Player){
                $sender->sendMessage("Cette commande doit être exécutée en jeu.");
                return true;
            }
            if(!$sender->hasPermission('roulette.admin')){
                $sender->sendMessage(TF::RED . "Vous n'avez pas la permission.");
                return true;
            }

            $this->spawnRouletteTableInFrontOf($sender);
            $sender->sendMessage(TF::GREEN . "Table de roulette créée devant vous.");
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
}
