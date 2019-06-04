<?php
declare(strict_types = 1);

namespace SalmonDE\WorldConverter;

use Thread;

class InputCheckThread extends Thread {

	public $listening = true;
	private $input = null;

	public function run(): void{
		while($this->listening === true){
			if($this->getInput() === null){
				$input = $this->waitForInput();
				$this->checkInput(strtolower($input));
			}
		}
	}

	public function checkInput(string $input): void{
		if(trim($input) === 'stop'){
			$this->setInput($input);
		}
	}

	public function getInput(): ?string{
		return $this->input;
	}

	private function setInput(string $input): void{
		$this->input = $input;
	}

	public function resetInput(): void{
		$this->input = null;
	}

	public function waitForInput(int $length = 1024, int $timeout = 8): string{
		$cmdLine = fopen('php://stdin', 'r');

		$read = [$cmdLine];
		$write = [];
		$except = [];

		if(stream_select($read, $write, $except, $timeout) > 0){
			return trim(stream_get_line($cmdLine, $length, PHP_EOL));
		}else{
			return '';
		}
	}
}
