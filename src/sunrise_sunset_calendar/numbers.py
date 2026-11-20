"""How numbers are rounded and written in the output."""

from __future__ import annotations

from decimal import ROUND_HALF_UP, Decimal


def round_half_up(value: float, places: int = 0) -> float:
    """value rounded to places decimals, halves away from zero.

    round() takes halves to the even neighbour, so 0.5 seconds would round to 0 and
    2.5 to 2. Decimal(value) is the float's exact value, so 0.49999999999999994 stays below a half.
    """
    return float(Decimal(value).quantize(Decimal(1).scaleb(-places), rounding=ROUND_HALF_UP))


def round_int(value: float) -> int:
    """value rounded to an integer, halves away from zero."""
    return int(round_half_up(value))


def format_coordinate(value: float) -> str:
    """A latitude or longitude as written in event UIDs and the calendar: "41.9028", "90", "0.00001".

    Fourteen significant digits, as the feeds have always had: changing how a coordinate
    is written would change every event's UID, and calendar apps would re-create them.
    Never in exponent notation, which older feeds used below 0.0001 ("1.0E-5").
    """
    text = f"{value:.14g}"
    return f"{value:.14f}".rstrip("0").rstrip(".") if "e" in text else text
