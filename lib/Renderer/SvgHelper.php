<?php
namespace S2\Tex\Renderer;

class SvgHelper
{
    private const POINTS_IN_PIXEL = 0.75;
    private const TOP_SHIFT_RATIO = 0.5;

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
     * Only apply recolor when ?c=xxxxxx (or ?color=xxxxxx) exists.
     * - Root fill="currentColor"
     * - Root style="color:#xxxxxx"
     * - Replace explicit pure-black representations to currentColor
     * - Do NOT set root stroke (avoid bold glyphs)
     */
    private static function applyColorParam(string $svg): string
    {
        $color = self::normalizeHexColor($_GET['c'] ?? ($_GET['color'] ?? null));
        if ($color === null) {
            return $svg; // default output unchanged
        }

        // 1) Root svg: fill=currentColor + color=<hex>
        $svg = preg_replace_callback('/<svg\b([^>]*)>/', static function (array $m) use ($color): string {
            $attrs = $m[1];

            // Ensure default fill follows currentColor
            if (stripos($attrs, ' fill=') === false) {
                $attrs .= ' fill="currentColor"';
            }

            // Update or append style="color:..."
            if (preg_match('/\sstyle=("|\')([^"\']*)\1/i', $attrs, $sm)) {
                $quote = $sm[1];
                $style = $sm[2];

                // remove existing color:...
                $style = preg_replace('/(^|;)\s*color\s*:\s*[^;]+/i', '$1', $style);
                $style = trim($style);
                if ($style !== '' && substr($style, -1) !== ';') $style .= ';';
                $style .= 'color:' . $color . ';';

                $attrs = preg_replace('/\sstyle=("|\')([^"\']*)\1/i', ' style=' . $quote . $style . $quote, $attrs, 1);
            } else {
                $attrs .= ' style="color:' . $color . ';"';
            }

            return '<svg' . $attrs . '>';
        }, $svg, 1);

        // 2) Replace pure black in attributes -> currentColor
        // Support BOTH single and double quotes.
        $svg = preg_replace(
            '/\b(fill|stroke|stop-color)\s*=\s*(["\'])(#000000|#000|black)\2/i',
            '$1=$2currentColor$2',
            $svg
        );

        // rgb(0,0,0) or rgb(0%,0%,0%) or rgb(0.0%,0.0%,0.0%)
        $svg = preg_replace(
            '/\b(fill|stroke|stop-color)\s*=\s*(["\'])rgb\(\s*0(?:\.0+)?%?\s*,\s*0(?:\.0+)?%?\s*,\s*0(?:\.0+)?%?\s*\)\2/i',
            '$1=$2currentColor$2',
            $svg
        );
        // rgb(0 0 0) space-separated variant
        $svg = preg_replace(
            '/\b(fill|stroke|stop-color)\s*=\s*(["\'])rgb\(\s*0(?:\.0+)?%?\s+0(?:\.0+)?%?\s+0(?:\.0+)?%?\s*\)\2/i',
            '$1=$2currentColor$2',
            $svg
        );
        // rgba(0,0,0,1)
        $svg = preg_replace(
            '/\b(fill|stroke|stop-color)\s*=\s*(["\'])rgba\(\s*0(?:\.0+)?\s*,\s*0(?:\.0+)?\s*,\s*0(?:\.0+)?\s*,\s*1(?:\.0+)?\s*\)\2/i',
            '$1=$2currentColor$2',
            $svg
        );

        // 3) Replace pure black in inline style -> currentColor
        $svg = preg_replace('/\b(fill|stroke|stop-color)\s*:\s*#000000\b/i', '$1:currentColor', $svg);
        $svg = preg_replace('/\b(fill|stroke|stop-color)\s*:\s*#000\b/i', '$1:currentColor', $svg);
        $svg = preg_replace('/\b(fill|stroke|stop-color)\s*:\s*black\b/i', '$1:currentColor', $svg);

        $svg = preg_replace('/\b(fill|stroke|stop-color)\s*:\s*rgb\(\s*0(?:\.0+)?%?\s*,\s*0(?:\.0+)?%?\s*,\s*0(?:\.0+)?%?\s*\)\b/i', '$1:currentColor', $svg);
        $svg = preg_replace('/\b(fill|stroke|stop-color)\s*:\s*rgb\(\s*0(?:\.0+)?%?\s+0(?:\.0+)?%?\s+0(?:\.0+)?%?\s*\)\b/i', '$1:currentColor', $svg);
        $svg = preg_replace('/\b(fill|stroke|stop-color)\s*:\s*rgba\(\s*0(?:\.0+)?\s*,\s*0(?:\.0+)?\s*,\s*0(?:\.0+)?\s*,\s*1(?:\.0+)?\s*\)\b/i', '$1:currentColor', $svg);

        return $svg;
    }

    public static function processSvgContent(string $svg, bool $useBaseline): string
    {
        $startPattern = '#<!--start (-?[\d.]+) (-?[\d.]+) -->#';
        if (!preg_match($startPattern, $svg, $matchBaseline)) {
            return self::applyColorParam($svg);
        }

        $viewBoxPattern = '#viewBox=["\'](-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)["\']#';
        if (!preg_match($viewBoxPattern, $svg, $matchViewBox)) {
            return self::applyColorParam($svg);
        }

        [, $userStartX, $userStartY, $userWidth, $userHeight] = $matchViewBox;
        if ($userWidth < 0.000001 || $userHeight < 0.000001) {
            return self::applyColorParam($svg);
        }

        $userBaselineY = $matchBaseline[2];
        $userFromTopToBaseline = max(0, $userBaselineY - $userStartY);
        $userFromBottomToBaseline = $useBaseline
            ? max($userHeight - $userFromTopToBaseline, 0)
            : $userHeight * 0.5;

        $multiplier = OUTER_SCALE / self::POINTS_IN_PIXEL;

        $viewportFromBottomToBaseline = $multiplier * $userFromBottomToBaseline;
        $viewportHeight               = $multiplier * $userHeight;
        $viewportWidth                = $multiplier * $userWidth;

        $extendedViewportHeight = ceil($viewportHeight);
        $extendedViewportWidth  = ceil($viewportWidth);
        $extendedViewportFromBottomToBaseline =
            $viewportFromBottomToBaseline
            + (1 - self::TOP_SHIFT_RATIO) * ($extendedViewportHeight - $viewportHeight);

        $extendedUserHeight = $userHeight * $extendedViewportHeight / $viewportHeight;
        $extendedUserWidth  = $userWidth * $extendedViewportWidth / $viewportWidth;

        $svg = preg_replace(
            '#<svg\b[^>]*>#',
            sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%s" height="%s" viewBox="%s %s %s %s">',
                round($extendedViewportWidth, 6),
                round($extendedViewportHeight, 6),
                $userStartX,
                round($userStartY - self::TOP_SHIFT_RATIO * ($extendedUserHeight - $userHeight), 6),
                round($extendedUserWidth, 6),
                round($extendedUserHeight, 6)
            ),
            $svg,
            1
        );

        $script = sprintf(
            '<script type="text/ecmascript">if(window.parent.postMessage)window.parent.postMessage("%s|%s|%s|"+window.location,"*");</script>',
            round($extendedViewportFromBottomToBaseline * self::POINTS_IN_PIXEL, 5),
            round($extendedViewportWidth * self::POINTS_IN_PIXEL, 5),
            round($extendedViewportHeight * self::POINTS_IN_PIXEL, 5)
        );

        $svg = str_replace('</svg>', $script . "\n" . '</svg>', $svg);

        return self::applyColorParam($svg);
    }
}
