<?php
/**
 * @copyright 2014-2022 Roman Parpalak
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @package   Upmath Latex Renderer
 * @link      https://i.upmath.me
 */

namespace S2\Tex\Renderer;

use Psr\Log\LoggerInterface;
use S2\Tex\Helper;
use S2\Tex\TemplaterInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs Latex CLI.
 */
class Renderer implements RendererInterface
{
	private TemplaterInterface $templater;
	private ?PngConverter $pngConverter = null;
	private ?JpgConverter $jpgConverter = null;
	private ?LoggerInterface $logger = null;
	private string $tmpDir;
	private bool $isDebug = false;
	private string $texPath;
	private string $latexCommand;
	private string $svgCommand;

	public function __construct(
		TemplaterInterface $templater,
		string             $tmpDir,
		string             $texPath,
		string             $latexCommand,
		string             $svgCommand
	) {
		$this->templater    = $templater;
		$this->tmpDir       = $tmpDir;
		$this->latexCommand = $latexCommand;
		$this->svgCommand   = $svgCommand;
		$this->texPath      = $texPath;
	}

	public function setIsDebug(bool $isDebug): self
	{
		$this->isDebug = $isDebug;

		return $this;
	}

	public function setLogger(LoggerInterface $logger): self
	{
		$this->logger = $logger;

		return $this;
	}

	public function setPngConverter(PngConverter $pngConverter): self
	{
		$this->pngConverter = $pngConverter;

		return $this;
	}

	public function setJpgConverter(JpgConverter $jpgConverter): self
	{
		$this->jpgConverter = $jpgConverter;

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run(string $formula, string $type): string
	{
		$this->validateFormula($formula);

		$formulaObj = $this->templater->run($formula);
		$texSource  = $formulaObj->getText();
		$this->echoDebug(htmlspecialchars($texSource));

		$workDir = $this->createWorkingDirectory();
		$tmpName = $workDir . '/formula';

		// Latex
		if (file_put_contents($tmpName, $texSource, LOCK_EX) === false) {
			$this->cleanupWorkingDirectory($workDir);
			throw new \RuntimeException('Cannot write the temporary Latex source.');
		}

		/**
		 * Adding the TeX bin directory (e.g. `/home/tex/tl-2020/bin/x86_64-linux`) to the PATH environment variable.
		 * Otherwise, there can be floating bugs when generating font files.
		 *
		 * See https://github.com/symfony/symfony/issues/5030 for 'exec' hack
		 */
		$process = Process::fromShellCommandline(
			'exec ' . $this->latexCommand . ' ' . escapeshellarg(basename($tmpName)),
			$workDir,
			$this->processEnvironment($workDir)
		);
		$process->setTimeout(8);

		try {
			$exitCode = Helper::newRelicProfileDataStore(
				static fn() => $process->run(),
				'shell',
				Helper::getShortCommandName($this->latexCommand)
			);
		} catch (\Throwable $e) {
			if ($this->logger !== null) {
				$message = $e instanceof ProcessTimedOutException ? 'Latex has been interrupted by a timeout' : 'Cannot run Latex';
				$this->logger->error($message, [
					'message' => $e->getMessage(),
					'command' => $process->getCommandLine(),
					'source'  => $texSource,
				]);
			}
			$this->dumpDebug($texSource);
			$this->cleanupWorkingDirectory($workDir);

			throw $e;
		}

		if ($this->isDebug) {
			echo '<pre>';
			readfile($tmpName . '.log');
			var_dump('exitcode', $exitCode);
			echo '</pre>';
		}

		if (!file_exists($tmpName . '.dvi')) {
			// Ohe has to figure out why the process was killed and why no dvi-file has been created.
			if ($this->logger !== null) {
				$this->logger->error('Latex finished incorrectly', [
					'command'                   => $process->getCommandLine(),
					'exit_code'                 => $process->getExitCode(),
					'exit_code_text'            => $process->getExitCodeText(),
					"file_exists($tmpName.dvi)" => file_exists($tmpName . '.dvi'),
				]);
				$this->logger->error('source', [$texSource]);
				$this->logger->error('trace (' . $tmpName . '.log)', [file_get_contents($tmpName . '.log')]);
			}

			$this->cleanupWorkingDirectory($workDir);
			throw new \RuntimeException('Invalid formula');
		}

		// DVI -> SVG
		$cmd        = sprintf($this->svgCommand, escapeshellarg($tmpName));
		$svgProcess = Process::fromShellCommandline($cmd, $workDir, $this->processEnvironment($workDir));
		$svgProcess->setTimeout(8);
		try {
			Helper::newRelicProfileDataStore(
				static fn() => $svgProcess->run(),
				'shell',
				Helper::getShortCommandName($cmd)
			);
		} catch (\Throwable $e) {
			$this->cleanupWorkingDirectory($workDir);
			throw $e;
		}
		$svgOutput = $svgProcess->getOutput() . $svgProcess->getErrorOutput();

		$this->dumpDebug($cmd);
		$this->dumpDebug($svgOutput);
		if (!file_exists($tmpName . '.svg')) {
			// Ohe has to figure out why the dvisvgm command has failed.
			if ($this->logger !== null) {
				$this->logger->error('Dvisvgm finished incorrectly', [
					'command'                   => $cmd,
					'dvisvgm output'            => $svgOutput,
					"file_exists($tmpName.svg)" => file_exists($tmpName . '.svg'),
				]);
				$this->logger->error('source', [$texSource]);
				$this->logger->error('trace (' . $tmpName . '.log)', [file_get_contents($tmpName . '.log')]);
			}

			$this->cleanupWorkingDirectory($workDir);
			throw new \RuntimeException('Invalid formula');
		}

		try {
			$rawSvg = file_get_contents($tmpName . '.svg');
			if ($rawSvg === false) {
				throw new \RuntimeException('Cannot read the rendered SVG file.');
			}
			$svgContent = SvgHelper::processSvgContent($rawSvg, $formulaObj->useBaseline());
			if (file_put_contents($tmpName . '.svg', $svgContent, LOCK_EX) === false) {
				throw new \RuntimeException('Cannot sanitize the rendered SVG file.');
			}
		} catch (\Throwable $e) {
			$this->cleanupWorkingDirectory($workDir);
			throw $e;
		}

		try {
			if ($type === 'png') {
				if ($this->pngConverter === null) {
					throw new \RuntimeException('PNG converter is not configured.');
				}
				$pngContent = $this->pngConverter->convert($tmpName . '.svg');
			} elseif ($type === 'jpg' || $type === 'jpeg') {
				if ($this->jpgConverter === null) {
					throw new \RuntimeException('JPG converter is not configured.');
				}
				$jpgContent = $this->jpgConverter->convert($tmpName . '.svg');
			}
		} catch (\Throwable $e) {
			$this->cleanupWorkingDirectory($workDir);
			throw $e;
		}

		// Cleaning up
		$this->cleanupWorkingDirectory($workDir);

		if ($type === 'png') {
			return $pngContent;
		}
		if ($type === 'jpg' || $type === 'jpeg') {
			return $jpgContent;
		}
		return $svgContent;
	}

	private function createWorkingDirectory(): string
	{
		$baseDir = rtrim($this->tmpDir, '/');
		for ($attempt = 0; $attempt < 10; $attempt++) {
			$workDir = $baseDir . '/render-' . bin2hex(random_bytes(16));
			if (@mkdir($workDir, 0700)) {
				return $workDir;
			}
		}

		throw new \RuntimeException('Cannot create a temporary working directory.');
	}

	private function cleanupWorkingDirectory(string $workDir): void
	{
		if (!is_dir($workDir)) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($files as $file) {
			$path = $file->getPathname();
			if ($file->isLink() || $file->isFile()) {
				@unlink($path);
			} else {
				@rmdir($path);
			}
		}
		@rmdir($workDir);
	}

	private function processEnvironment(string $workDir): array
	{
		return [
			'HOME'         => $workDir,
			'PATH'         => rtrim($this->texPath, '/') . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'TEMP'         => $workDir,
			'TEXMFOUTPUT'  => $workDir,
			'TMP'          => $workDir,
			'TMPDIR'       => $workDir,
			'openin_any'   => 'p',
			'openout_any'  => 'p',
			'shell_escape' => 'f',
		];
	}

	/**
	 * @param mixed $output
	 */
	private function dumpDebug($output): void
	{
		if ($this->isDebug) {
			echo '<pre>';
			var_dump($output);
			echo '</pre>';
		}
	}

	private function echoDebug(string $output): void
	{
		if ($this->isDebug) {
			echo '<pre>';
			echo $output;
			echo '</pre>';
		}
	}

	private function validateFormula(string $formula): void
	{
		foreach (['\\write', '\\input', '\\usepackage', '\\special', '\\include'] as $disabledCommand) {
			if (strpos($formula, $disabledCommand) !== false) {
				if ($this->logger !== null) {
					$this->logger->error(sprintf('Forbidden command "%s": ', $disabledCommand), [$formula]);
				}
				throw new \RuntimeException('Forbidden commands.');
			}
		}

		if (preg_match('#{\\s*filecontents\\s*\\*?\\s*}#', $formula) === 1) {
			if ($this->logger !== null) {
				$this->logger->error(sprintf('Forbidden command "%s": ', 'filecontents'), [$formula]);
			}
			throw new \RuntimeException('Forbidden commands.');
		}
	}
}
