# sunrise-sunset-calendar

[![CI](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/mbologna/sunrise-sunset-calendar/actions/workflows/ci.yml)
[![Python](https://img.shields.io/badge/Python-3.15-blue.svg)](https://www.python.org/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](#licence)

An [iCalendar](https://en.wikipedia.org/wiki/ICalendar) feed of sunrise, sunset, twilight and moon phases for any location, computed from NASA JPL's [DE442 ephemeris](https://naif.jpl.nasa.gov/pub/naif/generic_kernels/spk/planets/de442_tech-comments.txt) with [skyfield](https://rhodesmill.org/skyfield/). Google Calendar, Apple Calendar and Outlook can subscribe to it, and a web page builds the subscription URL.

## What the feed contains

- Daylight and night as events, each titled with its length. The description gives the change since yesterday with its percentage, the day's rank among the year's, and how it compares with the year's shortest and longest.
- Morning and evening civil, nautical and astronomical twilight, each as its own event, opening with how it changes the sky ("Horizon appears as stars fade"). Civil twilight runs from first light to sunrise and from sunset to last light, as weather apps such as Apple Weather use the terms; the military uses them for nautical twilight's 12°.
- On Daylight: what the sun does (how far sunrise and sunset moved on the clock, and its highest point) and the morning and evening blue and golden hours.
- On Night: the moon's phase and illumination, and the moonless part of the night.
- Weekly summaries from Sunday to Saturday, with the drift of sunrise and sunset, the change in daylight and the moon's principal phases.
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

Open `http://localhost:8000/?token=<the token>` and generate a subscription URL. Bookmark that link: without the token the page answers 403, like the feed.

As a container, from the image CI publishes on every push to `main`, or built locally:

```bash
docker run -p 8080:8080 -e AUTH_TOKEN="$(openssl rand -hex 32)" ghcr.io/mbologna/sunrise-sunset-calendar:latest

docker build -t sunrise-sunset-calendar .
docker run -p 8080:8080 -e AUTH_TOKEN="$(openssl rand -hex 32)" sunrise-sunset-calendar
```

The server is [waitress](https://docs.pylonsproject.org/projects/waitress/), one process, small enough for a 128 MiB [Cloud Run](https://cloud.google.com/run) instance: about 30 MiB idle, and at most about 70 MiB with the default two threads (80 MiB with four) while new places are searched. A place's first feed takes about a second (up to two above 60° of latitude) while skyfield searches its years, one search at a time; later feeds reuse them. The image runs as an unprivileged user, has no pip and listens on `$PORT`. Images are tagged `latest` and `sha-<commit>` only and carry no version number. Each push to `main` that changes the image deploys it to Cloud Run by its digest.

## Configuration

All settings are environment variables.

| Setting | Default | Meaning |
|---------|---------|---------|
| `AUTH_TOKEN` | none (required) | Secret in the feed URLs and in the page's (`/?token=…`). Without it, or `AUTH_TOKENS`, every request gets a 500. |
| `AUTH_TOKENS` | none | More secrets, comma-separated, each as good as `AUTH_TOKEN`. Give each person their own: the page builds feed URLs with the token it was opened with, so removing one revokes that person's feeds only. |
| `CALENDAR_WINDOW_DAYS` | `400` | Days of future events in the feed; the ephemeris ends in 2150, so no feed can reach past 2148 |
| `CALENDAR_PAST_DAYS` | `30` | Days of past events in the feed |
| `UPDATE_INTERVAL` | `86400` | Refresh interval suggested to calendar apps, in seconds |
| `PORT` | `8080` | Port the server listens on |
| `THREADS` | `2` | Requests served at once; each full feed takes about 15 MB while it is built, and skyfield's searches run one at a time |

## Endpoints

| Path | Response |
|------|----------|
| `/?token=…` | The web page that builds subscription URLs; without the token, 403 |
| `/?feed=1&…` | The feed (parameters below) |
| `/api/v1/now?lat=…&lon=…&zone=…` | The sun and the moon at a moment, as JSON (see [JSON](#json)); the token in `?token=` or an `Authorization: Bearer` header |
| `/assets/` | The page's CSS and JavaScript; its address search calls [Nominatim](https://nominatim.org/) from the browser |
| `/health` | `{"status":"ok","years":[2000,2148]}`, the years the ephemeris serves; 503 with `"status":"down"` and the problem when `CALENDAR_PAST_DAYS` or `CALENDAR_WINDOW_DAYS` reach outside them |

Every other path returns 404. Every response carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and a `Content-Security-Policy` that allows only the page's own script and stylesheet, and Nominatim. Feeds are `Cache-Control: private`, because their URL holds the token, and carry an `ETag`: a request whose `If-None-Match` matches it gets a 304 without the feed being built, until local midnight or the next deploy. The page, errors and `/health` are `no-store`. Text responses of 1 KB or more (feeds, the page, JSON, the script and stylesheet) are gzipped for clients that send `Accept-Encoding: gzip`, with `Vary: Accept-Encoding`; a year of events shrinks about 27 times. The feed's `ETag` is weak (`W/"…"`), since both encodings carry the same content. Behind a proxy that serves the app on another host, it builds subscription URLs from the `X-Forwarded-Host` header and ignores a malformed value.

## JSON

`GET /api/v1/now?token=TOKEN&lat=41.9028&lon=12.4964&zone=Europe/Rome` answers with the sun and the moon now, or at the moment `at` names: a Unix time, or an ISO 8601 date and time (read in `zone` without an offset), between 2000 and 2148.

```json
{
  "at": "2026-11-20T12:42:00+01:00",
  "location": {"lat": 41.9028, "lon": 12.4964, "zone": "Europe/Rome"},
  "sun": {
    "altitude": 27.42, "azimuth": 192.3, "up": true, "period": "daylight",
    "today": {
      "astronomical_dawn": "2026-11-20T05:28:00+01:00", "nautical_dawn": "2026-11-20T06:01:10+01:00",
      "civil_dawn": "2026-11-20T06:35:12+01:00", "sunrise": "2026-11-20T07:05:32+01:00",
      "solar_noon": "2026-11-20T11:55:37+01:00", "sunset": "2026-11-20T16:45:19+01:00",
      "civil_dusk": "2026-11-20T17:15:39+01:00", "nautical_dusk": "2026-11-20T17:49:41+01:00",
      "astronomical_dusk": "2026-11-20T18:22:49+01:00", "day_length": 34787, "polar": null,
      "vs_yesterday": {"seconds": -115, "percent": -0.33},
      "year": {
        "shortest": {"date": "2026-12-21", "day_length": 32854},
        "longest": {"date": "2026-06-21", "day_length": 54834},
        "shorter_days": 62, "days": 365
      }
    },
    "next": {"event": "sunset", "at": "2026-11-20T16:45:19+01:00"}
  },
  "moon": {
    "altitude": -15.77, "azimuth": 70.22, "up": false, "illumination": 78.8, "phase": "Waxing Gibbous",
    "next_phase": {"name": "Full Moon", "at": "2026-11-24T15:53:34+01:00"},
    "next_rise": "2026-11-20T14:05:57+01:00", "next_set": "2026-11-21T03:13:29+01:00"
  }
}
```

- `altitude` is the centre of the body above the horizon, in degrees and without refraction; `azimuth` is in degrees from north. `up` follows the feed's rules: the sun's centre above −0.8333°, the moon's upper limb above the horizon.
- `period` is where the sun is: `daylight`, `civil_twilight`, `nautical_twilight`, `astronomical_twilight` or `night`.
- `today` holds the local date's events; an event that doesn't happen (polar day or night) is `null`, and `polar` is `"day"`, `"night"` or `null`. `day_length` is in seconds. `civil_dawn` and `civil_dusk` are what weather apps call first and last light.
- `vs_yesterday` and `year` are the numbers behind the feed's Daylight comparisons, unrounded: the change since yesterday in seconds and as a percentage of yesterday's length (`null` after a day without daylight), the year's shortest and longest day, and how many of the year's days are shorter than today. In polar years many days tie at 0 or 24 hours; the extremes are the first of them.
- The token can come in an `Authorization: Bearer TOKEN` header instead of `?token=`, which keeps it out of request logs and shell history: `curl -H "Authorization: Bearer $TOKEN" 'https://example.com/api/v1/now?lat=41.9028&lon=12.4964&zone=Europe/Rome'`. The header wins when both are given.
- Without a valid token, 401 with `{"error": "Invalid authentication token"}` and `WWW-Authenticate: Bearer`. Input it can't read gets a 400 with `{"error": "…"}`, unlike the feed, which falls back to defaults. Responses are `no-store`.

## Feed URL parameters

`https://example.com/?feed=1&token=TOKEN&lat=40.7128&lon=-74.0060&zone=America/New_York&daynight=1`

| Parameter | Required | Values |
|-----------|----------|--------|
| `feed` | yes | `1` |
| `token` | yes | `AUTH_TOKEN`, or one of `AUTH_TOKENS` |
| `lat` | yes | -90 to 90 |
| `lon` | yes | -180 to 180 |
| `zone` | yes | IANA time zone, e.g. `Europe/Rome` |
| `location` | no | Name shown in the calendar title |
| `civil`, `nautical`, `astro` | no | `1` adds that twilight's morning and evening events |
| `daynight` | no | `1` adds daylight, night and weekly summary events |
| `rise_off`, `set_off` | no | Minutes (-1440 to 1440) to shift morning and evening events |
| `desc` | no | Note appended to daylight and night events |

Any selection of event types still carries every time. A period without its own event goes into the description of the subscribed event nearest in time: the one that contains it or comes just before it. Each description lists its times as one schedule in time order (`🕒 This morning`, `🕒 This evening`), each twilight with a short note, then the carried period's details in a section of their own, headed with its times. When there is no Daylight event, the schedule includes blue and golden hour.

On a twilight-only calendar, daylight rides on the last morning twilight and night on the last evening one. The twilight nearest sunrise says how sunrise moved and where the sun peaks, the one nearest sunset how sunset moved; evening civil twilight gives the evening's blue and golden hour. On a daylight-and-night calendar, Daylight lists this evening's twilights and Night tomorrow morning's.

## Events

Each event is a period titled with its name. Every description keeps one layout: the schedule (`🕒`), what the sun does (`☀️ Sun`), the period's numbers (`🔆 Daylight`, `🌙 Night`), the moon (`🌔 Moon`), and a closing plain-language explanation (`ℹ️ About`); each emoji means the same thing in every event. The numbers open with the change since yesterday (`vs yesterday: −1m 50s (−0.3%)`), then a `Year:` bar between the year's shortest and longest (`9h 08m ▕█░░░░░░░░░▏ 15h 14m`, linear, both ends labelled), the rank among the year's days (`Longer than 58 of 365 days (16%)`) and the comparison with each extreme (`vs shortest (Dec 21): +29m (+5.2%)`). Data lines stay within 38 columns, so they don't wrap in macOS Calendar's popover, on an iPhone or in Google Calendar's popup; only prose does.

| Event | Title | From | To |
|-------|-------|------|----|
| Morning astronomical twilight | 🌌 Morning Astronomical Twilight | astronomical dawn (sun 18° below) | nautical dawn (12° below) |
| Morning nautical twilight | ⚓ Morning Nautical Twilight | nautical dawn | civil dawn (6° below) |
| Morning civil twilight | 🌅 Morning Civil Twilight | civil dawn | sunrise |
| Daylight | 🔆 Daylight · *length* | sunrise | sunset |
| Evening civil twilight | 🌇 Evening Civil Twilight | sunset | civil dusk (6° below) |
| Evening nautical twilight | ⚓ Evening Nautical Twilight | civil dusk | nautical dusk (12° below) |
| Evening astronomical twilight | 🌌 Evening Astronomical Twilight | nautical dusk | astronomical dusk (18° below) |
| Night | *moon phase* Night · *length* | astronomical dusk | the next astronomical dawn |

Sunrise and sunset are when the sun's top edge crosses the horizon, allowing for refraction. Weekly summaries, equinoxes, solstices and the other days of change are all-day events on their local date, so they appear whatever event types the feed includes. An event states facts about its own day only; a fact about another day gets an event on that day.

When the sun never crosses a boundary, an event runs to or from solar noon or solar midnight, and its description says why. During polar night, civil twilight peaks at solar noon. Under the midnight sun, daylight lasts 24 hours and no twilight or night events appear. On the day the midnight sun begins or ends, the sun crosses the horizon once, so daylight runs from sunrise to solar midnight or from solar midnight to sunset; twilight boundaries work the same way. The first sunset after the midnight sun can come after local midnight, and its day of change is the date it happens on. Wherever the sun stays within 18° of the horizon all night (above about 48.6° latitude around the summer solstice), there are no night events. Above about 84.6° the winter noon sun can stay more than 18° below the horizon, and night then runs from solar noon to solar noon.

## Accuracy

The sun and the moon come from NASA JPL's DE442 ephemeris (2024), the latest, through [skyfield](https://rhodesmill.org/skyfield/), with ΔT and leap seconds from skyfield's built-in IERS tables. The package ships a 12 MB excerpt of it, `data/de442-1999-2150.bsp`: the Sun, the Earth and the Moon, and Jupiter and Saturn, whose gravity bends light, from 1999 to 2150, enough for feeds up to 2148. Nothing is downloaded at run time; `astronomy.py` says how to make the excerpt again.

- Sunrise and sunset are the moments the sun's centre is 0.8333° below the horizon: 34′ of refraction plus its 16′ radius, the US Naval Observatory's definition. Twilight, blue hour and golden hour use the sun's centre at -18°, -12°, -6°, -4° and +6°, without refraction.
- A date's events belong to the solar day whose transit (solar noon) falls nearest the date's local noon; the day runs from the solar midnight before it to the one after, so each crossing belongs to one day.
- skyfield's rise and set search misses crossings that skim the horizon: the moon's from about 70° of latitude, the sun's from about 88°. From 60° for the moon and 85° for the sun, the altitude is sampled every 10 minutes instead and each crossing refined; where both methods work they agree to a millisecond.
- Each search covers a UTC year for a place and stays cached across requests (about 50 kB a year and place).

Real times can still differ by a minute or more near the horizon: refraction changes with the weather, and the feed assumes a flat horizon at sea level. The tests check:

- sunrise against Paul Schlyter's sunriset algorithm, an independent solar model, every day for a year in Rome, New York and Sydney, within 2 minutes;
- twilight times for Mapello, Italy, against published reference times, within a minute;
- equinoxes, solstices and moon phases against published times, which give the minute, within 60 seconds; moon illumination at each phase within 1%; and moonrise and moonset against the US Naval Observatory within 30 seconds (its tables round to the minute).

Time zones come from the pinned [`tzdata`](https://pypi.org/project/tzdata/) package, never the host.

## Development

```bash
uv sync                          # Python 3.15 and the dev tools
uv run pytest                    # all tests in parallel (pytest-xdist), with the coverage gate (80%)
uv run ruff check . && uv run ruff format .
uv run pre-commit run --all-files  # ruff, mypy, bandit, hadolint and file checks
```

CI runs ruff and the tests through `mbologna/.github`'s `python-ci`, then builds the image and smoke-tests it: the image must answer the health check and serve a feed. Each push to `main` that changes the image deploys it to Cloud Run. The skeleton (Dockerfile, CI, lint and test settings) comes from [python-app-template](https://github.com/mbologna/python-app-template); `.copier-answers.yml` records the answers its updates read.

```
├── src/sunrise_sunset_calendar/
│   ├── app.py          # WSGI app: configuration, routing, headers, input sanitisation; main() serves it
│   ├── api.py          # the JSON endpoint: the sun and the moon at a moment
│   ├── feed.py         # generate_calendar(): builds the VCALENDAR
│   ├── helpers.py      # iCalendar escaping and folding, descriptions, year statistics
│   ├── astronomy.py    # skyfield: sun_events(), twilight, equinoxes and solstices, moon phases, moonrise and moonset
│   ├── clock.py        # dates and times in the feed's zone, on datetime and zoneinfo
│   ├── numbers.py      # rounding, and how coordinates are written
│   ├── web.py          # the web page
│   ├── strings.py      # all user-facing text
│   ├── timezones.py    # accepted time zones (the tz database's zone.tab, plus UTC)
│   ├── cache.py        # per-request memoisation
│   ├── assets/         # the page's CSS and JavaScript
│   └── data/           # the JPL DE442 excerpt (see astronomy.py)
└── tests/              # unit, integration, reference and accuracy suites, the golden corpus
```

### Golden corpus

`tests/golden/` holds 403 recorded responses: 46 locations (polar day and night, both hemispheres, the equator, the date line, every kind of DST rule, including changes at midnight), the full default window, extra dates (DST changes, New Year, a leap day), every combination of event types for six representative places, offsets, notes, malformed input, environment settings, the web page and its form. `test_golden.py` replays every request and compares the bytes, apart from `DTSTAMP`, the moment a feed is generated. When the output changes on purpose, `uv run python -m tests.golden.make_corpus` records the corpus again; review the changed lines before committing. `test_width.py` holds every data line of every recorded description to 38 columns, an emoji counting as two; prose (a sentence ending in a full stop, or a line over 60 columns) may wrap.

## Versioning

`main` is what runs: each push to it that changes the image deploys it to Cloud Run ([Running it](#running-it)). Releases follow SemVer as a changelog: every Monday at 06:00 UTC [auto-release](https://github.com/mbologna/auto-release) tags `vX.Y.Z` from the [Conventional Commit](https://www.conventionalcommits.org/) types merged since the last tag (`feat` minor; `fix`, `perf`, `revert` patch; a breaking change major), or cuts a maintenance release for a week of only dependency updates. The tag builds no image and deploys nothing; its [GitHub Release](https://github.com/mbologna/sunrise-sunset-calendar/releases) lists the changes. To see the next release before Monday, open the summary of the "Release preview" job in a pull request's checks (as if it merged now) or in the latest CI run on `main`, or run `docker run --rm -v "$PWD:/repo:ro" -w /repo -e AUTO_RELEASE_STRATEGY=conventional -e AUTO_RELEASE_FORGE=none ghcr.io/mbologna/auto-release:2.0.0 auto-release plan` in a clone. The preview only shows a maintenance release once it is due, when the last tag is a week old: one that becomes due by Monday appears on Monday's run alone. See the [versioning standard](https://github.com/mbologna/.github/blob/main/VERSIONING.md).

## Licence

The code is MIT ([LICENSE](LICENSE)). skyfield is MIT too, and the DE442 ephemeris is a work of NASA JPL.
