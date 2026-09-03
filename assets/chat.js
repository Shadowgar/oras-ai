(function (window, document) {
	'use strict';

	function normalizeSource(source) {
		if (!source || typeof source !== 'object') {
			return null;
		}

		var title = typeof source.source_title === 'string'
			? source.source_title
			: (typeof source.title === 'string' ? source.title : '');
		var url = typeof source.canonical_url === 'string' ? source.canonical_url : '';
		if (!title.trim() || !/^https?:\/\/[^\s]+$/i.test(url)) {
			return null;
		}

		return {
			source_title: title.trim(),
			canonical_url: url
		};
	}

	function clearElement(element) {
		if (typeof element.replaceChildren === 'function') {
			element.replaceChildren();
			return;
		}
		while (element.firstChild) {
			element.removeChild(element.firstChild);
		}
		element.textContent = '';
	}

	function renderSources(container, sources, documentRef) {
		clearElement(container);
		var safeSources = (Array.isArray(sources) ? sources : []).map(normalizeSource).filter(Boolean);
		if (!safeSources.length) {
			return false;
		}

		var heading = documentRef.createElement('strong');
		heading.className = 'oras-ai-chat__sources-title';
		heading.textContent = 'Sources';
		container.appendChild(heading);
		var list = documentRef.createElement('ul');
		safeSources.forEach(function (source) {
			var item = documentRef.createElement('li');
			var link = documentRef.createElement('a');
			link.href = source.canonical_url;
			link.textContent = source.source_title;
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			item.appendChild(link);
			list.appendChild(item);
		});
		container.appendChild(list);
		return true;
	}

	function renderMessage(container, message, documentRef) {
		var role = message && message.role === 'member' ? 'member' : 'assistant';
		var wrapper = documentRef.createElement('article');
		wrapper.className = 'oras-ai-chat__message oras-ai-chat__message--' + role;
		wrapper.setAttribute('data-role', role);

		var label = documentRef.createElement('strong');
		label.className = 'oras-ai-chat__message-label';
		label.textContent = role === 'member' ? 'You' : 'ORAS AI Assistant';
		wrapper.appendChild(label);

		var text = documentRef.createElement('div');
		text.className = 'oras-ai-chat__message-text';
		text.textContent = message && typeof message.content === 'string' ? message.content : '';
		wrapper.appendChild(text);

		if (role === 'assistant' && message && Array.isArray(message.sources)) {
			var sourceContainer = documentRef.createElement('div');
			sourceContainer.className = 'oras-ai-chat__sources';
			if (renderSources(sourceContainer, message.sources, documentRef)) {
				wrapper.appendChild(sourceContainer);
			}
		}

		container.appendChild(wrapper);
		return wrapper;
	}

	function createTransport(config, requestImpl) {
		requestImpl = requestImpl || window.fetch.bind(window);
		return function (operation, fields) {
			var body = new URLSearchParams();
			body.set('action', config.action);
			body.set('nonce', config.nonce);
			body.set('operation', operation);
			Object.keys(fields || {}).forEach(function (key) {
				if (fields[key] !== undefined && fields[key] !== null) {
					body.set(key, String(fields[key]));
				}
			});

			return Promise.resolve(requestImpl(config.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})).then(function (response) {
				return response && typeof response.json === 'function' ? response.json() : response;
			}).then(function (payload) {
				if (!payload || !payload.success) {
					var data = payload && payload.data ? payload.data : {};
					var error = new Error(data.message || 'ORAS AI request failed.');
					error.code = data.code || 'oras_ai_request_failed';
					throw error;
				}
				return payload.data || {};
			});
		};
	}

	function statusMessage(result, strings) {
		var code = result && result.error_code ? result.error_code : '';
		if (code.indexOf('oras_ai_') === 0) {
			code = code.slice(8);
		}
		if (code === 'sensitive_input') {
			return { text: strings.sensitive_input, kind: 'error' };
		}
		if (code === 'daily_quota' || code === 'monthly_quota' || code === 'burst_limit' || code === 'site_hard_stop') {
			return { text: strings.limit, kind: 'error' };
		}
		if (code === 'provider_unavailable' || code === 'kill_switch') {
			return { text: strings.unavailable, kind: 'error' };
		}
		if (result && result.status === 'refusal') {
			return { text: strings.refusal, kind: 'notice' };
		}
		if (result && result.status === 'no_evidence') {
			return { text: strings.no_evidence, kind: 'notice' };
		}
		if (result && result.status === 'failure') {
			return { text: strings.generic_error, kind: 'error' };
		}
		return { text: '', kind: '' };
	}

	function createController(root, config, dependencies) {
		dependencies = dependencies || {};
		var documentRef = root.ownerDocument || document;
		var strings = config.strings || {};
		var transport = dependencies.transport || createTransport(config);
		var messages = root.querySelector('[data-oras-ai-chat-messages]');
		var status = root.querySelector('[data-oras-ai-chat-status]');
		var form = root.querySelector('[data-oras-ai-chat-form]');
		var input = root.querySelector('[data-oras-ai-chat-input]');
		var send = root.querySelector('[data-oras-ai-chat-send]');
		var newChat = root.querySelector('[data-oras-ai-chat-new]');
		var close = root.querySelector('[data-oras-ai-chat-close]');
		var launcher = dependencies.launcher || null;
		var busy = false;
		var conversationId = 0;
		var lastFocused = null;

		function setStatus(text, kind) {
			status.textContent = text || '';
			if (kind) {
				status.setAttribute('data-status-kind', kind);
			} else {
				status.removeAttribute('data-status-kind');
			}
		}

		function setBusy(value) {
			busy = value;
			send.disabled = value;
			input.disabled = value;
			if (value) {
				setStatus(strings.thinking || 'Thinking…', 'pending');
			}
		}

		function renderTranscript(items) {
			clearElement(messages);
			(Array.isArray(items) ? items : []).forEach(function (message) {
				renderMessage(messages, message, documentRef);
			});
		}

		function applyConversation(data) {
			conversationId = Number(data.conversation_id || 0);
			renderTranscript(data.messages);
			if (!data.messages || !data.messages.length) {
				setStatus(strings.empty || 'Ask ORAS AI about ORAS or astronomy.', 'notice');
			}
		}

		function loadCurrent() {
			setStatus(strings.loading || 'Loading your current conversation…', 'pending');
			return transport('current').then(function (data) {
				applyConversation(data);
				if (data.messages && data.messages.length) {
					setStatus(strings.loaded || 'Conversation loaded.', 'notice');
				}
				return data;
			}).catch(function () {
				setStatus(strings.unavailable || 'ORAS AI is temporarily unavailable.', 'error');
			});
		}

		function open() {
			if (root.getAttribute('data-oras-ai-chat-mode') !== 'panel') {
				return;
			}
			lastFocused = documentRef.activeElement;
			root.hidden = false;
			if (launcher) {
				launcher.setAttribute('aria-expanded', 'true');
			}
			input.focus();
		}

		function closePanel() {
			if (root.getAttribute('data-oras-ai-chat-mode') !== 'panel') {
				return;
			}
			root.hidden = true;
			if (launcher) {
				launcher.setAttribute('aria-expanded', 'false');
				launcher.focus();
			} else if (lastFocused && typeof lastFocused.focus === 'function') {
				lastFocused.focus();
			}
		}

		function startNewChat() {
			if (busy) {
				return;
			}
			setBusy(true);
			return transport('new_chat').then(function (data) {
				applyConversation(data);
				setStatus(strings.new_chat || 'New conversation started.', 'notice');
				input.value = '';
			}).catch(function () {
				setStatus(strings.generic_error || 'Please try again.', 'error');
			}).then(function () {
				setBusy(false);
				input.focus();
			});
		}

		function submit() {
			if (busy || !input.value.trim() || !conversationId) {
				return Promise.resolve(false);
			}
			setBusy(true);
			var question = input.value.trim();
			return transport('send', { conversation_id: conversationId, question: question }).then(function (data) {
				if (data.member_message) {
					renderMessage(messages, data.member_message, documentRef);
				}
				if (data.assistant_message) {
					renderMessage(messages, data.assistant_message, documentRef);
				}
				input.value = '';
				var state = statusMessage(data.result || {}, strings);
				setStatus(state.text, state.kind);
				messages.scrollTop = messages.scrollHeight;
				return data;
			}).catch(function (error) {
				var state = statusMessage({ status: 'failure', error_code: error && error.code }, strings);
				setStatus(state.text || strings.generic_error || 'Please try again.', state.kind || 'error');
				return false;
			}).then(function (data) {
				setBusy(false);
				input.focus();
				return data;
			});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			submit();
		});
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
				event.preventDefault();
				submit();
			}
		});
		if (newChat) {
			newChat.addEventListener('click', startNewChat);
		}
		if (close) {
			close.addEventListener('click', closePanel);
		}
		root.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closePanel();
			}
		});

		loadCurrent();
		return {
			loadCurrent: loadCurrent,
			open: open,
			close: closePanel,
			newChat: startNewChat,
			submit: submit,
			state: function () { return { busy: busy, conversationId: conversationId }; }
		};
	}

	window.ORASAIChat = {
		normalizeSource: normalizeSource,
		renderSources: renderSources,
		renderMessage: renderMessage,
		createTransport: createTransport,
		statusMessage: statusMessage,
		createController: createController
	};

	function init() {
		var config = window.ORAS_AI_CHAT;
		if (!config) {
			return;
		}
		var roots = document.querySelectorAll('[data-oras-ai-chat]');
		Array.prototype.forEach.call(roots, function (root) {
			var launcher = root.parentElement ? root.parentElement.querySelector('[data-oras-ai-chat-launcher]') : null;
			var controller = createController(root, config, { launcher: launcher });
			if (launcher) {
				launcher.addEventListener('click', controller.open);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}(window, document));
