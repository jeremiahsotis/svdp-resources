/**
 * Frontend JavaScript for Monday Resources Plugin
 */

// Open Report Issue Modal
function openReportModal(resourceName, resourceIndex) {
    const modal = document.getElementById('reportIssueModal');
    if (!modal) {
        return;
    }

    document.getElementById('report_resource_name').value = resourceName;
    document.getElementById('report_resource_index').value = resourceIndex;

    const modalTitle = modal.querySelector('h2');
    if (modalTitle) {
        modalTitle.textContent = 'Report an Issue: ' + resourceName;
    }

    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Report Issue Modal
function closeReportModal() {
    const modal = document.getElementById('reportIssueModal');
    if (!modal) {
        return;
    }

    modal.style.display = 'none';
    document.body.style.overflow = 'auto';

    const form = document.getElementById('reportIssueForm');
    if (form) {
        form.reset();
    }

    const message = document.getElementById('reportFormMessage');
    if (message) {
        message.innerHTML = '';
    }
}

// Open Submit Resource Modal
function openSubmitResourceModal() {
    const modal = document.getElementById('submitResourceModal');
    if (!modal) {
        return;
    }

    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close Submit Resource Modal
function closeSubmitResourceModal() {
    const modal = document.getElementById('submitResourceModal');
    if (!modal) {
        return;
    }

    modal.style.display = 'none';
    document.body.style.overflow = 'auto';

    const form = document.getElementById('submitResourceForm');
    if (form) {
        form.reset();
    }

    const message = document.getElementById('submitFormMessage');
    if (message) {
        message.innerHTML = '';
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const reportModal = document.getElementById('reportIssueModal');
    const submitModal = document.getElementById('submitResourceModal');

    if (event.target === reportModal) {
        closeReportModal();
    }
    if (event.target === submitModal) {
        closeSubmitResourceModal();
    }
};

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

    function parsePrefilters(container) {
        const raw = container ? container.getAttribute('data-prefilters') : '';
        if (!raw) {
            return { geography: [], service_type: [] };
        }

        try {
            const parsed = JSON.parse(raw);
            return {
                geography: Array.isArray(parsed.geography) ? parsed.geography : [],
                service_type: Array.isArray(parsed.service_type) ? parsed.service_type : []
            };
        } catch (error) {
            return { geography: [], service_type: [] };
        }
    }

    function filterSheetOptions(input, optionContainerSelector) {
        const query = (input.value || '').toLowerCase().trim();
        const options = document.querySelectorAll(optionContainerSelector + ' .sheet-option');

        options.forEach(function(option) {
            const text = (option.getAttribute('data-filter-text') || '').toLowerCase();
            option.style.display = query === '' || text.indexOf(query) !== -1 ? 'flex' : 'none';
        });
    }

    function getCheckedValues(selector) {
        return Array.from(document.querySelectorAll(selector + ':checked')).map(function(input) {
            return input.value;
        });
    }

    function setControlsDisabled(disabled) {
        const controls = document.querySelectorAll(
            '.service-area-tile, #resource-search, #narrow-results-btn, #resources-load-more, #narrow-apply-btn, #narrow-clear-btn, #snapshot-print-btn, #snapshot-email-btn, #snapshot-text-btn'
        );

        controls.forEach(function(control) {
            if (control.id === 'snapshot-text-btn' && !mondayResources.twilioEnabled) {
                control.disabled = true;
                return;
            }
            control.disabled = disabled;
        });
    }

    function initDirectoryFiltering() {
        const directory = document.getElementById('svdp-resources-directory');
        if (!directory || !window.mondayResources) {
            return;
        }

        const prefilters = parsePrefilters(directory);
        const state = {
            service_area: '',
            services_offered: [],
            provider_type: '',
            population: [],
            q: '',
            page: 1,
            per_page: Number(mondayResources.perPage || 25),
            geography_prefilter: prefilters.geography,
            service_type_prefilter: prefilters.service_type
        };

        let currentRequest = null;

        const tiles = document.querySelectorAll('.service-area-tile');
        const searchInput = document.getElementById('resource-search');
        const narrowBtn = document.getElementById('narrow-results-btn');
        const sheet = document.getElementById('narrow-sheet');
        const sheetCloseBtn = document.getElementById('narrow-sheet-close');
        const applyBtn = document.getElementById('narrow-apply-btn');
        const clearBtn = document.getElementById('narrow-clear-btn');
        const providerToggle = document.getElementById('provider-type-toggle');
        const providerContent = document.getElementById('provider-type-content');
        const servicesSearch = document.getElementById('services-offered-search');
        const populationSearch = document.getElementById('population-search');
        const grid = document.getElementById('resources-grid');
        const loadMoreBtn = document.getElementById('resources-load-more');
        const visibleCount = document.getElementById('visible-count');
        const totalCount = document.getElementById('total-count');
        const loadingIndicator = document.getElementById('resources-filter-loading');
        const snapshotPanel = document.getElementById('snapshot-actions-panel');
        const snapshotNeighborInput = document.getElementById('snapshot-neighbor-name');
        const snapshotContactInput = document.getElementById('snapshot-contact-value');
        const snapshotPrintBtn = document.getElementById('snapshot-print-btn');
        const snapshotEmailBtn = document.getElementById('snapshot-email-btn');
        const snapshotTextBtn = document.getElementById('snapshot-text-btn');
        const snapshotMessage = document.getElementById('snapshot-action-message');
        const inlineOrgDatalist = document.getElementById('resource-inline-org-suggestions');

        let snapshotRequest = null;
        let orgLookupRequest = null;
        const orgLookupCache = {};

        function setSnapshotMessage(message, type) {
            if (!snapshotMessage) {
                return;
            }

            snapshotMessage.classList.remove('error', 'success');
            if (type === 'error') {
                snapshotMessage.classList.add('error');
            } else if (type === 'success') {
                snapshotMessage.classList.add('success');
            }
            snapshotMessage.textContent = message || '';
        }

        function setSnapshotControlsDisabled(disabled) {
            [snapshotPrintBtn, snapshotEmailBtn, snapshotTextBtn].forEach(function(button) {
                if (button) {
                    if (button.id === 'snapshot-text-btn' && !mondayResources.twilioEnabled) {
                        button.disabled = true;
                        return;
                    }
                    button.disabled = disabled;
                }
            });
        }

        function getVisibleResourceIds() {
            if (!grid) {
                return [];
            }

            return Array.from(grid.querySelectorAll('.resource-card[data-resource-id]'))
                .map(function(card) {
                    return Number(card.getAttribute('data-resource-id') || 0);
                })
                .filter(function(id) {
                    return id > 0;
                });
        }

        function extractAjaxErrorMessage(xhr, fallbackMessage) {
            const fallback = fallbackMessage || 'Something went wrong. Please try again.';
            if (!xhr) {
                return fallback;
            }

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return String(xhr.responseJSON.data.message);
            }

            return fallback;
        }

        function createSnapshot(channel, contactValue) {
            const resourceIds = getVisibleResourceIds();
            if (!resourceIds.length) {
                setSnapshotMessage('No visible resources to share yet.', 'error');
                return $.Deferred().reject().promise();
            }

            const payload = {
                action: 'svdp_snapshot_create',
                nonce: mondayResources.nonce,
                resource_ids: resourceIds,
                neighbor_name: snapshotNeighborInput ? snapshotNeighborInput.value.trim() : '',
                primary_contact_type: channel,
                primary_contact_value: contactValue || '',
                source_url: window.location.href,
                share_cap: mondayResources.shareCap || ''
            };

            if (snapshotRequest && snapshotRequest.readyState !== 4) {
                snapshotRequest.abort();
            }

            snapshotRequest = $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: payload
            });

            return snapshotRequest;
        }

        function sendSnapshot(token, channel, contactValue) {
            return $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'svdp_snapshot_send',
                    nonce: mondayResources.nonce,
                    token: token,
                    channel: channel,
                    contact_value: contactValue || '',
                    source_url: window.location.href,
                    share_cap: mondayResources.shareCap || ''
                }
            });
        }

        function handleSnapshotAction(channel) {
            if (!snapshotPanel) {
                return;
            }

            if (channel === 'text' && !mondayResources.twilioEnabled) {
                setSnapshotMessage('Text sharing is unavailable until Twilio settings are configured.', 'error');
                return;
            }

            const contactValue = snapshotContactInput ? snapshotContactInput.value.trim() : '';
            if ((channel === 'email' || channel === 'text') && !contactValue) {
                setSnapshotMessage('Enter an email or mobile number first.', 'error');
                return;
            }

            setSnapshotMessage('Preparing shared snapshot...', '');
            setSnapshotControlsDisabled(true);

            createSnapshot(channel, contactValue)
                .done(function(createResponse) {
                    if (!createResponse || !createResponse.success || !createResponse.data) {
                        setSnapshotMessage('Could not create shared snapshot.', 'error');
                        return;
                    }

                    const snapshotData = createResponse.data;
                    if (channel === 'print') {
                        const printUrl = snapshotData.print_url || '';
                        if (printUrl) {
                            window.open(printUrl, '_blank', 'noopener');
                        }
                        setSnapshotMessage('Print view opened in a new tab.', 'success');
                        return;
                    }

                    sendSnapshot(snapshotData.token, channel, contactValue)
                        .done(function(sendResponse) {
                            if (sendResponse && sendResponse.success) {
                                setSnapshotMessage(
                                    channel === 'email' ? 'Email sent successfully.' : 'Text sent successfully.',
                                    'success'
                                );
                            } else {
                                setSnapshotMessage('Snapshot created, but delivery failed.', 'error');
                            }
                        })
                        .fail(function(sendXhr) {
                            setSnapshotMessage(extractAjaxErrorMessage(sendXhr, 'Snapshot created, but delivery failed.'), 'error');
                        });
                })
                .fail(function(xhr) {
                    setSnapshotMessage(extractAjaxErrorMessage(xhr, 'Unable to create shared snapshot.'), 'error');
                })
                .always(function() {
                    setSnapshotControlsDisabled(false);
                });
        }

        function setInlineEditMessage(panel, message, type) {
            if (!panel) {
                return;
            }

            const messageEl = panel.querySelector('.inline-edit-message');
            if (!messageEl) {
                return;
            }

            messageEl.classList.remove('error', 'success');
            if (type === 'error') {
                messageEl.classList.add('error');
            } else if (type === 'success') {
                messageEl.classList.add('success');
            }
            messageEl.textContent = message || '';
        }

        function closeInlineEditPanel(panel) {
            if (!panel) {
                return;
            }

            panel.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
            const card = panel.closest('.resource-card');
            if (!card) {
                return;
            }

            const toggleBtn = card.querySelector('.resource-inline-edit-toggle');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        }

        function openInlineEditPanel(panel) {
            if (!panel) {
                return;
            }

            document.querySelectorAll('.resource-inline-edit-panel.is-open').forEach(function(otherPanel) {
                if (otherPanel !== panel) {
                    closeInlineEditPanel(otherPanel);
                }
            });

            panel.classList.add('is-open');
            panel.setAttribute('aria-hidden', 'false');

            const card = panel.closest('.resource-card');
            if (!card) {
                return;
            }

            const toggleBtn = card.querySelector('.resource-inline-edit-toggle');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', 'true');
            }
        }

        function renderInlineOrgSuggestions(items) {
            if (!inlineOrgDatalist) {
                return;
            }

            inlineOrgDatalist.innerHTML = '';
            if (!Array.isArray(items) || items.length === 0) {
                return;
            }

            items.forEach(function(item) {
                if (!item || !item.name) {
                    return;
                }

                const option = document.createElement('option');
                option.value = String(item.name);
                inlineOrgDatalist.appendChild(option);
            });
        }

        function requestInlineOrgSuggestions(query) {
            if (!inlineOrgDatalist) {
                return;
            }

            const normalizedQuery = String(query || '').trim().toLowerCase();
            if (Object.prototype.hasOwnProperty.call(orgLookupCache, normalizedQuery)) {
                renderInlineOrgSuggestions(orgLookupCache[normalizedQuery]);
                return;
            }

            if (orgLookupRequest && orgLookupRequest.readyState !== 4) {
                orgLookupRequest.abort();
            }

            orgLookupRequest = $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'svdp_org_lookup',
                    nonce: mondayResources.nonce,
                    q: query,
                    limit: 15
                }
            })
                .done(function(response) {
                    const items = response && response.success && response.data && Array.isArray(response.data.items)
                        ? response.data.items
                        : [];
                    orgLookupCache[normalizedQuery] = items;
                    renderInlineOrgSuggestions(items);
                })
                .fail(function() {
                    renderInlineOrgSuggestions([]);
                });
        }

        const debouncedInlineOrgLookup = debounce(function(inputEl) {
            if (!inlineOrgDatalist || !inputEl) {
                return;
            }

            const query = String(inputEl.value || '').trim();
            if (query.length < 2) {
                return;
            }

            requestInlineOrgSuggestions(query);
        }, 220);

        function updateCardSummaryAfterInlineSave(panel) {
            const card = panel ? panel.closest('.resource-card') : null;
            if (!card) {
                return;
            }

            const nameInput = panel.querySelector('.inline-edit-resource-name');
            const organizationInput = panel.querySelector('.inline-edit-organization');
            const serviceAreaInputs = panel.querySelectorAll('.inline-edit-service-area:checked');
            const serviceAreaLabels = Array.from(serviceAreaInputs).map(function(input) {
                const slug = String(input.value || '');
                if (mondayResources.serviceAreas && mondayResources.serviceAreas[slug]) {
                    return String(mondayResources.serviceAreas[slug]);
                }
                return slug;
            }).filter(Boolean);

            let serviceAreaLabel = '';
            if (serviceAreaLabels.length > 3) {
                const visible = serviceAreaLabels.slice(0, 3);
                serviceAreaLabel = visible.join(', ') + ' +' + String(serviceAreaLabels.length - 3) + ' more';
            } else {
                serviceAreaLabel = serviceAreaLabels.join(', ');
            }

            const titleEl = card.querySelector('h3');
            if (titleEl && nameInput) {
                titleEl.textContent = nameInput.value.trim();
            }

            const organizationValue = organizationInput ? organizationInput.value.trim() : '';
            let orgEl = card.querySelector('.resource-organization');
            if (organizationValue !== '') {
                if (!orgEl) {
                    orgEl = document.createElement('div');
                    orgEl.className = 'resource-organization';
                    if (titleEl && titleEl.parentNode) {
                        titleEl.insertAdjacentElement('afterend', orgEl);
                    }
                }
                if (orgEl) {
                    orgEl.textContent = organizationValue;
                }
            } else if (orgEl) {
                orgEl.remove();
            }

            if (serviceAreaLabel !== '') {
                card.querySelectorAll('.resource-field').forEach(function(field) {
                    const labelEl = field.querySelector('.resource-field-label');
                    if (!labelEl) {
                        return;
                    }

                    const normalizedLabel = labelEl.textContent.trim().toLowerCase();
                    if (normalizedLabel !== 'service area:' && normalizedLabel !== 'service areas:') {
                        return;
                    }

                    const valueEl = field.querySelector('.resource-field-value');
                    if (valueEl) {
                        valueEl.textContent = serviceAreaLabel;
                    }
                });
            }
        }

        function submitInlineEdit(panel, confirmedCloseMatch) {
            if (!panel) {
                return;
            }

            const resourceId = Number(panel.getAttribute('data-resource-id') || 0);
            if (!resourceId) {
                setInlineEditMessage(panel, 'Missing resource ID.', 'error');
                return;
            }

            const nameInput = panel.querySelector('.inline-edit-resource-name');
            const organizationInput = panel.querySelector('.inline-edit-organization');
            const serviceAreaInputs = panel.querySelectorAll('.inline-edit-service-area:checked');
            const servicesInput = panel.querySelector('.inline-edit-services-offered');
            const providerSelect = panel.querySelector('.inline-edit-provider-type');
            const phoneInput = panel.querySelector('.inline-edit-phone');
            const emailInput = panel.querySelector('.inline-edit-email');
            const websiteInput = panel.querySelector('.inline-edit-website');
            const notesInput = panel.querySelector('.inline-edit-notes-and-tips');
            const saveBtn = panel.querySelector('.inline-edit-save');

            const payload = {
                action: 'svdp_resource_inline_save',
                nonce: mondayResources.nonce,
                resource_id: resourceId,
                resource_name: nameInput ? nameInput.value.trim() : '',
                organization: organizationInput ? organizationInput.value.trim() : '',
                service_area: Array.from(serviceAreaInputs).map(function(input) { return input.value; }),
                provider_type: providerSelect ? providerSelect.value : '',
                phone: phoneInput ? phoneInput.value.trim() : '',
                email: emailInput ? emailInput.value.trim() : '',
                website: websiteInput ? websiteInput.value.trim() : '',
                notes_and_tips: notesInput ? notesInput.value.trim() : ''
            };

            const servicesValue = servicesInput ? servicesInput.value.trim() : '';
            payload.services_offered = servicesValue
                ? servicesValue.split(',').map(function(part) { return part.trim(); }).filter(Boolean)
                : [];

            if (confirmedCloseMatch) {
                payload.confirm_org_close_match = '1';
            }

            if (!payload.resource_name) {
                setInlineEditMessage(panel, 'Resource name is required.', 'error');
                return;
            }

            if (!Array.isArray(payload.service_area) || payload.service_area.length === 0) {
                setInlineEditMessage(panel, 'At least one Service Area is required.', 'error');
                return;
            }

            setInlineEditMessage(panel, 'Saving changes...', '');
            if (saveBtn) {
                saveBtn.disabled = true;
            }

            $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: payload
            })
                .done(function(response) {
                    if (!response || !response.success) {
                        setInlineEditMessage(panel, 'Unable to save changes.', 'error');
                        return;
                    }

                    updateCardSummaryAfterInlineSave(panel);
                    setInlineEditMessage(panel, 'Saved.', 'success');
                })
                .fail(function(xhr) {
                    const responseData = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                    if (xhr && xhr.status === 409 && responseData && responseData.code === 'organization_close_match_confirmation_required') {
                        const matches = Array.isArray(responseData.matches) ? responseData.matches : [];
                        const matchNames = matches.map(function(match) {
                            return match && match.name ? String(match.name) : '';
                        }).filter(Boolean);

                        const confirmMessage = matchNames.length
                            ? 'Possible duplicate organization found: ' + matchNames.join(', ') + '. Save anyway?'
                            : 'Possible duplicate organization found. Save anyway?';

                        if (window.confirm(confirmMessage)) {
                            submitInlineEdit(panel, true);
                            return;
                        }

                        setInlineEditMessage(panel, 'Save canceled.', 'error');
                        return;
                    }

                    setInlineEditMessage(panel, extractAjaxErrorMessage(xhr, 'Unable to save changes.'), 'error');
                })
                .always(function() {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                    }
                });
        }

        function showLoading(isLoading) {
            if (loadingIndicator) {
                loadingIndicator.classList.toggle('is-visible', isLoading);
            }
            setControlsDisabled(isLoading);
        }

        function closeSheet() {
            if (!sheet) {
                return;
            }
            sheet.classList.remove('is-open');
            sheet.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = 'auto';
        }

        function openSheet() {
            if (!sheet) {
                return;
            }
            sheet.classList.add('is-open');
            sheet.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function applyTileSelection() {
            tiles.forEach(function(tile) {
                const isSelected = tile.getAttribute('data-service-area') === state.service_area;
                tile.classList.toggle('is-selected', isSelected);
            });
        }

        function updateCounts(responseData) {
            if (visibleCount) {
                visibleCount.textContent = String(responseData.visible_count || 0);
            }
            if (totalCount) {
                totalCount.textContent = String(responseData.total_count || 0);
            }
        }

        function updateLoadMoreVisibility(responseData) {
            if (!loadMoreBtn) {
                return;
            }

            if (responseData.has_more) {
                loadMoreBtn.style.display = 'inline-flex';
            } else {
                loadMoreBtn.style.display = 'none';
            }
        }

        function performRequest(options) {
            const append = Boolean(options && options.append);

            if (currentRequest && currentRequest.readyState !== 4) {
                currentRequest.abort();
            }

            showLoading(true);

            const payload = {
                action: 'filter_resources',
                nonce: mondayResources.nonce,
                service_area: state.service_area,
                services_offered: state.services_offered,
                provider_type: state.provider_type,
                population: state.population,
                q: state.q,
                page: state.page,
                per_page: state.per_page,
                geography_prefilter: state.geography_prefilter,
                service_type_prefilter: state.service_type_prefilter
            };

            currentRequest = $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: payload
            })
                .done(function(response) {
                    if (!response || !response.success || !response.data) {
                        return;
                    }

                    if (!grid) {
                        return;
                    }

                    if (append) {
                        grid.insertAdjacentHTML('beforeend', response.data.html || '');
                    } else {
                        grid.innerHTML = response.data.html || '';
                    }

                    updateCounts(response.data);
                    updateLoadMoreVisibility(response.data);
                })
                .always(function() {
                    showLoading(false);
                });
        }

        function refreshFirstPage() {
            state.page = 1;
            performRequest({ append: false });
        }

        const debouncedSearch = debounce(function() {
            state.q = (searchInput ? searchInput.value : '').trim();
            refreshFirstPage();
        }, 350);

        tiles.forEach(function(tile) {
            tile.addEventListener('click', function() {
                const selected = this.getAttribute('data-service-area') || '';
                state.service_area = state.service_area === selected ? '' : selected;
                applyTileSelection();
                refreshFirstPage();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', debouncedSearch);
        }

        if (narrowBtn) {
            narrowBtn.addEventListener('click', openSheet);
        }

        if (sheetCloseBtn) {
            sheetCloseBtn.addEventListener('click', closeSheet);
        }

        if (sheet) {
            sheet.addEventListener('click', function(event) {
                if (event.target === sheet) {
                    closeSheet();
                }
            });
        }

        if (providerToggle && providerContent) {
            providerToggle.addEventListener('click', function() {
                providerContent.classList.toggle('is-open');
            });
        }

        if (servicesSearch) {
            servicesSearch.addEventListener('input', function() {
                filterSheetOptions(servicesSearch, '#services-offered-options');
            });
        }

        if (populationSearch) {
            populationSearch.addEventListener('input', function() {
                filterSheetOptions(populationSearch, '#population-options');
            });
        }

        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                state.services_offered = getCheckedValues('.services-offered-input');
                state.population = getCheckedValues('.population-input');

                const providerInput = document.querySelector('.provider-type-input:checked');
                state.provider_type = providerInput ? providerInput.value : '';

                closeSheet();
                refreshFirstPage();
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                document.querySelectorAll('.services-offered-input, .population-input').forEach(function(input) {
                    input.checked = false;
                });

                const anyProvider = document.querySelector('.provider-type-input[value=""]');
                if (anyProvider) {
                    anyProvider.checked = true;
                }

                state.services_offered = [];
                state.population = [];
                state.provider_type = '';

                closeSheet();
                refreshFirstPage();
            });
        }

        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                state.page += 1;
                performRequest({ append: true });
            });
        }

        if (snapshotPanel) {
            if (!mondayResources.canSnapshot) {
                snapshotPanel.style.display = 'none';
            } else {
                if (snapshotTextBtn && !mondayResources.twilioEnabled) {
                    snapshotTextBtn.disabled = true;
                    snapshotTextBtn.classList.add('is-disabled');
                    snapshotTextBtn.title = 'Text sharing is unavailable until Twilio settings are configured.';
                }

                if (snapshotPrintBtn) {
                    snapshotPrintBtn.addEventListener('click', function() {
                        handleSnapshotAction('print');
                    });
                }

                if (snapshotEmailBtn) {
                    snapshotEmailBtn.addEventListener('click', function() {
                        handleSnapshotAction('email');
                    });
                }

                if (snapshotTextBtn) {
                    snapshotTextBtn.addEventListener('click', function() {
                        handleSnapshotAction('text');
                    });
                }
            }
        }

        if (mondayResources.canInlineEdit) {
            directory.addEventListener('click', function(event) {
                const toggleBtn = event.target.closest('.resource-inline-edit-toggle');
                if (toggleBtn) {
                    const panelId = toggleBtn.getAttribute('data-target-id');
                    const panel = panelId ? document.getElementById(panelId) : null;
                    if (!panel) {
                        return;
                    }

                    if (panel.classList.contains('is-open')) {
                        closeInlineEditPanel(panel);
                    } else {
                        openInlineEditPanel(panel);
                    }
                    return;
                }

                const cancelBtn = event.target.closest('.inline-edit-cancel');
                if (cancelBtn) {
                    const panel = cancelBtn.closest('.resource-inline-edit-panel');
                    closeInlineEditPanel(panel);
                    return;
                }

                const saveBtn = event.target.closest('.inline-edit-save');
                if (saveBtn) {
                    const panel = saveBtn.closest('.resource-inline-edit-panel');
                    submitInlineEdit(panel, false);
                }
            });

            directory.addEventListener('focusin', function(event) {
                const orgInput = event.target.closest('.inline-edit-organization');
                if (!orgInput) {
                    return;
                }

                const query = String(orgInput.value || '').trim();
                if (query.length >= 2) {
                    requestInlineOrgSuggestions(query);
                    return;
                }

                requestInlineOrgSuggestions('');
            });

            directory.addEventListener('input', function(event) {
                const orgInput = event.target.closest('.inline-edit-organization');
                if (!orgInput) {
                    return;
                }

                debouncedInlineOrgLookup(orgInput);
            });
        }

        applyTileSelection();
    }

    // Handle Report Issue Form Submission
    $(document).ready(function() {
        $('#reportIssueForm').on('submit', function(e) {
            e.preventDefault();

            const form = $(this);
            const messageDiv = $('#reportFormMessage');
            const submitBtn = form.find('button[type="submit"]');

            submitBtn.prop('disabled', true).text('Submitting...');
            messageDiv.html('');

            $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                data: {
                    action: 'submit_resource_issue',
                    nonce: mondayResources.nonce,
                    resource_name: $('#report_resource_name').val(),
                    resource_index: $('#report_resource_index').val(),
                    issue_type: $('#issue_type').val(),
                    issue_description: $('#issue_description').val(),
                    reporter_name: $('#reporter_name').val(),
                    reporter_email: $('#reporter_email').val()
                },
                success: function(response) {
                    if (response.success) {
                        messageDiv.html('<div class="success-message">' + response.data.message + '</div>');
                        form[0].reset();
                        setTimeout(function() {
                            closeReportModal();
                        }, 2000);
                    } else {
                        messageDiv.html('<div class="error-message">' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    messageDiv.html('<div class="error-message">An error occurred. Please try again.</div>');
                },
                complete: function() {
                    submitBtn.prop('disabled', false).text('Submit Report');
                }
            });
        });

        $('#submitResourceForm').on('submit', function(e) {
            e.preventDefault();

            const form = $(this);
            const messageDiv = $('#submitFormMessage');
            const submitBtn = form.find('button[type="submit"]');

            submitBtn.prop('disabled', true).text('Submitting...');
            messageDiv.html('');

            $.ajax({
                url: mondayResources.ajaxurl,
                type: 'POST',
                data: {
                    action: 'submit_new_resource',
                    nonce: mondayResources.nonce,
                    organization_name: $('#organization_name').val(),
                    contact_name: $('#contact_name').val(),
                    contact_email: $('#contact_email').val(),
                    contact_phone: $('#contact_phone').val(),
                    website: $('#website').val(),
                    service_type: $('#service_type').val(),
                    description: $('#description').val(),
                    address: $('#address').val(),
                    counties_served: $('#counties_served').val()
                },
                success: function(response) {
                    if (response.success) {
                        messageDiv.html('<div class="success-message">' + response.data.message + '</div>');
                        form[0].reset();
                        setTimeout(function() {
                            closeSubmitResourceModal();
                        }, 2000);
                    } else {
                        messageDiv.html('<div class="error-message">' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    messageDiv.html('<div class="error-message">An error occurred. Please try again.</div>');
                },
                complete: function() {
                    submitBtn.prop('disabled', false).text('Submit Resource');
                }
            });
        });

        initDirectoryFiltering();
    });
})(jQuery);
