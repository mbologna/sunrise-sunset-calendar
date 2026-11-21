"""The JSON endpoint's content: positions, the day's events and what comes next."""

from __future__ import annotations

from sunrise_sunset_calendar import api, clock
from sunrise_sunset_calendar.astronomy import get_accurate_moon_phase, sun_events
from sunrise_sunset_calendar.feed import Options, generate_calendar

ROME = (41.9028, 12.4964, "Europe/Rome")


def rome_at(text: str) -> dict:
    with clock.use_zone("Europe/Rome"):
        ts = clock.LocalDate.parse(text[:10], clock.zone("Europe/Rome")).set_time(int(text[11:13]), int(text[14:16])).ts
    return api.now_json(*ROME, ts)


def test_noon_in_rome() -> None:
    data = rome_at("2026-11-20 11:56")
    sun = data["sun"]
    assert sun["period"] == "daylight" and sun["up"] is True
    assert 170 < sun["azimuth"] < 190, "Due south near solar noon"
    assert 27 < sun["altitude"] < 29
    assert sun["next"]["event"] == "sunset"

    with clock.use_zone("Europe/Rome"):
        events = sun_events(clock.LocalDate.parse("2026-11-20", clock.zone("Europe/Rome")), 41.9028, 12.4964)
    sunrise = clock.LocalDate.from_timestamp(events["sunrise"], clock.zone("Europe/Rome")).wall.isoformat()
    assert sun["today"]["sunrise"].startswith(sunrise)
    assert sun["today"]["first_light"] < sun["today"]["civil_dawn"] < sun["today"]["sunrise"]


def test_night_in_rome() -> None:
    data = rome_at("2026-11-20 03:00")
    assert data["sun"]["period"] == "night" and data["sun"]["up"] is False
    assert data["sun"]["next"]["event"] == "astronomical_dawn"


def test_polar_night_in_tromso() -> None:
    with clock.use_zone("Europe/Oslo"):
        ts = clock.LocalDate.parse("2026-12-21", clock.zone("Europe/Oslo")).set_time(12, 0).ts
    sun = api.now_json(69.6492, 18.9553, "Europe/Oslo", ts)["sun"]
    assert sun["today"]["sunrise"] is None and sun["today"]["sunset"] is None
    assert sun["today"]["polar"] == "night"
    assert sun["period"] == "civil_twilight", "The sun climbs to about 3° below the horizon at noon"


def test_moon_agrees_with_the_phase_calculation() -> None:
    data = rome_at("2026-11-20 12:42")
    with clock.use_zone("Europe/Rome"):
        phase = get_accurate_moon_phase(clock.local_timestamp(2026, 11, 20, 12, 42))
    assert data["moon"]["phase"] == phase["phase_name"]
    assert data["moon"]["illumination"] == phase["illumination"]
    assert data["moon"]["next_phase"]["name"] == phase["next_phase"]["name"]
    assert data["moon"]["next_rise"].startswith("2026-11-20T14:0"), "USNO: 14:06"


def test_day_length_against_yesterday_and_the_year() -> None:
    today = rome_at("2026-11-22 12:00")["sun"]["today"]
    assert today["vs_yesterday"] == {"seconds": -110, "percent": -0.32}
    assert today["year"] == {
        "shortest": {"date": "2026-12-21", "day_length": 32854},
        "longest": {"date": "2026-06-21", "day_length": 54834},
        "shorter_days": 58,
        "days": 365,
    }


def test_day_length_comparisons_match_the_feed() -> None:
    """The API's numbers are the ones the feed's Daylight event shows, unrounded."""
    today = rome_at("2026-11-22 12:00")["sun"]["today"]
    enabled = {"civil": False, "nautical": False, "astro": False, "daylight": True}
    ics = generate_calendar(Options(*ROME, enabled=enabled, past_days=0, window_days=0, today="2026-11-22")).replace(
        "\r\n ", ""
    )
    assert "vs yesterday: −1m 50s (−0.3%)" in ics
    assert f"Longer than {today['year']['shorter_days']} of {today['year']['days']} days" in ics


def test_day_length_in_a_polar_year() -> None:
    """Days tie at 0 and 24 hours: the extremes are the first such days, and nothing compares with 0."""
    with clock.use_zone("Europe/Oslo"):
        ts = clock.LocalDate.parse("2026-12-21", clock.zone("Europe/Oslo")).set_time(12, 0).ts
    today = api.now_json(69.6492, 18.9553, "Europe/Oslo", ts)["sun"]["today"]
    assert today["vs_yesterday"] == {"seconds": 0, "percent": None}
    assert today["year"]["shortest"]["day_length"] == 0
    assert today["year"]["longest"]["day_length"] == 86400
    assert today["year"]["shorter_days"] == 0
