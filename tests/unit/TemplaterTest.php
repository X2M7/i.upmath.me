<?php

namespace S2\Tex\Test\unit;

use Codeception\Test\Unit;
use S2\Tex\Templater;

class TemplaterTest extends Unit
{
	public function testDocumentIncludesGraphicxPackage(): void
	{
		$templater = new Templater(__DIR__ . '/../../tpl/');
		$formula   = $templater->run('x^2');

		$this->assertStringContainsString('\\usepackage{graphicx}', $formula->getText());
	}
}
