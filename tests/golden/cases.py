"""The golden-master corpus: requests answered by the last PHP release, replayed against the port.

Each case is one web request: a query string, an optional urlencoded POST
body, the environment, and the moment it is made (the PHP side ran under
libfaketime). make_corpus.py records the PHP responses; test_golden.py
replays every case and wants the same bytes, DTSTAMP aside.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from itertools import product
from urllib.parse import quote

TOKEN = "golden-token"


@dataclass(frozen=True)
class Case:
    id: str
    group: str
    query: str = ""
    body: str | None = None
    env: dict[str, str] = field(default_factory=dict)
    now: str = "2026-03-29 10:00:00"
    https: bool = False
    host: str = "calendar.example"


# name, latitude, longitude, time zone
LOCATIONS = [
    # Polar day and night, white nights
    ("Longyearbyen", 78.2232, 15.6267, "Arctic/Longyearbyen"),
    ("McMurdo", -77.846, 166.676, "Antarctica/McMurdo"),
    ("Tromsø", 69.6492, 18.9553, "Europe/Oslo"),
    ("Utqiagvik", 71.2906, -156.7886, "America/Anchorage"),
    ("Murmansk", 68.9585, 33.0827, "Europe/Moscow"),
    ("Reykjavik", 64.1466, -21.9426, "Atlantic/Reykjavik"),
    ("Nuuk", 64.1814, -51.6941, "America/Nuuk"),
    ("Scoresbysund", 70.4853, -21.9667, "America/Scoresbysund"),
    ("Helsinki", 60.1699, 24.9384, "Europe/Helsinki"),
    ("Ushuaia", -54.8019, -68.303, "America/Argentina/Ushuaia"),
    ("North Pole", 90.0, 0.0, "UTC"),
    ("South Pole", -90.0, 0.0, "Antarctica/McMurdo"),
    # Mid-latitudes, DST rules of every kind
    ("Rome", 41.9028, 12.4964, "Europe/Rome"),
    ("New York", 40.7128, -74.006, "America/New_York"),
    ("London", 51.5074, -0.1278, "Europe/London"),
    ("Dublin", 53.3498, -6.2603, "Europe/Dublin"),
    ("Madrid", 40.4168, -3.7038, "Europe/Madrid"),
    ("Tokyo", 35.6762, 139.6503, "Asia/Tokyo"),
    ("Los Angeles", 34.0522, -118.2437, "America/Los_Angeles"),
    ("Phoenix", 33.4484, -112.074, "America/Phoenix"),
    ("St. John's", 47.5615, -52.7126, "America/St_Johns"),
    ("Kathmandu", 27.7172, 85.324, "Asia/Kathmandu"),
    ("Delhi", 28.6139, 77.209, "Asia/Kolkata"),
    ("Tehran", 35.6892, 51.389, "Asia/Tehran"),
    ("Havana", 23.1136, -82.3666, "America/Havana"),
    ("Beirut", 33.8938, 35.5018, "Asia/Beirut"),
    ("Cairo", 30.0444, 31.2357, "Africa/Cairo"),
    ("Casablanca", 33.5731, -7.5898, "Africa/Casablanca"),
    # Tropics and the equator
    ("Quito", -0.1807, -78.4678, "America/Guayaquil"),
    ("Singapore", 1.3521, 103.8198, "Asia/Singapore"),
    ("Nairobi", -1.2921, 36.8219, "Africa/Nairobi"),
    ("Null Island", 0.0, 0.0, "UTC"),
    ("Honolulu", 21.3069, -157.8583, "Pacific/Honolulu"),
    ("Rio de Janeiro", -22.9068, -43.1729, "America/Sao_Paulo"),
    ("Tropic of Capricorn", -23.44, -46.6333, "America/Sao_Paulo"),
    # Southern mid-latitudes
    ("Sydney", -33.8688, 151.2093, "Australia/Sydney"),
    ("Lord Howe Island", -31.5553, 159.0821, "Australia/Lord_Howe"),
    ("Chatham Islands", -43.9535, -176.5597, "Pacific/Chatham"),
    ("Santiago", -33.4489, -70.6693, "America/Santiago"),
    ("Asunción", -25.2637, -57.5759, "America/Asuncion"),
    ("Cape Town", -33.9249, 18.4241, "Africa/Johannesburg"),
    ("Auckland", -36.8485, 174.7633, "Pacific/Auckland"),
    # Around the date line
    ("Apia", -13.8333, -171.7667, "Pacific/Apia"),
    ("Kiritimati", 1.8721, -157.4278, "Pacific/Kiritimati"),
    ("Pago Pago", -14.2756, -170.702, "Pacific/Pago_Pago"),
    # A zone far from the place: local times a day away from solar time
    ("London in Kiritimati time", 51.5074, -0.1278, "Pacific/Kiritimati"),
]

FLAGS = ("civil", "nautical", "astro", "daynight")
ALL_FLAGS = dict.fromkeys(FLAGS, "1")


def slug(name: str) -> str:
    return "".join(c if c.isalnum() else "-" for c in name.lower()).strip("-")


def feed_query(lat: float, lon: float, zone: str, location: str = "", flags=ALL_FLAGS, **extra: str) -> str:
    params = {"feed": "1", "token": TOKEN, "lat": repr(lat), "lon": repr(lon), "zone": zone}
    if location:
        params["location"] = location
    params.update(flags)
    params.update(extra)
    return "&".join(f"{k}={quote(str(v), safe='')}" for k, v in params.items())


def _cases() -> list[Case]:
    cases: list[Case] = []

    # A. Every location, the full default window (30 days back, 400 ahead), every event type
    for name, lat, lon, zone in LOCATIONS:
        cases.append(Case(f"full-{slug(name)}", "full", feed_query(lat, lon, zone, name)))

    # B. Three more moments: DST changes, solstices, New Year and leap days inside a short window
    windows = {"CALENDAR_PAST_DAYS": "7", "CALENDAR_WINDOW_DAYS": "14"}
    for moment in ("2026-10-25 01:30:00", "2027-12-26 23:59:00", "2028-02-27 12:00:00"):
        for name, lat, lon, zone in LOCATIONS:
            cases.append(
                Case(
                    f"moment-{moment[:10]}-{slug(name)}",
                    "moments",
                    feed_query(lat, lon, zone, name),
                    env=windows,
                    now=moment,
                )
            )

    # C. Every other combination of event types, around the June solstice (midnight sun and polar night)
    sweep = {"CALENDAR_PAST_DAYS": "2", "CALENDAR_WINDOW_DAYS": "9"}
    combos = [c for c in product((False, True), repeat=4) if not all(c)]
    for name, lat, lon, zone in LOCATIONS:
        for combo in combos:
            flags = {f: "1" for f, on in zip(FLAGS, combo, strict=True) if on}
            tag = "".join(f[0] for f, on in zip(FLAGS, combo, strict=True) if on) or "none"
            cases.append(
                Case(
                    f"flags-{tag}-{slug(name)}",
                    "flags",
                    feed_query(lat, lon, zone, name, flags),
                    env=sweep,
                    now="2026-06-20 18:00:00",
                )
            )

    # D. Options and input handling: offsets, notes, names, malformed values
    short = {"CALENDAR_PAST_DAYS": "1", "CALENDAR_WINDOW_DAYS": "8"}
    option_sets = {
        "offsets": {"rise_off": "15", "set_off": "-20"},
        "offset-rise-only": {"rise_off": "-45"},
        "offset-set-only": {"set_off": "90"},
        "offset-small": {"rise_off": "1", "set_off": "0"},
        "offset-limits": {"rise_off": "1440", "set_off": "-1440"},
        "offset-out-of-range": {"rise_off": "1441", "set_off": "-2000"},
        "offset-invalid": {"rise_off": "1.5", "set_off": "abc"},
        "offset-padded": {"rise_off": " 30 ", "set_off": "+10"},
        "offset-leading-zero": {"rise_off": "010", "set_off": "-0"},
        "desc": {"desc": "Bring a tripod; check the forecast, too."},
        "desc-html": {"desc": "<b>Bold</b> <script>alert(1)</script>text <!-- note --> a<b ok"},
        "desc-newlines": {"desc": "line one\r\nline two\nline three\rfour"},
        "desc-backslash": {"desc": "C:\\photos\\sun, moon; stars"},
        "desc-long": {"desc": "é" * 700},
        "desc-emoji": {"desc": "🌅🌇🌌 " * 40},
    }
    for (name, lat, lon, zone), (tag, extra) in product(
        [LOCATIONS[12], LOCATIONS[35], LOCATIONS[0]], option_sets.items()
    ):
        cases.append(
            Case(f"option-{tag}-{slug(name)}", "options", feed_query(lat, lon, zone, name, **extra), env=short)
        )

    raw_queries = {
        # Locations: none, "0" (empty for PHP's ?:), markup, very long, odd characters
        "location-none": feed_query(41.9028, 12.4964, "Europe/Rome"),
        "location-zero": feed_query(41.9028, 12.4964, "Europe/Rome", "0"),
        "location-markup": feed_query(41.9028, 12.4964, "Europe/Rome", "<i>Rome</i> & co, \"centro\"; 'X'"),
        "location-long": feed_query(41.9028, 12.4964, "Europe/Rome", "Roma " * 150),
        "location-backslash": feed_query(41.9028, 12.4964, "Europe/Rome", "a\\b\\,c"),
        "location-invalid-utf8": "feed=1&token=golden-token&lat=41.9&lon=12.5&zone=Europe%2FRome"
        "&location=caf%E9+%C3%28+%E2%82+%F0%9F%8C+%ED%A0%80+%C0%AF&daynight=1",
        "location-nul": "feed=1&token=golden-token&lat=41.9&lon=12.5&location=a%00b&daynight=1",
        # Coordinates: limits, formats PHP's filter accepts or rejects
        "lat-out-of-range": "feed=1&token=golden-token&lat=91&lon=181&daynight=1",
        "lat-exponent": "feed=1&token=golden-token&lat=4.19e1&lon=1.2e1&daynight=1&civil=1",
        "lat-padded": "feed=1&token=golden-token&lat=+41.9%20&lon=%0A12.5&daynight=1",
        "lat-comma": "feed=1&token=golden-token&lat=41%2C9&lon=12.5&daynight=1",
        "lat-negative-zero": "feed=1&token=golden-token&lat=-0.0&lon=-0&zone=UTC&daynight=1&civil=1",
        "lat-tiny": "feed=1&token=golden-token&lat=0.00001&lon=-0.0001&zone=UTC&daynight=1",
        "lat-many-digits": "feed=1&token=golden-token&lat=45.123456789012345&lon=9.1234567890123456&daynight=1",
        "lat-trailing-dot": "feed=1&token=golden-token&lat=45.&lon=.5&daynight=1",
        "lat-hex": "feed=1&token=golden-token&lat=0x1A&lon=1e400&daynight=1",
        "lat-underflow": "feed=1&token=golden-token&lat=1e-400&lon=0e5&daynight=1",
        "lat-missing": "feed=1&token=golden-token&daynight=1&nautical=1",
        "lat-array": "feed=1&token=golden-token&lat[]=10&lon=12&daynight=1",
        # Time zones
        "zone-invalid": "feed=1&token=golden-token&lat=40.7&lon=-74&zone=US%2FEastern&daynight=1",
        "zone-lowercase": "feed=1&token=golden-token&lat=40.7&lon=-74&zone=america%2Fnew_york&daynight=1",
        "zone-utc": "feed=1&token=golden-token&lat=40.7&lon=-74&zone=UTC&daynight=1",
        "zone-empty": "feed=1&token=golden-token&lat=40.7&lon=-74&zone=&daynight=1",
        # Flags must be exactly "1"
        "flags-not-one": "feed=1&token=golden-token&lat=45&lon=9&civil=on&nautical=true&astro=2&daynight=1",
        "flags-array": "feed=1&token=golden-token&lat=45&lon=9&civil[]=1&daynight=1",
        # PHP's parameter names: dots and spaces become underscores, the last value wins
        "names-mangled": "feed=1&token=golden-token&lat=45&lon=9&rise.off=30&set%20off=-30&daynight=1",
        "names-repeated": "feed=1&token=golden-token&lat=10&lat=45&lon=9&daynight=1&daynight=0&civil=1",
        "plus-as-space": "feed=1&token=golden-token&lat=45&lon=9&location=San+Marino%2BCity&daynight=1",
    }
    for tag, query in raw_queries.items():
        cases.append(Case(f"query-{tag}", "options", query, env=short))

    # Environment settings
    for tag, env in {
        "refresh-hourly": {"UPDATE_INTERVAL": "3600"},
        "refresh-odd": {"UPDATE_INTERVAL": "5400"},
        "refresh-text": {"UPDATE_INTERVAL": "12abc"},
        "window-zero": {"CALENDAR_PAST_DAYS": "0", "CALENDAR_WINDOW_DAYS": "0"},
        "window-text": {"CALENDAR_PAST_DAYS": "x", "CALENDAR_WINDOW_DAYS": "3 days"},
    }.items():
        cases.append(Case(f"env-{tag}", "options", feed_query(41.9028, 12.4964, "Europe/Rome", "Rome"), env=env))

    # Days a DST change skips or repeats midnight (or the evening hour before it): "today",
    # sunrise shifts and week starts depend on how PHP resolves those wall times
    edge = {"CALENDAR_PAST_DAYS": "3", "CALENDAR_WINDOW_DAYS": "10"}
    transitions = {
        "Havana": ("2026-03-08", "2026-11-01"),
        "Beirut": ("2026-03-29", "2026-10-24", "2026-10-25"),
        "Santiago": ("2026-04-04", "2026-04-05", "2026-09-06"),
        "Cairo": ("2026-04-24", "2026-10-29", "2026-10-30"),
        "Nuuk": ("2026-03-29", "2026-10-24"),
        "Casablanca": ("2026-02-15", "2026-03-22"),
        "Chatham Islands": ("2026-04-05",),
        "Lord Howe Island": ("2026-10-04",),
    }
    by_name = {loc[0]: loc for loc in LOCATIONS}
    for place, days in transitions.items():
        name, lat, lon, zone = by_name[place]
        for day, clock in product(days, ("03:00:00", "15:00:00")):
            cases.append(
                Case(
                    f"edge-{day}-{clock[:2]}-{slug(name)}",
                    "edges",
                    feed_query(lat, lon, zone, name),
                    env=edge,
                    now=f"{day} {clock}",
                )
            )

    # E. Responses other than a feed
    others = {
        "health": "health",
        "health-array": "health[]=1",
        "token-wrong": "feed=1&token=wrong&lat=45&lon=9",
        "token-missing": "feed=1&lat=45&lon=9",
        "token-array": "feed=1&token[]=golden-token",
        "token-prefix": "feed=1&token=golden-toke",
    }
    for tag, query in others.items():
        cases.append(Case(f"http-{tag}", "http", query))

    # F. The web page, on three days, and its form
    for moment in ("2026-03-29 10:00:00", "2026-06-21 03:00:00", "2026-12-21 23:30:00"):
        cases.append(Case(f"page-{moment[:10]}", "page", "", now=moment))
    form = (
        "generate_url=Generate&password=golden-token&lat=45.4642&lon=9.19&zone=Europe%2FRome"
        "&location=Mil%C3%A0n+%26+co+%3Cb%3E&rise_off=5&set_off=-5&description=hi+there&civil=on&daynight=on"
    )
    cases += [
        Case("page-post-ok", "page", "", body=form),
        Case("page-post-ok-https", "page", "", body=form, https=True),
        Case("page-post-ok-host", "page", "", body=form, host="sun.example.org:8443"),
        Case("page-post-wrong-password", "page", "", body=form.replace("golden-token", "nope")),
        Case("page-post-no-password", "page", "", body="generate_url=1&lat=1"),
        Case("page-post-defaults", "page", "", body="generate_url=1&password=golden-token"),
        Case(
            "page-post-every-type",
            "page",
            "",
            body="generate_url=1&password=golden-token&nautical=&astro=x&civil[]=1&daynight=on&zone=Asia%2FTokyo"
            "&location=~tilde+%2A+%21&description=a%0Ab",
        ),
        Case("page-post-no-submit", "page", "", body="password=golden-token&lat=1"),
        Case("page-get-generate", "page", "generate_url=1&password=golden-token"),
    ]
    return cases


CASES = _cases()
