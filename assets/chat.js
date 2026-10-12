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

    function robust_json_parse(str) {
        if (!str || typeof str !== 'string') return null;
        let s = str.trim();

        try {
            return JSON.parse(s);
        } catch (e) {}

        // Fix 1: Remove trailing commas before } or ]
        let cleaned = s.replace(/,\s*([\}\]])/g, '$1');

        // Fix 2: Replace smart/curly quotes with standard quotes
        cleaned = cleaned.replace(/[\u201C\u201D]/g, '"').replace(/[\u2018\u2019]/g, "'");

        // Fix 3: Sanitize literal control characters / raw unescaped newlines inside strings
        cleaned = cleaned.replace(/[\u0000-\u001F]+/g, ' ');

        try {
            return JSON.parse(cleaned);
        } catch (e2) {}

        // Fix 4: Truncated JSON repair (e.g. model ran out of tokens mid-response)
        if (cleaned.includes('"products"') || cleaned.includes('product_recommendation')) {
            // Attempt 4A: Trim back to last complete product block closing brace '}'
            const lastBrace = cleaned.lastIndexOf('}');
            if (lastBrace !== -1) {
                const sub = cleaned.substring(0, lastBrace + 1) + "\n  ]\n}";
                try {
                    return JSON.parse(sub);
                } catch (e3) {}
            }

            // Attempt 4B: Close unclosed string and close brackets
            let attemptStr = cleaned.trim();
            const quoteCount = (attemptStr.match(/"/g) || []).length - (attemptStr.match(/\\"/g) || []).length;
            if (quoteCount % 2 !== 0) {
                attemptStr += '"';
            }
            attemptStr += "\n    }\n  ]\n}";
            try {
                return JSON.parse(attemptStr);
            } catch (e4) {}
        }

        return null;
    }

    // Emergency fallback to extract products when JSON is incomplete or heavily malformed
    function extract_products_fallback(text) {
        if (!text || typeof text !== 'string') return null;
        if (!text.includes('"title"') && !text.includes('"products"')) return null;

        const products = [];
        // Match individual product objects {...}
        const itemRegex = /\{[^{}]*?"title"\s*:\s*"([^"]+)"[^{}]*?\}/g;
        let m;
        while ((m = itemRegex.exec(text)) !== null) {
            const block = m[0];
            const getVal = (key) => {
                const r = new RegExp('"' + key + '"\\s*:\\s*"([^"]*)"', 'i');
                const res = block.match(r);
                return res ? res[1] : '';
            };
            products.push({
                title: getVal('title'),
                price: getVal('price'),
                stock: getVal('stock'),
                image_url: getVal('image_url'),
                permalink: getVal('permalink'),
                short_description: getVal('short_description'),
                attributes: getVal('attributes')
            });
        }

        // If truncated inside the first product before closing brace '}'
        if (products.length === 0) {
            const titleMatch = text.match(/"title"\s*:\s*"([^"]+)"/i);
            if (titleMatch) {
                const getLooseVal = (key) => {
                    const r = new RegExp('"' + key + '"\\s*:\\s*"([^"\\n\\r]+)', 'i');
                    const res = text.match(r);
                    return res ? res[1].replace(/"$/, '').trim() : '';
                };
                products.push({
                    title: getLooseVal('title'),
                    price: getLooseVal('price'),
                    stock: getLooseVal('stock'),
                    image_url: getLooseVal('image_url'),
                    permalink: getLooseVal('permalink'),
                    short_description: getLooseVal('short_description'),
                    attributes: getLooseVal('attributes')
                });
            }
        }

        if (products.length > 0) {
            const replyMatch = text.match(/"reply"\s*:\s*"([^"]+)"/i);
            return {
                reply: replyMatch ? replyMatch[1] : 'Here are the recommended products:',
                products: products
            };
        }

        return null;
    }

    function extract_json_data(text) {
        if (!text || typeof text !== 'string') return null;

        // 1. Check for markdown code blocks ```json ... ``` or ``` ... ```
        const codeMatch = text.match(/```(?:json)?\s*([\s\S]*?)(?:```|$)/i);
        if (codeMatch && codeMatch[1]) {
            const parsed = robust_json_parse(codeMatch[1]);
            if (parsed) {
                return {
                    data: parsed,
                    before: text.substring(0, codeMatch.index).trim(),
                    after: text.substring(codeMatch.index + codeMatch[0].length).trim()
                };
            }
        }

        // 2. Check for balanced or open JSON object { ... }
        const firstBrace = text.indexOf('{');
        const lastBrace  = text.lastIndexOf('}');
        if (firstBrace !== -1) {
            const candidate = (lastBrace > firstBrace) ? text.substring(firstBrace, lastBrace + 1) : text.substring(firstBrace);
            const parsed = robust_json_parse(candidate);
            if (parsed) {
                return {
                    data: parsed,
                    before: text.substring(0, firstBrace).trim(),
                    after: (lastBrace > firstBrace) ? text.substring(lastBrace + 1).trim() : ''
                };
            }
        }

        // 3. Check for JSON array [ ... ]
        const firstBracket = text.indexOf('[');
        const lastBracket  = text.lastIndexOf(']');
        if (firstBracket !== -1) {
            const candidate = (lastBracket > firstBracket) ? text.substring(firstBracket, lastBracket + 1) : text.substring(firstBracket);
            const parsed = robust_json_parse(candidate);
            if (parsed) {
                return {
                    data: parsed,
                    before: text.substring(0, firstBracket).trim(),
                    after: (lastBracket > firstBracket) ? text.substring(lastBracket + 1).trim() : ''
                };
            }
        }

        // 4. Emergency regex extraction for truncated product JSON
        const fallbackData = extract_products_fallback(text);
        if (fallbackData) {
            return {
                data: fallbackData,
                before: '',
                after: ''
            };
        }

        return null;
    }

    function render_products_html(products) {
        if (!Array.isArray(products) || products.length === 0) return '';
        let html = '<div class="aam-products-grid">';
        products.forEach(p => {
            if (!p || typeof p !== 'object') return;
            const title = escape_html(decode_entities(p.title || p.name || 'Product'));
            const price = escape_html(decode_entities(p.price || ''));
            const stock = escape_html(decode_entities(p.stock || p.stock_status || ''));
            const img   = safe_url(p.image_url || p.image || '');
            const url   = safe_url(p.permalink || p.url || p.link || '');
            const desc  = escape_html(decode_entities(p.short_description || p.description || ''));
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
        return html;
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

        const extracted = extract_json_data(cleanContent);

        if (extracted && extracted.data) {
            const data = extracted.data;
            const products = Array.isArray(data.products) ? data.products : (Array.isArray(data.items) ? data.items : (Array.isArray(data) ? data : null));

            // Case A: Product recommendations structured JSON
            if (products && products.length > 0) {
                let html = '';

                if (extracted.before) {
                    html += '<div class="aam-chat-reply-text">' + format_text_content(extracted.before) + '</div>';
                }

                const replyText = data.reply || data.message || data.text;
                if (replyText) {
                    html += '<div class="aam-chat-reply-text">' + format_text_content(replyText) + '</div>';
                }

                html += render_products_html(products);

                if (extracted.after) {
                    html += '<div class="aam-chat-reply-text" style="margin-top:8px;">' + format_text_content(extracted.after) + '</div>';
                }

                div.innerHTML = html;
                targetContainer.appendChild(div);
                targetContainer.scrollTop = targetContainer.scrollHeight;
                return div;
            }

            // Case B: General conversational JSON without products (e.g. {"reply": "..."})
            const generalReply = data.reply || data.message || data.text || data.answer || data.content || data.response;
            if (generalReply && typeof generalReply === 'string') {
                let combinedText = '';
                if (extracted.before) combinedText += extracted.before + "\n\n";
                combinedText += generalReply;
                if (extracted.after) combinedText += "\n\n" + extracted.after;

                div.innerHTML = format_text_content(combinedText);
                targetContainer.appendChild(div);
                targetContainer.scrollTop = targetContainer.scrollHeight;
                return div;
            }
        }

        // Standard text fallback
        div.innerHTML = format_text_content(cleanContent);
        targetContainer.appendChild(div);
        targetContainer.scrollTop = targetContainer.scrollHeight;
        return div;
    }

    // Helper to safely parse WordPress AJAX response even if notices or warnings were output
    function parse_ajax_response(rawText) {
        if (!rawText || typeof rawText !== 'string') {
            throw new Error('Empty response');
        }

        try {
            return JSON.parse(rawText.trim());
        } catch (e) {}

        const firstBrace = rawText.indexOf('{');
        const lastBrace  = rawText.lastIndexOf('}');
        if (firstBrace !== -1 && lastBrace > firstBrace) {
            try {
                return JSON.parse(rawText.substring(firstBrace, lastBrace + 1));
            } catch (e2) {}
        }

        throw new Error('Invalid JSON response format');
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
        .then(res => res.text())
        .then(rawText => {
            typingDiv.remove();
            const data = parse_ajax_response(rawText);
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
            .then(res => res.text())
            .then(rawText => {
                hide_typing();
                const data = parse_ajax_response(rawText);
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
