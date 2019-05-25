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

	private function waitForInput(int $length = 1024): string{
		return trim(stream_get_line(fopen('php://stdin', 'r'), $length, PHP_EOL));
	}
}
