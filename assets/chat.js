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

	function renderEscalation(card, escalation, documentRef, strings, contactUrl, onAction) {
		clearElement(card);
		card.className = 'oras-ai-chat__escalation';
		card.setAttribute('tabindex', '-1');
		var state = escalation && typeof escalation.status === 'string' ? escalation.status : 'unavailable';
		card.setAttribute('data-escalation-state', state);
		card.setAttribute('aria-busy', state === 'creating' || state === 'cancelling' ? 'true' : 'false');
		var heading = documentRef.createElement('h3');
		heading.textContent = strings.support_heading || 'Support ticket preview';
		card.appendChild(heading);

		function paragraph(value, className) {
			var node = documentRef.createElement('p');
			node.className = className || '';
			node.textContent = value;
			card.appendChild(node);
			return node;
		}
		function detail(label, value) {
			if (typeof value !== 'string' || !value) { return; }
			var row = documentRef.createElement('p');
			row.className = 'oras-ai-chat__escalation-detail';
			var strong = documentRef.createElement('strong');
			strong.textContent = label + ': ';
			row.appendChild(strong);
			var span = documentRef.createElement('span');
			span.textContent = value;
			row.appendChild(span);
			card.appendChild(row);
		}
		function fallback() {
			if (typeof contactUrl !== 'string' || !/^https?:\/\/[^\s]+$/i.test(contactUrl)) { return; }
			var row = documentRef.createElement('p');
			var link = documentRef.createElement('a');
			link.href = contactUrl;
			link.textContent = strings.support_contact || 'Contact ORAS Support';
			row.appendChild(link);
			card.appendChild(row);
		}

		if (state === 'awaiting_confirmation' && escalation.preview) {
			var preview = escalation.preview;
			detail(strings.support_category || 'Category', preview.category);
			detail(strings.support_subject || 'Subject', preview.subject);
			detail(strings.support_summary || 'Summary', preview.summary);
			detail(strings.support_question || 'Original question', preview.original_question);
			detail(strings.support_destination || 'Destination', preview.destination);
			paragraph(strings.support_disclosure || 'Support tickets follow a separate retention policy.', 'oras-ai-chat__escalation-disclosure');
			var actions = documentRef.createElement('div');
			actions.className = 'oras-ai-chat__escalation-actions';
			var create = documentRef.createElement('button');
			create.setAttribute('type', 'button');
			create.textContent = strings.support_create || 'Create Support Ticket';
			create.addEventListener('click', function () { if (onAction) { return onAction('confirm_escalation'); } });
			actions.appendChild(create);
			var cancel = documentRef.createElement('button');
			cancel.setAttribute('type', 'button');
			cancel.textContent = strings.support_cancel || 'Cancel';
			cancel.addEventListener('click', function () { if (onAction) { return onAction('cancel_escalation'); } });
			actions.appendChild(cancel);
			card.appendChild(actions);
			return card;
		}

		var messages = {
			creating: strings.support_creating || 'Creating your support ticket. Please wait.',
			cancelling: strings.support_cancelling || 'Cancelling your support request. Please wait.',
			created: strings.support_created || 'Your question was sent to ORAS Support.',
			failed: strings.support_failed || 'The support ticket could not be created.',
			uncertain: strings.support_uncertain || 'ORAS AI cannot determine whether the ticket was created and will not retry automatically.',
			cancelled: strings.support_cancelled || 'Support request cancelled. No ticket was created.',
			expired: strings.support_expired || 'This support request expired before confirmation.',
			unavailable: strings.support_unavailable || 'ORAS support ticketing is temporarily unavailable.',
			status_unavailable: strings.support_status_unknown || 'We could not check the ticket status. Refresh this page.'
		};
		paragraph(messages[state] || messages.unavailable, 'oras-ai-chat__escalation-status').setAttribute('role', 'status');
		if (state === 'created' && Number.isSafeInteger(Number(escalation.ticket_id)) && Number(escalation.ticket_id) > 0) {
			paragraph((strings.support_ticket_ref || 'Ticket') + ' #' + Number(escalation.ticket_id), 'oras-ai-chat__escalation-reference');
			if (escalation.reason === 'tag_attachment_uncertain') {
				paragraph(strings.support_tag_warning || 'Your ticket was created, but its routing tag could not be confirmed.');
			}
		}
		if (state === 'failed' || state === 'uncertain' || state === 'unavailable' || state === 'status_unavailable') {
			fallback();
		}
		return card;
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
		var supportContactUrl = config.supportContactUrl || '';

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

		function appendEscalation(initial) {
			if (!initial || typeof initial !== 'object') { return null; }
			var card = documentRef.createElement('article');
			messages.appendChild(card);
			var current = initial;
			var inFlight = false;
			function update(next) {
				current = next;
				renderEscalation(card, current, documentRef, strings, supportContactUrl, perform);
			}
			function perform(operation) {
				if (inFlight || busy || current.status !== 'awaiting_confirmation' || !current.token) { return Promise.resolve(false); }
				var token = current.token;
				inFlight = true;
				update({ status: operation === 'confirm_escalation' ? 'creating' : 'cancelling', token: token });
				setStatus(operation === 'confirm_escalation' ? strings.support_creating : strings.support_cancelling, 'pending');
				card.focus();
				return transport(operation, { token: token }).then(function (result) {
				update(result);
				var currentStatus = card.querySelector('.oras-ai-chat__escalation-status');
				setStatus(currentStatus ? currentStatus.textContent : '', result.status === 'failed' || result.status === 'uncertain' ? 'error' : 'notice');
				return result;
				}).catch(function () {
					return transport('escalation_status', { token: token }).then(function (result) {
						update(result);
						var currentStatus = card.querySelector('.oras-ai-chat__escalation-status');
						setStatus(currentStatus ? currentStatus.textContent : '', result.status === 'failed' || result.status === 'uncertain' ? 'error' : 'notice');
						return result;
					}).catch(function () {
						update({ status: 'status_unavailable', token: token });
						setStatus(strings.support_status_unknown, 'error');
						return false;
					});
				}).then(function (result) {
					inFlight = false;
					card.focus();
					return result;
				});
			}
			update(initial);
			return card;
		}

		function applyConversation(data) {
			conversationId = Number(data.conversation_id || 0);
			renderTranscript(data.messages);
			(Array.isArray(data.escalations) ? data.escalations : []).forEach(appendEscalation);
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
			var proposalCard = null;
			return transport('send', { conversation_id: conversationId, question: question }).then(function (data) {
				if (data.member_message) {
					renderMessage(messages, data.member_message, documentRef);
				}
				if (data.assistant_message) {
					renderMessage(messages, data.assistant_message, documentRef);
				}
				if (data.result && data.result.escalation) {
					proposalCard = appendEscalation(data.result.escalation);
				}
				input.value = '';
				var state = statusMessage(data.result || {}, strings);
				setStatus(proposalCard && data.result.escalation.status === 'awaiting_confirmation' ? strings.support_ready : state.text, proposalCard ? 'notice' : state.kind);
				messages.scrollTop = messages.scrollHeight;
				return data;
			}).catch(function (error) {
				var state = statusMessage({ status: 'failure', error_code: error && error.code }, strings);
				setStatus(state.text || strings.generic_error || 'Please try again.', state.kind || 'error');
				return false;
			}).then(function (data) {
				setBusy(false);
				if (proposalCard) { proposalCard.focus(); } else { input.focus(); }
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
		renderEscalation: renderEscalation,
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
