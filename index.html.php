<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Sun & Twilight Calendar</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <div class="container">
        <h1>🌅 Enhanced Sun & Twilight Calendar</h1>

        <div class="info-box">
            <strong>Today's Information (Rome, Italy)</strong><br>
            <button class="time-format-toggle" onclick="toggleTimeFormat()">Switch to 12-hour format</button><br>
            Date: <?php echo date('F j, Y'); ?><br>
            <span id="time-display">
                Astronomical Dawn: <?php echo date('H:i', $sun_info['astronomical_twilight_begin']); ?><br>
                Nautical Dawn: <?php echo date('H:i', $sun_info['nautical_twilight_begin']); ?><br>
                Civil Dawn: <?php echo date('H:i', $sun_info['civil_twilight_begin']); ?><br>
                Sunrise: <?php echo date('H:i', $sun_info['sunrise']); ?><br>
                Solar Noon: <?php echo date('H:i', $sun_info['transit']); ?><br>
                Sunset: <?php echo date('H:i', $sun_info['sunset']); ?><br>
                Civil Dusk: <?php echo date('H:i', $sun_info['civil_twilight_end']); ?><br>
                Nautical Dusk: <?php echo date('H:i', $sun_info['nautical_twilight_end']); ?><br>
                Astronomical Dusk: <?php echo date('H:i', $sun_info['astronomical_twilight_end']); ?>
            </span>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-box">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($subscription_url)): ?>
            <div class="success-box">
                <h3>✅ Subscription URL Generated!</h3>
                <p><strong>Your Calendar Subscription URL:</strong></p>
                <div class="url-display" id="subscription-url"><?php echo htmlspecialchars($subscription_url); ?></div>
                <button class="copy-button" onclick="copyToClipboard('subscription-url')">📋 Copy URL</button>

                <p style="margin-top: 20px;"><strong>Webcal URL (recommended):</strong></p>
                <div class="url-display" id="webcal-url"><?php echo htmlspecialchars($webcal_url); ?></div>
                <button class="copy-button" onclick="copyToClipboard('webcal-url')">📋 Copy Webcal URL</button>

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
                <p><strong>Tip:</strong> Select only one event type for smart organization!</p>
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
                    value="<?php echo $default_lat; ?>" required onchange="reverseGeocode()">
                <div class="help-text">Decimal format (e.g., 41.9028)</div>
            </div>

            <div class="form-group">
                <label for="lon">Longitude <span class="required">*</span></label>
                <input type="number" name="lon" id="lon" step="0.000001" min="-180" max="180"
                    value="<?php echo $default_lon; ?>" required onchange="reverseGeocode()">
                <div class="help-text">Decimal format (e.g., 12.4964)</div>
            </div>

            <div class="form-group">
                <label for="elevation">Elevation (meters)</label>
                <input type="number" name="elevation" id="elevation" step="1" min="-500" max="9000" value="21">
                <div class="help-text">Above sea level (optional)</div>
            </div>

            <div class="form-group">
                <label for="zone">Timezone <span class="required">*</span></label>
                <select name="zone" id="zone" required>
                    <option value="Europe/Rome" selected>Europe/Rome (CET)</option>
                    <?php
                    $zones = timezone_identifiers_list();
                    foreach ($zones as $zone) {
                        if ($zone !== 'Europe/Rome') {
                            echo "<option value=\"" . htmlspecialchars($zone) . "\">" .
                                htmlspecialchars($zone) . "</option>\n";
                        }
                    }
                    ?>
                </select>
            </div>

            <hr>

            <div class="form-group">
                <strong>Event Types <span class="required">*</span></strong>
                <div class="help-text" style="margin-bottom: 10px;">💡 Each option creates 2 events (dawn + dusk). Select fewer options to get complete sun data embedded in notes!</div>
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
                    <label for="astro">🌌 Astronomical Twilight - Astronomical Dawn → Nautical Dawn | Nautical Dusk → Astronomical Dusk</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="daynight" id="daynight" checked>
                    <label for="daynight">☀️🌙 Day & Night - Daylight (with stats & solar noon) | Night (with stats & solar midnight)</label>
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
                <div class="checkbox-group">
                    <input type="checkbox" name="twelve" id="twelve">
                    <label for="twelve">Use 12-hour time format (AM/PM)</label>
                </div>
            </div>

            <div class="form-group">
                <label for="description">Custom Note (optional)</label>
                <textarea name="description" id="description" rows="3"
                    placeholder="Add custom text to all events"></textarea>
            </div>

            <input type="submit" name="generate_url" value="Generate Subscription URL">
        </form>

        <div class="footer">
            <strong>Astronomical Terminology:</strong>
            <ul>
                <li><strong>Dawn/Dusk:</strong> Period of twilight before sunrise / after sunset</li>
                <li><strong>Civil:</strong> Sun 0-6° below horizon - outdoor activities possible</li>
                <li><strong>Nautical:</strong> Sun 6-12° below horizon - horizon visible at sea</li>
                <li><strong>Astronomical:</strong> Sun 12-18° below horizon - last/first light</li>
            </ul>

            <strong>Smart Single-Event Mode:</strong>
            <ul>
                <li>Select only <strong>Sunrise & Sunset</strong> → Get all dawn events + day stats in sunrise notes, all dusk events + night stats in sunset notes</li>
                <li>Clean calendar with complete astronomical data embedded!</li>
            </ul>
        </div>
    </div>

    <script>
        const sunInfo = {
            astro_begin: <?php echo $sun_info['astronomical_twilight_begin']; ?>,
            nautical_begin: <?php echo $sun_info['nautical_twilight_begin']; ?>,
            civil_begin: <?php echo $sun_info['civil_twilight_begin']; ?>,
            sunrise: <?php echo $sun_info['sunrise']; ?>,
            transit: <?php echo $sun_info['transit']; ?>,
            sunset: <?php echo $sun_info['sunset']; ?>,
            civil_end: <?php echo $sun_info['civil_twilight_end']; ?>,
            nautical_end: <?php echo $sun_info['nautical_twilight_end']; ?>,
            astro_end: <?php echo $sun_info['astronomical_twilight_end']; ?>
        };
    </script>
    <script src="script.js"></script>
</body>

</html>
