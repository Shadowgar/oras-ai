const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(__dirname + '/../assets/chat.js', 'utf8');
const nodes = [];

class FakeNode {
	constructor(tagName) {
		this.tagName = tagName.toUpperCase();
		this.children = [];
		this.attributes = {};
		this.textContent = '';
		this.className = '';
		this.href = '';
	}
	appendChild(child) {
		this.children.push(child);
		return child;
	}
	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}
}

const fakeDocument = {
	createElement(tagName) {
		const node = new FakeNode(tagName);
		nodes.push(node);
		return node;
	},
	createTextNode(text) {
		const node = new FakeNode('#text');
		node.textContent = String(text);
		return node;
	},
};

const context = {
	document: fakeDocument,
	window: {},
	URLSearchParams,
	console,
};
vm.runInNewContext(source, context, { filename: 'chat.js' });
const api = context.window.ORASAIChat;
assert(api, 'chat.js must expose its testable component API');

const normalizedSource = api.normalizeSource({ source_title: 'Guide', canonical_url: 'https://oras.org/guide/' });
assert.strictEqual(normalizedSource.source_title, 'Guide');
assert.strictEqual(normalizedSource.canonical_url, 'https://oras.org/guide/');
assert.strictEqual(api.normalizeSource({ source_title: 'Bad', canonical_url: 'javascript:alert(1)' }), null);
const statusStrings = {
	sensitive_input: 'Sensitive input blocked',
	limit: 'Request limit reached',
	unavailable: 'Unavailable',
	generic_error: 'Request failed',
	refusal: 'Out of scope',
	no_evidence: 'No evidence',
};
assert.strictEqual(api.statusMessage({ status: 'failure', error_code: 'oras_ai_sensitive_input' }, statusStrings).text, 'Sensitive input blocked');

const message = new FakeNode('div');
api.renderMessage(message, { role: 'assistant', content: '<script>alert(1)</script>' }, fakeDocument);
assert.strictEqual(message.textContent, '');
assert.strictEqual(message.children[0].children[0].textContent, 'ORAS AI Assistant');
assert.strictEqual(message.children[0].children[1].textContent, '<script>alert(1)</script>');

const sources = new FakeNode('div');
api.renderSources(sources, [
	{ source_title: 'Guide', canonical_url: 'https://oras.org/guide/' },
	{ source_title: 'Bad', canonical_url: 'javascript:alert(1)' },
], fakeDocument);
assert.strictEqual(sources.children[0].textContent, 'Sources');
assert.strictEqual(sources.children[1].children.length, 1);
assert.strictEqual(sources.children[1].children[0].children[0].textContent, 'Guide');
assert.strictEqual(sources.children[1].children[0].children[0].href, 'https://oras.org/guide/');

let sent;
const transport = api.createTransport(
	{ ajaxUrl: '/admin-ajax.php', action: 'oras_ai_conversation', nonce: 'nonce' },
	async (url, options) => {
		sent = { url, options };
		return { success: true, data: { conversation_id: 12 } };
	},
);
transport('send', { conversation_id: 12, question: 'What is ORAS?' });
setImmediate(() => {
	assert.strictEqual(sent.url, '/admin-ajax.php');
	assert(sent.options.body.includes('action=oras_ai_conversation'));
	assert(sent.options.body.includes('operation=send'));
	assert(sent.options.body.includes('conversation_id=12'));
	assert(!sent.options.body.includes('user_id'));
	assert(!sent.options.body.includes('apiKey'));
	console.log('12 frontend chat assertions passed.');
});
