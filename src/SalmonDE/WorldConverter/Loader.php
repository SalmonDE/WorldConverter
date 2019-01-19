<?php
declare(strict_types = 1);

namespace SalmonDE\WorldConverter;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\format\EmptySubChunk;
use pocketmine\level\format\io\LevelProvider;
use pocketmine\plugin\PluginBase;

class Loader extends PluginBase {

    private $blocks = [];

    public function onEnable(): void{
        $this->saveResource('config.yml');
        $this->saveResource('blocks.json');
        $this->blocks = json_decode(file_get_contents($this->getDataFolder().'blocks.json'), true)['blocks'];
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $params): bool{
        $level = $sender->getLevel();

        $level->setAutoSave(false);

        $total = 0;
        $processed = 0;
        $changed = 0;
        $time = 0;

        $this->convertAllBlocks($level->getProvider(), $total, $processed, $changed, $time);

        foreach($level->getChunks() as $chunk){
            $level->unloadChunk($chunk->getX(), $chunk->getZ(), false, false);
            $level->loadChunk($chunk->getX(), $chunk->getZ(), false);
        }

        $level->setAutoSave(true);

        foreach($level->getPlayers() as $player){
            foreach($player->usedChunks as $key => $v){
                $player->usedChunks[$key] = false;
            }
        }

        $sender->sendMessage('§a(Conversion) Time spent: '.($time).' seconds; Total blocks: '.number_format($total).'; Blocks processed: '.number_format($processed).'; Blocks changed: '.number_format($changed));
        return true;
    }

    public function convertAllBlocks(LevelProvider $provider, int &$total = 0, int &$processed = 0, int &$changed = 0, int &$time): void{
        $time = time();

        foreach($provider->getAllChunks() as $chunk){
            try{
                foreach($chunk->getEntities() as $entity){
                    $entity->close();
                }
            }catch(\Throwable $e){
                $this->getLogger()->warning('Corrupted entities in chunk '.$chunk->getX().';'.$chunk->getZ());
            }

            $total += 65536;

            foreach($chunk->getSubChunks() as $subChunk){
                if($subChunk instanceof EmptySubChunk){
                    continue;
                }

                for($x = 0; $x < 16; ++$x){
                    for($y = 0; $y < 16; ++$y){
                        for($z = 0; $z < 16; ++$z){
                            ++$processed;

                            $blockId = $subChunk->getBlockId($x, $y, $z);
                            $blockData = $subChunk->getBlockData($x, $y, $z);

                            if(isset($this->blocks[(string) $blockId])){
                                $newBlockId = (int) $this->blocks[(string) $blockId];
                                $subChunk->setBlock($x, $y, $z, $newBlockId, $blockData);
                                ++$changed;
                            }elseif(isset($this->blocks[$blockId.':'.$blockData])){
                                $parts = explode(':', $this->blocks[$blockId.':'.$blockData]);
                                $subChunk->setBlock($x, $y, $z, (int) $parts[0], (int) $parts[1]);
                                ++$changed;
                            }
                        }
                    }
                }
            }

            $provider->saveChunk($chunk);
        }

        $time = time() - $time;
    }
}
