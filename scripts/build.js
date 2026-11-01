'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { promisify } = require('node:util');
const zlib = require('node:zlib');
const { build } = require('esbuild');

const gzip = promisify(zlib.gzip);
const root = path.resolve(__dirname, '..');

async function writeGzip(file) {
	const content = await fs.readFile(file);
	const compressed = await gzip(content, { level: 6, mtime: 0 });
	await fs.writeFile(`${file}.gz`, compressed);
}

async function main() {
	const editorOutput = path.join(root, 'www/js/scripts.min.js');
	const cssOutput = path.join(root, 'www/css/style.min.css');
	const publicScriptOutput = path.join(root, 'www/latex.js');

	await build({
		stdin: {
			contents: 'window.autosize = require("autosize"); require("./www/js/init_editor.js");',
			resolveDir: root,
			sourcefile: 'editor-entry.js',
		},
		bundle: true,
		format: 'iife',
		legalComments: 'none',
		minify: true,
		outfile: editorOutput,
		platform: 'browser',
		target: ['es2018'],
	});

	await build({
		entryPoints: [path.join(root, 'www/css/style.css')],
		legalComments: 'none',
		minify: true,
		outfile: cssOutput,
	});

	const source = await fs.readFile(path.join(root, 'src/latex.js'), 'utf8');
	const directoryName = path.basename(root);
	const host = directoryName === 'tex.s2cms.ru' ? 'i.upmath.me' : directoryName;
	await fs.writeFile(publicScriptOutput, source.replaceAll('i.upmath.me', host));

	await Promise.all([
		writeGzip(editorOutput),
		writeGzip(cssOutput),
		writeGzip(publicScriptOutput),
	]);

	const fingerprint = crypto.createHash('sha256');
	for (const file of [editorOutput, cssOutput, publicScriptOutput]) {
		fingerprint.update(await fs.readFile(file));
	}
	const value = fingerprint.digest('hex').slice(0, 16);
	await fs.writeFile(
		path.join(root, 'fingerprint.php'),
		`<?php define('FINGERPRINT', '${value}'); ?>\n`,
	);
}

main().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
