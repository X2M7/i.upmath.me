<?php
/**
 * @copyright 2020-2022 Roman Parpalak
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @package   Upmath Latex Renderer
 * @link      https://i.upmath.me
 */

namespace S2\Tex\Renderer;

use S2\Tex\Helper;
use Symfony\Component\Process\Process;

class PngConverter
{
	private string $svg2pngCommand;

	public function __construct(string $svg2pngCommand)
	{
		$this->svg2pngCommand = $svg2pngCommand;
	}

	public function convert(string $svgFileName): string
	{
		$command = sprintf($this->svg2pngCommand, escapeshellarg($svgFileName));

		$process = Process::fromShellCommandline($command);
		$process->setTimeout(8);
		Helper::newRelicProfileDataStore(
			static fn () => $process->mustRun(),
			'shell',
			Helper::getShortCommandName($command)
		);

		return $process->getOutput();
	}
}
