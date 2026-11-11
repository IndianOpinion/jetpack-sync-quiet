#!/usr/bin/env bash
# Count Jetpack spawn-sync loopbacks per day in an nginx or Apache combined log.
#
# Usage: examples/count-spawn-sync.sh /var/log/nginx/access.log [more logs...]
#        zcat /var/log/nginx/access.log.*.gz | examples/count-spawn-sync.sh -
#
# Prints: day, spawn-sync requests, all requests, share. Read the origin's log,
# not the CDN's: the loopback never leaves the server.
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "usage: $0 <access.log>... (use - for standard input)" >&2
    exit 64
fi

cat -- "$@" | awk '
    {
        # Combined log: the date is in field 4, like [11/Nov/2026:12:00:00
        day = substr($4, 2, 11)
        if (day == "") { next }
        total[day]++
        if ($7 ~ /\/wp-json\/jetpack\/v4\/sync\/spawn-sync/ || $7 ~ /rest_route=(%2F|\/)jetpack(%2F|\/)v4(%2F|\/)sync(%2F|\/)spawn-sync/) {
            spawn[day]++
        }
        if (!(day in seen)) { seen[day] = 1; order[++n] = day }
    }
    END {
        printf "%-12s %12s %12s %8s\n", "day", "spawn-sync", "all", "share"
        for (i = 1; i <= n; i++) {
            d = order[i]
            s = spawn[d] + 0
            printf "%-12s %12d %12d %7.2f%%\n", d, s, total[d], (total[d] ? 100 * s / total[d] : 0)
        }
    }
'
