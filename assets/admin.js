jQuery(document).ready(function($) {
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
                    var items = response.data.results;
                    if (items.length === 0) {
                        $resultsBox.html('<div style="background:#fff1f2; border:1px solid #fecdd3; padding:12px; border-radius:6px; color:#9f1239; font-size:13px;">No matching products or pages found for "<strong>' + $("<div>").text(query).html() + '</strong>".</div>');
                        return;
                    }

                    var html = '<div style="margin-bottom:8px; font-weight:600; font-size:13px; color:#0f172a;">Found ' + items.length + ' matching item(s):</div>';
                    html += '<div style="display:flex; flex-direction:column; gap:10px;">';

                    $.each(items, function(i, item) {
                        var badgeBg = (item.content_type === 'product') ? '#e0f2fe' : '#f1f5f9';
                        var badgeColor = (item.content_type === 'product') ? '#0369a1' : '#475569';
                        var typeLabel = (item.content_type === 'product') ? 'PRODUCT' : 'PAGE';

                        html += '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px;">';
                        html += '  <div style="flex:1;">';
                        html += '    <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">';
                        html += '      <span style="background:' + badgeBg + '; color:' + badgeColor + '; font-size:10px; font-weight:700; padding:2px 6px; border-radius:4px; text-transform:uppercase;">' + typeLabel + '</span>';
                        html += '      <a href="' + item.permalink + '" target="_blank" style="font-weight:600; color:#0f172a; text-decoration:none; font-size:14px;">' + $("<div>").text(item.title).html() + '</a>';
                        html += '    </div>';
                        if (item.price || item.stock_status) {
                            html += '    <div style="font-size:12px; color:#059669; font-weight:600; margin-bottom:4px;">' + (item.price ? item.price + ' ' : '') + (item.stock_status ? '• (' + item.stock_status + ')' : '') + '</div>';
                        }
                        html += '    <div style="font-size:12px; color:#64748b; line-height:1.4;">' + $("<div>").text(item.searchable_text).html().substring(0, 160) + '...</div>';
                        html += '  </div>';
                        if (item.image_url) {
                            html += '  <img src="' + item.image_url + '" style="width:48px; height:48px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1;" />';
                        }
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
});
