# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A high-precision iCalendar feed generator for sunrise, sunset, and twilight times using the full NREL SPA (Solar Position Algorithm) via the `abbadon1334/sun-position-spa-php` library. The application generates dynamic `.ics` calendar feeds that users can subscribe to in any calendar application (Google Calendar, Apple Calendar, Outlook, etc.).

**Core Functionality**: Takes latitude, longitude, timezone, and preferences via URL parameters, then generates a 365-day iCalendar feed with sunrise/sunset times, civil/nautical/astronomical twilight events, day length statistics, moon phases, and weekly summaries.

**Accuracy**: ±30 seconds for solar event times using the full NREL SPA algorithm (±0.0003° precision for solar positions).

## Development Commands

### Dependency Management
```bash
# Install dependencies (required for first-time setup)
composer install

# Update dependencies
composer update

# Validate composer.json
composer validate --strict
```

### Testing
```bash
# Run all PHPUnit tests (via composer script)
composer test

# Or directly with PHPUnit
./vendor/bin/phpunit

# Run specific test suites
composer test:unit                               # Unit tests only
./vendor/bin/phpunit --testsuite Unit           # Unit tests
./vendor/bin/phpunit --testsuite Reference       # Reference data validation

# Run tests with detailed output
./vendor/bin/phpunit --testdox

# Run specific test file
./vendor/bin/phpunit tests/Unit/SolarCalculationsTest.php

# Run tests with code coverage (requires xdebug)
composer test:coverage
```

### Linting & Code Quality
```bash
# Run PSR-12 linter (via composer script)
composer lint

# Auto-fix PSR-12 violations
composer lint:fix

# Check PHP syntax manually
php -l sunrise-sunset-calendar.php
php -l src/calendar-generator.php
```

### Local Development
```bash
# Create config file (required)
cp config/config.example.php config/config.php
# Edit config/config.php and set AUTH_TOKEN to a secure random string:
# openssl rand -hex 32

# Test calendar generation directly
php sunrise-sunset-calendar.php

# Start PHP built-in server for testing web interface
php -S localhost:8000
# Then visit: http://localhost:8000/sunrise-sunset-calendar.php
```

### Validation
```bash
# Validate iCalendar output format
composer check:ical

# Check project readiness for deployment
composer check
```

## Architecture

### File Structure

**Main Application Files:**
- `sunrise-sunset-calendar.php` - Entry point, solar calculation functions, and core logic
- `src/calendar-generator.php` - iCalendar event generation and formatting (requires `strings.php`)
- `src/strings.php` - Centralized configuration for all user-facing text and event descriptions
- `src/functions.php` - Shared utilities and caching (loaded via Composer autoload)
- `src/solar-spa-wrapper.php` - High-precision NREL SPA wrapper
- `src/meeus-astronomy.php` - Meeus algorithms for equinoxes, solstices, and moon phases
- `assets/index.html.php` - Web UI for generating subscription URLs
- `assets/script.js` - Frontend JavaScript for location search and URL generation
- `assets/styles.css` - Styling for web interface
- `config/config.php` - User configuration (not in repo, copied from `config/config.example.php`)

**Key Architectural Patterns:**

1. **Two-Phase Request Handling**:
   - `?feed=1` parameter triggers calendar generation
   - Without `?feed=1`, displays web UI (`assets/index.html.php`)

2. **Solar Calculation Pipeline**:
   - **Primary**: Uses `abbadon1334/sun-position-spa-php` library (full NREL SPA)
   - **Wrapper**: `src/solar-spa-wrapper.php` provides backward-compatible interface
   - **Legacy**: Original NREL-inspired functions kept as `_legacy()` suffix for rollback
   - **Flow**: `calculate_sun_times()` → `calculate_sun_times_spa()` → SPA library

3. **Event Generation Architecture** (in `src/calendar-generator.php`):
   - Loads strings from `src/strings.php` configuration
   - Generates events for each selected type (civil, nautical, astronomical, daylight, night)
   - Calculates day length statistics and percentiles
   - Adds supplemental information when fewer calendar types are selected
   - Uses `format_ical_description()` for RFC 5545 compliant text escaping and line folding

4. **Text Configuration System**:
   - All event summaries, descriptions, labels stored in `src/strings.php`
   - Supports BEFORE/DURING/AFTER phase descriptions
   - Centralizes emojis and formatting for easy customization

### Critical Implementation Details

**Solar Calculations:**
- **Accuracy**: ±30 seconds (uses full NREL SPA algorithm)
- **Library**: `abbadon1334/sun-position-spa-php` v1.0+ (NREL SPA by Reda & Andreas, 2008)
- **Precision**: ±0.0003° for solar positions
- **Wrapper**: `src/solar-spa-wrapper.php` maintains backward compatibility
- **Standard atmospheric refraction**: 0.833° for sunrise/sunset
- **Twilight angles**: Civil (-6°), Nautical (-12°), Astronomical (-18°)
- **Date range**: Valid for years -2000 to 6000
- **Elevation**: NOT used in time calculations (API parameter maintained for backward compatibility)

**Percentile Algorithm:**
- Calculates where today's day length ranks among all 365 days
- Formula: `(count of days with less daylight / 365) × 100`
- Uses actual daylight duration distribution for the specific location
- 0th percentile = winter solstice (shortest day)
- 100th percentile = summer solstice (longest day)
- **Performance**: Cached per location/year to avoid recalculating 365 days repeatedly (see `src/functions.php`)

**iCalendar Generation:**
- Must comply with RFC 5545 format
- Line folding at 75 octets with CRLF + space continuation
- Special characters require escaping: `\` → `\\`, newlines → `\n`, `,` → `\,`, `;` → `\;`
- CRITICAL: Escape backslashes FIRST before other characters
- All times use 24-hour format (HHMMSS)
- Uses VTIMEZONE for timezone definitions

**Security Model:**
- Token-based authentication via `AUTH_TOKEN` in config/config.php
- Uses `hash_equals()` for constant-time token comparison
- All user inputs sanitized via `sanitize_float()`, `sanitize_int()`, `sanitize_timezone()`, `sanitize_text()`
- Security headers: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection
- Never commit config/config.php to version control

### Code Style Standards

- **Coding Standard**: PSR-12 (enforced via `phpcs.xml`)
- **Line Length**: 120 characters (absolute max: 150)
- **Cyclomatic Complexity**: Target ≤10, absolute max 15
- **Mathematical Functions**: Compact single-line syntax allowed for formulas
- **Naming**: Descriptive names, camelCase for functions/variables

## Important Constraints

1. **PHP Version**: Minimum 8.2, tested on 8.2, 8.3, 8.4, 8.5
2. **External Dependencies**: Requires Composer for `abbadon1334/sun-position-spa-php` (full NREL SPA implementation)
3. **Polar Regions**: Events may be missing during polar day/night periods
4. **Date Range**: Valid for years -2000 to 6000 (SPA library range)
5. **Timezone Handling**: Uses PHP's built-in timezone database

## Testing Philosophy

- Tests verify solar calculation accuracy against known values
- Percentile tests check mathematical correctness
- Format tests ensure RFC 5545 compliance (especially escape sequences)
- Tests must pass on PHP 8.2, 8.3, 8.4, 8.5 (see `.github/workflows/ci.yml`)

## Common Development Patterns

**When adding new event types:**
1. Add strings to `src/strings.php` (summaries, headers, descriptions)
2. Update event generation logic in `src/calendar-generator.php`
3. Use `build_phase_description()` for BEFORE/DURING/AFTER descriptions
4. Always use `format_ical_description()` for RFC 5545 compliance

**When modifying solar calculations:**
1. Update functions in `sunrise-sunset-calendar.php`
2. Add/update tests in `tests/SolarCalculationsTest.php`
3. Verify accuracy against NOAA or USNO data
4. Document any algorithm changes in comments

**When changing iCalendar format:**
1. Test with `tools/validate-ical.php`
2. Check RFC 5545 compliance for escaping and line folding
3. Test in multiple calendar applications (Google, Apple, Outlook)
4. Add tests in `tests/FormatTest.php`

## Configuration

The `config/config.php` file (created from `config/config.example.php`) contains:
- `AUTH_TOKEN` (required): Secure random string for authentication
- `CALENDAR_WINDOW_DAYS` (optional): Number of days to generate (default: 365)
- `UPDATE_INTERVAL` (optional): Calendar refresh interval in seconds (default: 86400)
