document.addEventListener('DOMContentLoaded', () => {
    setupAttendanceSearch();
    setupAttendanceLocationAdmin();
    setupAttendanceSelfMark();
});

function attendanceToken() {
    return (window.attendanceConfig && window.attendanceConfig.csrfToken) || '';
}

function attendanceSetMessage(element, message, type = '') {
    if (!element) {
        return;
    }

    element.textContent = message;
    element.className = 'attendance-live-message';

    if (type) {
        element.classList.add(type);
    }
}

function attendanceEscape(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function setupAttendanceSearch() {
    const searchInput = document.querySelector('[data-attendance-search]');
    const serviceInput = document.getElementById('attendanceServiceId');
    const resultsBody = document.getElementById('attendanceResults');
    const messageBox = document.getElementById('attendanceMessage');

    if (!searchInput || !serviceInput || !resultsBody) {
        return;
    }

    let searchTimer = null;

    const renderEmpty = (message) => {
        resultsBody.innerHTML = `<tr><td colspan="5" class="empty-state">${attendanceEscape(message)}</td></tr>`;
    };

    const renderRows = (rows) => {
        if (!rows.length) {
            renderEmpty('No matching staff found.');
            return;
        }

        resultsBody.innerHTML = rows.map((staff) => {
            const isMarked = staff.attendance_status === 'present';
            const status = isMarked ? 'Present' : 'Not marked';
            const button = isMarked
                ? '<button class="secondary-button secondary-button--small" type="button" disabled>Marked</button>'
                : `<button class="login-button attendance-mark-button" type="button" data-staff-id="${attendanceEscape(staff.id)}">Mark Present</button>`;

            return `
                <tr>
                    <td>${attendanceEscape(staff.staff_code)}</td>
                    <td>${attendanceEscape(staff.full_name)}</td>
                    <td>${attendanceEscape(staff.phone || staff.email || '')}</td>
                    <td>${status}</td>
                    <td>${button}</td>
                </tr>
            `;
        }).join('');
    };

    const runSearch = async () => {
        const query = searchInput.value.trim();
        const serviceId = serviceInput.value;

        if (query.length < 2) {
            renderEmpty('Search for a staff member to mark attendance.');
            attendanceSetMessage(messageBox, '');
            return;
        }

        attendanceSetMessage(messageBox, 'Searching...');

        try {
            const url = `${searchInput.dataset.searchEndpoint}?q=${encodeURIComponent(query)}&service_id=${encodeURIComponent(serviceId)}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (!data.success) {
                renderEmpty(data.message || 'Staff search failed.');
                attendanceSetMessage(messageBox, data.message || 'Staff search failed.', 'error');
                return;
            }

            renderRows(data.staff || []);
            attendanceSetMessage(messageBox, '');
        } catch (error) {
            renderEmpty('Staff search failed.');
            attendanceSetMessage(messageBox, 'Staff search failed.', 'error');
        }
    };

    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(runSearch, 250);
    });

    resultsBody.addEventListener('click', async (event) => {
        const button = event.target.closest('.attendance-mark-button');

        if (!button) {
            return;
        }

        button.disabled = true;
        attendanceSetMessage(messageBox, 'Marking attendance...');

        try {
            const response = await fetch((window.attendanceConfig && window.attendanceConfig.markEndpoint) || 'attendance-mark-present.php', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: attendanceToken(),
                    service_id: serviceInput.value,
                    staff_id: button.dataset.staffId,
                }),
            });
            const data = await response.json();

            if (!data.success) {
                button.disabled = false;
                attendanceSetMessage(messageBox, data.message || 'Attendance could not be marked.', 'error');
                return;
            }

            attendanceSetMessage(messageBox, data.message || 'Attendance marked successfully.', 'success');
            runSearch();
        } catch (error) {
            button.disabled = false;
            attendanceSetMessage(messageBox, 'Attendance could not be marked.', 'error');
        }
    });
}

function setupAttendanceLocationAdmin() {
    const root = document.querySelector('[data-attendance-location-admin]');

    if (!root) {
        return;
    }

    const button = root.querySelector('[data-attendance-save-location]');
    const radiusInput = root.querySelector('[data-attendance-radius]');
    const message = root.querySelector('[data-location-message]');
    const result = root.querySelector('[data-location-result]');

    if (!button || !navigator.geolocation) {
        attendanceSetMessage(message, 'GPS is not available in this browser.', 'error');
        return;
    }

    button.addEventListener('click', () => {
        button.disabled = true;
        attendanceSetMessage(message, 'Getting your current GPS location...');

        navigator.geolocation.getCurrentPosition(async (position) => {
            try {
                const response = await fetch(root.dataset.locationEndpoint || 'attendance-location.php', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        csrf_token: attendanceToken(),
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        attendance_radius: radiusInput ? radiusInput.value : 100,
                    }),
                });
                const data = await response.json();

                if (!data.success) {
                    attendanceSetMessage(message, data.message || 'Location could not be saved.', 'error');
                    return;
                }

                root.querySelector('[data-location-latitude]').textContent = Number(data.settings.latitude).toFixed(7);
                root.querySelector('[data-location-longitude]').textContent = Number(data.settings.longitude).toFixed(7);
                root.querySelector('[data-location-radius-label]').textContent = data.settings.attendance_radius;
                attendanceSetMessage(message, data.message || 'Location saved successfully.', 'success');

                if (result) {
                    result.hidden = false;
                    result.innerHTML = `<dl>
                        <div><dt>Accuracy</dt><dd>${attendanceEscape(Math.round(position.coords.accuracy))} metres</dd></div>
                        <div><dt>Radius</dt><dd>${attendanceEscape(data.settings.attendance_radius)} metres</dd></div>
                    </dl>`;
                }
            } catch (error) {
                attendanceSetMessage(message, 'Location could not be saved.', 'error');
            } finally {
                button.disabled = false;
            }
        }, () => {
            button.disabled = false;
            attendanceSetMessage(message, 'Please allow location access to save the attendance point.', 'error');
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    });
}

function setupAttendanceSelfMark() {
    const root = document.querySelector('[data-member-attendance-location]');

    if (!root) {
        return;
    }

    const button = root.querySelector('[data-member-attendance-button]');
    const message = root.querySelector('[data-member-attendance-message]');
    const result = root.querySelector('[data-member-attendance-result]');

    if (!button || button.disabled) {
        return;
    }

    if (!navigator.geolocation) {
        button.disabled = true;
        attendanceSetMessage(message, 'GPS is not available in this browser.', 'error');
        return;
    }

    button.addEventListener('click', () => {
        button.disabled = true;
        attendanceSetMessage(message, 'Getting your current GPS location...');

        navigator.geolocation.getCurrentPosition(async (position) => {
            try {
                attendanceSetMessage(message, 'Marking attendance...');

                const response = await fetch(root.dataset.markEndpoint || 'attendance-mark-present.php', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        csrf_token: attendanceToken(),
                        service_id: button.dataset.serviceId,
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                    }),
                });
                const data = await response.json();

                if (!data.success) {
                    button.disabled = false;
                    attendanceSetMessage(message, data.message || 'Attendance could not be marked.', 'error');
                    return;
                }

                button.querySelector('span').textContent = 'Already marked';
                attendanceSetMessage(message, data.message || 'Attendance marked successfully.', 'success');

                if (result) {
                    result.hidden = false;
                    result.innerHTML = `<dl>
                        <div><dt>Distance</dt><dd>${attendanceEscape(data.distance ?? 0)} metres</dd></div>
                        <div><dt>Accuracy</dt><dd>${attendanceEscape(Math.round(position.coords.accuracy))} metres</dd></div>
                    </dl>`;
                }
            } catch (error) {
                button.disabled = false;
                attendanceSetMessage(message, 'Attendance could not be marked.', 'error');
            }
        }, () => {
            button.disabled = false;
            attendanceSetMessage(message, 'Please allow location access to mark attendance.', 'error');
        }, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    });
}
