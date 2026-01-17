<?php
/**
 * 中文界面
 */
return [
    'title'                 => 'Web 版 LaTeX 公式渲染',
    'meta-keywords'         => 'LaTeX, 公式, SVG, 图片, 渲染, 将LaTeX转换为图片',
    'meta-description'      => '将 LaTeX 公式转换为可用于网页发布的 SVG 图片。',
    'header'                => 'Web 版 LaTeX 公式渲染',
    'equation editor'       => '在线编辑器',
    'formula in latex'      => 'LaTeX 表达式',
    'image URL'             => '图片 URL：',
    'examples'              => '示例',
    'examples info'         => '这里提供一些 LaTeX 源码示例及渲染结果。',
    'add to editor'         => '添加到编辑器',
    'link-s2'               => '&larr; S2 CMS',
    'link-faq'              => '常见问题',
    'link-install'          => '嵌入使用',
    'page-editor'           => 'Upmath：Markdown & LaTeX',

    'samples'               => [
        'integrals'  => '积分、根式与上下界',
        'limits'     => '极限与求和',
        'chains'     => '连分数',
        'matrices'   => '矩阵',
        'align'      => '多行公式',
        'picture'    => '<code>picture</code> 环境',
        'xy-pics'    => '<code>xy-pic</code> 图示',
        'tikz'       => 'TikZ 图形',
        'tikz-plots' => 'TikZ 绘图',
    ],

    'faq section'           => '
<h2>常见问题（FAQ）</h2>
<div class="info-text">
<div class="question">
<h3>什么是 LaTeX？</h3>
<p>
LaTeX 是一种排版系统，常用于生成复杂文档，尤其在数学、物理等学科中被广泛使用。
更多介绍可参考 <a href="http://en.wikipedia.org/wiki/LaTeX">Wikipedia</a>。
</p>
</div>
<div class="question">
<h3>这个网站具体做什么？</h3>
<p>
本网站用于将数学/LaTeX 表达式转换为适合网页使用的图片（SVG/PNG）。
你无需使用绘图软件手工制作并上传图片，可以直接生成链接用于论坛、博客或聊天分享。
</p>
</div>
<div class="question">
<h3>可以免费使用吗？</h3>
<p>
可以。在服务负载合理的前提下可免费使用；如果请求量影响到其他用户，访问可能会被限制。
</p>
</div>
<div class="question">
<h3>服务稳定可靠吗？</h3>
<p>总体较稳定。可查看由第三方服务 <a href="https://stats.uptimerobot.com/YVrX5ik0A5">UptimeRobot</a> 收集的可用性数据。</p>
</div>
<div class="question">
<h3>是否保证服务一直可用？</h3>
<p>不作保证。但我会在自己的站点中持续使用，并且暂无关闭计划。</p>
</div>
<div class="question">
<h3>公式是如何被转换成图片的？</h3>
<p>
服务器安装了 <a href="https://en.wikipedia.org/wiki/TeX_Live">TeX Live</a>，
并配合现代 Web 技术完成渲染与输出。
</p>
</div>
<div class="question">
<h3>公式中的中文/其他非拉丁字符显示不出来怎么办？</h3>
<p>可以尝试使用 <code>\\text{...}</code>。例如 <code>Q_\\text{熔化}&gt;0</code>。</p>
</div>
<div class="question">
<h3>如何引入更多 LaTeX 包？我需要化学式/乐谱等支持。</h3>
<p>默认仅包含最小可用的 LaTeX 包集合。如需特定包支持，请联系维护者并说明用途。</p>
</div>
<div class="question">
<h3>必须在这个编辑器里输入所有公式吗？</h3>
<p>
不一定。少量公式可以直接在在线编辑器中输入；
更长的内容建议使用支持 LaTeX 与 Markdown 的 <a href="https://upmath.me/">Upmath 编辑器</a>。
另外也可以在网页 HTML 源码中直接写 LaTeX，并通过脚本自动转换。
</p>
</div>
</div>
',

    'embedding section 1'   => '
<h2>嵌入到网站</h2>
<div class="info-text">
<p>
你可以在 HTML 中直接书写 LaTeX 表达式。
将表达式用美元符号包裹：<code><span>$$</span>...$$</code>，并引入以下脚本：
</p>
',

    'embedding section 2'   => '
<p>HTML 代码示例：</p>
',

    'embedding section 2.1' => '
<p>渲染结果：</p>
',

    'embedding section 3'   => '
<div class="question">
<p>在现代浏览器中，该脚本会加载 SVG 矢量图并对齐公式基线，使其与周围文字排版更自然：</p>
<p align="center"><img src="/i/baseline_en.png" alt="" width="400" height="230" class="screenshot" style="max-width: 90vw; max-height: 51.75vw;" /></p>
</div>
<p>
本服务也用于我的 <a href="https://susy.page/">理论物理博客</a>。
</p>
</div>
',

    'copyright section'     => <<<TEXT
&copy; 2014&ndash;2026 <a href="https://parpalak.com/">Roman Parpalak</a>.
<script>var mailto="roman%"+"40parpalak.com";document.write('Drop&nbsp;me&nbsp;a&nbsp;line: <a href="mailto:'+unescape(mailto)+'">' + unescape(mailto) + '</a>.');</script>
TEXT
,
];
