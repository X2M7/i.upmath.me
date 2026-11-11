import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { setTimeout as sleep } from 'node:timers/promises';
import { deflateRawSync } from 'node:zlib';

const base = new URL(process.argv[2] ?? 'http://127.0.0.1:8080');
assert(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Run against a local test container, not a public CDN');
const cacheControl = 'public, max-age=86400, s-maxage=15552000';
const strictCsp = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
const embeddedCsp = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; sandbox allow-scripts";
const compressed = (formula, extension = 'svg') => `/${extension}b/${deflateRawSync(formula).toString('base64url')}`;

async function request(path, options = {}) {
  const response = await fetch(new URL(path, base), { signal: AbortSignal.timeout(45000), ...options });
  const body = Buffer.from(await response.arrayBuffer());
  if (/^\/(?:svg|png|jpg|jpeg)b?\//.test(path)) {
    const destination = new Headers(options.headers).get('Sec-Fetch-Dest');
    const expectedCsp = ['object', 'embed', 'iframe'].includes(destination) ? embeddedCsp : strictCsp;
    assert.equal(response.headers.get('content-security-policy'), expectedCsp, `CSP for ${path} (${destination})`);
    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
    assert(response.headers.get('vary')?.toLowerCase().split(/\s*,\s*/).includes('sec-fetch-dest'), 'Vary must distinguish CSP variants');
  }
  return { status: response.status, headers: response.headers, body };
}

function checkSvg(result) {
  assert.equal(result.status, 200, result.body.toString().slice(0, 200));
  assert.match(result.headers.get('content-type'), /^image\/svg\+xml/);
  assert.equal(result.headers.get('cache-control'), cacheControl);
  assert.equal(result.headers.get('access-control-allow-origin'), '*');
  assert.match(result.body.toString(), /<svg\b/);
}

const legacyPath = compressed('cache-smoke-legacy-gzip');
for (const headers of [{}, { 'Accept-Encoding': 'identity' }]) {
  const legacy = await request(legacyPath, { headers });
  checkSvg(legacy);
  assert.equal(legacy.headers.get('last-modified'), 'Tue, 14 Nov 2023 22:13:20 GMT');
  assert.match(legacy.body.toString(), /<rect/);
}
for (const extension of ['png', 'jpg']) {
  const result = await request(compressed('cache-smoke-legacy-gzip', extension));
  assert.equal(result.status, 200);
  assert.equal(result.body.subarray(0, 2).toString('hex'), extension === 'png' ? '8950' : 'ffd8');
}
const invalidCached = await request(compressed('cache-smoke-invalid'));
assert.equal(invalidCached.status, 400);
assert.equal(invalidCached.headers.get('cache-control'), 'no-store');
assert.doesNotMatch(invalidCached.body.toString(), /Warning:|Fatal error:/);
console.log('PASS legacy gzip-only SVG, PNG/JPG conversion, cached formula errors');

const formula = `x^{${Date.now()}}+1`;
const path = compressed(formula);
checkSvg(await request(path));
checkSvg(await request(`${path}?c=eaeaea`));
await sleep(2000);
const cached = await request(path);
checkSvg(cached);
const raw = await request(`/svg/${encodeURIComponent(formula)}`);
checkSvg(raw);
assert.deepEqual(raw.body, cached.body);
const dark = await request(`${path}?c=eaeaea`);
checkSvg(dark);
assert.match(dark.body.toString(), /#eaeaea/i);
assert.notDeepEqual(dark.body, cached.body);
const identity = await request(path, { headers: { 'Accept-Encoding': 'identity' } });
checkSvg(identity);
assert.deepEqual(identity.body, cached.body);
assert.equal(identity.headers.get('content-encoding'), null);

const head = await request(path, { method: 'HEAD' });
assert.equal(head.status, 200);
assert.equal(head.headers.get('cache-control'), cacheControl);
assert.equal(head.body.length, 0);
const notModified = await request(path, { headers: { 'If-Modified-Since': cached.headers.get('last-modified') } });
assert.equal(notModified.status, 304);
assert.equal(notModified.headers.get('cache-control'), cacheControl);

// Exercise static/raw, X-Accel-Redirect, and cold-render paths, not just one location.
for (const destination of ['document', 'image', 'empty', 'object', 'embed', 'iframe', 'object-extra']) {
  for (const variantPath of [path, `/svg/${encodeURIComponent(formula)}`]) {
    const headers = { 'Sec-Fetch-Dest': destination, 'Accept-Encoding': 'gzip' };
    const variant = await request(variantPath, { headers });
    checkSvg(variant);
    assert.deepEqual(variant.body, cached.body);
    assert.match(variant.headers.get('vary'), /accept-encoding/i);
    assert.equal((await request(variantPath, { method: 'HEAD', headers })).status, 200);
    const conditional = await request(variantPath, {
      headers: { ...headers, 'If-Modified-Since': variant.headers.get('last-modified') },
    });
    assert.equal(conditional.status, 304);
    assert.equal(conditional.headers.get('cache-control'), cacheControl);
  }
  assert.equal((await request('/svgb/invalid!', { headers: { 'Sec-Fetch-Dest': destination } })).status, 400);
}
for (const destination of ['document', 'object']) {
  for (const encode of [compressed, value => `/svg/${encodeURIComponent(value)}`]) {
    checkSvg(await request(encode(`${formula}+${Date.now()}`), { headers: { 'Sec-Fetch-Dest': destination } }));
  }
}
console.log('PASS SVG CSP and Vary on raw/compressed, cached/cold, HEAD/304/error responses');

for (const extension of ['png', 'jpg']) {
  const result = await request(compressed(formula, extension));
  assert.equal(result.status, 200);
  assert.equal(result.headers.get('cache-control'), cacheControl);
  assert.match(result.headers.get('content-type'), extension === 'png' ? /^image\/png/ : /^image\/jpeg/);
  assert.equal(result.body.subarray(0, 2).toString('hex'), extension === 'png' ? '8950' : 'ffd8');
}

for (const badPath of ['/svgb/invalid!', compressed('x'.repeat(100001)), compressed('\\input{/etc/passwd}')]) {
  const result = await request(badPath);
  assert.equal(result.status, 400);
  assert.equal(result.headers.get('cache-control'), 'no-store');
  assert.doesNotMatch(result.body.toString(), /Warning:|Fatal error:/);
}
const fixturePath = formula => {
  const hash = createHash('md5').update(`${formula}\nsvg\n\ncache-v2`).digest('hex');
  return `${hash.slice(0, 2)}/${hash.slice(2, 4)}/${hash.slice(4)}.svg`;
};
for (const privatePath of [
  `/_cache/${fixturePath('cache-smoke-legacy-gzip')}.gz`,
  `/_error/${fixturePath('cache-smoke-invalid')}`,
  '/_cache/00/00/test.svg', '/_error/test.svg', '/resolve.php', '/render.php',
]) {
  assert.equal((await request(privatePath)).status, 404, privatePath);
}
assert.equal((await request(path, { method: 'OPTIONS' })).status, 204);
assert.equal((await request(path, { method: 'POST' })).status, 403);
console.log('PASS formats, color variants, headers, conditional requests, invalid input, private paths');

const coldPaths = Array.from({ length: 25 }, (_, i) => compressed(`${formula}+${i + 2}`));
let start = performance.now();
const coldResults = await Promise.all(coldPaths.map(p => request(p)));
coldResults.forEach(checkSvg);
console.log(`PASS 25 concurrent cold renders (${((performance.now() - start) / 1000).toFixed(2)}s)`);
await sleep(2000);
const stable = await request(legacyPath);
start = performance.now();
const warmResults = await Promise.all(Array.from({ length: 100 }, () => request(legacyPath)));
for (const result of warmResults) {
  checkSvg(result);
  assert.equal(result.headers.get('last-modified'), stable.headers.get('last-modified'));
  assert.deepEqual(result.body, stable.body);
}
console.log(`PASS 100 concurrent cache hits (${((performance.now() - start) / 1000).toFixed(2)}s)`);

if (process.argv[3]) {
  const urls = JSON.parse(await readFile(process.argv[3], 'utf8'));
  const paths = urls.map(url => new URL(url).pathname);
  for (const suffix of ['', '?c=eaeaea']) {
    start = performance.now();
    const results = await Promise.all(paths.map(p => request(p + suffix)));
    results.forEach(checkSvg);
    console.log(`PASS article ${paths.length} images ${suffix || 'default'} (${((performance.now() - start) / 1000).toFixed(2)}s)`);
    await sleep(2000);
  }
}
