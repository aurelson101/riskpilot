#!/bin/sh
set -eu

if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
  echo "Usage: $0 <sauvegarde>" >&2
  exit 64
fi

backup_directory=$1
(cd "$backup_directory" && sha256sum -c SHA256SUMS)
gzip -t "$backup_directory/postgresql.sql.gz"
tar -tzf "$backup_directory/isms-documents.tar.gz" >/dev/null
test -s "$backup_directory/redis.rdb"
echo "Sauvegarde restaurable : $backup_directory"
