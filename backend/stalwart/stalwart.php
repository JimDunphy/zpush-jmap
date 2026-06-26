<?php
/***********************************************
* File          :   stalwart.php
* Project       :   Z-Push Stalwart JMAP Backend
* Description   :   ActiveSync backend for Stalwart mail server via JMAP.
*                   All JMAP protocol work is done in Rust (ext-php-rs FFI).
*                   This PHP layer maps Z-Push calls to Rust methods and
*                   constructs ActiveSync objects from the results.
*
* Copyright     :   Originally based on Z-Push Zimbra Backend by Vincent Sherwood et al.
*                   Rewritten for Stalwart JMAP via Rust FFI.
************************************************/

if (!class_exists("Request") && !class_exists("ZPushAutodiscover")) {
    ZLog::Write(LOGLEVEL_FATAL, "FATAL: Stalwart Backend only works with z-push 2.x");
    return false;
}

require_once('backend/stalwart/config.php');
include_once('lib/default/diffbackend/diffbackend.php');
include_once('lib/default/backend.php');
require_once('backend/stalwart/mime.php');


class BackendStalwart extends BackendDiff {

    private $mainUser;
    protected $_user;
    protected $_protocolversion;

    protected $_connected = false;
    public $_folders = array();
    protected $_idToIndex = array();
    protected $_password = "";
    protected $_wasteID = false;
    protected $_sentID = false;
    protected $_draftsID = false;

    // Rust JmapClient instance
    protected $_jmapClient = null;

    // Change tracking
    protected $_emailState = null;
    protected $_mailboxState = null;
    protected $_contactState = null;
    protected $_calendarEventState = null;

    // ChangesSink
    public $changesSink = false;
    public $changesSinkFolders = array();

    private const QUERY_PAGE_SIZE = 10000;
    private const QUERY_MAX_IDS = 50000;


    /**
     * Constructor
     */
    public function __construct() {
        $this->notifications = true;
        $this->changesSink = false;
        $this->changesSinkFolders = array();

        if (!function_exists('jmap_connect')) {
            ZLog::Write(LOGLEVEL_FATAL, 'Stalwart: Rust extension not loaded! jmap_connect() not found.');
        }
    }

    private function ParseBoolOption($value, $default) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ($value != 0);
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
                return false;
            }
        }
        return $default;
    }

    private function GetBackendVersion() {
        if (!defined('STALWART_BACKEND_VERSION')) {
            return 'unknown';
        }

        $version = trim(strval(STALWART_BACKEND_VERSION));
        if ($version === '') {
            return 'unknown';
        }

        return $version;
    }

    private function ExportRustBoolOption($name, $value, $default) {
        $boolValue = ($value !== null) ? $this->ParseBoolOption($value, $default) : $default;
        putenv($name . '=' . ($boolValue ? 'true' : 'false'));
    }

    private function ExportRustIntOption($name, $value, $default, $min, $max) {
        $intValue = $default;
        if ($value !== null) {
            $intValue = intval($value);
        }
        if ($intValue < $min) {
            $intValue = $min;
        } elseif ($intValue > $max) {
            $intValue = $max;
        }
        putenv($name . '=' . strval($intValue));
    }

    private function ExportRustStringOption($name, $value, $default) {
        $stringValue = $default;
        if ($value !== null) {
            $trimmed = trim(strval($value));
            if ($trimmed !== '') {
                $stringValue = $trimmed;
            }
        }
        putenv($name . '=' . $stringValue);
    }


    private function ApplyRustRuntimeConfig() {
        $this->ClearRustRuntimeConfig();
        $sslVerifyPeer = defined('STALWART_SSL_VERIFYPEER') ? STALWART_SSL_VERIFYPEER : null;
        $sslVerifyHost = defined('STALWART_SSL_VERIFYHOST') ? STALWART_SSL_VERIFYHOST : null;
        $allowInsecureHttp = defined('STALWART_ALLOW_INSECURE_HTTP') ? STALWART_ALLOW_INSECURE_HTTP : null;
        $connectTimeout = defined('STALWART_CONNECT_TIMEOUT_SECS') ? STALWART_CONNECT_TIMEOUT_SECS : null;
        $requestTimeout = defined('STALWART_REQUEST_TIMEOUT_SECS') ? STALWART_REQUEST_TIMEOUT_SECS : null;
        $blobTimeout = defined('STALWART_BLOB_TIMEOUT_SECS') ? STALWART_BLOB_TIMEOUT_SECS : null;
        $pushChangesEnabled = defined('STALWART_PUSH_CHANGES_ENABLED') ? STALWART_PUSH_CHANGES_ENABLED : null;
        $pushEventTypes = defined('STALWART_PUSH_EVENT_TYPES') ? STALWART_PUSH_EVENT_TYPES : null;
        $pushCloseAfter = defined('STALWART_PUSH_CLOSEAFTER') ? STALWART_PUSH_CLOSEAFTER : null;
        $pushPingSecs = defined('STALWART_PUSH_PING_SECS') ? STALWART_PUSH_PING_SECS : null;

        $this->ExportRustBoolOption('STALWART_SSL_VERIFYPEER', $sslVerifyPeer, true);
        $this->ExportRustBoolOption('STALWART_SSL_VERIFYHOST', $sslVerifyHost, true);
        $this->ExportRustBoolOption('STALWART_ALLOW_INSECURE_HTTP', $allowInsecureHttp, false);
        $this->ExportRustIntOption('STALWART_CONNECT_TIMEOUT_SECS', $connectTimeout, 10, 1, 300);
        $this->ExportRustIntOption('STALWART_REQUEST_TIMEOUT_SECS', $requestTimeout, 60, 1, 3600);
        $this->ExportRustIntOption('STALWART_BLOB_TIMEOUT_SECS', $blobTimeout, 180, 5, 7200);
        $this->ExportRustBoolOption('STALWART_PUSH_CHANGES_ENABLED', $pushChangesEnabled, true);
        $this->ExportRustStringOption('STALWART_PUSH_EVENT_TYPES', $pushEventTypes, 'Mailbox,Email,ContactCard,CalendarEvent');
        $this->ExportRustStringOption('STALWART_PUSH_CLOSEAFTER', $pushCloseAfter, 'state');
        $this->ExportRustIntOption('STALWART_PUSH_PING_SECS', $pushPingSecs, 30, 10, 300);
    }

    private function ClearRustRuntimeConfig() {
        $keys = array(
            'STALWART_URL',
            'STALWART_SSL_VERIFYPEER',
            'STALWART_SSL_VERIFYHOST',
            'STALWART_ALLOW_INSECURE_HTTP',
            'STALWART_CONNECT_TIMEOUT_SECS',
            'STALWART_REQUEST_TIMEOUT_SECS',
            'STALWART_BLOB_TIMEOUT_SECS',
            'STALWART_PUSH_CHANGES_ENABLED',
            'STALWART_PUSH_EVENT_TYPES',
            'STALWART_PUSH_CLOSEAFTER',
            'STALWART_PUSH_PING_SECS',
            'STALWART_ABQ_ENABLED',
            'STALWART_ABQ_ALLOWED_RULES',
            'STALWART_ABQ_BLOCKED_RULES',
            'STALWART_ABQ_QUARANTINED_RULES',
            'STALWART_ABQ_QUARANTINE_BY_DEFAULT',
        );

        foreach ($keys as $key) {
            putenv($key);
        }
    }


    private function SecureClearString(&$value, $context) {
        if (!is_string($value)) {
            $value = '';
            return;
        }

        if ($value !== '' && function_exists('sodium_memzero')) {
            try {
                sodium_memzero($value);
            } catch (Throwable $t) {
                ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->' . $context . '(): sodium_memzero failed: ' . $t->getMessage());
            }
        }

        $value = '';
    }

    private function SecureClearPassword() {
        $this->SecureClearString($this->_password, 'SecureClearPassword');
    }

    private function ParseRuleListOption($value) {
        if (is_array($value)) {
            $rules = array();
            foreach ($value as $entry) {
                if (is_string($entry) || is_numeric($entry)) {
                    $rule = trim(strval($entry));
                    if ($rule !== '') {
                        $rules[] = $rule;
                    }
                }
            }
            return $rules;
        }

        if ($value === null) {
            return array();
        }

        $raw = trim(strval($value));
        if ($raw === '') {
            return array();
        }

        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->ParseRuleListOption($decoded);
            }
        }

        $parts = preg_split('/[\r\n,]+/', $raw);
        if (!is_array($parts)) {
            return array();
        }

        $rules = array();
        foreach ($parts as $part) {
            $rule = trim($part);
            if ($rule !== '') {
                $rules[] = $rule;
            }
        }
        return $rules;
    }

    private function LogAbqRegexCacheStats() {
        if (!function_exists('abq_regex_cache_stats')) {
            return;
        }

        try {
            $stats = abq_regex_cache_stats();
            if (!is_object($stats)) {
                return;
            }

            $hits = property_exists($stats, 'hits') ? intval($stats->hits) : 0;
            $misses = property_exists($stats, 'misses') ? intval($stats->misses) : 0;
            $compileErrors = property_exists($stats, 'compile_errors') ? intval($stats->compile_errors) : 0;
            $evictions = property_exists($stats, 'evictions') ? intval($stats->evictions) : 0;
            $cachedEntries = property_exists($stats, 'cached_entries') ? intval($stats->cached_entries) : 0;

            ZLog::Write(
                LOGLEVEL_DEBUG,
                'Stalwart->EvaluateDevicePolicy(): regex_cache hits=' . $hits
                    . ' misses=' . $misses
                    . ' compile_errors=' . $compileErrors
                    . ' evictions=' . $evictions
                    . ' entries=' . $cachedEntries
            );
        } catch (Throwable $t) {
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->EvaluateDevicePolicy(): regex_cache stats unavailable: ' . $t->getMessage());
        }
    }

    private function ReadRequestString($methodName) {
        if (!class_exists('Request') || !method_exists('Request', $methodName)) {
            return '';
        }

        try {
            $value = call_user_func(array('Request', $methodName));
            if (is_string($value) || is_numeric($value)) {
                return trim(strval($value));
            }
        } catch (Throwable $t) {
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ReadRequestString(): failed for ' . $methodName . ': ' . $t->getMessage());
        }

        return '';
    }

    private function ReadDeviceUserAgent() {
        $userAgent = $this->ReadRequestString('GetUserAgent');
        if ($userAgent !== '') {
            return $userAgent;
        }

        if (class_exists('ZPush') && method_exists('ZPush', 'GetDeviceManager')) {
            try {
                $deviceManager = ZPush::GetDeviceManager();
                if (is_object($deviceManager) && method_exists($deviceManager, 'GetUserAgent')) {
                    $fallbackUserAgent = $deviceManager->GetUserAgent();
                    if (is_string($fallbackUserAgent) || is_numeric($fallbackUserAgent)) {
                        return trim(strval($fallbackUserAgent));
                    }
                }
            } catch (Throwable $t) {
                ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ReadDeviceUserAgent(): fallback failed: ' . $t->getMessage());
            }
        }

        return '';
    }

    private function EvaluateDevicePolicy($username) {
        $abqEnabled = defined('STALWART_ABQ_ENABLED')
            ? $this->ParseBoolOption(STALWART_ABQ_ENABLED, false)
            : false;
        if (!$abqEnabled) {
            return;
        }

        if (!function_exists('abq_evaluate_device')) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->EvaluateDevicePolicy(): ABQ enabled but Rust ABQ function missing');
            throw new AuthenticationRequiredException('Access denied by device policy.');
        }

        $deviceId = $this->ReadRequestString('GetDeviceID');
        $deviceType = $this->ReadRequestString('GetDeviceType');
        $userAgent = $this->ReadDeviceUserAgent();
        $remoteAddr = $this->ReadRequestString('GetRemoteAddr');

        $allowedRules = $this->ParseRuleListOption(defined('STALWART_ABQ_ALLOWED_RULES') ? STALWART_ABQ_ALLOWED_RULES : array());
        $blockedRules = $this->ParseRuleListOption(defined('STALWART_ABQ_BLOCKED_RULES') ? STALWART_ABQ_BLOCKED_RULES : array());
        $quarantinedRules = $this->ParseRuleListOption(defined('STALWART_ABQ_QUARANTINED_RULES') ? STALWART_ABQ_QUARANTINED_RULES : array());
        $quarantineByDefault = defined('STALWART_ABQ_QUARANTINE_BY_DEFAULT')
            ? $this->ParseBoolOption(STALWART_ABQ_QUARANTINE_BY_DEFAULT, false)
            : false;

        try {
            $decision = abq_evaluate_device(
                ($deviceId !== '') ? $deviceId : null,
                ($deviceType !== '') ? $deviceType : null,
                ($userAgent !== '') ? $userAgent : null,
                $allowedRules,
                $blockedRules,
                $quarantinedRules,
                $quarantineByDefault
            );
        } catch (Throwable $t) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->EvaluateDevicePolicy(): evaluation failure: ' . $t->getMessage());
            throw new AuthenticationRequiredException('Access denied by device policy.');
        }

        $action = 'allow';
        $matchedRule = '';
        $matchedField = '';
        if (is_object($decision)) {
            if (property_exists($decision, 'action') && is_string($decision->action) && trim($decision->action) !== '') {
                $action = strtolower(trim($decision->action));
            }
            if (property_exists($decision, 'matched_rule') && is_string($decision->matched_rule)) {
                $matchedRule = $decision->matched_rule;
            }
            if (property_exists($decision, 'matched_field') && is_string($decision->matched_field)) {
                $matchedField = $decision->matched_field;
            }
        }

        $contextParts = array(
            'user=' . $username,
            'ip=' . (($remoteAddr !== '') ? $remoteAddr : 'unknown'),
            'device_id=' . (($deviceId !== '') ? $deviceId : 'unknown'),
            'device_type=' . (($deviceType !== '') ? $deviceType : 'unknown'),
            'user_agent=' . (($userAgent !== '') ? $userAgent : 'unknown'),
            'action=' . $action,
        );
        if ($matchedRule !== '') {
            $contextParts[] = 'rule=' . $matchedRule;
        }
        if ($matchedField !== '') {
            $contextParts[] = 'field=' . $matchedField;
        }
        $context = implode(' ', $contextParts);
        $this->LogAbqRegexCacheStats();

        if ($action === 'allow') {
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->EvaluateDevicePolicy(): allowed ' . $context);
            return;
        }

        if ($action === 'quarantine') {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->EvaluateDevicePolicy(): quarantined (denied) ' . $context);
            throw new AuthenticationRequiredException('Access denied by device policy (quarantine).');
        }

        ZLog::Write(LOGLEVEL_WARN, 'Stalwart->EvaluateDevicePolicy(): blocked ' . $context);
        throw new AuthenticationRequiredException('Access denied by device policy.');
    }

    private function AddChangedFoldersByView($view, &$changedFolders) {
        foreach ($this->changesSinkFolders as $fid) {
            $index = $this->GetFolderIndex($fid);
            if ($index === false || !isset($this->_folders[$index])) {
                continue;
            }
            if ($this->_folders[$index]->view == $view) {
                $changedFolders[] = $fid;
            }
        }
    }

    private function CollectChangedFoldersOnce() {
        $hasChanges = false;
        $changedFolders = array();

        $emailChanges = $this->_jmapClient->getEmailChanges($this->_emailState);
        if ($emailChanges->has_changes) {
            $hasChanges = true;
            $this->AddChangedFoldersByView('message', $changedFolders);
        }
        $this->_emailState = $emailChanges->new_state;

        $contactChanges = $this->_jmapClient->getContactChanges($this->_contactState);
        if ($contactChanges->has_changes) {
            $hasChanges = true;
            $this->AddChangedFoldersByView('contact', $changedFolders);
        }
        $this->_contactState = $contactChanges->new_state;

        $calChanges = $this->_jmapClient->getCalendarEventChanges($this->_calendarEventState);
        if ($calChanges->has_changes) {
            $hasChanges = true;
            $this->AddChangedFoldersByView('appointment', $changedFolders);
        }
        $this->_calendarEventState = $calChanges->new_state;

        return array($hasChanges, array_values(array_unique($changedFolders)));
    }


    /**
     * Indicates which AS version is supported by the backend.
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_141;
    }


    /**
     * Authenticates the user and establishes a JMAP session.
     */
    public function Logon($username, $domain, $password) {
        if (class_exists("Request")) {
            $this->_protocolversion = Request::GetProtocolVersion();
        } else {
            $this->_protocolversion = "N/A";
        }

        // Autodiscover's login() passes $username straight from
        // SimpleXMLElement ($incomingXml->Request->EMailAddress) — PHP
        // auto-stringifies that in concat/sprintf contexts (which is
        // why ZLog renders it correctly), but the Rust FFI's typed
        // String parameter on jmap_connect rejects it with
        // "Invalid value given for argument `username`". Cast to
        // string here so the backend is robust to either caller shape.
        $username = (string) $username;
        $password = (string) $password;

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->Logon(): START { user: ' . $username . ', backend_version: ' . $this->GetBackendVersion() . ' }');

        $this->mainUser = $username;
        $this->_user = $username;
        $this->SecureClearPassword();
        $this->ClearRustRuntimeConfig();

        if (!defined('STALWART_URL')) {
            ZLog::Write(LOGLEVEL_FATAL, 'Stalwart->Logon(): STALWART_URL not defined');
            $this->SecureClearString($password, 'Logon');
            return false;
        }

        try {
            $this->EvaluateDevicePolicy($username);
            $this->ApplyRustRuntimeConfig();
            $this->_jmapClient = jmap_connect(STALWART_URL, $username, $password);
            $this->_connected = true;
            ZLog::Write(LOGLEVEL_INFO, 'Stalwart->Logon(): JMAP session established, account: ' .
                $this->_jmapClient->getAccountId() . ', backend_version: ' . $this->GetBackendVersion());
            return true;
        } catch (AuthenticationRequiredException $e) {
            throw $e;
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->Logon(): Auth failed: ' . $e->getMessage());
            throw new AuthenticationRequiredException("Access denied.");
        } finally {
            $this->SecureClearString($password, 'Logon');
        }
    }


    /**
     * Called before shutting down the request.
     */
    public function Logoff() {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->Logoff(): START');
        $this->_jmapClient = null;
        $this->_connected = false;
        $this->SecureClearPassword();
        $this->ClearRustRuntimeConfig();
        return true;
    }


    /**
     * Setup the backend for a specific store/user.
     * Fetches mailboxes from Stalwart and populates $this->_folders.
     */
    public function Setup($store, $checkACLonly = false, $folderid = false, $readonly = false) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->Setup(): START store=' . $store);

        if (!$this->_connected || !$this->_jmapClient) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->Setup(): Not connected');
            return false;
        }

        // Only populate folders once per request
        if (!empty($this->_folders)) {
            return true;
        }

        try {
            $mailboxes = $this->_jmapClient->getMailboxes();
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->Setup(): get_mailboxes failed: ' . $e->getMessage());
            return false;
        }

        $this->_folders = array();
        $this->_idToIndex = array();
        $this->_wasteID = false;
        $this->_sentID = false;
        $this->_draftsID = false;

        foreach ($mailboxes as $i => $mb) {
            $folder = new stdClass();
            $folder->id = $mb->id;
            $folder->devid = $mb->id;           // Device-facing ID
            $folder->name = $mb->name;
            $folder->parentid = ($mb->parent_id !== null) ? $mb->parent_id : '0';
            $folder->role = $mb->role;
            $folder->view = 'message';           // Mail only in this backend
            $folder->include = 1;
            $folder->virtual = 0;
            $folder->primary = 0;
            $folder->totalEmails = $mb->total_emails;
            $folder->unreadEmails = $mb->unread_emails;

            // Map JMAP roles to folder properties
            switch ($mb->role) {
                case 'inbox':
                    $folder->primary = 1;
                    break;
                case 'trash':
                    $this->_wasteID = $mb->id;
                    break;
                case 'sent':
                    $this->_sentID = $mb->id;
                    break;
                case 'drafts':
                    $this->_draftsID = $mb->id;
                    break;
                case 'junk':
                case 'archive':
                    break;
            }

            $this->_folders[$i] = $folder;
            $this->_idToIndex[$mb->id] = $i;
        }

        $nextIndex = count($this->_folders);

        // Fetch address books
        try {
            $addressBooks = $this->_jmapClient->getAddressBooks();
            foreach ($addressBooks as $ab) {
                $folder = new stdClass();
                $folder->id = 'ab-' . $ab->id;
                $folder->devid = 'ab-' . $ab->id;
                $folder->jmapId = $ab->id;
                $folder->name = $ab->name;
                $folder->parentid = '0';
                $folder->role = null;
                $folder->view = 'contact';
                $folder->include = 1;
                $folder->virtual = 0;
                $folder->primary = $ab->is_default ? 1 : 0;

                $this->_folders[$nextIndex] = $folder;
                $this->_idToIndex[$folder->devid] = $nextIndex;
                $nextIndex++;
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->Setup(): getAddressBooks failed: ' . $e->getMessage());
        }

        // Fetch calendars
        try {
            $calendars = $this->_jmapClient->getCalendars();
            foreach ($calendars as $cal) {
                $folder = new stdClass();
                $folder->id = 'cal-' . $cal->id;
                $folder->devid = 'cal-' . $cal->id;
                $folder->jmapId = $cal->id;
                $folder->name = $cal->name;
                $folder->parentid = '0';
                $folder->role = null;
                $folder->view = 'appointment';
                $folder->include = 1;
                $folder->virtual = 0;
                $folder->primary = $cal->is_default ? 1 : 0;

                $this->_folders[$nextIndex] = $folder;
                $this->_idToIndex[$folder->devid] = $nextIndex;
                $nextIndex++;
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->Setup(): getCalendars failed: ' . $e->getMessage());
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->Setup(): Loaded ' . count($this->_folders) . ' folders (mail+contacts+calendar)');
        return true;
    }


    /**
     * Returns an array of SyncFolder objects with all folders available.
     */
    public function GetFolderList() {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetFolderList(): START');
        $folders = array();

        foreach ($this->_folders as $folder) {
            if ($folder->include != 1 || $folder->virtual != 0) {
                continue;
            }
            $f = array();
            $f['id'] = $folder->devid;
            $f['parent'] = ($folder->parentid == '0') ? '0' : $folder->parentid;
            $f['mod'] = $folder->name;
            $folders[] = $f;
        }

        return $folders;
    }


    /**
     * Returns a SyncFolder object for the given folder ID.
     */
    public function GetFolder($devid) {
        $index = $this->GetFolderIndex($devid);

        $folder = new SyncFolder();
        $folder->serverid = $this->_folders[$index]->devid;
        $folder->parentid = ($this->_folders[$index]->parentid == '0') ? '0' : $this->_folders[$index]->parentid;
        $folder->displayname = $this->_folders[$index]->name;

        // Map folder view/role to ActiveSync folder type
        $view = $this->_folders[$index]->view;
        $role = $this->_folders[$index]->role;
        $name = strtolower($this->_folders[$index]->name);

        if ($view == 'contact') {
            $folder->type = ($this->_folders[$index]->primary == 1)
                ? SYNC_FOLDER_TYPE_CONTACT
                : SYNC_FOLDER_TYPE_USER_CONTACT;
        } elseif ($view == 'appointment') {
            $folder->type = ($this->_folders[$index]->primary == 1)
                ? SYNC_FOLDER_TYPE_APPOINTMENT
                : SYNC_FOLDER_TYPE_USER_APPOINTMENT;
        } else {
            // Mail folders - dispatch by role
            switch ($role) {
                case 'inbox':
                    $folder->type = SYNC_FOLDER_TYPE_INBOX;
                    $folder->displayname = 'Inbox';
                    break;
                case 'trash':
                    $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
                    $folder->displayname = 'Trash';
                    break;
                case 'sent':
                    $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
                    $folder->displayname = 'Sent';
                    break;
                case 'drafts':
                    $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
                    $folder->displayname = 'Drafts';
                    break;
                default:
                    if ($name == 'outbox' && $this->_folders[$index]->parentid == '0') {
                        $folder->type = SYNC_FOLDER_TYPE_OUTBOX;
                        $folder->displayname = 'Outbox';
                    } else {
                        $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
                    }
                    break;
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetFolder(): id=' . $devid . ' type=' . $folder->type . ' name=' . $folder->displayname);
        return $folder;
    }


    /**
     * Returns folder stats (id, mod, parent).
     */
    public function StatFolder($devid) {
        $index = $this->GetFolderIndex($devid);

        $stat = array();
        $stat['id'] = $this->_folders[$index]->devid;
        $stat['mod'] = $this->_folders[$index]->name;
        $stat['parent'] = ($this->_folders[$index]->parentid == '0') ? '0' : $this->_folders[$index]->parentid;

        return $stat;
    }


    /**
     * Returns the internal folder index for a given device ID.
     */
    public function GetFolderIndex($devid) {
        if (isset($this->_idToIndex[$devid])) {
            return $this->_idToIndex[$devid];
        }
        // Linear search fallback
        for ($i = 0; $i < count($this->_folders); $i++) {
            if ($this->_folders[$i]->devid == $devid) {
                return $i;
            }
        }
        ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetFolderIndex(): Folder not found: ' . $devid);
        throw new StatusException("Folder not found: $devid", SYNC_FSSTATUS_CODEUNKNOWN);
    }


    /**
     * Returns the waste basket folder ID.
     */
    public function GetWasteBasket() {
        return $this->_wasteID;
    }


    /**
     * Returns a list of messages in a folder.
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetMessageList(): folder=' . $folderid . ' cutoff=' . $cutoffdate);

        if (!$this->_jmapClient) {
            return array();
        }

        $index = $this->GetFolderIndex($folderid);
        $view = $this->_folders[$index]->view;

        try {
            switch ($view) {
                case 'contact':
                    return $this->GetContactMessageList($folderid, $index);
                case 'appointment':
                    return $this->GetAppointmentMessageList($folderid, $index, $cutoffdate);
                default:
                    return $this->GetEmailMessageList($folderid, $cutoffdate);
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetMessageList(): Error: ' . $e->getMessage());
            return array();
        }
    }

    private function QueryEmailIds($folderid, $cutoff_str) {
        $position = 0;
        $ids = array();

        while (count($ids) < self::QUERY_MAX_IDS) {
            $remaining = self::QUERY_MAX_IDS - count($ids);
            $limit = min(self::QUERY_PAGE_SIZE, $remaining);

            $result = $this->_jmapClient->queryEmails($folderid, $cutoff_str, $limit, $position);
            if (!is_object($result) || !isset($result->ids) || !is_array($result->ids) || empty($result->ids)) {
                break;
            }

            $batchCount = count($result->ids);
            foreach ($result->ids as $id) {
                $ids[] = $id;
            }
            $position += $batchCount;

            $canLoadMore = property_exists($result, 'can_load_more') ? (bool)$result->can_load_more : false;
            if (!$canLoadMore || $batchCount < $limit) {
                break;
            }
        }

        if (count($ids) >= self::QUERY_MAX_IDS) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->QueryEmailIds(): reached query cap of ' . self::QUERY_MAX_IDS . ' IDs');
        }

        return $ids;
    }

    private function QueryContactIds($jmapId) {
        $position = 0;
        $ids = array();

        while (count($ids) < self::QUERY_MAX_IDS) {
            $remaining = self::QUERY_MAX_IDS - count($ids);
            $limit = min(self::QUERY_PAGE_SIZE, $remaining);

            $result = $this->_jmapClient->queryContacts($jmapId, $limit, $position);
            if (!is_object($result) || !isset($result->ids) || !is_array($result->ids) || empty($result->ids)) {
                break;
            }

            $batchCount = count($result->ids);
            foreach ($result->ids as $id) {
                $ids[] = $id;
            }
            $position += $batchCount;

            $total = property_exists($result, 'total') ? intval($result->total) : 0;
            if (($total > 0 && $position >= $total) || $batchCount < $limit) {
                break;
            }
        }

        if (count($ids) >= self::QUERY_MAX_IDS) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->QueryContactIds(): reached query cap of ' . self::QUERY_MAX_IDS . ' IDs');
        }

        return $ids;
    }

    private function QueryCalendarEventIds($jmapId, $after) {
        $position = 0;
        $ids = array();

        while (count($ids) < self::QUERY_MAX_IDS) {
            $remaining = self::QUERY_MAX_IDS - count($ids);
            $limit = min(self::QUERY_PAGE_SIZE, $remaining);

            $result = $this->_jmapClient->queryCalendarEvents($jmapId, $after, null, $limit, $position);
            if (!is_object($result) || !isset($result->ids) || !is_array($result->ids) || empty($result->ids)) {
                break;
            }

            $batchCount = count($result->ids);
            foreach ($result->ids as $id) {
                $ids[] = $id;
            }
            $position += $batchCount;

            $total = property_exists($result, 'total') ? intval($result->total) : 0;
            if (($total > 0 && $position >= $total) || $batchCount < $limit) {
                break;
            }
        }

        if (count($ids) >= self::QUERY_MAX_IDS) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->QueryCalendarEventIds(): reached query cap of ' . self::QUERY_MAX_IDS . ' IDs');
        }

        return $ids;
    }

    private function GetEmailMessageList($folderid, $cutoffdate) {
        $cutoff_str = null;
        if ($cutoffdate) {
            $cutoff_str = gmdate('Y-m-d\TH:i:s\Z', $cutoffdate);
        }

        $ids = $this->QueryEmailIds($folderid, $cutoff_str);

        if (empty($ids)) {
            return array();
        }

        $messages = array();
        $batchSize = 500;
        for ($offset = 0; $offset < count($ids); $offset += $batchSize) {
            $batch = array_slice($ids, $offset, $batchSize);
            $metas = $this->_jmapClient->getEmailMetadata($batch);

            foreach ($metas as $meta) {
                $msg = array();
                $msg['id'] = $meta->id;
                $msg['flags'] = in_array('$seen', $meta->keywords) ? 1 : 0;
                $msg['star'] = in_array('$flagged', $meta->keywords) ? 1 : 0;
                $msg['mod'] = $meta->received_at;
                $messages[] = $msg;
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetEmailMessageList(): Found ' . count($messages) . ' messages');
        return $messages;
    }

    private function GetContactMessageList($folderid, $index) {
        $jmapId = $this->_folders[$index]->jmapId;
        $ids = $this->QueryContactIds($jmapId);

        $messages = array();
        foreach ($ids as $id) {
            $msg = array();
            $msg['id'] = $id;
            $msg['flags'] = 1; // Contacts are always "read"
            $msg['mod'] = $id; // Use ID as mod key (changes trigger re-sync via state)
            $messages[] = $msg;
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetContactMessageList(): Found ' . count($messages) . ' contacts');
        return $messages;
    }

    private function GetAppointmentMessageList($folderid, $index, $cutoffdate) {
        $jmapId = $this->_folders[$index]->jmapId;

        $after = null;
        if ($cutoffdate) {
            $after = gmdate('Y-m-d\TH:i:s\Z', $cutoffdate);
        }

        $ids = $this->QueryCalendarEventIds($jmapId, $after);

        if (empty($ids)) {
            return array();
        }

        $messages = array();
        $batchSize = 500;
        for ($offset = 0; $offset < count($ids); $offset += $batchSize) {
            $batch = array_slice($ids, $offset, $batchSize);
            $metas = $this->_jmapClient->getCalendarEventMetadata($batch);

            foreach ($metas as $meta) {
                $msg = array();
                $msg['id'] = $meta->id;
                $msg['flags'] = 1;
                $msg['mod'] = ($meta->utc_start !== null) ? $meta->utc_start : $meta->id;
                $messages[] = $msg;
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetAppointmentMessageList(): Found ' . count($messages) . ' events');
        return $messages;
    }



    /**
     * Returns metadata for a specific message.
     */
    public function StatMessage($folderid, $id) {
        try {
            $index = $this->GetFolderIndex($folderid);
            $view = $this->_folders[$index]->view;

            switch ($view) {
                case 'contact':
                    return $this->StatContactMessage($id);
                case 'appointment':
                    return $this->StatAppointmentMessage($id);
                default:
                    return $this->StatEmailMessage($id);
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->StatMessage(): Error: ' . $e->getMessage());
            return false;
        }
    }

    private function StatEmailMessage($id) {
        $metas = $this->_jmapClient->getEmailMetadata(array($id));
        if (empty($metas)) {
            return false;
        }

        $meta = $metas[0];
        $stat = array();
        $stat['id'] = $meta->id;
        $stat['flags'] = in_array('$seen', $meta->keywords) ? 1 : 0;
        $stat['star'] = in_array('$flagged', $meta->keywords) ? 1 : 0;
        $stat['mod'] = $meta->received_at;
        return $stat;
    }

    private function StatContactMessage($id) {
        $metas = $this->_jmapClient->getContactMetadata(array($id));
        if (empty($metas)) {
            return false;
        }

        $meta = $metas[0];
        $stat = array();
        $stat['id'] = $meta->id;
        $stat['flags'] = 1;    // Contacts are always "read"
        $stat['mod'] = $meta->id;
        return $stat;
    }

    private function StatAppointmentMessage($id) {
        $metas = $this->_jmapClient->getCalendarEventMetadata(array($id));
        if (empty($metas)) {
            return false;
        }

        $meta = $metas[0];
        $stat = array();
        $stat['id'] = $meta->id;
        $stat['flags'] = 1;
        $stat['mod'] = ($meta->utc_start !== null) ? $meta->utc_start : $meta->id;
        return $stat;
    }


    /**
     * Returns a SyncMail, SyncContact, or SyncAppointment for the given message.
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetMessage(): folder=' . $folderid . ' id=' . $id);

        try {
            $index = $this->GetFolderIndex($folderid);
            $view = $this->_folders[$index]->view;

            switch ($view) {
                case 'contact':
                    return $this->GetContactMessage($id, $contentparameters);
                case 'appointment':
                    return $this->GetAppointmentMessage($id, $contentparameters);
                default:
                    return $this->GetEmailMessage($folderid, $id, $contentparameters);
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetMessage(): Error: ' . $e->getMessage());
            return false;
        }
    }

    private function GetEmailMessage($folderid, $id, $contentparameters) {
        $email = $this->_jmapClient->getEmail($id);

        $output = new SyncMail();

        // Basic fields
        $output->subject = $email->subject;
        $output->datereceived = $this->IsoToTimestamp($email->received_at);
        $output->messageclass = "IPM.Note";
        $output->contentclass = "urn:content-classes:message";

        // Addresses
        if ($email->from != '') $output->from = $email->from;
        if ($email->to != '') $output->to = $email->to;
        if ($email->cc != '') $output->cc = $email->cc;
        if ($email->bcc != '') $output->bcc = $email->bcc;
        if ($email->reply_to != '') $output->reply_to = $email->reply_to;
        if ($email->display_to != '') $output->displayto = $email->display_to;
        if ($email->display_cc != '') $output->displaycc = $email->display_cc;
        if ($email->display_bcc != '') $output->displaybcc = $email->display_bcc;

        // Flags
        $keywords = $email->keywords;
        $output->read = in_array('$seen', $keywords) ? 1 : 0;
        $output->flag = new SyncMailFlags();
        $output->flag->flagstatus = in_array('$flagged', $keywords) ? 2 : 0;

        if (in_array('$answered', $keywords)) {
            $output->lastverbexecuted = AS_REPLYTOSENDER;
        } elseif (in_array('$forwarded', $keywords)) {
            $output->lastverbexecuted = AS_FORWARD;
        }

        // Importance
        $output->importance = $email->importance;

        // Native body type
        $output->nativebodytype = ($email->html_body !== null) ? 2 : 1;

        // Body content parameters
        $bodyPrefArray = $contentparameters->GetBodyPreference();
        if (!is_array($bodyPrefArray)) {
            $bodyPrefArray = array();
        }

        $plain = $email->text_body;
        $html = $email->html_body;

        // Protocol version < 12
        if (Request::GetProtocolVersion() < 12.0) {
            if ($plain !== null) {
                $output->bodysize = strlen($plain);
                $output->body = $plain;
                $output->bodytruncated = 0;
            }
        } else {
            // AS 12+ - use SyncBaseBody
            $output->asbody = new SyncBaseBody();

            if (isset($bodyPrefArray[4])) {
                // MIME body requested - download raw message
                $output->asbody->type = 4;
                $tmpFile = tempnam(sys_get_temp_dir(), 'zpush_mime_');
                try {
                    $size = $this->_jmapClient->downloadBlobToFile($email->blob_id, $tmpFile);
                    $output->asbody->data = file_get_contents($tmpFile);
                    $output->asbody->estimatedDataSize = $size;
                } catch (Exception $e) {
                    ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetEmailMessage(): MIME download error: ' . $e->getMessage());
                    $output->asbody->data = '';
                    $output->asbody->estimatedDataSize = 0;
                }
                if (file_exists($tmpFile)) unlink($tmpFile);
                $output->asbody->truncated = 0;
            } elseif (isset($bodyPrefArray[2]) && $html !== null) {
                // HTML body
                $output->asbody->type = 2;
                $output->asbody->estimatedDataSize = strlen($html);

                $truncSize = isset($bodyPrefArray[2]) ? $bodyPrefArray[2]->TruncationSize : false;
                if ($truncSize && strlen($html) > $truncSize) {
                    $html = Utils::Utf8_truncate($html, $truncSize);
                    $output->asbody->truncated = 1;
                } else {
                    $output->asbody->truncated = 0;
                }

                $output->asbody->data = StringStreamWrapper::Open($html);
            } else {
                // Plain text body
                $output->asbody->type = 1;
                $text = ($plain !== null) ? $plain : '';
                $output->asbody->estimatedDataSize = strlen($text);

                $truncSize = isset($bodyPrefArray[1]) ? $bodyPrefArray[1]->TruncationSize : false;
                if ($truncSize && strlen($text) > $truncSize) {
                    $text = Utils::Utf8_truncate($text, $truncSize);
                    $output->asbody->truncated = 1;
                } else {
                    $output->asbody->truncated = 0;
                }

                $output->asbody->data = StringStreamWrapper::Open($text);
            }

            // Preview (first 255 chars of plain text)
            if ($plain !== null && strlen($plain) > 0) {
                $output->asbody->preview = substr($plain, 0, 255);
            }
        }

        // Attachments
        if ($email->has_attachment && $email->attachment_count > 0) {
            $attachments_data = json_decode($email->attachments_json, true);
            if (is_array($attachments_data) && !empty($attachments_data)) {
                if (Request::GetProtocolVersion() >= 12.0) {
                    $output->asattachments = array();
                    foreach ($attachments_data as $att) {
                        $attachment = new SyncBaseAttachment();
                        $attachment->displayname = isset($att['name']) ? $att['name'] : 'attachment';
                        $attachment->filereference = bin2hex($folderid . ':' . $id . ':' . $att['blob_id']);
                        $attachment->method = 1;
                        $attachment->estimatedDataSize = isset($att['size']) ? $att['size'] : 0;
                        $attachment->contentid = isset($att['content_id']) ? $att['content_id'] : null;
                        $attachment->isinline = isset($att['is_inline']) ? $att['is_inline'] : false;
                        $output->asattachments[] = $attachment;
                    }
                } else {
                    $output->attachments = array();
                    foreach ($attachments_data as $att) {
                        $attachment = new SyncAttachment();
                        $attachment->attname = bin2hex($folderid . ':' . $id . ':' . $att['blob_id']);
                        $attachment->displayname = isset($att['name']) ? $att['name'] : 'attachment';
                        $attachment->attmethod = 1;
                        $attachment->attsize = isset($att['size']) ? $att['size'] : 0;
                        $output->attachments[] = $attachment;
                    }
                }
            }
        }

        // Threading headers
        if ($email->in_reply_to !== null) {
            $output->internetcpid = "65001"; // UTF-8
        }

        return $output;
    }

    private function GetContactMessage($id, $contentparameters) {
        $contact = $this->_jmapClient->getContact($id);

        $output = new SyncContact();

        // Name fields
        if ($contact->first_name != '') $output->firstname = $contact->first_name;
        if ($contact->last_name != '') $output->lastname = $contact->last_name;
        if ($contact->middle_name != '') $output->middlename = $contact->middle_name;
        if ($contact->suffix != '') $output->suffix = $contact->suffix;
        if ($contact->title != '') $output->title = $contact->title;
        if ($contact->nickname != '') $output->nickname = $contact->nickname;

        // File-as (display name)
        $fileas = '';
        if ($contact->last_name != '' && $contact->first_name != '') {
            $fileas = $contact->last_name . ', ' . $contact->first_name;
        } elseif ($contact->last_name != '') {
            $fileas = $contact->last_name;
        } elseif ($contact->first_name != '') {
            $fileas = $contact->first_name;
        }
        if ($fileas != '') $output->fileas = $fileas;

        // Business info
        if ($contact->company != '') $output->companyname = $contact->company;
        if ($contact->department != '') $output->department = $contact->department;
        if ($contact->job_title != '') $output->jobtitle = $contact->job_title;
        if ($contact->assistant_name != '') $output->assistantname = $contact->assistant_name;
        if ($contact->spouse != '') $output->spouse = $contact->spouse;

        // Email
        if ($contact->email1 != '') $output->email1address = $contact->email1;
        if ($contact->email2 != '') $output->email2address = $contact->email2;
        if ($contact->email3 != '') $output->email3address = $contact->email3;

        // Phone numbers
        if ($contact->work_phone != '') $output->businessphonenumber = $contact->work_phone;
        if ($contact->work_phone2 != '') $output->business2phonenumber = $contact->work_phone2;
        if ($contact->home_phone != '') $output->homephonenumber = $contact->home_phone;
        if ($contact->home_phone2 != '') $output->home2phonenumber = $contact->home_phone2;
        if ($contact->mobile_phone != '') $output->mobilephonenumber = $contact->mobile_phone;
        if ($contact->car_phone != '') $output->carphonenumber = $contact->car_phone;
        if ($contact->pager != '') $output->pagernumber = $contact->pager;
        if ($contact->work_fax != '') $output->businessfaxnumber = $contact->work_fax;
        if ($contact->home_fax != '') $output->homefaxnumber = $contact->home_fax;

        // Home address
        if ($contact->home_street != '') $output->homestreet = $contact->home_street;
        if ($contact->home_city != '') $output->homecity = $contact->home_city;
        if ($contact->home_state != '') $output->homestate = $contact->home_state;
        if ($contact->home_postal_code != '') $output->homepostalcode = $contact->home_postal_code;
        if ($contact->home_country != '') $output->homecountry = $contact->home_country;

        // Work address
        if ($contact->work_street != '') $output->businessstreet = $contact->work_street;
        if ($contact->work_city != '') $output->businesscity = $contact->work_city;
        if ($contact->work_state != '') $output->businessstate = $contact->work_state;
        if ($contact->work_postal_code != '') $output->businesspostalcode = $contact->work_postal_code;
        if ($contact->work_country != '') $output->businesscountry = $contact->work_country;

        // Other address
        if ($contact->other_street != '') $output->otherstreet = $contact->other_street;
        if ($contact->other_city != '') $output->othercity = $contact->other_city;
        if ($contact->other_state != '') $output->otherstate = $contact->other_state;
        if ($contact->other_postal_code != '') $output->otherpostalcode = $contact->other_postal_code;
        if ($contact->other_country != '') $output->othercountry = $contact->other_country;

        // IM addresses
        if ($contact->im_address != '') $output->imaddress = $contact->im_address;
        if ($contact->im_address2 != '') $output->imaddress2 = $contact->im_address2;
        if ($contact->im_address3 != '') $output->imaddress3 = $contact->im_address3;

        // Webpage
        if ($contact->webpage != '') $output->webpage = $contact->webpage;

        // Children (CSV string → array)
        if ($contact->children_csv != '') {
            $output->children = array_map('trim', explode(',', $contact->children_csv));
        }

        // Dates
        if ($contact->birthday != '') $output->birthday = $this->IsoToTimestamp($contact->birthday);
        if ($contact->anniversary != '') $output->anniversary = $this->IsoToTimestamp($contact->anniversary);

        // Notes as body
        if ($contact->notes != '') {
            if (Request::GetProtocolVersion() >= 12.0) {
                $output->asbody = new SyncBaseBody();
                $output->asbody->type = 1;
                $output->asbody->data = StringStreamWrapper::Open($contact->notes);
                $output->asbody->estimatedDataSize = strlen($contact->notes);
                $output->asbody->truncated = 0;
            } else {
                $output->body = $contact->notes;
                $output->bodysize = strlen($contact->notes);
                $output->bodytruncated = 0;
            }
        }

        // Contact photo
        if ($contact->has_photo && $contact->photo_blob_id != '') {
            try {
                $tmpFile = tempnam(sys_get_temp_dir(), 'zpush_photo_');
                $this->_jmapClient->downloadBlobToFile($contact->photo_blob_id, $tmpFile);
                $photoData = file_get_contents($tmpFile);
                if (file_exists($tmpFile)) unlink($tmpFile);
                $output->picture = base64_encode($photoData);
            } catch (Exception $e) {
                ZLog::Write(LOGLEVEL_WARN, 'Stalwart->GetContactMessage(): Photo download error: ' . $e->getMessage());
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetContactMessage(): Built contact ' . $id);
        return $output;
    }

    private function GetAppointmentMessage($id, $contentparameters) {
        $event = $this->_jmapClient->getCalendarEvent($id);

        $output = new SyncAppointment();

        // Basic fields
        if ($event->title != '') $output->subject = $event->title;
        if ($event->location != '') $output->location = $event->location;
        if ($event->uid != '') $output->uid = $event->uid;

        // Times (UTC)
        $output->starttime = $this->IsoToTimestamp($event->utc_start);
        $output->endtime = $this->IsoToTimestamp($event->utc_end);
        $output->dtstamp = time();

        // All-day event
        $output->alldayevent = $event->all_day ? 1 : 0;

        // Status/sensitivity
        $output->busystatus = $event->busy_status;
        $output->sensitivity = $event->sensitivity;

        // Meeting status: 1 = organizer, 3 = attendee, 0 = non-meeting
        $output->meetingstatus = 0;

        // Organizer
        if ($event->organizer_name != '') $output->organizername = $event->organizer_name;
        if ($event->organizer_email != '') {
            $output->organizeremail = $event->organizer_email;
            $output->meetingstatus = 1;
        }

        // Reminder
        if ($event->reminder_minutes !== null && $event->reminder_minutes >= 0) {
            $output->reminder = $event->reminder_minutes;
        }

        // Timezone blob
        if ($event->time_zone != '') {
            $output->timezone = $this->GetActiveSync_Timezone($event->time_zone);
        }

        // Description as body
        if ($event->description != '') {
            if (Request::GetProtocolVersion() >= 12.0) {
                $output->asbody = new SyncBaseBody();
                $output->asbody->type = 1;
                $output->asbody->data = StringStreamWrapper::Open($event->description);
                $output->asbody->estimatedDataSize = strlen($event->description);
                $output->asbody->truncated = 0;
            } else {
                $output->body = $event->description;
                $output->bodytruncated = 0;
            }
        }

        // Attendees
        if ($event->attendees_json != '') {
            $attendees_data = json_decode($event->attendees_json, true);
            if (is_array($attendees_data) && !empty($attendees_data)) {
                $output->attendees = array();
                foreach ($attendees_data as $att) {
                    $attendee = new SyncAttendee();
                    if (isset($att['name'])) $attendee->name = $att['name'];
                    if (isset($att['email'])) $attendee->email = $att['email'];
                    // AS attendee status: 0=response unknown, 2=tentative, 3=accept, 4=decline, 5=not responded
                    $status = isset($att['status']) ? $att['status'] : 'needs-action';
                    switch ($status) {
                        case 'accepted': $attendee->attendeestatus = 3; break;
                        case 'declined': $attendee->attendeestatus = 4; break;
                        case 'tentative': $attendee->attendeestatus = 2; break;
                        default: $attendee->attendeestatus = 5; break;
                    }
                    // AS attendee type: 1=required, 2=optional, 3=resource
                    $role = isset($att['role']) ? $att['role'] : 'attendee';
                    switch ($role) {
                        case 'optional': $attendee->attendeetype = 2; break;
                        case 'resource': $attendee->attendeetype = 3; break;
                        default: $attendee->attendeetype = 1; break;
                    }
                    $output->attendees[] = $attendee;
                }
                if (!empty($output->attendees)) {
                    $output->meetingstatus = 3;
                }
            }
        }

        // Recurrence
        if ($event->recurrence_json != '') {
            $rec_data = json_decode($event->recurrence_json, true);
            if (is_array($rec_data) && !empty($rec_data)) {
                $output->recurrence = $this->BuildSyncRecurrence($rec_data);
            }
        }

        // Exceptions (overrides/exclusions)
        if ($event->exceptions_json != '') {
            $exc_data = json_decode($event->exceptions_json, true);
            if (is_array($exc_data) && !empty($exc_data)) {
                $output->exceptions = array();
                foreach ($exc_data as $exc) {
                    $exception = new SyncAppointmentException();
                    if (isset($exc['original_start'])) {
                        $exception->exceptionstarttime = $this->IsoToTimestamp($exc['original_start']);
                    }
                    if (isset($exc['deleted']) && $exc['deleted']) {
                        $exception->deleted = 1;
                    } else {
                        $exception->deleted = 0;
                        if (isset($exc['title'])) $exception->subject = $exc['title'];
                        if (isset($exc['location'])) $exception->location = $exc['location'];
                        if (isset($exc['utc_start'])) $exception->starttime = $this->IsoToTimestamp($exc['utc_start']);
                        if (isset($exc['utc_end'])) $exception->endtime = $this->IsoToTimestamp($exc['utc_end']);
                        if (isset($exc['busy_status'])) $exception->busystatus = $exc['busy_status'];
                        if (isset($exc['sensitivity'])) $exception->sensitivity = $exc['sensitivity'];
                        if (isset($exc['reminder_minutes'])) $exception->reminder = $exc['reminder_minutes'];
                        if (isset($exc['all_day'])) $exception->alldayevent = $exc['all_day'] ? 1 : 0;
                    }
                    $output->exceptions[] = $exception;
                }
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetAppointmentMessage(): Built event ' . $id);
        return $output;
    }

    /**
     * Builds a SyncRecurrence from recurrence JSON data.
     */
    private function BuildSyncRecurrence($rec) {
        $recurrence = new SyncRecurrence();

        // Type: 0=daily, 1=weekly, 2=monthly, 3=monthly-nth, 5=yearly, 6=yearly-nth
        $type = isset($rec['type']) ? $rec['type'] : 'daily';
        switch ($type) {
            case 'weekly': $recurrence->type = 1; break;
            case 'monthly': $recurrence->type = 2; break;
            case 'monthly-nth': $recurrence->type = 3; break;
            case 'yearly': $recurrence->type = 5; break;
            case 'yearly-nth': $recurrence->type = 6; break;
            default: $recurrence->type = 0; break; // daily
        }

        // Interval
        if (isset($rec['interval'])) {
            $recurrence->interval = intval($rec['interval']);
        }

        // Day of week (bitmask: Su=1, Mo=2, Tu=4, We=8, Th=16, Fr=32, Sa=64)
        if (isset($rec['dayOfWeek'])) {
            $recurrence->dayofweek = intval($rec['dayOfWeek']);
        }

        // Day of month (1-31)
        if (isset($rec['dayOfMonth'])) {
            $recurrence->dayofmonth = intval($rec['dayOfMonth']);
        }

        // Week of month (1-5, 5=last)
        if (isset($rec['weekOfMonth'])) {
            $recurrence->weekofmonth = intval($rec['weekOfMonth']);
        }

        // Month of year (1-12)
        if (isset($rec['monthOfYear'])) {
            $recurrence->monthofyear = intval($rec['monthOfYear']);
        }

        // Until date
        if (isset($rec['until'])) {
            $recurrence->until = $this->IsoToTimestamp($rec['until']);
        }

        // Occurrences count
        if (isset($rec['count'])) {
            $recurrence->occurrences = intval($rec['count']);
        }

        return $recurrence;
    }

    /**
     * Converts an IANA timezone name to an ActiveSync timezone blob.
     */
    private function GetActiveSync_Timezone($tzName) {
        try {
            $tz = new DateTimeZone($tzName);
            $now = new DateTime('now', $tz);
            $transitions = $tz->getTransitions($now->getTimestamp() - 86400 * 365, $now->getTimestamp() + 86400 * 365);

            // Base offset in minutes (negated for ActiveSync)
            $baseOffset = -($tz->getOffset($now) / 60);

            // Build 172-byte ActiveSync timezone blob
            // Format: bias(4) + standardName(64) + standardDate(16) + standardBias(4) +
            //         daylightName(64) + daylightDate(16) + daylightBias(4)
            $blob = pack('l', $baseOffset);                          // Bias
            $blob .= str_pad('', 64, "\0");                          // Standard name (blank)
            $blob .= str_pad('', 16, "\0");                          // Standard date (none)
            $blob .= pack('l', 0);                                   // Standard bias
            $blob .= str_pad('', 64, "\0");                          // Daylight name (blank)
            $blob .= str_pad('', 16, "\0");                          // Daylight date (none)
            $blob .= pack('l', 0);                                   // Daylight bias

            // Find DST transitions and update the blob if DST exists
            $stdTransition = null;
            $dstTransition = null;
            foreach ($transitions as $t) {
                if (isset($t['isdst'])) {
                    if ($t['isdst']) {
                        $dstTransition = $t;
                    } else {
                        $stdTransition = $t;
                    }
                }
                if ($stdTransition && $dstTransition) break;
            }

            if ($stdTransition && $dstTransition) {
                $stdDt = new DateTime($stdTransition['time']);
                $dstDt = new DateTime($dstTransition['time']);
                $dstBias = -(($dstTransition['offset'] - $stdTransition['offset']) / 60);

                $blob = pack('l', -($stdTransition['offset'] / 60)); // Bias (standard offset)
                $blob .= str_pad('', 64, "\0");                     // Standard name
                $blob .= $this->PackSystemTime($stdDt);              // Standard date
                $blob .= pack('l', 0);                               // Standard bias
                $blob .= str_pad('', 64, "\0");                     // Daylight name
                $blob .= $this->PackSystemTime($dstDt);              // Daylight date
                $blob .= pack('l', $dstBias);                       // Daylight bias
            }

            return base64_encode($blob);
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->GetActiveSync_Timezone(): Error for ' . $tzName . ': ' . $e->getMessage());
            // Return UTC as fallback
            $blob = str_pad('', 172, "\0");
            return base64_encode($blob);
        }
    }

    /**
     * Packs a DateTime into a 16-byte SYSTEMTIME structure for ActiveSync timezone.
     */
    private function PackSystemTime($dt) {
        return pack('vvvvvvvv',
            0,                        // wYear (0 = relative)
            intval($dt->format('n')), // wMonth (1-12)
            $this->GetDayOccurrence($dt), // wDayOfWeek occurrence (1-5)
            intval($dt->format('w')), // wDayOfWeek (0=Sun)
            intval($dt->format('G')), // wHour
            intval($dt->format('i')), // wMinute
            intval($dt->format('s')), // wSecond
            0                         // wMilliseconds
        );
    }

    /**
     * Returns which occurrence (1-5) of the weekday within its month.
     */
    private function GetDayOccurrence($dt) {
        $day = intval($dt->format('j'));
        return intval(ceil($day / 7));
    }


    /**
     * Returns attachment data for the given reference.
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->GetAttachmentData(): ' . $attname);


        if (!is_string($attname) || $attname === '' || (strlen($attname) % 2) !== 0 || !ctype_xdigit($attname)) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetAttachmentData(): Invalid attachment reference encoding');
            return false;
        }

        $decoded = pack("H*", $attname);
        $parts = explode(':', $decoded, 3);

        if (count($parts) !== 3 || $parts[2] === '') {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetAttachmentData(): Invalid attachment reference');
            return false;
        }

        // Parts: folderid:emailid:blobid (blobid may contain ':' and is preserved by limit=3)
        $blobId = $parts[2];

        $attachment = new SyncItemOperationsAttachment();

        try {
            $tmpFile = tempnam(sys_get_temp_dir(), 'zpush_att_');
            $size = $this->_jmapClient->downloadBlobToFile($blobId, $tmpFile);
            $attachment->data = file_get_contents($tmpFile);
            if (file_exists($tmpFile)) unlink($tmpFile);
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->GetAttachmentData(): Error: ' . $e->getMessage());
            return false;
        }

        return $attachment;
    }


    /**
     * Creates or updates a message (email flags, contact, or appointment).
     */
    public function ChangeMessage($folderid, $id, $input, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangeMessage(): folder=' . $folderid . ' id=' . $id);

        if (!$this->_jmapClient) {
            return false;
        }

        try {
            $index = $this->GetFolderIndex($folderid);
            $view = $this->_folders[$index]->view;

            switch ($view) {
                case 'contact':
                    return $this->ChangeContactMessage($folderid, $id, $input, $index);
                case 'appointment':
                    return $this->ChangeAppointmentMessage($folderid, $id, $input, $index);
                default:
                    return $this->ChangeEmailMessage($folderid, $id, $input);
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->ChangeMessage(): Error: ' . $e->getMessage());
            return false;
        }
    }

    private function ChangeEmailMessage($folderid, $id, $input) {
        $keywords = array();

        // Update read flag
        if (isset($input->read)) {
            $keywords['$seen'] = ($input->read == 1);
        }

        // Update flagged status
        if (isset($input->flag) && isset($input->flag->flagstatus)) {
            $keywords['$flagged'] = ($input->flag->flagstatus == 2);
        }

        if (!empty($keywords)) {
            $this->_jmapClient->setEmailKeywords($id, $keywords);
        }

        return $this->StatMessage($folderid, $id);
    }

    private function ChangeContactMessage($folderid, $id, $input, $index) {
        $data = $this->BuildContactJson($input);
        $jmapId = $this->_folders[$index]->jmapId;

        if (empty($id)) {
            // Create new contact
            $newId = $this->_jmapClient->createContact($jmapId, json_encode($data));
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangeContactMessage(): Created contact ' . $newId);
            return $this->StatMessage($folderid, $newId);
        } else {
            // Update existing contact
            $this->_jmapClient->updateContact($id, json_encode($data));
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangeContactMessage(): Updated contact ' . $id);
            return $this->StatMessage($folderid, $id);
        }
    }

    private function ChangeAppointmentMessage($folderid, $id, $input, $index) {
        $data = $this->BuildCalendarEventJson($input);
        $jmapId = $this->_folders[$index]->jmapId;

        if (empty($id)) {
            // Create new event
            $newId = $this->_jmapClient->createCalendarEvent($jmapId, json_encode($data));
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangeAppointmentMessage(): Created event ' . $newId);
            return $this->StatMessage($folderid, $newId);
        } else {
            // Update existing event
            $this->_jmapClient->updateCalendarEvent($id, json_encode($data));
            ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangeAppointmentMessage(): Updated event ' . $id);
            return $this->StatMessage($folderid, $id);
        }
    }

    /**
     * Builds JSContact JSON data from a SyncContact object.
     */
    private function BuildContactJson($contact) {
        $data = array();

        // Name components
        $nameComponents = array();
        if (isset($contact->firstname) && $contact->firstname != '') {
            $nameComponents[] = array('kind' => 'given', 'value' => $contact->firstname);
        }
        if (isset($contact->lastname) && $contact->lastname != '') {
            $nameComponents[] = array('kind' => 'surname', 'value' => $contact->lastname);
        }
        if (isset($contact->middlename) && $contact->middlename != '') {
            $nameComponents[] = array('kind' => 'given2', 'value' => $contact->middlename);
        }
        if (isset($contact->suffix) && $contact->suffix != '') {
            $nameComponents[] = array('kind' => 'suffix', 'value' => $contact->suffix);
        }
        if (isset($contact->title) && $contact->title != '') {
            $nameComponents[] = array('kind' => 'title', 'value' => $contact->title);
        }
        if (!empty($nameComponents)) {
            $data['name'] = array('components' => $nameComponents);
        }

        // Nicknames
        if (isset($contact->nickname) && $contact->nickname != '') {
            $data['nicknames'] = array('n1' => array('name' => $contact->nickname));
        }

        // Emails
        $emails = array();
        if (isset($contact->email1address) && $contact->email1address != '') {
            $emails['e1'] = array('address' => $contact->email1address, 'contexts' => array('private' => true));
        }
        if (isset($contact->email2address) && $contact->email2address != '') {
            $emails['e2'] = array('address' => $contact->email2address, 'contexts' => array('work' => true));
        }
        if (isset($contact->email3address) && $contact->email3address != '') {
            $emails['e3'] = array('address' => $contact->email3address);
        }
        if (!empty($emails)) $data['emails'] = $emails;

        // Phones
        $phones = array();
        $pi = 1;
        if (isset($contact->businessphonenumber) && $contact->businessphonenumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->businessphonenumber, 'contexts' => array('work' => true), 'features' => array('voice' => true));
        }
        if (isset($contact->business2phonenumber) && $contact->business2phonenumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->business2phonenumber, 'contexts' => array('work' => true), 'features' => array('voice' => true));
        }
        if (isset($contact->homephonenumber) && $contact->homephonenumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->homephonenumber, 'contexts' => array('private' => true), 'features' => array('voice' => true));
        }
        if (isset($contact->home2phonenumber) && $contact->home2phonenumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->home2phonenumber, 'contexts' => array('private' => true), 'features' => array('voice' => true));
        }
        if (isset($contact->mobilephonenumber) && $contact->mobilephonenumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->mobilephonenumber, 'features' => array('cell' => true));
        }
        if (isset($contact->carphonenumber) && $contact->carphonenumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->carphonenumber, 'features' => array('voice' => true));
        }
        if (isset($contact->pagernumber) && $contact->pagernumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->pagernumber, 'features' => array('pager' => true));
        }
        if (isset($contact->businessfaxnumber) && $contact->businessfaxnumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->businessfaxnumber, 'contexts' => array('work' => true), 'features' => array('fax' => true));
        }
        if (isset($contact->homefaxnumber) && $contact->homefaxnumber != '') {
            $phones['p' . $pi++] = array('number' => $contact->homefaxnumber, 'contexts' => array('private' => true), 'features' => array('fax' => true));
        }
        if (!empty($phones)) $data['phones'] = $phones;

        // Addresses
        $addresses = array();
        $hasHome = (isset($contact->homestreet) || isset($contact->homecity) || isset($contact->homestate) || isset($contact->homepostalcode) || isset($contact->homecountry));
        if ($hasHome) {
            $addr = array('contexts' => array('private' => true));
            if (isset($contact->homestreet)) $addr['street'] = array(array('type' => 'name', 'value' => $contact->homestreet));
            if (isset($contact->homecity)) $addr['locality'] = $contact->homecity;
            if (isset($contact->homestate)) $addr['region'] = $contact->homestate;
            if (isset($contact->homepostalcode)) $addr['postcode'] = $contact->homepostalcode;
            if (isset($contact->homecountry)) $addr['country'] = $contact->homecountry;
            $addresses['a1'] = $addr;
        }
        $hasWork = (isset($contact->businessstreet) || isset($contact->businesscity) || isset($contact->businessstate) || isset($contact->businesspostalcode) || isset($contact->businesscountry));
        if ($hasWork) {
            $addr = array('contexts' => array('work' => true));
            if (isset($contact->businessstreet)) $addr['street'] = array(array('type' => 'name', 'value' => $contact->businessstreet));
            if (isset($contact->businesscity)) $addr['locality'] = $contact->businesscity;
            if (isset($contact->businessstate)) $addr['region'] = $contact->businessstate;
            if (isset($contact->businesspostalcode)) $addr['postcode'] = $contact->businesspostalcode;
            if (isset($contact->businesscountry)) $addr['country'] = $contact->businesscountry;
            $addresses['a2'] = $addr;
        }
        $hasOther = (isset($contact->otherstreet) || isset($contact->othercity) || isset($contact->otherstate) || isset($contact->otherpostalcode) || isset($contact->othercountry));
        if ($hasOther) {
            $addr = array();
            if (isset($contact->otherstreet)) $addr['street'] = array(array('type' => 'name', 'value' => $contact->otherstreet));
            if (isset($contact->othercity)) $addr['locality'] = $contact->othercity;
            if (isset($contact->otherstate)) $addr['region'] = $contact->otherstate;
            if (isset($contact->otherpostalcode)) $addr['postcode'] = $contact->otherpostalcode;
            if (isset($contact->othercountry)) $addr['country'] = $contact->othercountry;
            $addresses['a3'] = $addr;
        }
        if (!empty($addresses)) $data['addresses'] = $addresses;

        // Organizations
        if ((isset($contact->companyname) && $contact->companyname != '') || (isset($contact->department) && $contact->department != '')) {
            $org = array();
            if (isset($contact->companyname)) $org['name'] = $contact->companyname;
            if (isset($contact->department)) $org['units'] = array(array('name' => $contact->department));
            $data['organizations'] = array('o1' => $org);
        }

        // Titles (job title)
        if (isset($contact->jobtitle) && $contact->jobtitle != '') {
            $data['titles'] = array('t1' => array('name' => $contact->jobtitle));
        }

        // Anniversaries
        $anniversaries = array();
        if (isset($contact->birthday) && $contact->birthday) {
            $anniversaries['a1'] = array('type' => 'birth', 'date' => gmdate('Y-m-d', $contact->birthday));
        }
        if (isset($contact->anniversary) && $contact->anniversary) {
            $anniversaries['a2'] = array('type' => 'wedding', 'date' => gmdate('Y-m-d', $contact->anniversary));
        }
        if (!empty($anniversaries)) $data['anniversaries'] = $anniversaries;

        // Links (webpage)
        if (isset($contact->webpage) && $contact->webpage != '') {
            $data['links'] = array('l1' => array('uri' => $contact->webpage, 'contexts' => array('work' => true)));
        }

        // Notes
        if (isset($contact->body) && $contact->body != '') {
            $data['notes'] = $contact->body;
        } elseif (isset($contact->asbody) && isset($contact->asbody->data)) {
            $bodyData = $contact->asbody->data;
            if (is_resource($bodyData)) {
                $bodyData = stream_get_contents($bodyData);
            }
            if ($bodyData != '') $data['notes'] = $bodyData;
        }

        // Spouse and assistant as relations
        $relatedTo = array();
        if (isset($contact->spouse) && $contact->spouse != '') {
            $relatedTo['r1'] = array('relation' => array('spouse' => true), '@type' => 'Relation');
        }
        if (isset($contact->assistantname) && $contact->assistantname != '') {
            $relatedTo['r2'] = array('relation' => array('assistant' => true), '@type' => 'Relation');
        }
        if (!empty($relatedTo)) $data['relatedTo'] = $relatedTo;

        return $data;
    }

    /**
     * Builds JSCalendar JSON data from a SyncAppointment object.
     */
    private function BuildCalendarEventJson($appt) {
        $data = array();

        if (isset($appt->subject)) $data['title'] = $appt->subject;
        // JMAP CalendarEvent (RFC 8984) takes `locations`: a map keyed
        // by an arbitrary id, with each value being a Location object
        // (`@type: Location`, `name`, etc.). Stalwart rejects the
        // singular `location` shape we used to send with
        // {"description":"Invalid property.","properties":["location"],
        // "type":"invalidProperties"}. Round-trip is fine even after
        // this: the Rust crate's extract_calendar_location() already
        // flattens the read-side locations map back into a single
        // string at the FFI boundary, so device-side EAS only ever
        // sees `$appt->location` regardless of which shape Stalwart
        // stores.
        if (isset($appt->location) && $appt->location !== '') {
            $data['locations'] = array(
                '1' => array(
                    '@type' => 'Location',
                    'name'  => (string) $appt->location,
                ),
            );
        }
        if (isset($appt->uid)) $data['uid'] = $appt->uid;

        // Start time and timezone
        if (isset($appt->starttime)) {
            $tz = 'UTC';
            if (isset($appt->timezone) && $appt->timezone != '') {
                $tz = $this->ParseActiveSync_Timezone($appt->timezone);
            }
            $dt = new DateTime('@' . $appt->starttime);
            $dt->setTimezone(new DateTimeZone($tz));
            $data['start'] = $dt->format('Y-m-d\TH:i:s');
            $data['timeZone'] = $tz;
        }

        // Duration (from start/end)
        if (isset($appt->starttime) && isset($appt->endtime)) {
            $duration = $appt->endtime - $appt->starttime;
            $data['duration'] = $this->SecondsToIsoDuration($duration);
        }

        // All-day event
        if (isset($appt->alldayevent) && $appt->alldayevent) {
            $data['showWithoutTime'] = true;
        }

        // Status
        if (isset($appt->busystatus)) {
            $busyMap = array(0 => 'free', 1 => 'tentative', 2 => 'busy', 3 => 'unavailable');
            $data['freeBusyStatus'] = isset($busyMap[$appt->busystatus]) ? $busyMap[$appt->busystatus] : 'busy';
        }

        // Sensitivity/privacy
        if (isset($appt->sensitivity)) {
            $privacyMap = array(0 => 'public', 2 => 'private', 3 => 'secret');
            $data['privacy'] = isset($privacyMap[$appt->sensitivity]) ? $privacyMap[$appt->sensitivity] : 'public';
        }

        // Reminder/alert
        if (isset($appt->reminder) && $appt->reminder >= 0) {
            $offset = $this->SecondsToIsoDuration($appt->reminder * 60);
            $data['alerts'] = array('a1' => array(
                'trigger' => array('@type' => 'OffsetTrigger', 'offset' => '-' . $offset, 'relativeTo' => 'start'),
                'action' => 'display'
            ));
        }

        // Description
        if (isset($appt->body) && $appt->body != '') {
            $data['description'] = $appt->body;
        } elseif (isset($appt->asbody) && isset($appt->asbody->data)) {
            $bodyData = $appt->asbody->data;
            if (is_resource($bodyData)) {
                $bodyData = stream_get_contents($bodyData);
            }
            if ($bodyData != '') $data['description'] = $bodyData;
        }

        // Participants (organizer + attendees).
        //
        // iOS and several other EAS clients don't populate
        // organizername/organizeremail on the SyncAppointment for events
        // the user is creating — they assume the server knows who's
        // signed in. Z-Push surfaces that gap as a WARN ("Parameter
        // 'organizername' and 'organizeremail' should be set for a
        // meeting request") and then continues. We hit Stalwart's
        // JMAP CalendarEvent/set without an organizer in the
        // participants map, which means there's no `roles.owner`
        // participant, which means Stalwart's scheduling layer has no
        // basis to fire iTIP and the attendees never receive an
        // invitation email.
        //
        // Fill in the authenticated user as organizer when EAS
        // doesn't. Safe because $this->_user is the same identity
        // Stalwart used to authenticate the JMAP session, so the
        // ownership claim matches what the backend will accept.
        $organizerEmail = (isset($appt->organizeremail) && $appt->organizeremail != '')
            ? $appt->organizeremail
            : $this->_user;
        $organizerName = (isset($appt->organizername) && $appt->organizername != '')
            ? $appt->organizername
            : null;

        $participants = array();
        $org = array(
            'roles' => array('owner' => true, 'attendee' => true, 'chair' => true),
            'sendTo' => array('imip' => 'mailto:' . $organizerEmail),
        );
        if ($organizerName !== null) $org['name'] = $organizerName;
        $participants['p0'] = $org;

        if (isset($appt->attendees) && is_array($appt->attendees)) {
            $ai = 1;
            foreach ($appt->attendees as $att) {
                $p = array('roles' => array('attendee' => true));
                if (isset($att->name)) $p['name'] = $att->name;
                if (isset($att->email)) $p['sendTo'] = array('imip' => 'mailto:' . $att->email);
                // Map attendee status back to JMAP
                if (isset($att->attendeestatus)) {
                    $statusMap = array(2 => 'tentative', 3 => 'accepted', 4 => 'declined');
                    $p['participationStatus'] = isset($statusMap[$att->attendeestatus]) ? $statusMap[$att->attendeestatus] : 'needs-action';
                }
                // Map attendee type to role
                if (isset($att->attendeetype) && $att->attendeetype == 2) {
                    $p['roles']['optional'] = true;
                }
                $participants['p' . $ai++] = $p;
            }
        }
        if (!empty($participants)) $data['participants'] = $participants;

        // Recurrence
        if (isset($appt->recurrence) && $appt->recurrence !== null) {
            $data['recurrenceRules'] = array($this->BuildRecurrenceRule($appt->recurrence));
        }

        return $data;
    }

    /**
     * Builds a JSCalendar RecurrenceRule from a SyncRecurrence object.
     */
    private function BuildRecurrenceRule($rec) {
        $rule = array();

        // Frequency
        $typeMap = array(0 => 'daily', 1 => 'weekly', 2 => 'monthly', 3 => 'monthly', 5 => 'yearly', 6 => 'yearly');
        $rule['frequency'] = isset($typeMap[$rec->type]) ? $typeMap[$rec->type] : 'daily';

        if (isset($rec->interval) && $rec->interval > 0) {
            $rule['interval'] = $rec->interval;
        }

        // Day of week bitmask → byDay array
        if (isset($rec->dayofweek) && $rec->dayofweek > 0) {
            $dayNames = array(1 => 'su', 2 => 'mo', 4 => 'tu', 8 => 'we', 16 => 'th', 32 => 'fr', 64 => 'sa');
            $byDay = array();
            foreach ($dayNames as $bit => $name) {
                if ($rec->dayofweek & $bit) {
                    $entry = array('day' => $name);
                    // For monthly-nth/yearly-nth, include nthOfPeriod
                    if (($rec->type == 3 || $rec->type == 6) && isset($rec->weekofmonth)) {
                        $entry['nthOfPeriod'] = $rec->weekofmonth;
                    }
                    $byDay[] = $entry;
                }
            }
            if (!empty($byDay)) $rule['byDay'] = $byDay;
        }

        if (isset($rec->dayofmonth) && $rec->dayofmonth > 0) {
            $rule['byMonthDay'] = array($rec->dayofmonth);
        }

        if (isset($rec->monthofyear) && $rec->monthofyear > 0) {
            // JSCalendar uses month number strings
            $rule['byMonth'] = array(strval($rec->monthofyear));
        }

        if (isset($rec->until) && $rec->until) {
            $rule['until'] = gmdate('Y-m-d\TH:i:s\Z', $rec->until);
        }

        if (isset($rec->occurrences) && $rec->occurrences > 0) {
            $rule['count'] = $rec->occurrences;
        }

        return $rule;
    }

    /**
     * Converts seconds to ISO 8601 duration string (e.g., PT1H30M).
     */
    private function SecondsToIsoDuration($seconds) {
        $seconds = abs(intval($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = 'P';
        if ($hours > 0 || $minutes > 0 || $secs > 0) {
            $parts .= 'T';
            if ($hours > 0) $parts .= $hours . 'H';
            if ($minutes > 0) $parts .= $minutes . 'M';
            if ($secs > 0) $parts .= $secs . 'S';
        } else {
            $parts .= 'T0S';
        }
        return $parts;
    }

    /**
     * Parses an ActiveSync timezone blob back to an IANA timezone name.
     */
    private function ParseActiveSync_Timezone($b64blob) {
        $data = base64_decode($b64blob);
        if (strlen($data) < 4) return 'UTC';

        $bias = unpack('l', substr($data, 0, 4));
        $offsetMinutes = -$bias[1]; // ActiveSync negates the offset

        // Try to find a matching timezone
        $abbreviations = DateTimeZone::listAbbreviations();
        $targetOffset = $offsetMinutes * 60;
        foreach ($abbreviations as $zones) {
            foreach ($zones as $zone) {
                if ($zone['offset'] == $targetOffset && $zone['timezone_id'] != '') {
                    return $zone['timezone_id'];
                }
            }
        }

        // Fallback: construct from offset
        $sign = ($offsetMinutes >= 0) ? '+' : '-';
        $absMin = abs($offsetMinutes);
        return sprintf('Etc/GMT%s%d', ($offsetMinutes >= 0) ? '-' : '+', intdiv($absMin, 60));
    }


    /**
     * Moves a message to a different folder.
     */
    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->MoveMessage(): ' . $id . ' from ' . $folderid . ' to ' . $newfolderid);

        try {
            $this->_jmapClient->moveEmail($id, $folderid, $newfolderid);
            return $id;
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->MoveMessage(): Error: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Deletes a message (email to trash, contact/event permanently destroyed).
     */
    public function DeleteMessage($folderid, $id, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->DeleteMessage(): folder=' . $folderid . ' id=' . $id);

        try {
            $index = $this->GetFolderIndex($folderid);
            $view = $this->_folders[$index]->view;

            switch ($view) {
                case 'contact':
                    $this->_jmapClient->destroyContact($id);
                    return true;
                case 'appointment':
                    $this->_jmapClient->destroyCalendarEvent($id);
                    return true;
                default:
                    $deletesAsMoves = defined('STALWART_DELETESASMOVES') ? STALWART_DELETESASMOVES : true;
                    if ($deletesAsMoves && $this->_wasteID && $folderid != $this->_wasteID) {
                        $this->_jmapClient->trashEmail($id, $this->_wasteID);
                    } else {
                        $this->_jmapClient->destroyEmail($id);
                    }
                    return true;
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->DeleteMessage(): Error: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Sends an email message.
     */
    public function SendMail($syncsm) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->SendMail(): START');

        if (!$this->_jmapClient) {
            return false;
        }

        // Get the raw MIME content
        $mime = $syncsm->mime;
        if (is_resource($mime)) {
            $mime = stream_get_contents($mime);
        }

        if (empty($mime)) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->SendMail(): Empty MIME content');
            return false;
        }

        // Z-Push doesn't guarantee Setup() runs before SendMail() — the EAS
        // request processor dispatches the SendMail command directly without
        // a preceding FolderSync. Each PHP-FPM worker handles requests with
        // a fresh backend instance, so $this->_folders / $this->_sentID
        // start empty and stay empty unless we populate them here. Without
        // this call, the "No folders available" path below always fires and
        // every outbound message gets stuck in the device's Outbox.
        if (empty($this->_folders) && method_exists($this, 'Setup')) {
            $this->Setup($this->_user);
        }

        // Ensure we have the sent folder ID
        if (!$this->_sentID) {
            // Try to find it from folders
            foreach ($this->_folders as $folder) {
                if ($folder->role == 'sent') {
                    $this->_sentID = $folder->id;
                    break;
                }
            }
        }

        $sentFolderId = $this->_sentID;
        if (!$sentFolderId) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->SendMail(): No Sent folder found, using first available mailbox');
            if (!empty($this->_folders)) {
                $sentFolderId = $this->_folders[0]->id;
            } else {
                ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->SendMail(): No folders available');
                return false;
            }
        }

        try {
            // Write MIME to temp file (Rust reads from file to handle binary safely)
            $tmpFile = tempnam(sys_get_temp_dir(), 'zpush_send_');
            file_put_contents($tmpFile, $mime);

            $result = $this->_jmapClient->sendEmail($tmpFile, $sentFolderId);

            if (file_exists($tmpFile)) unlink($tmpFile);

            ZLog::Write(LOGLEVEL_INFO, 'Stalwart->SendMail(): Message sent successfully');
            return $result;

        } catch (Exception $e) {
            if (isset($tmpFile) && file_exists($tmpFile)) unlink($tmpFile);
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->SendMail(): Error: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Initializes the ChangesSink for a folder.
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangesSinkInitialize(): folder=' . $folderid);
        $this->changesSink = true;
        $this->changesSinkFolders[$folderid] = $folderid;
        return true;
    }


    /**
     * Waits for changes on monitored folders.
     * Returns array of folder IDs that have changes, or empty array on timeout.
     */
    public function ChangesSink($timeout = 30) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangesSink(): timeout=' . $timeout);

        $endTime = time() + $timeout;

        // Get initial states if not set
        try {
            if ($this->_emailState === null) {
                $this->_emailState = $this->_jmapClient->getEmailState();
            }
            if ($this->_contactState === null) {
                $this->_contactState = $this->_jmapClient->getContactState();
            }
            if ($this->_calendarEventState === null) {
                $this->_calendarEventState = $this->_jmapClient->getCalendarEventState();
            }
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->ChangesSink(): Initial state error: ' . $e->getMessage());
            sleep(5);
            return array();
        }

        $pushEnabled = defined('STALWART_PUSH_CHANGES_ENABLED')
            ? $this->ParseBoolOption(STALWART_PUSH_CHANGES_ENABLED, true)
            : true;
        $pushSupported = false;

        if ($pushEnabled && method_exists($this->_jmapClient, 'supportsPushNotifications')) {
            try {
                $pushSupported = $this->_jmapClient->supportsPushNotifications();
            } catch (Exception $e) {
                ZLog::Write(LOGLEVEL_WARN, 'Stalwart->ChangesSink(): Push capability check failed, falling back to poll: ' . $e->getMessage());
            }
        }

        if ($pushEnabled
            && method_exists($this->_jmapClient, 'waitForPushChange')
            && $pushSupported
            && $timeout > 0) {
            try {
                $pushTimeout = max(1, intval($timeout));
                $pushNotified = $this->_jmapClient->waitForPushChange($pushTimeout);
                if ($pushNotified) {
                    list($hasChanges, $changedFolders) = $this->CollectChangedFoldersOnce();
                    if ($hasChanges) {
                        return $changedFolders;
                    }
                } else {
                    return array();
                }
            } catch (Exception $e) {
                ZLog::Write(LOGLEVEL_WARN, 'Stalwart->ChangesSink(): Push wait failed, falling back to poll: ' . $e->getMessage());
            }
        }

        while (time() < $endTime) {
            try {
                list($hasChanges, $changedFolders) = $this->CollectChangedFoldersOnce();

                if ($hasChanges) {
                    return $changedFolders;
                }

            } catch (Exception $e) {
                ZLog::Write(LOGLEVEL_WARN, 'Stalwart->ChangesSink(): Poll error: ' . $e->getMessage());
                $this->_emailState = null;
                $this->_contactState = null;
                $this->_calendarEventState = null;
                sleep(5);
                return array();
            }

            // Sleep before next poll (5 seconds)
            if (time() + 5 < $endTime) {
                sleep(5);
            } else {
                break;
            }
        }

        return array();
    }


    /**
     * Folder operations - not supported (Stalwart manages folders).
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->ChangeFolder(): Not implemented');
        return false;
    }

    public function DeleteFolder($id, $parentid) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->DeleteFolder(): Not implemented');
        return false;
    }

    public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, 'Stalwart->SetReadFlag(): folder=' . $folderid . ' id=' . $id);
        try {
            $this->_jmapClient->setEmailKeywords($id, array('$seen' => ($flags == 1)));
            return true;
        } catch (Exception $e) {
            ZLog::Write(LOGLEVEL_ERROR, 'Stalwart->SetReadFlag(): Error: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Convert ISO 8601 date string to Unix timestamp.
     */
    private function IsoToTimestamp($isoDate) {
        if (empty($isoDate)) {
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->IsoToTimestamp(): empty date input, using current-time fallback');
            return time();
        }

        $raw = strval($isoDate);
        $ts = strtotime($raw);
        if ($ts === false) {
            $preview = preg_replace('/\s+/', ' ', trim($raw));
            if (!is_string($preview)) {
                $preview = '';
            }
            if (strlen($preview) > 120) {
                $preview = substr($preview, 0, 117) . '...';
            }
            ZLog::Write(LOGLEVEL_WARN, 'Stalwart->IsoToTimestamp(): invalid date input [' . $preview . '], using current-time fallback');
            return time();
        }

        return $ts;
    }


}
