<?php
declare(strict_types = 1);

namespace SalmonDE\WorldConverter;

use Ds\Map;
use pocketmine\block\BlockFactory;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\format\EmptySubChunk;
use pocketmine\level\format\io\LevelProvider;
use pocketmine\level\Level;
use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;

class Loader extends PluginBase {

	private const SAVE_DELIMITER = ';';

	private $blocks = [];
	private $saveThreshold;

	public function onEnable(): void{
		$this->saveResource('config.yml');
		$this->saveResource('blocks.json');
		$this->saveThreshold = max(1, $this->getConfig()->get('saveThreshold'));
		$this->blocks = json_decode(file_get_contents($this->getDataFolder().'blocks.json'), true)['blocks'];

		$blockMapping = new Map();
		foreach($this->blocks as $block => $replacement){
			$targetBlock = $this->readElements($block);
			$replaceBlock = $this->readElements($replacement);

			if($targetBlock['meta'] === null){
				$targetBlock['meta'] = range(0, 15);
			}

			if($replaceBlock['meta'] !== null){
				$replaceBlock['meta'] = $replaceBlock['meta'][0];
			}

			foreach($targetBlock['meta'] as $meta){
				$blockMapping[$this->getFullBlock($targetBlock['id'], $meta)] = $this->getFullBlock($replaceBlock['id'], $replaceBlock['meta'] ?? $meta);
			}
		}

		$this->blocks = $blockMapping;

		foreach($this->blocks as $from => $to){
			$from = BlockFactory::fromFullBlock($from);
			$to = BlockFactory::fromFullBlock($to);
			$this->getLogger()->debug('Mapped '.($from->getName() === 'Unknown' ? $from->getId().':'.$from->getMeta() : $from->getName()).' to '.($to->getName() === 'Unknown' ? $to->getId().':'.$to->getMeta() : $to->getName()));
		}
	}

	private function getFullBlock(int $id, int $meta): int{
		return ($id << 4) | $meta;
	}

	private function readElements($value): array{
		if(is_string($value)){
			$array = explode(':', $value);
		}else{
			$array = [$value];
		}

		foreach($array as $key => $element){
			$array[$key] = (int) $element;
		}

		$array['id'] = $array[0];

		if(isset($array[1])){
			$array['meta'] = [$array[1]];
		}else{
			$array['meta'] = null;
		}

		return $array;
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

		$msg = '§a(Conversion of '.$level->getFolderName().') Time spent: '.($time).' seconds; Total blocks: '.number_format($total).'; Blocks processed: '.number_format($processed).'; Blocks changed: '.number_format($changed);
		$sender->sendMessage($msg);
		$this->getLogger()->notice($msg);
		return true;
	}

	public function convertAllBlocks(LevelProvider $provider, int &$total = 0, int &$processed = 0, int &$changed = 0, int &$time): void{
		$time = time();

		$levelName = $provider->getLevelData()->getName();
		$chunkCount = $provider->calculateChunkCount();
		$total = $chunkCount * 65536;
		$chunksConverted = 0;

		$chunks = [];

		$saveFilePath = $provider->getPath().'convertedChunks';
		$skipChunks = $this->readSaveFile($saveFilePath);

		$deleteSaveFile = true;

		$inputThread = new InputCheckThread();
		$inputThread->start();

		foreach($provider->getAllChunks() as $chunk){
			if(!$inputThread->isRunning() and !$inputThread->isJoined()){
				$inputThread->join();

				$input = $inputThread->getInput();
				if($input === 'stop'){
					$this->getLogger()->notice('Stopping conversion ...');
					$deleteSaveFile = false;

					if(count($chunks) > 0){
						$this->getLogger()->notice('Saving pending progress ...');
						$this->writeSaveFile($saveFilePath, $chunks);
					}

					$this->getLogger()->notice('Conversion stopped, time passed: '.(time() - $time));
					break;
				}

				$inputThread = new InputCheckThread();
				$inputThread->start();
			}

			$chunkHash = Level::chunkHash($chunk->getX(), $chunk->getZ());
			$percentage = round(++$chunksConverted * 100 / $chunkCount, 2);

			if($skipChunks !== false and ($skipChunks[$chunkHash] ?? false) === true){
				$this->getLogger()->notice('('.$percentage.'%) Converting level "'.$levelName.'"; §cSkipped Chunk §b'.($chunksConverted).'/'.$chunkCount);
				unset($skipChunks[$chunkHash]);

				if($skipChunks === []){
					$skipChunks = false;
				}
				continue;
			}

			$this->getLogger()->notice('('.$percentage.'%) Converting level "'.$levelName.'"; Chunk '.($chunksConverted).'/'.$chunkCount);

			try{
				foreach($chunk->getEntities() as $entity){
					$entity->close();
				}
			}catch(\Throwable $e){
				$this->getLogger()->warning('Corrupted entities in chunk '.$chunk->getX().';'.$chunk->getZ());
			}

			foreach($chunk->getSubChunks() as $subChunk){
				if($subChunk instanceof EmptySubChunk){
					continue;
				}

				for($x = 0; $x < 16; ++$x){
					for($y = 0; $y < 16; ++$y){
						for($z = 0; $z < 16; ++$z){
							++$processed;

							$block = $subChunk->getFullBlock($x, $y, $z);

							if(isset($this->blocks[$block])){
								$subChunk->setFullBlock($x, $y, $z, $this->blocks[$block]);
								++$changed;
							}
						}
					}
				}
			}

			$provider->saveChunk($chunk);

			$chunks[] = $chunkHash;

			if($chunksConverted % $this->saveThreshold === 0){
				$this->getLogger()->notice('Saving Progress ...');
				$this->writeSaveFile($saveFilePath, $chunks);
			}
		}

		if($deleteSaveFile === true and file_exists($saveFilePath)){
			unlink($saveFilePath);
		}

		$time = time() - $time;
	}

	private function readSaveFile($saveFilePath){
		if(!file_exists($saveFilePath)){
			return false;
		}

		$data = explode(self::SAVE_DELIMITER, file_get_contents($saveFilePath));
		$data = array_flip($data);

		foreach($data as $chunk => $_){
			$data[$chunk] = true;
		}

		return $data;
	}

	private function writeSaveFile(string $saveFilePath, array &$processedChunks): void{
		$saveFile = fopen($saveFilePath, 'a');

		$data = implode(self::SAVE_DELIMITER, $processedChunks).self::SAVE_DELIMITER;

		fwrite($saveFile, $data);
		fclose($saveFile);

		$processedChunks = [];
	}
}
