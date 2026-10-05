'use strict';
// Small DOM seam for the released renderEscalation function; no browser/network.
const fs = require('fs');
const vm = require('vm');
class Node {
	constructor(tag) { this.tag = tag; this.children = []; this.textContent = ''; this.listeners = {}; this.attributes = {}; }
	appendChild(child) { this.children.push(child); return child; }
	replaceChildren() { this.children = []; }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	addEventListener(name, callback) { this.listeners[name] = callback; }
}
const document = { readyState: 'loading', addEventListener() {}, createElement(tag) { return new Node(tag); } };
const context = { window: {}, document, URLSearchParams };
vm.runInNewContext(fs.readFileSync(__dirname + '/../../assets/chat.js', 'utf8'), context, { filename: 'chat.js' });
const member = JSON.parse(fs.readFileSync(0, 'utf8'));
const card = new Node('article');
const actions = [];
context.window.ORASAIChat.renderEscalation(card, member, document, {}, 'https://oras.org/contact/', (action) => actions.push(action));
const flatten = (node) => [node, ...node.children.flatMap(flatten)];
const nodes = flatten(card);
const beforeClick = actions.length;
const buttons = nodes.filter((node) => node.tag === 'button');
if (buttons[0]) { buttons[0].listeners.click(); }
process.stdout.write(JSON.stringify({ text: nodes.map((node) => node.textContent).filter(Boolean).join(' '), buttons: buttons.map((node) => node.textContent), actions_before_click: beforeClick, actions_after_explicit_click: actions, state: card.attributes['data-escalation-state'] }));
