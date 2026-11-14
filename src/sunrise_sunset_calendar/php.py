"""The PHP behaviours the feed's output depends on, reproduced exactly.

The calendar was first written in PHP, and its feeds are byte-for-byte
golden-mastered against that version. These helpers mirror the PHP 8.5
primitives whose results reach the output: rounding, float-to-string
conversion, input filters, strip_tags(), multibyte truncation, date
formatting, and wall-clock time zone arithmetic. Each follows the C source
of php-src 8.5 rather than the manual, so edge cases match too.
"""

from __future__ import annotations

import calendar as _calendar
import math
import re
import time as _time
from contextlib import contextmanager
from contextvars import ContextVar
from datetime import UTC, date, datetime, timedelta
from functools import cache
from zoneinfo import ZoneInfo

PHP_INT_MIN = -(2**63)
PHP_INT_MAX = 2**63 - 1

# ============================================================================
# MATH
# ============================================================================


def deg2rad(deg: float) -> float:
    """PHP deg2rad(): (deg / 180.0) * M_PI, which rounds differently from math.radians()."""
    return (deg / 180.0) * math.pi


def rad2deg(rad: float) -> float:
    """PHP rad2deg(): (rad / M_PI) * 180."""
    return (rad / math.pi) * 180


def php_round(value: float, places: int = 0) -> float:
    """PHP round() with PHP_ROUND_HALF_UP (ext/standard/math.c, _php_math_round)."""
    value = float(value)
    if not math.isfinite(value) or value == 0.0:
        return value
    if places == 0 and value == math.trunc(value):
        return value
    exponent = 10.0 ** abs(places) if abs(places) <= 22 else math.pow(10.0, abs(places))
    scaled = value * exponent if places > 0 else value / exponent
    if value >= 0.0:
        tmp = float(math.floor(scaled))
        tmp2 = tmp + 1.0
    else:
        # C's ceil() keeps the sign: ceil(-0.4) is -0.0, which steers the rounding below
        tmp = math.copysign(float(math.ceil(scaled)), -1.0)
        tmp2 = tmp - 1.0
    if (tmp2 / exponent if places > 0 else tmp2 * exponent) == value:
        tmp = tmp2
    if abs(tmp) >= 1e16:
        return value
    # php_round_helper, PHP_ROUND_HALF_UP
    edge = abs((tmp + math.copysign(0.5, tmp)) / exponent if places > 0 else (tmp + math.copysign(0.5, tmp)) * exponent)
    if abs(value) >= edge:
        tmp = tmp + math.copysign(1.0, tmp)
    return tmp / exponent if places > 0 else tmp * exponent


def round_int(value: float) -> int:
    """(int) round($value)."""
    return int(php_round(value))


def intdiv(a: int, b: int) -> int:
    """PHP intdiv(): truncates toward zero."""
    q = abs(a) // abs(b)
    return q if (a >= 0) == (b >= 0) else -q


def array_sum(values) -> float:
    """PHP array_sum(): plain left-to-right addition (Python's sum() compensates)."""
    total = 0
    for v in values:
        total = total + v
    return total


# ============================================================================
# STRINGS
# ============================================================================


def float_to_str(value: float) -> str:
    """PHP's (string) cast of a float: zend_gcvt() with precision 14."""
    if math.isnan(value):
        return "NAN"
    if math.isinf(value):
        return "INF" if value > 0 else "-INF"
    if value == 0.0:
        return "-0" if math.copysign(1.0, value) < 0 else "0"
    mantissa, exp = f"{abs(value):.13e}".split("e")
    digits = mantissa.replace(".", "").rstrip("0") or "0"
    decpt = int(exp) + 1
    sign = "-" if value < 0 else ""
    ndigit = 14
    if decpt > ndigit or decpt < -3:
        e = decpt - 1
        rest = digits[1:] or "0"
        esign = "-" if e < 0 else "+"
        return f"{sign}{digits[0]}.{rest}E{esign}{abs(e)}"
    if decpt <= 0:
        return f"{sign}0.{'0' * -decpt}{digits}"
    if len(digits) <= decpt:
        return sign + digits + "0" * (decpt - len(digits))
    return f"{sign}{digits[:decpt]}.{digits[decpt:]}"


def to_str(value) -> str:
    """PHP string conversion of a scalar."""
    if isinstance(value, bool):
        return "1" if value else ""
    if isinstance(value, float):
        return float_to_str(value)
    if value is None:
        return ""
    return str(value)


def to_int(value) -> int:
    """PHP (int) conversion of an int or float (truncation)."""
    if isinstance(value, float):
        if not math.isfinite(value) or not (-(2.0**63) <= value < 2.0**63):
            return 0
        return int(value)
    return int(value)


_SPRINTF = re.compile(r"%(?:(\d+)\$)?(0?)(\d*)([sd%])")


def sprintf(fmt: str, *args) -> str:
    """PHP sprintf() for the conversions the strings use: %s, %d, %0Nd, %%, %N$s."""
    pos = 0

    def repl(m: re.Match) -> str:
        nonlocal pos
        argnum, zero, width, conv = m.groups()
        if conv == "%":
            return "%"
        if argnum:
            arg = args[int(argnum) - 1]
        else:
            arg = args[pos]
            pos += 1
        if conv == "d":
            n = to_int(arg)
            if width and zero:
                return ("-" if n < 0 else "") + str(abs(n)).rjust(int(width) - (n < 0), "0")
            return str(n).rjust(int(width)) if width else str(n)
        text = to_str(arg)
        if width:
            text = text.rjust(int(width), "0" if zero else " ")
        return text

    return _SPRINTF.sub(repl, fmt)


def lcfirst(text: str) -> str:
    return text[:1].lower() + text[1:] if text and "A" <= text[0] <= "Z" else text


def strtolower(text: str) -> str:
    """PHP 8 strtolower(): ASCII only."""
    return text.translate(_ASCII_LOWER)


_ASCII_LOWER = {c: c + 32 for c in range(ord("A"), ord("Z") + 1)}


# ============================================================================
# INPUT FILTERS (ext/filter/logical_filters.c)
# ============================================================================

_FILTER_WS = b" \t\r\v\n"


def _filter_trim(raw: bytes) -> bytes | None:
    start = 0
    end = len(raw)
    while start < end and raw[start] in _FILTER_WS:
        start += 1
    if end - start < 1:
        return None
    while raw[end - 1] in _FILTER_WS:
        end -= 1
    return raw[start:end]


def _is_digit(c: int) -> bool:
    return 48 <= c <= 57


def filter_validate_float(raw: bytes) -> float | None:
    """FILTER_VALIDATE_FLOAT without flags: the float, or None when invalid."""
    s = _filter_trim(raw)
    if s is None:
        return None
    n = len(s)
    i = 0
    out = bytearray()
    if i < n and s[i] in b"+-":
        out.append(s[i])
        i += 1
    while i < n and _is_digit(s[i]):
        out.append(s[i])
        i += 1
    if i < n and s[i] not in b".eE":
        return None
    if i < n and s[i] == 0x2E:
        out.append(0x2E)
        i += 1
        while i < n and _is_digit(s[i]):
            out.append(s[i])
            i += 1
    if i < n and s[i] in b"eE":
        out.append(s[i])
        i += 1
        if i < n and s[i] in b"+-":
            out.append(s[i])
            i += 1
        while i < n and _is_digit(s[i]):
            out.append(s[i])
            i += 1
    if i != n:
        return None
    num = bytes(out)
    kind = _numeric_kind(num)
    if kind is None:
        return None
    if kind == "long":
        return float(int(num))
    dval = float(num)
    if (dval == 0.0 and len(num) > 1 and re.search(rb"[1-9]", num)) or not math.isfinite(dval):
        return None
    return dval


def _numeric_kind(num: bytes) -> str | None:
    """is_numeric_string() on a buffer built by the float filter: 'long', 'double' or None."""
    m = re.fullmatch(rb"[+-]?(\d+)(\.\d*)?([eE][+-]?\d+)?|[+-]?\.\d+([eE][+-]?\d+)?", num)
    if not m:
        return None
    if m.group(1) is not None and m.group(2) is None and m.group(3) is None:
        digits = m.group(1).lstrip(b"0")
        # MAX_LENGTH_OF_LONG is 20: 20 or more significant digits parse as a double
        if len(digits) >= 20:
            return "double"
        if len(digits) == 19 and digits >= b"9223372036854775808" and not num.startswith(b"-"):
            return "double"
        if len(digits) == 19 and digits > b"9223372036854775808":
            return "double"
        return "long"
    return "double"


def filter_validate_int(raw: bytes) -> int | None:
    """FILTER_VALIDATE_INT without flags: the integer, or None when invalid."""
    if len(raw) == 0:
        return None
    s = _filter_trim(raw)
    if s is None:
        return None
    if s[0] == 0x30:
        return 0 if len(s) == 1 else None
    i = 0
    neg = False
    if s[0] in b"+-":
        neg = s[0] == 0x2D
        i = 1
    if s[i : i + 1] == b"0" and i + 1 == len(s):
        return 0
    if i >= len(s) or not (49 <= s[i] <= 57):
        return None
    rest = s[i:]
    if len(rest) - 1 > 19 or not rest.isdigit():
        return None
    value = -int(rest) if neg else int(rest)
    if value > PHP_INT_MAX or value < PHP_INT_MIN:
        return None
    return value


def env_int(text: str) -> int:
    """PHP (int) cast of a string, as the entry point applies it to environment variables."""
    m = re.match(r"[ \t\n\r\v\f]*([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)", text)
    if not m:
        return 0
    num = m.group(1)
    if re.fullmatch(r"[+-]?\d+", num):
        value = int(num)
        if PHP_INT_MIN <= value <= PHP_INT_MAX:
            return value
        return to_int(float(num))
    return to_int(float(num))


# ============================================================================
# strip_tags() (ext/standard/string.c, php_strip_tags_ex without allowed tags)
# ============================================================================

_C_SPACE = b" \t\n\v\f\r"


def strip_tags(raw: bytes) -> bytes:
    buf = raw
    end = len(buf)
    out = bytearray()
    p = 0
    lc = 0
    br = 0
    depth = 0
    in_q = 0
    state = 0
    is_xml = False

    def at(i: int) -> int:
        return buf[i] if 0 <= i < end else 0

    while p < end:
        c = buf[p]
        if state == 0:
            if c == 0:
                pass
            elif c == 0x3C:  # <
                if in_q:
                    pass
                elif at(p + 1) in _C_SPACE and at(p + 1) != 0:
                    out.append(c)
                else:
                    lc = 0x3C
                    state = 1
            elif c == 0x3E:  # >
                if depth:
                    depth -= 1
                elif in_q:
                    pass
                else:
                    out.append(c)
            else:
                out.append(c)
            p += 1
        elif state == 1:
            if c == 0:
                pass
            elif c == 0x3C:
                if in_q:
                    pass
                elif at(p + 1) in _C_SPACE and at(p + 1) != 0:
                    pass  # reg_char_1: nothing to record without allowed tags
                else:
                    depth += 1
            elif c == 0x3E:
                if depth:
                    depth -= 1
                elif in_q:
                    pass
                else:
                    lc = 0x3E
                    if is_xml and p >= 1 and buf[p - 1] == 0x2D:
                        pass
                    else:
                        in_q = 0
                        state = 0
                        is_xml = False
            elif c in (0x22, 0x27):
                if p != 0 and (not in_q or c == in_q):
                    in_q = 0 if in_q else c
            elif c == 0x21:  # !
                if p >= 1 and buf[p - 1] == 0x3C:
                    state = 3
                    lc = c
            elif c == 0x3F:  # ?
                if p >= 1 and buf[p - 1] == 0x3C:
                    br = 0
                    state = 2
            p += 1
        elif state == 2:
            if c == 0x28:  # (
                if lc not in (0x22, 0x27):
                    lc = 0x28
                    br += 1
            elif c == 0x29:  # )
                if lc not in (0x22, 0x27):
                    lc = 0x29
                    br -= 1
            elif c == 0x3E:
                if depth:
                    depth -= 1
                elif in_q:
                    pass
                elif not br and p >= 1 and lc != 0x22 and buf[p - 1] == 0x3F:
                    in_q = 0
                    state = 0
            elif c in (0x22, 0x27):
                if p >= 1 and buf[p - 1] != 0x5C:
                    if lc == c:
                        lc = 0
                    elif lc != 0x5C:
                        lc = c
                    if p != 0 and (not in_q or c == in_q):
                        in_q = 0 if in_q else c
            elif c in (0x6C, 0x4C):  # l L
                if (
                    p > 4
                    and buf[p - 1] in (0x6D, 0x4D)
                    and buf[p - 2] in (0x78, 0x58)
                    and buf[p - 3] == 0x3F
                    and buf[p - 4] == 0x3C
                ):
                    state = 1
                    is_xml = True
            p += 1
        elif state == 3:
            if c == 0x3E:
                if depth:
                    depth -= 1
                elif in_q:
                    pass
                else:
                    in_q = 0
                    state = 0
            elif c in (0x22, 0x27):
                if p != 0 and buf[p - 1] != 0x5C and (not in_q or c == in_q):
                    in_q = 0 if in_q else c
            elif c == 0x2D:  # -
                if p >= 2 and buf[p - 1] == 0x2D and buf[p - 2] == 0x21:
                    state = 4
            elif c in (0x45, 0x65):  # E e: <!DOCTYPE
                if p > 6 and bytes(buf[p - 6 : p]).lower() == b"doctyp":
                    state = 1
            p += 1
        else:  # state 4: inside <!-- -->
            if c == 0x3E and not in_q and p >= 2 and buf[p - 1] == 0x2D and buf[p - 2] == 0x2D:
                in_q = 0
                state = 0
            p += 1
    return bytes(out)


# ============================================================================
# MULTIBYTE (ext/mbstring, UTF-8)
# ============================================================================


def utf8_decode_php(raw: bytes) -> str:
    """Decode UTF-8 as mbstring does, each invalid sequence becoming '?' (mb_utf8_to_wchar)."""
    out = []
    p = 0
    e = len(raw)
    while p < e:
        c = raw[p]
        p += 1
        if c < 0x80:
            out.append(chr(c))
        elif c < 0xC2:
            out.append("?")
        elif c <= 0xDF:
            if p < e:
                c2 = raw[p]
                p += 1
                if (c2 & 0xC0) != 0x80:
                    out.append("?")
                    p -= 1
                else:
                    out.append(chr(((c & 0x1F) << 6) | (c2 & 0x3F)))
            else:
                out.append("?")
        elif c <= 0xEF:
            if e - p >= 2:
                c2 = raw[p]
                c3 = raw[p + 1]
                p += 2
                if (c2 & 0xC0) != 0x80 or (c == 0xE0 and c2 < 0xA0) or (c == 0xED and c2 >= 0xA0):
                    out.append("?")
                    p -= 2
                elif (c3 & 0xC0) != 0x80:
                    out.append("?")
                    p -= 1
                else:
                    out.append(chr(((c & 0xF) << 12) | ((c2 & 0x3F) << 6) | (c3 & 0x3F)))
            else:
                out.append("?")
                if p < e and (c != 0xE0 or raw[p] >= 0xA0) and (c != 0xED or raw[p] < 0xA0) and (raw[p] & 0xC0) == 0x80:
                    p += 1
                    if p < e and (raw[p] & 0xC0) == 0x80:
                        p += 1
        elif c <= 0xF4:
            if e - p >= 3:
                c2, c3, c4 = raw[p], raw[p + 1], raw[p + 2]
                p += 3
                if (c2 & 0xC0) != 0x80 or (c == 0xF0 and c2 < 0x90) or (c == 0xF4 and c2 >= 0x90):
                    out.append("?")
                    p -= 3
                elif (c3 & 0xC0) != 0x80:
                    out.append("?")
                    p -= 2
                elif (c4 & 0xC0) != 0x80:
                    out.append("?")
                    p -= 1
                else:
                    out.append(chr(((c & 0x7) << 18) | ((c2 & 0x3F) << 12) | ((c3 & 0x3F) << 6) | (c4 & 0x3F)))
            else:
                out.append("?")
                if p < e:
                    c2 = raw[p]
                    if (c == 0xF0 and c2 >= 0x90) or (c == 0xF4 and c2 < 0x90) or (0xF1 <= c <= 0xF3):
                        while p < e and (raw[p] & 0xC0) == 0x80:
                            p += 1
        else:
            out.append("?")
    return "".join(out)


def mb_substr(raw: bytes, length: int) -> str:
    """mb_substr($raw, 0, $length, 'UTF-8'), which re-encodes and so repairs invalid input."""
    if length == 0 or not raw:
        return ""
    return utf8_decode_php(raw)[:length]


# ============================================================================
# URLS AND HTML
# ============================================================================


def urlencode(raw: bytes) -> str:
    """PHP urlencode(): RFC 1738, spaces as '+', only alphanumerics and -_. kept."""
    out = []
    for b in raw:
        if 48 <= b <= 57 or 65 <= b <= 90 or 97 <= b <= 122 or b in b"-_.":
            out.append(chr(b))
        elif b == 0x20:
            out.append("+")
        else:
            out.append(f"%{b:02X}")
    return "".join(out)


def http_build_query(params: list[tuple[str, bytes]]) -> str:
    """http_build_query() of a flat array of strings, with '&' separators."""
    return "&".join(f"{urlencode(k.encode())}={urlencode(v)}" for k, v in params)


def htmlspecialchars(text: str) -> str:
    """htmlspecialchars() with PHP 8.1+ defaults (ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401)."""
    return (
        text.replace("&", "&amp;")
        .replace('"', "&quot;")
        .replace("'", "&#039;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
    )


def _parse_key(key: str) -> tuple[str, bool]:
    """A query key's variable name, and whether it is an array (php_register_variable_ex)."""
    key = key.lstrip(" ")
    name = []
    for i, ch in enumerate(key):
        if ch in " .":
            name.append("_")
        elif ch == "[":
            if not name:
                return "", False
            rest = key[i + 1 :]
            if "]" in rest:
                return "".join(name), True
            # Not an index: the '[' and what follows stay part of a plain name
            return "".join(name) + "_" + rest.replace(" ", "_").replace(".", "_").replace("[", "_"), False
        else:
            name.append(ch)
    return "".join(name), False


class _Array:
    """An array-valued request variable (key[] or key[x]): set, but not a string."""

    def __repr__(self) -> str:
        return "ARRAY"


ARRAY = _Array()


def parse_query(query: bytes) -> dict[str, bytes | _Array]:
    """Parse a query string or urlencoded body the way PHP fills $_GET and $_POST.

    Returns each variable's last value as bytes; an array-valued variable
    (key[] or key[x]) maps to ARRAY, since the app reads only strings.
    """
    from urllib.parse import unquote_to_bytes

    result: dict[str, bytes | _Array] = {}
    for part in query.split(b"&"):
        if not part:
            continue
        if b"=" in part:
            raw_key, raw_value = part.split(b"=", 1)
        else:
            raw_key, raw_value = part, b""
        key_bytes = unquote_to_bytes(raw_key.replace(b"+", b" "))
        value = unquote_to_bytes(raw_value.replace(b"+", b" "))
        key = key_bytes.split(b"\0", 1)[0].decode("latin-1")
        if not key.lstrip(" "):
            continue
        name, is_array = _parse_key(key)
        if not name:
            continue
        if is_array:
            result[name] = ARRAY
        else:
            result[name] = value
    return result


# ============================================================================
# DATE AND TIME
# ============================================================================

_default_tz: ContextVar[str] = ContextVar("php_default_timezone", default="UTC")
_now: ContextVar[int | None] = ContextVar("php_now", default=None)


@cache
def zone(name: str) -> ZoneInfo:
    return ZoneInfo(name)


def default_timezone() -> str:
    return _default_tz.get()


@contextmanager
def default_timezone_set(name: str):
    """date_default_timezone_set() for the duration of a block."""
    token = _default_tz.set(name)
    try:
        yield
    finally:
        _default_tz.reset(token)


@contextmanager
def frozen_time(now: int):
    """The moment time() reports for the duration of a block (mktime() depends on it)."""
    token = _now.set(now)
    try:
        yield
    finally:
        _now.reset(token)


def php_time() -> int:
    now = _now.get()
    return int(_time.time()) if now is None else now


def _tz():
    return zone(_default_tz.get())


def _offset_info(ts: int, tz: ZoneInfo) -> tuple[int, bool, str | None]:
    """UTC offset, DST flag and abbreviation in effect at a UTC instant."""
    dt = datetime.fromtimestamp(ts, tz)
    return int(dt.utcoffset().total_seconds()), bool(dt.dst()), dt.tzname()


def _transition_time(ts: int, tz: ZoneInfo) -> int | None:
    """When the period in effect at ts began, if within the previous three days; None if earlier.

    The resolution below needs the transition only when it is hours away: a
    period that began long before behaves like one that always was.
    """
    key = _offset_info(ts, tz)
    lo = ts - 3 * 86400
    if _offset_info(lo, tz) == key:
        return None
    hi = ts
    while hi - lo > 1:
        mid = (lo + hi) // 2
        if _offset_info(mid, tz) == key:
            hi = mid
        else:
            lo = mid
    return hi


def resolve(wall: datetime, tz: ZoneInfo, dst: bool = False, have_zone: bool = False) -> int:
    """Timestamp of a wall-clock time, as PHP resolves it (timelib's do_adjust_timezone()).

    A time a DST change skips counts with the offset before the change. For a
    time it repeats, PHP's choice depends on the DST flag of the date being
    changed (dst, with have_zone): a date object keeps the flag of its last
    resolved instant, mktime() takes the current time's.
    """
    sse = _calendar.timegm(wall.timetuple())
    current, current_dst, _ = _offset_info(sse, tz)
    after = _offset_info(sse - current, tz)[0]
    actual = after
    actual_at = sse - current  # the instant whose period's start bounds the transition test
    if current == after and have_zone:
        if current >= 0 and dst and not current_dst:
            earlier = _offset_info(sse - current - 7200, tz)[0]
            after_transition = _transition_time(sse - current, tz)
            if earlier != after and after_transition is not None and sse - earlier < after_transition:
                actual = earlier
                actual_at = sse - current - 7200
        elif current <= 0 and current_dst and not dst:
            later = _offset_info(sse - current + 7200, tz)[0]
            later_transition = _transition_time(sse - current + 7200, tz)
            if later != after and (later_transition is None or sse - later >= later_transition):
                actual = later
                actual_at = sse - current + 7200
    if current != actual:
        transition = _transition_time(actual_at, tz)
        in_transition = (
            transition is not None and sse - actual >= transition + (current - actual) and sse - actual < transition
        )
        if not in_transition:
            return sse - actual
    return sse - current


def is_dst(ts: int, tz: ZoneInfo) -> bool:
    return _offset_info(ts, tz)[1]


def wall_clock(ts: int, tz: ZoneInfo) -> datetime:
    """Naive local date and time of a timestamp."""
    return datetime.fromtimestamp(ts, tz).replace(tzinfo=None)


def mktime(hour: int, minute: int, second: int, month: int, day: int, year: int) -> int:
    """PHP mktime() in the default time zone, normalising out-of-range fields."""
    year += (month - 1) // 12
    month = (month - 1) % 12 + 1
    base = datetime(year, month, 1) + timedelta(days=day - 1, hours=hour, minutes=minute, seconds=second)
    tz = _tz()
    return resolve(base, tz, is_dst(php_time(), tz), True)


def gmmktime(hour: int, minute: int, second: int, month: int, day: int, year: int) -> int:
    year += (month - 1) // 12
    month = (month - 1) % 12 + 1
    base = datetime(year, month, 1) + timedelta(days=day - 1, hours=hour, minutes=minute, seconds=second)
    return _calendar.timegm(base.timetuple())


def local(ts: int) -> datetime:
    """Local wall-clock time of a timestamp in the default time zone."""
    return wall_clock(ts, _tz())


_DAYS = ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")
_MONTHS = (
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
)


def format_datetime(dt: datetime, fmt: str) -> str:
    """PHP date() formatting of a wall-clock time, for the characters the app uses."""
    out = []
    i = 0
    n = len(fmt)
    while i < n:
        ch = fmt[i]
        if ch == "\\" and i + 1 < n:
            out.append(fmt[i + 1])
            i += 2
            continue
        match ch:
            case "d":
                out.append(f"{dt.day:02d}")
            case "j":
                out.append(str(dt.day))
            case "D":
                out.append(_DAYS[dt.weekday()][:3])
            case "l":
                out.append(_DAYS[dt.weekday()])
            case "w":
                out.append(str((dt.weekday() + 1) % 7))
            case "N":
                out.append(str(dt.weekday() + 1))
            case "F":
                out.append(_MONTHS[dt.month - 1])
            case "M":
                out.append(_MONTHS[dt.month - 1][:3])
            case "m":
                out.append(f"{dt.month:02d}")
            case "n":
                out.append(str(dt.month))
            case "Y":
                out.append(str(dt.year))
            case "y":
                out.append(f"{dt.year % 100:02d}")
            case "H":
                out.append(f"{dt.hour:02d}")
            case "G":
                out.append(str(dt.hour))
            case "i":
                out.append(f"{dt.minute:02d}")
            case "s":
                out.append(f"{dt.second:02d}")
            case _:
                out.append(ch)
        i += 1
    return "".join(out)


def php_date(fmt: str, ts: int) -> str:
    """PHP date() in the default time zone."""
    return format_datetime(local(ts), fmt)


def gmdate(fmt: str, ts: int) -> str:
    return format_datetime(datetime.fromtimestamp(ts, UTC).replace(tzinfo=None), fmt)


class LocalDate:
    """The DateTimeImmutable operations the calendar uses, with PHP's wall-clock arithmetic.

    Holds a wall-clock time in a zone, normalised as PHP stores it: a time a
    DST change skips moves forward by the gap, so every later change keeps
    the resolved wall time. A new date resolves its wall time without a DST
    hint; a changed one (add_days(), set_time()) with the DST flag of its
    current instant, as timelib does.
    """

    __slots__ = ("dst", "ts", "tz", "wall")

    def __init__(self, wall: datetime, tz: ZoneInfo, dst: bool = False, have_zone: bool = False):
        self.tz = tz
        self.ts = resolve(wall, tz, dst, have_zone)
        self.wall = wall_clock(self.ts, tz)
        self.dst = is_dst(self.ts, tz)

    @classmethod
    def today(cls, tz: ZoneInfo, now: int) -> LocalDate:
        day = wall_clock(now, tz).date()
        return cls(datetime(day.year, day.month, day.day), tz)

    @classmethod
    def parse(cls, text: str, tz: ZoneInfo) -> LocalDate:
        """A 'YYYY-MM-DD' date at midnight."""
        d = date.fromisoformat(text)
        return cls(datetime(d.year, d.month, d.day), tz)

    @classmethod
    def from_timestamp(cls, ts: int, tz: ZoneInfo) -> LocalDate:
        obj = cls.__new__(cls)
        obj.tz = tz
        obj.ts = ts
        obj.wall = wall_clock(ts, tz)
        obj.dst = is_dst(ts, tz)
        return obj

    def add_days(self, days: int) -> LocalDate:
        return LocalDate(self.wall + timedelta(days=days), self.tz, self.dst, True)

    def set_time(self, hour: int, minute: int) -> LocalDate:
        return LocalDate(self.wall.replace(hour=hour, minute=minute, second=0, microsecond=0), self.tz, self.dst, True)

    def offset(self) -> int:
        return int(datetime.fromtimestamp(self.ts, self.tz).utcoffset().total_seconds())

    def format(self, fmt: str) -> str:
        return format_datetime(self.wall, fmt)

    @property
    def year(self) -> int:
        return self.wall.year

    @property
    def month(self) -> int:
        return self.wall.month

    @property
    def day(self) -> int:
        return self.wall.day


def strtotime_days(days: int, base: int) -> int:
    """strtotime("+N days", $base) in the default time zone."""
    tz = _tz()
    return resolve(wall_clock(base, tz) + timedelta(days=days), tz)


def first_of_month(ts: int) -> int:
    """strtotime('first day of this month', $ts): day 1, same time of day."""
    tz = _tz()
    return resolve(wall_clock(ts, tz).replace(day=1), tz)


def add_month(ts: int) -> int:
    """strtotime('+1 month', $ts) for a timestamp on the 1st of a month."""
    tz = _tz()
    w = wall_clock(ts, tz)
    year, month = (w.year + 1, 1) if w.month == 12 else (w.year, w.month + 1)
    return resolve(w.replace(year=year, month=month), tz)
