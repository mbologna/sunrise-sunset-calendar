# Sun & Twilight Calendar Generator

[![CI](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

An iCalendar feed of daily sunrise, sunset and twilight events for any location, computed with the NREL Solar Position Algorithm. Subscribe to it from Google Calendar, Apple Calendar or Outlook.

## Features

- Morning and evening civil, nautical and astronomical twilight as separate events
- Daylight and night events with their length in the title, the change since yesterday (including how far sunrise and sunset moved on the clock), and where today sits between the year's shortest and longest
- The noon sun's height, and blue and golden hour on the Daylight event (photographers' rules of thumb)
- The moon on night events: phase, illumination, and the moonless part of the night
- Weekly daylight summaries spanning Sunday to Saturday, with sunrise and sunset drift
- Equinoxes and solstices named by the local season, with the exact moment and facts for the location
- A plain-language explanation of each period in its description
- A calendar description with the location's daylight range, midnight sun, polar night and white nights
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
| `civil`, `nautical`, `astro` | no | `1` adds that twilight's morning and evening events |
| `daynight` | no | `1` adds daylight, night and weekly summary events |
| `rise_off`, `set_off` | no | Minutes (-1440 to 1440) to shift morning and evening events |
| `desc` | no | Note appended to daylight and night events |

Twilight types not subscribed to as events are listed in the description of the events that are, so a calendar with only daylight and night events still carries every time.

## Event types

Each event is a period, titled with its name; the calendar shows when it starts and ends, so the description doesn't repeat that. It opens with what changed since yesterday, then where today sits in the year: a bar between the year's shortest and longest (`9h 08m ▕█░░░░░░░░░▏ 15h 14m`, linear, with both ends labelled), and how far today is from each, with their dates. Night gives the moonless part of the night, the window that matters for stargazing. Daylight also lists the day's blue and golden hours, morning and evening; without a Daylight event, they come with the daylight block in the morning twilight's notes. An explanation of the period comes last.

| Event | Title | From | To |
|-------|-------|------|----|
| Morning astronomical twilight | 🌌 Astronomical Twilight | astronomical dawn (sun 18° below) | nautical dawn (12° below) |
| Morning nautical twilight | ⚓ Nautical Twilight | nautical dawn | civil dawn (6° below) |
| Morning civil twilight | 🌅 Civil Twilight | civil dawn | sunrise |
| Daylight | ☀️ Daylight · *length* | sunrise | sunset |
| Evening civil twilight | 🌇 Civil Twilight | sunset | civil dusk (6° below) |
| Evening nautical twilight | ⚓ Nautical Twilight | civil dusk | nautical dusk (12° below) |
| Evening astronomical twilight | 🌌 Astronomical Twilight | nautical dusk | astronomical dusk (18° below) |
| Night | *moon phase* Night · *length* | astronomical dusk | the next astronomical dawn |

Sunrise and sunset are when the sun's top edge crosses the horizon, allowing for refraction. Weekly summaries are all-day events from Sunday to Saturday. Equinoxes and solstices are all-day events on their local date.

When the sun never crosses a boundary, an event runs to or from solar noon or solar midnight instead, and its description says why. During polar night, civil twilight peaks at solar noon; under the midnight sun, daylight lasts 24 hours and no twilight or night events appear. Wherever the sun stays within 18° of the horizon all night (above about 48.6° latitude around the summer solstice), there are no night events. Above about 84.6° latitude the noon sun can stay more than 18° below the horizon in winter; night then runs from solar noon to solar noon, around the clock.

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
- equinoxes, solstices and moon phases (Meeus, *Astronomical Algorithms*) against published dates, moon illumination at each phase within 1%, and moonrise and moonset against the US Naval Observatory within 3 minutes.

## License

MIT. See [LICENSE](LICENSE).
