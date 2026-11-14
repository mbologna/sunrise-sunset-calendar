"""The time zones a feed accepts: PHP's timezone_identifiers_list().

That list is every zone in the tz database's zone.tab, plus UTC. It is read
from the pinned tzdata package, the same database the feed's times use.
"""

from __future__ import annotations

from functools import cache
from importlib import resources


@cache
def timezone_identifiers() -> list[str]:
    tab = resources.files("tzdata").joinpath("zoneinfo", "zone.tab").read_text(encoding="utf-8")
    zones = {line.split("\t")[2] for line in tab.splitlines() if line and not line.startswith("#")}
    return sorted([*zones, "UTC"])


@cache
def timezone_set() -> frozenset[str]:
    return frozenset(timezone_identifiers())
