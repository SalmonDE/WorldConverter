<?php
declare(strict_types = 1);

namespace SalmonDE\WorldConverter;

use \Thread;

class InputCheckThread extends Thread {

	private $input = null;

	public function run(): void{
		$input = $this->waitForInput();
		$this->checkInput(strtolower($input));
	}

	private function checkInput(string $input): void{
		if($input === 'stop'){
			$this->setInput('stop');
		}
	}

	public function getInput(): ?string{
		return $this->input;
	}

	private function setInput(string $input): void{
		$this->input = $input;
	}

	private function waitForInput(int $length = 1024, int $timeout = 5): string{
		$cmdLine = fopen('php://stdin', 'r');

		$read = [$cmdLine];
		$write = [];
		$except = [];

		if(stream_select($read, $write, $except, $timeout) > 0){
			return trim(stream_get_line($cmdLine, $length, PHP_EOL));
		}else{
			return ''; // hack to prevent blocking the main thread on join
		}
	}
}
