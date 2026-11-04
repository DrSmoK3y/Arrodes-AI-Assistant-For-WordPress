document.addEventListener('DOMContentLoaded', function () {

    if (typeof AAM === 'undefined') return;

    let lastUserQuery = '';

    // Helpers
    function escape_html(str) {
        if (!str && str !== 0) return '';
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
        // Disallow dangerous URI schemes (javascript:, data:, vbscript:) and protocol-relative (//)
        if (/^https?:\/\/[a-zA-Z0-9\-\.]+(:\d+)?(\/[^\s<>"']*)?$/i.test(cleanUrl)) {
            return escape_html(cleanUrl);
        }
        if (/^\/[a-zA-Z0-9_\-\.\/]+(\?[^\s<>"']*)?$/i.test(cleanUrl) && !cleanUrl.startsWith('//')) {
            return escape_html(cleanUrl);
        }
        return '';
    }

    function render_table_html(headers, rows) {
        if (!rows || rows.length === 0) return '';
        let h = '<div class="aam-table-responsive"><table class="aam-chat-table">';
        if (headers && headers.length > 0) {
            h += '<thead><tr>';
            headers.forEach(function (header) {
                h += '<th>' + escape_html(header) + '</th>';
            });
            h += '</tr></thead>';
        }
        h += '<tbody>';
        rows.forEach(function (row) {
            h += '<tr>';
            row.forEach(function (cell, idx) {
                const headerLabel = (headers && headers[idx]) ? headers[idx] : '';
                let safeCell = escape_html(cell);
                safeCell = safeCell.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
                h += '<td data-label="' + escape_html(headerLabel) + '">' + safeCell + '</td>';
            });
            h += '</tr>';
        });
        h += '</tbody></table></div>';
        return h;
    }

    function format_text_content(text) {
        if (!text) return '';

        // 1. Extract & parse markdown comparison tables into placeholders
        const tablePlaceholders = [];
        let preprocessed = String(text);

        if (preprocessed.includes('|')) {
            const lines = preprocessed.split('\n');
            const newLines = [];
            let inTable = false;
            let headers = [];
            let rows = [];

            for (let i = 0; i < lines.length; i++) {
                const line = lines[i].trim();
                const isPipeLine = (line.startsWith('|') || (line.match(/\|/g) || []).length >= 2);
                const isSeparator = /^\|?(\s*:?-{2,}:?\s*\|)+\s*:?-{2,}:?\s*\|?$/.test(line);

                if (isPipeLine) {
                    const rawCells = line.split('|').map(function (c) { return c.trim(); });
                    if (rawCells.length > 0 && rawCells[0] === '') rawCells.shift();
                    if (rawCells.length > 0 && rawCells[rawCells.length - 1] === '') rawCells.pop();

                    if (!inTable) {
                        const nextLine = (i + 1 < lines.length) ? lines[i + 1].trim() : '';
                        const nextIsSep = /^\|?(\s*:?-{2,}:?\s*\|)+\s*:?-{2,}:?\s*\|?$/.test(nextLine);
                        if (nextIsSep) {
                            inTable = true;
                            headers = rawCells;
                            rows = [];
                            i++; // skip separator line
                            continue;
                        } else {
                            newLines.push(lines[i]);
                        }
                    } else {
                        if (isSeparator) continue;
                        rows.push(rawCells);
                    }
                } else {
                    if (inTable) {
                        const placeholder = '___AAM_TBL_' + tablePlaceholders.length + '___';
                        tablePlaceholders.push(render_table_html(headers, rows));
                        newLines.push(placeholder);
                        inTable = false;
                        headers = [];
                        rows = [];
                    }
                    newLines.push(lines[i]);
                }
            }

            if (inTable) {
                const placeholder = '___AAM_TBL_' + tablePlaceholders.length + '___';
                tablePlaceholders.push(render_table_html(headers, rows));
                newLines.push(placeholder);
            }

            preprocessed = newLines.join('\n');
        }

        // 2. Strict HTML escaping on non-table text
        let safe = escape_html(preprocessed);

        // Markdown Images: ![alt](url)
        safe = safe.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s\)\"\'<>]+)\)/gi, function(match, alt, url) {
            const valid = safe_url(url);
            if (!valid) return '';
            return '<div class="aam-chat-img-wrap"><img src="' + valid + '" alt="' + escape_html(alt) + '" class="aam-chat-img" loading="lazy" /></div>';
        });

        // Markdown Links: [title](url) - strictly validate URL and escape title
        safe = safe.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)\"\'<>]+)\)/gi, function(match, title, url) {
            const valid = safe_url(url);
            if (!valid) return escape_html(title);
            return '<a href="' + valid + '" target="_blank" rel="noopener noreferrer" class="aam-chat-link">' + escape_html(title) + ' ↗</a>';
        });

        // Raw URLs
        safe = safe.replace(/(^|[\s>(])(https?:\/\/[^\s<)"']+)/gi, function(match, prefix, url) {
            const valid = safe_url(url);
            if (!valid) return prefix + escape_html(url);
            return prefix + '<a href="' + valid + '" target="_blank" rel="noopener noreferrer" class="aam-chat-link">' + valid + ' ↗</a>';
        });

        // Bold text: **text**
        safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Clean bullet list formatting (lines starting with • or - or *)
        safe = safe.replace(/(^|<br>)(?:[•\-\*]\s+)(.*?)(?=(?:<br>|$))/g, '$1<div class="aam-bullet-item"><span class="aam-bullet-dot">•</span><div>$2</div></div>');

        // Line breaks (ignore existing HTML tags)
        safe = safe.replace(/\n/g, '<br>');

        // 3. Restore parsed table HTML
        tablePlaceholders.forEach(function (tblHtml, idx) {
            safe = safe.replace('___AAM_TBL_' + idx + '___', tblHtml);
        });

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
            const title = escape_html(p.title || p.name || 'Product');
            const price = escape_html(p.price || '');
            const stock = escape_html(p.stock || p.stock_status || '');
            const img   = safe_url(p.image_url || p.image || '');
            const url   = safe_url(p.permalink || p.url || p.link || '');
            const cartUrl = safe_url(p.add_to_cart_url || '');
            const waUrl = safe_url(p.whatsapp_url || '');
            const desc  = escape_html(p.short_description || p.description || '');
            const attrs = escape_html(p.attributes || '');

            const svgFallback = "data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Crect width='200' height='200' fill='%23f1f5f9'/%3E%3Ctext x='100' y='105' font-size='36' text-anchor='middle'%3E🛍️%3C/text%3E%3Ctext x='100' y='145' font-family='sans-serif' font-size='12' font-weight='bold' fill='%230f766e' text-anchor='middle'%3EWooCommerce%3C/text%3E%3C/svg%3E";

            html += '<div class="aam-product-card">';
            html += '  <div class="aam-product-card-img">';
            html += '    <img src="' + (img || svgFallback) + '" alt="' + title + '" loading="lazy" onerror="this.onerror=null;this.src=\'' + svgFallback + '\';" />';
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

            html += '    <div class="aam-product-actions" style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-top:6px;">';
            if (url) {
                html += '      <a href="' + url + '" target="_blank" rel="noopener noreferrer" class="aam-product-btn">View Product ↗</a>';
            }
            if (cartUrl) {
                html += '      <a href="' + cartUrl + '" target="_blank" rel="noopener noreferrer" class="aam-product-cart-btn" style="background:#f1f5f9;color:#334155;padding:3px 7px;border-radius:4px;font-size:10px;font-weight:600;text-decoration:none;">Add to Cart 🛒</a>';
            }
            if (waUrl) {
                html += '      <a href="' + waUrl + '" target="_blank" rel="noopener noreferrer" class="aam-product-wa-btn" style="background:#25D366;color:#fff;padding:3px 7px;border-radius:4px;font-size:10px;font-weight:bold;text-decoration:none;">WhatsApp 💬</a>';
            }
            html += '    </div>';
            html += '  </div>';

            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    function attach_feedback_row(messageEl, query) {
        if (!messageEl || messageEl.querySelector('.aam-feedback-row')) return;
        const feedbackDiv = document.createElement('div');
        feedbackDiv.className = 'aam-feedback-row';
        feedbackDiv.innerHTML = '<span class="aam-feedback-label">Helpful?</span>' +
            '<button type="button" class="aam-feedback-btn" data-rating="up" title="Helpful">👍</button>' +
            '<button type="button" class="aam-feedback-btn" data-rating="down" title="Not helpful">👎</button>';

        const btns = feedbackDiv.querySelectorAll('.aam-feedback-btn');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const rating = this.getAttribute('data-rating');
                feedbackDiv.innerHTML = '<span style="color:#10b981;font-weight:600;font-size:11px;">✓ Thank you for your feedback!</span>';

                if (AAM.ajax_url && AAM.nonce) {
                    fetch(AAM.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'aam_save_feedback',
                            nonce:  AAM.nonce,
                            rating: rating,
                            query:  query || ''
                        })
                    }).catch(function() {});
                }
            });
        });
        messageEl.appendChild(feedbackDiv);
    }

    function stream_html_content(contentEl, fullHtml, onComplete) {
        if (!AAM.streaming || fullHtml.includes('<table') || fullHtml.includes('aam-product-card') || fullHtml.length > 1500) {
            contentEl.innerHTML = fullHtml;
            if (onComplete) onComplete();
            return;
        }

        const tokens = fullHtml.split(/(\s+|<[^>]+>)/g).filter(Boolean);
        let currentIndex = 0;
        contentEl.innerHTML = '';
        contentEl.classList.add('aam-streaming-cursor');

        const timer = setInterval(function () {
            if (currentIndex >= tokens.length) {
                clearInterval(timer);
                contentEl.classList.remove('aam-streaming-cursor');
                contentEl.innerHTML = fullHtml;
                if (onComplete) onComplete();
                return;
            }

            contentEl.innerHTML += tokens[currentIndex];
            currentIndex++;
            if (contentEl.parentElement) {
                contentEl.parentElement.scrollTop = contentEl.parentElement.scrollHeight;
            }
        }, 16);
    }

    function render_msg_into(targetContainer, content, type, animateStreaming) {
        if (!content || !targetContainer) return null;

        let cleanContent = String(content).replace(/<(think|reasoning)>[\s\S]*?<\/\1>/gi, '').trim();
        if (!cleanContent) return null;

        const div = document.createElement('div');
        div.classList.add('aam-message', 'aam-' + type);

        if (type === 'user') {
            lastUserQuery = cleanContent;
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
                attach_feedback_row(div, lastUserQuery);
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

                const formatted = format_text_content(combinedText);
                targetContainer.appendChild(div);
                if (animateStreaming && AAM.streaming) {
                    stream_html_content(div, formatted, function() {
                        attach_feedback_row(div, lastUserQuery);
                        targetContainer.scrollTop = targetContainer.scrollHeight;
                    });
                } else {
                    div.innerHTML = formatted;
                    attach_feedback_row(div, lastUserQuery);
                }
                targetContainer.scrollTop = targetContainer.scrollHeight;
                return div;
            }
        }

        // Standard text fallback
        const formattedFallback = format_text_content(cleanContent);
        targetContainer.appendChild(div);
        if (animateStreaming && AAM.streaming) {
            stream_html_content(div, formattedFallback, function() {
                attach_feedback_row(div, lastUserQuery);
                targetContainer.scrollTop = targetContainer.scrollHeight;
            });
        } else {
            div.innerHTML = formattedFallback;
            attach_feedback_row(div, lastUserQuery);
        }
        targetContainer.scrollTop = targetContainer.scrollHeight;
        return div;
    }

    // Helper to safely parse WordPress AJAX response even if notices or warnings were output
    function parse_ajax_response(rawText) {
        if (!rawText || typeof rawText !== 'string') {
            return { success: false, data: { message: 'Empty response received from server.' } };
        }

        const trimmed = rawText.trim();
        if (trimmed === '0' || trimmed === '-1') {
            return { success: false, data: { message: 'WordPress security verification failed. Please refresh the page.' } };
        }

        try {
            return JSON.parse(trimmed);
        } catch (e) {}

        const firstBrace = rawText.indexOf('{');
        const lastBrace  = rawText.lastIndexOf('}');
        if (firstBrace !== -1 && lastBrace > firstBrace) {
            try {
                return JSON.parse(rawText.substring(firstBrace, lastBrace + 1));
            } catch (e2) {}
        }

        // Clean any HTML tags to display plain message
        const stripped = trimmed.replace(/<[^>]*>?/gm, '').trim();
        return {
            success: false,
            data: { message: stripped || 'Could not process server response. Please try again.' }
        };
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
                render_msg_into(messagesEl, data.data.response, 'bot', true);
            } else {
                const errorMsg = (data.data && data.data.message) ? data.data.message : 'Something went wrong.';
                render_msg_into(messagesEl, errorMsg, 'bot', false);
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

    // Global Safe Reset Handler for Shortcode / Elementor Embed instances
    window.AAM_Reset_Embed = function (containerId, welcomeMessage) {
        const messagesEl = document.getElementById('aam-messages-' + containerId);
        const inputEl    = document.getElementById('aam-input-' + containerId);
        const wrapper    = document.getElementById(containerId);

        if (messagesEl) {
            messagesEl.textContent = '';
            const botDiv = document.createElement('div');
            botDiv.className = 'aam-message aam-bot';
            botDiv.textContent = welcomeMessage || 'Hi! How can I help you today?';
            messagesEl.appendChild(botDiv);
        }

        if (wrapper) {
            const chipsBox = wrapper.querySelector('.aam-suggested-box');
            if (chipsBox) chipsBox.style.display = 'block';
            const refreshBtn = wrapper.querySelector('.aam-refresh-btn-embedded');
            if (refreshBtn) {
                refreshBtn.classList.add('is-spinning');
                setTimeout(function() { refreshBtn.classList.remove('is-spinning'); }, 600);
            }
        }

        if (inputEl) {
            inputEl.value = '';
            inputEl.disabled = false;
            inputEl.focus();
        }

        // Clear server transient history via AJAX
        if (AAM.ajax_url && AAM.nonce) {
            fetch(AAM.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'aam_reset_chat',
                    nonce:  AAM.nonce
                })
            }).catch(function() {});
        }
    };

    // Initialize all embedded wrappers on page
    document.querySelectorAll('.aam-embed-wrapper').forEach(function (wrapper) {
        const containerId = wrapper.id;
        if (!containerId) return;

        const refreshBtn = wrapper.querySelector('.aam-refresh-btn-embedded');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const welcome = this.getAttribute('data-welcome') || '';
                window.AAM_Reset_Embed(containerId, welcome);
            });
        }

        const chips = wrapper.querySelectorAll('.aam-chip-btn-embed');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function(e) {
                e.preventDefault();
                const query = this.getAttribute('data-query');
                if (query) {
                    window.AAM_Send_Embed(containerId, query);
                }
            });
        });

        const inputEl = wrapper.querySelector('.aam-input-embedded');
        const sendBtn = wrapper.querySelector('.aam-send-embedded');

        function triggerEmbedSend() {
            if (!inputEl) return;
            const text = inputEl.value;
            if (text && text.trim()) {
                window.AAM_Send_Embed(containerId, text);
            }
        }

        if (inputEl) {
            inputEl.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    triggerEmbedSend();
                }
            });
        }

        if (sendBtn) {
            sendBtn.addEventListener('click', function(e) {
                e.preventDefault();
                triggerEmbedSend();
            });
        }
    });

    // FLOATING CHAT WIDGET INITIALIZATION
    const bubble     = document.getElementById('aam-bubble');
    const window_    = document.getElementById('aam-window');
    const closeBtn   = document.getElementById('aam-close');
    const refreshBtn = document.getElementById('aam-refresh');
    const input      = document.getElementById('aam-input');
    const sendBtn    = document.getElementById('aam-send');
    const messages   = document.getElementById('aam-messages');

    if (bubble && window_ && closeBtn && input && sendBtn && messages) {
        const suggestedWrapper = document.getElementById('aam-suggested-wrapper');
        const initialWelcomeHtml = messages.innerHTML;

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

        // Refresh / Reset Chat Handler
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function(e) {
                if (e) { e.preventDefault(); e.stopPropagation(); }
                refreshBtn.classList.add('is-spinning');
                setTimeout(function() {
                    refreshBtn.classList.remove('is-spinning');
                }, 600);

                // Reset messages area back to initial welcome greeting
                messages.innerHTML = initialWelcomeHtml;

                // Re-show suggested questions
                if (suggestedWrapper && Array.isArray(AAM.suggested_questions) && AAM.suggested_questions.length > 0) {
                    suggestedWrapper.style.display = 'block';
                }

                input.value = '';
                input.disabled = false;
                sendBtn.disabled = false;
                input.focus();

                // Clear server transient history via AJAX
                if (AAM.ajax_url && AAM.nonce) {
                    fetch(AAM.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'aam_reset_chat',
                            nonce:  AAM.nonce
                        })
                    }).catch(function() {});
                }
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

        function add_message(content, type, animateStreaming) {
            return render_msg_into(messages, content, type, animateStreaming);
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
                    add_message(data.data.response, 'bot', true);
                } else {
                    const errorMsg = (data.data && data.data.message) ? data.data.message : 'Something went wrong.';
                    add_message(errorMsg, 'bot', false);
                }
            })
            .catch(() => {
                hide_typing();
                add_message('Connection error. Please try again.', 'bot', false);
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
