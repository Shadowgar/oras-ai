const nodeAssert = require('assert');
let assertionCount = 0;
function assert(...args) { assertionCount++; return nodeAssert(...args); }
assert.strictEqual = (...args) => { assertionCount++; return nodeAssert.strictEqual(...args); };
assert.deepStrictEqual = (...args) => { assertionCount++; return nodeAssert.deepStrictEqual(...args); };
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
		if (this.selectors[selector]) { return this.selectors[selector]; }
		if (selector.startsWith('.')) {
			const className = selector.slice(1);
			for (const child of this.children) {
				if (child.className.split(' ').includes(className)) { return child; }
				const nested = child.querySelector(selector);
				if (nested) { return nested; }
			}
		}
		return null;
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
assert.strictEqual(typeof api.renderEscalation, 'function', 'Task 4 support card renderer is missing');
function allText(node) { return node.textContent + node.children.map(allText).join(' '); }
const supportStrings = {
	support_heading: 'Support ticket preview', support_subject: 'Subject', support_summary: 'Summary',
	support_question: 'Original question', support_destination: 'Destination', support_category: 'Category',
	support_disclosure: 'Chat text is deleted after 30 days; support tickets follow separate retention.',
	support_create: 'Create Support Ticket', support_cancel: 'Cancel', support_creating: 'Creating ticket',
	support_created: 'Ticket sent', support_failed: 'Ticket could not be created',
	support_uncertain: 'Cannot determine whether ticket was created; will not retry automatically',
	support_cancelled: 'Request cancelled', support_expired: 'Request expired',
	support_contact: 'Contact ORAS Support', support_ticket_ref: 'Ticket',
};
const pendingEscalation = {
	status: 'awaiting_confirmation', token: 'opaque-token',
	preview: { category: 'Feedback', subject: '<b>Subject</b>', summary: 'Concise summary',
		original_question: 'Original member question', destination: 'ORAS Support', mailbox_id: 999 },
};
const supportCard = fakeDocument.createElement('article');
const supportActions = [];
api.renderEscalation(supportCard, pendingEscalation, fakeDocument, supportStrings, 'https://example.test/contact/', (action) => supportActions.push(action));
assert(allText(supportCard).includes('Concise summary') && allText(supportCard).includes('Original member question'));
assert(allText(supportCard).includes('ORAS Support') && allText(supportCard).includes('separate retention'));
assert(!allText(supportCard).includes('999') && !allText(supportCard).includes('mailbox_id'));
assert.strictEqual(supportCard.querySelector('.oras-ai-chat__escalation-actions').children.length, 2);
assert.strictEqual(supportCard.querySelector('.oras-ai-chat__escalation-actions').children[0].getAttribute('type'), 'button');
supportCard.querySelector('.oras-ai-chat__escalation-actions').children[0].dispatch('click');
supportCard.querySelector('.oras-ai-chat__escalation-actions').children[1].dispatch('click');
assert.deepStrictEqual(supportActions, ['confirm_escalation', 'cancel_escalation']);
api.renderEscalation(supportCard, { status: 'created', ticket_id: 123 }, fakeDocument, supportStrings, 'https://example.test/contact/');
assert(allText(supportCard).includes('Ticket #123') && !supportCard.querySelector('.oras-ai-chat__escalation-actions'));
api.renderEscalation(supportCard, { status: 'uncertain' }, fakeDocument, supportStrings, 'https://example.test/contact/');
assert(allText(supportCard).includes('will not retry automatically') && allText(supportCard).includes('Contact ORAS Support'));
assert(!supportCard.querySelector('.oras-ai-chat__escalation-actions'));
api.renderEscalation(supportCard, { status: 'unavailable' }, fakeDocument, supportStrings, 'https://example.test/contact/');
assert(allText(supportCard).includes('Contact ORAS Support') && !supportCard.querySelector('.oras-ai-chat__escalation-actions'), 'Summary failure must offer manual contact without a Create button');
for (const state of ['failed', 'cancelled', 'expired']) {
	api.renderEscalation(supportCard, { status: state }, fakeDocument, supportStrings, 'https://example.test/contact/');
	assert(!supportCard.querySelector('.oras-ai-chat__escalation-actions'), state + ' retained action controls');
}
api.renderEscalation(supportCard, { status: 'creating' }, fakeDocument, supportStrings, 'https://example.test/contact/');
assert.strictEqual(supportCard.getAttribute('aria-busy'), 'true');

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
	await transport('confirm_escalation', { token: 'opaque-token' });
	assert(sent.options.body.includes('nonce=nonce') && sent.options.body.includes('operation=confirm_escalation'));
	assert(sent.options.body.includes('token=opaque-token') && !sent.options.body.includes('mailbox_id') && !sent.options.body.includes('customer_id'));

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
	assert(styles.includes('.oras-ai-chat[hidden]') && styles.includes('display: none'), 'Hidden panel must not intercept launcher clicks');

	const supportRoot = fakeDocument.createElement('section');
	const supportMessages = fakeDocument.createElement('div');
	const supportStatus = fakeDocument.createElement('div');
	const supportForm = fakeDocument.createElement('form');
	const supportInput = fakeDocument.createElement('textarea');
	const supportSend = fakeDocument.createElement('button');
	const supportNew = fakeDocument.createElement('button');
	supportRoot.setAttribute('data-oras-ai-chat-mode', 'page');
	supportRoot.selectors = {
		'[data-oras-ai-chat-messages]': supportMessages,
		'[data-oras-ai-chat-status]': supportStatus,
		'[data-oras-ai-chat-form]': supportForm,
		'[data-oras-ai-chat-input]': supportInput,
		'[data-oras-ai-chat-send]': supportSend,
		'[data-oras-ai-chat-new]': supportNew,
	};
	let restoreData = { conversation_id: 42, messages: [], escalations: [pendingEscalation] };
	let resolveConfirm;
	const supportOperations = [];
	const supportController = api.createController(supportRoot, {
		strings: Object.assign({}, supportStrings, { loading: 'Loading', empty: 'Empty', support_ready: 'Review preview' }),
		supportContactUrl: 'https://example.test/contact/',
	}, {
		transport: (operation, fields = {}) => {
			supportOperations.push({ operation, fields });
			if (operation === 'current') { return Promise.resolve(restoreData); }
			if (operation === 'confirm_escalation') { return new Promise((resolve) => { resolveConfirm = resolve; }); }
			if (operation === 'cancel_escalation') { return Promise.resolve({ status: 'cancelled' }); }
			if (operation === 'send') {
				return Promise.resolve({
					conversation_id: 42,
					member_message: { role: 'member', content: fields.question },
					assistant_message: { role: 'assistant', content: 'I can offer support.' },
					result: { status: 'no_evidence', escalation: pendingEscalation },
				});
			}
			return Promise.resolve({ status: 'uncertain' });
		},
	});
	await new Promise((resolve) => setImmediate(resolve));
	assert.strictEqual(supportMessages.children[0].getAttribute('data-escalation-state'), 'awaiting_confirmation');
	const createButton = supportMessages.children[0].querySelector('.oras-ai-chat__escalation-actions').children[0];
	const confirming = createButton.dispatch('click');
	createButton.dispatch('click');
	assert.strictEqual(supportOperations.filter((item) => item.operation === 'confirm_escalation').length, 1);
	assert.strictEqual(JSON.stringify(supportOperations[1].fields), JSON.stringify({ token: 'opaque-token' }));
	assert.strictEqual(supportMessages.children[0].getAttribute('aria-busy'), 'true');
	assert(!supportMessages.children[0].querySelector('.oras-ai-chat__escalation-actions'));
	resolveConfirm({ status: 'created', ticket_id: 123 });
	await confirming;
	assert.strictEqual(supportMessages.children[0].getAttribute('data-escalation-state'), 'created');
	assert.strictEqual(fakeDocument.activeElement, supportMessages.children[0]);
	restoreData = { conversation_id: 42, messages: [], escalations: [{ status: 'created', ticket_id: 123 }] };
	await supportController.loadCurrent();
	assert(allText(supportMessages).includes('Ticket #123'));
	assert.strictEqual(supportOperations.filter((item) => item.operation === 'confirm_escalation').length, 1);
	restoreData = { conversation_id: 42, messages: [], escalations: [pendingEscalation] };
	await supportController.loadCurrent();
	await supportMessages.children[0].querySelector('.oras-ai-chat__escalation-actions').children[1].dispatch('click');
	assert.strictEqual(supportOperations.filter((item) => item.operation === 'cancel_escalation').length, 1);
	assert.strictEqual(supportMessages.children[0].getAttribute('data-escalation-state'), 'cancelled');
	assert.strictEqual(supportOperations.filter((item) => item.operation === 'confirm_escalation').length, 1);
	restoreData = { conversation_id: 42, messages: [], escalations: [{ status: 'uncertain' }, { status: 'expired' }] };
	await supportController.loadCurrent();
	assert(allText(supportMessages).includes('will not retry automatically'));
	assert(allText(supportMessages).includes('Request expired'));
	assert(!supportMessages.children[0].querySelector('.oras-ai-chat__escalation-actions'));
	supportInput.value = 'I have a suggestion for ORAS';
	await supportController.submit();
	assert.strictEqual(supportOperations.filter((item) => item.operation === 'send').length, 1);
	assert.strictEqual(supportMessages.children[supportMessages.children.length - 1].getAttribute('data-escalation-state'), 'awaiting_confirmation');
	assert.strictEqual(fakeDocument.activeElement, supportMessages.children[supportMessages.children.length - 1]);

	console.log(assertionCount + ' frontend chat assertions passed.');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
