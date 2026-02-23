(function($) {
    function debounce(fn, wait) {
        let timer = null;
        return function debounced() {
            const args = arguments;
            const context = this;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(context, args);
            }, wait);
        };
    }

    $(document).ready(function() {
        if (!window.mondayResourcesAdmin) {
            return;
        }

        const input = document.getElementById('organization');
        const datalist = document.getElementById('resource-organization-suggestions');
        if (!input || !datalist) {
            return;
        }

        let lookupRequest = null;
        const lookupCache = {};

        function renderSuggestions(items) {
            datalist.innerHTML = '';
            if (!Array.isArray(items) || items.length === 0) {
                return;
            }

            items.forEach(function(item) {
                if (!item || !item.name) {
                    return;
                }

                const option = document.createElement('option');
                option.value = String(item.name);
                datalist.appendChild(option);
            });
        }

        function requestSuggestions(query) {
            const normalizedQuery = String(query || '').trim().toLowerCase();
            if (Object.prototype.hasOwnProperty.call(lookupCache, normalizedQuery)) {
                renderSuggestions(lookupCache[normalizedQuery]);
                return;
            }

            if (lookupRequest && lookupRequest.readyState !== 4) {
                lookupRequest.abort();
            }

            lookupRequest = $.ajax({
                url: mondayResourcesAdmin.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'svdp_org_lookup',
                    nonce: mondayResourcesAdmin.nonce,
                    q: query,
                    limit: 15
                }
            })
                .done(function(response) {
                    const items = response && response.success && response.data && Array.isArray(response.data.items)
                        ? response.data.items
                        : [];
                    lookupCache[normalizedQuery] = items;
                    renderSuggestions(items);
                })
                .fail(function() {
                    renderSuggestions([]);
                });
        }

        const debouncedLookup = debounce(function() {
            const query = String(input.value || '').trim();
            if (query.length < 2) {
                return;
            }
            requestSuggestions(query);
        }, 220);

        input.addEventListener('focus', function() {
            const query = String(input.value || '').trim();
            if (query.length >= 2) {
                requestSuggestions(query);
                return;
            }
            requestSuggestions('');
        });

        input.addEventListener('input', debouncedLookup);
    });
})(jQuery);
