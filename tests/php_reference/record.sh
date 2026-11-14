#!/bin/sh
# Records primitives.php's output with the PHP release image, in winter and in
# summer, into primitives-<moment>.json. Needs Docker.
set -eu
cd "$(dirname "$0")"
image=sunrise-sunset-calendar-golden-recorder
docker build -q -t "$image" ../golden/generator >/dev/null
for moment in 2026-01-15 2026-07-15; do
    docker run --rm -i --entrypoint sh -e FAKETIME="@$moment 12:00:00" "$image" \
        -c 'LD_PRELOAD=/usr/lib/faketime-dir/libfaketime.so.1 php' < primitives.php > "primitives-$moment.json"
done
