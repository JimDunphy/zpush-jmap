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

Partial updates preserve native fields not represented by the device. Native
progress percentages are retained unless the device changes completion status.
When a task belongs to more than one calendar, deleting it from one list removes
only that membership; deleting its last membership destroys the native task.
Reads and writes check both task type and calendar membership.

Task content fingerprints include the returned native object, so changes to
notes, dates, completion, and reminders produce ActiveSync changes even when
the item ID stays the same. Tasks use an independent baseline for the shared
`CalendarEvent` change stream; appointment synchronization cannot consume a
task notification before its task folders are checked.

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
