# --- Rust Build Stage ---
FROM rust:bookworm AS rust-builder

# Install PHP dev tools and clang (needed for binding generation)
RUN apt-get update \
 && apt-get install -y --no-install-recommends clang php-dev \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
# Copy the rust library source
COPY rust-lib /app
# Build the release binary
RUN cargo build --release

# --- Final Stage ---
FROM debian:bookworm-slim

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive
ENV NGINX_WORKER_PROCESSES=auto

# Pin Z-Push tarball and verify integrity at build time.
# Override these at build time when upgrading:
#   docker build --build-arg ZPUSH_VERSION=<tag> --build-arg ZPUSH_SHA256=<sha256> ...
ARG ZPUSH_VERSION=2.7.6
ARG ZPUSH_SHA256=71c6ba3bc1dfcf3819dc5741454eea58db13967cc813497f60fef542e1d87091

# Install necessary packages:
# nginx, php, and their dependencies for the web server
# curl, ca-certificates, tar for downloading and extracting files
# tini as a lightweight init system
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    nginx vim telnet iputils-ping patch \
    php-fpm php-cli php-xml php-curl php-mbstring php-soap php-zip php-intl \
    ca-certificates curl tar tini gettext-base \
 && rm -rf /var/lib/apt/lists/*

# Install Composer from official Docker image instead of piping a network script to php.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- Automated Download and Installation ---
# This single RUN command performs all the steps from your script to keep the number of image layers small.
RUN set -e && \
    DOWNLOAD_URL="https://github.com/Z-Hub/Z-Push/archive/refs/tags/${ZPUSH_VERSION}.tar.gz" && \
    echo "Downloading Z-Push ${ZPUSH_VERSION}..." && \
    curl -fsSL "$DOWNLOAD_URL" -o z-push.tar.gz && \
    echo "${ZPUSH_SHA256}  z-push.tar.gz" | sha256sum -c - && \
    echo "Setting up Z-Push directories..." && \
    mkdir -p /var/www/zpush && \
    echo "Extracting Z-Push 'src' directory..." && \
    tar xf ./z-push.tar.gz -C /var/www/zpush --strip-components=2 "Z-Push-${ZPUSH_VERSION}/src" && \
    echo "Cleaning up..." && \
    rm -f z-push.tar.gz

# Copy the Stalwart JMAP backend
# Build context is project root, so we copy from backend/stalwart
COPY backend/stalwart /var/www/zpush/backend/stalwart
COPY VERSION /var/www/zpush/backend/stalwart/VERSION

# Fix invalid folder mappings and empty completion dates in the Z-Push framework.
# Fail the build if an upstream upgrade no longer matches this compatibility patch.
RUN patch --batch --fuzz=0 -p1 -d /var/www/zpush < /var/www/zpush/backend/stalwart/zpush-compat.patch

# Install composer dependencies
RUN echo "Installing main Z-Push composer dependencies..." && \
    composer install --working-dir=/var/www/zpush --no-dev --no-interaction && \
    echo "Installing composer dependencies for Stalwart backend..." && \
    composer require --working-dir=/var/www/zpush/backend/stalwart halaxa/json-machine --update-no-dev --no-interaction

# --- Install Rust Extension ---
# Copy the built extension from the builder stage
# Note: PHP 8.2 extension dir is usually 20220829. We find it dynamically to be safe.
COPY --from=rust-builder /app/target/release/libstalwart_zpush.so /usr/lib/php/20220829/stalwart_zpush.so

# Enable the extension
RUN echo "extension=stalwart_zpush.so" > /etc/php/8.2/mods-available/stalwart_zpush.ini && \
    phpenmod stalwart_zpush

# Prepare additional filesystem directories needed at runtime
RUN mkdir -p /run/php /data/config /data/state /data/log

# Set correct ownership for the web server user (www-data on Debian)
# This is critical to prevent permission errors
RUN chown -R www-data:www-data /var/www/zpush /data

# Copy container-specific config files and the entrypoint script
# Build context is project root, so these are in nginx/
COPY nginx/nginx-zpush.conf /etc/nginx/conf.d/zpush.conf
COPY nginx/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
 && rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default || true

# Expose the default backend port
EXPOSE 10000

# Add a healthcheck to ensure the service is running correctly
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD curl -fsS http://127.0.0.1:10000/healthz || exit 1

# Set the entrypoint for the container
ENTRYPOINT ["/usr/bin/tini","--","/entrypoint.sh"]
