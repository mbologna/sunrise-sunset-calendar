"""The web page for generating subscription URLs.

The markup, whitespace included, is what the PHP template rendered, so the
page is byte-for-byte the same.
"""

from __future__ import annotations

import hashlib
from functools import cache as memoize
from importlib import resources

from . import cache
from .astronomy import sun_events
from .helpers import format_duration, format_time
from .php import LocalDate, default_timezone_set, frozen_time, htmlspecialchars, php_date, round_int, zone
from .strings import STRINGS

DEFAULT_LAT = 41.9028
DEFAULT_LON = 12.4964
DEFAULT_TIMEZONE = "Europe/Rome"
DEFAULT_LAT_TEXT = "41.9028"
DEFAULT_LON_TEXT = "12.4964"

_W = STRINGS["web"]
e = htmlspecialchars


def json_encode_strings(values: dict[str, str]) -> str:
    """json_encode() with JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE, for a flat dict."""

    def enc(text: str) -> str:
        out = ['"']
        for ch in text:
            code = ord(ch)
            if ch == '"':
                out.append('\\"')
            elif ch == "\\":
                out.append("\\\\")
            elif ch == "/":
                out.append("\\/")
            elif ch == "<":
                out.append("\\u003C")
            elif ch == ">":
                out.append("\\u003E")
            elif ch == "&":
                out.append("\\u0026")
            elif ch == "\b":
                out.append("\\b")
            elif ch == "\f":
                out.append("\\f")
            elif ch == "\n":
                out.append("\\n")
            elif ch == "\r":
                out.append("\\r")
            elif ch == "\t":
                out.append("\\t")
            elif code < 0x20 or code in (0x2028, 0x2029):
                out.append(f"\\u{code:04x}")
            else:
                out.append(ch)
        out.append('"')
        return "".join(out)

    return "{" + ",".join(f"{enc(k)}:{enc(v)}" for k, v in values.items()) + "}"


@memoize
def asset_url(name: str) -> str:
    """An asset's URL with a hash of its content: a new release's page never gets a cached old asset.

    Cloudflare keeps the assets at its edge for hours, so after a deploy the page could otherwise load
    the previous script, built for the previous page. The server ignores the query string.
    """
    digest = hashlib.sha256(resources.files(__package__).joinpath("assets", name).read_bytes()).hexdigest()
    return f"assets/{name}?v={digest[:12]}"


def render_page(
    now: int,
    timezones: list[str],
    subscription_url: str | None = None,
    webcal_url: str | None = None,
) -> str:
    """The page, with today's times in Rome and the generated URLs."""
    cache.reset()
    with default_timezone_set(DEFAULT_TIMEZONE), frozen_time(now):
        today = sun_events(LocalDate.today(zone(DEFAULT_TIMEZONE), now), DEFAULT_LAT, DEFAULT_LON)
        daylight_seconds = round_int(today["daylength_h"] * 3600)
        date_line = _W["today_date"].format(php_date(_W["today_date_format"], now))
        times = "".join(
            f"                    {e(label)}: {e(format_time(today[key]))}<br>\n                "
            for key, label in _W["today_times"].items()
        )

    out = [
        "<!DOCTYPE html>\n"
        '<html lang="en">\n'
        "\n"
        "<head>\n"
        '    <meta charset="utf-8">\n'
        '    <meta name="viewport" content="width=device-width, initial-scale=1.0">\n'
        f"    <title>{e(_W['title'])}</title>\n"
        f'    <link rel="stylesheet" href="{asset_url("styles.css")}">\n'
        "</head>\n"
        "\n"
        "<body>\n"
        '    <div class="container">\n'
        f"        <h1>{e(_W['heading'])}</h1>\n"
        "\n"
        '        <div class="info-box">\n'
        f"            <strong>{e(_W['today_heading'])}</strong><br>\n"
        f"            {e(date_line)}<br>\n"
        '            <span id="time-display">\n'
        "                " + times + "                <strong>"
        f"{e(_W['day_length'].format(format_duration(daylight_seconds)))}</strong>\n"
        "            </span>\n"
        "        </div>\n"
        "\n"
        "        "
    ]
    out.append("\n        ")
    if subscription_url is not None and webcal_url is not None:
        link = '<a href="https://calendar.google.com" target="_blank">' + e(_W["google_link"]) + "</a>"
        steps = "".join(
            f"                        <li>{e(step).replace('{}', link)}</li>\n                    "
            for step in _W["google_steps"]
        )
        out.append(
            '            <div class="success-box">\n'
            f"                <h3>{e(_W['generated'])}</h3>\n"
            f"                <p><strong>{e(_W['subscription_url'])}</strong></p>\n"
            f'                <div class="url-display" id="subscription-url">{e(subscription_url)}</div>\n'
            '                <button class="copy-button" data-copy="subscription-url">'
            f"{e(_W['copy_url'])}</button>\n"
            "\n"
            f'                <p class="spaced"><strong>{e(_W["webcal_url"])}</strong></p>\n'
            f'                <div class="url-display" id="webcal-url">{e(webcal_url)}</div>\n'
            '                <button class="copy-button" data-copy="webcal-url">'
            f"{e(_W['copy_webcal'])}</button>\n"
            "\n"
            "                <hr>\n"
            f"                <h4>{e(_W['google_heading'])}</h4>\n"
            "                <ol>\n"
            "                    " + steps + "                </ol>\n"
            "            </div>\n"
            "        "
        )
    options = "".join(
        f'                        <option value="{e(tz)}"{" selected" if tz == DEFAULT_TIMEZONE else ""}>'
        f"{e(tz)}</option>\n                    "
        for tz in timezones
    )
    types = "".join(
        '                    <div class="checkbox-group">\n'
        f'                        <input type="checkbox" name="{name}" id="{name}"'
        f"{' checked' if name in ('civil', 'daynight') else ''}>\n"
        f'                        <label for="{name}">{e(label)}</label>\n'
        "                    </div>\n"
        "                "
        for name, label in _W["types"].items()
    )
    footer = "".join(
        f"                <strong>{e(heading)}</strong>\n                <p>{e(text)}</p>\n            "
        for heading, text in _W["footer"]
    )
    out.append(
        "\n"
        '        <div class="button-row">\n'
        f'            <button id="use-location" class="secondary">{e(_W["use_location"])}</button>\n'
        f'            <button id="show-address-search" class="secondary">{e(_W["search_address"])}</button>\n'
        "        </div>\n"
        '        <div id="location-status"></div>\n'
        "\n"
        '        <div id="address-search" hidden>\n'
        '            <div class="form-group">\n'
        f'                <label for="address-input">{e(_W["address"])}</label>\n'
        f'                <input type="text" id="address-input" placeholder="{e(_W["address_placeholder"])}">\n'
        '                <button id="geocode-address">'
        f"{e(_W['search_location'])}</button>\n"
        "            </div>\n"
        '            <div id="geocode-status"></div>\n'
        "        </div>\n"
        "\n"
        '        <form method="post" id="feed-form">\n'
        "            <hr>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="location">{e(_W["location"])}</label>\n'
        '                <input type="text" name="location" id="location" '
        f'placeholder="{e(_W["location_placeholder"])}">\n'
        f'                <div class="help-text">{e(_W["location_help"])}</div>\n'
        "            </div>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="lat">{e(_W["latitude"])} <span class="required">*</span></label>\n'
        '                <input type="number" name="lat" id="lat" step="0.000001" min="-90" max="90"\n'
        f'                    value="{DEFAULT_LAT_TEXT}" required>\n'
        f'                <div class="help-text">{e(_W["latitude_help"])}</div>\n'
        "            </div>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="lon">{e(_W["longitude"])} <span class="required">*</span></label>\n'
        '                <input type="number" name="lon" id="lon" step="0.000001" min="-180" max="180"\n'
        f'                    value="{DEFAULT_LON_TEXT}" required>\n'
        f'                <div class="help-text">{e(_W["longitude_help"])}</div>\n'
        "            </div>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="zone">{e(_W["timezone"])} <span class="required">*</span></label>\n'
        '                <select name="zone" id="zone" required>\n'
        "                    " + options + "                </select>\n"
        "            </div>\n"
        "\n"
        "            <hr>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <strong>{e(_W["event_types"])} <span class="required">*</span></strong>\n'
        f'                <div class="help-text event-types-help">{e(_W["event_types_help"])}</div>\n'
        "                " + types + "            </div>\n"
        "\n"
        "            <hr>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="rise_off">{e(_W["rise_offset"])}</label>\n'
        '                <input type="number" name="rise_off" id="rise_off" value="0" min="-1440" max="1440">\n'
        f'                <div class="help-text">{e(_W["offset_help"])}</div>\n'
        "            </div>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="set_off">{e(_W["set_offset"])}</label>\n'
        '                <input type="number" name="set_off" id="set_off" value="0" min="-1440" max="1440">\n'
        f'                <div class="help-text">{e(_W["offset_help"])}</div>\n'
        "            </div>\n"
        "\n"
        "            <hr>\n"
        "\n"
        '            <div class="form-group">\n'
        f'                <label for="description">{e(_W["note"])}</label>\n'
        '                <textarea name="description" id="description" rows="3"\n'
        f'                    placeholder="{e(_W["note_placeholder"])}"></textarea>\n'
        "            </div>\n"
        "\n"
        f'            <input type="submit" name="generate_url" value="{e(_W["submit"])}">\n'
        "        </form>\n"
        "\n"
        '        <div class="footer">\n'
        "            " + footer + "        </div>\n"
        "    </div>\n"
        "\n"
        # Data, not code: the Content-Security-Policy runs no inline script
        f'    <script type="application/json" id="strings">{json_encode_strings(_W["js"])}</script>\n'
        f'    <script src="{asset_url("script.js")}"></script>\n'
        "</body>\n"
        "\n"
        "</html>\n"
    )
    return "".join(out)
