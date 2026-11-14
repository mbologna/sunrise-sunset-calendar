# Sun & Twilight Calendar Generator

[![CI](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml)
[![Python](https://img.shields.io/badge/Python-3.15-blue.svg)](https://www.python.org/)
[![License](https://img.shields.io/badge/license-MIT%20%2B%20GPL--3.0-green.svg)](#license)

An iCalendar feed of daily sunrise, sunset and twilight events for any location, computed with the NREL Solar Position Algorithm. Subscribe to it from Google Calendar, Apple Calendar or Outlook.

## Features

- Morning and evening civil, nautical and astronomical twilight as separate events
- Daylight and night events with their length in the title, the change since yesterday (including how far sunrise and sunset moved on the clock), and where today sits between the year's shortest and longest
- The noon sun's height, and blue and golden hour on the Daylight event (photographers' rules of thumb)
- The moon on night events: phase, illumination, and the moonless part of the night
- Weekly daylight summaries spanning Sunday to Saturday, with sunrise and sunset drift
- Equinoxes and solstices named by the local season, with the exact moment and facts for the location
- All-day events on the days something changes, each told on its own day: sunrises or sunsets turning earlier or later, 12 hours of daylight, the start of each meteorological season (outside the tropics), and the start and end of midnight sun, polar night and fully dark nights
- A plain-language explanation of each period in its description
- A calendar description with the location's daylight range, midnight sun, polar night and white nights
- Correct times across daylight-saving changes, and continuous twilight during polar days and white nights
- Token-protected feed URLs

## Installation

```bash
git clone https://github.com/mbologna/sunrise-sunset-calendar.git
cd sunrise-sunset-calendar
uv sync
AUTH_TOKEN="$(openssl rand -hex 32)" PORT=8000 uv run sunrise-sunset-calendar
```

Open `http://localhost:8000/`, enter the token as the password and generate a subscription URL. The server is [waitress](https://docs.pylonsproject.org/projects/waitress/): one process, no C extensions, which fits a 128 MiB Cloud Run instance.

### Docker

```bash
docker build -t sunrise-sunset-calendar .
docker run -p 8080:8080 -e AUTH_TOKEN="$(openssl rand -hex 32)" sunrise-sunset-calendar
```

The image runs as an unprivileged user, has no pip, and listens on `$PORT` (default 8080), so it runs unchanged on Cloud Run. It serves the page at `/` and `/sunrise-sunset-calendar.php`, the feed at either with `?feed=1`, and the page's CSS and JavaScript under `/assets/`; every other path is a 404.

## Configuration

All settings are environment variables.

| Setting | Default | Meaning |
|---------|---------|---------|
| `AUTH_TOKEN` | none (required) | Secret embedded in feed URLs and used as the web form password. Without it every request gets a 500. |
| `CALENDAR_WINDOW_DAYS` | `400` | Days of future events in the feed |
| `CALENDAR_PAST_DAYS` | `30` | Days of past events in the feed |
| `UPDATE_INTERVAL` | `86400` | Refresh interval suggested to calendar apps, in seconds |
| `PORT` | `8080` | Port the server listens on |
| `THREADS` | `2` | Requests served at once; each full feed takes about 15 MB while it is built |

The health check, `/?health`, returns `{"status":"ok","python":"<version>","timestamp":<unix time>}`.

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

Types not subscribed to as events are carried in the descriptions of the events that are, so any selection still carries every time. Each piece goes once, on the subscribed event nearest in time: the one that contains it or comes right before it. The times come as one schedule in time order (`🕒 Today`, `🕒 This evening`), blue and golden hour included when there is no Daylight event, followed by the carried period's details as a section of its own. On a twilight-only calendar, daylight rides on the last morning twilight and night on the last evening one; the civil twilight that ends at sunrise says how sunrise moved, and the one that starts at sunset says how sunset moved and gives the evening's blue and golden hour. On a daylight-and-night calendar, Daylight lists this evening's twilights and Night tomorrow morning's.

## Event types

Each event is a period, titled with its name; the calendar shows when it starts and ends, so the description doesn't repeat that. It opens with what changed since yesterday, then where today sits in the year: an "Over the year" bar between the year's shortest and longest (`9h 08m ▕█░░░░░░░░░▏ 15h 14m`, linear, with both ends labelled), and how far today is from each, with their dates. Night gives the moonless part of the night, the window that matters for stargazing. Daylight also lists the day's blue and golden hours, morning and evening. An explanation of the period comes last.

| Event | Title | From | To |
|-------|-------|------|----|
| Morning astronomical twilight | 🌌 Morning Astronomical Twilight | astronomical dawn (sun 18° below) | nautical dawn (12° below) |
| Morning nautical twilight | ⚓ Morning Nautical Twilight | nautical dawn | civil dawn (6° below) |
| Morning civil twilight | 🌅 Morning Civil Twilight | civil dawn | sunrise |
| Daylight | ☀️ Daylight · *length* | sunrise | sunset |
| Evening civil twilight | 🌇 Evening Civil Twilight | sunset | civil dusk (6° below) |
| Evening nautical twilight | ⚓ Evening Nautical Twilight | civil dusk | nautical dusk (12° below) |
| Evening astronomical twilight | 🌌 Evening Astronomical Twilight | nautical dusk | astronomical dusk (18° below) |
| Night | *moon phase* Night · *length* | astronomical dusk | the next astronomical dawn |

Sunrise and sunset are when the sun's top edge crosses the horizon, allowing for refraction. Weekly summaries are all-day events from Sunday to Saturday. Equinoxes, solstices and the other days when something changes are all-day events on their local date, so they show in every calendar view, whatever event types the feed includes. An event only states facts about its own day; a fact about another day gets an event on that day.

When the sun never crosses a boundary, an event runs to or from solar noon or solar midnight instead, and its description says why. During polar night, civil twilight peaks at solar noon; under the midnight sun, daylight lasts 24 hours and no twilight or night events appear. Wherever the sun stays within 18° of the horizon all night (above about 48.6° latitude around the summer solstice), there are no night events. Above about 84.6° latitude the noon sun can stay more than 18° below the horizon in winter; night then runs from solar noon to solar noon, around the clock.

## Development

```bash
uv sync                     # Python 3.15 and the dev tools
uv run pytest               # all tests, with the coverage gate (80%)
uv run ruff check .
uv run ruff format .
```

CI runs the same checks through `mbologna/.github`'s `python-ci`, then builds the image, smoke-tests it (it must answer the health check and serve a feed), and on `main` deploys it to Cloud Run.

### Parity with the PHP version

Up to v2 the calendar was written in PHP. The Python version produces the same feeds byte for byte; only `DTSTAMP`, the moment a feed is generated, differs. That is what makes it a drop-in replacement: the same URLs, parameters and token keep working, and existing subscriptions see no change.

- `tests/golden/` holds a corpus of 1004 responses recorded from the last PHP release: 46 locations (polar day and night, both hemispheres, the equator, the date line, DST rules of every kind, including changes at midnight), the full default window, three more dates (DST changes, New Year, a leap day), the days DST changes skip or repeat midnight, every combination of event types, offsets, notes, malformed input, environment settings, the web page and its form. `test_golden.py` replays every request against the Python app and compares the bytes. `make_corpus.py` records the corpus again from the PHP image (needs Docker), and `http_parity.py` compares status codes and headers of the two images over real HTTP.
- `tests/php_reference/` records what PHP returns for the primitives the output depends on (rounding, float formatting, input filters, `strip_tags()`, `mb_substr()`, query parsing, wall-clock time zone arithmetic around DST changes), and `test_php.py` checks the Python equivalents in `php.py` against it.

The sunrise and sunset times come from a line-by-line port of the [sun-position-spa-php](https://github.com/abbadon1334/sun-position-spa-php) library the PHP version used: its interpolation of rise and set times differs slightly from other SPA implementations, and the feeds must keep their times to the second. Time zones come from the pinned `tzdata` package, never the host.

## Project structure

```
├── src/sunrise_sunset_calendar/
│   ├── app.py          # WSGI entry point: configuration, routing, headers, input sanitisation; main() serves it
│   ├── feed.py         # generate_calendar(): builds the VCALENDAR
│   ├── helpers.py      # iCalendar escaping and folding, descriptions, year statistics
│   ├── astronomy.py    # sun_events(), twilight, equinoxes and solstices, moon phases, moonrise and moonset
│   ├── spa.py          # NREL Solar Position Algorithm: solar position, sunrise, transit and sunset
│   ├── php.py          # the PHP behaviours the output depends on, reproduced exactly
│   ├── web.py          # the web form page
│   ├── strings.py      # all user-facing text
│   ├── timezones.py    # accepted time zones (the tz database's zone.tab, plus UTC)
│   ├── cache.py        # per-request memoisation
│   └── assets/         # web form CSS and JavaScript (address search calls Nominatim from the browser)
└── tests/              # unit, integration, reference and accuracy suites, the golden corpus and PHP references
```

## Accuracy

Solar positions come from the NREL SPA (Reda & Andreas, 2008), specified to ±0.0003°. The test suite checks:

- sunrise against PHP's `date_sun_info()` algorithm every day for a year in Rome, New York and Sydney, within 2 minutes;
- twilight times for Mapello, Italy, against published reference times, within 1–2 minutes;
- equinoxes, solstices and moon phases (Meeus, *Astronomical Algorithms*) against published dates, moon illumination at each phase within 1%, and moonrise and moonset against the US Naval Observatory within 3 minutes.

## License

The code is MIT ([LICENSE](LICENSE)), except `src/sunrise_sunset_calendar/spa.py`, which is GPL-3.0 ([LICENSES/GPL-3.0-only.txt](LICENSES/GPL-3.0-only.txt)).

`spa.py` is a port of [sun-position-spa-php](https://github.com/abbadon1334/sun-position-spa-php) by Francesco Danti. That project declares MIT in its source headers and package metadata, but the LICENSE file it ships is the GNU GPL version 3. To satisfy either reading, `spa.py` is distributed under the GPL version 3 with the upstream MIT notice kept. Because the application and its container image include `spa.py`, they are distributed under the GPL's terms as a whole; the MIT-licensed files can still be reused on their own under MIT.
