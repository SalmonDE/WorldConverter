<?php
declare(strict_types = 1);

namespace SalmonDE\WorldConverter;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\format\EmptySubChunk;
use pocketmine\level\format\io\LevelProvider;
use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;

class Loader extends PluginBase {

	private $blocks = [];

	public function onEnable(): void{
		$this->saveResource('config.yml');
		$this->saveResource('blocks.json');
		$this->blocks = json_decode(file_get_contents($this->getDataFolder().'blocks.json'), true)['blocks'];
	}

	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $params): bool{
		if(isset($params[0])){
			if(empty($params[0])){
				return false;
			}else{
				if($this->getServer()->getLevelManager()->loadLevel($params[0])){
					$level = $this->getServer()->getLevelManager()->getLevelByName($params[0]);
				}else{
					$sender->sendMessage('Level not found');
				}
			}
		}elseif($sender instanceof Player){
			$level = $sender->getLevel();
		}else{
			return false;
		}

		if(($level ?? null) === null){
			$sender->sendMessage('§cLevel not found');
			return false;
		}

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

		$msg = '§a(Conversion of '.$level->getFolderName().') Time spent: '.($time).' seconds; Total blocks: '.number_format($total).'; Blocks processed: '.number_format($processed).'; Blocks changed: '.number_format($changed);
		$sender->sendMessage($msg);
		$this->getLogger()->notice($msg);
		return true;
	}

	public function convertAllBlocks(LevelProvider $provider, int &$total = 0, int &$processed = 0, int &$changed = 0, int &$time): void{
		$time = time();

		$levelName = $provider->getLevelData()->getName();
		$chunkCount = $provider->calculateChunkCount();
		$chunksConverted = 0;

		foreach($provider->getAllChunks() as $chunk){
			$percentage = round(++$chunksConverted * 100 / $chunkCount, 2);
			$this->getLogger()->notice('('.$percentage.'%) Converting level "'.$levelName.'"; Chunk '.($chunksConverted).'/'.$chunkCount);

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
