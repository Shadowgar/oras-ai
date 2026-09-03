const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(__dirname + '/../assets/chat.js', 'utf8');
const styles = fs.readFileSync(__dirname + '/../assets/chat.css', 'utf8');
const nodes = [];

class FakeNode {
	constructor(tagName) {
		this.tagName = tagName.toUpperCase();
		this.children = [];
		this.attributes = {};
		this.textContent = '';
		this.className = '';
		this.href = '';
		this.value = '';
		this.hidden = false;
		this.disabled = false;
		this.listeners = {};
		this.selectors = {};
		this.ownerDocument = null;
	}
	appendChild(child) {
		child.parentElement = this;
		this.children.push(child);
		return child;
	}
	replaceChildren(...children) {
		this.children = [];
		children.forEach((child) => this.appendChild(child));
	}
	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}
	getAttribute(name) {
		return this.attributes[name] || null;
	}
	removeAttribute(name) {
		delete this.attributes[name];
	}
	querySelector(selector) {
		return this.selectors[selector] || null;
	}
	addEventListener(type, listener) {
		this.listeners[type] = listener;
	}
	dispatch(type, event = {}) {
		if (this.listeners[type]) {
			return this.listeners[type](event);
		}
	}
	focus() {
		if (this.ownerDocument) {
			this.ownerDocument.activeElement = this;
		}
	}
}

const fakeDocument = {
	activeElement: null,
	createElement(tagName) {
		const node = new FakeNode(tagName);
		node.ownerDocument = this;
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
assert.deepStrictEqual([
	api.statusMessage({ status: 'success' }, statusStrings).text,
	api.statusMessage({ status: 'refusal' }, statusStrings).text,
	api.statusMessage({ status: 'no_evidence' }, statusStrings).text,
	api.statusMessage({ status: 'failure', error_code: 'daily_quota' }, statusStrings).text,
	api.statusMessage({ status: 'failure', error_code: 'provider_unavailable' }, statusStrings).text,
	api.statusMessage({ status: 'failure', error_code: 'unexpected' }, statusStrings).text,
], ['', 'Out of scope', 'No evidence', 'Request limit reached', 'Unavailable', 'Request failed']);

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
const emptySources = new FakeNode('div');
assert.strictEqual(api.renderSources(emptySources, [], fakeDocument), false);

let sent;
const transport = api.createTransport(
	{ ajaxUrl: '/admin-ajax.php', action: 'oras_ai_conversation', nonce: 'nonce' },
	async (url, options) => {
		sent = { url, options };
		return { success: true, data: { conversation_id: 12 } };
	},
);

(async () => {
	await transport('send', { conversation_id: 12, question: 'What is ORAS?' });
	assert.strictEqual(sent.url, '/admin-ajax.php');
	assert(sent.options.body.includes('action=oras_ai_conversation'));
	assert(sent.options.body.includes('operation=send'));
	assert(sent.options.body.includes('conversation_id=12'));
	assert(!sent.options.body.includes('user_id'));
	assert(!sent.options.body.includes('apiKey'));

	const root = fakeDocument.createElement('section');
	const messages = fakeDocument.createElement('div');
	const status = fakeDocument.createElement('div');
	const form = fakeDocument.createElement('form');
	const input = fakeDocument.createElement('textarea');
	const send = fakeDocument.createElement('button');
	const newChat = fakeDocument.createElement('button');
	const close = fakeDocument.createElement('button');
	const launcher = fakeDocument.createElement('button');
	root.hidden = true;
	root.setAttribute('data-oras-ai-chat-mode', 'panel');
	root.selectors = {
		'[data-oras-ai-chat-messages]': messages,
		'[data-oras-ai-chat-status]': status,
		'[data-oras-ai-chat-form]': form,
		'[data-oras-ai-chat-input]': input,
		'[data-oras-ai-chat-send]': send,
		'[data-oras-ai-chat-new]': newChat,
		'[data-oras-ai-chat-close]': close,
	};
	const operations = [];
	const controller = api.createController(root, {
		strings: Object.assign({}, statusStrings, {
			loading: 'Loading',
			thinking: 'Thinking',
			empty: 'Empty',
			loaded: 'Loaded',
			new_chat: 'New conversation started',
		}),
	}, {
		launcher,
		transport: async (operation, fields = {}) => {
			operations.push({ operation, fields });
			if (operation === 'current') {
				return { conversation_id: 7, messages: [{ role: 'assistant', content: 'Restored answer' }] };
			}
			if (operation === 'new_chat') {
				return { conversation_id: 8, messages: [] };
			}
			return {
				conversation_id: 8,
				member_message: { role: 'member', content: fields.question },
				assistant_message: { role: 'assistant', content: 'Shared answer', sources: [] },
				result: { status: 'success' },
			};
		},
	});
	await new Promise((resolve) => setImmediate(resolve));
	assert.strictEqual(operations[0].operation, 'current');
	assert.strictEqual(controller.state().conversationId, 7);
	controller.open();
	assert(!root.hidden && launcher.getAttribute('aria-expanded') === 'true' && fakeDocument.activeElement === input);
	root.dispatch('keydown', { key: 'Escape' });
	assert(root.hidden && launcher.getAttribute('aria-expanded') === 'false' && fakeDocument.activeElement === launcher);
	await controller.newChat();
	assert.strictEqual(operations[1].operation, 'new_chat');
	assert.strictEqual(controller.state().conversationId, 8);
	assert.strictEqual(status.textContent, 'New conversation started');
	input.value = 'What is Mars?';
	const firstSend = controller.submit();
	const secondSend = controller.submit();
	await Promise.all([firstSend, secondSend]);
	assert.strictEqual(operations.filter((item) => item.operation === 'send').length, 1);
	assert.strictEqual(JSON.stringify(operations[2].fields), JSON.stringify({ conversation_id: 8, question: 'What is Mars?' }));
	assert.strictEqual(messages.children.length, 2);
	assert.strictEqual(status.textContent, '');
	assert(styles.includes('@media (max-width: 600px)') && styles.includes('.oras-ai-chat--panel') && styles.includes('max-height: none'));

	console.log('26 frontend chat assertions passed.');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
