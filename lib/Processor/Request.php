<?php
/**
 * @copyright 2020-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Upmath Latex Renderer
 * @link      https://i.upmath.me
 */

namespace S2\Tex\Processor;

class Request
{
	public const SVG = 'svg';
	public const PNG = 'png';

	protected string $extension;
	protected string $formula;
	protected string $variant;

	public function __construct(string $formula, string $extension, string $variant = '')
	{
		if (!self::extensionIsValid($extension)) {
			throw new \InvalidArgumentException('Incorrect output format has been requested. Expected SVG or PNG.');
		}

		$this->formula   = $formula;
		$this->extension = $extension;
		$this->variant   = $variant;
	}

	/**
	 * @throws \RuntimeException
	 */
	public static function createFromUri(string $uri): self
	{
		$path  = $uri;
		$query = '';
		if (strpos($uri, '?') !== false) {
			[$path, $query] = explode('?', $uri, 2);
		}

		$parts = explode('/', $path, 3);		
		if (\count($parts) < 3) {
			throw new \RuntimeException('Incorrect input format.');
		}

		$extension = $parts[1];
		if ($extension === 'svgb' || $extension === 'pngb') {
			$extension = substr($extension, 0, -1);
			$formula   = self::decodeCompressedFormula($parts[2]);
		} else {
			$formula = rawurldecode($parts[2]);
		}
		$formula = trim($formula);

		return new static($formula, $extension, self::parseVariant($query));
	}

	public function getExtension(): string
	{
		return $this->extension;
	}

	public function getFormula(): string
	{
		return $this->formula;
	}

	public function getVariant(): string
	{
		return $this->variant;
	}

	public function isPng(): bool
	{
		return $this->extension === self::PNG;
	}

	public function isSvg(): bool
	{
		return $this->extension === self::SVG;
	}

	public function withExtension(string $extension): self
	{
		if (!self::extensionIsValid($extension)) {
			throw new \InvalidArgumentException(\sprintf('Unsupported extension "%s".', $extension));
		}
		$result            = clone $this;
		$result->extension = $extension;

		return $result;
	}

	private static function extensionIsValid(string $str): bool
	{
		return $str === self::SVG || $str === self::PNG;
	}

	private static function parseVariant(string $query): string
	{
		if ($query === '') {
			return '';
		}

		parse_str($query, $params);
		$color = $params['c'] ?? ($params['color'] ?? null);
		return self::normalizeHexColor($color) ?? '';
	}

	private static function normalizeHexColor(?string $c): ?string
	{
		if ($c === null) return null;
		$c = strtolower(trim($c));
		$c = ltrim($c, '#');

		if (preg_match('/^[0-9a-f]{3}$/', $c)) {
			return '#' . $c[0].$c[0] . $c[1].$c[1] . $c[2].$c[2];
		}
		if (preg_match('/^[0-9a-f]{6}$/', $c)) {
			return '#' . $c;
		}
		return null;
	}
	
	/**
	 * @throws \RuntimeException
	 */
	public static function decodeCompressedFormula(string $compressed): string
	{
		$base64     = strtr($compressed, '-_', '+/'); // URL-safe base64 to standard
		$compressed = base64_decode($base64);

		$result = @gzinflate($compressed);
		if ($result === false) {
			throw new \RuntimeException('Failed to decompress formula.');
		}
		return $result;
	}
}
