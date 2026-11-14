"""Solar position and sunrise, transit and sunset: the NREL Solar Position Algorithm.

A faithful port of abbadon1334/sun-position-spa-php 2.0.0 (Francesco
Danti), the implementation the PHP calendar used, of Reda & Andreas, "Solar
Position Algorithm for Solar Radiation Applications", NREL/TP-560-34302
(2008). It is ported operation by operation rather than replaced by another
SPA implementation: that library's sunrise and sunset interpolation differs
from the reference C code in small ways, and the feeds must reproduce its
times to the second. Only the quantities the calendar reads are computed:
the geocentric right ascension, declination and sidereal time, and the
rise/transit/set day fractions built from them.

Copyright (c) 2017 Francesco Danti (the original PHP implementation).

Licence: the upstream project declares MIT in its source headers and
composer.json, but the LICENSE file it ships is the GNU GPL version 3. To
satisfy either reading, this file, as a derivative of that code, is
distributed under the GNU General Public License version 3
(LICENSES/GPL-3.0-only.txt), keeping the upstream MIT notice above. The rest of
this repository stays under the MIT licence (LICENSE); the application and its
image, which include this file, are therefore distributed under the GPL's terms.
"""

# SPDX-License-Identifier: GPL-3.0-only

from __future__ import annotations

import math
from functools import lru_cache

_PI = math.pi
PRIME_HOUR_ATMOSPHERIC_REFRACTION = 0.5667
SUN_RADIUS = 0.26667
PRIME_HOUR_ANGLE = -1 * (SUN_RADIUS + PRIME_HOUR_ATMOSPHERIC_REFRACTION)


def _to_degrees(radians: float) -> float:
    return (180.0 / _PI) * radians


def _to_radians(degrees: float) -> float:
    return (_PI / 180.0) * degrees


def _limit_zero_to_one(value: float) -> float:
    limited = value - math.floor(value)
    if limited < 0:
        limited += 1.0
    return limited


def _limit_to_180pm(degrees: float) -> float:
    degrees /= 360.0
    limited = 360.0 * (degrees - math.floor(degrees))
    if limited < -180.0:
        limited += 360.0
    elif limited > 180.0:
        limited -= 360.0
    return limited


def _limit_to(degrees: float, limit: float) -> float:
    degrees /= limit
    limited = limit * (degrees - math.floor(degrees))
    if limited < 0:
        limited += limit
    return limited


def _polynomial(x: float, coeffs) -> float:
    total = 0
    for index, v in enumerate(coeffs):
        total = total + v * math.pow(x, index)
    return total


def _earth_terms(jme: float, table) -> list[float]:
    sums = []
    cos = math.cos
    for terms in table:
        total = 0
        for a, b, c in terms:
            total = total + a * cos(b + c * jme)
        sums.append(total)
    return sums


def _earth_poly(jme: float, table) -> float:
    return _polynomial(jme, _earth_terms(jme, table)) / 1e8


def julian_day(year: float, month: float, day: float, hour: float, minute: float, second: float, tz: float) -> float:
    """ObserverTime::calcJulianDay()."""
    if month <= 2:
        month += 12
        year -= 1
    d = day + hour / 24.0 - tz / 24 + minute / (24.0 * 60.0) + second / (24.0 * 60.0 * 60.0)
    a = int(year / 100.0)
    b = 2 - a + int(a / 4.0)
    jd = int(365.25 * (year + 4716.0)) + int(30.6001 * (month + 1.0)) + d + b - 1524.5
    if jd < 2299160:
        jd = int(365.25 * (year + 4716.0)) + int(30.6001 * (month + 1.0)) + d + 0 - 1524.5
    return jd


@lru_cache(maxsize=16384)
def geocentric(jd: float) -> tuple[float, float, float, float, float, float]:
    """Right ascension, declination and apparent sidereal time (degrees) at a Julian day.

    Also the nutation in longitude, the true obliquity of the ecliptic
    (degrees) and the Julian ephemeris millennium, for the equation of time.

    SunPosition::calculate() with ΔT = 0, so the ephemeris day equals the
    Julian day; the topocentric steps that follow it there do not feed the
    values the calendar reads, and are skipped.
    """
    jc = (jd - 2451545) / 36525
    jde = jd + 0
    jce = (jde - 2451545) / 36525
    jme = jce / 10

    l_deg = _limit_to(_to_degrees(_earth_poly(jme, EARTH_PERIODIC_TERMS_L)), 360.0)
    b_deg = _to_degrees(_earth_poly(jme, EARTH_PERIODIC_TERMS_B))
    r = _earth_poly(jme, EARTH_PERIODIC_TERMS_R)

    theta = _limit_to(l_deg + 180.0, 360.0)
    beta = _to_radians(-b_deg)

    x = [_polynomial(jce, NUTATION_COEFFS[i]) for i in range(5)]
    sin = math.sin
    cos = math.cos
    dpsi_sum = 0
    deps_sum = 0
    for idx, (a, b, c, d) in enumerate(COEFF_PSI_EPSILON):
        terms = SIN_TERMS[idx]
        xy = 0
        for j in range(5):
            xy = xy + x[j] * terms[j]
        arg = _to_radians(xy)
        dpsi_sum = dpsi_sum + (a + b * jce) * sin(arg)
        deps_sum = deps_sum + (c + d * jce) * cos(arg)
    dpsi = dpsi_sum / 36000000.0
    deps = deps_sum / 36000000.0

    eps0 = _polynomial(jme / 10.0, OBLIQUITY_COEFFS)
    eps_deg = (eps0 / 3600.0) + deps
    eps = _to_radians(eps_deg)

    dtau = -20.4898 / (3600.0 * r)
    lam = _to_radians(theta + dpsi + dtau)

    nu0 = (
        280.46061837 + 360.98564736629 * (jd - 2451545.0) + 0.000387933 * math.pow(jc, 2) - math.pow(jc, 3) / 38710000.0
    )
    nu0 = _limit_to(nu0, 360.0)
    nu = nu0 + dpsi * cos(eps)

    alpha = math.atan2(sin(lam) * cos(eps) - math.tan(beta) * sin(eps), cos(lam))
    alpha_deg = _limit_to(_to_degrees(alpha), 360.0)
    delta_deg = _to_degrees(math.asin(sin(beta) * cos(eps) + cos(beta) * sin(eps) * sin(lam)))
    return alpha_deg, delta_deg, nu, dpsi, eps_deg, jme


def equation_of_time(year: int, month: int, day: int, tz: float) -> float:
    """SunPosition::getEquationOfTime() at local noon, in minutes."""
    jd = julian_day(float(year), float(month), float(day), 12.0, 0.0, 0.0, float(tz))
    alpha_deg, _, _, dpsi, eps_deg, jme = geocentric(jd)
    mean_longitude = _limit_to(_polynomial(jme, EQUATION_OF_TIME), 360.0)
    e_deg = mean_longitude - 0.0057183 - alpha_deg + dpsi * math.cos(_to_radians(eps_deg))
    eot = e_deg * 4
    if eot < -20.0:
        eot += 1440.0
    elif eot > 20.0:
        eot -= 1440.0
    return eot


def declination(year: int, month: int, day: int, tz: float) -> float:
    """The sun's geocentric declination at local noon (SunPosition 'δ°' after calculate())."""
    jd = julian_day(float(year), float(month), float(day), 12.0, 0.0, 0.0, float(tz))
    return geocentric(jd)[1]


def rise_transit_set(
    year: int, month: int, day: int, lat: float, lon: float
) -> tuple[float | None, float, float | None]:
    """SunPosition::calcSunRiseTransitSet(): sunrise, transit and sunset as UT day fractions.

    Sunrise and sunset are None when the sun stays above or below the
    horizon all day.
    """
    y = float(year)
    m = float(month)
    d = float(day)
    a0, d0, nu, *_ = geocentric(julian_day(y, m, d, 0, 0, 0, 0))
    am1, dm1, *_ = geocentric(julian_day(y, m, d - 1, 0, 0, 0, 0))
    ap1, dp1, *_ = geocentric(julian_day(y, m, d + 1, 0, 0, 0, 0))
    alpha = {-1: am1, 0: a0, 1: ap1}
    delta = {-1: dm1, 0: d0, 1: dp1}
    sigma = lon
    phi_deg = lat

    m0 = (alpha[0] - sigma - nu) / 360.0

    prime = _to_radians(PRIME_HOUR_ANGLE)
    h0 = math.sin(prime) - math.sin(_to_radians(phi_deg)) * math.sin(_to_radians(delta[0]))
    h0 /= math.cos(_to_radians(phi_deg)) * math.cos(_to_radians(delta[0]))
    no_rise_set = False
    if abs(h0) <= 1:
        h0 = _limit_to(_to_degrees(math.acos(h0)), 180.0)
    else:
        no_rise_set = True
        h0 = -99999

    dfrac = h0 / 360.0
    ms = {
        0: _limit_zero_to_one(m0),
        -1: _limit_zero_to_one(m0 - dfrac),
        1: _limit_zero_to_one(m0 + dfrac),
    }

    a_a = alpha[0] - alpha[-1]
    if abs(a_a) >= 2.0:
        a_a = _limit_zero_to_one(a_a)
    b_a = alpha[1] - alpha[0]
    if abs(b_a) >= 2.0:
        b_a = _limit_zero_to_one(b_a)
    c_a = b_a - a_a
    a_d = delta[0] - delta[-1]
    if abs(a_d) >= 2.0:
        a_d = _limit_zero_to_one(a_d)
    b_d = delta[1] - delta[0]
    if abs(b_d) >= 2.0:
        b_d = _limit_zero_to_one(b_d)
    c_d = b_d - a_d

    phi = _to_radians(phi_deg)
    fractions = {}
    for index in (0, -1, 1):
        mv = ms[index]
        nu_i = nu + 360.985647 * mv
        n = mv + 0 / 86400.0
        alpha_p = alpha[0] + (n * (a_a + b_a + c_a * n)) / 2.0
        delta_p_deg = delta[0] + (n * (a_d + b_d + c_d * n)) / 2.0
        delta_p = _to_radians(delta_p_deg)
        h_p_deg = _limit_to_180pm(nu_i + sigma - alpha_p)
        h_p = _to_radians(h_p_deg)
        if index == 0:
            fractions[0] = mv - h_p_deg / 360.0
        else:
            h = _to_degrees(
                math.asin(math.sin(phi) * math.sin(delta_p) + math.cos(phi) * math.cos(delta_p) * math.cos(h_p))
            )
            fractions[index] = mv + (h - PRIME_HOUR_ANGLE) / (360.0 * math.cos(delta_p) * math.cos(phi) * math.sin(h_p))
    if no_rise_set:
        return None, fractions[0], None
    return fractions[-1], fractions[0], fractions[1]


def day_fraction_to_hours(dayfrac: float | None, tz: float) -> float:
    """SunPosition::_DayFracToHours($dayfrac, true): local hours wrapped into 0-24."""
    return 24.0 * _limit_zero_to_one((dayfrac or 0) + tz / 24.0)


# ============================================================================
# Tables (NREL/TP-560-34302, Tables A4.2, A4.3 and A.1), as the PHP library has them
# ============================================================================

EARTH_PERIODIC_TERMS_L = (
    # L0
    (
        (175347046.0, 0, 0),
        (3341656.0, 4.6692568, 6283.07585),
        (34894.0, 4.6261, 12566.1517),
        (3497.0, 2.7441, 5753.3849),
        (3418.0, 2.8289, 3.5231),
        (3136.0, 3.6277, 77713.7715),
        (2676.0, 4.4181, 7860.4194),
        (2343.0, 6.1352, 3930.2097),
        (1324.0, 0.7425, 11506.7698),
        (1273.0, 2.0371, 529.691),
        (1199.0, 1.1096, 1577.3435),
        (990, 5.233, 5884.927),
        (902, 2.045, 26.298),
        (857, 3.508, 398.149),
        (780, 1.179, 5223.694),
        (753, 2.533, 5507.553),
        (505, 4.583, 18849.228),
        (492, 4.205, 775.523),
        (357, 2.92, 0.067),
        (317, 5.849, 11790.629),
        (284, 1.899, 796.298),
        (271, 0.315, 10977.079),
        (243, 0.345, 5486.778),
        (206, 4.806, 2544.314),
        (205, 1.869, 5573.143),
        (202, 2.458, 6069.777),  # Corrected from 202,2.4458,6069.777
        (156, 0.833, 213.299),
        (132, 3.411, 2942.463),
        (126, 1.083, 20.775),
        (115, 0.645, 0.98),
        (103, 0.636, 4694.003),
        (102, 0.976, 15720.839),
        (102, 4.267, 7.114),
        (99, 6.21, 2146.17),
        (98, 0.68, 155.42),
        (86, 5.98, 161000.69),
        (85, 1.3, 6275.96),
        (85, 3.67, 71430.7),
        (80, 1.81, 17260.15),
        (79, 3.04, 12036.46),
        (75, 1.76, 5088.63),  # Corrected from  71 1.76 5088.63
        (74, 3.5, 3154.69),
        (74, 4.68, 801.82),
        (70, 0.83, 9437.76),
        (62, 3.98, 8827.39),
        (61, 1.82, 7084.9),
        (57, 2.78, 6286.6),
        (56, 4.39, 14143.5),
        (56, 3.47, 6279.55),
        (52, 0.19, 12139.55),
        (52, 1.33, 1748.02),
        (51, 0.28, 5856.48),
        (49, 0.49, 1194.45),
        (41, 5.37, 8429.24),
        (41, 2.4, 19651.05),
        (39, 6.17, 10447.39),
        (37, 6.04, 10213.29),
        (37, 2.57, 1059.38),
        (36, 1.71, 2352.87),
        (36, 1.78, 6812.77),
        (33, 0.59, 17789.85),
        (30, 0.44, 83996.85),
        (30, 2.74, 1349.87),
        (25, 3.16, 4690.48),
    ),
    # L1
    (
        (628331966747.0, 0, 0),
        (206059.0, 2.678235, 6283.07585),
        (4303.0, 2.6351, 12566.1517),
        (425.0, 1.59, 3.523),
        (119.0, 5.796, 26.298),
        (109.0, 2.966, 1577.344),
        (93, 2.59, 18849.23),
        (72, 1.14, 529.69),
        (68, 1.87, 398.15),
        (67, 4.41, 5507.55),
        (59, 2.89, 5223.69),
        (56, 2.17, 155.42),
        (45, 0.4, 796.3),
        (36, 0.47, 775.52),
        (29, 2.65, 7.11),
        (21, 5.34, 0.98),
        (19, 1.85, 5486.78),
        (19, 4.97, 213.3),
        (17, 2.99, 6275.96),
        (16, 0.03, 2544.31),
        (16, 1.43, 2146.17),
        (15, 1.21, 10977.08),
        (12, 2.83, 1748.02),
        (12, 3.26, 5088.63),
        (12, 5.27, 1194.45),
        (12, 2.08, 4694),
        (11, 0.77, 553.57),
        (10, 1.3, 6286.6),  # Corrected from   10 1.3 3286.6
        (10, 4.24, 1349.87),
        (9, 2.7, 242.73),
        (9, 5.64, 951.72),
        (8, 5.3, 2352.87),
        (6, 2.65, 9437.76),
        (6, 4.67, 4690.48),
    ),
    # L2
    (
        (52919.0, 0, 0),
        (8720.0, 1.0721, 6283.0758),
        (309.0, 0.867, 12566.152),
        (27, 0.05, 3.52),
        (16, 5.19, 26.3),
        (16, 3.68, 155.42),
        (10, 0.76, 18849.23),
        (9, 2.06, 77713.77),
        (7, 0.83, 775.52),
        (5, 4.66, 1577.34),
        (4, 1.03, 7.11),
        (4, 3.44, 5573.14),
        (3, 5.14, 796.3),
        (3, 6.05, 5507.55),
        (3, 1.19, 242.73),
        (3, 6.12, 529.69),
        (3, 0.31, 398.15),
        (3, 2.28, 553.57),
        (2, 4.38, 5223.69),
        (2, 3.75, 0.98),
    ),
    # L3
    (
        (289.0, 5.844, 6283.076),
        (35, 0, 0),
        (17, 5.49, 12566.15),
        (3, 5.2, 155.42),
        (1, 4.72, 3.52),
        (1, 5.3, 18849.23),
        (1, 5.97, 242.73),
    ),
    # L4
    (
        (114.0, 3.142, 0),
        (8, 4.13, 6283.08),
        (1, 3.84, 12566.15),
    ),
    # L5
    ((1, 3.14, 0),),
)

# earth heliocentric latitude.
EARTH_PERIODIC_TERMS_B = (
    # B0
    (
        (280.0, 3.199, 84334.662),
        (102.0, 5.422, 5507.553),
        (80, 3.88, 5223.69),
        (44, 3.7, 2352.87),
        (32, 4, 1577.34),
    ),
    # B1
    (
        (9, 3.9, 5507.55),
        (6, 1.73, 5223.69),
    ),
)

# earth radius vector.
EARTH_PERIODIC_TERMS_R = (
    # R0
    (
        (100013989.0, 0, 0),
        (1670700.0, 3.0984635, 6283.07585),
        (13956.0, 3.05525, 12566.1517),
        (3084.0, 5.1985, 77713.7715),
        (1628.0, 1.1739, 5753.3849),
        (1576.0, 2.8469, 7860.4194),
        (925.0, 5.453, 11506.77),
        (542.0, 4.564, 3930.21),
        (472.0, 3.661, 5884.927),
        (346.0, 0.964, 5507.553),
        (329.0, 5.9, 5223.694),
        (307.0, 0.299, 5573.143),
        (243.0, 4.273, 11790.629),
        (212.0, 5.847, 1577.344),
        (186.0, 5.022, 10977.079),
        (175.0, 3.012, 18849.228),
        (110.0, 5.055, 5486.778),
        (98, 0.89, 6069.78),
        (86, 5.69, 15720.84),
        (86, 1.27, 161000.69),
        (65, 0.27, 17260.15),  # Corrected from  85 0.27 17260.15
        (63, 0.92, 529.69),
        (57, 2.01, 83996.85),
        (56, 5.24, 71430.7),
        (49, 3.25, 2544.31),
        (47, 2.58, 775.52),
        (45, 5.54, 9437.76),
        (43, 6.01, 6275.96),
        (39, 5.36, 4694),
        (38, 2.39, 8827.39),
        (37, 0.83, 19651.05),
        (37, 4.9, 12139.55),
        (36, 1.67, 12036.46),
        (35, 1.84, 2942.46),
        (33, 0.24, 7084.9),
        (32, 0.18, 5088.63),
        (32, 1.78, 398.15),
        (28, 1.21, 6286.6),
        (28, 1.9, 6279.55),
        (26, 4.59, 10447.39),
    ),
    # R1
    (
        (103019.0, 1.10749, 6283.07585),
        (1721.0, 1.0644, 12566.1517),
        (702.0, 3.142, 0),
        (32, 1.02, 18849.23),
        (31, 2.84, 5507.55),
        (25, 1.32, 5223.69),
        (18, 1.42, 1577.34),
        (10, 5.91, 10977.08),
        (9, 1.42, 6275.96),
        (9, 0.27, 5486.78),
    ),
    # R2
    (
        (4359.0, 5.7846, 6283.0758),
        (124.0, 5.579, 12566.152),
        (12, 3.14, 0),
        (9, 3.63, 77713.77),
        (6, 1.87, 5573.14),
        (3, 5.47, 18849.23),  # Corrected from 3 5.47 18849
    ),
    # R3
    (
        (145.0, 4.273, 6283.076),
        (7, 3.92, 12566.15),
    ),
    # R4
    ((4, 2.56, 6283.08),),
)
NUTATION_COEFFS = (
    (297.85036, 445267.111480, -0.0019142, 1.0 / 189474),
    (357.52772, 35999.050340, -0.0001603, -1.0 / 300000),
    (134.96298, 477198.867398, 0.0086972, 1.0 / 56250),
    (93.27191, 483202.017538, -0.0036825, 1.0 / 327270),
    (125.04452, -1934.136261, 0.0020708, 1.0 / 450000),
)
SIN_TERMS = (
    (0, 0, 0, 0, 1),
    (-2, 0, 0, 2, 2),
    (0, 0, 0, 2, 2),
    (0, 0, 0, 0, 2),
    (0, 1, 0, 0, 0),
    (0, 0, 1, 0, 0),
    (-2, 1, 0, 2, 2),
    (0, 0, 0, 2, 1),
    (0, 0, 1, 2, 2),
    (-2, -1, 0, 2, 2),
    (-2, 0, 1, 0, 0),
    (-2, 0, 0, 2, 1),
    (0, 0, -1, 2, 2),
    (2, 0, 0, 0, 0),
    (0, 0, 1, 0, 1),
    (2, 0, -1, 2, 2),
    (0, 0, -1, 0, 1),
    (0, 0, 1, 2, 1),
    (-2, 0, 2, 0, 0),
    (0, 0, -2, 2, 1),
    (2, 0, 0, 2, 2),
    (0, 0, 2, 2, 2),
    (0, 0, 2, 0, 0),
    (-2, 0, 1, 2, 2),
    (0, 0, 0, 2, 0),
    (-2, 0, 0, 2, 0),
    (0, 0, -1, 2, 1),
    (0, 2, 0, 0, 0),
    (2, 0, -1, 0, 1),
    (-2, 2, 0, 2, 2),
    (0, 1, 0, 0, 1),
    (-2, 0, 1, 0, 1),
    (0, -1, 0, 0, 1),
    (0, 0, 2, -2, 0),
    (2, 0, -1, 2, 1),
    (2, 0, 1, 2, 2),
    (0, 1, 0, 2, 2),
    (-2, 1, 1, 0, 0),
    (0, -1, 0, 2, 2),
    (2, 0, 0, 2, 1),
    (2, 0, 1, 0, 0),
    (-2, 0, 2, 2, 2),
    (-2, 0, 1, 2, 1),
    (2, 0, -2, 0, 1),
    (2, 0, 0, 0, 1),
    (0, -1, 1, 0, 0),
    (-2, -1, 0, 2, 1),
    (-2, 0, 0, 0, 1),
    (0, 0, 2, 2, 1),
    (-2, 0, 2, 0, 1),
    (-2, 1, 0, 2, 1),
    (0, 0, 1, -2, 0),
    (-1, 0, 1, 0, 0),
    (-2, 1, 0, 0, 0),
    (1, 0, 0, 0, 0),
    (0, 0, 1, 2, 0),
    (0, 0, -2, 2, 2),
    (-1, -1, 1, 0, 0),
    (0, 1, 1, 0, 0),
    (0, -1, 1, 2, 2),
    (2, -1, -1, 2, 2),
    (0, 0, 3, 2, 2),
    (2, -1, 0, 2, 2),
)
COEFF_PSI_EPSILON = (
    (-171996, -174.2, 92025, 8.9),
    (-13187, -1.6, 5736, -3.1),
    (-2274, -0.2, 977, -0.5),
    (2062, 0.2, -895, 0.5),
    (1426, -3.4, 54, -0.1),
    (712, 0.1, -7, 0),
    (-517, 1.2, 224, -0.6),
    (-386, -0.4, 200, 0),
    (-301, 0, 129, -0.1),
    (217, -0.5, -95, 0.3),
    (-158, 0, 0, 0),
    (129, 0.1, -70, 0),
    (123, 0, -53, 0),
    (63, 0, 0, 0),
    (63, 0.1, -33, 0),
    (-59, 0, 26, 0),
    (-58, -0.1, 32, 0),
    (-51, 0, 27, 0),
    (48, 0, 0, 0),
    (46, 0, -24, 0),
    (-38, 0, 16, 0),
    (-31, 0, 13, 0),
    (29, 0, 0, 0),
    (29, 0, -12, 0),
    (26, 0, 0, 0),
    (-22, 0, 0, 0),
    (21, 0, -10, 0),
    (17, -0.1, 0, 0),
    (16, 0, -8, 0),
    (-16, 0.1, 7, 0),
    (-15, 0, 9, 0),
    (-13, 0, 7, 0),
    (-12, 0, 6, 0),
    (11, 0, 0, 0),
    (-10, 0, 5, 0),
    (-8, 0, 3, 0),
    (7, 0, -3, 0),
    (-7, 0, 0, 0),
    (-7, 0, 3, 0),
    (-7, 0, 3, 0),
    (6, 0, 0, 0),
    (6, 0, -3, 0),
    (6, 0, -3, 0),
    (-6, 0, 3, 0),
    (-6, 0, 3, 0),
    (5, 0, 0, 0),
    (-5, 0, 3, 0),
    (-5, 0, 3, 0),
    (-5, 0, 3, 0),
    (4, 0, 0, 0),
    (4, 0, 0, 0),
    (4, 0, 0, 0),
    (-4, 0, 0, 0),
    (-4, 0, 0, 0),
    (-4, 0, 0, 0),
    (3, 0, 0, 0),
    (-3, 0, 0, 0),
    (-3, 0, 0, 0),
    (-3, 0, 0, 0),
    (-3, 0, 0, 0),
    (-3, 0, 0, 0),
    (-3, 0, 0, 0),
    (-3, 0, 0, 0),
)
OBLIQUITY_COEFFS = (
    84381.448,
    -4680.93,
    -1.55,
    1999.25,
    51.38,
    -249.67,
    -39.05,
    7.12,
    27.87,
    5.79,
    2.45,
)

EQUATION_OF_TIME = (
    280.4664567,
    360007.6982779,
    0.03032028,
    1 / 49913,
    -1 / 15300,
    -1 / 2000000,
)
