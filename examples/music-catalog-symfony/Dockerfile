# Serve the music-catalog Symfony example over FrankenPHP (https://frankenphp.dev) —
# a self-contained demo of the bundle answering real JSON:API requests over HTTP.
#
# Build from the BUNDLE repository root (the build context is the whole bundle):
#   docker build -f examples/music-catalog-symfony/Dockerfile -t json-api-symfony-example .
#   docker run --rm -p 8080:80 json-api-symfony-example
#   curl -H 'Accept: application/vnd.api+json' http://localhost:8080/albums
#
# Or, from examples/music-catalog-symfony/, just: docker compose up
FROM dunglas/frankenphp

# pdo_sqlite backs the in-memory Doctrine database; intl backs the reference data;
# zip + unzip + git let Composer fetch the Symfony/Doctrine stack.
RUN install-php-extensions intl pdo_sqlite zip \
 && apt-get update && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
# Resolve the unpublished core (dev-main) from GitHub, exactly as CI does, then
# resolve the full tree fresh (the committed lock is path-repo based). The example
# app's Symfony/Doctrine stack rides the bundle's require-dev. `"no-api":true` makes
# Composer clone core over git rather than walking the GitHub commits API (which trips
# GitHub's anonymous secondary rate limit — HTTP 429 — on dev-branch resolution; the
# same fix the CI workflow carries). COMPOSER_MAX_PARALLEL_HTTP caps concurrent dist
# downloads so anonymous codeload fetches don't trip GitHub's rate limit (HTTP 400).
ENV COMPOSER_MAX_PARALLEL_HTTP=6
RUN composer config repositories.haddowg-json-api '{"type":"vcs","url":"https://github.com/haddowg/json-api","no-api":true}' \
 && composer update --no-interaction --prefer-dist --no-progress

ENV SERVER_NAME=:80
ENV SERVER_ROOT=/app/examples/music-catalog-symfony/public

EXPOSE 80
