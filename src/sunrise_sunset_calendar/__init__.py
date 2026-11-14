"""Sun & Twilight Calendar: an iCalendar feed of sunrise, sunset, twilight and the moon."""

import zoneinfo

# Time zones come from the pinned tzdata package, never the host's database, so
# every deployment computes the same times.
zoneinfo.reset_tzpath(to=())
