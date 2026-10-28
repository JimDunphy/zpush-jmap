# Internals — Developer Guide

This document explains how the Z-Push Stalwart backend works, aimed at developers who want to understand, debug, or extend the code.

## Architecture Overview

The system has three layers:

```
┌──────────────────────────────────────────────────────┐
│  Z-Push Framework (upstream, not modified)            │
│  Receives ActiveSync XML, calls BackendDiff methods   │
└──────────────────┬───────────────────────────────────┘
                   │  PHP method calls
┌──────────────────▼───────────────────────────────────┐
│  BackendStalwart  (backend/stalwart/stalwart.php)     │
│  ~1,838 lines — thin adapter layer                    │
│  Maps Z-Push calls → Rust methods                     │
│  Builds SyncMail, SyncContact, SyncAppointment objects│
└──────────────────┬───────────────────────────────────┘
                   │  ext-php-rs FFI calls
┌──────────────────▼───────────────────────────────────┐
│  Rust Extension  (rust-lib/src/lib.rs)                │
│  ~2,728 lines — all JMAP protocol work                │
│  JmapClient struct with 34 methods                    │
│  Handles: HTTP, auth, JSON, data flattening           │
└──────────────────┬───────────────────────────────────┘
                   │  HTTP/JMAP
              [ Stalwart Mail Server ]
```

**Design principle:** Rust does all JMAP protocol work (HTTP calls, JSON parsing, field extraction). PHP only maps between Z-Push's ActiveSync objects and the flat structs returned by Rust.

## Device ABQ Flow

Optional ABQ policy enforcement runs in `BackendStalwart::Logon()` before `jmap_connect()`:

1. PHP gathers device context (`device_id`, `device_type`, `user_agent`, remote IP) from `Request`/DeviceManager.
2. PHP calls Rust `abq_evaluate_device(...)` with configured rule lists.
3. Rust returns an `AbqDecision` (`allow`, `block`, or `quarantine`) with optional matched rule metadata.
4. PHP allows login only for `allow`. `block` and `quarantine` are denied (quarantine is currently enforced as deny).

Rule matching is implemented in Rust (`rust-lib/src/lib.rs`) with:

- Wildcard matching (`*`, `?`) case-insensitive.
- Optional regex rules via `re:` prefix.
- `re:` patterns are cached per process and limited to 256 characters.
- Runtime cache stats are exposed via Rust `abq_regex_cache_stats()` and logged from PHP ABQ evaluation.
- Optional field prefixes (`ua:`, `dt:`, `id:`, `any:`).


## Runtime Config and Secret Handling

- `nginx/entrypoint.sh` validates `STALWART_*`, `ZPUSH_*`, `PHP_*`, and `NGINX_*` env inputs before writing/patching runtime config.
- `BackendStalwart::ClearRustRuntimeConfig()` clears process-level `STALWART_*` env values before each runtime export and again during `Logoff()`.
- `BackendStalwart::Logon()` clears the incoming PHP password variable (with `sodium_memzero()` when available) in a `finally` block.
- Rust `JmapClient` stores credentials as `zeroize::Zeroizing<String>` and uses borrowed `&str` only when issuing Basic Auth requests.

## ext-php-rs Naming Conventions

The Rust crate [ext-php-rs](https://github.com/davidcole1340/ext-php-rs) bridges Rust and PHP. Its naming rules are critical:

### Methods: snake_case -> camelCase

The `#[php_impl]` macro automatically converts Rust method names to camelCase in PHP:

```
Rust:  fn get_mailboxes()      -> PHP: $client->getMailboxes()
Rust:  fn query_contacts()     -> PHP: $client->queryContacts()
Rust:  fn download_blob_to_file() -> PHP: $client->downloadBlobToFile()
```

### Properties: keep exact Rust name

The `#[prop]` attribute keeps the Rust field name unchanged in PHP (snake_case):

```
Rust:  parent_id: Option<String>  -> PHP: $mb->parent_id
Rust:  first_name: String         -> PHP: $contact->first_name
Rust:  utc_start: String          -> PHP: $event->utc_start
```

Properties are NOT visible via `ReflectionClass::getProperties()` because ext-php-rs uses C-level handlers.

## JmapClient — The Rust Core

`jmap_connect(url, username, password)` creates a `JmapClient` instance that holds the HTTP session. All subsequent calls go through this object.

### 34 Methods

**Mail (14 methods):**

| Rust method | PHP method | Returns |
|---|---|---|
| `get_account_id()` | `getAccountId()` | String |
| `get_mailboxes()` | `getMailboxes()` | Vec\<JmapMailbox\> |
| `query_emails(folder, cutoff, limit, pos)` | `queryEmails()` | JmapEmailQueryResult |
| `get_email_metadata(ids)` | `getEmailMetadata()` | Vec\<JmapEmailMeta\> |
| `get_email(id)` | `getEmail()` | JmapEmail |
| `download_blob_to_file(blob_id, path)` | `downloadBlobToFile()` | i64 (bytes) |
| `set_email_keywords(id, keywords)` | `setEmailKeywords()` | bool |
| `send_email(mime_path, sent_folder)` | `sendEmail()` | bool |
| `move_email(id, from, to)` | `moveEmail()` | bool |
| `trash_email(id, trash_id)` | `trashEmail()` | bool |
| `destroy_email(id)` | `destroyEmail()` | bool |
| `get_email_state()` | `getEmailState()` | String |
| `get_email_changes(since)` | `getEmailChanges()` | JmapChanges |
| `get_mailbox_state/changes()` | `getMailboxState/Changes()` | String / JmapChanges |

**Contacts (9 methods):**

| Rust method | PHP method | Returns |
|---|---|---|
| `get_address_books()` | `getAddressBooks()` | Vec\<JmapAddressBook\> |
| `query_contacts(book_id, limit, pos)` | `queryContacts()` | JmapContactQueryResult |
| `get_contact_metadata(ids)` | `getContactMetadata()` | Vec\<JmapContactMeta\> |
| `get_contact(id)` | `getContact()` | JmapContact |
| `create_contact(book_id, json)` | `createContact()` | String (new ID) |
| `update_contact(id, json)` | `updateContact()` | bool |
| `destroy_contact(id)` | `destroyContact()` | bool |
| `get_contact_state()` | `getContactState()` | String |
| `get_contact_changes(since)` | `getContactChanges()` | JmapChanges |

**Calendar and tasks:**

| Rust method | PHP method | Returns |
|---|---|---|
| `get_calendars()` | `getCalendars()` | Vec\<JmapCalendar\> |
| `query_calendar_events(cal, after, before, limit, pos)` | `queryCalendarEvents()` | JmapCalendarEventQueryResult |
| `get_calendar_event_metadata(ids)` | `getCalendarEventMetadata()` | Vec\<JmapCalendarEventMeta\> |
| `get_tasks(ids)` | `getTasks()` | Vec\<JmapTask\>, native Task objects only |
| `get_calendar_event(id)` | `getCalendarEvent()` | JmapCalendarEvent |
| `create_calendar_event(cal_id, json)` | `createCalendarEvent()` | String (new ID) |
| `update_calendar_event(id, json)` | `updateCalendarEvent()` | bool |
| `destroy_calendar_event(id)` | `destroyCalendarEvent()` | bool |
| `get_calendar_event_state()` | `getCalendarEventState()` | String |
| `get_calendar_event_changes(since)` | `getCalendarEventChanges()` | JmapChanges |

### PHP-Facing Collaboration Types

| Type | Key Fields |
|---|---|
| `JmapAddressBook` | id, name, is_default |
| `JmapContactMeta` | id, address_book_ids |
| `JmapContact` | id, uid, first_name, last_name, middle_name, title, suffix, nickname, company, department, job_title, email1-3, work_phone, home_phone, mobile_phone, ..., birthday, anniversary, notes, has_photo, photo_blob_id (~45 fields) |
| `JmapContactQueryResult` | ids, total |
| `JmapCalendar` | id, name, is_default |
| `JmapCalendarEventMeta` | id, object_type, calendar_ids, uid, utc_start, utc_end |
| `JmapCalendarEvent` | id, uid, title, description, location, start, time_zone, utc_start, utc_end, duration, all_day, organizer_name/email, busy_status, sensitivity, reminder_minutes, attendees_json, recurrence_json, exceptions_json |
| `JmapCalendarEventQueryResult` | ids, total |
| `JmapTask` | id, data_json (native task fields used for mapping and content fingerprints) |

## Data Strategies

### Binary Data (File-Based Transfer)

Rust and PHP exchange binary data via temp files to avoid encoding issues:

- **Blob download:** Rust `downloadBlobToFile(blob_id, path)` writes to file, PHP reads with `file_get_contents()`
- **Email send:** PHP writes MIME to temp file, Rust `sendEmail(path, folder)` reads it
- **Contact photo:** Same as blob download, PHP base64-encodes for ActiveSync

### Attachments

Email attachments are serialized as a JSON string by Rust (`attachments_json`). PHP decodes and builds ActiveSync attachment objects. Attachment references use hex-encoded `folderid:emailid:blobid` strings for the `GetAttachmentData()` round-trip.

### Complex Nested Data (JSON Strings)

For data structures too complex to flatten into scalar fields, Rust serializes them as JSON strings:

- `attendees_json` — array of `{name, email, role, status}`
- `recurrence_json` — `{type, interval, dayOfWeek, until, count, ...}`
- `exceptions_json` — array of `{original_start, deleted, title, utc_start, utc_end, ...}`

PHP decodes these and builds the corresponding `SyncAttendee`, `SyncRecurrence`, and `SyncAppointmentException` objects.

## ID Prefixing

Address book and calendar IDs could collide with mailbox IDs (Stalwart uses short IDs like "b", "c"). The PHP layer adds prefixes to disambiguate:

| Type | Prefix | Example | JMAP ID |
|---|---|---|---|
| Mailbox | *(none)* | `a` | `a` |
| Address book | `ab-` | `ab-b` | `b` |
| Calendar | `cal-` | `cal-b` | `b` |

The `jmapId` property on folder objects stores the raw JMAP ID. When calling Rust methods, the prefix is stripped.

## JSContact / JSCalendar Mapping

Stalwart uses the modern JSContact (RFC 9553) and JSCalendar (RFC 8984) formats. These are deeply nested JSON structures. Rust flattens them into ActiveSync-friendly flat structs.

### Contact Mapping (JSContact -> JmapContact)

| JSContact path | JmapContact field |
|---|---|
| `name.components[kind=given]` | `first_name` |
| `name.components[kind=surname]` | `last_name` |
| `name.components[kind=given2]` | `middle_name` |
| `name.components[kind=suffix]` | `suffix` |
| `name.components[kind=title]` | `title` |
| `emails` (by context: private, work) | `email1`, `email2`, `email3` |
| `phones` (by context+features) | `work_phone`, `home_phone`, `mobile_phone`, `work_fax`, etc. |
| `addresses` (by context) | `home_street/city/state/...`, `work_street/city/state/...` |
| `organizations[0].name` | `company` |
| `organizations[0].units[0].name` | `department` |
| `titles[0].name` | `job_title` |
| `anniversaries` (by type) | `birthday`, `anniversary` |
| `notes` | `notes` |
| `links` (first work URL) | `webpage` |

### Calendar Mapping (JSCalendar -> JmapCalendarEvent)

| JSCalendar path | JmapCalendarEvent field |
|---|---|
| `title` | `title` |
| `description` | `description` |
| `start` + `timeZone` | `start`, `time_zone` |
| `utcStart` / `utcEnd` | `utc_start`, `utc_end` |
| `duration` | `duration` |
| `showWithoutTime` | `all_day` |
| `freeBusyStatus` | `busy_status` (0=free, 1=tentative, 2=busy, 3=unavailable) |
| `privacy` | `sensitivity` (0=public, 2=private) |
| `participants` (owner) | `organizer_name`, `organizer_email` |
| `participants` (attendees) | `attendees_json` |
| `alerts` (first display) | `reminder_minutes` |
| `recurrenceRules` | `recurrence_json` |
| `recurrenceOverrides` | `exceptions_json` |

## PHP Backend Method Dispatch

The PHP backend dispatches most methods by folder **view** property:

```php
$view = $this->_folders[$index]->view;  // 'message', 'contact', or 'appointment'

switch ($view) {
    case 'contact':    return $this->ContactMethod(...);
    case 'appointment': return $this->AppointmentMethod(...);
    default:           return $this->EmailMethod(...);
}
```

Methods that dispatch by view: `GetMessageList`, `StatMessage`, `GetMessage`, `ChangeMessage`, `DeleteMessage`.

Methods that are email-only: `SendMail`, `MoveMessage`, `SetReadFlag`, `GetAttachmentData`.

## Change Tracking (ChangesSink)

Tasks are mapped by `backend/stalwart/tasks.php` and the Rust `getTasks()` method.
Native `CalendarEvent` objects with `@type: Task` are exposed in `task-` folders;
appointments stay in `cal-` folders. Task metadata uses a content fingerprint,
and its independent change baseline wakes task folders without consuming the
appointment view's notification. See [task behavior and limits](TASKS.md).

`HasChangesSink()` advertises notification support to Z-Push. Without this override,
the framework bypasses `ChangesSink()` and polls folder contents every 30 seconds.
The backend tracks three independent JMAP states for monitored folder types:

- `_emailState` — from `getEmailState()` / `getEmailChanges()`
- `_contactState` — from `getContactState()` / `getContactChanges()`
- `_calendarEventState` — from `getCalendarEventState()` / `getCalendarEventChanges()`

`ChangesSinkInitialize()` captures the relevant type's state before the framework's
initial folder comparison. `ChangesSink()` checks `/changes` before connecting to
JMAP `eventSourceUrl` and after every wait, including timeout, EOF, or error. This
catches changes between connections even if an SSE notification is missed. Push
is enabled by `STALWART_PUSH_CHANGES_ENABLED`; unavailable push falls back to
5-second polling. Waits use the interval supplied by Z-Push (normally 30 seconds).

Changes notify all monitored folders of that type, covering arrivals, deletions,
and moves. Z-Push then compares actual folder contents with the device's saved
state and sends an ActiveSync Ping notification when a sync is needed. An expired
JMAP state triggers another folder comparison; it does not reset ActiveSync state.
An unavailable contact/calendar type is retried independently of mail.

At INFO level, `ChangesSink(): JMAP push received` confirms an upstream event;
`source=JMAP push changed folders=...` confirms a resulting folder notification.
`source=push catch-up`, `push recovery`, or `poll` identifies fallback detection.
At DEBUG level, `Waiting on JMAP event source` records each push wait. A completed
Ping and subsequent Sync, followed by the device updating its view, complete the
end-to-end check.

## ActiveSync Timezone Blob

ActiveSync encodes timezones as a 172-byte binary blob (base64-encoded). The PHP backend converts between IANA timezone names (from JMAP) and these blobs:

- **Reading:** `GetActiveSync_Timezone($ianaName)` — builds the blob from PHP's `DateTimeZone` transitions
- **Writing:** `ParseActiveSync_Timezone($b64blob)` — extracts the UTC offset bias and finds a matching IANA zone

## Docker Build

The Dockerfile has two stages:

1. **rust-builder** — `rust:bookworm` with `clang` and `php-dev`, compiles `libstalwart_zpush.so`
2. **Final image** — `debian:bookworm-slim` with Nginx, PHP-FPM, and Z-Push. Copies the `.so` from stage 1.

The Rust build is cached by Docker layers. After the first build, only PHP/config changes trigger a fast rebuild (~3 seconds).

```bash
# Build image
docker compose -f nginx/docker-compose.yml build

# Verify extension loads
docker run --rm --entrypoint php local/zpush:latest -r \
  "var_dump(function_exists('jmap_connect'));"

# List all methods
docker run --rm --entrypoint php local/zpush:latest -r \
  "\$r = new ReflectionClass('JmapClient'); foreach (\$r->getMethods() as \$m) echo \$m->getName().'\n';"
```

## Adding a New Method

To add a new Rust method exposed to PHP:

1. **Rust** (`lib.rs`): Add the method to the `#[php_impl] impl JmapClient` block. Use snake_case naming. Return types must be PHP-compatible (String, bool, i64, Vec, or a `#[php_class]` struct).

2. **PHP** (`stalwart.php`): Call it as `$this->_jmapClient->camelCaseName()`.

3. **Build & test:**
   ```bash
   docker compose -f nginx/docker-compose.yml build
   docker run --rm --entrypoint php local/zpush:latest -r \
     "\$r = new ReflectionClass('JmapClient'); var_dump(\$r->hasMethod('yourMethodName'));"
   ```

## Adding a New PHP-Facing Type

1. **Rust** (`lib.rs`): Define a struct with `#[php_class]` and `#[prop]` on each field:
   ```rust
   #[php_class]
   pub struct JmapNewType {
       #[prop] pub id: String,
       #[prop] pub name: String,
   }
   ```

2. Properties use exact Rust field names in PHP (snake_case).

3. Return instances from `JmapClient` methods — they automatically become PHP objects.

## JMAP Capabilities

The Rust client requests these JMAP capabilities during session establishment:

```
urn:ietf:params:jmap:core
urn:ietf:params:jmap:mail
urn:ietf:params:jmap:submission
urn:ietf:params:jmap:contacts
urn:ietf:params:jmap:calendars
```

## Testing

Tests run inside Docker against a live Stalwart server:

```bash
bash tests/run_tests.sh
```

The test file (`tests/test_jmap.php`) directly exercises every Rust FFI method — it does not go through Z-Push or ActiveSync. This isolates the Rust/JMAP layer for focused testing.

**24 tests total:** 14 email, 6 contact, 4 calendar. See `tests/test_jmap.php` for details.
