#!/bin/sh
set -eu

env_file=${1:-.env}
if [ ! -f "$env_file" ]; then
  echo "Fichier d'environnement introuvable : $env_file" >&2
  exit 64
fi

read_value() {
  sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed "s/^[\"']//;s/[\"']$//"
}

failed=0
require_value() {
  key=$1
  minimum=${2:-1}
  value=$(read_value "$key")
  if [ "${#value}" -lt "$minimum" ]; then
    echo "ERREUR: $key est absent ou trop court (minimum $minimum caractères)." >&2
    failed=1
  fi
}

require_value APP_SECRET 32
require_value POSTGRES_PASSWORD 16
require_value JWT_PASSPHRASE 16

app_env=$(read_value APP_ENV)
app_debug=$(read_value APP_DEBUG)
app_url=$(read_value APP_URL)
[ "$app_env" = prod ] || { echo "ERREUR: APP_ENV doit valoir prod." >&2; failed=1; }
[ "$app_debug" = 0 ] || { echo "ERREUR: APP_DEBUG doit valoir 0." >&2; failed=1; }
case "$app_url" in https://*) ;; *) echo "ERREUR: APP_URL doit être une origine HTTPS." >&2; failed=1 ;; esac

if grep -Eqi '(change[-_ ]?me|replace[-_ ]?me|example[-_ ]?secret|your[-_ ]?secret|dev[-_ ]?secret|password123)' "$env_file"; then
  echo "ERREUR: une valeur factice connue subsiste dans $env_file." >&2
  failed=1
fi

app_secret=$(read_value APP_SECRET)
postgres_password=$(read_value POSTGRES_PASSWORD)
jwt_passphrase=$(read_value JWT_PASSPHRASE)
if [ -n "$app_secret" ] && { [ "$app_secret" = "$postgres_password" ] || [ "$app_secret" = "$jwt_passphrase" ]; }; then
  echo "ERREUR: APP_SECRET doit être distinct des autres secrets." >&2
  failed=1
fi
if [ -n "$postgres_password" ] && [ "$postgres_password" = "$jwt_passphrase" ]; then
  echo "ERREUR: POSTGRES_PASSWORD et JWT_PASSPHRASE doivent être distincts." >&2
  failed=1
fi

[ "$failed" -eq 0 ] || exit 65
echo "Configuration de production validée sans afficher les secrets : $env_file"
