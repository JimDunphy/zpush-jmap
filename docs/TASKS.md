# ActiveSync task support

The backend exposes a task folder for each Stalwart calendar. Task folder IDs
are `task-<calendarId>`; existing appointment IDs remain `cal-<calendarId>`.
Exactly one task folder is advertised as the default Tasks folder. When the
server omits `isDefault`, the calendar named Calendar is preferred, followed by
the lowest calendar ID. Additional task folders use the calendar name plus Tasks.

Stalwart stores JSCalendar Events and Tasks through `CalendarEvent` methods.
The backend checks `@type` to keep these classes separate. Task writes use
`@type: Task`; they do not use Stalwart's unrelated administrative task API.

Ordinary tasks support reading, creation, partial updates, completion/reopening,
moving between task folders, and deletion. Fields include subject, notes,
start/due dates, priority, sensitivity, categories, and display reminders.
Tasks without dates and overdue/completed tasks remain in the inventory.
Local dates and their UTC equivalents are both sent to ActiveSync clients.
Completion responses include the required completion date, preferring
`progressUpdated`, then the native modification/creation time. Epoch denotes an
unknown completion date when none of those timestamps exists.
The image's Z-Push compatibility patch encodes this fallback as the valid
`1970-01-01T00:00:00.000Z` value. Unpatched Z-Push suppresses zero-valued dates,
which otherwise leaves the required `DateCompleted` element empty even though
`Complete` is 1. This is a placeholder for an unknown time, not the actual date
of completion; reads do not invent or write a completion time to Stalwart.

Stalwart 0.14.3 can reject `progressUpdated` on writes, including a `null` value.
New incomplete tasks omit that unnecessary field. For completion/reopening,
an explicit per-object `invalidProperties` rejection naming only that field
allows one retry without the optional timestamp. The same UID and all other
fields are retained. Reads then use the server's native modification/creation
time when it cannot store the supplied completion time. This fallback does not
preserve an explicitly backdated completion time on those servers.

Permission failures, other invalid fields, and transport/ambiguous failures
remain errors; an uncertain create is never automatically replayed. Compatibility
retry applies only to verified Task objects, not ordinary appointments.

Partial updates preserve native fields not represented by the device. Native
progress percentages are retained unless the device changes completion status.
ActiveSync Tasks has a binary `Complete` field, with no percentage field:
0–99% remains incomplete on the device, while native `progress: completed`
is sent as complete. ZWC can continue displaying and editing partial progress.
An ordinary phone edit that leaves completion unchanged preserves that progress;
completing/reopening on the phone sets it to 100%/0%, respectively.
See Microsoft's [Tasks elements](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-astask/0a61ddb0-cce1-481b-94cb-2b9a8271da73)
and [Complete definition](https://learn.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-astask/d889f302-aef2-4cc2-8b16-faa47d39fdbb).
When a task belongs to more than one calendar, deleting it from one list removes
only that membership; deleting its last membership destroys the native task.
Reads and writes check both task type and calendar membership.

Task content fingerprints include the returned native object, so changes to
notes, dates, completion, and reminders produce ActiveSync changes even when
the item ID stays the same. Tasks use an independent baseline for the shared
`CalendarEvent` change stream; appointment synchronization cannot consume a
task notification before its task folders are checked.

The image applies `backend/stalwart/zpush-compat.patch` to Z-Push's
device folder lookup. An empty client folder ID in an older saved hierarchy
must not shadow the actual task folder and swallow its push notification.
New empty mappings are rejected; existing empty entries are ignored during
lookup, preserving valid short IDs and legacy folder IDs. Recovery does not
reset device state or rewrite task data. The image build fails if the patch no
longer applies after an upstream Z-Push upgrade.

Version 0.2.4 also changes the sync fingerprint of completed tasks whose native
timestamps are absent or resolve to epoch. This sends their corrected completion
date once even if an older image already recorded the task as synchronized.
Unchanged tasks with a real completion timestamp and incomplete tasks retain
their fingerprints. A normal sync or push heartbeat recheck discovers the repair;
no task edits, account removal, or device-state reset is required.

## Initial release boundaries

Existing recurring/assigned tasks can be read as task items. Recurrence rules
and assignment details are not projected into ActiveSync. Creation or editing
of recurring/assigned tasks is explicitly rejected to avoid flattening their
native data. This includes completing a recurring series. Use another capable
client for those operations. Task exceptions, regeneration, per-occurrence
completion, subtasks, and delegated assignment workflows are not implemented.
Deletion of a task removes the native item/list membership, including a whole
recurring series; it is not a per-occurrence operation.

ActiveSync exposes one display reminder. The earliest unacknowledged native
display reminder is sent. Explicit reminder changes replace/remove display
alarms while preserving non-display alarms. The first release sends plain-text
notes; HTML is converted to text for display. A truncated device body is never
accepted as a replacement for complete notes.

On iPhone, Exchange task lists are consumed through Reminders. Local automated
checks do not establish every iOS version's task-list behavior. An existing
account should discover the additional folders through its normal FolderSync;
no account deletion or device-state reset is part of deployment.

## Validation

`nginx/manage.sh --verify-image` runs the real Z-Push task/WBXML regression suite
and a compiled PHP/Rust task fixture, along with the existing mail and push
regressions. These tests use isolated dummy data and do not modify a live mailbox.
The transport fixture covers timestamp-capable servers and the exact older-server
field rejection, including creating an incomplete task due the next day, completion,
reopening, UID preservation, permission failures, and response loss after a write.
The framework regression reproduces an empty legacy folder mapping, routes a
completion notification through the real device lookup and differential exporter,
and checks that valid short/shared folder mappings still work. Wire tests distinguish
partial progress from completion and include the completion date.
The real stream importer also checks completed tasks without native timestamps,
and the differential exporter verifies that the corrected response resends once.
