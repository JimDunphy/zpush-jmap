#!/bin/bash
set -e

# Define paths
DATA_DIR="/data"
CONFIG_DIR="$DATA_DIR/config"
STATE_DIR="$DATA_DIR/state"
LOG_DIR="$DATA_DIR/log"

ZPUSH_SRC="/var/www/zpush"
MAIN_CONFIG_FILE="$CONFIG_DIR/config.php"
AUTODISCOVER_CONFIG_FILE="$CONFIG_DIR/autodiscover-config.php"

# --- Helpers ---
die() {
    echo "ERROR: $*" >&2
    exit 1
}

to_php_bool_literal() {
    local name="$1"
    local raw="$2"
    local normalized="${raw,,}"
    case "$normalized" in
        1|true|yes|on) echo "true" ;;
        0|false|no|off) echo "false" ;;
        *) die "Invalid boolean for $name: '$raw'" ;;
    esac
}

to_php_uint_literal() {
    local name="$1"
    local raw="$2"
    local min="$3"
    local max="$4"

    if ! [[ "$raw" =~ ^[0-9]+$ ]]; then
        die "Invalid integer for $name: '$raw'"
    fi

    local numeric=$((10#$raw))
    if (( numeric < min || numeric > max )); then
        die "Out-of-range integer for $name: '$raw' (expected ${min}-${max})"
    fi

    echo "$numeric"
}

escape_php_single_quoted() {
    local raw="$1"
    raw="${raw//\\/\\\\}"
    raw="${raw//\'/\\\'}"
    printf '%s' "$raw"
}

append_php_define_string() {
    local name="$1"
    local value="${2:-}"

    if [ -z "$value" ]; then
        return 0
    fi

    if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
        die "Invalid newline characters for $name"
    fi

    local escaped
    escaped="$(escape_php_single_quoted "$value")"
    echo "Setting $name to: $value"
    printf "    define('%s', '%s');\n" "$name" "$escaped" >> "$STALWART_CONFIG"
}

append_php_define_bool() {
    local name="$1"
    local value="${2:-}"

    if [ -z "$value" ]; then
        return 0
    fi

    local literal
    literal="$(to_php_bool_literal "$name" "$value")"
    echo "Setting $name to: $literal"
    printf "    define('%s', %s);\n" "$name" "$literal" >> "$STALWART_CONFIG"
}

append_php_define_uint() {
    local name="$1"
    local value="${2:-}"
    local min="$3"
    local max="$4"

    if [ -z "$value" ]; then
        return 0
    fi

    local literal
    literal="$(to_php_uint_literal "$name" "$value" "$min" "$max")"
    echo "Setting $name to: $literal"
    printf "    define('%s', %s);\n" "$name" "$literal" >> "$STALWART_CONFIG"
}

append_php_define_enum_string() {
    local name="$1"
    local value="${2:-}"
    local allowed_csv="$3"

    if [ -z "$value" ]; then
        return 0
    fi

    local normalized="${value,,}"
    local match=""
    local option
    IFS=',' read -r -a options <<< "$allowed_csv"
    for option in "${options[@]}"; do
        if [ "$normalized" = "$option" ]; then
            match="$option"
            break
        fi
    done

    if [ -z "$match" ]; then
        die "Invalid value for $name: '$value' (allowed: $allowed_csv)"
    fi

    append_php_define_string "$name" "$match"
}

validate_bind_address_literal() {
    local name="$1"
    local raw="$2"

    if [[ "$raw" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        local octet
        IFS='.' read -r o1 o2 o3 o4 <<< "$raw"
        for octet in "$o1" "$o2" "$o3" "$o4"; do
            if (( octet < 0 || octet > 255 )); then
                die "Invalid IPv4 address for $name: '$raw'"
            fi
        done
        echo "$raw"
        return 0
    fi

    if [[ "$raw" =~ ^\[[0-9A-Fa-f:]+\]$ ]]; then
        echo "$raw"
        return 0
    fi

    if [[ "$raw" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
        echo "$raw"
        return 0
    fi

    die "Invalid bind address for $name: '$raw'"
}

to_php_ini_size_literal() {
    local name="$1"
    local raw="$2"

    if [[ "$raw" == "-1" ]]; then
        echo "-1"
        return 0
    fi

    if [[ "$raw" =~ ^([0-9]+)([KkMmGg]?)$ ]]; then
        local number="${BASH_REMATCH[1]}"
        local suffix="${BASH_REMATCH[2]}"
        suffix="${suffix^^}"
        echo "${number}${suffix}"
        return 0
    fi

    die "Invalid PHP size value for $name: '$raw'"
}

to_nginx_size_literal() {
    local name="$1"
    local raw="$2"

    if [[ "$raw" =~ ^([0-9]+)([KkMmGg]?)$ ]]; then
        local number="${BASH_REMATCH[1]}"
        local suffix="${BASH_REMATCH[2]}"
        suffix="${suffix,,}"
        echo "${number}${suffix}"
        return 0
    fi

    die "Invalid NGINX size value for $name: '$raw'"
}

# --- Fix Volume Permissions & Ensure ALL Log Files Exist ---
echo "Ensuring correct ownership and existence of data files..."
touch "$LOG_DIR/z-push.log" "$LOG_DIR/z-push-error.log"
touch "$LOG_DIR/autodiscover.log" "$LOG_DIR/autodiscover-error.log"
chown -R www-data:www-data "$CONFIG_DIR" "$STATE_DIR" "$LOG_DIR"

# --- Main Configuration Setup ---
if [ ! -f "$MAIN_CONFIG_FILE" ]; then
    echo "No main config.php found. Copying default..."
    cp "$ZPUSH_SRC/config.php" "$MAIN_CONFIG_FILE"
    chown www-data:www-data "$MAIN_CONFIG_FILE"
fi
# Also link it to where Z-Push expects to find it
ln -sf "$MAIN_CONFIG_FILE" "$ZPUSH_SRC/config.php"

# --- Autodiscover Configuration Setup ---
if [ ! -f "$AUTODISCOVER_CONFIG_FILE" ]; then
    echo "No autodiscover-config.php found. Copying default..."
    cp "$ZPUSH_SRC/autodiscover/config.php" "$AUTODISCOVER_CONFIG_FILE"
    chown www-data:www-data "$AUTODISCOVER_CONFIG_FILE"
fi
# Symlink unconditionally — matches the main config pattern above, so
# the link survives container restarts when the config file is already
# present in the persistent volume.
ln -sf "$AUTODISCOVER_CONFIG_FILE" "$ZPUSH_SRC/autodiscover/config.php"

# --- Enforce Correct Paths in BOTH Config Files ---
echo "Verifying and setting persistent data paths..."
# Main config
sed -i -E "s#^(\s*define\('STATE_DIR',).*#\1 '$STATE_DIR/');#" "$MAIN_CONFIG_FILE"
sed -i -E "s#^(\s*define\('LOGFILEDIR',).*#\1 '$LOG_DIR/');#" "$MAIN_CONFIG_FILE"
# Force BackendStalwart
sed -i -E "s#^(\s*define\('BACKEND_PROVIDER',\s*)'[^']*'#\1'BackendStalwart'#" "$MAIN_CONFIG_FILE"
# Autodiscover config
sed -i -E "s#^(\s*define\('LOGFILEDIR',).*#\1 '$LOG_DIR/');#" "$AUTODISCOVER_CONFIG_FILE"
# Same backend pinning as the main config. Without this, autodiscover's
# config has BACKEND_PROVIDER='' which falls through to BackendKopano in
# lib/core/zpush.php::$autoloadBackendPreference, which then fatals on
# the missing PHP-MAPI extension.
sed -i -E "s#^(\s*define\('BACKEND_PROVIDER',\s*)'[^']*'#\1'BackendStalwart'#" "$AUTODISCOVER_CONFIG_FILE"
# Stalwart account names are full email addresses (e.g. user@domain.tld).
# The autodiscover stock default strips to the local part, which Stalwart
# then rejects with a 401.
sed -i -E "s#^(\s*define\('USE_FULLEMAIL_FOR_LOGIN',\s*)(true|false)#\1true#" "$AUTODISCOVER_CONFIG_FILE"

# When clients hit autodiscover at a separate hostname (e.g.
# autodiscover.example.com), Z-Push's createResponse() falls back to
# $_SERVER['HTTP_HOST'] for the EAS server URL — so the autodiscover
# response tells the device "use https://autodiscover.example.com/
# Microsoft-Server-ActiveSync" which is cosmetically wrong (devices
# show autodiscover.* as their server) even though it works at the TLS
# layer if the same cert covers both names. Set ZPUSH_HOST so the
# autodiscover response always carries the canonical EAS hostname.
if [ -n "${ZPUSH_HOST:-}" ]; then
    if grep -qE "^\s*//\s*define\('ZPUSH_HOST'" "$AUTODISCOVER_CONFIG_FILE"; then
        sed -i -E "s#^\s*//\s*define\('ZPUSH_HOST',\s*'[^']*'\);#    define('ZPUSH_HOST', '${ZPUSH_HOST}');#" "$AUTODISCOVER_CONFIG_FILE"
    elif grep -qE "^\s*define\('ZPUSH_HOST'" "$AUTODISCOVER_CONFIG_FILE"; then
        sed -i -E "s#^(\s*define\('ZPUSH_HOST',\s*)'[^']*'#\1'${ZPUSH_HOST}'#" "$AUTODISCOVER_CONFIG_FILE"
    fi
fi

# --- Configure Stalwart Backend from Environment Variables ---
STALWART_CONFIG="/var/www/zpush/backend/stalwart/config.php"
if [ -f "$STALWART_CONFIG" ]; then
    echo "Configuring Stalwart backend from environment variables..."

    # Fix line endings first
    dos2unix "$STALWART_CONFIG" 2>/dev/null || true

    # Remove any existing STALWART_* definitions to avoid duplicates
    sed -i '/define.*STALWART_URL/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_BACKEND_VERSION/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_SSL_VERIFYPEER/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_SSL_VERIFYHOST/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_ALLOW_INSECURE_HTTP/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_CONNECT_TIMEOUT_SECS/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_REQUEST_TIMEOUT_SECS/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_BLOB_TIMEOUT_SECS/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_PUSH_CHANGES_ENABLED/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_PUSH_EVENT_TYPES/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_PUSH_CLOSEAFTER/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_PUSH_PING_SECS/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_ABQ_ENABLED/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_ABQ_ALLOWED_RULES/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_ABQ_BLOCKED_RULES/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_ABQ_QUARANTINED_RULES/d' "$STALWART_CONFIG"
    sed -i '/define.*STALWART_ABQ_QUARANTINE_BY_DEFAULT/d' "$STALWART_CONFIG"

    # Add sanitized configuration at the end of the file.
    append_php_define_string "STALWART_URL" "${STALWART_URL:-}"
    append_php_define_string "STALWART_BACKEND_VERSION" "${STALWART_BACKEND_VERSION:-dev}"
    append_php_define_bool "STALWART_SSL_VERIFYPEER" "${STALWART_SSL_VERIFYPEER:-}"
    append_php_define_bool "STALWART_SSL_VERIFYHOST" "${STALWART_SSL_VERIFYHOST:-}"
    append_php_define_bool "STALWART_ALLOW_INSECURE_HTTP" "${STALWART_ALLOW_INSECURE_HTTP:-}"
    append_php_define_uint "STALWART_CONNECT_TIMEOUT_SECS" "${STALWART_CONNECT_TIMEOUT_SECS:-}" 1 300
    append_php_define_uint "STALWART_REQUEST_TIMEOUT_SECS" "${STALWART_REQUEST_TIMEOUT_SECS:-}" 1 3600
    append_php_define_uint "STALWART_BLOB_TIMEOUT_SECS" "${STALWART_BLOB_TIMEOUT_SECS:-}" 5 7200
    append_php_define_bool "STALWART_PUSH_CHANGES_ENABLED" "${STALWART_PUSH_CHANGES_ENABLED:-}"
    append_php_define_string "STALWART_PUSH_EVENT_TYPES" "${STALWART_PUSH_EVENT_TYPES:-}"
    append_php_define_enum_string "STALWART_PUSH_CLOSEAFTER" "${STALWART_PUSH_CLOSEAFTER:-}" "state,no"
    append_php_define_uint "STALWART_PUSH_PING_SECS" "${STALWART_PUSH_PING_SECS:-}" 10 300
    append_php_define_bool "STALWART_ABQ_ENABLED" "${STALWART_ABQ_ENABLED:-}"
    append_php_define_string "STALWART_ABQ_ALLOWED_RULES" "${STALWART_ABQ_ALLOWED_RULES:-}"
    append_php_define_string "STALWART_ABQ_BLOCKED_RULES" "${STALWART_ABQ_BLOCKED_RULES:-}"
    append_php_define_string "STALWART_ABQ_QUARANTINED_RULES" "${STALWART_ABQ_QUARANTINED_RULES:-}"
    append_php_define_bool "STALWART_ABQ_QUARANTINE_BY_DEFAULT" "${STALWART_ABQ_QUARANTINE_BY_DEFAULT:-}"
else
    echo "Warning: Stalwart backend config not found at $STALWART_CONFIG"
fi


# --- Set PHP and NGINX configuration from environment variables ---
echo "Applying runtime PHP & NGINX settings..."

ZPUSH_BIND_ADDRESS_SAFE="$(validate_bind_address_literal "ZPUSH_BIND_ADDRESS" "${ZPUSH_BIND_ADDRESS:-127.0.0.1}")"
ZPUSH_HTTP_PORT_SAFE="$(to_php_uint_literal "ZPUSH_HTTP_PORT" "${ZPUSH_HTTP_PORT:-10000}" 1 65535)"
PHP_MEMORY_LIMIT_SAFE="$(to_php_ini_size_literal "PHP_MEMORY_LIMIT" "${PHP_MEMORY_LIMIT:-256M}")"
PHP_UPLOAD_MAX_FILESIZE_SAFE="$(to_php_ini_size_literal "PHP_UPLOAD_MAX_FILESIZE" "${PHP_UPLOAD_MAX_FILESIZE:-50M}")"
PHP_POST_MAX_SIZE_SAFE="$(to_php_ini_size_literal "PHP_POST_MAX_SIZE" "${PHP_POST_MAX_SIZE:-50M}")"
PHP_MAX_EXECUTION_TIME_SAFE="$(to_php_uint_literal "PHP_MAX_EXECUTION_TIME" "${PHP_MAX_EXECUTION_TIME:-3600}" 0 86400)"
PHP_MAX_INPUT_TIME_SAFE="$(to_php_uint_literal "PHP_MAX_INPUT_TIME" "${PHP_MAX_INPUT_TIME:-3600}" 0 86400)"
NGINX_CLIENT_MAX_BODY_SIZE_SAFE="$(to_nginx_size_literal "NGINX_CLIENT_MAX_BODY_SIZE" "${NGINX_CLIENT_MAX_BODY_SIZE:-50m}")"

sed -i 's|unix:/run/php/php-fpm.sock|unix:/run/php/php8.2-fpm.sock|' /etc/nginx/conf.d/zpush.conf
sed -i "s/\${ZPUSH_BIND_ADDRESS}/${ZPUSH_BIND_ADDRESS_SAFE}/g" /etc/nginx/conf.d/zpush.conf
sed -i "s/\${ZPUSH_HTTP_PORT}/${ZPUSH_HTTP_PORT_SAFE}/g" /etc/nginx/conf.d/zpush.conf
sed -i "s/^;?memory_limit = .*/memory_limit = ${PHP_MEMORY_LIMIT_SAFE}/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;?upload_max_filesize = .*/upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE_SAFE}/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;?post_max_size = .*/post_max_size = ${PHP_POST_MAX_SIZE_SAFE}/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;?max_execution_time = .*/max_execution_time = ${PHP_MAX_EXECUTION_TIME_SAFE}/" /etc/php/8.2/fpm/php.ini
sed -i "s/^;?max_input_time = .*/max_input_time = ${PHP_MAX_INPUT_TIME_SAFE}/" /etc/php/8.2/fpm/php.ini
sed -i "s/client_max_body_size .*;$/client_max_body_size ${NGINX_CLIENT_MAX_BODY_SIZE_SAFE};/" /etc/nginx/conf.d/zpush.conf
chown www-data:www-data /run/php

echo "Starting PHP-FPM and NGINX..."
php-fpm8.2 -D
exec nginx -g 'daemon off;'
