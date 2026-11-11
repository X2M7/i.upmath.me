<?php

declare(strict_types=1);

namespace S2\Tex\Test\unit\Processor;

use Codeception\Test\Unit;
use S2\Tex\Cache\CacheProvider;
use S2\Tex\Helper;
use S2\Tex\Processor\CachedResponse;
use S2\Tex\Processor\DelayedProcessor;
use S2\Tex\Processor\Processor;
use S2\Tex\Processor\Request;
use S2\Tex\Renderer\JpgConverter;
use S2\Tex\Renderer\PngConverter;
use S2\Tex\Renderer\RendererInterface;

class CacheTest extends Unit
{
	private string $root;
	private CacheProvider $provider;
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><animateTransform/></svg>';

	protected function _before(): void
	{
		$this->root = sys_get_temp_dir() . '/upmath-cache-test-' . bin2hex(random_bytes(8));
		mkdir($this->root);
		$this->provider = new CacheProvider($this->root . '/success/', $this->root . '/error/');
	}

	protected function _after(): void
	{
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->root);
	}

	public function testGzipOnlyCacheAvoidsRenderingAndPreservesModificationTime(): void
	{
		$request = new Request('x^2', Request::SVG);
		$path = $this->provider->cachePathFromRequest($request, false) . '.gz';
		Helper::filePut($path, gzencode(self::SVG));
		touch($path, 1700000000);

		$renderer = $this->createMock(RendererInterface::class);
		$renderer->expects($this->never())->method('run');
		$processor = $this->processor($renderer);
		for ($i = 0; $i < 2; $i++) {
			$response = $processor->process($request);
			$this->assertInstanceOf(CachedResponse::class, $response);
			$this->assertSame(self::SVG, $response->getContent());
		}
		$this->assertSame($path, $this->provider->getCacheState($request)->getCacheName());
		$this->assertSame(1700000000, filemtime($path));
	}

	public function testPlainSvgTakesPrecedenceOverGzip(): void
	{
		$request = new Request('x', Request::SVG);
		$path = $this->provider->cachePathFromRequest($request, false);
		Helper::filePut($path, self::SVG);
		Helper::filePut($path . '.gz', gzencode('older SVG'));
		$renderer = $this->createMock(RendererInterface::class);
		$renderer->expects($this->never())->method('run');
		$this->assertSame(self::SVG, $this->processor($renderer)->process($request)->getContent());
	}

	public function testCorruptGzipFallsBackToRendering(): void
	{
		$request = new Request('x', Request::SVG);
		Helper::filePut($this->provider->cachePathFromRequest($request, false) . '.gz', 'broken');
		$renderer = $this->createMock(RendererInterface::class);
		$renderer->expects($this->once())->method('run')->with('x', Request::SVG)->willReturn(self::SVG);
		$response = $this->processor($renderer)->process($request);
		$this->assertNotInstanceOf(CachedResponse::class, $response);
		$this->assertSame(self::SVG, $response->getContent());
	}

	public function testGzipCacheDoesNotMixColorVariantsOrImageFormats(): void
	{
		$request = new Request('x', Request::SVG);
		Helper::filePut($this->provider->cachePathFromRequest($request, false) . '.gz', gzencode(self::SVG));
		$this->assertTrue($this->provider->getCacheState($request)->cacheExists());
		$this->assertFalse($this->provider->getCacheState(new Request('x', Request::SVG, '#eaeaea'))->cacheExists());
		$this->assertFalse($this->provider->getCacheState($request->withExtension(Request::PNG))->cacheExists());
	}

	public function testPngConversionReusesGzipOnlySvg(): void
	{
		$request = new Request('x', Request::PNG);
		$path = $this->provider->cachePathFromRequest($request->withExtension(Request::SVG), false) . '.gz';
		Helper::filePut($path, gzencode(self::SVG));
		$renderer = $this->createMock(RendererInterface::class);
		$renderer->expects($this->never())->method('run');
		$converter = $this->createMock(PngConverter::class);
		$converter->expects($this->once())->method('convert')->with($path)->willReturn('PNG');
		$this->assertSame('PNG', $this->processor($renderer, $converter)->process($request)->getContent());
	}

	public function testOptimizationRetainsBothPlainAndGzipSvg(): void
	{
		$request = new Request('x', Request::SVG);
		$path = $this->provider->cachePathFromRequest($request, false);
		Helper::filePut($path, self::SVG);
		$processor = new DelayedProcessor($this->provider, 'http://127.0.0.1:1/');
		$processor->process($request);
		$this->assertSame(self::SVG, file_get_contents($path));
		$this->assertSame(self::SVG, gzdecode(file_get_contents($path . '.gz')));
	}

	private function processor(RendererInterface $renderer, ?PngConverter $pngConverter = null): Processor
	{
		return new Processor(
			$renderer,
			$this->provider,
			$pngConverter ?? $this->createMock(PngConverter::class),
			$this->createMock(JpgConverter::class)
		);
	}
}
