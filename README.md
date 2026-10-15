# Sun & Twilight Calendar Generator

[![CI](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

An iCalendar feed of daily sunrise, sunset and twilight events for any location, computed with the NREL Solar Position Algorithm. Subscribe to it from Google Calendar, Apple Calendar or Outlook.

## Features

- Civil, nautical and astronomical twilight as separate dawn and dusk events
- Daylight and night events with day-length percentiles, day-to-day change and solstice comparisons
- Moon phase and illumination on night events
- Weekly daylight summaries on Sundays
- Equinox and solstice events on their computed dates
- Correct times across daylight-saving changes, and continuous twilight during polar days and white nights
- Token-protected feed URLs

## Installation

```bash
git clone https://github.com/mbologna/sunrise-sunset-calendar.git
cd sunrise-sunset-calendar
composer install
cp config/config.example.php config/config.php
# Set AUTH_TOKEN in config/config.php, e.g. to the output of: openssl rand -hex 32
php -S localhost:8000
```

Open `http://localhost:8000/sunrise-sunset-calendar.php`, enter the token as the password and generate a subscription URL.

### Docker

```bash
docker build -t sunrise-sunset-calendar .
docker run -p 8080:8080 -e AUTH_TOKEN="$(openssl rand -hex 32)" sunrise-sunset-calendar
```

The container listens on `$PORT` (default 8080), so it runs unchanged on Cloud Run. Only the entry point and `assets/` are reachable over HTTP.

## Configuration

Set these as environment variables or as constants in `config/config.php`. Environment variables take precedence.

| Setting | Default | Meaning |
|---------|---------|---------|
| `AUTH_TOKEN` | none (required) | Secret embedded in feed URLs and used as the web form password |
| `CALENDAR_WINDOW_DAYS` | `400` | Days of future events in the feed |
| `CALENDAR_PAST_DAYS` | `30` | Days of past events in the feed |
| `UPDATE_INTERVAL` | `86400` | Refresh interval suggested to calendar apps, in seconds |

## Feed URL parameters

`https://example.com/sunrise-sunset-calendar.php?feed=1&token=TOKEN&lat=40.7128&lon=-74.0060&zone=America/New_York&daynight=1`

| Parameter | Required | Values |
|-----------|----------|--------|
| `feed` | yes | `1` |
| `token` | yes | `AUTH_TOKEN` |
| `lat` | yes | -90 to 90 |
| `lon` | yes | -180 to 180 |
| `zone` | yes | IANA time zone, e.g. `Europe/Rome` |
| `location` | no | Name shown in the calendar title |
| `civil`, `nautical`, `astro` | no | `1` adds that twilight's dawn and dusk events |
| `daynight` | no | `1` adds daylight, night and weekly summary events |
| `rise_off`, `set_off` | no | Minutes (-1440 to 1440) to shift morning and evening events |
| `desc` | no | Note appended to daylight and night events |

Twilight types not subscribed to as events are listed in the description of the events that are, so a calendar with only daylight and night events still carries every time.

## Event types

- **Astronomical twilight**: sun 12–18° below the horizon
- **Nautical twilight**: sun 6–12° below the horizon
- **Civil twilight**: sun 0–6° below the horizon
- **Daylight**: sunrise to sunset
- **Night**: astronomical dusk to the next astronomical dawn

When the sun never crosses a boundary, an event runs to or from solar noon or solar midnight instead. During polar night, civil twilight peaks at solar noon; under the midnight sun, daylight lasts 24 hours and no twilight or night events appear.

## Development

```bash
composer test       # PHPUnit
composer analyse    # PHPStan (level 6)
composer lint       # PHP_CodeSniffer (PSR-12)
composer lint:fix   # fix what phpcbf can
composer check:all  # all three; CI runs the same checks
```

## Project structure

```
├── sunrise-sunset-calendar.php   # HTTP entry point: config, routing, headers
├── src/
│   ├── astronomy.php             # SPA wrapper, sun_events(), equinoxes, moon phases
│   ├── calendar-generator.php    # generate_calendar(): builds the VCALENDAR
│   ├── helpers.php               # iCalendar escaping/folding, descriptions, statistics
│   ├── functions.php             # input sanitisation, token check, defaults
│   ├── Cache.php                 # per-request memoisation
│   ├── strings.php               # all user-facing text
│   └── index.html.php            # web form template
├── assets/                       # web form CSS and JavaScript (address search calls Nominatim)
├── config/config.example.php
└── tests/                        # Unit, Integration, Reference and Accuracy suites
```

All of `src/` except the template and strings loads through Composer's autoloader.

## Accuracy

Solar positions come from the NREL SPA (Reda & Andreas, 2008), specified to ±0.0003°. The test suite checks:

- sunrise against PHP's `date_sun_info()` every day for a year in Rome, New York and Sydney, within 2 minutes;
- twilight times for Mapello, Italy, against published reference times, within 1–2 minutes;
- equinoxes, solstices and moon phases (Meeus, *Astronomical Algorithms*) against published dates.

## License

MIT. See [LICENSE](LICENSE).
