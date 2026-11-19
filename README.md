# sunrise-sunset-calendar

[![CI](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml)
[![Python](https://img.shields.io/badge/Python-3.15-blue.svg)](https://www.python.org/)
[![License](https://img.shields.io/badge/license-MIT%20%2B%20GPL--3.0-green.svg)](#licence)

An [iCalendar](https://en.wikipedia.org/wiki/ICalendar) feed of sunrise, sunset, twilight and moon phases for any location, computed with the [NREL Solar Position Algorithm](https://midcdmz.nrel.gov/spa/). Google Calendar, Apple Calendar and Outlook can subscribe to it, and a web page builds the subscription URL.

## What the feed contains

- Daylight and night as events, each titled with its length. The description gives the change since yesterday (including how far sunrise and sunset moved on the clock) and where the day sits between the year's shortest and longest.
- Morning and evening civil, nautical and astronomical twilight, each as its own event.
- On Daylight: the noon sun's height, and the morning and evening blue and golden hours.
- On Night: the moon's phase and illumination, and the moonless part of the night.
- Weekly summaries from Sunday to Saturday, with the drift of sunrise and sunset.
- Equinoxes and solstices, named by the local season, with the exact moment and facts for the location.
- All-day events on the days something changes: sunrises or sunsets turning earlier or later, 12 hours of daylight, the start of each meteorological season (outside the tropics), and the start and end of midnight sun, polar night and fully dark nights.
- A calendar description with the location's daylight range, midnight sun, polar night and white nights.
- Correct times across daylight-saving changes, and continuous twilight during polar days and white nights.

Every feed URL carries a secret token.

## Running it

From a clone, with [uv](https://docs.astral.sh/uv/):

```bash
git clone https://github.com/mbologna/sunrise-sunset-calendar.git
cd sunrise-sunset-calendar
uv sync
AUTH_TOKEN="$(openssl rand -hex 32)" PORT=8000 uv run sunrise-sunset-calendar
```

Open `http://localhost:8000/`, enter the token as the password and generate a subscription URL.

As a container, from the image CI publishes on every push to `main`, or built locally:

```bash
docker run -p 8080:8080 -e AUTH_TOKEN="$(openssl rand -hex 32)" ghcr.io/mbologna/sunrise-sunset-calendar:latest

docker build -t sunrise-sunset-calendar .
docker run -p 8080:8080 -e AUTH_TOKEN="$(openssl rand -hex 32)" sunrise-sunset-calendar
```

The server is [waitress](https://docs.pylonsproject.org/projects/waitress/): one process with no C extensions, small enough for a 128 MiB [Cloud Run](https://cloud.google.com/run) instance. The image runs as an unprivileged user, has no pip and listens on `$PORT`. Images are tagged `latest` and `sha-<commit>`; there are no version releases.

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

## Endpoints

| Path | Response |
|------|----------|
| `/` | The web page that builds subscription URLs |
| `/?feed=1&…` | The feed (parameters below) |
| `/assets/` | The page's CSS and JavaScript; its address search calls [Nominatim](https://nominatim.org/) from the browser |
| `/health` | `{"status":"ok","python":"<version>","timestamp":<unix time>}`, with `Cache-Control: no-store` |

Every other path returns 404. Behind a proxy that serves the app on another host, it builds subscription URLs from the `X-Forwarded-Host` header and ignores a malformed value.

## Feed URL parameters

`https://example.com/?feed=1&token=TOKEN&lat=40.7128&lon=-74.0060&zone=America/New_York&daynight=1`

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

Any selection of event types still carries every time. A period without its own event goes into the description of the subscribed event nearest in time: the one that contains it or comes just before it. Each description lists its times as one schedule in time order (`🕒 Today`, `🕒 This evening`), then the carried period's details in a section of their own. When there is no Daylight event, the schedule includes blue and golden hour.

On a twilight-only calendar, daylight rides on the last morning twilight and night on the last evening one. The civil twilight that ends at sunrise says how sunrise moved; the one that starts at sunset says how sunset moved and gives the evening's blue and golden hour. On a daylight-and-night calendar, Daylight lists this evening's twilights and Night tomorrow morning's.

## Events

Each event is a period titled with its name. Its description opens with what changed since yesterday, then an "Over the year" bar between the year's shortest and longest (`9h 08m ▕█░░░░░░░░░▏ 15h 14m`, linear, both ends labelled) with the distance to each and their dates. It closes with a plain-language explanation of the period.

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

Sunrise and sunset are when the sun's top edge crosses the horizon, allowing for refraction. Weekly summaries, equinoxes, solstices and the other days of change are all-day events on their local date, so they appear whatever event types the feed includes. An event states facts about its own day only; a fact about another day gets an event on that day.

When the sun never crosses a boundary, an event runs to or from solar noon or solar midnight, and its description says why. During polar night, civil twilight peaks at solar noon. Under the midnight sun, daylight lasts 24 hours and no twilight or night events appear. Wherever the sun stays within 18° of the horizon all night (above about 48.6° latitude around the summer solstice), there are no night events. Above about 84.6° the winter noon sun can stay more than 18° below the horizon, and night then runs from solar noon to solar noon.

## Accuracy

Solar positions come from the NREL SPA (Reda & Andreas, 2008), specified to ±0.0003°. The tests check:

- sunrise against PHP's `date_sun_info()` algorithm every day for a year in Rome, New York and Sydney, within 2 minutes;
- twilight times for Mapello, Italy, against published reference times, within 1 to 2 minutes;
- equinoxes, solstices and moon phases (Meeus, *Astronomical Algorithms*) against published dates, moon illumination at each phase within 1%, and moonrise and moonset against the US Naval Observatory within 3 minutes.

Time zones come from the pinned [`tzdata`](https://pypi.org/project/tzdata/) package, never the host.

## Development

```bash
uv sync                          # Python 3.15 and the dev tools
uv run pytest                    # all tests, with the coverage gate (80%)
uv run ruff check . && uv run ruff format .
uv run pre-commit run --all-files  # ruff, mypy, bandit, hadolint and file checks
```

CI runs ruff and the tests through `mbologna/.github`'s `python-ci`, then builds the image and smoke-tests it: the image must answer the health check and serve a feed. Each push to `main` that changes the image deploys it to Cloud Run. The skeleton (Dockerfile, CI, lint and test settings) comes from [python-app-template](https://github.com/mbologna/python-app-template); `.copier-answers.yml` records the answers its updates read.

```
├── src/sunrise_sunset_calendar/
│   ├── app.py          # WSGI app: configuration, routing, headers, input sanitisation; main() serves it
│   ├── feed.py         # generate_calendar(): builds the VCALENDAR
│   ├── helpers.py      # iCalendar escaping and folding, descriptions, year statistics
│   ├── astronomy.py    # sun_events(), twilight, equinoxes and solstices, moon phases, moonrise and moonset
│   ├── spa.py          # NREL Solar Position Algorithm: solar position, sunrise, transit and sunset
│   ├── php.py          # the PHP behaviours the output depends on (see below)
│   ├── web.py          # the web page
│   ├── strings.py      # all user-facing text
│   ├── timezones.py    # accepted time zones (the tz database's zone.tab, plus UTC)
│   ├── cache.py        # per-request memoisation
│   └── assets/         # the page's CSS and JavaScript
└── tests/              # unit, integration, reference and accuracy suites, the golden corpus, PHP references
```

### Parity with the PHP version

Up to v2 the calendar was written in PHP. The Python version produces the same feeds byte for byte, apart from `DTSTAMP`, the moment a feed is generated, so existing subscriptions, URLs, parameters and tokens work unchanged. The PHP version's `/sunrise-sunset-calendar.php` path and `/?health` check are gone.

- `php.py` reproduces the PHP 8.5 primitives whose results reach the output: rounding, float-to-string conversion, input filters, `strip_tags()`, multibyte truncation, date formatting and wall-clock time zone arithmetic. Each follows php-src's C code, so edge cases match too.
- `tests/php_reference/` records what PHP returns for those primitives, and `test_php.py` checks `php.py` against it.
- `tests/golden/` holds 1004 responses recorded from the last PHP release: 46 locations (polar day and night, both hemispheres, the equator, the date line, every kind of DST rule, including changes at midnight), the full default window, extra dates (DST changes, New Year, a leap day), every combination of event types, offsets, notes, malformed input, environment settings, the web page and its form. `test_golden.py` replays every request against the Python app and compares the bytes. `make_corpus.py` records the corpus again from the PHP image (needs Docker), and `http_parity.py` compares the two images' status codes and headers over HTTP.

The sunrise and sunset times come from a line-by-line port of [sun-position-spa-php](https://github.com/abbadon1334/sun-position-spa-php), the library the PHP version used. Its interpolation of rise and set times differs slightly from other SPA implementations, and the feeds keep their times to the second.

## Licence

The code is MIT ([LICENSE](LICENSE)), except `src/sunrise_sunset_calendar/spa.py`, which is GPL-3.0 ([LICENSES/GPL-3.0-only.txt](LICENSES/GPL-3.0-only.txt)).

`spa.py` is a port of [sun-position-spa-php](https://github.com/abbadon1334/sun-position-spa-php) by Francesco Danti. That project declares MIT in its source headers and package metadata, but the LICENSE file it ships is the GNU GPL version 3. To satisfy either reading, `spa.py` is distributed under the GPL version 3 with the upstream MIT notice kept. Because the application and its container image include `spa.py`, they are distributed under the GPL's terms as a whole; the MIT-licensed files can still be reused on their own under MIT.
