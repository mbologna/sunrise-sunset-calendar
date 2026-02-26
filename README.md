# Sun & Twilight Calendar Generator

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A high-precision iCalendar feed generator for sunrise, sunset, and twilight times using the full NREL SPA algorithm. Subscribe in any calendar app (Google Calendar, Apple Calendar, Outlook) for daily solar events.

## Features

- **High-Precision Calculations** - NREL SPA algorithm, ±30 seconds accuracy
- **Twilight Types** - Civil, nautical, astronomical twilight events
- **Day Length Data** - Percentiles, solstice comparisons
- **Moon Phases** - Current phase and illumination info
- **Week Summaries** - Weekly daylight trends (Sundays)
- **Location Notes** - Special guidance for polar/tropical regions
- **Secure** - Token-based authentication
- **Universal** - iCalendar (ICS) format for all calendar apps

## Installation

```bash
git clone https://github.com/yourusername/sun-twilight-calendar.git
cd sun-twilight-calendar
composer install
cp config/config.example.php config/config.php
# Edit config/config.php and set AUTH_TOKEN to: openssl rand -hex 32
```

## Configuration

`config/config.php`:
- `AUTH_TOKEN` (required) - Secure random string (32+ characters)
- `CALENDAR_WINDOW_DAYS` (optional) - Days to generate (default: 365)
- `UPDATE_INTERVAL` (optional) - Refresh interval in seconds (default: 86400)

## API Parameters

Subscribe with: `https://yourdomain.com/sunrise-sunset-calendar.php?token=YOUR_TOKEN&...`

| Parameter | Required | Values | Example |
|-----------|----------|--------|---------|
| `token` | ✓ | AUTH_TOKEN value | `abc123...` |
| `lat` | ✓ | -90 to 90 | `40.7128` |
| `lon` | ✓ | -180 to 180 | `-74.0060` |
| `zone` | ✓ | Timezone identifier | `America/New_York` |
| `location` | ✗ | Location name | `New York City` |
| `civil` | ✗ | `1` to enable | `1` |
| `nautical` | ✗ | `1` to enable | `1` |
| `astro` | ✗ | `1` to enable | `1` |
| `daynight` | ✗ | `1` to enable | `1` |

## Event Types

- **Civil Twilight** - When sun is 6° below horizon (visual twilight)
- **Nautical Twilight** - Sun 12° below horizon (sea navigation possible)
- **Astronomical Twilight** - Sun 18° below horizon (naked eye astronomy possible)
- **Daylight** - Sunrise to sunset block
- **Night** - Astronomical dusk to dawn with moon phase

## Development

```bash
# Run all tests
composer test

# Check PSR-12 style
composer lint

# Auto-fix PSR-12 violations
composer lint:fix

# Validate iCalendar format
composer check:ical

# Start local server
php -S localhost:8000
```

## Project Structure

```
├── sunrise-sunset-calendar.php    # Entry point
├── src/
│   ├── calendar-generator.php     # iCalendar generation logic
│   ├── astronomy.php              # Solar/moon calculations
│   ├── helpers.php                # Format and utility functions
│   ├── functions.php              # Sanitization and caching
│   ├── Cache.php                  # Singleton cache manager
│   ├── geocoding.php              # OSM geocoding API
│   └── strings.php                # Strings configuration
├── assets/
│   ├── index.html.php            # Web UI
│   ├── script.js                 # Location search JS
│   └── styles.css                # Styling
├── config/
│   ├── config.example.php        # Example config
│   └── config.php                # User config (not in repo)
├── tests/                        # PHPUnit test suites
└── vendor/                       # Composer dependencies
```

## Requirements

- PHP 8.2+ (tested on 8.2, 8.3, 8.4, 8.5)
- Composer

## Accuracy

Uses full NREL Solar Position Algorithm (SPA) by Reda & Andreas, 2008:
- Solar position: ±0.0003°
- Sunrise/sunset times: ±30 seconds
- Valid for years -2000 to 6000

For polar regions (>66.5° latitude), twilight times may be unavailable during polar day/night periods.

## License

MIT - See LICENSE file

## Contributing

Pull requests welcome. Run tests before submitting: `composer test`
