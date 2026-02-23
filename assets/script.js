function showAddressSearch() {
    const searchDiv = document.getElementById('address-search');
    searchDiv.style.display = searchDiv.style.display === 'none' ? 'block' : 'none';
}

async function geocodeAddress() {
    const address = document.getElementById('address-input').value;
    const status = document.getElementById('geocode-status');

    if (!address) {
        status.className = 'error';
        status.textContent = 'Please enter an address';
        return;
    }

    status.className = '';
    status.style.display = 'block';
    status.textContent = 'Searching...';

    try {
        const response = await fetch('?geocode=1&address=' + encodeURIComponent(address));
        const data = await response.json();

        if (data.success) {
            document.getElementById('lat').value = parseFloat(data.lat).toFixed(6);
            document.getElementById('lon').value = parseFloat(data.lon).toFixed(6);
            document.getElementById('location').value = data.display_name.split(',')[0];

            status.className = 'success';
            status.textContent = '✓ Location found: ' + data.display_name;
        } else {
            status.className = 'error';
            status.textContent = '✗ ' + (data.error || 'Location not found');
        }
    } catch (error) {
        status.className = 'error';
        status.textContent = '✗ Error searching location';
    }
}

async function reverseGeocode() {
    const lat = document.getElementById('lat').value;
    const lon = document.getElementById('lon').value;
    const locationField = document.getElementById('location');

    if (locationField.value || !lat || !lon) {
        return;
    }

    try {
        const response = await fetch('?reverse=1&lat=' + lat + '&lon=' + lon);
        const data = await response.json();

        if (data.success) {
            locationField.value = data.name;
            locationField.placeholder = data.name;
        }
    } catch (error) {
        // Silently fail
    }
}

function getLocation() {
    const status = document.getElementById('location-status');
    status.className = '';
    status.style.display = 'none';

    if (!navigator.geolocation) {
        status.className = 'error';
        status.textContent = 'Geolocation not supported.';
        status.style.display = 'block';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('lat').value = Math.round(position.coords.latitude * 1000000) / 1000000;
            document.getElementById('lon').value = Math.round(position.coords.longitude * 1000000) / 1000000;

            status.className = 'success';
            status.textContent = '✓ Location retrieved!';
            status.style.display = 'block';

            reverseGeocode();
        },
        function (error) {
            status.className = 'error';
            let message = 'Unable to retrieve location. ';
            switch (error.code) {
                case error.PERMISSION_DENIED:
                    message += 'Permission denied.';
                    break;
                case error.POSITION_UNAVAILABLE:
                    message += 'Location unavailable.';
                    break;
                case error.TIMEOUT:
                    message += 'Request timed out.';
                    break;
                default:
                    message += 'Error: ' + error.message;
            }
            status.textContent = message;
            status.style.display = 'block';
        },
        { enableHighAccuracy: true }
    );
}

function validateForm() {
    const civil = document.getElementById('civil').checked;
    const nautical = document.getElementById('nautical').checked;
    const astro = document.getElementById('astro').checked;
    const daynight = document.getElementById('daynight').checked;

    if (!civil && !nautical && !astro && !daynight) {
        alert('Please select at least one event type.');
        return false;
    }

    const password = document.getElementById('password').value;
    if (!password) {
        alert('Please enter the password.');
        return false;
    }

    return true;
}

function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    const text = element.textContent;

    navigator.clipboard.writeText(text).then(
        function () {
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = '✓ Copied!';
            button.style.background = '#28a745';

            setTimeout(function () {
                button.textContent = originalText;
                button.style.background = '';
            }, 2000);
        },
        function (err) {
            alert('Failed to copy: ' + err);
        }
    );
}

document.addEventListener('DOMContentLoaded', function () {
    const addressInput = document.getElementById('address-input');
    if (addressInput) {
        addressInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                geocodeAddress();
            }
        });
    }
});
