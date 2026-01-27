let use12Hour = false;

function formatTime(timestamp, twelveHour) {
    const date = new Date(timestamp * 1000);
    if (twelveHour) {
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    } else {
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });
    }
}

function toggleTimeFormat() {
    use12Hour = !use12Hour;
    const button = document.querySelector('.time-format-toggle');
    button.textContent = use12Hour ? 'Switch to 24-hour format' : 'Switch to 12-hour format';

    const display = document.getElementById('time-display');
    display.innerHTML = `
        Astronomical Dawn: ${formatTime(sunInfo.astro_begin, use12Hour)}<br>
        Nautical Dawn: ${formatTime(sunInfo.nautical_begin, use12Hour)}<br>
        Civil Dawn: ${formatTime(sunInfo.civil_begin, use12Hour)}<br>
        Sunrise: ${formatTime(sunInfo.sunrise, use12Hour)}<br>
        Solar Noon: ${formatTime(sunInfo.transit, use12Hour)}<br>
        Sunset: ${formatTime(sunInfo.sunset, use12Hour)}<br>
        Civil Dusk: ${formatTime(sunInfo.civil_end, use12Hour)}<br>
        Nautical Dusk: ${formatTime(sunInfo.nautical_end, use12Hour)}<br>
        Astronomical Dusk: ${formatTime(sunInfo.astro_end, use12Hour)}
    `;
}

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

    // Only suggest if location field is empty
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
        // Silently fail - not critical
    }
}

function getLocation() {
    const status = document.getElementById('location-status');
    status.className = '';
    status.style.display = 'none';

    if (!navigator.geolocation) {
        status.className = 'error';
        status.textContent = 'Geolocation not supported by your browser.';
        status.style.display = 'block';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('lat').value =
                Math.round(position.coords.latitude * 1000000) / 1000000;
            document.getElementById('lon').value =
                Math.round(position.coords.longitude * 1000000) / 1000000;

            if (position.coords.altitude !== null) {
                document.getElementById('elevation').value =
                    Math.round(position.coords.altitude);
            }

            status.className = 'success';
            status.textContent = '✓ Location retrieved successfully!';
            status.style.display = 'block';

            // Try to get location name
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

// Add enter key support for address search
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
