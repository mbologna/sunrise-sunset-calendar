function showAddressSearch() {
    const searchDiv = document.getElementById('address-search');
    searchDiv.style.display = searchDiv.style.display === 'none' ? 'block' : 'none';
}

// Address lookups go from the browser straight to OpenStreetMap Nominatim
// (https://operations.osmfoundation.org/policies/nominatim/), so the server
// relays nothing to third parties for unauthenticated visitors.
const NOMINATIM = 'https://nominatim.openstreetmap.org/';

async function nominatim(endpoint, params) {
    const response = await fetch(NOMINATIM + endpoint + '?' + new URLSearchParams({ ...params, format: 'json' }));
    if (!response.ok) {
        throw new Error('Nominatim returned ' + response.status);
    }
    return response.json();
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
        const [place] = await nominatim('search', { q: address, limit: 1 });

        if (place) {
            document.getElementById('lat').value = parseFloat(place.lat).toFixed(6);
            document.getElementById('lon').value = parseFloat(place.lon).toFixed(6);
            document.getElementById('location').value = place.display_name.split(',')[0];

            status.className = 'success';
            status.textContent = '✓ Location found: ' + place.display_name;
        } else {
            status.className = 'error';
            status.textContent = '✗ Location not found';
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
        const data = await nominatim('reverse', { lat, lon, zoom: 10 });
        const a = data.address || {};
        const name = [a.city || a.town || a.village || a.municipality, a.state || a.province]
            .filter(Boolean)
            .join(', ') || data.display_name;

        if (name) {
            locationField.value = name;
            locationField.placeholder = name;
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

function copyToClipboard(elementId, button) {
    const text = document.getElementById(elementId).textContent;

    navigator.clipboard.writeText(text).then(
        function () {
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
    // Default the timezone to the browser's own, when the list has it
    const zone = document.getElementById('zone');
    const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (zone && [...zone.options].some(option => option.value === browserZone)) {
        zone.value = browserZone;
    }

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
