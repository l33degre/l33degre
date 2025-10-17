<?php

declare(strict_types=1);

namespace Roulette\region;

final class Region
{
    public function __construct(
        public string $name,
        public string $world,
        public string $creator,
        public int $minX,
        public int $minY,
        public int $minZ,
        public int $maxX,
        public int $maxY,
        public int $maxZ,
        public bool $allowFireDamage,
        public bool $allowEnderPearl,
        public bool $allowPvp,
        public bool $allowBreak,
        public bool $allowPlace,
        public bool $priority
    ){
        // normalize bounds
        $this->minX = min($this->minX, $this->maxX);
        $this->maxX = max($this->minX, $this->maxX);
        $this->minY = min($this->minY, $this->maxY);
        $this->maxY = max($this->minY, $this->maxY);
        $this->minZ = min($this->minZ, $this->maxZ);
        $this->maxZ = max($this->minZ, $this->maxZ);
    }

    public function contains(string $world, int $x, int $y, int $z) : bool
    {
        if($world !== $this->world){
            return false;
        }
        return $x >= $this->minX && $x <= $this->maxX
            && $y >= $this->minY && $y <= $this->maxY
            && $z >= $this->minZ && $z <= $this->maxZ;
    }

    public function toArray() : array
    {
        return [
            'name' => $this->name,
            'world' => $this->world,
            'creator' => $this->creator,
            'minX' => $this->minX,
            'minY' => $this->minY,
            'minZ' => $this->minZ,
            'maxX' => $this->maxX,
            'maxY' => $this->maxY,
            'maxZ' => $this->maxZ,
            'allowFireDamage' => $this->allowFireDamage,
            'allowEnderPearl' => $this->allowEnderPearl,
            'allowPvp' => $this->allowPvp,
            'allowBreak' => $this->allowBreak,
            'allowPlace' => $this->allowPlace,
            'priority' => $this->priority
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data) : self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['world'] ?? ''),
            (string) ($data['creator'] ?? ''),
            (int) ($data['minX'] ?? 0),
            (int) ($data['minY'] ?? 0),
            (int) ($data['minZ'] ?? 0),
            (int) ($data['maxX'] ?? 0),
            (int) ($data['maxY'] ?? 0),
            (int) ($data['maxZ'] ?? 0),
            (bool) ($data['allowFireDamage'] ?? false),
            (bool) ($data['allowEnderPearl'] ?? true),
            (bool) ($data['allowPvp'] ?? true),
            (bool) ($data['allowBreak'] ?? false),
            (bool) ($data['allowPlace'] ?? false),
            (bool) ($data['priority'] ?? false),
        );
    }
}
