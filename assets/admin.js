jQuery(document).ready(function($) {
    // ── Single-Screen Dashboard Tab Switching with Deep-Link Anchor Hashes ──
    function activateTab(tabId) {
        if (!tabId) {
            tabId = 'tab-identity';
        }
        // Normalize if someone passes without tab- prefix
        if (!tabId.startsWith('tab-')) {
            tabId = 'tab-' + tabId;
        }

        var $targetNav = $('.aam-nav-item[data-tab="' + tabId + '"]');
        var $targetPanel = $('#' + tabId);

        if ($targetPanel.length) {
            $('.aam-nav-item').removeClass('active');
            $targetNav.addClass('active');

            $('.aam-tab-panel').removeClass('active');
            $targetPanel.addClass('active');

            $('#aam_active_tab_input').val(tabId);
        }
    }

    // Handle Tab Click
    $('.aam-nav-item[data-tab]').on('click', function(e) {
        e.preventDefault();
        var tabId = $(this).data('tab');
        activateTab(tabId);

        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, '#' + tabId);
        } else {
            window.location.hash = '#' + tabId;
        }
    });

    // Handle Initial URL Hash or Hidden Input on Load
    var currentHash = window.location.hash ? window.location.hash.substring(1) : '';
    if (currentHash && $('#' + currentHash).length) {
        activateTab(currentHash);
    } else {
        var defaultTab = $('#aam_active_tab_input').val() || 'tab-identity';
        activateTab(defaultTab);
    }

    // Support Browser Back/Forward navigation between tabs
    $(window).on('hashchange', function() {
        var h = window.location.hash ? window.location.hash.substring(1) : '';
        if (h && $('#' + h).length) {
            activateTab(h);
        }
    });

    // Two-way sync for Primary Theme Color (allows typing hex code directly)
    var $colorPicker = $('#aam_primary_color');
    var $colorText = $('#aam_primary_color_text');

    if ($colorPicker.length && $colorText.length) {
        $colorPicker.on('input change', function() {
            $colorText.val($(this).val().toUpperCase());
        });

        $colorText.on('input', function() {
            var val = $(this).val().trim();
            if (val.charAt(0) !== '#') {
                val = '#' + val;
            }
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                $colorPicker.val(val);
            }
        });

        $colorText.on('blur', function() {
            var val = $(this).val().trim();
            if (val && val.charAt(0) !== '#') {
                val = '#' + val;
            }
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                $(this).val(val.toUpperCase());
                $colorPicker.val(val);
            } else {
                $(this).val($colorPicker.val().toUpperCase());
            }
        });
    }

    // Reindex All Button
    $('#aam_reindex_btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#aam_reindex_status');

        $btn.prop('disabled', true).text('Indexing in progress...');
        $status.removeClass('success error').html('<span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span> Indexing catalog and pages...').show();

        $.ajax({
            url: aam_admin_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'aam_reindex_all',
                nonce: aam_admin_vars.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Reindex All Content Now');
                if (response.success) {
                    $status.addClass('success').html('<strong>Success:</strong> ' + response.data.message);
                    if (response.data.stats) {
                        $('#aam_stat_total').text(response.data.stats.total_indexed);
                        $('#aam_stat_products').text(response.data.stats.products_indexed);
                        $('#aam_stat_pages').text(response.data.stats.pages_indexed);
                        $('#aam_stat_updated').text(response.data.stats.last_updated);
                    }
                } else {
                    $status.addClass('error').text(response.data.message || 'Error occurred during indexing.');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Reindex All Content Now');
                $status.addClass('error').text('Server request failed. Please try again.');
            }
        });
    });

    // Test Search Button
    $('#aam_test_search_btn').on('click', function(e) {
        e.preventDefault();
        var query = $('#aam_test_query').val().trim();
        var limit = $('#aam_search_limit').val() || 8;
        var $resultsBox = $('#aam_test_results');

        if (!query) {
            alert('Please enter a search phrase or product name to test.');
            return;
        }

        $resultsBox.html('<p style="color:#64748b; font-style:italic;"><span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span> Searching index...</p>');

        $.ajax({
            url: aam_admin_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'aam_test_search',
                nonce: aam_admin_vars.nonce,
                query: query,
                limit: limit
            },
            success: function(response) {
                if (response.success) {
                    var items = response.data.results || [];
                    var prep  = response.data.preprocessed;
                    var engine = response.data.engine || 'legacy';

                    if (items.length === 0) {
                        $resultsBox.html('<div style="background:#fff1f2; border:1px solid #fecdd3; padding:12px; border-radius:6px; color:#9f1239; font-size:13px;">No matching semantic chunks found for "<strong>' + $("<div>").text(query).html() + '</strong>". The AI will safely state that information is not available rather than hallucinating.</div>');
                        return;
                    }

                    var html = '';

                    // Display Query Intent & Expansion Badge if available
                    if (prep) {
                        html += '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 14px; margin-bottom:12px; font-size:12px; color:#166534; display:flex; flex-wrap:wrap; align-items:center; gap:8px;">';
                        html += '  <span>Detected Intent: <strong style="text-transform:uppercase; background:#dcfce7; padding:2px 6px; border-radius:4px;">' + $("<div>").text(prep.intent || 'general').html() + '</strong></span>';
                        if (prep.expanded_keywords && prep.expanded_keywords.length > 0) {
                            html += '  <span style="color:#15803d;">| Expanded Tokens:</span>';
                            $.each(prep.expanded_keywords.slice(0, 6), function(idx, tok) {
                                html += '  <span style="background:#ffffff; border:1px solid #86efac; padding:1px 6px; border-radius:10px; font-size:11px;">' + $("<div>").text(tok).html() + '</span>';
                            });
                        }
                        html += '</div>';
                    }

                    html += '<div style="margin-bottom:8px; font-weight:600; font-size:13px; color:#0f172a;">Retrieved ' + items.length + ' High-Relevance Semantic Chunks:</div>';
                    html += '<div style="display:flex; flex-direction:column; gap:10px;">';

                    function safeAdminUrl(url) {
                        if (!url) return '';
                        var u = String(url).trim();
                        if (/^https?:\/\/[a-zA-Z0-9\-\.]+(:\d+)?(\/[^\s<>"']*)?$/i.test(u)) {
                            return $('<div>').text(u).html();
                        }
                        if (/^\/[a-zA-Z0-9_\-\.\/]+(\?[^\s<>"']*)?$/i.test(u) && !u.startsWith('//')) {
                            return $('<div>').text(u).html();
                        }
                        return '#';
                    }

                    $.each(items, function(i, item) {
                        var type = (item.chunk_type || item.content_type || 'text').toLowerCase();
                        var typeColor = '#0284c7';
                        var typeBg = '#e0f2fe';
                        if (type === 'pricing') {
                            typeColor = '#059669';
                            typeBg = '#d1fae5';
                        } else if (type === 'faq') {
                            typeColor = '#d97706';
                            typeBg = '#fef3c7';
                        } else if (type === 'policy') {
                            typeColor = '#7c3aed';
                            typeBg = '#ede9fe';
                        }

                        var title = item.section_title || item.title || 'Untitled Section';
                        var safeLink = safeAdminUrl(item.url || item.permalink);
                        var contentPreview = item.clean_content || item.searchable_text || '';
                        var scoreVal = item.score ? Math.round(item.score * 100) : null;
                        var imgUrl = safeAdminUrl(item.image_url || '');
                        var priceVal = item.price || '';
                        var stockVal = item.stock_status || '';

                        html += '<div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">';
                        html += '  <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; gap:12px;">';
                        html += '    <div style="display:flex; align-items:center; gap:10px;">';
                        
                        if (imgUrl && imgUrl !== '#') {
                            html += '      <img src="' + imgUrl + '" alt="' + $("<div>").text(title).html() + '" style="width:48px; height:48px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1; flex-shrink:0;" onerror="this.style.display=\'none\';" />';
                        }

                        html += '      <div>';
                        html += '        <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">';
                        html += '          <span style="background:' + typeBg + '; color:' + typeColor + '; font-size:10px; font-weight:700; padding:2px 8px; border-radius:4px; text-transform:uppercase;">' + type.toUpperCase() + '</span>';
                        if (priceVal) {
                            html += '          <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px;">' + $("<div>").text(priceVal).html() + '</span>';
                        }
                        if (stockVal) {
                            var stockBg = (stockVal.toLowerCase().indexOf('in stock') !== -1) ? '#dcfce7' : '#fee2e2';
                            var stockColor = (stockVal.toLowerCase().indexOf('in stock') !== -1) ? '#166534' : '#991b1b';
                            html += '          <span style="background:' + stockBg + '; color:' + stockColor + '; font-size:11px; font-weight:600; padding:2px 6px; border-radius:4px;">' + $("<div>").text(stockVal).html() + '</span>';
                        }
                        html += '        </div>';
                        html += '        <a href="' + safeLink + '" target="_blank" rel="noopener noreferrer" style="font-weight:600; color:#0f172a; text-decoration:none; font-size:13px;">' + $("<div>").text(title).html() + ' ↗</a>';
                        html += '      </div>';
                        html += '    </div>';
                        if (scoreVal !== null) {
                            html += '    <span style="background:#f1f5f9; color:#334155; font-size:11px; font-weight:600; padding:2px 6px; border-radius:4px; flex-shrink:0;">Match: ' + scoreVal + '%</span>';
                        }
                        html += '  </div>';
                        html += '  <div style="font-size:12px; color:#475569; line-height:1.5; white-space:pre-wrap; max-height:120px; overflow-y:auto; background:#f8fafc; padding:8px; border-radius:6px; border:1px solid #f1f5f9;">' + $("<div>").text(contentPreview).html() + '</div>';
                        html += '</div>';
                    });

                    html += '</div>';
                    $resultsBox.html(html);
                } else {
                    $resultsBox.html('<div style="color:#ef4444; font-size:13px;">Error: ' + response.data.message + '</div>');
                }
            },
            error: function() {
                $resultsBox.html('<div style="color:#ef4444; font-size:13px;">Server request failed.</div>');
            }
        });
    });

    // Quick Pricing & Plans Templates
    $('.aam-template-btn').on('click', function(e) {
        e.preventDefault();
        var tpl = $(this).data('template');
        var $target = $('#aam_pricing_plans');
        var text = '';

        if (tpl === 'saas') {
            text = "• Basic Plan: $19/mo — Essential features, 1 project, standard email support\n• Professional Plan: $49/mo — All core features, team collaboration, priority support\n• Enterprise Plan: Custom quote — Dedicated infrastructure, custom integrations & SLA";
        } else if (tpl === 'service') {
            text = "• Basic Package: $499 — Essential audit, initial setup & strategy report\n• Growth Package: $1,250/mo — Full management, weekly reporting & dedicated specialist\n• Enterprise Package: Custom Quote — Tailored enterprise architecture & SLA guarantees";
        } else if (tpl === 'course') {
            text = "• Single Course Pass: $79 — Full lifetime access to course videos, files & certificate\n• All-Access Learning Pass: $199/year — Unlimited access to all current and future certification courses\n• Corporate Team Bundle: $499/year — 5 team seats with progress tracking dashboard";
        }

        if (text) {
            var current = $target.val().trim();
            if (current && !confirm('This will replace your current pricing text. Proceed?')) {
                return;
            }
            $target.val(text);
            $target.focus();
        }
    });

    // Auto-populate Real Site Pages
    $('#aam_auto_populate_pages_btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $status = $('#aam_pages_populate_status');
        var $target = $('#aam_key_pages');

        $btn.prop('disabled', true);
        $status.removeClass('success error').html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Scanning published pages...').show();

        $.ajax({
            url: aam_admin_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'aam_get_site_pages',
                nonce: aam_admin_vars.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success && response.data && response.data.pages) {
                    var pages = response.data.pages;
                    if (pages.length === 0) {
                        $status.addClass('error').text('No published pages found.');
                        return;
                    }

                    var lines = [];
                    $.each(pages, function(i, p) {
                        lines.push('• ' + p.title + ': ' + p.permalink);
                    });

                    var combined = lines.join("\n");
                    $target.val(combined);
                    $status.addClass('success').html('<strong>Done:</strong> Loaded ' + pages.length + ' published pages into directory!');
                } else {
                    $status.addClass('error').text(response.data.message || 'Could not fetch pages.');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.addClass('error').text('Server request failed.');
            }
        });
    });

    // Clear Unanswered Questions Log
    $('#aam_clear_unanswered_btn').on('click', function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to clear the unanswered knowledge gaps log?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing...');

        $.ajax({
            url: aam_admin_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'aam_clear_unanswered_log',
                nonce: aam_admin_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#aam_unanswered_table_body').html('');
                    $('#aam_stat_gaps_count').text('0');
                    $btn.remove();
                } else {
                    alert(response.data.message || 'Error clearing log.');
                    $btn.prop('disabled', false).text('Clear Knowledge Gaps Log');
                }
            },
            error: function() {
                alert('Request failed.');
                $btn.prop('disabled', false).text('Clear Knowledge Gaps Log');
            }
        });
    });
});
