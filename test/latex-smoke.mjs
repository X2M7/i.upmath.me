import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { setImmediate } from 'node:timers/promises';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { inflateRawSync } from 'node:zlib';

const source = await readFile(new URL('../www/latex.js', import.meta.url), 'utf8');

class Element {
  constructor(tagName) {
    this.tagName = this.nodeName = tagName.toUpperCase();
    this.nodeType = 1;
    this.childNodes = [];
    this.style = {};
    this.attributes = {};
  }

  get firstChild() { return this.childNodes[0] ?? null; }
  setAttribute(name, value) { this.attributes[name] = value; }
  appendChild(node) { this.insertBefore(node, null); }
  insertBefore(node, reference) {
    const index = reference ? this.childNodes.indexOf(reference) : this.childNodes.length;
    this.childNodes.splice(index, 0, node);
    this.linkSiblings();
  }
  removeChild(node) {
    this.childNodes.splice(this.childNodes.indexOf(node), 1);
    this.linkSiblings();
  }
  linkSiblings() {
    this.childNodes.forEach((node, index) => { node.nextSibling = this.childNodes[index + 1] ?? null; });
  }
}

function eventTarget() {
  const listeners = new Map();
  return {
    addEventListener(name, callback) { listeners.set(name, callback); },
    removeEventListener(name) { listeners.delete(name); },
    dispatch(name) { listeners.get(name)?.({ type: name }); },
  };
}

function loadRenderer({ readyState, scriptSrc, formula = 'x^2', compression = false, fetchOk = true }) {
  const body = new Element('div');
  const requests = [];
  let resolveRequest;
  const firstRequest = new Promise(resolve => { resolveRequest = resolve; });
  const document = {
    ...eventTarget(),
    readyState,
    currentScript: scriptSrc ? { src: scriptSrc } : null,
    documentElement: new Element('html'),
    body,
    implementation: { hasFeature: () => true },
    createTextNode: nodeValue => ({ nodeName: '#text', nodeType: 3, nodeValue }),
    createElement(tag) {
      if (tag === 'a') {
        return { set href(value) { this.host = new URL(value, 'https://article.example/').host; } };
      }
      return new Element(tag);
    },
  };
  body.appendChild(document.createTextNode(`before $$${formula}$$ after`));
  const window = eventTarget();
  runInNewContext(source, {
    document,
    window,
    location: { protocol: 'https:' },
    ...(compression ? { Blob, Response, CompressionStream, btoa } : {}),
    fetch(path) {
      requests.push(path);
      resolveRequest();
      return Promise.resolve({
        ok: fetchOk,
        text: async () => '<svg><script>parent.postMessage("1|12|8", "*")</script></svg>',
      });
    },
  });
  return { body, document, window, requests, firstRequest };
}

for (const readyState of ['loading', 'complete']) {
  for (const compression of [false, true]) {
    test(`renders when ${readyState}, compression ${compression}`, { timeout: 5000 }, async () => {
      const formula = compression ? 'x+'.repeat(200) + '1' : 'x^2';
      const renderer = loadRenderer({
        readyState,
        compression,
        formula,
        scriptSrc: 'https://renderer.example:8443/latex.js?0123456789abcdef',
      });
      assert.equal(typeof renderer.window.S2Latex.processTree, 'function');
      if (readyState === 'loading') {
        assert.equal(renderer.body.childNodes.length, 1);
        assert.equal(renderer.requests.length, 0);
        renderer.document.dispatch('DOMContentLoaded');
        renderer.document.readyState = 'complete';
        renderer.document.dispatch('readystatechange');
        renderer.window.dispatch('load');
      }
      await renderer.firstRequest;
      await setImmediate();

      assert.equal(renderer.requests.length, 1);
      assert.equal(renderer.body.childNodes.length, 3);
      const [before, image, after] = renderer.body.childNodes;
      assert.equal(before.nodeValue, 'before ');
      assert.equal(after.nodeValue, ' after');
      assert.equal(image.tagName, 'IMG');
      assert.equal(image.attributes.alt, formula);
      assert.equal(image.attributes.src, renderer.requests[0]);
      const url = new URL(image.attributes.src, 'https://article.example/');
      assert.equal(url.host, 'renderer.example:8443');
      if (compression) {
        assert.match(url.pathname, /^\/svgb\//);
        assert.equal(inflateRawSync(Buffer.from(url.pathname.slice('/svgb/'.length), 'base64url')).toString(), formula);
      } else {
        assert.equal(url.pathname, '/svg/x%5E2');
      }
      assert.equal(image.style.opacity, '1');
      assert.equal(image.style.width, 'calc(var(--latex-zoom, 1)*12pt)');
      assert.equal(image.style.height, 'calc(var(--latex-zoom, 1)*8pt)');
      assert.equal(image.style.verticalAlign, 'calc(var(--latex-zoom, 1)*-1pt)');
    });
  }
}

test('uses the configured fallback host and reveals images after a failed metadata fetch', { timeout: 5000 }, async () => {
  const renderer = loadRenderer({ readyState: 'complete', fetchOk: false });
  await renderer.firstRequest;
  await setImmediate();
  assert.deepEqual(renderer.requests, ['//tex.xumin.net/svg/x%5E2']);
  assert.equal(renderer.body.childNodes[1].style.opacity, '1');
});
