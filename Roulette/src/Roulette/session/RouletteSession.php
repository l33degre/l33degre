<?php

declare(strict_types=1);

namespace Roulette\session;

use pocketmine\world\Position;

final class RouletteSession
{
    public function __construct(
        public string $playerName,
        public Position $tablePosition,
        public int $chosenNumber,
        public string $chosenColor, // 'red' or 'black'
        public int $bet
    ){}
}
