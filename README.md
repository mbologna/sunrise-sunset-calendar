# Enhanced Sun & Twilight Calendar Generator

A PHP-based tool that generates dynamic iCalendar feeds with comprehensive sun position data, moon phases, and day length tracking for any location worldwide. Perfect for photographers, astronomers, outdoor enthusiasts, or anyone who wants automated sunrise/sunset/twilight notifications in their calendar app.

**Current Version: 6.0** - Moon phases, day length comparison, enhanced descriptions

## What's New in Version 6.0

- 🌙 **Moon Phase Information**: Night events now include current moon phase, illumination percentage, and dates of previous/next phases
- 📊 **Day Length Comparison**: See how today's daylight compares to yesterday (+2m 27s longer, etc.)
- 📝 **Enhanced Descriptions**: More concise and informative event descriptions with BEFORE, DURING, and AFTER explanations
- 🔄 **Reorganized Supplemental Info**: Daylight and Night information now appears after twilight events in supplemental sections
- ✨ **Uniform Formatting**: Consistent formatting across all event types for easier reading

## Features

- ðŸŒ… **Multiple Event Types**: Civil, Nautical, and Astronomical twilight periods plus full day/night cycles
- ðŸ"Š **Detailed Statistics**: Daylight duration, percentages, yearly percentiles, and day-to-day comparisons
- 🌙 **Moon Phase Tracking**: Current phase, illumination, and dates of upcoming lunar events
- ðŸ§  **Smart Single-Event Mode**: Select just one event type to get a clean calendar with all sun data in event notes
- ðŸŒ **Any Location**: Works worldwide with latitude/longitude coordinates
- â° **Custom Offsets**: Set reminders before/after actual sun events
- ðŸ• **12/24 Hour Format**: Choose your preferred time display
- ðŸ"„ **Auto-Updates**: Calendar refreshes daily with new events
- ðŸ"' **Secure**: Password-protected with externalized configuration
- ðŸ"± **Universal**: Works with Google Calendar, Apple Calendar, Outlook, and any iCal-compatible app

## What You Get

### Event Types (Select Any Combination):

1. **ðŸŒŒ Astronomical Dawn/Dusk** - When stars appear/disappear (Sun 12-18Â° below horizon)
2. **âš" Nautical Dawn/Dusk** - When horizon becomes visible/invisible at sea (Sun 6-12Â° below horizon)
3. **ðŸŒ… Civil Dawn/Dusk** - First light to sunrise, sunset to last light (Sun 0-6Â° below horizon)
4. **â˜€ï¸ Day & Night** - Complete daylight period + full night with statistics

### Each Event Includes:

- **BEFORE/DURING/AFTER descriptions** explaining what happens at each stage
- **Concise explanations** - one sentence per stage for easy reading
- **Solar events** (solar noon for day, solar midnight for night)
- **Statistics** (duration, percentage of day, yearly percentile ranking)
- **Day length comparison** (e.g., "+2m 27s longer than yesterday")
- **Moon information** (phase, illumination, upcoming phase changes - in Night events)
- **Complete sun schedule** (when selecting only one event type)

## Quick Start

### 1. Installation

```bash
# Clone repository
git clone https://github.com/yourusername/sun-twilight-calendar.git
cd sun-twilight-calendar

# Create config from example
cp config.example.php config.php

# Generate secure token (Linux/Mac)
openssl rand -hex 32

# Edit config.php and set your AUTH_TOKEN
nano config.php
```

Your `config.php`:
```php
<?php
define('AUTH_TOKEN', 'your_secure_random_token_here');
define('CALENDAR_WINDOW_DAYS', 365);  // Days to generate
define('UPDATE_INTERVAL', 86400);      // Refresh every 24 hours
```

### 2. Deploy

Upload to your web server with PHP support (7.4+). Ensure `config.php` is not web-accessible or in `.gitignore`.

```bash
# Set permissions
chmod 644 *.php
chmod 600 config.php
```

### 3. Generate Calendar

1. Navigate to `https://yourdomain.com/sunrise-sunset-calendar.php`
2. Enter your password (same as AUTH_TOKEN)
3. Set your location (or click "Use My Current Location")
4. Select event types - **Pro tip:** Select only ONE for a clean calendar with complete info
5. Click "Generate Subscription URL"

### 4. Subscribe in Your Calendar App

**Google Calendar:**
1. Copy the subscription URL
2. Google Calendar â†' "+" next to Other calendars â†' From URL
3. Paste URL â†' Add calendar

**Apple Calendar:**
1. Copy the webcal:// URL
2. File â†' New Calendar Subscription â†' Paste URL

**Outlook:**
1. Copy URL
2. Add calendar â†' Subscribe from web â†' Paste URL

## Configuration Options

| Parameter | Description | Default |
|-----------|-------------|---------|
| `lat` | Latitude (-90 to 90) | 41.9028 (Rome) |
| `lon` | Longitude (-180 to 180) | 12.4964 (Rome) |
| `elev` | Elevation in meters | 21 |
| `zone` | Timezone | Europe/Rome |
| `rise_off` | Morning event offset (minutes) | 0 |
| `set_off` | Evening event offset (minutes) | 0 |
| `twelve` | Use 12-hour format | 0 (24-hour) |
| `civil` | Include civil twilight | 0 |
| `nautical` | Include nautical twilight | 0 |
| `astro` | Include astronomical twilight | 0 |
| `sun` | Include day/night events | 0 |
| `desc` | Custom description | Empty |

## Smart Single-Event Mode

**The Secret Sauce:** When you select only ONE event type, all other sun times and statistics are automatically included in each event's description!

**Example:** Select only "Civil Dawn/Dusk" â†' You get:
- Clean calendar with just 2 events per day (dawn and dusk)
- Each event contains: astronomical dawn, nautical dawn, sunrise, solar noon, sunset, nautical dusk, astronomical dusk
- Plus complete daylight/night statistics
- Moon phase information in evening events
- Day length comparison with yesterday
- All with emojis and concise descriptions for easy reading

Perfect for minimalist calendars with maximum information!

## Understanding the Events

### Dawn â†' Dusk Progression:
```
ðŸŒŒ Astronomical Dawn  â†' Stars fade, first light appears
âš" Nautical Dawn       â†' Horizon becomes visible
ðŸŒ… Civil Dawn          â†' Enough light for activities (First Light)
â˜€ï¸ Sunrise            â†' Sun breaks horizon
â˜€ï¸ Solar Noon         â†' Sun at highest point
â˜€ï¸ Sunset             â†' Sun dips below horizon
ðŸŒ‡ Civil Dusk          â†' Artificial light needed (Last Light)
âš" Nautical Dusk       â†' Horizon fades from view
ðŸŒŒ Astronomical Dusk   â†' Complete darkness
ðŸŒ™ Night              â†' Optimal stargazing with moon phase info
```

## Moon Phase Information

Night events now include comprehensive moon data:
- **Current Phase**: New Moon, Waxing Crescent, First Quarter, etc.
- **Illumination**: Percentage of moon visible (0-100%)
- **Previous Phase**: Date and time of last major phase
- **Next Phase**: Date and time of upcoming major phase

Example:
```
🌙 MOON: Waxing Gibbous (67.5% illuminated)
   First Quarter: 26 Jan 2026, 05:47 (Previous Phase)
   Full Moon: 1 Feb 2026, 23:09 (Next Phase)
```

## Day Length Tracking

Each day's events now show how daylight duration compares to the previous day:
- **+2m 27s longer** - Days are getting longer (spring/summer approach)
- **-1m 45s shorter** - Days are getting shorter (fall/winter approach)
- **same length as yesterday** - Near equinox or solstice

This helps you track the progression of seasons and plan accordingly!

## Security

- **Keep AUTH_TOKEN secret** - Never commit `config.php` to version control
- **Use HTTPS** - Required by most calendar apps
- **Unique tokens** - Generate a different token for each installation
- **No data storage** - Everything calculated on-demand, nothing logged

## Troubleshooting

**Calendar not updating?**
- Wait 24 hours for refresh or remove/re-add subscription
- Check URL is still accessible in browser

**Wrong times?**
- Verify coordinates and timezone are correct
- Times calculated using PHP's astronomical algorithms (may differ slightly from other sources)

**Events not appearing?**
- Ensure at least one event type is selected
- Check calendar is visible/enabled in your app
- Wait 5-10 minutes for initial sync

**PHP errors?**
```bash
php -l sunrise-sunset-calendar.php  # Check syntax
tail -f /var/log/nginx/error.log    # Check server logs
```

## Finding Your Coordinates

- **Web interface**: Click "Use My Current Location"
- **Google Maps**: Right-click anywhere â†' coordinates appear
- **Format**: Decimal degrees (e.g., 41.9028, 12.4964)

## Technical Details

- **Language**: PHP 7.4+
- **Format**: iCalendar (RFC 5545)
- **Calculations**: PHP `date_sun_info()` function
- **Moon phases**: Astronomical formula based on synodic month (29.53 days)
- **Performance**: <100ms for 365 days
- **Storage**: Stateless, no database required

## Example Use Cases

- **Photographer**: Civil twilight only for golden/blue hour planning, track moon phases for night photography
- **Astronomer**: Astronomical twilight + night for optimal observation windows with moon phase tracking
- **Outdoor enthusiast**: All twilights for complete day planning with day length trends
- **Minimalist**: Any single event type for clean calendar with full data in notes
- **Lunar observer**: Night events for comprehensive moon phase information and predictions

## Version History

### Version 6.0 (2026)
- Added moon phase information to night events
- Day length comparison with previous day
- Enhanced BEFORE/DURING/AFTER descriptions
- Reorganized supplemental information
- Uniform formatting across event types

### Version 5.3 (2025)
- Redesigned event structure with smart supplemental information
- Dawn/Dusk naming convention
- Enhanced statistics

### Version 5.1 (2025)
- Smart single-event mode
- Improved event naming

## License

Free to use and modify. Originally by pdxvr, enhanced 2025-2026.

## Support

Check PHP error logs and verify configuration. The script is self-contained and requires minimal setup when properly configured.

## Contributing

Contributions welcome! Please ensure:
- Moon phase calculations remain accurate
- Day length comparisons handle edge cases (solstices, equinoxes)
- Event descriptions remain concise and informative
- Code follows existing formatting conventions
