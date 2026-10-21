#!/bin/sh
# Emergency build while the official patched PHP image is not yet published.
# Run on a build host, not on the memory-constrained demo VPS.
set -eu
upstream=9dac3d048ebdcdb3b6a27350b7572ddee58c70df
context=$(mktemp -d)
trap 'rm -rf -- "$context"' EXIT HUP INT TERM
base="https://raw.githubusercontent.com/docker-library/php/$upstream/8.4/alpine3.23/fpm"
for file in Dockerfile docker-php-source docker-php-ext-configure docker-php-ext-enable docker-php-ext-install docker-php-entrypoint; do
    curl --fail --show-error --silent --location "$base/$file" -o "$context/$file"
done
chmod +x "$context"/docker-php-*
# Keep upstream SHA256 and GPG signature verification; only replace the release
# metadata and cap compilation parallelism. Hash published by php.net.
sed -i \
    -e 's/8\.4\.26/8.4.27/g' \
    -e 's/32a2de53862ad44ed4a5005244ce4f1b50c271e74dced215449a4443b40569f1/dcf93923b0e38e0d5252b25b796520b5a47761a08b3488b6d0b5d1115764d3f0/g' \
    -e 's/make -j "$(nproc)"/make -j 2/' \
    "$context/Dockerfile"
docker build -t riskpilot-php:8.4.27-fpm-alpine "$context"
docker run --rm riskpilot-php:8.4.27-fpm-alpine \
    php -r 'exit(PHP_VERSION === "8.4.27" ? 0 : 1);'
