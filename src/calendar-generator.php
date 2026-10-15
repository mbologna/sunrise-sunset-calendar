<?php

/**
 * iCalendar feed generation.
 */

declare(strict_types=1);

/**
 * Split one day into calendar spans, from astronomical dawn to night.
 *
 * Each twilight band lies between two solar altitudes. When the sun never
 * crosses one of them, solar noon (the sun peaks inside the band) or solar
 * midnight (it bottoms out inside it) stands in, so polar days and white
 * nights yield continuous, well-ordered spans instead of empty or inverted ones.
 *
 * @param array $ev sun_events() for the day
 * @param array $next sun_events() for the following day
 * @param float $lat Latitude in degrees
 * @return array<string, array{0: int, 1: int}|null> Start/end timestamps keyed like strings['summaries']
 */
function solar_spans(array $ev, array $next, float $lat): array
{
    $noon = $ev['solar_noon'];
    $noonElevation = 90 - abs($lat - $ev['declination']);
    $midnightElevation = abs($lat + $ev['declination']) - 90;

    // Altitude with its rising and setting crossing times, deepest first
    $crossings = [
        [-18.0, $ev['astro_begin'], $ev['astro_end']],
        [-12.0, $ev['nautical_begin'], $ev['nautical_end']],
        [-6.0, $ev['civil_begin'], $ev['civil_end']],
        [0.0, $ev['sunrise'], $ev['sunset']],
    ];

    $spans = [];
    foreach (['astronomical', 'nautical', 'civil'] as $i => $band) {
        [$lowAltitude, $lowRise, $lowSet] = $crossings[$i];
        [$highAltitude, $highRise, $highSet] = $crossings[$i + 1];

        if ($lowRise === null && $highRise === null) {
            // Neither altitude crossed: the sun is inside the band all day, or never
            $inBand = $midnightElevation > $lowAltitude && $noonElevation < $highAltitude;
            $spans["{$band}_dawn"] = $inBand ? [$noon - 43200, $noon] : null;
            $spans["{$band}_dusk"] = $inBand ? [$noon, $noon + 43200] : null;
        } else {
            $spans["{$band}_dawn"] = [$lowRise ?? $noon - 43200, $highRise ?? $noon];
            $spans["{$band}_dusk"] = [$highSet ?? $noon, $lowSet ?? $noon + 43200];
        }
    }

    $daylight = null;
    if ($ev['sunrise'] !== null && $ev['sunset'] !== null) {
        $daylight = [$ev['sunrise'], $ev['sunset']];
    } elseif ($ev['polar'] === 'day') {
        $daylight = [$noon - 43200, $noon + 43200];
    }

    $night = ($ev['astro_end'] !== null && $next['astro_begin'] !== null)
        ? [$ev['astro_end'], $next['astro_begin']]
        : null;

    return $spans + ['daylight' => $daylight, 'night' => $night];
}

/**
 * Daylight on the year's winter and summer solstices, by hemisphere.
 *
 * @return array{winter: array{seconds: int, info: string}, summer: array{seconds: int, info: string}}
 */
function solstice_daylight(int $year, float $lat, float $lon, DateTimeZone $tz): array
{
    $solstices = calculate_equinox_solstice($year);
    [$winter, $summer] = $lat >= 0 ? ['december_solstice', 'june_solstice'] : ['june_solstice', 'december_solstice'];

    $describe = function (int $timestamp) use ($lat, $lon, $tz): array {
        $date = (new DateTimeImmutable("@{$timestamp}"))->setTimezone($tz);
        return [
            'seconds' => (int) round(sun_events($date, $lat, $lon)['daylength_h'] * 3600),
            'info' => $date->format('M j'),
        ];
    };

    return ['winter' => $describe($solstices[$winter]), 'summer' => $describe($solstices[$summer])];
}

/**
 * Format an iCalendar duration, in hours when whole.
 */
function ical_duration(int $seconds): string
{
    return $seconds % 3600 === 0 ? 'PT' . intdiv($seconds, 3600) . 'H' : "PT{$seconds}S";
}

/**
 * Format a time of day, or a dash when the event does not occur.
 */
function format_time(?int $timestamp): string
{
    return $timestamp === null ? '—' : date('H:i', $timestamp);
}

/**
 * Render one VEVENT.
 *
 * @param array<string> $dates DTSTART and DTEND property lines
 */
function ical_event(string $uid, array $dates, string $summary, string $description, string $dtstamp): string
{
    return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$dtstamp}\r\n"
        . implode("\r\n", $dates) . "\r\n"
        . ical_text_property('SUMMARY', $summary)
        . format_ical_description($description)
        . "TRANSP:TRANSPARENT\r\nEND:VEVENT\r\n";
}

/**
 * Generate the iCalendar feed.
 *
 * Event times are UTC; descriptions show local times in the feed's time zone.
 *
 * @param array{
 *     lat: float, lon: float, timezone: string, location: string, description: string,
 *     rise_offset: int, set_offset: int,
 *     enabled: array{civil: bool, nautical: bool, astro: bool, daylight: bool},
 *     past_days: int, window_days: int, refresh_seconds: int, today?: string
 * } $o Options; offsets in seconds, today as a date string (defaults to today)
 * @return string Complete VCALENDAR document
 */
function generate_calendar(array $o): string
{
    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set($o['timezone']);
    try {
        return render_calendar($o);
    } finally {
        date_default_timezone_set($previousTimezone);
    }
}

/**
 * Body of generate_calendar(); expects the default time zone to be the feed's.
 *
 * @param array $o Options as documented on generate_calendar()
 */
function render_calendar(array $o): string
{
    $S = get_strings();
    $tz = new DateTimeZone($o['timezone']);
    $lat = $o['lat'];
    $lon = $o['lon'];
    $enabled = $o['enabled'];
    $uidSuffix = "{$lat}-{$lon}@sun-calendar";
    $dtstamp = gmdate('Ymd\THis\Z');
    $refresh = ical_duration($o['refresh_seconds']);

    $timed = fn(int $start, int $end) => [
        'DTSTART:' . gmdate('Ymd\THis\Z', $start),
        'DTEND:' . gmdate('Ymd\THis\Z', $end),
    ];
    $allDay = fn(DateTimeImmutable $day) => [
        'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
        'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd'),
    ];
    $signed = fn(int $diff) => ($diff >= 0 ? '+' : '-') . format_duration_short(abs($diff));

    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:{$S['calendar_prodid']}\r\n"
        . "CALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
        . ical_text_property('X-WR-CALNAME', sprintf($S['calendar_name_format'], $o['location'] ?: "{$lat}, {$lon}"))
        . "X-WR-TIMEZONE:{$o['timezone']}\r\n"
        . "X-PUBLISHED-TTL:{$refresh}\r\nREFRESH-INTERVAL;VALUE=DURATION:{$refresh}\r\n";

    $today = new DateTimeImmutable($o['today'] ?? 'today', $tz);
    $first = $today->modify("-{$o['past_days']} days");
    $last = $today->modify("+{$o['window_days']} days");

    // Equinoxes and solstices by local date, for every year the window touches
    $specialEvents = [];
    for ($y = (int) $first->format('Y'); $y <= (int) $last->format('Y'); $y++) {
        foreach (get_special_astronomical_events($y) as $event) {
            $specialEvents[date('Y-m-d', $event['date'])] = $event;
        }
    }

    $solstices = [];
    $locationNotes = get_location_notes($lat);
    $notesShown = false;

    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        $year = (int) $day->format('Y');
        $dateStr = $day->format('Ymd');
        $ev = sun_events($day, $lat, $lon);
        $prev = sun_events($day->modify('-1 day'), $lat, $lon);
        $spans = solar_spans($ev, sun_events($day->modify('+1 day'), $lat, $lon), $lat);
        $solstices[$year] ??= solstice_daylight($year, $lat, $lon, $tz);

        $daylightSeconds = (int) round($ev['daylength_h'] * 3600);
        $daylightPercentile = calculate_daylight_percentile($ev['daylength_h'], $lat, $lon, $year, $ev['utc_offset']);
        $dayStats = [
            'daylight_seconds' => $daylightSeconds,
            'daylight_pct' => round($daylightSeconds / 864, 1),
            'daylight_percentile' => $daylightPercentile,
            'day_length_comparison' => format_day_length_comparison(
                $daylightSeconds - (int) round($prev['daylength_h'] * 3600)
            ),
            'solar_noon_time' => date('H:i', $ev['solar_noon']),
            'winter_comparison' => $signed($daylightSeconds - $solstices[$year]['winter']['seconds']),
            'winter_solstice_info' => $solstices[$year]['winter']['info'],
            'summer_comparison' => $signed($daylightSeconds - $solstices[$year]['summer']['seconds']),
            'summer_solstice_info' => $solstices[$year]['summer']['info'],
        ];

        // Night runs from astronomical dusk to the next astronomical dawn
        $nightSeconds = $spans['night'] ? $spans['night'][1] - $spans['night'][0] : 0;
        $lastNight = ($prev['astro_end'] !== null && $ev['astro_begin'] !== null)
            ? $ev['astro_begin'] - $prev['astro_end']
            : null;
        $nightStats = [
            'night_seconds' => $nightSeconds,
            'night_pct' => round($nightSeconds / 864, 1),
            'night_percentile' => 100 - $daylightPercentile,
            'night_length_comparison' => ($spans['night'] && $lastNight !== null)
                ? format_day_length_comparison($nightSeconds - $lastNight)
                : null,
            'moon_info' => get_accurate_moon_phase($ev['solar_noon'] + 43200),
        ];

        // Week summary (Sundays)
        if ($enabled['daylight'] && $day->format('w') === '0') {
            $week = SunCalendar\Cache::getInstance()
                ->getWeekSummary($day->getTimestamp(), $lat, $lon, $ev['utc_offset']);
            $lastYear = get_last_year_week_data($day->getTimestamp(), $lat, $lon, $ev['utc_offset']);

            $desc = "{$S['headers']['at_a_glance']}\n\n";
            $desc .= "{$S['labels']['trend']}: {$week['trend_emoji']} {$week['trend']}\n";
            $desc .= "{$S['labels']['average']}: " . format_duration_full((int) $week['avg_length']) . "\n\n";
            $desc .= "{$S['headers']['details']}\n\n";
            $desc .= "{$S['labels']['change']}: " . $signed((int) $week['total_change']) . "\n";
            $desc .= "{$S['labels']['shortest']}: " . date('l, M j', $week['shortest_day']) . "\n";
            $desc .= '  ' . format_duration_full((int) $week['min_length']) . "\n";
            $desc .= "{$S['labels']['longest']}: " . date('l, M j', $week['longest_day']) . "\n";
            $desc .= '  ' . format_duration_full((int) $week['max_length']) . "\n\n";
            $desc .= "{$S['headers']['comparisons']}\n\n";
            $desc .= "{$S['labels']['vs_last_year']}: " . $signed((int) ($week['avg_length'] - $lastYear['avg_length']))
                . "\n  (Week of " . date('M j, Y', $lastYear['week_start']) . ")\n\n";
            $desc .= "{$S['labels']['moon']}: " . get_moon_phase_emoji($week['moon_phase']) . " {$week['moon_phase']}";

            // Location notes go on the first week summary only
            if (!$notesShown && $locationNotes) {
                $desc .= "\n\n{$S['headers']['location_notes']}\n" . implode("\n", $locationNotes) . "\n";
                $notesShown = true;
            }

            $summary = sprintf(
                $S['week_summary']['title_format'],
                $day->format('M j'),
                $day->modify('+6 days')->format('M j')
            );
            $out .= ical_event("week-summary-{$dateStr}-{$uidSuffix}", $allDay($day), $summary, $desc, $dtstamp);
        }

        // Equinoxes and solstices
        $special = $specialEvents[$day->format('Y-m-d')] ?? null;
        if ($special) {
            $desc = "{$S['headers']['astronomical_event']}\n\n";
            $desc .= "{$S['labels']['event']}: {$special['name']}\n";
            $desc .= "{$S['labels']['date']}: " . date('F j, Y, H:i', $special['date']) . "\n\n";
            $desc .= $special['description'];

            $slug = strtolower(str_replace(' ', '-', $special['name']));
            $out .= ical_event(
                "special-{$slug}-{$dateStr}@sun-calendar",
                $allDay($day),
                "{$special['emoji']} {$special['name']}",
                $desc,
                $dtstamp
            );
        }

        // Twilight events: span key => [enabled flag, UID prefix, morning?]
        $twilights = [
            'civil_dawn' => ['civil', 'civil-dawn', true],
            'nautical_dawn' => ['nautical', 'nautical-dawn', true],
            'astronomical_dawn' => ['astro', 'astro-dawn', true],
            'civil_dusk' => ['civil', 'civil-dusk', false],
            'nautical_dusk' => ['nautical', 'nautical-dusk', false],
            'astronomical_dusk' => ['astro', 'astro-dusk', false],
        ];
        foreach ($twilights as $key => [$type, $uid, $morning]) {
            if (!$enabled[$type] || $spans[$key] === null) {
                continue;
            }
            [$start, $end] = $spans[$key];
            $offset = $morning ? $o['rise_offset'] : $o['set_offset'];

            $desc = "{$S['headers']['at_a_glance']}\n\n";
            $desc .= "{$S['labels']['duration']}: " . format_duration_full($end - $start) . "\n\n";
            $desc .= "{$S['headers']['details']}\n\n";
            if ($key === 'civil_dawn') {
                $desc .= "{$S['labels']['first_light']}: " . format_time($start) . "\n";
            }
            $desc .= "{$S['labels']['sunrise']}: " . format_time($ev['sunrise']) . "\n";
            $desc .= "{$S['labels']['sunset']}: " . format_time($ev['sunset']) . "\n";
            if ($key === 'civil_dusk') {
                $desc .= "{$S['labels']['last_light']}: " . format_time($end) . "\n";
            }
            $desc .= "\n{$S['headers']['context']}\n\n{$S['twilight_descriptions'][$key]}";
            $desc .= $morning
                ? build_dawn_supplemental(['spans' => $spans, 'enabled' => $enabled, 'daylight' => $dayStats], $S)
                : build_dusk_supplemental(['spans' => $spans, 'enabled' => $enabled, 'night' => $nightStats], $S);

            $out .= ical_event(
                "{$uid}-{$dateStr}-{$uidSuffix}",
                $timed($start + $offset, $end + $offset),
                $S['summaries'][$key],
                $desc,
                $dtstamp
            );
        }

        // Daylight
        if ($enabled['daylight'] && $spans['daylight'] !== null) {
            [$start, $end] = $spans['daylight'];

            $desc = "{$S['headers']['at_a_glance']}\n\n";
            $desc .= "{$S['labels']['duration']}: " . format_duration_full($daylightSeconds) . "\n";
            $desc .= "{$S['labels']['progress']}: " . format_percentile_bar($daylightPercentile) . "\n\n";
            $desc .= "{$S['headers']['details']}\n\n";
            $desc .= "{$S['labels']['sunrise']}: " . format_time($ev['sunrise']) . "\n";
            $desc .= "{$S['labels']['sunset']}: " . format_time($ev['sunset']) . "\n";
            $desc .= "{$S['labels']['solar_noon']}: {$dayStats['solar_noon_time']}\n";
            $desc .= "{$S['labels']['of_day']}: " . number_format($dayStats['daylight_pct'], 1) . "%\n\n";
            $desc .= "{$S['headers']['comparisons']}\n\n";
            $desc .= "{$S['labels']['vs_yesterday']}: {$dayStats['day_length_comparison']}\n";
            $desc .= "{$S['labels']['vs_winter_solstice']}: {$dayStats['winter_comparison']}\n";
            $desc .= "  ({$dayStats['winter_solstice_info']})\n";
            $desc .= "{$S['labels']['vs_summer_solstice']}: {$dayStats['summer_comparison']}\n";
            $desc .= "  ({$dayStats['summer_solstice_info']})\n\n";
            $desc .= "{$S['headers']['context']}\n\n{$S['twilight_descriptions']['daylight']}";
            if ($o['description'] !== '') {
                $desc .= "\n\n" . $o['description'];
            }

            $out .= ical_event(
                "daylight-{$dateStr}-{$uidSuffix}",
                $timed($start + $o['rise_offset'], $end + $o['set_offset']),
                $S['summaries']['daylight'],
                $desc,
                $dtstamp
            );
        }

        // Night
        if ($enabled['daylight'] && $spans['night'] !== null) {
            [$start, $end] = $spans['night'];

            $desc = "{$S['headers']['at_a_glance']}\n\n";
            $desc .= "{$S['labels']['duration']}: " . format_duration_full($nightSeconds) . "\n";
            $desc .= "{$S['labels']['progress']}: " . format_percentile_bar($nightStats['night_percentile']) . "\n\n";
            $desc .= "{$S['headers']['details']}\n\n";
            $desc .= "{$S['labels']['solar_midnight']}: " . date('H:i', intdiv($start + $end, 2)) . "\n";
            $desc .= "{$S['labels']['of_day']}: " . number_format($nightStats['night_pct'], 1) . "%\n\n";
            if ($nightStats['night_length_comparison'] !== null) {
                $desc .= "{$S['headers']['comparisons']}\n\n";
                $desc .= "{$S['labels']['vs_yesterday']}: {$nightStats['night_length_comparison']}\n\n";
            }
            $desc .= format_moon_section($nightStats['moon_info'], $S) . "\n";
            $desc .= "{$S['headers']['context']}\n\n{$S['twilight_descriptions']['night']}";
            if ($o['description'] !== '') {
                $desc .= "\n\n" . $o['description'];
            }

            $out .= ical_event(
                "night-{$dateStr}-{$uidSuffix}",
                $timed($start + $o['set_offset'], $end + $o['rise_offset']),
                $S['summaries']['night'],
                $desc,
                $dtstamp
            );
        }
    }

    return $out . "END:VCALENDAR\r\n";
}
