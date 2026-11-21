"""Every structured line of every recorded event description fits a narrow calendar popup.

macOS Calendar's event popover, an iPhone in portrait and the Google Calendar app fit about 40
characters of their system font. A data line that wraps there breaks the reading, so each one stays
within 38 columns, an emoji counting as two. Prose (a sentence ending in a full stop, or anything
longer than a data line could be) may wrap.
"""

from __future__ import annotations

import re
import unicodedata

import pytest

from .cases import CASES
from .test_golden import bodies

WIDTH = 38
# Longer than this, a line is a paragraph: a data line never comes close
PROSE = 60

_DESCRIPTION = re.compile(r"^DESCRIPTION:(.*?)\r\n(?! )", re.M | re.S)


def columns(line: str) -> int:
    """Display columns: wide characters and emoji (a character with a variation selector) count two."""
    width = 0
    for i, ch in enumerate(line):
        if ch == "️" or unicodedata.combining(ch):
            continue
        emoji = i + 1 < len(line) and line[i + 1] == "️"
        width += 2 if emoji or unicodedata.east_asian_width(ch) in "WF" else 1
    return width


def description_lines(ics: str) -> list[str]:
    """The lines of every DESCRIPTION, unfolded and unescaped."""
    lines = []
    for folded in _DESCRIPTION.findall(ics):
        value = folded.replace("\r\n ", "")
        for escaped, plain in (("\\n", "\n"), ("\\,", ","), ("\\;", ";"), ("\\\\", "\\")):
            value = value.replace(escaped, plain)
        lines += value.split("\n")
    return lines


def is_prose(line: str) -> bool:
    return line.endswith(".") or columns(line) > PROSE


def test_columns() -> None:
    assert columns("vs shortest (Dec 21): +29m (+5.2%)") == 34
    assert columns("☀️ Sun") == 6, "The weather sun with its variation selector is an emoji"
    assert columns("⚓ Nautical") == 11
    assert columns("Year: 9h 08m ▕█░░░░░░░░░▏ 15h 14m") == 33


@pytest.mark.parametrize("group", sorted({c.group for c in CASES}))
def test_description_lines_fit_a_popup(group: str) -> None:
    too_wide = set()
    for body in bodies(group).values():
        if not body.startswith(b"BEGIN:VCALENDAR"):
            continue
        for line in description_lines(body.decode("utf-8")):
            if not is_prose(line) and columns(line) > WIDTH:
                too_wide.add(line)
    assert not too_wide, sorted(too_wide)
