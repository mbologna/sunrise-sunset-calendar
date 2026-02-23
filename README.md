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

## Installation

```bash
git clone https://github.com/yourusername/sun-twilight-calendar.git
cd sun-twilight-calendar
composer install
cp config/config.example.php config/config.php
# Edit config/config.php and set AUTH_TOKEN to a secure random string:
# openssl rand -hex 32
```

## Requirements

- PHP 7.4 or higher (tested through 8.2)
- Composer (dependency management)
- Web server (Apache, Nginx, or PHP built-in server)
- HTTPS recommended for production

## Deployment

### Option 1: Shared Hosting

1. **Upload files** via FTP/SFTP to your web root
2. **Install dependencies** (if SSH available):
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   Or upload the `vendor/` folder from a local install.

3. **Configure**:
   ```bash
   cp config/config.example.php config/config.php
   # Generate a secure token
   openssl rand -hex 32
   # Edit config/config.php and add the token
   ```

4. **Set permissions**:
   ```bash
   chmod 600 config/config.php
   chmod 755 sunrise-sunset-calendar.php
   ```

5. **Access** at `https://yourdomain.com/sunrise-sunset-calendar.php`

### Option 2: VPS/Dedicated Server

1. **Clone repository**:
   ```bash
   git clone https://github.com/yourusername/sun-twilight-calendar.git /var/www/sun-calendar
   cd /var/www/sun-calendar
   ```

2. **Install dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure**:
   ```bash
   cp config/config.example.php config/config.php
   openssl rand -hex 32  # Generate token
   nano config/config.php  # Add token
   chmod 600 config/config.php
   ```

4. **Web server setup**:

   **Apache** - Create `/etc/apache2/sites-available/sun-calendar.conf`:
   ```apache
   <VirtualHost *:443>
       ServerName sun.yourdomain.com
       DocumentRoot /var/www/sun-calendar

       <Directory /var/www/sun-calendar>
           AllowOverride All
           Require all granted
       </Directory>

       SSLEngine on
       SSLCertificateFile /path/to/cert.pem
       SSLCertificateKeyFile /path/to/key.pem
   </VirtualHost>
   ```

   **Nginx** - Create `/etc/nginx/sites-available/sun-calendar`:
   ```nginx
   server {
       listen 443 ssl;
       server_name sun.yourdomain.com;
       root /var/www/sun-calendar;
       index sunrise-sunset-calendar.php;

       ssl_certificate /path/to/cert.pem;
       ssl_certificate_key /path/to/key.pem;

       location / {
           try_files $uri $uri/ /sunrise-sunset-calendar.php?$args;
       }

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }

       # Block access to sensitive files
       location ~ ^/(config|tests|tools|vendor/.*\.(php|md))$ {
           deny all;
       }
   }
   ```

5. **Enable site and restart**:
   ```bash
   # Apache
   a2ensite sun-calendar && systemctl restart apache2

   # Nginx
   ln -s /etc/nginx/sites-available/sun-calendar /etc/nginx/sites-enabled/
   systemctl restart nginx
   ```

### Option 3: Docker

```dockerfile
FROM php:8.2-apache
RUN apt-get update && apt-get install -y unzip
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader
RUN chmod 600 config/config.php
EXPOSE 80
```

```bash
docker build -t sun-calendar .
docker run -p 8080:80 -v $(pwd)/config:/var/www/html/config sun-calendar
```

### Option 4: Local Development

```bash
composer install
cp config/config.example.php config/config.php
# Edit config.php with a test token
php -S localhost:8000
# Visit http://localhost:8000/sunrise-sunset-calendar.php
```

## Configuration

Edit `config/config.php`:

```php
<?php
// Required: Secure random token (32+ characters recommended)
define('AUTH_TOKEN', 'your-secure-random-string');

// Optional: Number of days to generate (default: 365)
define('CALENDAR_WINDOW_DAYS', 365);

// Optional: Cache refresh interval in seconds (default: 86400 = 24h)
define('UPDATE_INTERVAL', 86400);
```

## Health Check

The application provides a health endpoint for monitoring:

```bash
curl https://yourdomain.com/sunrise-sunset-calendar.php?health=1
```

Response:
```json
{
  "status": "ok",
  "version": "10.0",
  "php": "8.2.0",
  "timestamp": 1707400000
}
```

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

### Composer Scripts

```bash
# Run all checks (tests + static analysis + linting)
composer check:all

# Individual commands
composer test              # Run PHPUnit tests
composer test:unit         # Run unit tests only
composer analyse           # Run PHPStan static analysis
composer lint              # Check PSR-12 compliance
composer lint:fix          # Auto-fix PSR-12 violations
composer check             # Validate project structure
composer check:ical        # Validate iCalendar output
```

### Run Tests

```bash
# Run all PHPUnit tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit --testsuite Unit           # Unit tests
./vendor/bin/phpunit --testsuite Integration    # Integration tests
./vendor/bin/phpunit --testsuite Reference      # Reference data validation

# Run tests with detailed output
./vendor/bin/phpunit --testdox

# Run with code coverage
composer test:coverage
```

Test suites:
- **Unit**: Solar calculations, percentiles, formatting, sanitization
- **Integration**: iCalendar generation, RFC 5545 compliance, health endpoint
- **Reference**: Real-world validation against authoritative sources (timeanddate.com, NOAA)

### Static Analysis

```bash
# Run PHPStan at level 5
composer analyse

# Or directly
./vendor/bin/phpstan analyse
```

### Code Linting

```bash
# Check PSR-12 compliance
composer lint

# Auto-fix violations
composer lint:fix
```

### Project Structure

```
.
├── sunrise-sunset-calendar.php  # Main entry point & request routing
├── assets/                      # Frontend assets
│   ├── script.js               # Frontend JavaScript
│   ├── styles.css              # Styling
│   └── index.html.php          # Web UI template
├── src/                        # PHP source modules
│   ├── astronomy.php           # Solar & moon calculations (NREL SPA)
│   ├── Cache.php               # Caching singleton class
│   ├── calendar-generator.php  # iCalendar event generation
│   ├── functions.php           # Core utilities & sanitization
│   ├── geocoding.php           # Location search (Nominatim API)
│   ├── helpers.php             # Helper functions (percentiles, formatting)
│   └── strings.php             # UI text configuration
├── config/                     # Configuration
│   ├── config.example.php     # Config template
│   └── config.php             # Actual config (gitignored)
├── tests/                      # PHPUnit test suite
│   ├── bootstrap.php          # PHPUnit bootstrap
│   ├── BaseTest.php           # Base test class
│   ├── Unit/                  # Unit tests
│   │   ├── FormatTest.php
│   │   ├── SolarCalculationsTest.php
│   │   └── PercentileCalculationsTest.php
│   ├── Integration/           # Integration tests
│   │   ├── ICalendarOutputTest.php
│   │   └── HealthEndpointTest.php
│   ├── Reference/             # Reference data validation
│   │   └── MapelloReferenceTest.php
│   ├── Accuracy/              # Accuracy tests
│   │   ├── EquinoxSolsticeAccuracyTest.php
│   │   └── MoonPhaseAccuracyTest.php
│   └── Fixtures/              # Test data
│       ├── AstronomicalReferenceData.php
│       └── ReferenceLocations.php
├── tools/                      # Development tools
│   ├── validate-ical.php      # iCalendar format validator
│   └── validate-project.php   # Project structure validator
├── .github/
│   └── workflows/
│       └── ci.yml             # GitHub Actions CI pipeline
├── composer.json              # Composer dependencies & scripts
├── phpunit.xml                # PHPUnit configuration
├── phpstan.neon               # PHPStan configuration (level 5)
├── .editorconfig
├── .gitignore
├── .gitattributes
├── LICENSE
├── README.md
└── CLAUDE.md                  # AI development guide
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
4. Run all checks: `composer check:all`
5. Commit with clear message
6. Open Pull Request

**Coding Standards**: PSR-12, meaningful names, PHPDoc annotations, strict types

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

### v10.0.0 (2026-02-08)
- ✨ **Enhanced UI/UX** - Visual progress bars, separators, grouped sections
- ✨ **Moon phase emojis** - Accurate phase icons (🌑🌒🌓🌔🌕🌖🌗🌘)
- ✨ **Week-over-week comparison** - Compare to same week last year
- ✨ **Day of year counter** - "Day 39 of 365" in events
- ✨ **Health endpoint** - `?health=1` for monitoring
- ♻️ **Modular architecture** - Cache class, helpers, cleaner separation
- ♻️ **PHPStan level 5** - Static analysis with strict typing
- ♻️ **Composer scripts** - `check:all`, `analyse`, unified commands
- 📝 **Full word labels** - "9 hours 42 minutes" instead of "9h 42m"
- 📝 **Structured descriptions** - "At a Glance", "Details", "Comparisons" sections

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
