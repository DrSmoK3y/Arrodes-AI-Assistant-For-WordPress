document.addEventListener('DOMContentLoaded', function () {

    if (typeof AAM === 'undefined') return;

    // Helpers
    function escape_html(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safe_url(url) {
        if (!url) return '';
        const cleanUrl = String(url).trim();
        if (/^(https?:\/\/|\/)/i.test(cleanUrl)) {
            return escape_html(cleanUrl);
        }
        return '';
    }

    function decode_entities(str) {
        if (!str) return '';
        const textarea = document.createElement('textarea');
        textarea.innerHTML = str;
        return textarea.value;
    }

    function format_text_content(text) {
        if (!text) return '';

        let cleanText = decode_entities(text);

        // Escape HTML special characters
        let safe = escape_html(cleanText);

        // Markdown Images: ![alt](url)
        safe = safe.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s\)\"]+)\)/gi, function(match, alt, url) {
            return '<div class="aam-chat-img-wrap"><img src="' + escape_html(url) + '" alt="' + escape_html(alt) + '" class="aam-chat-img" /></div>';
        });

        // Markdown Links: [title](url)
        safe = safe.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)\"]+)\)/gi, function(match, title, url) {
            return '<a href="' + escape_html(url) + '" target="_blank" rel="noopener noreferrer" class="aam-chat-link">' + title + ' ↗</a>';
        });

        // Raw URLs
        safe = safe.replace(/(^|[\s>(])(https?:\/\/[^\s<)"]+)/gi, function(match, prefix, url) {
            return prefix + '<a href="' + escape_html(url) + '" target="_blank" rel="noopener noreferrer" class="aam-chat-link">' + escape_html(url) + ' ↗</a>';
        });

        // Bold text: **text**
        safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Line breaks
        safe = safe.replace(/\n/g, '<br>');

        return safe;
    }

    function render_msg_into(targetContainer, content, type) {
        if (!content || !targetContainer) return null;

        let cleanContent = String(content).replace(/<(think|reasoning)>[\s\S]*?<\/\1>/gi, '').trim();
        if (!cleanContent) return null;

        const div = document.createElement('div');
        div.classList.add('aam-message', 'aam-' + type);

        if (type === 'user') {
            div.textContent = cleanContent;
            targetContainer.appendChild(div);
            targetContainer.scrollTop = targetContainer.scrollHeight;
            return div;
        }

        let parsedData = null;
        let rawJsonStr = cleanContent;

        const jsonMatch = content.match(/```(?:json)?\s*([\s\S]*?)\s*```/i);
        if (jsonMatch && jsonMatch[1]) {
            rawJsonStr = jsonMatch[1].trim();
        }

        if (rawJsonStr.trim().startsWith('{') || rawJsonStr.trim().startsWith('[')) {
            try {
                parsedData = JSON.parse(rawJsonStr.trim());
            } catch (e) {
                parsedData = null;
            }
        }

        if (parsedData && (parsedData.type === 'product_recommendation' || Array.isArray(parsedData.products))) {
            const products = parsedData.products || [];
            let html = '';

            if (parsedData.reply) {
                html += '<div class="aam-chat-reply-text">' + format_text_content(parsedData.reply) + '</div>';
            }

            if (products.length > 0) {
                html += '<div class="aam-products-grid">';
                products.forEach(p => {
                    const title = escape_html(decode_entities(p.title || 'Product'));
                    const price = escape_html(decode_entities(p.price || ''));
                    const stock = escape_html(decode_entities(p.stock || ''));
                    const img   = safe_url(p.image_url || '');
                    const url   = safe_url(p.permalink || '');
                    const desc  = escape_html(decode_entities(p.short_description || ''));
                    const attrs = escape_html(decode_entities(p.attributes || ''));

                    html += '<div class="aam-product-card">';
                    html += '  <div class="aam-product-card-img">';
                    if (img) {
                        html += '    <img src="' + img + '" alt="' + title + '" loading="lazy" />';
                    } else {
                        html += '    <div class="aam-product-no-img">🛍️</div>';
                    }
                    html += '  </div>';

                    html += '  <div class="aam-product-card-body">';
                    html += '    <h5 class="aam-product-title">' + title + '</h5>';
                    
                    if (price || stock) {
                        html += '    <div class="aam-product-meta">';
                        if (price) html += '      <span class="aam-product-price">' + price + '</span>';
                        if (stock) html += '      <span class="aam-product-stock">' + stock + '</span>';
                        html += '    </div>';
                    }

                    if (desc) {
                        html += '    <p class="aam-product-desc">' + desc + '</p>';
                    }

                    if (attrs) {
                        html += '    <div class="aam-product-attr">' + attrs + '</div>';
                    }

                    if (url) {
                        html += '    <a href="' + url + '" target="_blank" rel="noopener noreferrer" class="aam-product-btn">View Product ↗</a>';
                    }
                    html += '  </div>';

                    html += '</div>';
                });
                html += '</div>';
            }

            div.innerHTML = html;
        } else {
            div.innerHTML = format_text_content(content);
        }

        targetContainer.appendChild(div);
        targetContainer.scrollTop = targetContainer.scrollHeight;
        return div;
    }

    // Global Handler for Shortcode / Elementor Embed instances
    window.AAM_Send_Embed = function (containerId, text) {
        if (!text || !text.trim()) return;

        const messagesEl = document.getElementById('aam-messages-' + containerId);
        const inputEl    = document.getElementById('aam-input-' + containerId);
        if (!messagesEl) return;

        const queryText = text.trim();
        if (inputEl) inputEl.value = '';

        render_msg_into(messagesEl, queryText, 'user');

        const wrapper = document.getElementById(containerId);
        if (wrapper) {
            const chipsBox = wrapper.querySelector('.aam-suggested-box');
            if (chipsBox) chipsBox.style.display = 'none';
        }

        const typingDiv = document.createElement('div');
        typingDiv.className = 'aam-typing';
        typingDiv.innerHTML = '<span></span><span></span><span></span>';
        messagesEl.appendChild(typingDiv);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        if (inputEl) inputEl.disabled = true;

        fetch(AAM.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:  'aam_send_message',
                message: queryText,
                nonce:   AAM.nonce,
            })
        })
        .then(res => res.json())
        .then(data => {
            typingDiv.remove();
            if (data.success && data.data && data.data.response) {
                render_msg_into(messagesEl, data.data.response, 'bot');
            } else {
                const errorMsg = (data.data && data.data.message) ? data.data.message : 'Something went wrong.';
                render_msg_into(messagesEl, errorMsg, 'bot');
            }
        })
        .catch(() => {
            typingDiv.remove();
            render_msg_into(messagesEl, 'Connection error. Please try again.', 'bot');
        })
        .finally(() => {
            if (inputEl) {
                inputEl.disabled = false;
                inputEl.focus();
            }
        });
    };

    // FLOATING CHAT WIDGET INITIALIZATION
    const bubble   = document.getElementById('aam-bubble');
    const window_  = document.getElementById('aam-window');
    const closeBtn = document.getElementById('aam-close');
    const input    = document.getElementById('aam-input');
    const sendBtn  = document.getElementById('aam-send');
    const messages = document.getElementById('aam-messages');

    if (bubble && window_ && closeBtn && input && sendBtn && messages) {
        const suggestedWrapper = document.getElementById('aam-suggested-wrapper');

        // Auto open feature
        if (AAM.auto_open) {
            setTimeout(function() {
                window_.style.setProperty('display', 'flex', 'important');
                window_.setAttribute('aria-hidden', 'false');
                bubble.style.setProperty('display', 'none', 'important');
            }, 800);
        }

        // Render suggested starter questions
        if (suggestedWrapper && Array.isArray(AAM.suggested_questions) && AAM.suggested_questions.length > 0) {
            let html = '<div class="aam-suggested-box">';
            html += '<span class="aam-suggested-title">Suggested Questions:</span>';
            html += '<div class="aam-suggested-chips">';
            AAM.suggested_questions.forEach(q => {
                if (q.trim()) {
                    html += '<button type="button" class="aam-chip-btn" data-query="' + q.replace(/"/g, '&quot;') + '">💬 ' + q + '</button>';
                }
            });
            html += '</div></div>';
            suggestedWrapper.innerHTML = html;
            suggestedWrapper.style.display = 'block';

            const chips = suggestedWrapper.querySelectorAll('.aam-chip-btn');
            chips.forEach(chip => {
                chip.addEventListener('click', function() {
                    const query = this.getAttribute('data-query');
                    if (query) {
                        send_query(query);
                        suggestedWrapper.style.display = 'none';
                    }
                });
            });
        }

        bubble.addEventListener('click', function (e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            window_.style.setProperty('display', 'flex', 'important');
            window_.setAttribute('aria-hidden', 'false');
            bubble.style.setProperty('display', 'none', 'important');
            input.focus();
        });

        closeBtn.addEventListener('click', function (e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            window_.style.setProperty('display', 'none', 'important');
            window_.setAttribute('aria-hidden', 'true');
            bubble.style.setProperty('display', 'flex', 'important');
        });

        sendBtn.addEventListener('click', send_message);

        input.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') send_message();
        });

        function add_message(content, type) {
            return render_msg_into(messages, content, type);
        }

        function show_typing() {
            const div = document.createElement('div');
            div.classList.add('aam-typing');
            div.id = 'aam-typing';
            div.innerHTML = '<span></span><span></span><span></span>';
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }

        function hide_typing() {
            const typing = document.getElementById('aam-typing');
            if (typing) typing.remove();
        }

        function send_query(queryText) {
            if (!queryText || !queryText.trim()) return;

            const text = queryText.trim();
            add_message(text, 'user');
            input.value = '';

            if (suggestedWrapper) suggestedWrapper.style.display = 'none';

            show_typing();

            input.disabled   = true;
            sendBtn.disabled = true;

            fetch(AAM.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:  'aam_send_message',
                    message: text,
                    nonce:   AAM.nonce,
                })
            })
            .then(res => res.json())
            .then(data => {
                hide_typing();
                if (data.success && data.data && data.data.response) {
                    add_message(data.data.response, 'bot');
                } else {
                    const errorMsg = (data.data && data.data.message) ? data.data.message : 'Something went wrong.';
                    add_message(errorMsg, 'bot');
                }
            })
            .catch(() => {
                hide_typing();
                add_message('Connection error. Please try again.', 'bot');
            })
            .finally(() => {
                input.disabled   = false;
                sendBtn.disabled = false;
                input.focus();
            });
        }

        function send_message() {
            send_query(input.value);
        }

        // Chat starts fresh on page reload (history is cleared on page refresh)
    }

});
