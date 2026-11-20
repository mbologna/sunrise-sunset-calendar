"""The JSON endpoint's content: positions, the day's events and what comes next."""

from __future__ import annotations

from sunrise_sunset_calendar import api, clock
from sunrise_sunset_calendar.astronomy import get_accurate_moon_phase, sun_events

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
