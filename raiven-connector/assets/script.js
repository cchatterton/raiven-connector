(function () {
    'use strict';
    const chat = document.getElementById('as329-rai-chat-window');
    const form = document.getElementById('as329-rai-chat-form');
    const input = document.getElementById('as329-rai-chat-prompt');
    const button = document.getElementById('as329-rai-send-button');
    if (!chat || !form || !input || !button || typeof as329Rai === 'undefined') return;
    let busy = false;
    let sessionId = chat.dataset.sessionId;
    function addMessage(role, content, time) {
        chat.querySelector('.as329-rai-empty-state')?.remove();
        const row = document.createElement('div');
        row.className = 'as329-rai-message as329-rai-message-' + role;
        const bubble = document.createElement('div');
        bubble.className = 'as329-rai-bubble';
        const label = document.createElement('div');
        label.className = 'as329-rai-meta';
        label.textContent = (role === 'user' ? 'You' : role === 'error' ? 'Error' : 'rAIven') + (time ? ' — ' + time : '');
        const body = document.createElement('div');
        body.className = 'as329-rai-content';
        body.textContent = content;
        bubble.append(label, body);
        row.append(bubble);
        chat.append(row);
        chat.scrollTop = chat.scrollHeight;
        return row;
    }
    async function sendPrompt() {
        const prompt = input.value.trim();
        if (!prompt || busy || button.disabled) return;
        busy = true;
        button.disabled = true;
        input.readOnly = true;
        button.textContent = 'Sending…';
        form.setAttribute('aria-busy', 'true');
        const pending = addMessage('assistant', 'Waiting for rAIven…');
        const data = new FormData();
        data.append('action', 'as329_rai_send_prompt');
        data.append('nonce', as329Rai.nonce);
        data.append('session_id', sessionId);
        data.append('prompt', prompt);
        try {
            const response = await fetch(as329Rai.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
            let json;
            try { json = await response.json(); } catch (_) { throw new Error('The server returned an unreadable response. Reload this page before retrying; check your saved session first.'); }
            if (json?.data?.session_id) {
                sessionId = String(json.data.session_id);
                chat.dataset.sessionId = sessionId;
                const sessionLabel = document.getElementById('as329-rai-session-label');
                if (sessionLabel) sessionLabel.textContent = 'Session: #' + sessionId;
                const url = new URL(window.location.href);
                url.searchParams.set('session_id', sessionId);
                window.history.replaceState(null, '', url);
            }
            if (!response.ok || !json?.success) throw new Error(json?.data?.message || 'The request could not be completed. Your message has been kept.');
            pending.remove();
            addMessage('user', prompt);
            addMessage('assistant', json.data.chat_message.content, json.data.chat_message.timestamp);
            input.value = '';
        } catch (error) {
            pending.remove();
            addMessage('error', error.message || 'Connection interrupted. Check your saved session before retrying.');
        } finally {
            busy = false;
            button.disabled = false;
            input.readOnly = false;
            button.textContent = 'Send';
            form.setAttribute('aria-busy', 'false');
            input.focus();
        }
    }
    form.addEventListener('submit', (event) => { event.preventDefault(); sendPrompt(); });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); sendPrompt(); }
    });
    window.addEventListener('beforeunload', (event) => {
        if (busy || input.value.trim()) { event.preventDefault(); event.returnValue = ''; }
    });
    chat.scrollTop = chat.scrollHeight;
})();
