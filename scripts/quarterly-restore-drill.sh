#!/bin/sh
set -eu

backup_root=${1:-}
evidence_root=${2:-var/restore-drills}
case "$backup_root" in ''|/) echo "Usage: $0 <racine-sauvegardes> [répertoire-preuves]" >&2; exit 64;; esac

latest=$(find "$backup_root" -mindepth 1 -maxdepth 1 -type d -name '????????T??????Z' -print | sort | tail -n 1)
[ -n "$latest" ] || { echo "Aucune sauvegarde horodatée trouvée." >&2; exit 66; }
created=$(basename "$latest")
created_epoch=$(date -u -d "${created%Z}" +%s 2>/dev/null || stat -c %Y "$latest")
started_epoch=$(date -u +%s)
rpo_seconds=$((started_epoch - created_epoch))
rpo_target=${RESTORE_RPO_SECONDS:-86400}
rto_target=${RESTORE_RTO_SECONDS:-14400}

mkdir -p "$evidence_root"
evidence="$evidence_root/$(date -u +%Y%m%dT%H%M%SZ).json"
status=passed
if ! ./scripts/restore-verify.sh "$latest"; then status=failed; fi
finished_epoch=$(date -u +%s)
duration=$((finished_epoch - started_epoch))
[ "$rpo_seconds" -le "$rpo_target" ] || status=failed
[ "$duration" -le "$rto_target" ] || status=failed
printf '{"backup":"%s","status":"%s","rpoSeconds":%s,"rpoTargetSeconds":%s,"restoreVerificationSeconds":%s,"rtoTargetSeconds":%s,"finishedAt":"%s"}\n' \
  "$created" "$status" "$rpo_seconds" "$rpo_target" "$duration" "$rto_target" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$evidence"
echo "Preuve d'exercice : $evidence"
[ "$status" = passed ] || exit 70
