<?php

/**
 * iCalendar feed generation.
 */

declare(strict_types=1);

/**
 * The six twilights: subscription flag, depression angles bounding them, and UID prefix.
 *
 * @return array<string, array{flag: string, deep: int, high: int, morning: bool, uid: string}>
 */
function twilight_bands(): array
{
    $band = fn(string $flag, int $deep, int $high, bool $morning, string $uid) =>
        ['flag' => $flag, 'deep' => $deep, 'high' => $high, 'morning' => $morning, 'uid' => $uid];

    return [
        'morning_astronomical' => $band('astro', 18, 12, true, 'morning-astronomical-twilight'),
        'morning_nautical' => $band('nautical', 12, 6, true, 'morning-nautical-twilight'),
        'morning_civil' => $band('civil', 6, 0, true, 'morning-civil-twilight'),
        'evening_civil' => $band('civil', 6, 0, false, 'evening-civil-twilight'),
        'evening_nautical' => $band('nautical', 12, 6, false, 'evening-nautical-twilight'),
        'evening_astronomical' => $band('astro', 18, 12, false, 'evening-astronomical-twilight'),
    ];
}

/**
 * Split one day into calendar periods, from morning astronomical twilight to night.
 *
 * Each twilight lies between two solar altitudes. When the sun never crosses
 * one of them, solar noon (the sun peaks inside the band) or solar midnight
 * (it bottoms out inside it) stands in, so polar days and white nights yield
 * continuous, well-ordered periods instead of empty or inverted ones. Each
 * period also names the instants that bound it, keyed like strings['instants'].
 *
 * @param array $ev sun_events() for the day
 * @param array $next sun_events() for the following day
 * @return array<string, array{0: int, 1: int, 2: string, 3: string}|null>
 *     Start, end, start instant, end instant; keyed like strings['summaries']
 */
function solar_spans(array $ev, array $next): array
{
    $noon = $ev['solar_noon'];

    // Altitude with its rising and setting crossings and their instant names, deepest first
    $crossings = [
        [-18.0, $ev['astro_begin'], $ev['astro_end'], 'astronomical_dawn', 'astronomical_dusk'],
        [-12.0, $ev['nautical_begin'], $ev['nautical_end'], 'nautical_dawn', 'nautical_dusk'],
        [-6.0, $ev['civil_begin'], $ev['civil_end'], 'civil_dawn', 'civil_dusk'],
        [0.0, $ev['sunrise'], $ev['sunset'], 'sunrise', 'sunset'],
    ];

    $spans = [];
    foreach (['astronomical', 'nautical', 'civil'] as $i => $band) {
        [$lowAltitude, $lowRise, $lowSet, $lowDawn, $lowDusk] = $crossings[$i];
        [$highAltitude, $highRise, $highSet, $highDawn, $highDusk] = $crossings[$i + 1];

        if ($lowRise === null && $highRise === null) {
            // Neither altitude crossed: the sun is inside the band all day, or never
            $inBand = $ev['midnight_elevation'] > $lowAltitude && $ev['noon_elevation'] < $highAltitude;
            $spans["morning_{$band}"] = $inBand ? [$noon - 43200, $noon, 'solar_midnight', 'solar_noon'] : null;
            $spans["evening_{$band}"] = $inBand ? [$noon, $noon + 43200, 'solar_noon', 'solar_midnight'] : null;
        } else {
            $spans["morning_{$band}"] = [
                $lowRise ?? $noon - 43200,
                $highRise ?? $noon,
                $lowRise === null ? 'solar_midnight' : $lowDawn,
                $highRise === null ? 'solar_noon' : $highDawn,
            ];
            $spans["evening_{$band}"] = [
                $highSet ?? $noon,
                $lowSet ?? $noon + 43200,
                $highSet === null ? 'solar_noon' : $highDusk,
                $lowSet === null ? 'solar_midnight' : $lowDusk,
            ];
        }
    }

    $daylight = null;
    if ($ev['sunrise'] !== null && $ev['sunset'] !== null) {
        $daylight = [$ev['sunrise'], $ev['sunset'], 'sunrise', 'sunset'];
    } elseif ($ev['polar'] === 'day') {
        $daylight = [$noon - 43200, $noon + 43200, 'solar_midnight', 'solar_midnight'];
    }

    // Night runs from astronomical dusk to the next astronomical dawn. On a day the sun
    // stays more than 18° below the horizon, solar noon stands in, so consecutive
    // nights join up around the clock during deep polar night.
    $allDark = fn(array $e) => $e['astro_begin'] === null && $e['noon_elevation'] < -18;
    $nightStart = $ev['astro_end'] ?? ($allDark($ev) ? $noon : null);
    $nightEnd = $next['astro_begin'] ?? ($allDark($next) ? $next['solar_noon'] : null);
    $night = ($nightStart !== null && $nightEnd !== null)
        ? [
            $nightStart,
            $nightEnd,
            $ev['astro_end'] !== null ? 'astronomical_dusk' : 'solar_noon',
            $next['astro_begin'] !== null ? 'astronomical_dawn' : 'solar_noon',
        ]
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
            'info' => format_local($timestamp, 'date_short'),
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
    return $timestamp === null ? get_strings()['none'] : format_local($timestamp);
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
    $allDay = fn(DateTimeImmutable $day, int $days = 1) => [
        'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
        'DTEND;VALUE=DATE:' . $day->modify("+{$days} days")->format('Ymd'),
    ];
    // Offsets move an event away from the instants its description names; say so
    $offsetNote = fn(int $start, int $end) => ($start === 0 && $end === 0) ? '' : "\n\n" . sprintf(
        $S['labels']['offset_note'],
        format_signed_duration($start),
        format_signed_duration($end)
    );
    $customNote = $o['description'] !== '' ? "\n\n" . $o['description'] : '';

    $today = new DateTimeImmutable($o['today'] ?? 'today', $tz);
    $todayYear = year_summary($lat, $lon, (int) $today->format('Y'), $today->setTime(12, 0)->getOffset() / 3600);
    $place = sprintf($o['location'] !== '' ? $S['place_named'] : $S['place_coordinates'], $o['location'], $lat, $lon);
    $calendarDescription = implode(' ', [
        sprintf($S['calendar_description_format'], $place, $o['timezone']),
        ...location_facts($todayYear, $S),
    ]);

    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:{$S['calendar_prodid']}\r\n"
        . "CALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
        . ical_text_property('X-WR-CALNAME', sprintf($S['calendar_name_format'], $o['location'] ?: "{$lat}, {$lon}"))
        . ical_text_property('X-WR-CALDESC', $calendarDescription)
        . "X-WR-TIMEZONE:{$o['timezone']}\r\n"
        . "X-PUBLISHED-TTL:{$refresh}\r\nREFRESH-INTERVAL;VALUE=DURATION:{$refresh}\r\n";

    $first = $today->modify("-{$o['past_days']} days");
    $last = $today->modify("+{$o['window_days']} days");

    // Equinoxes and solstices by local date, for every year the window touches
    $specialEvents = [];
    for ($y = (int) $first->format('Y'); $y <= (int) $last->format('Y'); $y++) {
        foreach (get_special_astronomical_events($y, $lat) as $event) {
            $specialEvents[date('Y-m-d', $event['date'])] = $event;
        }
    }

    $solstices = [];

    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        $year = (int) $day->format('Y');
        $dateStr = $day->format('Ymd');
        $ev = sun_events($day, $lat, $lon);
        $prev = sun_events($day->modify('-1 day'), $lat, $lon);
        $next = sun_events($day->modify('+1 day'), $lat, $lon);
        $spans = solar_spans($ev, $next);
        $yearInfo = year_summary($lat, $lon, $year, $ev['utc_offset']);
        $solstices[$year] ??= solstice_daylight($year, $lat, $lon, $tz);

        $daylightSeconds = (int) round($ev['daylength_h'] * 3600);
        $daylightPercentile = calculate_daylight_percentile($ev['daylength_h'], $lat, $lon, $year, $ev['utc_offset']);

        $nightSeconds = $spans['night'] ? $spans['night'][1] - $spans['night'][0] : 0;
        $lastNightSpan = solar_spans($prev, $ev)['night'];
        $lastNight = $lastNightSpan ? $lastNightSpan[1] - $lastNightSpan[0] : null;
        $moon = get_accurate_moon_phase($ev['solar_noon'] + 43200) + ['lat' => $lat];
        if ($spans['night'] !== null) {
            $moon['rise_set'] = moon_rise_set($spans['night'][0], $spans['night'][1], $lat, $lon);
        }

        $yesterdaySeconds = (int) round($prev['daylength_h'] * 3600);
        $c = [
            'ev' => $ev,
            'spans' => $spans,
            'enabled' => $enabled,
            'year' => $yearInfo,
            'day_index' => (int) $day->format('z'),
            'daylight' => [
                'seconds' => $daylightSeconds,
                'percentile' => $daylightPercentile,
                'comparisons' => [
                    format_comparison(
                        $daylightSeconds - $yesterdaySeconds,
                        $yesterdaySeconds,
                        $S['comparisons']['yesterday'],
                        $S
                    ),
                    format_comparison(
                        $daylightSeconds - $solstices[$year]['winter']['seconds'],
                        $solstices[$year]['winter']['seconds'],
                        sprintf($S['comparisons']['winter_solstice'], $solstices[$year]['winter']['info']),
                        $S,
                        false
                    ),
                    format_comparison(
                        $daylightSeconds - $solstices[$year]['summer']['seconds'],
                        $solstices[$year]['summer']['seconds'],
                        sprintf($S['comparisons']['summer_solstice'], $solstices[$year]['summer']['info']),
                        $S,
                        false
                    ),
                ],
            ],
            'night' => [
                'seconds' => $nightSeconds,
                'percentile' => night_percentile($yearInfo, (int) $day->format('z')),
                'solar_midnight' => intdiv($ev['solar_noon'] + $next['solar_noon'], 2),
                'comparison' => ($spans['night'] && $lastNight !== null)
                    ? format_comparison($nightSeconds - $lastNight, $lastNight, $S['comparisons']['last_night'], $S)
                    : null,
                'moon' => $moon,
            ],
        ];

        // Weekly summary: an all-day event spanning Sunday through Saturday
        if ($enabled['daylight'] && $day->format('w') === '0') {
            $week = SunCalendar\Cache::getInstance()
                ->getWeekSummary($day->getTimestamp(), $lat, $lon, $ev['utc_offset']);

            $phases = array_map(
                fn($p) => sprintf(
                    $S['moon']['phase_at'],
                    get_moon_phase_emoji($p['name'], $lat),
                    moon_phase_name($p['name']),
                    format_local($p['timestamp'], 'datetime')
                ),
                moon_phases_between($day->getTimestamp(), $day->modify('+7 days')->getTimestamp())
            );
            if ($phases === []) {
                $phaseName = get_accurate_moon_phase($day->getTimestamp())['phase_name'];
                $phases = [get_moon_phase_emoji($phaseName, $lat) . ' ' . moon_phase_name($phaseName)];
            }

            $desc = sprintf(
                $S['labels']['week_daylight'],
                format_duration((int) round($week['start_length'])),
                format_duration((int) round($week['end_length']))
            ) . "\n";
            // Clock times people notice: in December sunsets stop getting earlier while sunrises keep getting later
            $saturday = sun_events($day->modify('+6 days'), $lat, $lon);
            if (!in_array(null, [$ev['sunrise'], $ev['sunset'], $saturday['sunrise'], $saturday['sunset']], true)) {
                $desc .= sprintf(
                    $S['labels']['week_sun'],
                    format_local($ev['sunrise']),
                    format_local($saturday['sunrise']),
                    format_local($ev['sunset']),
                    format_local($saturday['sunset'])
                ) . "\n";
            }
            $desc .= sprintf($S['labels']['average'], format_duration((int) round($week['avg_length']))) . "\n";
            $desc .= sprintf($S['labels']['week_moon'], implode('; ', $phases));

            $summary = sprintf($S['week_titles'][$week['trend']], format_duration((int) round($week['total_change'])));
            $out .= ical_event("week-{$dateStr}-{$uidSuffix}", $allDay($day, 7), $summary, $desc, $dtstamp);
        }

        // Equinoxes and solstices: all day on the local date, exact moment in the description
        $special = $specialEvents[$day->format('Y-m-d')] ?? null;
        if ($special) {
            $out .= ical_event(
                str_replace('_', '-', $special['season']) . "-{$dateStr}-{$uidSuffix}",
                $allDay($day),
                $S['seasons'][$special['season']]['title'],
                season_description($special, $ev, $yearInfo, $lat, $S),
                $dtstamp
            );
        }

        // Twilights
        foreach (twilight_bands() as $key => $band) {
            if (!$enabled[$band['flag']] || $spans[$key] === null) {
                continue;
            }
            $span = $spans[$key];
            $offset = $band['morning'] ? $o['rise_offset'] : $o['set_offset'];

            // Data first, then the rest of today's schedule, then the explanation
            $desc = twilight_details($key, $span, $ev, $yearInfo, $c['day_index'], $S);
            $desc .= $band['morning'] ? build_dawn_supplemental($c, $S) : build_dusk_supplemental($c, $S);
            $desc .= "\n\n" . twilight_about($key, $span, $spans, $S);
            $desc .= $offsetNote($offset, $offset);

            $out .= ical_event(
                "{$band['uid']}-{$dateStr}-{$uidSuffix}",
                $timed($span[0] + $offset, $span[1] + $offset),
                $S['summaries'][$key],
                $desc,
                $dtstamp
            );
        }

        // Daylight
        if ($enabled['daylight'] && $spans['daylight'] !== null) {
            [$start, $end] = $spans['daylight'];

            $light = format_light_block($ev, $S);
            $desc = rtrim(daylight_details($c, $S)) . ($light === '' ? '' : "\n\n" . $light)
                . build_twilight_schedule($c, $S)
                . "\n\n" . daylight_about($ev, $S)
                . $offsetNote($o['rise_offset'], $o['set_offset']) . $customNote;

            $out .= ical_event(
                "daylight-{$dateStr}-{$uidSuffix}",
                $timed($start + $o['rise_offset'], $end + $o['set_offset']),
                sprintf($S['summaries']['daylight'], format_duration($daylightSeconds)),
                $desc,
                $dtstamp
            );
        }

        // Night
        if ($enabled['daylight'] && $spans['night'] !== null) {
            [$start, $end] = $spans['night'];

            // With no Daylight event today, the night carries the twilight times instead
            $schedule = $spans['daylight'] === null ? build_twilight_schedule($c, $S) : '';
            $desc = rtrim(night_details($c, $S)) . $schedule . "\n\n" . night_about($S)
                . $offsetNote($o['set_offset'], $o['rise_offset']) . $customNote;

            $out .= ical_event(
                "night-{$dateStr}-{$uidSuffix}",
                $timed($start + $o['set_offset'], $end + $o['rise_offset']),
                sprintf(
                    $S['summaries']['night'],
                    get_moon_phase_emoji($moon['phase_name'], $lat),
                    format_duration($nightSeconds)
                ),
                $desc,
                $dtstamp
            );
        }
    }

    return $out . "END:VCALENDAR\r\n";
}
