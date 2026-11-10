<?php
// Web interface for generating calendar URLs; rendered by sunrise-sunset-calendar.php
$W = get_strings()['web'];
$e = fn(string $text): string => htmlspecialchars($text);
$today = sun_events(new DateTimeImmutable('today', new DateTimeZone(DEFAULT_TIMEZONE)), DEFAULT_LAT, DEFAULT_LON);
$daylight_seconds = (int) round($today['daylength_h'] * 3600);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($W['title']) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>

<body>
    <div class="container">
        <h1><?= $e($W['heading']) ?></h1>

        <div class="info-box">
            <strong><?= $e($W['today_heading']) ?></strong><br>
            <?= $e(sprintf($W['today_date'], date($W['today_date_format']))) ?><br>
            <span id="time-display">
                <?php foreach ($W['today_times'] as $key => $label): ?>
                    <?= $e($label) ?>: <?= $e(format_time($today[$key])) ?><br>
                <?php endforeach; ?>
                <strong><?= $e(sprintf($W['day_length'], format_duration($daylight_seconds))) ?></strong>
            </span>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-box">
                <strong><?= $e($W['error']) ?></strong> <?= $e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($subscription_url, $webcal_url)): ?>
            <div class="success-box">
                <h3><?= $e($W['generated']) ?></h3>
                <p><strong><?= $e($W['subscription_url']) ?></strong></p>
                <div class="url-display" id="subscription-url"><?= $e($subscription_url) ?></div>
                <button class="copy-button" onclick="copyToClipboard('subscription-url', this)"><?= $e($W['copy_url']) ?></button>

                <p style="margin-top: 20px;"><strong><?= $e($W['webcal_url']) ?></strong></p>
                <div class="url-display" id="webcal-url"><?= $e($webcal_url) ?></div>
                <button class="copy-button" onclick="copyToClipboard('webcal-url', this)"><?= $e($W['copy_webcal']) ?></button>

                <hr>
                <h4><?= $e($W['google_heading']) ?></h4>
                <ol>
                    <?php foreach ($W['google_steps'] as $step): ?>
                        <li><?= str_replace('%s', '<a href="https://calendar.google.com" target="_blank">' . $e($W['google_link']) . '</a>', $e($step)) ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <div class="warning-box">
            <strong><?= $e($W['auth_heading']) ?></strong><br>
            <?= $e($W['auth_text']) ?>
        </div>

        <div class="button-row">
            <button onclick="getLocation()" class="secondary"><?= $e($W['use_location']) ?></button>
            <button onclick="showAddressSearch()" class="secondary"><?= $e($W['search_address']) ?></button>
        </div>
        <div id="location-status"></div>

        <div id="address-search" style="display: none; margin-top: 15px;">
            <div class="form-group">
                <label for="address-input"><?= $e($W['address']) ?></label>
                <input type="text" id="address-input" placeholder="<?= $e($W['address_placeholder']) ?>">
                <button onclick="geocodeAddress()" style="margin-top: 10px;"><?= $e($W['search_location']) ?></button>
            </div>
            <div id="geocode-status"></div>
        </div>

        <form method="post" onsubmit="return validateForm()">
            <hr>

            <div class="form-group">
                <label for="password"><?= $e($W['password']) ?> <span class="required">*</span></label>
                <input type="password" name="password" id="password" required>
            </div>

            <hr>

            <div class="form-group">
                <label for="location"><?= $e($W['location']) ?></label>
                <input type="text" name="location" id="location" placeholder="<?= $e($W['location_placeholder']) ?>">
                <div class="help-text"><?= $e($W['location_help']) ?></div>
            </div>

            <div class="form-group">
                <label for="lat"><?= $e($W['latitude']) ?> <span class="required">*</span></label>
                <input type="number" name="lat" id="lat" step="0.000001" min="-90" max="90"
                    value="<?= DEFAULT_LAT ?>" required onchange="reverseGeocode()">
                <div class="help-text"><?= $e($W['latitude_help']) ?></div>
            </div>

            <div class="form-group">
                <label for="lon"><?= $e($W['longitude']) ?> <span class="required">*</span></label>
                <input type="number" name="lon" id="lon" step="0.000001" min="-180" max="180"
                    value="<?= DEFAULT_LON ?>" required onchange="reverseGeocode()">
                <div class="help-text"><?= $e($W['longitude_help']) ?></div>
            </div>

            <div class="form-group">
                <label for="zone"><?= $e($W['timezone']) ?> <span class="required">*</span></label>
                <select name="zone" id="zone" required>
                    <?php foreach (timezone_identifiers_list() as $zone): ?>
                        <option value="<?= htmlspecialchars($zone) ?>"<?= $zone === DEFAULT_TIMEZONE ? ' selected' : '' ?>><?= htmlspecialchars($zone) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <hr>

            <div class="form-group">
                <strong><?= $e($W['event_types']) ?> <span class="required">*</span></strong>
                <div class="help-text" style="margin-bottom: 10px;"><?= $e($W['event_types_help']) ?></div>
                <?php foreach ($W['types'] as $type => $label): ?>
                    <div class="checkbox-group">
                        <input type="checkbox" name="<?= $type ?>" id="<?= $type ?>"<?= in_array($type, ['civil', 'daynight'], true) ? ' checked' : '' ?>>
                        <label for="<?= $type ?>"><?= $e($label) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr>

            <div class="form-group">
                <label for="rise_off"><?= $e($W['rise_offset']) ?></label>
                <input type="number" name="rise_off" id="rise_off" value="0" min="-1440" max="1440">
                <div class="help-text"><?= $e($W['offset_help']) ?></div>
            </div>

            <div class="form-group">
                <label for="set_off"><?= $e($W['set_offset']) ?></label>
                <input type="number" name="set_off" id="set_off" value="0" min="-1440" max="1440">
                <div class="help-text"><?= $e($W['offset_help']) ?></div>
            </div>

            <hr>

            <div class="form-group">
                <label for="description"><?= $e($W['note']) ?></label>
                <textarea name="description" id="description" rows="3"
                    placeholder="<?= $e($W['note_placeholder']) ?>"></textarea>
            </div>

            <input type="submit" name="generate_url" value="<?= $e($W['submit']) ?>">
        </form>

        <div class="footer">
            <?php foreach ($W['footer'] as [$heading, $text]): ?>
                <strong><?= $e($heading) ?></strong>
                <p><?= $e($text) ?></p>
            <?php endforeach; ?>
        </div>
    </div>

    <script>const STRINGS = <?= json_encode($W['js'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="assets/script.js"></script>
</body>

</html>
