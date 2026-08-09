#!/bin/sh
set -eu

base_url=${1:-https://demo.riskpilot.site}
requests=${LOAD_REQUESTS:-100}
concurrency=${LOAD_CONCURRENCY:-10}
max_p95_ms=${LOAD_MAX_P95_MS:-750}

case "$requests:$concurrency:$max_p95_ms" in *[!0-9:]*) echo "Les paramètres de charge doivent être des entiers positifs." >&2; exit 64;; esac
[ "$requests" -gt 0 ] && [ "$concurrency" -gt 0 ] && [ "$max_p95_ms" -gt 0 ] || exit 64

results_directory=$(mktemp -d)
cleanup() { rm -rf -- "$results_directory"; }
trap cleanup EXIT HUP INT TERM

export RISKPILOT_LOAD_URL="$base_url/api/health"
export RISKPILOT_LOAD_RESULTS="$results_directory"
seq 1 "$requests" | xargs -P "$concurrency" -n 1 sh -c '
  result=$(curl --silent --show-error --output /dev/null --max-time 10 --write-out "%{http_code} %{time_total}" "$RISKPILOT_LOAD_URL" 2>/dev/null) || result="000 10"
  printf "%s\n" "$result" > "$RISKPILOT_LOAD_RESULTS/$1"
' sh

errors=$(awk '$1 != 200 { count++ } END { print count + 0 }' "$results_directory"/*)
sorted_times="$results_directory/times"
awk '{ printf "%.0f\n", $2 * 1000 }' "$results_directory"/* | sort -n > "$sorted_times"
p95_rank=$(( (requests * 95 + 99) / 100 ))
p95_ms=$(sed -n "${p95_rank}p" "$sorted_times")
max_ms=$(tail -n 1 "$sorted_times")

echo "Charge HTTP : $requests requêtes, concurrence $concurrency, erreurs $errors, p95 ${p95_ms} ms, max ${max_ms} ms."
[ "$errors" -eq 0 ] || exit 70
[ "$p95_ms" -le "$max_p95_ms" ] || { echo "Le p95 dépasse le seuil de ${max_p95_ms} ms." >&2; exit 71; }
