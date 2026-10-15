<?php
// Web interface for generating calendar URLs; rendered by sunrise-sunset-calendar.php
$today = sun_events(new DateTimeImmutable('today', new DateTimeZone(DEFAULT_TIMEZONE)), DEFAULT_LAT, DEFAULT_LON);
$daylight_minutes = (int) round($today['daylength_h'] * 60);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sun &amp; Twilight Calendar</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>

<body>
    <div class="container">
        <h1>🌅 Sun &amp; Twilight Calendar</h1>

        <div class="info-box">
            <strong>Today's Information (Rome, Italy)</strong><br>
            Date: <?= date('F j, Y') ?><br>
            <span id="time-display">
                Astronomical Dawn: <?= format_time($today['astro_begin']) ?><br>
                Nautical Dawn: <?= format_time($today['nautical_begin']) ?><br>
                Civil Dawn: <?= format_time($today['civil_begin']) ?><br>
                Sunrise: <?= format_time($today['sunrise']) ?><br>
                Solar Noon: <?= format_time($today['solar_noon']) ?><br>
                Sunset: <?= format_time($today['sunset']) ?><br>
                Civil Dusk: <?= format_time($today['civil_end']) ?><br>
                Nautical Dusk: <?= format_time($today['nautical_end']) ?><br>
                Astronomical Dusk: <?= format_time($today['astro_end']) ?><br>
                <strong>Day Length: <?= intdiv($daylight_minutes, 60) ?>h <?= $daylight_minutes % 60 ?>m</strong>
            </span>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-box">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($subscription_url, $webcal_url)): ?>
            <div class="success-box">
                <h3>✅ Subscription URL Generated!</h3>
                <p><strong>Your Calendar Subscription URL:</strong></p>
                <div class="url-display" id="subscription-url"><?= htmlspecialchars($subscription_url) ?></div>
                <button class="copy-button" onclick="copyToClipboard('subscription-url', this)">📋 Copy URL</button>

                <p style="margin-top: 20px;"><strong>Webcal URL (recommended):</strong></p>
                <div class="url-display" id="webcal-url"><?= htmlspecialchars($webcal_url) ?></div>
                <button class="copy-button" onclick="copyToClipboard('webcal-url', this)">📋 Copy Webcal URL</button>

                <hr>
                <h4>How to Add to Google Calendar:</h4>
                <ol>
                    <li>Copy the webcal URL above</li>
                    <li>Open <a href="https://calendar.google.com" target="_blank">Google Calendar</a></li>
                    <li>Click the <strong>+</strong> next to "Other calendars"</li>
                    <li>Select <strong>"From URL"</strong></li>
                    <li>Paste your subscription URL</li>
                    <li>Click <strong>"Add calendar"</strong></li>
                </ol>
            </div>
        <?php endif; ?>

        <div class="warning-box">
            <strong>🔒 Authentication Required</strong><br>
            This page requires a password to generate calendar feeds.
        </div>

        <div class="button-row">
            <button onclick="getLocation()" class="secondary">📍 Use My Current Location</button>
            <button onclick="showAddressSearch()" class="secondary">🔍 Search Address</button>
        </div>
        <div id="location-status"></div>

        <div id="address-search" style="display: none; margin-top: 15px;">
            <div class="form-group">
                <label for="address-input">Address</label>
                <input type="text" id="address-input" placeholder="e.g., Rome, Italy">
                <button onclick="geocodeAddress()" style="margin-top: 10px;">Search Location</button>
            </div>
            <div id="geocode-status"></div>
        </div>

        <form method="post" onsubmit="return validateForm()">
            <hr>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <input type="password" name="password" id="password" required>
            </div>

            <hr>

            <div class="form-group">
                <label for="location">Location Name</label>
                <input type="text" name="location" id="location" placeholder="e.g., Rome">
                <div class="help-text">Calendar name: "☀️🌅 Sun & Twilight - Your Location"</div>
            </div>

            <div class="form-group">
                <label for="lat">Latitude <span class="required">*</span></label>
                <input type="number" name="lat" id="lat" step="0.000001" min="-90" max="90"
                    value="<?= DEFAULT_LAT ?>" required onchange="reverseGeocode()">
                <div class="help-text">Decimal format (e.g., 41.9028)</div>
            </div>

            <div class="form-group">
                <label for="lon">Longitude <span class="required">*</span></label>
                <input type="number" name="lon" id="lon" step="0.000001" min="-180" max="180"
                    value="<?= DEFAULT_LON ?>" required onchange="reverseGeocode()">
                <div class="help-text">Decimal format (e.g., 12.4964)</div>
            </div>

            <div class="form-group">
                <label for="zone">Timezone <span class="required">*</span></label>
                <select name="zone" id="zone" required>
                    <?php foreach (timezone_identifiers_list() as $zone): ?>
                        <option value="<?= htmlspecialchars($zone) ?>"<?= $zone === DEFAULT_TIMEZONE ? ' selected' : '' ?>><?= htmlspecialchars($zone) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <hr>

            <div class="form-group">
                <strong>Event Types <span class="required">*</span></strong>
                <div class="help-text" style="margin-bottom: 10px;">💡 Each creates 2 events (dawn + dusk). Select fewer to get complete data in notes!</div>
                <div class="checkbox-group">
                    <input type="checkbox" name="civil" id="civil" checked>
                    <label for="civil">🌅 Civil Twilight - First Light → Sunrise | Sunset → Last Light</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="nautical" id="nautical">
                    <label for="nautical">⚓ Nautical Twilight - Nautical Dawn → First Light | Last Light → Nautical Dusk</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="astro" id="astro">
                    <label for="astro">🌌 Astronomical Twilight - Astro Dawn → Nautical Dawn | Nautical Dusk → Astro Dusk</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="daynight" id="daynight" checked>
                    <label for="daynight">☀️🌙 Day & Night - Daylight (with stats) | Night (with stats & moon phase)</label>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label for="rise_off">Morning Event Offset (minutes)</label>
                <input type="number" name="rise_off" id="rise_off" value="0" min="-1440" max="1440">
                <div class="help-text">Shift earlier (negative) or later (positive)</div>
            </div>

            <div class="form-group">
                <label for="set_off">Evening Event Offset (minutes)</label>
                <input type="number" name="set_off" id="set_off" value="0" min="-1440" max="1440">
                <div class="help-text">Shift earlier (negative) or later (positive)</div>
            </div>

            <hr>

            <div class="form-group">
                <label for="description">Custom Note (optional)</label>
                <textarea name="description" id="description" rows="3"
                    placeholder="Add custom text to all events"></textarea>
            </div>

            <input type="submit" name="generate_url" value="Generate Subscription URL">
        </form>

        <div class="footer">
            <strong>High-Precision NREL Calculations:</strong>
            <p>Uses the NREL Solar Position Algorithm, with proper solar declination, equation of time, and atmospheric refraction. Always displays 24-hour time format.</p>
            
            <strong>Astronomical Terminology:</strong>
            <ul>
                <li><strong>Civil:</strong> Sun 0-6° below horizon - outdoor activities possible</li>
                <li><strong>Nautical:</strong> Sun 6-12° below horizon - horizon visible at sea</li>
                <li><strong>Astronomical:</strong> Sun 12-18° below horizon - last/first astronomical light</li>
            </ul>

            <strong>Smart Mode:</strong>
            <p>Select only <strong>Day & Night</strong> events to get all twilight times embedded in notes for a clean calendar!</p>
        </div>
    </div>

    <script src="assets/script.js"></script>
</body>

</html>
