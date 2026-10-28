<?php
// Run in the application image with the project mounted at /workspace.
// Real Z-Push framework, fake JMAP state/events, no network or live mailbox.
chdir('/var/www/zpush');
ob_start();
class ZLog {
    public static function Write($level, $message, $truncate = true) {}
    public static function IsWbxmlDebugEnabled() { return false; }
}
require 'vendor/autoload.php';
require '/workspace/backend/stalwart/stalwart.php';
(new ReflectionProperty(Request::class, 'asProtocolVersion'))->setValue(null, '14.1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

class PushJmapFixture {
    public $states = ['Email' => '1', 'Contact' => '1', 'CalendarEvent' => '1'];
    public $unavailable = [];
    public $expired = [];
    public $stateReads = [];
    public $changeReads = [];
    public $events = [];
    public $waits = 0;
    public $supported = true;
    public $ids = ['mail-1'];
    public function __call($name, $arguments) {
        if (!preg_match('/^get(Email|Contact|CalendarEvent)(State|Changes)$/', $name, $match)) {
            throw new RuntimeException('Unexpected call: ' . $name);
        }
        list(, $type, $operation) = $match;
        if ($operation === 'State') $this->stateReads[] = $type;
        else $this->changeReads[] = $type;
        if (!empty($this->unavailable[$type])) throw new RuntimeException('Simulated type outage');
        if ($operation === 'State') return $this->states[$type];
        if (!empty($this->expired[$type])) {
            unset($this->expired[$type]);
            throw new RuntimeException('cannotCalculateChanges');
        }
        return (object) ['new_state' => $this->states[$type], 'has_changes' => $arguments[0] !== $this->states[$type]];
    }
    public function supportsPushNotifications() { return $this->supported; }
    public function waitForPushChange($timeout) {
        ++$this->waits;
        $event = array_shift($this->events);
        if ($event) return $event($this);
        usleep($timeout * 1000000);
        return false;
    }
    public function queryEmails($folder, $cutoff, $limit, $position) {
        return (object) ['ids' => $this->ids, 'can_load_more' => false];
    }
    public function getEmailMetadata($ids) {
        return array_map(fn($id) => (object) [
            'id' => $id, 'keywords' => [], 'received_at' => '2026-09-15T12:00:00Z'
        ], $ids);
    }
}
class PushBackendFixture extends BackendStalwart {
    public function __construct($client) {
        $this->_jmapClient = $client;
        $this->_connected = true;
        foreach (['a' => 'message', 'b' => 'message', 'ab-c' => 'contact', 'cal-d' => 'appointment'] as $id => $view) {
            $this->_idToIndex[$id] = count($this->_folders);
            $this->_folders[] = (object) ['id' => $id, 'devid' => $id, 'view' => $view];
        }
    }
}
function fixture($folders = ['a']) {
    $client = new PushJmapFixture();
    $backend = new PushBackendFixture($client);
    foreach ($folders as $id) check($backend->ChangesSinkInitialize($id), 'Folder initialization failed');
    return [$backend, $client];
}

$tests = [];
$tests['Z-Push can discover the notification sink'] = function () {
    list($backend) = fixture();
    check($backend->HasChangesSink() === true, 'Framework would bypass the sink');
};
$tests['Initialize captures a baseline once per monitored type'] = function () {
    list($backend, $client) = fixture(['a', 'b']);
    check($client->stateReads === ['Email'], 'Baseline missing, duplicated, or queried unmonitored types');
    $backend->ChangesSink(0);
    check($client->changeReads === ['Email'], 'Unmonitored contacts/calendars queried');
};
$tests['Change between initialization and first wait is caught immediately'] = function () {
    list($backend, $client) = fixture();
    $client->states['Email'] = '2';
    check($backend->ChangesSink(1) === ['a'], 'Initialization gap lost a change');
    check($client->waits === 0, 'Waited despite a pending change');
};
$tests['Push wakes all monitored mail folders, including a move to Trash'] = function () {
    list($backend, $client) = fixture(['a', 'b', 'ab-c']);
    $client->events[] = function ($client) { $client->states['Email'] = '2'; return true; };
    check($backend->ChangesSink(1) === ['a', 'b'], 'Push did not notify the right folders');
    check($client->waits === 1, 'Notification was not received through push');
    check($backend->ChangesSink(0) === [], 'Already consumed notification repeated');
};
$tests['Deletion push produces a real ActiveSync deletion and retains other mail'] = function () {
    list($backend, $client) = fixture();
    $client->ids = ['mail-1', 'mail-2'];
    $state = $backend->GetMessageList('a', 0);
    $client->events[] = function ($client) {
        $client->ids = ['mail-2'];
        $client->states['Email'] = '2';
        return true;
    };
    check($backend->ChangesSink(1) === ['a'], 'Deletion did not wake Inbox');
    $output = fopen('php://temp', 'w+');
    $encoder = new WBXMLEncoder($output);
    $encoder->startWBXML();
    $encoder->startTag(SYNC_SYNCHRONIZE);
    $importer = new ImportChangesStream($encoder, new SyncMail());
    (new ReflectionProperty($importer, 'checkForIgnoredMessages'))->setValue($importer, false);
    $cpo = new ContentParameters();
    $cpo->SetContentClass('Email');
    $exporter = $backend->GetExporter('a');
    $exporter->Config($state);
    $exporter->ConfigContentParameters($cpo);
    $exporter->InitializeExporter($importer);
    check($exporter->GetChangeCount() === 1, 'Expected exactly one deletion');
    $exporter->Synchronize();
    $encoder->endTag();
    rewind($output);
    $decoder = new WBXMLDecoder($output);
    check($decoder->getElementStartTag(SYNC_SYNCHRONIZE), 'Missing Sync response');
    check($decoder->getElementStartTag(SYNC_REMOVE), 'Missing deletion command');
    check($decoder->getElementStartTag(SYNC_SERVERENTRYID), 'Missing deletion ID');
    check($decoder->getElementContent() === 'mail-1', 'Wrong message deleted');
    check(array_column($exporter->GetState(), 'id') === ['mail-2'], 'Unrelated message removed');
};
$tests['Missed push or closed stream still checks for a deletion'] = function () {
    list($backend, $client) = fixture();
    $client->events[] = function ($client) { $client->states['Email'] = '2'; return false; };
    check($backend->ChangesSink(1) === ['a'], 'EOF/timeout lost the delta');
};
$tests['Deadline expiry still runs the final delta check'] = function () {
    list($backend, $client) = fixture();
    $client->events[] = function ($client) {
        usleep(30000);
        $client->states['Email'] = '2';
        return false;
    };
    check($backend->ChangesSink(0.02) === ['a'], 'Change at deadline was missed');
};
$tests['Disconnected push falls back and reconnects on the next wait'] = function () {
    list($backend, $client) = fixture();
    $client->events[] = function ($client) {
        $client->states['Email'] = '2';
        throw new RuntimeException('Simulated SSE disconnect');
    };
    check($backend->ChangesSink(1) === ['a'], 'Disconnect lost pending mail');
    $client->events[] = function ($client) { $client->states['Email'] = '3'; return true; };
    check($backend->ChangesSink(1) === ['a'] && $client->waits === 2, 'Push did not reconnect');
};
$tests['Unavailable calendar cannot discard a mail notification'] = function () {
    list($backend, $client) = fixture(['a', 'cal-d']);
    $client->states['Email'] = '2';
    $client->unavailable['CalendarEvent'] = true;
    check($backend->ChangesSink(0) === ['a'], 'Calendar failure swallowed mail change');
    $client->unavailable['CalendarEvent'] = false;
    check($backend->ChangesSink(0) === ['cal-d'], 'Recovered calendar was not rechecked');
};
$tests['Expired change token requests a diff without losing the change'] = function () {
    list($backend, $client) = fixture();
    $client->expired['Email'] = true;
    $client->states['Email'] = '10';
    check($backend->ChangesSink(0) === ['a'], 'Expired token silently reset the baseline');
    check($backend->ChangesSink(0) === [], 'Recovered token did not settle');
};
$tests['Unavailable initial baseline recovers with a folder recheck'] = function () {
    $client = new PushJmapFixture();
    $client->unavailable['Email'] = true;
    $backend = new PushBackendFixture($client);
    check($backend->ChangesSinkInitialize('a'), 'Temporary outage invalidated folder hierarchy');
    $client->unavailable['Email'] = false;
    $client->states['Email'] = '2';
    check($backend->ChangesSink(0) === ['a'], 'Recovery skipped changes during outage');
};
$tests['Polling fallback respects a short timeout'] = function () {
    list($backend, $client) = fixture();
    $client->supported = false;
    $start = microtime(true);
    check($backend->ChangesSink(0.03) === [], 'Idle mailbox reported changes');
    $elapsed = microtime(true) - $start;
    check($elapsed >= 0.025 && $elapsed < 0.5, 'Fallback did not respect the timeout');
    check($client->waits === 0, 'Unavailable push was used');
};
$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS: $name\n"; }
    catch (Throwable $e) { ++$failures; echo "FAIL: $name: {$e->getMessage()}\n"; }
}
exit($failures ? 1 : 0);
