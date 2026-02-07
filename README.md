# Sun & Twilight Calendar Generator

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A high-precision iCalendar feed generator for sunrise, sunset, and twilight times using the full NREL SPA (Solar Position Algorithm). Subscribe in any calendar app for daily solar event notifications.

## Features

- **High-Precision Solar Calculations** - Full NREL SPA algorithm accurate to ±30 seconds
- **Multiple Twilight Types** - Civil, nautical, and astronomical twilight events
- **Smart Supplemental Data** - Complete solar schedule in event notes when fewer types selected
- **Day Length Statistics** - Percentiles, solstice comparisons, yearly trends
- **Moon Phase Information** - Integrated lunar phase data
- **Week Summaries** - Weekly daylight overviews every Sunday
- **Location-Aware** - Special notes for polar, tropical, and equatorial regions
- **Secure** - Token-based authentication
- **Universal Format** - iCalendar (ICS) compatible with all calendar apps

## Quick Start

```bash
# Clone
git clone https://github.com/yourusername/sun-twilight-calendar.git
cd sun-twilight-calendar

# Install dependencies
composer install

# Configure
cp config/config.example.php config/config.php
openssl rand -hex 32  # Generate secure token
# Edit config/config.php and add token

# Deploy to web server and access via browser
```

## Requirements

- PHP 7.4 or higher
- Composer (dependency management)
- Web server (Apache, Nginx)
- HTTPS recommended

## Installation

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure

Edit `config/config.php`:

```php
<?php
define('AUTH_TOKEN', 'your-secure-random-string');  // Required
define('CALENDAR_WINDOW_DAYS', 365);                // Optional
define('UPDATE_INTERVAL', 86400);                   // Optional (24h)
?>
```

### 2. Web Server Setup

**Apache** - Add to `.htaccess`:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ sunrise-sunset-calendar.php [QSA,L]
```

**Nginx** - Add to server block:
```nginx
location / {
    try_files $uri $uri/ /sunrise-sunset-calendar.php?$args;
}
```

### 3. Access

Navigate to `https://yourdomain.com/sunrise-sunset-calendar.php`

## Usage

### Web Interface

1. Enter password (AUTH_TOKEN)
2. Set location and preferences
3. Generate subscription URL
4. Add to calendar app

### API Parameters

```
https://yourdomain.com/sunrise-sunset-calendar.php?
  feed=1&                    # Required
  token=YOUR_TOKEN&          # Required
  lat=45.68&                 # Required (-90 to 90)
  lon=9.55&                  # Required (-180 to 180)
  zone=Europe/Rome&          # Required (PHP timezone)
  civil=1&                   # Optional (include civil twilight)
  nautical=1&                # Optional (include nautical twilight)
  astro=1&                   # Optional (include astronomical twilight)
  daynight=1&                # Optional (include day/night with stats)
  location=MyCity&           # Optional (calendar title)
  rise_off=0&                # Optional (morning offset minutes)
  set_off=0&                 # Optional (evening offset minutes)
  desc=Note                  # Optional (custom note in all events)
```

### Subscribe in Calendar Apps

**Google Calendar:**
1. Copy webcal:// URL
2. Settings → Add calendar → From URL → Paste

**Apple Calendar:**
1. File → New Calendar Subscription → Paste URL

**Outlook:**
1. Calendar → Add Calendar → From Internet → Paste https:// URL

## Event Types

| Type | Sun Angle | Description |
|------|-----------|-------------|
| ☀️ **Civil** | 0° to -6° | Outdoor activities, blue hour photography |
| ⚓ **Nautical** | -6° to -12° | Horizon visible, marine navigation |
| 🌌 **Astronomical** | -12° to -18° | Darkest twilight, astronomy begins/ends |
| ☀️ **Daylight** | Above 0° | Full sun with statistics & percentiles |
| 🌙 **Night** | Below -18° | Complete darkness with moon phases |

## Development

### Run Tests

```bash
# Run all PHPUnit tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit --testsuite Unit           # Unit tests
./vendor/bin/phpunit --testsuite Integration     # Integration tests
./vendor/bin/phpunit --testsuite Reference       # Reference data validation

# Run tests with detailed output
./vendor/bin/phpunit --testdox

# Run comprehensive test suite (includes linting, syntax checks, PHPUnit tests)
./tools/run-tests.sh
```

Test suites:
- **Unit**: Solar calculations, percentiles, formatting, sanitization
- **Integration**: iCalendar generation, RFC 5545 compliance
- **Reference**: Real-world validation against authoritative sources (timeanddate.com, NOAA)

### Code Linting

```bash
# Syntax check
php -l sunrise-sunset-calendar.php

# PSR-12 standard
phpcs --standard=PSR12 *.php

# Auto-fix
phpcbf --standard=PSR12 *.php
```

### Project Structure

```
.
├── sunrise-sunset-calendar.php  # Main app + solar calculations
├── assets/                      # Frontend assets
│   ├── script.js               # Frontend JS
│   ├── styles.css              # Styling
│   └── index.html.php          # Web UI template
├── src/                        # PHP source libraries
│   ├── calendar-generator.php  # iCal event generator
│   └── strings.php            # Localized text/strings
├── config/                     # Configuration
│   ├── config.example.php     # Config template
│   └── config.php             # Actual config (gitignored)
├── tests/                      # PHPUnit test suite
│   ├── bootstrap.php          # PHPUnit bootstrap
│   ├── BaseTest.php           # Base test class
│   ├── Unit/                  # Unit tests
│   │   ├── SolarCalculationsTest.php
│   │   └── PercentileCalculationsTest.php
│   ├── Integration/           # Integration tests
│   │   └── ICalFormatTest.php
│   ├── Reference/             # Reference data validation
│   │   └── MapelloReferenceTest.php
│   ├── Fixtures/              # Test data
│   │   └── ReferenceLocations.php
│   └── run-tests.php          # Legacy test runner
├── tools/                      # Development tools
│   ├── validate-ical.php      # iCal validator
│   ├── validate-project.php   # Project validator
│   └── run-tests.sh           # Full test runner
├── .github/
│   └── workflows/
│       └── ci.yml             # CI pipeline
├── .editorconfig
├── .gitignore
├── .php-cs-fixer.php          # Fixer config
├── phpcs.xml                  # Linter config
├── LICENSE
├── README.md
└── CLAUDE.md                  # Development guide
```

## Solar Calculations

Uses the full NREL SPA (Solar Position Algorithm) via `abbadon1334/sun-position-spa-php`:

- **Algorithm**: NREL SPA by Ibrahim Reda & Afshin Andreas (2008 paper)
- **Accuracy**: ±30 seconds for solar event times
- **Precision**: ±0.0003° for solar positions
- **Date Range**: Valid for years -2000 to 6000
- **Twilight Angles**: Civil (-6°), Nautical (-12°), Astronomical (-18°)
- **Refraction**: Standard atmospheric model (0.833° for sunrise/sunset)

This is the same algorithm used by NREL for authoritative solar research.

## Percentile Algorithm

Shows where today's day length ranks among all 365 days:

```
percentile = (count of days with less daylight / 365) × 100
```

- **0th** - Shortest day (winter solstice)
- **50th** - Median day length (near equinoxes)
- **100th** - Longest day (summer solstice)

**Note**: The percentile is calculated using the actual day length distribution for the entire year at your location. Results may differ from simplified estimates because they account for the full solar position throughout the year.

## Troubleshooting

**Q: Calendar not updating?**
A: Check UPDATE_INTERVAL in config/config.php, force refresh in calendar app

**Q: Times off by 30+ seconds?**
A: Check timezone is correct. Small variations can occur due to atmospheric refraction

**Q: Wrong percentile?**  
A: Verify timezone, latitude, and longitude are correct

**Q: No events appear?**  
A: Check token, ensure PHP 7.4+, review web server error logs

**Q: "Invalid token" error?**
A: Verify token matches exactly in config/config.php and URL

## Security

- Use strong random tokens (32+ characters)
- Enable HTTPS in production
- Rotate tokens periodically
- Set restrictive file permissions:
  ```bash
  chmod 600 config/config.php
  ```
- Never commit config/config.php to version control

## Performance

- **Generation**: <100ms for 365-day calendar
- **Memory**: ~2-5 MB per request
- **Bandwidth**: ~50-100 KB per feed

## Contributing

1. Fork repository
2. Create feature branch
3. Make changes
4. Run tests: `php tests/run-tests.php`
5. Run linter: `phpcs --standard=PSR12 *.php`
6. Commit with clear message
7. Open Pull Request

**Coding Standards**: PSR-12, meaningful names, documented functions

## Known Limitations

- Polar regions during polar day/night may have missing events
- Atmospheric refraction uses standard model (not location-specific)
- Elevation parameter removed in v8.0 (was never used in calculations)

## License

MIT License - see [LICENSE](LICENSE)

## Acknowledgments

- NREL Solar Position Algorithm
- Jean Meeus - Astronomical Algorithms
- OpenStreetMap Nominatim (geocoding)

## Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/sun-twilight-calendar/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/sun-twilight-calendar/discussions)

## Changelog

### v8.0.0 (2026-02-01)
- ✨ **Full NREL SPA algorithm** via external library (±30 second accuracy)
- 📦 First Composer dependency: `abbadon1334/sun-position-spa-php`
- 🗑️ Removed altitude/elevation parameter from UI (kept in API for backward compatibility)
- ✅ Added MapelloReferenceTest.php for real-world validation
- ⬆️ Improved accuracy from ±1-2 minutes to ±30 seconds
- ♻️ Maintained backward compatibility with existing calendar URLs

### v7.3.0 (2026-01-29)
- ✨ High-precision NREL SPA-inspired calculations
- ✨ Accurate percentile algorithm
- ✨ Solstice comparisons in all events
- ✨ Smart supplemental information
- ✨ Week summaries & moon phases
- ♻️ Clean refactored codebase
- ✅ Comprehensive test suite
- 🐛 Fixed percentile using hours not seconds
- 🗑️ Removed unreliable UV index
- 🕐 Always 24-hour format

---

**⭐ Star this repo if you find it useful!**

Made with ☀️ for photographers, astronomers, and solar enthusiasts.
