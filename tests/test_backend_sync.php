<?php
// Run inside the application image, with the project mounted at /workspace:
// php /workspace/tests/test_backend_sync.php
// Uses the real Z-Push classes and a fake JMAP client; no live mailbox access.
chdir('/var/www/zpush');
ob_start();
class ZLog {
    public static $recent = [];
    public static function Write($level, $message, $truncate = true) {
        self::$recent[] = $message;
        if (count(self::$recent) > 12) array_shift(self::$recent);
    }
    public static function IsWbxmlDebugEnabled() { return false; }
}
require 'vendor/autoload.php';
define('SYNC_CONTACTS_MAXPICTURESIZE', 5242880);
require '/workspace/backend/stalwart/stalwart.php';
(new ReflectionProperty(Request::class, 'asProtocolVersion'))->setValue(null, '14.1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
});

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

class FakeSyncJmap {
    public $failQuery = false;
    public $mailboxCalls = 0;
    public $email;
    public $raw = "From: sender@example.test\r\nSubject: Test\r\n\r\nTest body\r\n";
    public function __construct() {
        $this->email = (object) array_fill_keys([
            'from', 'to', 'cc', 'bcc', 'reply_to', 'display_to', 'display_cc', 'display_bcc'
        ], '');
        foreach ([
            'id' => 'mail-1', 'blob_id' => 'blob-1', 'subject' => 'Test',
            'received_at' => '2026-09-15T12:00:00Z', 'keywords' => [],
            'importance' => 1, 'text_body' => 'Hello world', 'html_body' => '<p>Hello world</p>',
            'has_attachment' => false, 'attachment_count' => 0, 'in_reply_to' => null,
        ] as $key => $value) $this->email->$key = $value;
    }
    public function getEmail($id) { return $this->email; }
    public function getMailboxes() {
        ++$this->mailboxCalls;
        return [(object) [
            'id' => 'a', 'name' => 'Inbox', 'role' => 'inbox', 'parent_id' => null,
            'total_emails' => 1, 'unread_emails' => 1,
        ]];
    }
    public function getAddressBooks() { return []; }
    public function getCalendars() { return []; }
    public function getContact($id) {
        // ext-php-rs exposes virtual properties that PHP reflection omits.
        // Build the fixture from the Rust declaration to catch PHP/Rust drift.
        $source = file_get_contents('/workspace/rust-lib/src/lib.rs');
        preg_match('/pub struct JmapContact \{(.*?)\n\}/s', $source, $struct);
        preg_match_all('/pub (\w+):/', $struct[1], $fields);
        $contact = new stdClass();
        foreach ($fields[1] as $field) $contact->$field = null;
        $contact->name_title = 'Dr.';
        return $contact;
    }
    public function downloadBlobToFile($id, $file) { return file_put_contents($file, $this->raw); }
    public function queryEmails($folder, $cutoff, $limit, $position) {
        if ($this->failQuery) throw new RuntimeException('Simulated upstream outage');
        return (object) ['ids' => [$folder . '-mail'], 'can_load_more' => false];
    }
    public function getEmailMetadata($ids) {
        return array_map(fn($id) => (object) [
            'id' => $id, 'keywords' => [], 'received_at' => '2026-09-15T12:00:00Z'
        ], $ids);
    }
}

class SyncTestBackend extends BackendStalwart {
    public function __construct($client) {
        $this->_jmapClient = $client;
        foreach (['a' => 'inbox', 'b' => 'trash'] as $id => $role) {
            $this->_idToIndex[$id] = count($this->_folders);
            $this->_folders[] = (object) [
                'id' => $id, 'devid' => $id, 'view' => 'message', 'role' => $role,
                'name' => ucfirst($role), 'parentid' => '0'
            ];
        }
    }
}

class FreshSyncTestBackend extends BackendStalwart {
    public function __construct($client) {
        $this->_jmapClient = $client;
        $this->_connected = true;
        $this->_user = 'test@example.test';
    }
}

$tests = [];
$client = new FakeSyncJmap();
$backend = new SyncTestBackend($client);
$tests['Opening a message in a fresh request initializes its folder'] = function () {
    $client = new FakeSyncJmap();
    $cpo = new ContentParameters();
    $cpo->BodyPreference(2);
    for ($i = 0; $i < 3; ++$i) {
        $backend = new FreshSyncTestBackend($client);
        $mail = $backend->Fetch('a', 'mail-1', $cpo);
        check(stream_get_contents($mail->asbody->data) === $client->email->html_body, 'Fetch lost message body');
    }
    check($client->mailboxCalls === 3, 'Each fresh request must resolve the mailbox');
};
$tests['HTML body preference is a list of types'] = function () use ($backend, $client) {
    $cpo = new ContentParameters();
    $cpo->BodyPreference(2)->SetTruncationSize(1024);
    $mail = $backend->GetMessage('a', 'mail-1', $cpo);
    check($mail->asbody->type === 2, 'HTML request returned a different body type');
    check(stream_get_contents($mail->asbody->data) === $client->email->html_body, 'HTML content differs');
};
$tests['MIME body uses a stream'] = function () use ($backend, $client) {
    $cpo = new ContentParameters();
    $cpo->BodyPreference(4)->SetTruncationSize(1024);
    $mail = $backend->GetMessage('a', 'mail-1', $cpo);
    check($mail->asbody->type === 4, 'MIME request returned a different body type');
    check(is_resource($mail->asbody->data), 'MIME data must be a stream for WBXML encoding');
    check(stream_get_contents($mail->asbody->data) === $client->raw, 'MIME content differs');
};
$tests['Plain body honors truncation including zero'] = function () use ($backend) {
    foreach ([5, 0] as $size) {
        $cpo = new ContentParameters();
        $cpo->BodyPreference(1)->SetTruncationSize($size);
        $mail = $backend->GetMessage('a', 'mail-1', $cpo);
        check(strlen(stream_get_contents($mail->asbody->data)) === $size, 'Truncation size ignored');
        check($mail->asbody->estimatedDataSize === 11 && $mail->asbody->truncated === 1, 'Truncation metadata differs');
    }
};
$tests['Sync Fetch returns the complete body without changing sync preferences'] = function () use ($backend, $client) {
    $command = new ReflectionProperty(Request::class, 'command');
    $previous = $command->getValue();
    $command->setValue(null, 'Sync');
    try {
        $cpo = new ContentParameters();
        $cpo->BodyPreference(1)->SetTruncationSize(5);
        $mail = $backend->Fetch('a', 'mail-1', $cpo);
        check(stream_get_contents($mail->asbody->data) === $client->email->text_body, 'Full-message fetch returned another truncated preview');
        check($mail->asbody->truncated === 0, 'Fetched body is still truncated');
        check($cpo->BodyPreference(1)->GetTruncationSize() === 5, 'Fetch changed subsequent sync preferences');
    } finally {
        $command->setValue(null, $previous);
    }
};
$tests['ItemOperations Fetch honors its own requested size'] = function () use ($backend) {
    $command = new ReflectionProperty(Request::class, 'command');
    $previous = $command->getValue();
    $command->setValue(null, 'ItemOperations');
    try {
        $cpo = new ContentParameters();
        $cpo->BodyPreference(1)->SetTruncationSize(5);
        $mail = $backend->Fetch('a', 'mail-1', $cpo);
        check(stream_get_contents($mail->asbody->data) === 'Hello', 'ItemOperations size limit ignored');
    } finally {
        $command->setValue(null, $previous);
    }
};
$tests['ItemOperations request returns a decodable full HTML message'] = function () {
    $request = fopen('php://temp', 'w+');
    $encoder = new WBXMLEncoder($request);
    $encoder->startWBXML();
    $encoder->startTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS);
    $encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);
    foreach ([SYNC_ITEMOPERATIONS_STORE => 'Mailbox', SYNC_FOLDERID => 'a', SYNC_SERVERENTRYID => 'mail-1'] as $tag => $value) {
        $encoder->startTag($tag);
        $encoder->content($value);
        $encoder->endTag();
    }
    $encoder->startTag(SYNC_ITEMOPERATIONS_OPTIONS);
    $encoder->startTag(SYNC_AIRSYNCBASE_BODYPREFERENCE);
    $encoder->startTag(SYNC_AIRSYNCBASE_TYPE);
    $encoder->content('2');
    for ($i = 0; $i < 5; ++$i) $encoder->endTag();
    rewind($request);

    $client = new FakeSyncJmap();
    $response = fopen('php://temp', 'w+');
    $values = [
        'backend' => new FreshSyncTestBackend($client),
        'deviceManager' => new class {
            public function GetBackendIdForFolderId($id) { return $id; }
        },
        'topCollector' => new class {
            public function AnnounceInformation($message, $important = false) {}
        },
        'decoder' => new WBXMLDecoder($request),
        'encoder' => new WBXMLEncoder($response),
    ];
    $saved = [];
    $command = new ReflectionProperty(Request::class, 'command');
    $previous = $command->getValue();
    $command->setValue(null, 'ItemOperations');
    try {
        foreach ($values as $name => $value) {
            $property = new ReflectionProperty(RequestProcessor::class, $name);
            $saved[$name] = $property->getValue();
            $property->setValue(null, $value);
        }
        check((new ItemOperations())->Handle(ZPush::COMMAND_ITEMOPERATIONS), 'ItemOperations handler failed');
        rewind($response);
        $decoder = new WBXMLDecoder($response);
        $readValue = function ($tag, $expected) use ($decoder) {
            check((bool)$decoder->getElementStartTag($tag), 'Missing response tag ' . $tag);
            check($decoder->getElementContent() === $expected, 'Unexpected response value for ' . $tag);
            check($decoder->getElementEndTag(), 'Missing response end tag');
        };
        check((bool)$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS), 'Missing response root');
        $readValue(SYNC_ITEMOPERATIONS_STATUS, '1');
        check((bool)$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_RESPONSE), 'Missing response');
        check((bool)$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_FETCH), 'Missing fetch response');
        $readValue(SYNC_ITEMOPERATIONS_STATUS, '1');
        $readValue(SYNC_FOLDERID, 'a');
        $readValue(SYNC_SERVERENTRYID, 'mail-1');
        $readValue(SYNC_FOLDERTYPE, 'Email');
        check((bool)$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_PROPERTIES), 'Missing message properties');
        $mail = new SyncMail();
        // Streamer::Decode returns null on success and false on failure.
        check($mail->Decode($decoder) !== false, 'Unable to decode fetched message: ' . implode(' | ', ZLog::$recent));
        check($mail->asbody->type === '2' && $mail->asbody->truncated === '0', 'Fetch returned the wrong body metadata');
        check(stream_get_contents($mail->asbody->data) === $client->email->html_body, 'Fetched HTML was lost on the wire');
    } finally {
        foreach ($saved as $name => $value) (new ReflectionProperty(RequestProcessor::class, $name))->setValue(null, $value);
        $command->setValue(null, $previous);
        fclose($request);
        fclose($response);
    }
};
$tests['Inbox and Trash remain distinct'] = function () use ($backend) {
    check($backend->GetFolder('a')->type === SYNC_FOLDER_TYPE_INBOX, 'Inbox type differs');
    check($backend->GetFolder('b')->type === SYNC_FOLDER_TYPE_WASTEBASKET, 'Trash type differs');
    check($backend->GetMessageList('a', 0)[0]['id'] === 'a-mail', 'Inbox query crossed folders');
    check($backend->GetMessageList('b', 0)[0]['id'] === 'b-mail', 'Trash query crossed folders');
};
$tests['Contact mapping matches the Rust extension properties'] = function () use ($backend) {
    $method = new ReflectionMethod(BackendStalwart::class, 'GetContactMessage');
    $contact = $method->invoke($backend, 'contact-1', new ContentParameters());
    check($contact->title === 'Dr.', 'Contact title was lost');
};
$tests['Initial and subsequent sync preserve separate folder states'] = function () use ($backend) {
    $importer = new class {
        public $ids = [];
        public function ImportMessageChange($id, $mail) {
            $this->ids[] = $id;
            $output = fopen('php://temp', 'w+');
            $encoder = new WBXMLEncoder($output);
            $encoder->startWBXML();
            $encoder->startTag(SYNC_DATA);
            $mail->Encode($encoder);
            $encoder->endTag();
            check(ftell($output) > 20, 'Mail was not encoded');
            fclose($output);
            return true;
        }
    };
    $cpo = new ContentParameters();
    $cpo->SetContentClass('Email');
    $cpo->BodyPreference(4);
    foreach (['a', 'b'] as $folder) {
        $exporter = $backend->GetExporter($folder);
        $exporter->Config([]);
        $exporter->ConfigContentParameters($cpo);
        $exporter->InitializeExporter($importer);
        check($exporter->GetChangeCount() === 1, 'Initial sync missed a message');
        $exporter->Synchronize();
        $state = $exporter->GetState();
        check(count($state) === 1 && $state[0]['id'] === $folder . '-mail', 'Wrong folder state');
        $exporter = $backend->GetExporter($folder);
        $exporter->Config($state);
        $exporter->ConfigContentParameters($cpo);
        $exporter->InitializeExporter($importer);
        check($exporter->GetChangeCount() === 0, 'Subsequent sync did not settle');
    }
    check($importer->ids === ['a-mail', 'b-mail'], 'Folder messages were mixed');
};
$tests['An upstream outage cannot look like an empty mailbox'] = function () use ($backend, $client) {
    $client->failQuery = true;
    try {
        $list = $backend->GetMessageList('a', 0);
        check($list === false, 'Outage returned a successful mailbox listing');
    } catch (StatusException $e) {
        check($e->getCode() === SYNC_STATUS_SERVERERROR, 'Outage returned an unrelated status');
    } finally {
        $client->failQuery = false;
    }
};
$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS: $name\n"; }
    catch (Throwable $e) { ++$failures; echo "FAIL: $name: {$e->getMessage()}\n"; }
}
exit($failures ? 1 : 0);
