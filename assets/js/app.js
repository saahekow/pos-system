document.addEventListener('DOMContentLoaded', () => {
    setupInternalPageBack();
    setupSystemNotifications();
    setupStandaloneCustomerFlow();
    const cards = document.querySelectorAll('.module-card');

    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 70}ms`;
        card.classList.add('is-ready');
    });

    document.querySelectorAll('[data-ghana-card-input]').forEach((input) => {
        input.addEventListener('input', () => {
            input.value = formatGhanaCardNumber(input.value);
        });
    });

    document.querySelectorAll('[data-phone-input]').forEach((input) => {
        input.addEventListener('input', () => {
            input.value = formatPhoneNumber(input.value);
        });
    });

    document.querySelectorAll('[data-email-input]').forEach((input) => {
        input.addEventListener('input', () => {
            input.value = formatEmailAddress(input.value);
        });
    });

    document.querySelectorAll('[data-current-time-target]').forEach((button) => {
        button.addEventListener('click', () => {
            setCurrentTime(button.dataset.currentTimeTarget);
            if (button.hasAttribute('data-submit-current-time')) {
                button.closest('form')?.requestSubmit();
            }
        });
    });

    setupTimePickers();
    setupCurrentLocationButtons();
    setupPhotoSourceChoices();
    setupVisitFormEmptySelects();
    setupTripDistanceCalculator();
    setupRegionTownLookup();
    setupUnifiedLocationLookup();
    setupAssignmentAccountLookups();
    setupCustomerAssignmentSelection();
    setupFollowupRegistrationSearch();
    setupTripTokenPickers();
    setupVendorTownPickers();
    setupFormLookupSelects();
    setupReportFilters();
    setupReportModePanels();
    setupSalesVisitTypeToggle();
    setupDestinationShopTypeToggle();
    setupFormAutoRecovery();
    setupFeedbackViewDialogs();
    setupMediaViewer();
    setupFollowupMethodDialogs();
    setupFilterToggles();
    setupLiveFilters();
    setupClickableListings();
    setupEditOnlyTables();
    setupCustomerPhoneChecks();
    setupSalesStatusToggles();
    setupDateDayLabels();
    setupAutoSubmitFilters();
    setupListingCounts();

    document.querySelectorAll('form[data-confirm-title]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === 'true') {
                return;
            }

            event.preventDefault();
            showConfirmDialog({
                title: form.dataset.confirmTitle || 'Confirm action',
                message: form.dataset.confirmMessage || 'Are you sure you want to continue?',
                onConfirm: () => {
                    form.dataset.confirmed = 'true';
                    form.submit();
                },
            });
        });
    });

    document.querySelectorAll('button[data-confirm-title]').forEach((button) => {
        button.addEventListener('click', (event) => {
            if (button.dataset.confirmed === 'true') return;
            event.preventDefault();
            showConfirmDialog({
                title: button.dataset.confirmTitle || 'Confirm action',
                message: button.dataset.confirmMessage || 'Are you sure you want to continue?',
                onConfirm: () => {
                    button.dataset.confirmed = 'true';
                    button.form?.requestSubmit(button);
                },
            });
        });
    });
});

function setupListingCounts() {
    document.querySelectorAll('.management-panel--table').forEach((panel) => {
        if (panel.querySelector('[data-report-results-count], [data-live-filter-count], [data-location-directory-count], .report-result-count, .marketing-notes-result-bar, [data-generic-listing-count]')) {
            return;
        }

        const table = panel.querySelector('table.data-table, table');
        const body = table?.tBodies?.[0];
        const heading = panel.querySelector('.management-heading > div, .management-heading');
        if (!body || !heading) return;

        const count = document.createElement('p');
        count.className = 'report-result-count listing-result-count';
        count.dataset.genericListingCount = '';
        heading.appendChild(count);

        const update = () => {
            const rows = Array.from(body.rows).filter((row) => {
                if (row.hidden || row.classList.contains('is-hidden')) return false;
                return !row.querySelector('td.empty-state') && !row.classList.contains('empty-state');
            });
            const total = rows.length;
            count.textContent = `${total.toLocaleString()} ${total === 1 ? 'record' : 'records'}`;
        };

        const observer = new MutationObserver(update);
        observer.observe(body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'hidden'],
        });
        update();
    });
}

function setupStandaloneCustomerFlow() {
    const form = document.querySelector('[data-standalone-customer-form]');
    const sourceGrid = form?.querySelector(':scope > .form-grid');
    const actions = form?.querySelector(':scope > .form-actions');
    if (!form || !sourceGrid || !actions) return;

    form.classList.add('standalone-customer-flow');

    form.closest('.management-panel')?.querySelector(':scope > .management-heading')?.remove();
    document.querySelectorAll('.management-heading h1').forEach((heading) => {
        if (!/^create customers?$/i.test((heading.textContent || '').trim())) return;
        heading.closest('.management-heading')?.querySelector('p')?.remove();
    });

    const visitDate = sourceGrid.querySelector('#visit_date');
    if (visitDate) {
        const visitDateField = visitDate.closest('.form-field');
        visitDate.type = 'hidden';
        form.insertBefore(visitDate, sourceGrid);
        visitDateField?.remove();
    }

    const buildAccordion = (title, iconClass, fieldIds, open = false) => {
        const details = document.createElement('details');
        details.className = 'registration-accordion';
        details.setAttribute('name', 'standalone-customer-sections');
        details.open = open;

        const summary = document.createElement('summary');
        summary.className = 'registration-accordion__summary';
        summary.innerHTML = `
            <span class="registration-accordion__number"><i class="fa-solid ${iconClass}" aria-hidden="true"></i></span>
            <span class="registration-accordion__title"><strong>${title}</strong></span>
            <i class="fa-solid fa-chevron-down registration-accordion__icon" aria-hidden="true"></i>
        `;

        const body = document.createElement('div');
        body.className = 'registration-accordion__body';
        const grid = document.createElement('div');
        grid.className = 'form-grid';

        fieldIds.forEach((fieldId) => {
            const field = form.querySelector(`#${fieldId}`)?.closest('.form-field');
            if (field) grid.append(field);
        });

        body.append(grid);
        details.append(summary, body);
        form.insertBefore(details, actions);
        return details;
    };

    const locationAccordion = buildAccordion('Location', 'fa-location-dot', [
        'destination_id', 'customer_region', 'location_id', 'area',
        'google_location', 'shop_type_id', 'shop_pic', 'shop_video', 'station_pic',
    ]);
    const customerAccordion = buildAccordion('Customer', 'fa-user', [
        'vendor_id', 'company_name', 'owner_name', 'driver_name', 'phone', 'other_phone',
        'car_registration_no', 'vin_no', 'supervisor_name', 'supervisor_phone',
        'owner_pic', 'driver_pic', 'feedback_option_id', 'note',
    ]);

    const setupRecordAccordionMenu = (accordion, labels) => {
        const body = accordion.querySelector('.registration-accordion__body');
        const newFields = body.querySelector(':scope > .form-grid');
        if (!body || !newFields) return;
        const menu = document.createElement('div');
        menu.className = 'customer-mode-gateway customer-mode-gateway--accordion';
        menu.innerHTML = `
            <button type="button" class="customer-mode-card" data-record-mode="new"><span class="customer-mode-card__icon"><i class="fa-solid fa-plus"></i></span><span><strong>New</strong><small>${labels.new}</small></span><i class="fa-solid fa-chevron-right"></i></button>
            <a class="customer-mode-card" href="${labels.editUrl}"><span class="customer-mode-card__icon"><i class="fa-solid fa-pen-to-square"></i></span><span><strong>Edit</strong><small>${labels.edit}</small></span><i class="fa-solid fa-chevron-right"></i></a>
        `;
        const newPanel = document.createElement('div');
        newPanel.className = 'record-accordion-mode-panel';
        newPanel.hidden = true;
        newPanel.append(newFields);
        body.replaceChildren(menu, newPanel);
        menu.querySelector('[data-record-mode="new"]')?.addEventListener('click', (event) => {
            newPanel.hidden = false;
            event.currentTarget.classList.add('is-active');
        });
    };
    setupRecordAccordionMenu(locationAccordion, {new: 'Register a new location', edit: 'Open location records', editUrl: form.dataset.locationEditUrl});
    setupRecordAccordionMenu(customerAccordion, {new: 'Create a new customer record', edit: 'Open customer records', editUrl: form.dataset.customerEditUrl});    const salesAccordion = buildAccordion('Sales', 'fa-chart-line', [
        'sales_customer_id', 'sale_vin_0', 'sale_amount_0', 'sales_ref', 'promo_plug', 'car_pic',
    ]);

    const salesGrid = salesAccordion.querySelector('.form-grid');
    const salesTitle = salesAccordion.querySelector('.registration-accordion__title');
    salesTitle?.insertAdjacentHTML('beforeend', '<small>Record purchased VINs and their amounts.</small>');

    const purchaseEditor = document.createElement('div');
    purchaseEditor.className = 'sale-purchase-editor';
    purchaseEditor.dataset.salePurchaseEditor = '';
    const purchaseRows = document.createElement('div');
    purchaseRows.className = 'sale-purchase-rows';
    purchaseRows.dataset.salePurchaseRows = '';
    const firstPurchaseRow = document.createElement('div');
    firstPurchaseRow.className = 'sale-purchase-row';
    firstPurchaseRow.dataset.salePurchaseRow = '';
    const firstVinField = salesGrid.querySelector('#sale_vin_0')?.closest('.form-field');
    const firstAmountField = salesGrid.querySelector('#sale_amount_0')?.closest('.form-field');
    const firstVin = firstVinField?.querySelector('input');
    if (firstVinField) firstVinField.querySelector('label').textContent = 'VIN';
    if (firstVin) firstVin.dataset.saleVinInput = '';
    if (firstAmountField) firstAmountField.querySelector('input').dataset.saleAmountInput = '';
    firstPurchaseRow.append(firstVinField, firstAmountField);

    const buildRemoveButton = () => {
        const removeButton = document.createElement('button');
        removeButton.className = 'sale-purchase-remove';
        removeButton.type = 'button';
        removeButton.dataset.removeSalePurchase = '';
        removeButton.setAttribute('aria-label', 'Remove purchased vehicle');
        removeButton.title = 'Remove';
        removeButton.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        return removeButton;
    };
    firstPurchaseRow.append(buildRemoveButton());
    purchaseRows.append(firstPurchaseRow);

    const addPurchase = document.createElement('button');
    addPurchase.className = 'secondary-button secondary-button--small';
    addPurchase.type = 'button';
    addPurchase.dataset.addSalePurchase = '';
    addPurchase.innerHTML = '<i class="fa-solid fa-plus" aria-hidden="true"></i><span>Add Another VIN</span>';
    purchaseEditor.append(purchaseRows, addPurchase);
    const salesCustomerPicker = salesGrid.querySelector('[data-sales-customer-picker]');
    salesGrid.before(salesCustomerPicker, purchaseEditor);

    purchaseEditor.addEventListener('input', (event) => {
        if (!event.target.matches('[data-sale-vin-input]')) return;
        event.target.value = event.target.value.toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g, '').slice(0, 17);
    });
    purchaseEditor.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-sale-purchase]')) {
            const row = firstPurchaseRow.cloneNode(true);
            row.querySelectorAll('input').forEach((input) => {
                input.value = '';
                input.removeAttribute('id');
            });
            row.querySelectorAll('label').forEach((label) => label.removeAttribute('for'));
            purchaseRows.append(row);
            row.querySelector('[data-sale-vin-input]')?.focus();
            return;
        }
        const removeButton = event.target.closest('[data-remove-sale-purchase]');
        if (!removeButton) return;
        const row = removeButton.closest('[data-sale-purchase-row]');
        if (purchaseRows.children.length === 1) {
            row?.querySelectorAll('input').forEach((input) => input.value = '');
        } else {
            row?.remove();
        }
    });

    const salesBody = salesAccordion.querySelector('.registration-accordion__body');
    const salesModeMenu = document.createElement('div');
    salesModeMenu.className = 'standalone-sales-menu';
    salesModeMenu.setAttribute('aria-label', 'Choose sales activity');
    salesModeMenu.innerHTML = `
        <button type="button" class="standalone-sales-menu__button" data-sales-mode="sale">
            <span class="standalone-sales-menu__icon"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i></span>
            <span><strong>Sales</strong><small>Record customer VIN and amount</small></span>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </button>
        <button type="button" class="standalone-sales-menu__button" data-sales-mode="promo">
            <span class="standalone-sales-menu__icon"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i></span>
            <span><strong>Business Promo</strong><small>Record a promotional plug</small></span>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </button>
    `;

    const salePanel = document.createElement('div');
    salePanel.className = 'standalone-sales-panel';
    salePanel.dataset.salesPanel = 'sale';
    salePanel.hidden = true;
    const salesDetailsGrid = document.createElement('div');
    salesDetailsGrid.className = 'form-grid';
    const salesRefField = salesGrid.querySelector('#sales_ref')?.closest('.form-field');
    const carPictureField = salesGrid.querySelector('#car_pic')?.closest('.form-field');
    if (salesRefField) salesDetailsGrid.append(salesRefField);
    if (carPictureField) salesDetailsGrid.append(carPictureField);
    salePanel.append(salesCustomerPicker, purchaseEditor, salesDetailsGrid);

    const promoPanel = document.createElement('div');
    promoPanel.className = 'standalone-sales-panel standalone-promo-panel';
    promoPanel.dataset.salesPanel = 'promo';
    promoPanel.hidden = true;
    const promoField = salesGrid.querySelector('#promo_plug')?.closest('.form-field');
    const promoInput = promoField?.querySelector('#promo_plug');
    const promoChoice = document.createElement('label');
    promoChoice.className = 'standalone-promo-choice';
    promoChoice.innerHTML = '<input type="checkbox" data-business-promo-toggle><span><strong>Business Promo</strong><small data-business-promo-state>No</small></span>';
    const promoToggle = promoChoice.querySelector('[data-business-promo-toggle]');
    const promoState = promoChoice.querySelector('[data-business-promo-state]');
    promoToggle.checked = String(promoInput?.value || '').trim() !== '';
    const syncPromo = () => {
        const enabled = promoToggle.checked;
        promoState.textContent = enabled ? 'Yes' : 'No';
        if (promoField) promoField.hidden = !enabled;
        if (!enabled && promoInput) {
            promoInput.value = '';
            promoInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (enabled) promoInput?.focus();
    };
    promoToggle.addEventListener('change', syncPromo);
    promoPanel.append(promoChoice);
    if (promoField) promoPanel.append(promoField);
    syncPromo();

    salesBody.replaceChildren(salesModeMenu, salePanel, promoPanel);
    salesModeMenu.addEventListener('click', (event) => {
        const button = event.target.closest('[data-sales-mode]');
        if (!button) return;
        const mode = button.dataset.salesMode;
        salesModeMenu.querySelectorAll('[data-sales-mode]').forEach((item) => item.classList.toggle('is-active', item === button));
        salePanel.hidden = mode !== 'sale';
        promoPanel.hidden = mode !== 'promo';
    });
    const salesCustomerDialog = document.querySelector('[data-sales-customer-dialog]');
    const salesCustomerId = form.querySelector('[data-sales-customer-id]');
    const salesCustomerName = form.querySelector('[data-sales-customer-name]');
    const clearSalesCustomer = form.querySelector('[data-clear-sales-customer]');
    const salesCustomerOptions = Array.from(document.querySelectorAll('[data-sales-customer-option]'));
    const selectedVendor = form.querySelector('[data-admin-customer-vendor]');
    const selectSalesCustomer = (option = null) => {
        if (salesCustomerId) salesCustomerId.value = option?.dataset.customerId || '';
        if (salesCustomerName) salesCustomerName.textContent = option?.dataset.customerName || 'Select customer';
        if (clearSalesCustomer) clearSalesCustomer.hidden = !option;
        salesCustomerDialog?.close();
        salesCustomerId?.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const filterSalesCustomers = () => {
        const query = (document.querySelector('[data-sales-customer-search]')?.value || '').trim().toLowerCase();
        let matches = 0;
        salesCustomerOptions.forEach((option) => {
            const vendorMatches = !selectedVendor || selectedVendor.value === '' || option.dataset.vendorId === selectedVendor.value;
            const visible = vendorMatches && (query === '' || (option.dataset.customerSearch || '').includes(query));
            option.hidden = !visible;
            if (visible) matches += 1;
        });
        const empty = document.querySelector('[data-sales-customer-empty]');
        if (empty) empty.hidden = matches > 0;
    };
    form.querySelector('[data-open-sales-customer]')?.addEventListener('click', () => {
        filterSalesCustomers();
        salesCustomerDialog?.showModal();
    });
    document.querySelector('[data-close-sales-customer]')?.addEventListener('click', () => salesCustomerDialog?.close());
    document.querySelector('[data-sales-customer-search]')?.addEventListener('input', filterSalesCustomers);
    clearSalesCustomer?.addEventListener('click', () => selectSalesCustomer());
    selectedVendor?.addEventListener('change', () => {
        const selected = salesCustomerOptions.find((option) => option.dataset.customerId === salesCustomerId?.value);
        if (selected && selected.dataset.vendorId !== selectedVendor.value) selectSalesCustomer();
    });
    salesCustomerOptions.forEach((option) => option.addEventListener('click', () => selectSalesCustomer(option)));
    const restoredSalesCustomer = salesCustomerOptions.find((option) => option.dataset.customerId === salesCustomerId?.value);
    if (restoredSalesCustomer) selectSalesCustomer(restoredSalesCustomer);

    const regionSelect = form.querySelector('#customer_region[data-location-region-select]');
    const townSelect = form.querySelector('#location_id[data-location-town-select]');
    const regionField = regionSelect?.closest('.form-field');
    const townField = townSelect?.closest('.form-field');
    if (regionSelect && townSelect && regionField && townField) {
        const locationPicker = document.createElement('div');
        locationPicker.className = 'form-field form-field--wide sales-customer-picker standalone-location-picker';
        locationPicker.dataset.standaloneLocationPicker = '';
        locationPicker.innerHTML = `
            <span class="sales-customer-picker__label">Town / Region</span>
            <button class="sales-customer-picker__button" type="button" data-open-standalone-location>
                <span><i class="fa-solid fa-location-dot"></i><strong data-standalone-location-name>Select town</strong></span>
                <i class="fa-solid fa-chevron-right"></i>
            </button>
            <button class="sales-customer-picker__clear" type="button" data-clear-standalone-location hidden>Clear selection</button>
        `;
        regionField.before(locationPicker);
        regionField.hidden = true;
        townField.hidden = true;

        const locationDialog = document.createElement('dialog');
        locationDialog.className = 'sales-customer-dialog';
        locationDialog.dataset.standaloneLocationDialog = '';
        locationDialog.innerHTML = `
            <div class="sales-customer-dialog__header">
                <div><span>Locations</span><h2>Select Town</h2></div>
                <button type="button" data-close-standalone-location aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <label class="sales-customer-dialog__search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="Search town, district, or region" data-standalone-location-search>
            </label>
            <div class="sales-customer-dialog__list" data-standalone-location-list></div>
            <p class="empty-state" data-standalone-location-empty hidden>No town matches your search.</p>
        `;
        document.body.append(locationDialog);

        const locationName = locationPicker.querySelector('[data-standalone-location-name]');
        const clearLocation = locationPicker.querySelector('[data-clear-standalone-location]');
        const locationList = locationDialog.querySelector('[data-standalone-location-list]');
        const locationSearch = locationDialog.querySelector('[data-standalone-location-search]');
        const locationEmpty = locationDialog.querySelector('[data-standalone-location-empty]');
        const townOptions = Array.from(townSelect.options).filter((option) => option.value !== '');
        const regionOptions = Array.from(regionSelect.options);
        const regionName = (regionKey) => regionOptions.find((option) => option.value === regionKey)?.textContent?.trim() || '';
        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        })[char]);

        const locationButtons = townOptions.map((option) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sales-customer-option';
            button.dataset.standaloneLocationOption = '';
            button.dataset.locationId = option.value;
            button.dataset.regionKey = option.dataset.regionKey || '';
            button.dataset.vendorId = option.dataset.vendorId || '';
            button.dataset.locationLabel = option.textContent.trim();
            button.dataset.locationSearch = [
                option.textContent,
                option.dataset.mmdaName || '',
                regionName(option.dataset.regionKey || ''),
            ].join(' ').toLowerCase();
            button.innerHTML = `
                <span><strong>${escapeHtml(option.textContent.trim())}</strong><small>${escapeHtml(option.dataset.mmdaName || 'District not set')}</small></span>
                <span class="sales-customer-option__meta"><small>${escapeHtml(regionName(option.dataset.regionKey || ''))}</small></span>
            `;
            locationList.append(button);
            return button;
        });

        const updateLocationSummary = () => {
            const selected = townSelect.selectedOptions[0];
            if (!selected || selected.value === '') {
                if (locationName) locationName.textContent = 'Select town';
                if (clearLocation) clearLocation.hidden = true;
                return;
            }
            if (locationName) locationName.textContent = selected.textContent.trim();
            if (clearLocation) clearLocation.hidden = false;
        };

        const filterLocations = () => {
            const query = (locationSearch?.value || '').trim().toLowerCase();
            const vendorId = selectedVendor?.value || '';
            let matches = 0;
            locationButtons.forEach((button) => {
                const vendorMatches = !button.dataset.vendorId || (vendorId !== '' && button.dataset.vendorId === vendorId);
                const visible = vendorMatches && (query === '' || (button.dataset.locationSearch || '').includes(query));
                button.hidden = !visible;
                if (visible) matches += 1;
            });
            if (locationEmpty) locationEmpty.hidden = matches > 0;
        };

        const selectLocation = (button = null) => {
            if (!button) {
                regionSelect.value = '';
                townSelect.value = '';
            } else {
                regionSelect.value = button.dataset.regionKey || '';
                regionSelect.dispatchEvent(new Event('change', { bubbles: true }));
                townSelect.value = button.dataset.locationId || '';
            }
            townSelect.dispatchEvent(new Event('change', { bubbles: true }));
            updateLocationSummary();
            locationDialog.close();
        };

        locationPicker.querySelector('[data-open-standalone-location]')?.addEventListener('click', () => {
            filterLocations();
            locationDialog.showModal();
            locationSearch?.focus();
        });
        locationDialog.querySelector('[data-close-standalone-location]')?.addEventListener('click', () => locationDialog.close());
        locationSearch?.addEventListener('input', filterLocations);
        clearLocation?.addEventListener('click', () => selectLocation());
        selectedVendor?.addEventListener('change', () => {
            const selected = townSelect.selectedOptions[0];
            if (selected?.dataset.vendorId && selectedVendor.value !== selected.dataset.vendorId) selectLocation();
            filterLocations();
        });
        locationButtons.forEach((button) => button.addEventListener('click', () => selectLocation(button)));
        townSelect.addEventListener('change', updateLocationSummary);
        updateLocationSummary();
    }

    // Keep any newly added server-side fields visible instead of dropping them
    // when this progressive enhancement does not yet know their IDs.
    if (sourceGrid.children.length > 0) {
        const customerBody = form.querySelectorAll(':scope > .registration-accordion')[1]
            ?.querySelector('.registration-accordion__body .form-grid');
        if (customerBody) customerBody.append(...sourceGrid.children);
    }
    sourceGrid.remove();

}

function setupSystemNotifications() {
    document.querySelectorAll('.profile-message').forEach((message) => {
        if (message.hasAttribute('data-persistent-message')) return;
        const messageText = (message.textContent || '').trim();
        if (messageText === '') return;

        const kind = message.classList.contains('is-error') ? 'error' : 'success';
        const duplicate = Array.from(document.querySelectorAll('[data-system-notification]'))
            .some((notification) => (notification.querySelector('span')?.textContent || '').trim() === messageText);

        if (!duplicate) {
            const notification = document.createElement('div');
            notification.className = `system-notification system-notification--${kind}`;
            notification.setAttribute('role', kind === 'error' ? 'alert' : 'status');
            notification.setAttribute('data-system-notification', '');

            const icon = document.createElement('i');
            icon.className = `fa-solid ${kind === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'}`;
            icon.setAttribute('aria-hidden', 'true');

            const text = document.createElement('span');
            text.textContent = messageText;

            const close = document.createElement('button');
            close.type = 'button';
            close.setAttribute('aria-label', 'Close notification');
            close.setAttribute('data-dismiss-system-notification', '');
            close.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

            notification.append(icon, text, close);
            document.body.prepend(notification);
        }
        message.remove();
    });

    document.querySelectorAll('[data-system-notification]').forEach((notification) => {
        const dismiss = () => {
            notification.classList.add('is-leaving');
            window.setTimeout(() => notification.remove(), 220);
        };

        notification.querySelector('[data-dismiss-system-notification]')?.addEventListener('click', dismiss);
        window.setTimeout(dismiss, notification.classList.contains('system-notification--error') ? 6000 : 4200);
    });

    const url = new URL(window.location.href);
    let changed = false;
    ['deleted', 'draft_deleted', 'visit_deleted', 'trip_deleted', 'location_left'].forEach((parameter) => {
        if (url.searchParams.has(parameter)) {
            url.searchParams.delete(parameter);
            changed = true;
        }
    });
    if (changed) window.history.replaceState({}, '', url.toString());
}

function setupInternalPageBack() {
    if (document.body.classList.contains('app-page--internal')) {
        document.querySelectorAll('main a, main button').forEach((control) => {
            const label = (control.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (control.querySelector('.fa-arrow-left')
                || label === 'back'
                || label.startsWith('back to ')) {
                control.hidden = true;
            }
        });
    }

    // The header arrow always follows its server-defined parent URL. Browser
    // history is intentionally not used because form redirects can put the
    // current page, login, or an unrelated screen immediately behind it.
}

function setupDateDayLabels() {
    document.querySelectorAll('[data-date-day-source]').forEach((input) => {
        const label = input.parentElement?.querySelector('[data-date-day-label]');
        if (!label) return;

        const update = () => {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(input.value)) {
                label.textContent = 'Select a date to see the day.';
                return;
            }
            const [year, month, day] = input.value.split('-').map(Number);
            label.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'long' })
                .format(new Date(Date.UTC(year, month - 1, day)));
        };

        input.addEventListener('input', update);
        input.addEventListener('change', update);
        update();
    });
}

function setupDestinationShopTypeToggle() {
    document.querySelectorAll('[data-destination-shop-type-toggle]').forEach((destination) => {
        const form = destination.closest('form');
        const shopTypeField = form?.querySelector('[data-shop-type-field]');
        const shopType = shopTypeField?.querySelector('[name="shop_type_id"]');
        const taxiCustomerFields = form?.querySelectorAll('[data-taxi-customer-field]') || [];
        if (!shopTypeField || !shopType) return;

        const sync = () => {
            const isTaxiRank = destination.selectedOptions[0]?.dataset.destinationKey === 'taxi_rank';
            shopTypeField.hidden = isTaxiRank;
            shopType.disabled = isTaxiRank;
            if (isTaxiRank) shopType.value = '';
            taxiCustomerFields.forEach(field => {
                field.hidden = !isTaxiRank;
                field.querySelectorAll('input,select,textarea').forEach(control => {
                    control.disabled = !isTaxiRank;
                    if (!isTaxiRank && control.type !== 'hidden') control.value = '';
                });
            });
        };

        destination.addEventListener('change', sync);
        sync();
    });
}

function setupUnifiedLocationLookup() {
    document.querySelectorAll('[data-location-region-select]').forEach((regionSelect) => {
        const form = regionSelect.closest('form');
        const townSelect = form?.querySelector('[data-location-town-select]');
        const vendorSelect = form?.querySelector('[data-admin-customer-vendor]');
        const mmdaOutput = townSelect?.closest('.form-field')?.querySelector('[data-location-mmda-output]');
        if (!townSelect) return;
        if (mmdaOutput) mmdaOutput.hidden = true;

        const townOptions = Array.from(townSelect.options);
        const syncTowns = (clearInvalid = false) => {
            const regionKey = regionSelect.value;
            townOptions.forEach((option) => {
                if (option.value === '') return;
                const matchesRegion = regionKey !== '' && option.dataset.regionKey === regionKey;
                const matchesVendor = !option.dataset.vendorId || (
                    vendorSelect?.value !== '' && option.dataset.vendorId === vendorSelect.value
                );
                const matches = matchesRegion && matchesVendor;
                option.hidden = !matches;
                option.disabled = !matches;
            });
            const selected = townSelect.selectedOptions[0];
            if (clearInvalid && selected?.disabled) townSelect.value = '';
            townSelect.dispatchEvent(new Event('change', { bubbles: true }));
        };

        regionSelect.addEventListener('change', () => syncTowns(true));
        vendorSelect?.addEventListener('change', () => syncTowns(true));
        syncTowns(false);
    });
}

function setupAutoSubmitFilters() {
    document.querySelectorAll('[data-auto-submit-filter]').forEach((form) => {
        let submitting = false;
        form.querySelectorAll('input:not([type="hidden"]), select').forEach((control) => {
            control.addEventListener('change', () => {
                if (submitting) return;
                submitting = true;
                form.requestSubmit();
            });
        });
    });
}

function setupSalesStatusToggles() {
    document.querySelectorAll('[data-sales-status-toggle]').forEach((checkbox) => {
        checkbox.addEventListener('click', (event) => event.stopPropagation());
        checkbox.addEventListener('change', async () => {
            if (checkbox.closest('.sales-status-editor')) return;
            const previous = !checkbox.checked;
            checkbox.disabled = true;
            try {
                const response = await fetch(checkbox.dataset.endpoint, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({csrf_token: checkbox.dataset.csrfToken, record_id: checkbox.dataset.recordId, source: checkbox.dataset.source, confirmed: checkbox.checked ? 1 : 0}),
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || 'Sales status could not be updated.');
                checkbox.checked = !!data.sold;
                const row = checkbox.closest('[data-customer-sales-row]');
                if (row) { row.classList.toggle('is-sold', !!data.sold); row.classList.toggle('is-unsold', !data.sold); }
                const statusContainer = checkbox.closest('[data-customer-sales-row]');
                const label = statusContainer?.querySelector('[data-sales-status-label]');
                if (label) label.textContent = statusContainer.classList.contains('sales-status-editor') ? (data.sold ? 'Yes — Purchased' : 'No — Not purchased') : data.label;
                const controlText=checkbox.closest('.sales-status-control')?.querySelector('span');
                if(controlText&&statusContainer?.classList.contains('sales-status-editor'))controlText.textContent=data.sold?'Purchased':'Mark as purchased';
            } catch (error) {
                checkbox.checked = previous;
                showNoticeDialog({title: 'Sales status', message: error.message || 'Sales status could not be updated.'});
            } finally { checkbox.disabled = false; }
        });
    });
    document.querySelectorAll('[data-sales-status-save]').forEach((button)=>{
        button.addEventListener('click',async()=>{
            const vinEditor=button.closest('[data-sales-vin-editor]');
            const scope=vinEditor?.closest('form, section')||document;
            const checkbox=scope.querySelector('.sales-status-editor [data-sales-status-toggle]');
            const vinInput=vinEditor?.querySelector('[data-sales-vins]');
            if(!checkbox||!vinInput)return;
            const vins=vinInput.value.split(/[,\n]+/).map((vin)=>vin.trim()).filter(Boolean);
            button.disabled=true;
            try{
                const response=await fetch(checkbox.dataset.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:checkbox.dataset.csrfToken,record_id:checkbox.dataset.recordId,source:checkbox.dataset.source,confirmed:checkbox.checked?1:0,vins})});
                const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Sales details could not be saved.');
                vinInput.value=(data.vins||[]).join(', ');checkbox.checked=!!data.sold;
                const editor=scope.querySelector('.sales-status-editor');editor?.classList.toggle('is-sold',!!data.sold);editor?.classList.toggle('is-unsold',!data.sold);
                const label=editor?.querySelector('[data-sales-status-label]');if(label)label.textContent=data.sold?'Yes — Purchased':'No — Not purchased';
                const count=vinEditor.querySelector('[data-sales-purchase-count]');if(count)count.textContent=Number(data.purchase_count||0).toLocaleString();
                const purchaseWord=vinEditor.querySelector('[data-sales-purchase-word]');if(purchaseWord)purchaseWord.textContent=Number(data.purchase_count||0)===1?'Purchase':'Purchases';
                const vinList=scope.querySelector('[data-sales-vin-list]');if(vinList)vinList.textContent=(data.vins||[]).length?(data.vins||[]).join(', '):'None recorded';
                showNoticeDialog({title:'Sales details saved',message:`${data.purchase_count||0} ${Number(data.purchase_count||0)===1?'Purchase':'Purchases'} saved.`});
            }catch(error){showNoticeDialog({title:'Sales details',message:error.message||'Sales details could not be saved.'});}
            finally{button.disabled=false;}
        });
    });
}

function setupFormAutoRecovery() {
    document.querySelectorAll('form[data-form-recovery-key]').forEach((form) => {
        const storageKey = `spw-form:${form.dataset.formRecoveryKey}`;
        if (form.dataset.formRecoveryRemoveKey) {
            localStorage.removeItem(`spw-form:${form.dataset.formRecoveryRemoveKey}`);
        }
        if (form.dataset.formRecoveryClear === 'true') {
            localStorage.removeItem(storageKey);
        }

        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
            Object.entries(saved).forEach(([name, value]) => {
                const controls = Array.from(form.elements).filter((control) => control.name === name && control.type !== 'file');
                controls.forEach((control) => {
                    if (control.type === 'checkbox' || control.type === 'radio') {
                        control.checked = Array.isArray(value) ? value.includes(control.value) : value === control.value;
                    } else if (!control.disabled || control.closest('[data-visit-mode]')) {
                        control.value = String(value ?? '');
                    }
                });
            });
            form.querySelectorAll('select').forEach((select) => select.dispatchEvent(new Event('change', { bubbles: true })));
        } catch (error) {
            localStorage.removeItem(storageKey);
        }

        let timer;
        const persist = () => {
            const data = {};
            Array.from(form.elements).forEach((control) => {
                if (!control.name || ['file','submit','button'].includes(control.type) || ['csrf_token','form_action'].includes(control.name)) return;
                if (control.type === 'checkbox' || control.type === 'radio') {
                    if (!control.checked) return;
                    if (!Array.isArray(data[control.name])) data[control.name] = [];
                    data[control.name].push(control.value);
                } else {
                    data[control.name] = control.value;
                }
            });
            try { localStorage.setItem(storageKey, JSON.stringify(data)); } catch (error) { /* Recovery is best-effort. */ }
        };
        const save = () => {
            clearTimeout(timer);
            timer = setTimeout(persist, 250);
        };
        form.addEventListener('input', save);
        form.addEventListener('change', save);
        form.addEventListener('submit', persist);
        window.addEventListener('pagehide', persist);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') persist();
        });
    });
}

function setupClickableListings() {
    document.querySelectorAll('[data-clickable-listing]').forEach((listing) => {
        if (listing.dataset.clickableListingReady === '1') return;
        listing.dataset.clickableListingReady = '1';
        listing.tabIndex = 0;
        listing.setAttribute('role', 'link');

        const open = () => {
            if (listing.dataset.listingUrl) {
                const destination = new URL(listing.dataset.listingUrl, window.location.href);
                if (listing.hasAttribute('data-report-filter-item')) {
                    destination.searchParams.set('return_to', `${window.location.pathname}${window.location.search}`);
                }
                window.location.href = destination.href;
                return;
            }
            listing.click();
        };

        listing.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });

        if (listing.dataset.listingUrl) {
            listing.addEventListener('click', (event) => {
                if (!event.target.closest('a, button, input, select, textarea, label')) {
                    open();
                }
            });
        }
    });
}

function setupEditOnlyTables() {
    document.querySelectorAll('table.data-table').forEach((table) => {
        const bodyRows = Array.from(table.tBodies)
            .flatMap((body) => Array.from(body.rows))
            .filter((row) => !row.querySelector('.empty-state'));
        if (!bodyRows.length) return;

        const editableRows = bodyRows.map((row) => {
            const actionCell = row.querySelector('td[data-label="Action"]');
            if (!actionCell) return null;

            const controls = actionCell.querySelectorAll('a, button, input, select, textarea');
            const editLink = controls.length === 1 && controls[0].matches('a')
                ? controls[0]
                : null;
            if (!editLink || (editLink.textContent || '').trim().toLowerCase() !== 'edit') return null;

            return {row, actionCell, editLink};
        });

        if (editableRows.some((entry) => entry === null)) return;

        const actionColumnIndex = editableRows[0].actionCell.cellIndex;
        if (table.dataset.editRowsClickable !== 'true') {
            Array.from(table.tHead?.rows || []).forEach((row) => row.cells[actionColumnIndex]?.remove());
            table.dataset.editRowsClickable = 'true';
        }

        editableRows.forEach(({row, actionCell, editLink}) => {
            const editUrl = editLink.href;
            row.classList.add('is-edit-clickable');
            row.tabIndex = 0;
            row.setAttribute('role', 'link');
            row.setAttribute('aria-label', `Edit ${(row.textContent || 'record').replace(/\s+/g, ' ').trim()}`);

            const openEditor = () => {
                window.location.href = editUrl;
            };
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, select, textarea, label')) return;
                openEditor();
            });
            row.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                openEditor();
            });
            actionCell.remove();
        });

        table.querySelectorAll('td.empty-state[colspan]').forEach((cell) => {
            cell.colSpan = Math.max(1, cell.colSpan - 1);
        });
    });
}

function setupCustomerPhoneChecks() {
    document.querySelectorAll('[data-customer-phone-check]').forEach((input) => {
        const endpoint = input.dataset.customerPhoneCheck;
        const message = document.createElement('small');
        message.className = 'phone-duplicate-message';
        input.insertAdjacentElement('afterend', message);
        let timer;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            if (!input.classList.contains('has-registered-match')) {
                input.classList.remove('is-duplicate');
                input.setCustomValidity('');
            }
            message.textContent = '';
            const phone = input.value.trim(); if (phone.replace(/\D/g,'').length < 9) return;
            timer = setTimeout(async () => {
                try {
                    const exclude = input.dataset.excludeVisitId || '';
                    const excludeDraft = input.dataset.excludeDraftId || '';
                    const response = await fetch(`${endpoint}?phone=${encodeURIComponent(phone)}&exclude_id=${encodeURIComponent(exclude)}&exclude_draft_id=${encodeURIComponent(excludeDraft)}`, {headers:{Accept:'application/json'}});
                    const data = await response.json();
                    if (data.exists) {
                        input.classList.add('is-duplicate');
                        message.textContent = `Already registered${data.customer_name ? ` to ${data.customer_name}` : ''}.`;
                        input.setCustomValidity(message.textContent);
                    } else {
                        input.setCustomValidity('');
                    }
                } catch (error) { /* Server validation still prevents duplicates. */ }
            }, 100);
        });
    });
}

function formatGhanaCardNumber(value) {
    const cleaned = value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const digits = (cleaned.startsWith('GHA') ? cleaned.slice(3) : cleaned).replace(/\D/g, '').slice(0, 10);

    if (cleaned === '') {
        return '';
    }

    if (digits.length > 9) {
        return `GHA-${digits.slice(0, 9)}-${digits.slice(9)}`;
    }

    if (digits.length > 0) {
        return `GHA-${digits}`;
    }

    return 'GHA';
}

function setupAssignmentAccountLookups() {
    document.querySelectorAll('[data-assignment-account-lookup]').forEach((input) => {
        const form = input.closest('form');
        const idInput = form?.querySelector('[data-assignment-account-id]');
        const list = input.list;
        const options = Array.from(list?.options || []);

        const selectAccount = () => {
            const match = options.find((option) => option.value === input.value);
            if (idInput) idInput.value = match?.dataset.accountId || '';
            if (match?.dataset.accountId) {
                window.location.href = `${input.dataset.assignmentAccountUrl}${encodeURIComponent(match.dataset.accountId)}`;
            }
        };

        input.addEventListener('input', selectAccount);
        input.addEventListener('change', selectAccount);
    });

    document.querySelectorAll('[data-permission-group]').forEach((group) => {
        const toggle = group.querySelector('[data-permission-group-toggle]');
        const items = [...group.querySelectorAll('[data-permission-item]')];
        if (!toggle || !items.length) return;
        const sync = () => {
            const enabled = items.filter((item) => !item.disabled);
            const checked = items.filter((item) => item.checked).length;
            toggle.checked = checked === items.length;
            toggle.indeterminate = checked > 0 && checked < items.length;
            toggle.disabled = enabled.length === 0;
        };
        toggle.addEventListener('change', () => {
            items.forEach((item) => { if (!item.disabled) item.checked = toggle.checked; });
            sync();
        });
        items.forEach((item) => item.addEventListener('change', sync));
        sync();
    });

    document.querySelectorAll('[data-menu-group-card]').forEach((group) => {
        group.addEventListener('toggle', () => {
            if (!group.open) return;
            document.querySelectorAll('[data-menu-group-card][open]').forEach((other) => {
                if (other !== group) other.open = false;
            });
        });
    });
}

function setupCustomerAssignmentSelection() {
    const selectAll = document.querySelector('[data-select-all-customers]');
    const checkboxes = Array.from(document.querySelectorAll('[data-customer-assignment-checkbox]'));
    if (!selectAll || checkboxes.length === 0) return;
    selectAll.addEventListener('change', () => { checkboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; }); });
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', () => { selectAll.checked = checkboxes.every((item) => item.checked); }));

    document.querySelectorAll('[data-vendor-assignment-lookup]').forEach((input) => {
        const form = input.closest('form');
        const idInput = form?.querySelector('[data-vendor-assignment-id]');
        const options = Array.from(input.list?.options || []);
        const syncVendor = () => {
            const match = options.find((option) => option.value === input.value);
            if (idInput) idInput.value = match?.dataset.vendorId || '';
        };
        input.addEventListener('input', syncVendor);
        input.addEventListener('change', syncVendor);
    });
}

function setupFollowupRegistrationSearch() {
    document.querySelectorAll('[data-followup-registration-listing]').forEach((scope) => {
        const input = scope.querySelector('[data-followup-registration-search]');
        const items = Array.from(scope.querySelectorAll('[data-followup-registration-item]'));
        const results = scope.querySelector('[data-followup-registration-results]');
        const empty = scope.querySelector('[data-followup-registration-empty]');
        if (!input || items.length === 0) return;
        const filter = () => {
            const query = input.value.trim().toLowerCase();
            let visible = 0;
            items.forEach((item) => {
                const matches = query !== '' && (item.dataset.followupRegistrationSearch || '').includes(query);
                item.classList.toggle('is-hidden', !matches);
                if (matches) visible += 1;
            });
            results?.classList.toggle('is-hidden', visible === 0);
            if (empty) {
                empty.textContent = query === '' ? (empty.dataset.initialMessage || '') : (empty.dataset.noMatchMessage || 'No registrations match your search.');
                empty.classList.toggle('is-hidden', visible > 0);
            }
        };
        input.addEventListener('input', filter);
        filter();
    });
}

function setupTripTokenPickers() {
    document.querySelectorAll('[data-trip-token-picker]').forEach((picker) => {
        const search = picker.querySelector('[data-trip-token-search]');
        const control = picker.querySelector('[data-trip-token-control]');
        const tokenList = picker.querySelector('[data-trip-token-list]');
        const fieldName = picker.dataset.tripTokenName || '';
        const options = Array.from(picker.querySelectorAll('[data-trip-token-option]'));
        const selected = new Map(options.filter((option) => option.dataset.tripTokenSelected === 'true').map((option) => [option.dataset.tripTokenValue, option.dataset.tripTokenLabel]));

        const render = () => {
            const query = search?.value.trim().toLowerCase() || '';
            tokenList.replaceChildren();
            picker.querySelectorAll('input[type="hidden"][data-trip-token-input]').forEach((input) => input.remove());
            selected.forEach((label, value) => {
                const token = document.createElement('span');
                token.className = 'trip-token-picker__token';
                const text = document.createElement('span'); text.textContent = label;
                const remove = document.createElement('button'); remove.type = 'button'; remove.setAttribute('aria-label', `Remove ${label}`); remove.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
                remove.addEventListener('click', () => { selected.delete(value); render(); search?.focus(); });
                token.append(text, remove); tokenList.appendChild(token);
                const input = document.createElement('input'); input.type = 'hidden'; input.name = fieldName; input.value = value; input.dataset.tripTokenInput = 'true'; picker.appendChild(input);
            });
            options.forEach((option) => {
                const isSelected = selected.has(option.dataset.tripTokenValue);
                const matches = query === '' || (option.dataset.tripTokenLabel || '').toLowerCase().includes(query);
                option.classList.toggle('is-hidden', isSelected || !matches);
            });
        };

        options.forEach((option) => option.addEventListener('click', () => {
            selected.set(option.dataset.tripTokenValue, option.dataset.tripTokenLabel || '');
            if (search) search.value = '';
            render();
            search?.focus();
        }));
        search?.addEventListener('focus', () => picker.classList.add('is-open'));
        search?.addEventListener('input', () => {
            picker.classList.add('is-open');
            render();
        });
        control?.addEventListener('click', () => {
            picker.classList.add('is-open');
            search?.focus();
        });
        document.addEventListener('click', (event) => {
            if (!picker.contains(event.target)) picker.classList.remove('is-open');
        });
        render();
    });
}

function formatPhoneNumber(value) {
    let digits = value.replace(/\D/g, '');

    if (digits.startsWith('233') && digits.length >= 12) {
        digits = `0${digits.slice(3)}`;
    }

    if (digits.length === 9 && /^[235]/.test(digits)) {
        digits = `0${digits}`;
    }

    return digits.slice(0, 10);
}

function formatEmailAddress(value) {
    return value.trim().toLowerCase().replace(/\s+/g, '');
}

function setupTimePickers() {
    document.querySelectorAll('input[type="time"]').forEach((input) => {
        input.lang = input.lang || 'en-GB';

        if (!input.step) {
            input.step = '60';
        }

        input.setAttribute('inputmode', input.getAttribute('inputmode') || 'numeric');
    });

    document.querySelectorAll('[data-time-picker]').forEach((input) => {
        input.value = formatTimeValue(input.value);

        input.addEventListener('click', () => {
            openTimePicker(input);
        });

        input.addEventListener('focus', () => {
            openTimePicker(input);
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openTimePicker(input);
            }
        });
    });
}

function openTimePicker(input) {
    if (document.querySelector('.time-picker-backdrop')) {
        return;
    }

    input.value = formatTimeValue(input.value);

    const initialTime = parseTimeValue(input.value);
    const now = new Date();
    let selectedHour = initialTime ? initialTime.hour : now.getHours();
    let selectedMinute = initialTime ? initialTime.minute : now.getMinutes();
    let mode = 'hour';

    const backdrop = document.createElement('div');
    backdrop.className = 'time-picker-backdrop';
    backdrop.innerHTML = `
        <section class="time-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="time-picker-title">
            <div class="time-picker-display" id="time-picker-title">
                <span class="time-picker-display__title">Set time</span>
                <div class="time-picker-display__value">
                    <button class="time-picker-display__part is-active" type="button" data-time-mode="hour"></button>
                    <span>:</span>
                    <button class="time-picker-display__part" type="button" data-time-mode="minute"></button>
                </div>
            </div>
            <div class="time-picker-body">
                <div class="time-picker-face" data-time-face></div>
                <div class="time-picker-manual" data-time-manual hidden>
                    <h3>Type in time</h3>
                    <div class="time-picker-manual__fields">
                        <label>
                            <input type="text" inputmode="numeric" maxlength="2" data-time-manual-hour aria-label="Hour">
                            <span>hour</span>
                        </label>
                        <strong aria-hidden="true">:</strong>
                        <label>
                            <input type="text" inputmode="numeric" maxlength="2" data-time-manual-minute aria-label="Minute">
                            <span>minute</span>
                        </label>
                    </div>
                </div>
                <div class="time-picker-actions">
                    <button class="time-picker-actions__icon" type="button" data-time-keyboard aria-label="Type time">
                        <i class="fa-regular fa-keyboard" aria-hidden="true"></i>
                    </button>
                    <button type="button" data-time-clear>Clear</button>
                    <button type="button" data-time-cancel>Cancel</button>
                    <button type="button" data-time-set>Set</button>
                </div>
            </div>
        </section>
    `;

    const dialog = backdrop.querySelector('.time-picker-dialog');
    const display = backdrop.querySelector('.time-picker-display');
    const displayParts = Array.from(backdrop.querySelectorAll('[data-time-mode]'));
    const face = backdrop.querySelector('[data-time-face]');
    const displayHour = backdrop.querySelector('[data-time-mode="hour"]');
    const displayMinute = backdrop.querySelector('[data-time-mode="minute"]');
    const manualPanel = backdrop.querySelector('[data-time-manual]');
    const manualHour = backdrop.querySelector('[data-time-manual-hour]');
    const manualMinute = backdrop.querySelector('[data-time-manual-minute]');
    const keyboardButton = backdrop.querySelector('[data-time-keyboard]');
    const keyboardIcon = keyboardButton.querySelector('i');

    const setPickerMode = (isManual) => {
        dialog.classList.toggle('is-manual-entry', isManual);
        display.classList.toggle('is-manual-entry', isManual);
        face.hidden = isManual;
        manualPanel.hidden = !isManual;
        keyboardButton.setAttribute('aria-label', isManual ? 'Use clock picker' : 'Type time');
        keyboardIcon.className = isManual ? 'fa-regular fa-clock' : 'fa-regular fa-keyboard';
    };

    const updateDisplay = () => {
        displayHour.textContent = padTimePart(selectedHour);
        displayMinute.textContent = padTimePart(selectedMinute);

        displayParts.forEach((button) => {
            button.classList.toggle('is-active', button.dataset.timeMode === mode);
        });
    };

    const renderFace = () => {
        updateDisplay();
        setPickerMode(false);
        face.textContent = '';

        const selectedValue = mode === 'hour' ? selectedHour : selectedMinute;
        const max = mode === 'hour' ? 24 : 60;
        const center = document.createElement('span');
        center.className = 'time-picker-center';
        face.appendChild(center);

        const handData = timePickerPosition(selectedValue, mode);
        const hand = document.createElement('span');
        hand.className = 'time-picker-hand';
        hand.style.width = `${handData.radius}%`;
        hand.style.transform = `rotate(${handData.angle}deg)`;
        face.appendChild(hand);

        for (let value = 0; value < max; value++) {
            const data = timePickerPosition(value, mode);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'time-picker-option';
            button.style.left = `${data.x}%`;
            button.style.top = `${data.y}%`;
            button.dataset.timeValue = String(value);
            button.setAttribute('aria-label', mode === 'hour' ? `${padTimePart(value)} hours` : `${padTimePart(value)} minutes`);

            if (data.inner) {
                button.classList.add('is-inner');
            }

            if (value === selectedValue) {
                button.classList.add('is-active');
            }

            if (mode === 'hour' || value % 5 === 0 || value === selectedMinute) {
                button.textContent = padTimePart(value);
                button.classList.add('has-label');
            }

            button.addEventListener('click', () => {
                if (mode === 'hour') {
                    selectedHour = value;
                    mode = 'minute';
                } else {
                    selectedMinute = value;
                }

                renderFace();
            });

            face.appendChild(button);
        }
    };

    const showManualEntry = () => {
        updateDisplay();
        setPickerMode(true);
        manualHour.value = padTimePart(selectedHour);
        manualMinute.value = padTimePart(selectedMinute);
        manualHour.focus();
        manualHour.select();
    };

    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };

    function escapeHandler(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    displayParts.forEach((button) => {
        button.addEventListener('click', () => {
            mode = button.dataset.timeMode || 'hour';
            renderFace();
        });
    });

    keyboardButton.addEventListener('click', () => {
        if (manualPanel.hidden) {
            showManualEntry();
            return;
        }

        const manualTime = readManualTime();

        if (manualTime) {
            selectedHour = manualTime.hour;
            selectedMinute = manualTime.minute;
        }

        renderFace();
    });

    [manualHour, manualMinute].forEach((manualField) => {
        manualField.addEventListener('input', () => {
            manualField.value = manualField.value.replace(/\D/g, '').slice(0, 2);

            if (manualField === manualHour && manualField.value.length === 2) {
                manualMinute.focus();
                manualMinute.select();
            }
        });
    });

    manualMinute.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && manualMinute.value === '') {
            manualHour.focus();
        }
    });

    backdrop.querySelector('[data-time-clear]').addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    });

    backdrop.querySelector('[data-time-cancel]').addEventListener('click', close);

    backdrop.querySelector('[data-time-set]').addEventListener('click', () => {
        if (!manualPanel.hidden) {
            const manualTime = readManualTime();

            if (!manualTime) {
                (parseManualPart(manualHour.value, 23) === null ? manualHour : manualMinute).focus();
                return;
            }

            selectedHour = manualTime.hour;
            selectedMinute = manualTime.minute;
        }

        input.value = formatTimeValue(`${selectedHour}:${selectedMinute}`);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    });

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });

    document.addEventListener('keydown', escapeHandler);
    document.body.appendChild(backdrop);
    renderFace();
    backdrop.querySelector('[data-time-set]').focus();

    function readManualTime() {
        const hour = parseManualPart(manualHour.value, 23);
        const minute = parseManualPart(manualMinute.value, 59);

        if (hour === null || minute === null) {
            return null;
        }

        return { hour, minute };
    }
}

function parseTimeValue(value) {
    const match = /^(\d{1,2}):(\d{1,2})$/.exec((value || '').trim());

    if (!match) {
        return null;
    }

    const hour = Number.parseInt(match[1], 10);
    const minute = Number.parseInt(match[2], 10);

    if (hour > 23 || minute > 59) {
        return null;
    }

    return {
        hour,
        minute,
    };
}

function formatTimeValue(value) {
    const time = parseTimeValue(value);

    if (!time) {
        return '';
    }

    return `${padTimePart(time.hour)}:${padTimePart(time.minute)}`;
}

function parseManualPart(value, max) {
    if (!/^\d{1,2}$/.test((value || '').trim())) {
        return null;
    }

    const number = Number.parseInt(value, 10);

    return number >= 0 && number <= max ? number : null;
}

function padTimePart(value) {
    return String(value).padStart(2, '0');
}

function timePickerPosition(value, mode) {
    const inner = mode === 'hour' && (value === 0 || value > 12);
    const hourValue = mode === 'hour' ? (value % 12 || 12) : value / 5;
    const angle = (hourValue * 30) - 90;
    const radius = mode === 'hour' ? (inner ? 24 : 39) : 39;
    const radians = angle * Math.PI / 180;

    return {
        angle,
        radius,
        inner,
        x: 50 + Math.cos(radians) * radius,
        y: 50 + Math.sin(radians) * radius,
    };
}

function setCurrentTime(targetId) {
    const input = document.getElementById(targetId);

    if (!input) {
        return;
    }

    const now = new Date();
    input.value = formatTimeValue(`${now.getHours()}:${now.getMinutes()}`);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function setupCurrentLocationButtons() {
    document.querySelectorAll('[data-current-location-target]').forEach((button) => {
        button.addEventListener('click', () => {
            setCurrentLocation(button, button.dataset.currentLocationTarget);
        });
    });
}

function setCurrentLocation(button, targetId) {
    const input = document.getElementById(targetId);

    if (!input) {
        return;
    }

    if (!navigator.geolocation) {
        window.alert('Location is not supported on this device or browser.');
        return;
    }

    const label = button.querySelector('span');
    const originalText = label ? label.textContent : button.textContent;
    button.disabled = true;
    button.classList.add('is-loading');
    if (label) {
        label.textContent = 'Getting...';
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const latitude = position.coords.latitude.toFixed(7);
            const longitude = position.coords.longitude.toFixed(7);
            input.value = `https://www.google.com/maps?q=${latitude},${longitude}`;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            button.disabled = false;
            button.classList.remove('is-loading');
            if (label) {
                label.textContent = 'Use GPS';
            }
        },
        (error) => {
            button.disabled = false;
            button.classList.remove('is-loading');
            if (label) {
                label.textContent = originalText.trim() || 'Use GPS';
            }

            if (error.code === error.PERMISSION_DENIED) {
                window.alert('Location permission was denied. Please allow location access and try again.');
                return;
            }

            window.alert('Unable to get the current location. Please try again near the shop.');
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        }
    );
}

function setupPhotoSourceChoices() {
    document.querySelectorAll('[data-photo-source-choice]').forEach((input) => {
        input.dataset.photoOriginalAccept = input.getAttribute('accept') || '';
        input.addEventListener('change', () => updatePhotoPreview(input));

        input.addEventListener('click', (event) => {
            if (input.dataset.photoChooserOpening === 'true') {
                return;
            }

            event.preventDefault();
            showPhotoSourceDialog(input);
        });
    });
}

function updatePhotoPreview(input) {
    const field = input.closest('.form-field') || input.parentElement;
    if (!field) return;

    let preview = field.querySelector('[data-photo-preview]');
    if (!preview) {
        preview = document.createElement('div');
        preview.className = 'photo-file-preview';
        preview.setAttribute('data-photo-preview', '');
        preview.setAttribute('aria-live', 'polite');
        input.insertAdjacentElement('afterend', preview);
    }

    const file = input.files && input.files[0];
    if (!file) {
        preview.replaceChildren();
        preview.hidden = true;
        return;
    }

    preview.hidden = false;
    preview.replaceChildren();

    const image = document.createElement('img');
    image.className = 'photo-file-preview__image';
    image.alt = `Selected ${file.name || 'picture'}`;

    const details = document.createElement('span');
    details.className = 'photo-file-preview__details';
    details.textContent = file.name || 'Picture ready';

    if (file.type && file.type.startsWith('image/')) {
        const objectUrl = URL.createObjectURL(file);
        image.src = objectUrl;
        image.addEventListener('load', () => URL.revokeObjectURL(objectUrl), { once: true });
        image.addEventListener('error', () => {
            URL.revokeObjectURL(objectUrl);
            image.remove();
            details.textContent = `${file.name || 'Picture'} selected`;
        }, { once: true });
        preview.appendChild(image);
    }

    preview.appendChild(details);
}

function showPhotoSourceDialog(input) {
    const existingDialog = document.querySelector('.photo-source-backdrop');

    if (existingDialog) {
        existingDialog.remove();
    }

    const field = input.closest('.form-field');
    const label = field ? field.querySelector('label') : null;
    const fieldName = label ? label.textContent.trim() : 'Photo';
    const backdrop = document.createElement('div');
    backdrop.className = 'photo-source-backdrop';
    backdrop.innerHTML = `
        <section class="photo-source-dialog" role="dialog" aria-modal="true" aria-labelledby="photo-source-title">
            <div class="photo-source-dialog__body">
                <h2 id="photo-source-title"></h2>
                <p>Choose how you want to add this image.</p>
            </div>
            <div class="photo-source-dialog__actions">
                <button class="secondary-button" type="button" data-photo-upload>
                    <i class="fa-solid fa-upload" aria-hidden="true"></i>
                    <span>Upload image</span>
                </button>
                <button class="login-button" type="button" data-photo-camera>
                    <i class="fa-solid fa-camera" aria-hidden="true"></i>
                    <span>Take picture</span>
                </button>
            </div>
            <button class="photo-source-dialog__cancel" type="button" data-photo-cancel>Cancel</button>
        </section>
    `;

    backdrop.querySelector('h2').textContent = fieldName;

    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };

    const openFileChooser = (useCamera) => {
        const originalAccept = input.dataset.photoOriginalAccept || input.getAttribute('accept') || '';

        if (useCamera) {
            input.setAttribute('accept', 'image/*');
            input.setAttribute('capture', 'environment');
        } else {
            input.setAttribute('accept', originalAccept);
            input.removeAttribute('capture');
        }

        const restoreInput = () => {
            input.setAttribute('accept', originalAccept);
            input.removeAttribute('capture');
            input.removeEventListener('change', restoreInput);
        };

        if (useCamera) {
            input.addEventListener('change', restoreInput, { once: true });
        }

        input.dataset.photoChooserOpening = 'true';
        close();
        input.click();
        window.setTimeout(() => {
            delete input.dataset.photoChooserOpening;
        }, 0);
    };

    function escapeHandler(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });

    backdrop.querySelector('[data-photo-upload]').addEventListener('click', () => {
        openFileChooser(false);
    });

    backdrop.querySelector('[data-photo-camera]').addEventListener('click', () => {
        openFileChooser(true);
    });

    backdrop.querySelector('[data-photo-cancel]').addEventListener('click', close);
    document.addEventListener('keydown', escapeHandler);
    document.body.appendChild(backdrop);
    backdrop.querySelector('[data-photo-camera]').focus();
}

function setupVisitFormEmptySelects() {
    document.querySelectorAll('.visit-registration-form select, .mobile-line-form select').forEach((select) => {
        const syncEmptyState = () => {
            select.classList.toggle('is-empty-select', select.value === '');
        };

        select.addEventListener('change', syncEmptyState);
        syncEmptyState();
    });
}

function setupTripDistanceCalculator() {
    const startInput = document.querySelector('[data-trip-start-km]');
    const endInput = document.querySelector('[data-trip-end-km]');
    const output = document.querySelector('[data-trip-distance]');

    if (!endInput || !output) {
        return;
    }

    const updateDistance = () => {
        const startValue = output.dataset.tripStartValue || (startInput ? startInput.value : '');
        const start = Number.parseFloat(startValue);
        const end = Number.parseFloat(endInput.value);

        if (Number.isNaN(start) || Number.isNaN(end) || end < start) {
            output.textContent = '0.00 km';
            return;
        }

        output.textContent = `${(end - start).toFixed(2)} km`;
    };

    if (startInput) {
        startInput.addEventListener('input', updateDistance);
    }

    endInput.addEventListener('input', updateDistance);
    updateDistance();
}

function setupRegionTownLookup() {
    document.querySelectorAll('[data-region-select]').forEach((regionSelect) => {
        const scope = regionSelect.closest('form') || regionSelect.closest('section') || document;
        const districtSelect = scope.querySelector('[data-district-select]');
        const townSelect = scope.querySelector('[data-town-select]');
        const districtOptions = districtSelect ? Array.from(districtSelect.options) : [];
        const townOptions = townSelect ? Array.from(townSelect.options) : [];

        const filterDistricts = () => {
            if (!districtSelect) return;
            const regionId = regionSelect.value;
            districtOptions.forEach((option) => {
                if (option.value === '') { option.hidden = false; option.disabled = false; return; }
                const isMatch = regionId !== '' && option.dataset.regionId === regionId;
                option.hidden = !isMatch; option.disabled = !isMatch;
            });
            const selected = districtSelect.selectedOptions[0];
            if (selected && selected.disabled) {
                districtSelect.value = '';
                districtSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };
        const filterTowns = () => {
            if (!townSelect) return;
            const regionId = regionSelect.value;
            const districtId = districtSelect ? districtSelect.value : '';
            townOptions.forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                const matchesRegion = regionId !== '' && option.dataset.regionId === regionId;
                const matchesDistrict = districtId === '' || option.dataset.districtId === districtId;
                const isMatch = matchesRegion && matchesDistrict;
                option.hidden = !isMatch;
                option.disabled = !isMatch;
            });

            const selectedOption = townSelect.selectedOptions[0];

            if (selectedOption && selectedOption.disabled) {
                townSelect.value = '';
                townSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        regionSelect.addEventListener('change', () => {
            if (districtSelect) {
                districtSelect.value = '';
                districtSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
            filterDistricts();
            filterTowns();
        });
        if (districtSelect) districtSelect.addEventListener('change', filterTowns);
        if (districtSelect && townSelect) {
            townSelect.addEventListener('change', () => {
                const town = townSelect.selectedOptions[0];
                const districtId = town?.dataset.districtId || '';

                if (!town || town.value === '') {
                    return;
                }

                const matchingDistrict = districtOptions.find((option) => option.value === districtId);
                districtSelect.value = matchingDistrict && !matchingDistrict.disabled ? districtId : '';
                districtSelect.dispatchEvent(new Event('change', { bubbles: true }));
                updateLookupButton(districtSelect);
            });
        }
        filterDistricts();
        filterTowns();
    });
}

function setupVendorTownPickers() {
    document.querySelectorAll('[data-town-assignment-list]').forEach((list) => {
        const picker = list.closest('.vendor-town-picker');
        const region = picker?.querySelector('[data-town-assignment-region]');
        const district = picker?.querySelector('[data-town-assignment-district]');
        const town = picker?.querySelector('[data-town-assignment-town]');
        const count = picker?.querySelector('[data-town-assignment-count]');
        const empty = picker?.querySelector('[data-town-assignment-empty]');
        const items = Array.from(list.querySelectorAll('[data-town-assignment-item]'));

        const refreshCount = () => {
            const selected = items.filter((item) => item.querySelector('input[type="checkbox"]')?.checked).length;
            if (count) count.textContent = `${selected} selected`;
        };

        const filter = () => {
            const regionId = region?.value || '';
            const districtId = district?.value || '';
            const townId = town?.value || '';
            let visible = 0;
            items.forEach((item) => {
                const matches = (!regionId || item.dataset.regionId === regionId)
                    && (!districtId || item.dataset.districtId === districtId)
                    && (!townId || item.dataset.townId === townId);
                item.classList.toggle('is-hidden', !matches);
                if (matches) visible += 1;
            });
            if (empty) empty.classList.toggle('is-hidden', visible > 0);
        };

        const districtOptions = Array.from(district?.options || []).slice(1);
        const townOptions = Array.from(town?.options || []).slice(1);
        region?.addEventListener('change', () => {
            district.value = '';
            town.value = '';
            districtOptions.forEach((option) => { option.hidden = option.dataset.regionId !== region.value; });
            townOptions.forEach((option) => { option.hidden = option.dataset.regionId !== region.value; });
            district.disabled = !region.value;
            town.disabled = !region.value;
            filter();
        });
        district?.addEventListener('change', () => {
            town.value = '';
            townOptions.forEach((option) => {
                option.hidden = option.dataset.regionId !== region.value || (!!district.value && option.dataset.districtId !== district.value);
            });
            filter();
        });
        town?.addEventListener('change', filter);
        items.forEach((item) => item.querySelector('input[type="checkbox"]')?.addEventListener('change', refreshCount));
        refreshCount();
    });
}

function setupReportFilters() {
    document.querySelectorAll('[data-report-filter-form]').forEach((form) => {
        const searchInput = form.querySelector('[data-report-search]');
        const toggle = form.querySelector('[data-report-filter-toggle]');
        const panel = form.querySelector('[data-report-filter-panel]');
        const clearButton = form.querySelector('[data-report-filter-clear]');
        const selects = Array.from(form.querySelectorAll('[data-report-lookup]'));
        const dateInputs = Array.from(form.querySelectorAll('[data-report-date-filter]'));
        const query = new URLSearchParams(window.location.search);

        if (searchInput) {
            searchInput.value = query.get('q') || '';
        }
        selects.forEach((select) => {
            if (query.has(select.name)) {
                select.value = query.get(select.name) || '';
            }
        });
        dateInputs.forEach((input) => {
            input.value = query.get(input.name) || '';
        });

        const setPanelOpen = (isOpen) => {
            if (!toggle || !panel) {
                return;
            }

            panel.classList.toggle('is-hidden', !isOpen);
            toggle.classList.toggle('is-open', isOpen || hasReportLookupValue(form));
            toggle.setAttribute('aria-expanded', String(isOpen));
        };

        const syncState = () => {
            const hasValue = hasReportFilterValue(form);
            if (clearButton) {
                clearButton.classList.toggle('is-hidden', !hasValue);
            }

            if (toggle && panel) {
                toggle.classList.toggle('is-open', !panel.classList.contains('is-hidden') || hasReportLookupValue(form));
            }

            updateReportLookupButtons(form);
            applyReportLiveFilters(form);

            const url = new URL(window.location.href);
            const filterValues = {
                q: searchInput?.value.trim() || '',
                location_id: form.querySelector('[name="location_id"]')?.value || '',
                date_from: form.querySelector('[name="date_from"]')?.value || '',
                date_to: form.querySelector('[name="date_to"]')?.value || '',
            };
            if (form.querySelector('[name="destination_id"]')) {
                filterValues.destination_id = form.querySelector('[name="destination_id"]').value;
            }
            Object.entries(filterValues).forEach(([name, value]) => {
                if (value) url.searchParams.set(name, value);
                else if (name !== 'destination_id' || form.querySelector('[name="destination_id"]')) url.searchParams.delete(name);
            });
            window.history.replaceState({}, '', `${url.pathname}${url.search}`);
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            syncState();
        });

        if (toggle && panel) {
            toggle.addEventListener('click', () => {
                setPanelOpen(panel.classList.contains('is-hidden'));
            });
        }

        if (clearButton) {
            clearButton.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                }

                selects.forEach((select) => {
                    select.value = '';
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });

                dateInputs.forEach((input) => {
                    input.value = '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });

                setPanelOpen(false);
                syncState();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', syncState);
        }

        selects.forEach((select) => {
            createReportLookupButton(select);
            select.addEventListener('change', syncState);
        });

        dateInputs.forEach((input) => {
            input.addEventListener('input', syncState);
            input.addEventListener('change', syncState);
        });

        if (toggle && panel) {
            setPanelOpen(hasReportLookupValue(form) || !panel.classList.contains('is-hidden'));
        }

        syncState();
    });
}

function setupReportModePanels() {
    document.querySelectorAll('[data-report-mode-form]').forEach((form) => {
        const buttons = Array.from(form.querySelectorAll('[data-report-mode-toggle]'));
        const panels = Array.from(form.querySelectorAll('[data-report-mode-panel]'));

        if (buttons.length === 0 || panels.length === 0) {
            return;
        }

        const showMode = (mode) => {
            buttons.forEach((button) => {
                button.classList.toggle('is-active', button.dataset.reportModeToggle === mode);
            });

            panels.forEach((panel) => {
                panel.classList.toggle('is-hidden', panel.dataset.reportModePanel !== mode);
            });

            if (mode === 'lookup') {
                const filterPanel = form.querySelector('[data-report-filter-panel]');
                const filterToggle = form.querySelector('[data-report-filter-toggle]');

                if (filterPanel && filterToggle) {
                    filterPanel.classList.remove('is-hidden');
                    filterToggle.classList.add('is-open');
                    filterToggle.setAttribute('aria-expanded', 'true');
                }
            }
        };

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                showMode(button.dataset.reportModeToggle || '');
            });
        });

        const searchInput = form.querySelector('[data-report-search]');
        const hasSearch = searchInput && searchInput.value.trim() !== '';

        if (hasSearch) {
            showMode('type');
            return;
        }

        if (hasReportLookupValue(form)) {
            showMode('lookup');
        }
    });
}

function setupFormLookupSelects() {
    document.querySelectorAll('.visit-registration-form select, .mobile-line-form select, select[data-popup-select]').forEach((select) => {
        createLookupButton(select, {
            buttonClass: 'form-lookup-button',
            emptyText: select.hasAttribute('data-popup-select')
                ? (select.options[0]?.textContent.trim() || '')
                : '',
        });
        select.addEventListener('change', () => updateLookupButton(select));
    });
}

function hasReportFilterValue(form) {
    const searchInput = form.querySelector('[data-report-search]');
    const hasSearch = searchInput && searchInput.value.trim() !== '';
    const hasDate = Array.from(form.querySelectorAll('[data-report-date-filter]')).some((input) => input.value !== '');

    return Boolean(hasSearch || hasReportLookupValue(form) || hasDate);
}

function hasReportLookupValue(form) {
    return Array.from(form.querySelectorAll('[data-report-lookup]')).some((select) => select.value !== '');
}

function createReportLookupButton(select) {
    createLookupButton(select, {
        buttonClass: 'report-lookup-button',
        emptyText: null,
    });
}

function updateReportLookupButtons(scope) {
    scope.querySelectorAll('[data-report-lookup]').forEach(updateLookupButton);
}

function createLookupButton(select, options = {}) {
    if (select.dataset.lookupReady === 'true') {
        updateLookupButton(select);
        return;
    }

    const buttonId = select.id || `lookup-${Math.random().toString(16).slice(2)}`;
    const buttonClass = options.buttonClass || 'form-lookup-button';

    if (!select.id) {
        select.id = buttonId;
    }

    select.dataset.lookupReady = 'true';
    select.dataset.lookupEmptyText = options.emptyText === null ? '__auto__' : (options.emptyText || '');
    select.hidden = true;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = buttonClass;
    button.dataset.lookupButton = select.id;
    button.innerHTML = '<span></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>';
    const popupReadyAt = performance.now() + Math.max(0, Number(select.dataset.popupOpenDelay || 0));
    button.addEventListener('click', () => {
        if (performance.now() < popupReadyAt) return;
        const accordion = select.closest('details.registration-accordion');
        if (accordion && !accordion.open) return;
        openLookupDialog(select);
    });
    select.insertAdjacentElement('afterend', button);

    // Required lookup selects are hidden after enhancement. Native browser
    // validation cannot focus a hidden invalid select, which makes form
    // submission appear to do nothing. Send the user to the visible lookup
    // control and open its choices instead.
    select.addEventListener('invalid', (event) => {
        event.preventDefault();
        button.classList.add('is-invalid');
        button.setAttribute('aria-invalid', 'true');
        button.focus();
        if (select.closest('[data-standalone-customer-form]')) return;
        openLookupDialog(select);
    });

    select.addEventListener('change', () => {
        if (select.checkValidity()) {
            button.classList.remove('is-invalid');
            button.removeAttribute('aria-invalid');
        }
    });

    updateLookupButton(select);
}

function updateLookupButton(select) {
    const button = document.querySelector(`[data-lookup-button="${select.id}"]`);

    if (!button) {
        return;
    }

    const label = select.closest('.form-field')?.querySelector('label')?.textContent.trim() || 'Select';
    const selectedOption = select.selectedOptions[0];
    const hasValue = selectedOption && selectedOption.value !== '';
    const emptyText = select.dataset.lookupEmptyText === '__auto__' ? `All ${label.toLowerCase()}s` : (select.dataset.lookupEmptyText || '');
    const text = hasValue ? selectedOption.textContent.trim() : emptyText;
    const textNode = button.querySelector('span');
    const isCapitalTown = hasValue && selectedOption.dataset.isCapital === '1';
    textNode.textContent = isCapitalTown ? text.replace(/\s*\*\s*$/, '') : text;
    if (isCapitalTown) {
        const marker = document.createElement('span');
        marker.className = 'capital-town-marker';
        marker.textContent = '*';
        marker.setAttribute('aria-label', 'Capital town');
        textNode.append(' ', marker);
    }
    button.classList.toggle('is-empty', !hasValue);
}

function openReportLookup(select) {
    openLookupDialog(select);
}

function openLookupDialog(select) {
    const hideEmpty = select.hasAttribute('data-popup-hide-empty');
    const options = Array.from(select.options).filter((option) => !option.hidden && !option.disabled && (!hideEmpty || option.value !== ''));
    const searchable = select.hasAttribute('data-popup-search');
    const fieldLabel = select.closest('.form-field')?.querySelector('label')?.textContent.trim() || 'Choose';
    const existingDialog = document.querySelector('.report-lookup-backdrop');

    if (existingDialog) {
        existingDialog.remove();
    }

    const backdrop = document.createElement('div');
    backdrop.className = 'report-lookup-backdrop';
    backdrop.innerHTML = `
        <section class="report-lookup-dialog" role="dialog" aria-modal="true" aria-labelledby="report-lookup-title">
            <div class="report-lookup-dialog__header">
                <h2 id="report-lookup-title"></h2>
                <button class="report-lookup-dialog__close" type="button" data-report-lookup-close aria-label="Close lookup">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            ${searchable ? '<label class="report-lookup-dialog__search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span class="sr-only">Search options</span><input type="search" placeholder="Type to search..." autocomplete="off" data-lookup-search></label>' : ''}
            <div class="report-lookup-dialog__list" role="listbox"></div>
        </section>
    `;

    backdrop.querySelector('h2').textContent = fieldLabel;
    const list = backdrop.querySelector('.report-lookup-dialog__list');

    if (options.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'empty-state';
        empty.textContent = select.dataset.popupEmptyText || 'No options are available. Select the preceding field first or contact an administrator.';
        list.appendChild(empty);
    }

    options.forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'report-lookup-dialog__option';
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', String(option.selected));
        button.dataset.searchText = option.textContent.trim().toLowerCase();
        const optionText = option.textContent.trim();
        const isCapitalTown = option.dataset.isCapital === '1';
        button.textContent = isCapitalTown ? optionText.replace(/\s*\*\s*$/, '') : optionText;
        if (isCapitalTown) {
            const marker = document.createElement('span');
            marker.className = 'capital-town-marker';
            marker.textContent = '*';
            marker.setAttribute('aria-label', 'Capital town');
            button.append(' ', marker);
        }

        if (option.selected) {
            button.classList.add('is-selected');
        }

        button.addEventListener('click', () => {
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        });

        list.appendChild(button);
    });

    const searchInput = backdrop.querySelector('[data-lookup-search]');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const terms = searchInput.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            list.querySelectorAll('.report-lookup-dialog__option').forEach((button) => {
                button.hidden = !terms.every((term) => button.dataset.searchText.includes(term));
            });
        });
    }

    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };

    function escapeHandler(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });

    backdrop.querySelector('[data-report-lookup-close]').addEventListener('click', close);
    document.addEventListener('keydown', escapeHandler);
    document.body.appendChild(backdrop);
    (searchInput || backdrop.querySelector('.report-lookup-dialog__option.is-selected') || backdrop.querySelector('[data-report-lookup-close]')).focus();
}

function applyReportLiveFilters(form) {
    const reportPanel = form.closest('[data-report-listing]') || form.closest('.management-panel') || document;
    const resultItems = Array.from(reportPanel.querySelectorAll('[data-report-filter-item]'));
    const count = reportPanel.querySelector('[data-report-results-count]');
    const emptyState = reportPanel.querySelector('[data-report-empty-state]');
    const resultsContainer = reportPanel.querySelector('[data-report-results]');

    if (resultItems.length === 0 && !count) {
        return;
    }

    const search = (form.querySelector('[data-report-search]')?.value || '').trim().toLowerCase();
    const destinationId = form.querySelector('[name="destination_id"]')?.value || '';
    const locationId = form.querySelector('[name="location_id"]')?.value || '';
    const dateFrom = form.querySelector('[name="date_from"]')?.value || '';
    const dateTo = form.querySelector('[name="date_to"]')?.value || '';
    const requiresFilter = form.hasAttribute('data-report-require-filter');
    const hasActiveFilter = hasReportFilterValue(form);
    let visibleCount = 0;

    resultItems.forEach((item) => {
        const itemDate = item.dataset.reportDate || '';
        const matchesSearch = search === '' || (item.dataset.reportSearch || '').toLowerCase().includes(search);
        const matchesDestination = destinationId === '' || item.dataset.destinationId === destinationId;
        const matchesLocation = locationId === '' || item.dataset.locationId === locationId || item.dataset.townId === locationId;
        const matchesDateFrom = dateFrom === '' || (itemDate !== '' && itemDate >= dateFrom);
        const matchesDateTo = dateTo === '' || (itemDate !== '' && itemDate <= dateTo);
        const isVisible = (!requiresFilter || hasActiveFilter) && matchesSearch && matchesDestination && matchesLocation && matchesDateFrom && matchesDateTo;

        item.classList.toggle('is-hidden', !isVisible);

        if (isVisible) {
            visibleCount++;
        }
    });

    if (count) {
        count.textContent = `${visibleCount.toLocaleString()} ${visibleCount === 1 ? 'result' : 'results'} found`;
    }

    if (resultsContainer) {
        resultsContainer.classList.toggle('is-hidden', requiresFilter && !hasActiveFilter);
    }

    if (emptyState) {
        const showInitialMessage = requiresFilter && !hasActiveFilter;
        emptyState.textContent = showInitialMessage
            ? (emptyState.dataset.initialMessage || 'Search or select a filter to view records.')
            : (emptyState.dataset.noMatchMessage || 'No records match these filters.');
        emptyState.classList.toggle('is-hidden', visibleCount > 0);
    }
}

function setupSalesVisitTypeToggle() {
    const visitType = document.querySelector('[data-visit-type]');
    const destinationSelect = document.querySelector('[data-destination-select]');
    const modeFields = document.querySelectorAll('[data-visit-mode]');

    if ((!visitType && !destinationSelect) || modeFields.length === 0) {
        return;
    }

    const syncFields = () => {
        const selectedDestination = destinationSelect ? destinationSelect.selectedOptions[0] : null;
        const destinationMode = selectedDestination ? selectedDestination.dataset.destinationMode || '' : '';
        const activeMode = destinationMode !== '' ? destinationMode : (visitType ? visitType.value || 'registration' : 'registration');

        modeFields.forEach((field) => {
            const modes = (field.dataset.visitMode || '').split(/\s+/).filter(Boolean);
            const isVisible = modes.includes(activeMode);

            field.classList.toggle('is-hidden', !isVisible);

            field.querySelectorAll('input, select, textarea').forEach((control) => {
                if (!control.dataset.originalRequired) {
                    control.dataset.originalRequired = control.required ? 'true' : 'false';
                }

                control.disabled = !isVisible;
                control.required = isVisible && control.dataset.originalRequired === 'true';
            });
        });
    };

    if (visitType) {
        visitType.addEventListener('change', syncFields);
    }

    if (destinationSelect) {
        destinationSelect.addEventListener('change', syncFields);
    }

    syncFields();
}

function setupMediaViewer() {
    document
        .querySelectorAll(
            '.place-detail-media img, .place-detail-customer-card img, .staff-view-profile img, .table-avatar img'
        )
        .forEach((image) => {
            if (!image.hasAttribute('data-media-viewer')) {
                image.dataset.mediaViewer = 'image';
                image.dataset.mediaTitle = image.getAttribute('alt') || 'Picture';
            }
        });

    document.querySelectorAll('[data-media-viewer]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();

            const mediaType = trigger.dataset.mediaViewer || 'image';
            const src =
                trigger.dataset.mediaSrc ||
                trigger.getAttribute('href') ||
                trigger.currentSrc ||
                trigger.getAttribute('src') ||
                '';

            if (src === '') {
                return;
            }

            showMediaViewer({
                type: mediaType,
                src,
                title: trigger.dataset.mediaTitle || 'Media preview',
            });
        });
    });
}

function setupFollowupMethodDialogs() {
    document.querySelectorAll('[data-followup-method-open]').forEach((button) => {
        button.addEventListener('click', () => {
            showFollowupMethodDialog({
                name: button.dataset.recordName || 'this registration',
                phoneUrl: button.dataset.phoneUrl || '',
                visitUrl: button.dataset.visitUrl || '',
                hasActiveTrip: button.closest('[data-followup-registration-listing]')?.dataset.hasActiveTrip === 'true',
            });
        });
    });
}

function showFollowupMethodDialog({ name, phoneUrl, visitUrl, hasActiveTrip }) {
    document.querySelector('.followup-method-backdrop')?.remove();
    const backdrop = document.createElement('div');
    backdrop.className = 'followup-method-backdrop';
    backdrop.innerHTML = `
        <section class="followup-method-dialog" role="dialog" aria-modal="true" aria-labelledby="followup-method-title">
            <div class="followup-method-dialog__header">
                <div><span>Follow-up method</span><h2 id="followup-method-title"></h2></div>
                <button class="modal-close" type="button" data-followup-method-close aria-label="Close method selection"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p>How was this follow-up completed?</p>
            <div class="followup-method-options">
                <a class="followup-method-option" data-phone-option><i class="fa-solid fa-phone"></i><span><strong>Phone Call</strong><small>Record a call without starting a marketing trip.</small></span><i class="fa-solid fa-arrow-right"></i></a>
                <a class="followup-method-option" data-visit-option><i class="fa-solid fa-location-dot"></i><span><strong>Physical Visit</strong><small>Record arrival and departure during an active trip.</small></span><i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </section>`;
    backdrop.querySelector('h2').textContent = name;
    backdrop.querySelector('[data-phone-option]').href = phoneUrl;
    const visitOption = backdrop.querySelector('[data-visit-option]');
    visitOption.href = visitUrl;
    visitOption.addEventListener('click', (event) => {
        if (!hasActiveTrip) {
            event.preventDefault();
            showNoticeDialog({
                title: 'No active marketing trip',
                message: 'Start a marketing trip before recording a physical visit.',
            });
        }
    });

    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };
    function escapeHandler(event) {
        if (event.key === 'Escape') close();
    }
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) close();
    });
    backdrop.querySelector('[data-followup-method-close]').addEventListener('click', close);
    document.addEventListener('keydown', escapeHandler);
    document.body.appendChild(backdrop);
    backdrop.querySelector('[data-followup-method-close]').focus();
}

function setupFeedbackViewDialogs() {
    document.querySelectorAll('[data-feedback-view]').forEach((button) => {
        button.addEventListener('click', () => {
            showFeedbackViewDialog({
                name: button.dataset.feedbackName || '',
                number: button.dataset.feedbackNumber || '',
                feedback: button.dataset.feedbackText || '',
                note: button.dataset.feedbackNote || '',
                date: button.dataset.feedbackDate || '',
                staff: button.dataset.feedbackStaff || '',
                destination: button.dataset.feedbackDestination || '',
            });
        });
    });
}

function showFeedbackViewDialog({ name, number, feedback, note, date, staff, destination }) {
    const existingDialog = document.querySelector('.feedback-view-backdrop');

    if (existingDialog) {
        existingDialog.remove();
    }

    const backdrop = document.createElement('div');
    backdrop.className = 'feedback-view-backdrop';
    backdrop.innerHTML = `
        <section class="feedback-view-dialog" role="dialog" aria-modal="true" aria-labelledby="feedback-view-title">
            <div class="feedback-view-dialog__header">
                <div>
                    <h2 id="feedback-view-title"></h2>
                    <p data-feedback-view-number></p>
                </div>
                <button class="modal-close" type="button" data-feedback-view-close aria-label="Close feedback">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <dl class="feedback-view-dialog__body">
                <div>
                    <dt>Feedback</dt>
                    <dd data-feedback-view-text></dd>
                </div>
                <div>
                    <dt>Note</dt>
                    <dd data-feedback-view-note></dd>
                </div>
                <div>
                    <dt>Date</dt>
                    <dd data-feedback-view-date></dd>
                </div>
                <div>
                    <dt>Recorded by</dt>
                    <dd data-feedback-view-staff></dd>
                </div>
                <div>
                    <dt>Destination</dt>
                    <dd data-feedback-view-destination></dd>
                </div>
            </dl>
        </section>
    `;

    backdrop.querySelector('h2').textContent = name || 'Feedback';
    backdrop.querySelector('[data-feedback-view-number]').textContent = number;
    backdrop.querySelector('[data-feedback-view-text]').textContent = feedback || 'No feedback';
    backdrop.querySelector('[data-feedback-view-note]').textContent = note || 'No note';
    backdrop.querySelector('[data-feedback-view-date]').textContent = date || 'Not available';
    backdrop.querySelector('[data-feedback-view-staff]').textContent = staff || 'Unknown staff';
    backdrop.querySelector('[data-feedback-view-destination]').textContent = destination || 'Not available';

    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };

    function escapeHandler(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });

    backdrop.querySelector('[data-feedback-view-close]').addEventListener('click', close);
    document.addEventListener('keydown', escapeHandler);
    document.body.appendChild(backdrop);
    backdrop.querySelector('[data-feedback-view-close]').focus();
}

function showMediaViewer({ type, src, title }) {
    const existingViewer = document.querySelector('.media-viewer-backdrop');

    if (existingViewer) {
        existingViewer.remove();
    }

    const backdrop = document.createElement('div');
    backdrop.className = 'media-viewer-backdrop';
    backdrop.innerHTML = `
        <section class="media-viewer" role="dialog" aria-modal="true" aria-labelledby="media-viewer-title">
            <div class="media-viewer__header">
                <h2 id="media-viewer-title"></h2>
                <button class="modal-close" type="button" data-media-viewer-close aria-label="Close media preview">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <div class="media-viewer__body"></div>
        </section>
    `;

    backdrop.querySelector('h2').textContent = title;
    const body = backdrop.querySelector('.media-viewer__body');
    const isVideo = type === 'video';

    if (isVideo) {
        const video = document.createElement('video');
        video.controls = true;
        video.autoplay = true;
        video.playsInline = true;
        video.preload = 'metadata';

        const source = document.createElement('source');
        source.src = src;
        video.appendChild(source);
        body.appendChild(video);
    } else {
        const image = document.createElement('img');
        image.src = src;
        image.alt = title;
        body.appendChild(image);
    }

    let isClosed = false;
    const close = () => {
        if (isClosed) {
            return;
        }

        isClosed = true;
        backdrop.querySelectorAll('video').forEach((video) => {
            video.pause();
            video.removeAttribute('src');
            video.load();
        });
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };

    function escapeHandler(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });

    backdrop.querySelector('[data-media-viewer-close]').addEventListener('click', close);
    document.addEventListener('keydown', escapeHandler);

    document.body.appendChild(backdrop);
    backdrop.querySelector('[data-media-viewer-close]').focus();
}

function setupFilterToggles() {
    document.querySelectorAll('[data-filter-toggle]').forEach((toggle) => {
        const panelId = toggle.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : toggle.closest('form')?.querySelector('[data-filter-panel]');

        if (!panel) {
            return;
        }

        const setOpen = (isOpen) => {
            panel.classList.toggle('is-hidden', !isOpen);
            toggle.classList.toggle('is-open', isOpen);
            toggle.setAttribute('aria-expanded', String(isOpen));
        };

        setOpen(toggle.getAttribute('aria-expanded') === 'true' && !panel.classList.contains('is-hidden'));

        toggle.addEventListener('click', () => {
            setOpen(panel.classList.contains('is-hidden'));
        });
    });
}

function setupLiveFilters() {
    document.querySelectorAll('[data-live-filter-scope]').forEach((scope) => {
        const form = scope.querySelector('[data-live-filter-form]');
        const items = Array.from(scope.querySelectorAll('[data-live-filter-item]'));
        const count = scope.querySelector('[data-live-filter-count]');
        const empty = scope.querySelector('[data-live-filter-empty]');
        const reset = scope.querySelector('[data-live-filter-reset]');

        if (!form || items.length === 0) {
            return;
        }

        const controls = Array.from(form.querySelectorAll('input, select'));
        const filter = () => {
            const values = {};

            controls.forEach((control) => {
                if (!control.name) {
                    return;
                }

                values[control.name] = control.value.trim().toLowerCase();
            });

            const requiresFilter = scope.hasAttribute('data-live-filter-require-filter');
            const hasActiveFilter = Object.values(values).some((value) => value !== '');

            let visibleCount = 0;

            items.forEach((item) => {
                const showDefault = requiresFilter && !hasActiveFilter && item.dataset.liveFilterDefaultVisible === 'true';
                const isVisible = (showDefault || (!requiresFilter || hasActiveFilter)) && liveFilterItemMatches(item, values);
                item.classList.toggle('is-hidden', !isVisible);

                if (isVisible) {
                    visibleCount++;
                }
            });

            if (count) {
                count.textContent = requiresFilter && !hasActiveFilter
                    ? `Showing ${visibleCount.toLocaleString()} current ${visibleCount === 1 ? 'trip' : 'trips'}`
                    : `Showing ${visibleCount.toLocaleString()} ${visibleCount === 1 ? 'trip' : 'trips'}`;
            }

            if (empty) {
                empty.textContent = requiresFilter && !hasActiveFilter
                    ? (empty.dataset.liveFilterInitialMessage || 'Search or select a filter to view records.')
                    : (empty.dataset.liveFilterNoMatchMessage || 'No records match these filters.');
                empty.classList.toggle('is-hidden', visibleCount > 0);
            }
        };

        controls.forEach((control) => {
            control.addEventListener('input', filter);
            control.addEventListener('change', filter);
        });

        if (reset) {
            reset.addEventListener('click', () => {
                form.reset();
                filter();
            });
        }

        filter();
    });
}

function liveFilterItemMatches(item, values) {
    const search = values.search || '';

    if (search !== '' && !(item.dataset.filterText || '').includes(search)) {
        return false;
    }

    const itemDate = item.dataset.filterDate || '';

    if ((values.date_from || '') !== '' && itemDate < values.date_from) {
        return false;
    }

    if ((values.date_to || '') !== '' && itemDate > values.date_to) {
        return false;
    }

    return Object.entries(values).every(([name, value]) => {
        if (value === '' || ['search', 'date_from', 'date_to'].includes(name)) {
            return true;
        }

        const dataKey = `filter${name
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join('')}`;
        const itemValue = item.dataset[dataKey] || '';
        const itemParts = itemValue.split(/\s+/).filter(Boolean);

        return itemValue === value || itemParts.includes(value);
    });
}

function showConfirmDialog({ title, message, onConfirm, confirmLabel = 'Delete' }) {
    const existingDialog = document.querySelector('.confirm-backdrop');

    if (existingDialog) {
        existingDialog.remove();
    }

    const backdrop = document.createElement('div');
    backdrop.className = 'confirm-backdrop';
    backdrop.innerHTML = `
        <section class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
            <div class="confirm-dialog__icon" aria-hidden="true">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="confirm-dialog__body">
                <h2 id="confirm-title"></h2>
                <p></p>
            </div>
            <div class="confirm-dialog__actions">
                <button class="secondary-button" type="button" data-confirm-cancel>Cancel</button>
                <button class="danger-button" type="button" data-confirm-delete>Delete</button>
            </div>
        </section>
    `;

    backdrop.querySelector('h2').textContent = title;
    backdrop.querySelector('p').textContent = message;
    backdrop.querySelector('[data-confirm-delete]').textContent = confirmLabel;

    const close = () => backdrop.remove();

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            close();
        }
    });

    backdrop.querySelector('[data-confirm-cancel]').addEventListener('click', close);
    backdrop.querySelector('[data-confirm-delete]').addEventListener('click', () => {
        close();
        onConfirm();
    });

    document.addEventListener('keydown', function escapeHandler(event) {
        if (event.key === 'Escape') {
            close();
            document.removeEventListener('keydown', escapeHandler);
        }
    });

    document.body.appendChild(backdrop);
    backdrop.querySelector('[data-confirm-cancel]').focus();
}

function showNoticeDialog({ title, message, kind = '' }) {
    document.querySelector('.notice-backdrop')?.remove();
    const backdrop = document.createElement('div');
    backdrop.className = `confirm-backdrop notice-backdrop${kind ? ` notice-backdrop--${kind}` : ''}`;
    backdrop.innerHTML = `
        <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="notice-title">
            <div class="confirm-dialog__icon" aria-hidden="true"><i class="fa-solid ${kind === 'note' ? 'fa-note-sticky' : 'fa-triangle-exclamation'}"></i></div>
            <div class="confirm-dialog__body"><h2 id="notice-title"></h2><p></p></div>
            <div class="confirm-dialog__actions"><button class="login-button" type="button" data-notice-close>OK</button></div>
        </section>`;
    backdrop.querySelector('h2').textContent = title;
    backdrop.querySelector('p').textContent = message;

    const close = () => {
        backdrop.remove();
        document.removeEventListener('keydown', escapeHandler);
    };
    function escapeHandler(event) {
        if (event.key === 'Escape') close();
    }
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) close();
    });
    backdrop.querySelector('[data-notice-close]').addEventListener('click', close);
    document.addEventListener('keydown', escapeHandler);
    document.body.appendChild(backdrop);
    backdrop.querySelector('[data-notice-close]').focus();
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const field = button.closest('.profile-password-field');
        const input = field?.querySelector('input');
        const icon = button.querySelector('i');

        if (!input) return;

        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-label', `${showPassword ? 'Hide' : 'Show'} ${input.labels?.[0]?.textContent?.toLowerCase() || 'password'}`);
        icon?.classList.toggle('fa-eye', !showPassword);
        icon?.classList.toggle('fa-eye-slash', showPassword);
    });
});

document.querySelectorAll('[data-place-choice-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('[data-place-choice-modal]')?.remove());
});

document.querySelectorAll('[data-place-choice-modal]').forEach((modal) => {
    modal.addEventListener('click', (event) => { if (event.target === modal) modal.remove(); });
    document.addEventListener('keydown', function closePlaceChoice(event) {
        if (event.key === 'Escape' && modal.isConnected) {
            modal.remove();
            document.removeEventListener('keydown', closePlaceChoice);
        }
    });
});

const smartBackStorageKey = (url) => `spw:back:${url.pathname}${url.search}`;
const isTransientBackTarget = (url) => (
    /\/(?:[^/]*-edit|registration-edit|visit-edit|trip-edit|vendor-customer-edit|place-details|normalized-visit-details|legacy-customer-location)\.php$/i.test(url.pathname)
    || url.searchParams.has('edit')
    || (/\/vendor-setup\.php$/i.test(url.pathname) && url.searchParams.has('view'))
);

document.addEventListener('click', (event) => {
    const anchor = event.target.closest?.('a[href]');
    if (!anchor || anchor.dataset.smartBackBound === 'true' || anchor.target === '_blank' || anchor.hasAttribute('download')) return;
    try {
        const destination = new URL(anchor.href, window.location.href);
        const source = new URL(window.location.href);
        if (destination.origin !== window.location.origin || destination.href === source.href || isTransientBackTarget(destination) || isTransientBackTarget(source)) return;
        sessionStorage.setItem(smartBackStorageKey(destination), window.location.href);
    } catch (_) {
        // Existing link behaviour remains the fallback when storage is unavailable.
    }
}, true);

const smartBackLinks = [...document.querySelectorAll(
    '[data-history-back], a.secondary-button, a.flow-back-link, .registration-records-back a, .sales-page-back a'
)].filter((link) => (
    link.matches('[data-history-back]')
    || !!link.querySelector('.fa-arrow-left')
    || /^back\b/i.test((link.textContent || '').trim())
));

smartBackLinks.forEach((link) => {
    link.dataset.smartBackBound = 'true';
    link.addEventListener('click', (event) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const current = new URL(window.location.href);
        try {
            const storedTarget = sessionStorage.getItem(smartBackStorageKey(current));
            const storedUrl = storedTarget ? new URL(storedTarget) : null;
            if (storedUrl && storedUrl.origin === current.origin && storedUrl.href !== current.href && !isTransientBackTarget(storedUrl)) {
                event.preventDefault();
                window.location.href = storedUrl.href;
            }
        } catch (_) {
            // Use the link's original href when browser storage is unavailable.
        }
    });
});

document.querySelectorAll('[data-note-view]').forEach((button) => {
    button.addEventListener('click', () => {
        showNoticeDialog({ title: 'Customer note', message: button.dataset.noteText || 'No note recorded.', kind: 'note' });
    });
});
