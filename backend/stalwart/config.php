<?php
/***********************************************
* File        :   config.php
* Project     :   Z-Push - Stalwart JMAP Backend
* Description :   Backend configuration for Stalwart mail server
*
* The STALWART_URL is typically injected by the Docker entrypoint
* from the STALWART_URL environment variable. You can also set it
* manually here for non-Docker deployments.
************************************************/

    // ****************************
    // BackendStalwart settings
    // ****************************

    // URL to the Stalwart JMAP server (no trailing slash)
    // This is set automatically by the Docker entrypoint from the
    // STALWART_URL environment variable. Uncomment to set manually:
//  define('STALWART_URL', 'http://localhost:10080');

    // Application version comes from the root VERSION file, copied beside this
    // configuration in an image. A build/deployment label can identify a rebuild.
    $applicationVersionFile = __DIR__ . '/VERSION';
    if (!is_file($applicationVersionFile)) $applicationVersionFile = dirname(__DIR__, 2) . '/VERSION';
    define('STALWART_APPLICATION_VERSION', is_file($applicationVersionFile) ? trim(file_get_contents($applicationVersionFile)) : 'unknown');
    define('STALWART_BACKEND_VERSION', STALWART_APPLICATION_VERSION);

    // SSL certificate verification settings
    // Keep both true for production. Set to false only for controlled lab testing.
//  define('STALWART_SSL_VERIFYPEER', true);
//  define('STALWART_SSL_VERIFYHOST', true);

    // Allow insecure HTTP transport to non-loopback Stalwart hosts.
    // Leave false in production.
//  define('STALWART_ALLOW_INSECURE_HTTP', false);

    // Rust JMAP transport timeouts (seconds)
//  define('STALWART_CONNECT_TIMEOUT_SECS', 10);
//  define('STALWART_REQUEST_TIMEOUT_SECS', 60);
//  define('STALWART_BLOB_TIMEOUT_SECS', 180);

    // JMAP Push-assisted ChangeSink controls.
    // When enabled and supported by the upstream session, ChangesSink waits on eventSourceUrl
    // and falls back to polling on errors.
//  define('STALWART_PUSH_CHANGES_ENABLED', true);
//  define('STALWART_PUSH_EVENT_TYPES', 'Mailbox,Email,ContactCard,CalendarEvent');
//  define('STALWART_PUSH_CLOSEAFTER', 'state'); // valid: state, no
//  define('STALWART_PUSH_PING_SECS', 30);

    // Device ABQ policy controls (Allow / Block / Quarantine).
    // Rule format:
    //   - wildcard (case-insensitive): *iphone*, corp-*
    //   - regex (case-insensitive): re:^iphone[0-9]+$
    //   - regex patterns are cached per process and limited to 256 characters
    // Optional field prefixes:
    //   ua:pattern    -> User-Agent
    //   dt:pattern    -> Device type
    //   id:pattern    -> Device ID
    //   any:pattern   -> match any field (default when no prefix)
    //
    // If ALLOWED_RULES is non-empty, unmatched devices are denied (or quarantined
    // when QUARANTINE_BY_DEFAULT is true).
//  define('STALWART_ABQ_ENABLED', false);
//  define('STALWART_ABQ_ALLOWED_RULES', array());
//  define('STALWART_ABQ_BLOCKED_RULES', array());
//  define('STALWART_ABQ_QUARANTINED_RULES', array());
//  define('STALWART_ABQ_QUARANTINE_BY_DEFAULT', false);

    // Enable HTML email support for older ActiveSync protocol levels
    define('STALWART_HTML', true);

    // Enable debug logging for the Stalwart backend
    // Possible values:
    //   true     - debug logging for all users
    //   false    - no debug logging (default)
    //   'user@domain' - debug logging for specific user only
//  define('STALWART_DEBUG', false);

    // By default, deleted messages are moved to Trash.
    // Set to false to permanently delete immediately.
    define('STALWART_DELETESASMOVES', true);

    // mbstring function overload detection
    define('MBSTRING_OVERLOAD', (extension_loaded('mbstring') ? ini_get('mbstring.func_overload') : false));
