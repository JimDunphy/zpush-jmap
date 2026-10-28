use ext_php_rs::prelude::*;
use reqwest::blocking::Client;
use reqwest::Url;
use serde::Deserialize;
use serde_json::{json, Value};
use std::collections::{HashMap, VecDeque};
use std::io::{BufRead, BufReader};
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};
use zeroize::Zeroizing;

// ============================================================
// Internal serde types for JMAP JSON parsing
// ============================================================

#[derive(Deserialize)]
struct SessionResource {
    #[serde(rename = "apiUrl")]
    api_url: String,
    #[serde(rename = "downloadUrl")]
    download_url: String,
    #[serde(rename = "uploadUrl")]
    upload_url: String,
    #[serde(rename = "eventSourceUrl")]
    event_source_url: Option<String>,
    #[serde(rename = "primaryAccounts")]
    primary_accounts: HashMap<String, String>,
}

#[derive(Deserialize)]
struct MailboxGetData {
    id: String,
    name: String,
    #[serde(rename = "parentId")]
    parent_id: Option<String>,
    role: Option<String>,
    #[serde(rename = "totalEmails", default)]
    total_emails: u64,
    #[serde(rename = "unreadEmails", default)]
    unread_emails: u64,
    #[serde(rename = "sortOrder", default)]
    sort_order: u32,
}

#[derive(Deserialize)]
struct EmailQueryGetData {
    ids: Vec<String>,
    total: Option<u64>,
    position: Option<u64>,
    #[serde(rename = "queryState")]
    query_state: Option<String>,
}

#[derive(Deserialize)]
struct EmailMetaGetData {
    id: String,
    #[serde(default)]
    keywords: HashMap<String, bool>,
    #[serde(default)]
    size: u64,
    #[serde(rename = "receivedAt")]
    received_at: Option<String>,
    #[serde(rename = "mailboxIds", default)]
    mailbox_ids: HashMap<String, bool>,
}

#[derive(Deserialize)]
struct EmailAddressGetData {
    name: Option<String>,
    email: Option<String>,
}

#[derive(Deserialize)]
struct BodyValueGetData {
    value: String,
}

#[derive(Deserialize)]
struct BodyPartGetData {
    #[serde(rename = "partId")]
    part_id: Option<String>,
    #[serde(rename = "blobId")]
    blob_id: Option<String>,
    #[serde(rename = "type")]
    content_type: Option<String>,
    name: Option<String>,
    #[serde(default)]
    size: u64,
    disposition: Option<String>,
    cid: Option<String>,
}

#[derive(Deserialize)]
struct EmailFullGetData {
    id: String,
    #[serde(rename = "blobId")]
    blob_id: Option<String>,
    subject: Option<String>,
    from: Option<Vec<EmailAddressGetData>>,
    to: Option<Vec<EmailAddressGetData>>,
    cc: Option<Vec<EmailAddressGetData>>,
    bcc: Option<Vec<EmailAddressGetData>>,
    #[serde(rename = "replyTo")]
    reply_to: Option<Vec<EmailAddressGetData>>,
    #[serde(rename = "sentAt")]
    sent_at: Option<String>,
    #[serde(rename = "receivedAt")]
    received_at: Option<String>,
    #[serde(default)]
    size: u64,
    #[serde(default)]
    keywords: HashMap<String, bool>,
    #[serde(rename = "bodyValues", default)]
    body_values: HashMap<String, BodyValueGetData>,
    #[serde(rename = "textBody")]
    text_body: Option<Vec<BodyPartGetData>>,
    #[serde(rename = "htmlBody")]
    html_body: Option<Vec<BodyPartGetData>>,
    #[serde(rename = "hasAttachment", default)]
    has_attachment: bool,
    attachments: Option<Vec<BodyPartGetData>>,
    #[serde(rename = "inReplyTo")]
    in_reply_to: Option<Vec<String>>,
    #[serde(rename = "messageId")]
    message_id: Option<Vec<String>>,
    references: Option<Vec<String>>,
    #[serde(rename = "header:X-Priority:asText")]
    x_priority: Option<String>,
    #[serde(rename = "header:Importance:asText")]
    importance_header: Option<String>,
}

#[derive(Deserialize)]
struct ChangesGetData {
    #[serde(rename = "newState")]
    new_state: String,
    #[serde(default)]
    created: Vec<String>,
    #[serde(default)]
    updated: Vec<String>,
    #[serde(default)]
    destroyed: Vec<String>,
}

#[derive(Deserialize)]
struct UploadResponseData {
    #[serde(rename = "blobId")]
    blob_id: String,
}

#[derive(Deserialize)]
struct IdentityGetData {
    id: String,
}

// ============================================================
// Internal serde types for JMAP Contacts (JSContact)
// ============================================================

#[derive(Deserialize)]
struct AddressBookGetData {
    id: String,
    name: String,
    #[serde(rename = "isDefault", default)]
    is_default: bool,
}

#[derive(Deserialize)]
struct ContactCardGetData {
    id: String,
    uid: Option<String>,
    kind: Option<String>,
    name: Option<Value>,
    emails: Option<Value>,
    phones: Option<Value>,
    addresses: Option<Value>,
    anniversaries: Option<Value>,
    links: Option<Value>,
    organizations: Option<Value>,
    titles: Option<Value>,
    nicknames: Option<Value>,
    notes: Option<Value>,
    #[serde(rename = "addressBookIds", default)]
    address_book_ids: HashMap<String, bool>,
}

#[derive(Deserialize)]
struct ContactCardMetaGetData {
    id: String,
    #[serde(rename = "addressBookIds", default)]
    address_book_ids: HashMap<String, bool>,
}

#[derive(Deserialize)]
struct ContactCardQueryGetData {
    ids: Vec<String>,
    total: Option<u64>,
}

// ============================================================
// Internal serde types for JMAP Calendars (JSCalendar)
// ============================================================

#[derive(Deserialize)]
struct CalendarGetData {
    id: String,
    name: String,
    #[serde(rename = "isDefault", default)]
    is_default: bool,
}

#[derive(Deserialize)]
struct CalendarEventMetaGetData {
    id: String,
    #[serde(rename = "@type")]
    object_type: Option<String>,
    uid: Option<String>,
    #[serde(rename = "calendarIds", default)]
    calendar_ids: HashMap<String, bool>,
    #[serde(rename = "utcStart")]
    utc_start: Option<String>,
    #[serde(rename = "utcEnd")]
    utc_end: Option<String>,
}

#[derive(Deserialize)]
struct CalendarEventFullGetData {
    id: String,
    uid: Option<String>,
    title: Option<String>,
    description: Option<String>,
    location: Option<Value>,
    status: Option<String>,
    start: Option<String>,
    #[serde(rename = "timeZone")]
    time_zone: Option<String>,
    #[serde(rename = "utcStart")]
    utc_start: Option<String>,
    #[serde(rename = "utcEnd")]
    utc_end: Option<String>,
    duration: Option<String>,
    #[serde(rename = "showWithoutTime", default)]
    show_without_time: bool,
    #[serde(rename = "freeBusyStatus")]
    free_busy_status: Option<String>,
    privacy: Option<String>,
    alerts: Option<HashMap<String, Value>>,
    participants: Option<HashMap<String, Value>>,
    #[serde(rename = "recurrenceRules")]
    recurrence_rules: Option<Vec<Value>>,
    #[serde(rename = "excludedRecurrenceRules")]
    excluded_recurrence_rules: Option<Vec<Value>>,
    #[serde(rename = "recurrenceOverrides")]
    recurrence_overrides: Option<HashMap<String, Value>>,
    #[serde(rename = "calendarIds", default)]
    calendar_ids: HashMap<String, bool>,
}

#[derive(Deserialize)]
struct CalendarEventQueryGetData {
    ids: Vec<String>,
    total: Option<u64>,
}

// ============================================================
// PHP-facing types
// ============================================================

#[php_class]
pub struct JmapClient {
    client: Client,
    api_url: String,
    download_url: String,
    upload_url: String,
    push_event_source_url: Option<String>,
    account_id: String,
    username: String,
    password: Zeroizing<String>,
    identity_id: Option<String>,
    request_timeout_secs: u64,
    blob_timeout_secs: u64,
}

#[php_class]
pub struct JmapMailbox {
    #[prop]
    pub id: String,
    #[prop]
    pub name: String,
    #[prop]
    pub parent_id: Option<String>,
    #[prop]
    pub role: Option<String>,
    #[prop]
    pub total_emails: i64,
    #[prop]
    pub unread_emails: i64,
    #[prop]
    pub sort_order: i64,
}

#[php_class]
pub struct JmapEmailQueryResult {
    #[prop]
    pub ids: Vec<String>,
    #[prop]
    pub total: i64,
    #[prop]
    pub can_load_more: bool,
    #[prop]
    pub query_state: Option<String>,
}

#[php_class]
pub struct JmapEmailMeta {
    #[prop]
    pub id: String,
    #[prop]
    pub keywords: Vec<String>,
    #[prop]
    pub size: i64,
    #[prop]
    pub received_at: String,
    #[prop]
    pub mailbox_ids: Vec<String>,
}

#[php_class]
pub struct JmapEmail {
    #[prop]
    pub id: String,
    #[prop]
    pub blob_id: String,
    #[prop]
    pub subject: Option<String>,
    #[prop]
    pub from: String,
    #[prop]
    pub to: String,
    #[prop]
    pub cc: String,
    #[prop]
    pub bcc: String,
    #[prop]
    pub reply_to: String,
    #[prop]
    pub display_to: String,
    #[prop]
    pub display_cc: String,
    #[prop]
    pub display_bcc: String,
    #[prop]
    pub date: Option<String>,
    #[prop]
    pub received_at: String,
    #[prop]
    pub size: i64,
    #[prop]
    pub keywords: Vec<String>,
    #[prop]
    pub text_body: Option<String>,
    #[prop]
    pub html_body: Option<String>,
    #[prop]
    pub has_attachment: bool,
    #[prop]
    pub in_reply_to: Option<String>,
    #[prop]
    pub message_id: Option<String>,
    #[prop]
    pub references_header: Option<String>,
    #[prop]
    pub importance: i64,
    #[prop]
    pub attachments_json: String,
    #[prop]
    pub attachment_count: i64,
}

#[php_class]
pub struct JmapChanges {
    #[prop]
    pub new_state: String,
    #[prop]
    pub has_changes: bool,
    #[prop]
    pub created: Vec<String>,
    #[prop]
    pub updated: Vec<String>,
    #[prop]
    pub destroyed: Vec<String>,
}

#[php_class]
pub struct AbqDecision {
    #[prop]
    pub action: String,
    #[prop]
    pub matched_rule: Option<String>,
    #[prop]
    pub matched_field: Option<String>,
}

#[php_class]
pub struct AbqRegexCacheStats {
    #[prop]
    pub hits: u64,
    #[prop]
    pub misses: u64,
    #[prop]
    pub compile_errors: u64,
    #[prop]
    pub evictions: u64,
    #[prop]
    pub cached_entries: u64,
}

// ============================================================
// PHP-facing types — Contacts
// ============================================================

#[php_class]
pub struct JmapAddressBook {
    #[prop]
    pub id: String,
    #[prop]
    pub name: String,
    #[prop]
    pub is_default: bool,
}

#[php_class]
pub struct JmapContactMeta {
    #[prop]
    pub id: String,
    #[prop]
    pub address_book_ids: Vec<String>,
}

#[php_class]
pub struct JmapContact {
    #[prop]
    pub id: String,
    #[prop]
    pub uid: Option<String>,
    #[prop]
    pub first_name: Option<String>,
    #[prop]
    pub last_name: Option<String>,
    #[prop]
    pub middle_name: Option<String>,
    #[prop]
    pub name_title: Option<String>,
    #[prop]
    pub suffix: Option<String>,
    #[prop]
    pub nickname: Option<String>,
    #[prop]
    pub company: Option<String>,
    #[prop]
    pub department: Option<String>,
    #[prop]
    pub job_title: Option<String>,
    #[prop]
    pub spouse: Option<String>,
    #[prop]
    pub email1: Option<String>,
    #[prop]
    pub email2: Option<String>,
    #[prop]
    pub email3: Option<String>,
    #[prop]
    pub work_phone: Option<String>,
    #[prop]
    pub work_phone2: Option<String>,
    #[prop]
    pub home_phone: Option<String>,
    #[prop]
    pub home_phone2: Option<String>,
    #[prop]
    pub mobile_phone: Option<String>,
    #[prop]
    pub car_phone: Option<String>,
    #[prop]
    pub pager: Option<String>,
    #[prop]
    pub other_phone: Option<String>,
    #[prop]
    pub work_fax: Option<String>,
    #[prop]
    pub home_fax: Option<String>,
    #[prop]
    pub home_street: Option<String>,
    #[prop]
    pub home_city: Option<String>,
    #[prop]
    pub home_state: Option<String>,
    #[prop]
    pub home_postal_code: Option<String>,
    #[prop]
    pub home_country: Option<String>,
    #[prop]
    pub work_street: Option<String>,
    #[prop]
    pub work_city: Option<String>,
    #[prop]
    pub work_state: Option<String>,
    #[prop]
    pub work_postal_code: Option<String>,
    #[prop]
    pub work_country: Option<String>,
    #[prop]
    pub other_street: Option<String>,
    #[prop]
    pub other_city: Option<String>,
    #[prop]
    pub other_state: Option<String>,
    #[prop]
    pub other_postal_code: Option<String>,
    #[prop]
    pub other_country: Option<String>,
    #[prop]
    pub im_address: Option<String>,
    #[prop]
    pub im_address2: Option<String>,
    #[prop]
    pub im_address3: Option<String>,
    #[prop]
    pub webpage: Option<String>,
    #[prop]
    pub children_csv: Option<String>,
    #[prop]
    pub birthday: Option<String>,
    #[prop]
    pub anniversary: Option<String>,
    #[prop]
    pub notes: Option<String>,
    #[prop]
    pub has_photo: bool,
    #[prop]
    pub photo_blob_id: Option<String>,
}

#[php_class]
pub struct JmapContactQueryResult {
    #[prop]
    pub ids: Vec<String>,
    #[prop]
    pub total: i64,
}

// ============================================================
// PHP-facing types — Calendar
// ============================================================

#[php_class]
pub struct JmapCalendar {
    #[prop]
    pub id: String,
    #[prop]
    pub name: String,
    #[prop]
    pub is_default: bool,
}

#[php_class]
pub struct JmapCalendarEventMeta {
    #[prop]
    pub id: String,
    #[prop]
    pub object_type: Option<String>,
    #[prop]
    pub uid: Option<String>,
    #[prop]
    pub calendar_ids: Vec<String>,
    #[prop]
    pub utc_start: Option<String>,
    #[prop]
    pub utc_end: Option<String>,
}

#[php_class]
pub struct JmapCalendarEvent {
    #[prop]
    pub id: String,
    #[prop]
    pub uid: Option<String>,
    #[prop]
    pub title: Option<String>,
    #[prop]
    pub description: Option<String>,
    #[prop]
    pub location: Option<String>,
    #[prop]
    pub status: Option<String>,
    #[prop]
    pub start: Option<String>,
    #[prop]
    pub time_zone: Option<String>,
    #[prop]
    pub utc_start: Option<String>,
    #[prop]
    pub utc_end: Option<String>,
    #[prop]
    pub duration: Option<String>,
    #[prop]
    pub all_day: bool,
    #[prop]
    pub organizer_name: Option<String>,
    #[prop]
    pub organizer_email: Option<String>,
    #[prop]
    pub busy_status: i64,
    #[prop]
    pub sensitivity: i64,
    #[prop]
    pub reminder_minutes: i64,
    #[prop]
    pub attendees_json: String,
    #[prop]
    pub recurrence_json: String,
    #[prop]
    pub exceptions_json: String,
    #[prop]
    pub calendar_ids: Vec<String>,
}

#[php_class]
pub struct JmapCalendarEventQueryResult {
    #[prop]
    pub ids: Vec<String>,
    #[prop]
    pub total: i64,
}

// Preserve native task fields for lossless, property-level ActiveSync updates.
#[php_class]
pub struct JmapTask {
    #[prop]
    pub id: String,
    #[prop]
    pub data_json: String,
}

// ============================================================
// Helper functions
// ============================================================

fn php_err(msg: impl std::fmt::Display) -> PhpException {
    PhpException::default(msg.to_string())
}

fn parse_env_bool(value: &str) -> Option<bool> {
    match value.trim().to_ascii_lowercase().as_str() {
        "1" | "true" | "yes" | "on" => Some(true),
        "0" | "false" | "no" | "off" => Some(false),
        _ => None,
    }
}

fn env_bool(name: &str, default: bool) -> bool {
    std::env::var(name)
        .ok()
        .and_then(|value| parse_env_bool(&value))
        .unwrap_or(default)
}

fn env_u64(name: &str, default: u64, min: u64, max: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|value| value.trim().parse::<u64>().ok())
        .map(|value| value.clamp(min, max))
        .unwrap_or(default)
}

fn env_string(name: &str) -> Option<String> {
    std::env::var(name)
        .ok()
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
}

fn push_changes_enabled() -> bool {
    env_bool("STALWART_PUSH_CHANGES_ENABLED", true)
}

fn push_event_types() -> String {
    env_string("STALWART_PUSH_EVENT_TYPES")
        .unwrap_or_else(|| "Mailbox,Email,ContactCard,CalendarEvent".to_string())
}

fn push_closeafter() -> String {
    match env_string("STALWART_PUSH_CLOSEAFTER")
        .map(|value| value.to_ascii_lowercase())
        .as_deref()
    {
        Some("state") => "state".to_string(),
        Some("no") => "no".to_string(),
        Some(_) => "state".to_string(),
        None => "state".to_string(),
    }
}

fn push_ping_secs() -> u64 {
    env_u64("STALWART_PUSH_PING_SECS", 30, 10, 300)
}

fn is_loopback_host(host: &str) -> bool {
    if host.eq_ignore_ascii_case("localhost") {
        return true;
    }
    host.parse::<std::net::IpAddr>()
        .map(|ip| ip.is_loopback())
        .unwrap_or(false)
}

fn validate_base_url_security(base_url: &Url, allow_insecure_http: bool) -> Result<(), String> {
    match base_url.scheme() {
        "https" => Ok(()),
        "http" => {
            let host = base_url
                .host_str()
                .ok_or_else(|| "STALWART_URL is missing a host".to_string())?;

            if is_loopback_host(host) || allow_insecure_http {
                Ok(())
            } else {
                Err("Refusing insecure HTTP to non-loopback STALWART_URL. Use HTTPS or set STALWART_ALLOW_INSECURE_HTTP=true explicitly.".to_string())
            }
        }
        other => Err(format!(
            "Unsupported STALWART_URL scheme '{}'. Only http and https are supported.",
            other
        )),
    }
}

fn resolve_url(base: &str, url: &str) -> String {
    if url.starts_with("http://") || url.starts_with("https://") {
        url.to_string()
    } else {
        format!("{}{}", base.trim_end_matches('/'), url)
    }
}

fn parse_url_template(url: &str) -> Result<Url, String> {
    let normalized = url
        .replace("{accountId}", "account")
        .replace("{blobId}", "blob")
        .replace("{name}", "name")
        .replace("{type}", "application/octet-stream")
        .replace("{types}", "Mailbox,Email")
        .replace("{closeafter}", "state")
        .replace("{ping}", "30");
    Url::parse(&normalized).map_err(|e| format!("Failed to parse URL '{}': {}", url, e))
}

fn validate_resolved_url(base: &Url, resolved: &str, label: &str) -> Result<(), String> {
    let parsed = parse_url_template(resolved)?;
    if base.scheme() == "https" && parsed.scheme() != "https" {
        return Err(format!(
            "Session {} downgraded to non-HTTPS URL '{}'",
            label, resolved
        ));
    }
    Ok(())
}

fn push_payload_has_relevant_change(value: &Value, account_id: &str) -> bool {
    match value {
        Value::Object(map) => {
            if let Some(changed) = map.get("changed").and_then(Value::as_object) {
                if let Some(account_changes) = changed.get(account_id).and_then(Value::as_object) {
                    if account_changes.keys().any(|key| {
                        key.eq_ignore_ascii_case("Email")
                            || key.eq_ignore_ascii_case("Mailbox")
                            || key.eq_ignore_ascii_case("ContactCard")
                            || key.eq_ignore_ascii_case("CalendarEvent")
                    }) {
                        return true;
                    }
                }
            }
            map.values()
                .any(|nested| push_payload_has_relevant_change(nested, account_id))
        }
        Value::Array(list) => list
            .iter()
            .any(|nested| push_payload_has_relevant_change(nested, account_id)),
        _ => false,
    }
}

#[derive(Clone, Copy)]
enum AbqField {
    Any,
    UserAgent,
    DeviceType,
    DeviceId,
}

const ABQ_MAX_REGEX_PATTERN_LEN: usize = 256;
const ABQ_REGEX_CACHE_CAPACITY: usize = 512;

struct AbqRegexCache {
    capacity: usize,
    insertion_order: VecDeque<String>,
    compiled: HashMap<String, Option<regex::Regex>>,
    hits: u64,
    misses: u64,
    compile_errors: u64,
    evictions: u64,
}

impl AbqRegexCache {
    fn new(capacity: usize) -> Self {
        Self {
            capacity,
            insertion_order: VecDeque::new(),
            compiled: HashMap::new(),
            hits: 0,
            misses: 0,
            compile_errors: 0,
            evictions: 0,
        }
    }

    fn get_or_compile(&mut self, pattern: &str) -> Option<regex::Regex> {
        if let Some(existing) = self.compiled.get(pattern) {
            self.hits = self.hits.saturating_add(1);
            return existing.clone();
        }

        self.misses = self.misses.saturating_add(1);

        let compiled = regex::Regex::new(pattern).ok();
        if compiled.is_none() {
            self.compile_errors = self.compile_errors.saturating_add(1);
        }

        self.compiled.insert(pattern.to_string(), compiled.clone());
        self.insertion_order.push_back(pattern.to_string());

        while self.compiled.len() > self.capacity {
            if let Some(oldest) = self.insertion_order.pop_front() {
                if self.compiled.remove(&oldest).is_some() {
                    self.evictions = self.evictions.saturating_add(1);
                }
            } else {
                break;
            }
        }

        compiled
    }

    fn snapshot(&self) -> AbqRegexCacheStats {
        AbqRegexCacheStats {
            hits: self.hits,
            misses: self.misses,
            compile_errors: self.compile_errors,
            evictions: self.evictions,
            cached_entries: self.compiled.len() as u64,
        }
    }
}

static ABQ_REGEX_CACHE: OnceLock<Mutex<AbqRegexCache>> = OnceLock::new();

fn cached_case_insensitive_regex(pattern: &str) -> Option<regex::Regex> {
    let cache = ABQ_REGEX_CACHE
        .get_or_init(|| Mutex::new(AbqRegexCache::new(ABQ_REGEX_CACHE_CAPACITY)));

    if let Ok(mut guard) = cache.lock() {
        return guard.get_or_compile(pattern);
    }

    regex::Regex::new(pattern).ok()
}

#[php_function]
pub fn abq_regex_cache_stats() -> PhpResult<AbqRegexCacheStats> {
    let cache = ABQ_REGEX_CACHE
        .get_or_init(|| Mutex::new(AbqRegexCache::new(ABQ_REGEX_CACHE_CAPACITY)));

    if let Ok(guard) = cache.lock() {
        return Ok(guard.snapshot());
    }

    Ok(AbqRegexCacheStats {
        hits: 0,
        misses: 0,
        compile_errors: 0,
        evictions: 0,
        cached_entries: 0,
    })
}

fn wildcard_match_case_insensitive(pattern: &str, text: &str) -> bool {
    let pattern = pattern.to_ascii_lowercase();
    let text = text.to_ascii_lowercase();
    let pattern = pattern.as_bytes();
    let text = text.as_bytes();

    let mut p = 0usize;
    let mut t = 0usize;
    let mut star_index: Option<usize> = None;
    let mut match_index = 0usize;

    while t < text.len() {
        if p < pattern.len() && (pattern[p] == b'?' || pattern[p] == text[t]) {
            p += 1;
            t += 1;
        } else if p < pattern.len() && pattern[p] == b'*' {
            star_index = Some(p);
            p += 1;
            match_index = t;
        } else if let Some(star_pos) = star_index {
            p = star_pos + 1;
            match_index += 1;
            t = match_index;
        } else {
            return false;
        }
    }

    while p < pattern.len() && pattern[p] == b'*' {
        p += 1;
    }

    p == pattern.len()
}

fn abq_rule_parts(rule: &str) -> (AbqField, &str) {
    let trimmed = rule.trim();
    if let Some((prefix, rest)) = trimmed.split_once(':') {
        let field = match prefix.trim().to_ascii_lowercase().as_str() {
            "ua" | "useragent" | "user_agent" => Some(AbqField::UserAgent),
            "dt" | "devtype" | "device_type" => Some(AbqField::DeviceType),
            "id" | "devid" | "device_id" => Some(AbqField::DeviceId),
            "any" => Some(AbqField::Any),
            _ => None,
        };
        if let Some(field) = field {
            return (field, rest.trim());
        }
    }
    (AbqField::Any, trimmed)
}

fn abq_pattern_matches(pattern: &str, value: &str) -> bool {
    let trimmed = pattern.trim();
    if trimmed.is_empty() || value.is_empty() {
        return false;
    }

    if let Some(regex_pattern) = trimmed.strip_prefix("re:") {
        let regex_pattern = regex_pattern.trim();
        if regex_pattern.is_empty() {
            return false;
        }
        if regex_pattern.len() > ABQ_MAX_REGEX_PATTERN_LEN {
            return false;
        }
        let wrapped = format!("(?i){}", regex_pattern);
        if let Some(re) = cached_case_insensitive_regex(&wrapped) {
            return re.is_match(value);
        }
        return false;
    }

    wildcard_match_case_insensitive(trimmed, value)
}

fn abq_match_rule(
    rule: &str,
    device_id: &str,
    device_type: &str,
    user_agent: &str,
) -> Option<String> {
    let (field, pattern) = abq_rule_parts(rule);
    let matched = match field {
        AbqField::Any => {
            abq_pattern_matches(pattern, user_agent)
                || abq_pattern_matches(pattern, device_type)
                || abq_pattern_matches(pattern, device_id)
        }
        AbqField::UserAgent => abq_pattern_matches(pattern, user_agent),
        AbqField::DeviceType => abq_pattern_matches(pattern, device_type),
        AbqField::DeviceId => abq_pattern_matches(pattern, device_id),
    };

    if !matched {
        return None;
    }

    let matched_field = match field {
        AbqField::Any => "any",
        AbqField::UserAgent => "user_agent",
        AbqField::DeviceType => "device_type",
        AbqField::DeviceId => "device_id",
    };
    Some(matched_field.to_string())
}

fn first_matching_rule(
    rules: &[String],
    device_id: &str,
    device_type: &str,
    user_agent: &str,
) -> Option<(String, String)> {
    for rule in rules {
        if let Some(field) = abq_match_rule(rule, device_id, device_type, user_agent) {
            return Some((rule.clone(), field));
        }
    }
    None
}

fn normalize_rule_list(rules: Vec<String>) -> Vec<String> {
    rules
        .into_iter()
        .map(|rule| rule.trim().to_string())
        .filter(|rule| !rule.is_empty())
        .collect()
}

fn format_address(addr: &EmailAddressGetData) -> String {
    let email = addr.email.as_deref().unwrap_or("");
    let name = addr.name.as_deref().unwrap_or("");
    if name.is_empty() {
        email.to_string()
    } else if email.is_empty() {
        name.to_string()
    } else {
        format!("{} <{}>", name, email)
    }
}

fn format_address_list(addrs: &Option<Vec<EmailAddressGetData>>) -> String {
    match addrs {
        Some(list) if !list.is_empty() => list
            .iter()
            .map(format_address)
            .filter(|s| !s.is_empty())
            .collect::<Vec<_>>()
            .join(", "),
        _ => String::new(),
    }
}

fn format_display_list(addrs: &Option<Vec<EmailAddressGetData>>) -> String {
    match addrs {
        Some(list) if !list.is_empty() => list
            .iter()
            .filter_map(|a| {
                let name = a.name.as_deref().filter(|n| !n.is_empty());
                let email = a.email.as_deref();
                name.or(email)
            })
            .collect::<Vec<_>>()
            .join("; "),
        _ => String::new(),
    }
}

fn keywords_to_vec(keywords: &HashMap<String, bool>) -> Vec<String> {
    keywords
        .iter()
        .filter(|(_, &v)| v)
        .map(|(k, _)| k.clone())
        .collect()
}

fn mailbox_ids_to_vec(mailbox_ids: &HashMap<String, bool>) -> Vec<String> {
    mailbox_ids
        .iter()
        .filter(|(_, &v)| v)
        .map(|(k, _)| k.clone())
        .collect()
}

fn parse_importance(x_priority: &Option<String>, importance: &Option<String>) -> i64 {
    if let Some(xp) = x_priority {
        match xp.trim().chars().next() {
            Some('1') | Some('2') => return 2,
            Some('4') | Some('5') => return 0,
            _ => {}
        }
    }
    if let Some(imp) = importance {
        match imp.trim().to_lowercase().as_str() {
            "high" => return 2,
            "low" => return 0,
            _ => {}
        }
    }
    1
}

fn extract_body_content(
    body_parts: &Option<Vec<BodyPartGetData>>,
    body_values: &HashMap<String, BodyValueGetData>,
) -> Option<String> {
    let parts = body_parts.as_ref()?;
    let first = parts.first()?;
    let part_id = first.part_id.as_ref()?;
    let val = body_values.get(part_id)?;
    Some(val.value.clone())
}

fn attachments_to_json(attachments: &Option<Vec<BodyPartGetData>>) -> String {
    let arr: Vec<Value> = match attachments {
        Some(list) => list
            .iter()
            .map(|a| {
                json!({
                    "blob_id": a.blob_id,
                    "name": a.name,
                    "content_type": a.content_type.as_deref().unwrap_or("application/octet-stream"),
                    "size": a.size,
                    "is_inline": a.disposition.as_deref() == Some("inline"),
                    "content_id": a.cid,
                })
            })
            .collect(),
        None => Vec::new(),
    };
    serde_json::to_string(&arr).unwrap_or_else(|_| "[]".to_string())
}

fn find_method_response<'a>(response: &'a Value, method_name: &str) -> Option<&'a Value> {
    let responses = response.get("methodResponses")?.as_array()?;
    for entry in responses {
        let arr = entry.as_array()?;
        if arr.len() < 2 {
            continue;
        }
        if arr[0].as_str() == Some(method_name) {
            return Some(&arr[1]);
        }
    }
    None
}

fn check_jmap_error(response: &Value, call_id: &str) -> Option<String> {
    let responses = response.get("methodResponses")?.as_array()?;
    for entry in responses {
        let arr = entry.as_array()?;
        if arr.len() < 3 {
            continue;
        }
        if arr[0].as_str() != Some("error") {
            continue;
        }
        if arr[2].as_str() != Some(call_id) {
            continue;
        }
        let payload = &arr[1];
        let error_type = payload
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("unknown");
        let description = payload
            .get("description")
            .and_then(Value::as_str)
            .unwrap_or("");
        return Some(format!("{}: {}", error_type, description));
    }
    None
}

// ============================================================
// JSContact extraction helpers
// ============================================================

fn extract_name_component(name: &Value, kind: &str) -> Option<String> {
    let components = name.get("components")?.as_array()?;
    for comp in components {
        if comp.get("kind").and_then(Value::as_str) == Some(kind) {
            return comp
                .get("value")
                .and_then(Value::as_str)
                .map(|s| s.to_string());
        }
    }
    None
}

fn extract_contact_emails(
    emails: &Option<Value>,
) -> (Option<String>, Option<String>, Option<String>) {
    let map = match emails {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return (None, None, None),
    };

    let mut personal: Vec<&str> = Vec::new();
    let mut work: Vec<&str> = Vec::new();
    let mut other: Vec<&str> = Vec::new();

    for (_key, entry) in map {
        let addr = entry.get("address").and_then(Value::as_str).unwrap_or("");
        if addr.is_empty() {
            continue;
        }
        let contexts = entry.get("contexts");
        if contexts.map_or(false, |c| c.get("work").is_some()) {
            work.push(addr);
        } else if contexts.map_or(false, |c| c.get("private").is_some()) {
            personal.push(addr);
        } else {
            other.push(addr);
        }
    }

    // Merge: personal first, then work, then other
    let mut all: Vec<&str> = Vec::new();
    all.extend(&personal);
    all.extend(&work);
    all.extend(&other);

    let e1 = all.first().map(|s| s.to_string());
    let e2 = all.get(1).map(|s| s.to_string());
    let e3 = all.get(2).map(|s| s.to_string());
    (e1, e2, e3)
}

struct PhoneFields {
    work_phone: Option<String>,
    work_phone2: Option<String>,
    home_phone: Option<String>,
    home_phone2: Option<String>,
    mobile_phone: Option<String>,
    car_phone: Option<String>,
    pager: Option<String>,
    other_phone: Option<String>,
    work_fax: Option<String>,
    home_fax: Option<String>,
}

fn extract_contact_phones(phones: &Option<Value>) -> PhoneFields {
    let mut pf = PhoneFields {
        work_phone: None,
        work_phone2: None,
        home_phone: None,
        home_phone2: None,
        mobile_phone: None,
        car_phone: None,
        pager: None,
        other_phone: None,
        work_fax: None,
        home_fax: None,
    };

    let map = match phones {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return pf,
    };

    for (_key, entry) in map {
        let number = match entry.get("number").and_then(Value::as_str) {
            Some(n) if !n.is_empty() => n.to_string(),
            _ => continue,
        };

        let is_work = entry
            .get("contexts")
            .map_or(false, |c| c.get("work").is_some());
        let is_home = entry
            .get("contexts")
            .map_or(false, |c| c.get("private").is_some());
        let features = entry.get("features");
        let is_fax = features.map_or(false, |f| f.get("fax").is_some());
        let is_cell = features.map_or(false, |f| f.get("cell").is_some());
        let is_pager = features.map_or(false, |f| f.get("pager").is_some());
        let is_car = features.map_or(false, |f| f.get("car").is_some());

        if is_pager {
            if pf.pager.is_none() {
                pf.pager = Some(number);
            }
        } else if is_car {
            if pf.car_phone.is_none() {
                pf.car_phone = Some(number);
            }
        } else if is_fax && is_work {
            if pf.work_fax.is_none() {
                pf.work_fax = Some(number);
            }
        } else if is_fax {
            if pf.home_fax.is_none() {
                pf.home_fax = Some(number);
            }
        } else if is_cell {
            if pf.mobile_phone.is_none() {
                pf.mobile_phone = Some(number);
            }
        } else if is_work {
            if pf.work_phone.is_none() {
                pf.work_phone = Some(number);
            } else if pf.work_phone2.is_none() {
                pf.work_phone2 = Some(number);
            }
        } else if is_home {
            if pf.home_phone.is_none() {
                pf.home_phone = Some(number);
            } else if pf.home_phone2.is_none() {
                pf.home_phone2 = Some(number);
            }
        } else {
            if pf.other_phone.is_none() {
                pf.other_phone = Some(number);
            }
        }
    }

    pf
}

struct AddressFields {
    street: Option<String>,
    city: Option<String>,
    state: Option<String>,
    postal_code: Option<String>,
    country: Option<String>,
}

fn extract_address_by_context(addresses: &Option<Value>, context: &str) -> AddressFields {
    let empty = AddressFields {
        street: None,
        city: None,
        state: None,
        postal_code: None,
        country: None,
    };

    let map = match addresses {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return empty,
    };

    for (_key, entry) in map {
        let matches = if context == "other" {
            // "other" = no work/private context
            let ctx = entry.get("contexts");
            ctx.is_none()
                || (ctx.unwrap().is_object()
                    && !ctx.unwrap().get("work").is_some()
                    && !ctx.unwrap().get("private").is_some())
        } else {
            entry
                .get("contexts")
                .map_or(false, |c| c.get(context).is_some())
        };

        if !matches {
            continue;
        }

        // Try components array first (JSContact standard)
        if let Some(components) = entry.get("components").and_then(Value::as_array) {
            let mut af = AddressFields {
                street: None,
                city: None,
                state: None,
                postal_code: None,
                country: None,
            };
            for comp in components {
                let kind = comp.get("kind").and_then(Value::as_str).unwrap_or("");
                let val = comp.get("value").and_then(Value::as_str).unwrap_or("");
                if val.is_empty() {
                    continue;
                }
                match kind {
                    "streetAddress" | "name" => af.street = Some(val.to_string()),
                    "locality" => af.city = Some(val.to_string()),
                    "region" => af.state = Some(val.to_string()),
                    "postcode" => af.postal_code = Some(val.to_string()),
                    "country" => af.country = Some(val.to_string()),
                    _ => {}
                }
            }
            return af;
        }

        // Fallback: flat fields
        return AddressFields {
            street: entry
                .get("street")
                .and_then(Value::as_str)
                .map(|s| s.to_string()),
            city: entry
                .get("locality")
                .and_then(Value::as_str)
                .map(|s| s.to_string()),
            state: entry
                .get("region")
                .and_then(Value::as_str)
                .map(|s| s.to_string()),
            postal_code: entry
                .get("postcode")
                .and_then(Value::as_str)
                .map(|s| s.to_string()),
            country: entry
                .get("country")
                .and_then(Value::as_str)
                .map(|s| s.to_string()),
        };
    }

    empty
}

fn extract_anniversary(anniversaries: &Option<Value>, kind: &str) -> Option<String> {
    let map = match anniversaries {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return None,
    };

    for (_key, entry) in map {
        let entry_kind = entry.get("kind").and_then(Value::as_str).unwrap_or("");
        if entry_kind == kind || (kind == "birthday" && entry_kind == "birth") {
            if let Some(date) = entry.get("date") {
                // date can be a string "YYYY-MM-DD" or partial date object
                if let Some(s) = date.as_str() {
                    return Some(s.to_string());
                }
            }
        }
    }
    None
}

fn extract_first_value_from_map(val: &Option<Value>, field: &str) -> Option<String> {
    let map = val.as_ref()?.as_object()?;
    let first = map.values().next()?;
    first
        .get(field)
        .and_then(Value::as_str)
        .map(|s| s.to_string())
}

fn extract_org_fields(organizations: &Option<Value>) -> (Option<String>, Option<String>) {
    let map = match organizations {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return (None, None),
    };

    let first = match map.values().next() {
        Some(v) => v,
        None => return (None, None),
    };

    let company = first
        .get("name")
        .and_then(Value::as_str)
        .map(|s| s.to_string());
    let department = first
        .get("units")
        .and_then(Value::as_array)
        .and_then(|a| a.first())
        .and_then(|u| u.get("name"))
        .and_then(Value::as_str)
        .map(|s| s.to_string());

    (company, department)
}

fn extract_links_webpage(links: &Option<Value>) -> Option<String> {
    let map = match links {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return None,
    };

    // Try work URL first, then any URL
    let mut first_url: Option<String> = None;
    for (_key, entry) in map {
        let uri = entry.get("uri").and_then(Value::as_str).unwrap_or("");
        if uri.is_empty() {
            continue;
        }
        if first_url.is_none() {
            first_url = Some(uri.to_string());
        }
        if entry
            .get("contexts")
            .map_or(false, |c| c.get("work").is_some())
        {
            return Some(uri.to_string());
        }
    }
    first_url
}

fn extract_notes_text(notes: &Option<Value>) -> Option<String> {
    let map = match notes {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return None,
    };

    // Concatenate all notes
    let texts: Vec<&str> = map
        .values()
        .filter_map(|v| v.get("note").and_then(Value::as_str))
        .collect();

    if texts.is_empty() {
        None
    } else {
        Some(texts.join("\n"))
    }
}

fn contact_card_to_jmap_contact(card: ContactCardGetData) -> JmapContact {
    let name = &card.name;
    let first_name = name
        .as_ref()
        .and_then(|n| extract_name_component(n, "given"));
    let last_name = name
        .as_ref()
        .and_then(|n| extract_name_component(n, "surname"));
    let middle_name = name
        .as_ref()
        .and_then(|n| extract_name_component(n, "additional"));
    let name_title = name
        .as_ref()
        .and_then(|n| extract_name_component(n, "prefix"));
    let suffix = name
        .as_ref()
        .and_then(|n| extract_name_component(n, "suffix"));

    let (email1, email2, email3) = extract_contact_emails(&card.emails);
    let phones = extract_contact_phones(&card.phones);

    let home_addr = extract_address_by_context(&card.addresses, "private");
    let work_addr = extract_address_by_context(&card.addresses, "work");
    let other_addr = extract_address_by_context(&card.addresses, "other");

    let birthday = extract_anniversary(&card.anniversaries, "birthday");
    let anniversary = extract_anniversary(&card.anniversaries, "anniversary");

    let (company, department) = extract_org_fields(&card.organizations);
    let job_title = extract_first_value_from_map(&card.titles, "name");
    let nickname = extract_first_value_from_map(&card.nicknames, "name");
    let webpage = extract_links_webpage(&card.links);
    let notes = extract_notes_text(&card.notes);

    JmapContact {
        id: card.id,
        uid: card.uid,
        first_name,
        last_name,
        middle_name,
        name_title,
        suffix,
        nickname,
        company,
        department,
        job_title,
        spouse: None, // JSContact has no spouse field
        email1,
        email2,
        email3,
        work_phone: phones.work_phone,
        work_phone2: phones.work_phone2,
        home_phone: phones.home_phone,
        home_phone2: phones.home_phone2,
        mobile_phone: phones.mobile_phone,
        car_phone: phones.car_phone,
        pager: phones.pager,
        other_phone: phones.other_phone,
        work_fax: phones.work_fax,
        home_fax: phones.home_fax,
        home_street: home_addr.street,
        home_city: home_addr.city,
        home_state: home_addr.state,
        home_postal_code: home_addr.postal_code,
        home_country: home_addr.country,
        work_street: work_addr.street,
        work_city: work_addr.city,
        work_state: work_addr.state,
        work_postal_code: work_addr.postal_code,
        work_country: work_addr.country,
        other_street: other_addr.street,
        other_city: other_addr.city,
        other_state: other_addr.state,
        other_postal_code: other_addr.postal_code,
        other_country: other_addr.country,
        im_address: None,
        im_address2: None,
        im_address3: None,
        webpage,
        children_csv: None,
        birthday,
        anniversary,
        notes,
        has_photo: false,
        photo_blob_id: None,
    }
}

// ============================================================
// JSCalendar extraction helpers
// ============================================================

fn extract_calendar_location(location: &Option<Value>) -> Option<String> {
    let map = match location {
        Some(v) if v.is_object() => v.as_object().unwrap(),
        _ => return None,
    };

    map.values()
        .filter_map(|v| v.get("name").and_then(Value::as_str))
        .next()
        .map(|s| s.to_string())
}

fn extract_organizer(
    participants: &Option<HashMap<String, Value>>,
) -> (Option<String>, Option<String>) {
    let map = match participants {
        Some(m) => m,
        None => return (None, None),
    };

    for (_key, p) in map {
        let roles = p.get("roles");
        let is_owner = roles.map_or(false, |r| r.get("owner").is_some());
        if !is_owner {
            continue;
        }

        let name = p.get("name").and_then(Value::as_str).map(|s| s.to_string());
        let email = p
            .get("sendTo")
            .and_then(|st| st.get("imip"))
            .and_then(Value::as_str)
            .map(|s| s.strip_prefix("mailto:").unwrap_or(s).to_string());

        return (name, email);
    }

    (None, None)
}

fn extract_attendees_json(participants: &Option<HashMap<String, Value>>) -> String {
    let map = match participants {
        Some(m) => m,
        None => return "[]".to_string(),
    };

    let mut attendees: Vec<Value> = Vec::new();
    for (_key, p) in map {
        let roles = p.get("roles");
        let is_owner = roles.map_or(false, |r| r.get("owner").is_some());
        if is_owner {
            continue; // Skip organizer
        }

        let name = p.get("name").and_then(Value::as_str).unwrap_or("");
        let email = p
            .get("sendTo")
            .and_then(|st| st.get("imip"))
            .and_then(Value::as_str)
            .map(|s| s.strip_prefix("mailto:").unwrap_or(s))
            .unwrap_or("");

        let status = p
            .get("participationStatus")
            .and_then(Value::as_str)
            .unwrap_or("needs-action");

        let is_required = roles.map_or(false, |r| r.get("attendee").is_some());
        let is_optional = roles.map_or(false, |r| r.get("optional").is_some());
        let attendee_type = if is_optional {
            2
        } else if is_required {
            1
        } else {
            1
        };

        // Map JSCalendar status to ActiveSync (NE=5, AC=3, TE=2, DE=4)
        let as_status = match status {
            "accepted" => 3,
            "tentative" => 2,
            "declined" => 4,
            _ => 5, // needs-action = no response
        };

        attendees.push(json!({
            "name": name,
            "email": email,
            "type": attendee_type,
            "status": as_status,
        }));
    }

    serde_json::to_string(&attendees).unwrap_or_else(|_| "[]".to_string())
}

fn extract_reminder_minutes(alerts: &Option<HashMap<String, Value>>) -> i64 {
    let map = match alerts {
        Some(m) => m,
        None => return -1,
    };

    for (_key, alert) in map {
        let action = alert.get("action").and_then(Value::as_str).unwrap_or("");
        if action != "display" && !action.is_empty() {
            continue; // Prefer display alerts
        }

        if let Some(trigger) = alert.get("trigger") {
            if let Some(offset) = trigger.get("offset").and_then(Value::as_str) {
                return parse_iso_duration_minutes(offset);
            }
        }
    }

    -1 // No reminder
}

fn parse_iso_duration_minutes(duration: &str) -> i64 {
    // Parse ISO 8601 duration like "-PT15M", "PT1H", "PT30M", "-PT1H30M"
    let s = duration.trim_start_matches('-');
    let s = s.strip_prefix('P').unwrap_or(s);
    let s = s.strip_prefix('T').unwrap_or(s);

    let mut minutes: i64 = 0;
    let mut num_buf = String::new();

    for ch in s.chars() {
        if ch.is_ascii_digit() {
            num_buf.push(ch);
        } else {
            let n: i64 = num_buf.parse().unwrap_or(0);
            num_buf.clear();
            match ch {
                'H' => minutes += n * 60,
                'M' => minutes += n,
                'S' => minutes += n / 60,
                'D' => minutes += n * 1440,
                'W' => minutes += n * 10080,
                _ => {}
            }
        }
    }

    minutes
}

fn extract_recurrence_json(rules: &Option<Vec<Value>>) -> String {
    let rules = match rules {
        Some(r) if !r.is_empty() => r,
        _ => return "{}".to_string(),
    };

    let rule = &rules[0];
    serde_json::to_string(rule).unwrap_or_else(|_| "{}".to_string())
}

fn extract_exceptions_json(overrides: &Option<HashMap<String, Value>>) -> String {
    let map = match overrides {
        Some(m) if !m.is_empty() => m,
        _ => return "{}".to_string(),
    };

    serde_json::to_string(map).unwrap_or_else(|_| "{}".to_string())
}

fn map_busy_status(status: &Option<String>) -> i64 {
    match status.as_deref() {
        Some("free") => 0,
        Some("tentative") => 1,
        Some("busy") => 2,
        Some("unavailable") => 3,
        _ => 2, // Default to busy
    }
}

fn map_sensitivity(privacy: &Option<String>) -> i64 {
    match privacy.as_deref() {
        Some("private") => 2,
        Some("secret") => 3,
        _ => 0, // public / normal
    }
}

fn calendar_event_to_jmap(event: CalendarEventFullGetData) -> JmapCalendarEvent {
    let location = extract_calendar_location(&event.location);
    let (organizer_name, organizer_email) = extract_organizer(&event.participants);
    let attendees_json = extract_attendees_json(&event.participants);
    let reminder_minutes = extract_reminder_minutes(&event.alerts);
    let recurrence_json = extract_recurrence_json(&event.recurrence_rules);
    let exceptions_json = extract_exceptions_json(&event.recurrence_overrides);
    let busy_status = map_busy_status(&event.free_busy_status);
    let sensitivity = map_sensitivity(&event.privacy);

    let all_day = event.show_without_time
        || event
            .duration
            .as_deref()
            .map_or(false, |d| d.starts_with('P') && !d.contains('T'));

    JmapCalendarEvent {
        id: event.id,
        uid: event.uid,
        title: event.title,
        description: event.description,
        location,
        status: event.status,
        start: event.start,
        time_zone: event.time_zone,
        utc_start: event.utc_start,
        utc_end: event.utc_end,
        duration: event.duration,
        all_day,
        organizer_name,
        organizer_email,
        busy_status,
        sensitivity,
        reminder_minutes,
        attendees_json,
        recurrence_json,
        exceptions_json,
        calendar_ids: mailbox_ids_to_vec(&event.calendar_ids),
    }
}

// ============================================================
// JmapClient - Internal methods (not exposed to PHP)
// ============================================================

const JMAP_CAPS: [&str; 5] = [
    "urn:ietf:params:jmap:core",
    "urn:ietf:params:jmap:mail",
    "urn:ietf:params:jmap:submission",
    "urn:ietf:params:jmap:contacts",
    "urn:ietf:params:jmap:calendars",
];

impl JmapClient {
    fn calendar_set_request(&self, mut args: Value) -> Result<Value, String> {
        // A compatibility retry is safe only after an explicit per-object
        // rejection. Never replay an Add after a transport/ambiguous failure.
        for attempt in 0..2 {
            let resp = self.jmap_request(vec![json!(["CalendarEvent/set", args, "s0"])])?;
            let retry = if attempt == 0 {
                [ ("create", "notCreated", "created"), ("update", "notUpdated", "updated") ]
                    .iter().find_map(|(operation, rejected, accepted)| {
                        let objects = args.get(*operation)?.as_object()?;
                        if objects.len() != 1 { return None; }
                        let (id, object) = objects.iter().next()?;
                        if object.get("progressUpdated").is_none() { return None; }
                        let payload = find_method_response(&resp, "CalendarEvent/set")?;
                        if payload.get(*accepted).and_then(Value::as_object)
                            .map_or(false, |items| items.contains_key(id)) { return None; }
                        let error = payload.get(*rejected)?.get(id)?;
                        let properties = error.get("properties")?.as_array()?;
                        if error.get("type").and_then(Value::as_str) != Some("invalidProperties")
                            || properties.len() != 1
                            || properties[0].as_str().map(|s| s.trim_start_matches('/')) != Some("progressUpdated") {
                            return None;
                        }
                        let is_task = if *operation == "create" {
                            object.get("@type").and_then(Value::as_str) == Some("Task")
                        } else {
                            matches!(self.get_tasks(vec![id.clone()]), Ok(tasks) if tasks.iter().any(|task| task.id == *id))
                        };
                        if is_task { Some((operation.to_string(), id.clone())) } else { None }
                    })
            } else { None };
            if let Some((operation, id)) = retry {
                args[&operation][&id].as_object_mut().expect("calendar patch object")
                    .remove("progressUpdated");
                continue;
            }
            return Ok(resp);
        }
        unreachable!("calendar compatibility retry is bounded")
    }

    fn jmap_request(&self, method_calls: Vec<Value>) -> Result<Value, String> {
        let request = json!({
            "using": JMAP_CAPS,
            "methodCalls": method_calls
        });

        let resp = self
            .client
            .post(&self.api_url)
            .basic_auth(&self.username, Some(self.password.as_str()))
            .header("Content-Type", "application/json")
            .json(&request)
            .send()
            .map_err(|e| format!("JMAP request failed: {}", e))?;

        if !resp.status().is_success() {
            return Err(format!("JMAP HTTP error: {}", resp.status()));
        }

        resp.json::<Value>()
            .map_err(|e| format!("JMAP response parse error: {}", e))
    }

    fn download_blob_internal(&self, blob_id: &str) -> Result<Vec<u8>, String> {
        let url = self
            .download_url
            .replace("{accountId}", &self.account_id)
            .replace("{blobId}", blob_id)
            .replace("{name}", "download")
            .replace("{type}", "application/octet-stream");

        let resp = self
            .client
            .get(&url)
            .timeout(Duration::from_secs(self.blob_timeout_secs))
            .basic_auth(&self.username, Some(self.password.as_str()))
            .send()
            .map_err(|e| format!("Blob download failed: {}", e))?;

        if !resp.status().is_success() {
            return Err(format!("Blob download HTTP error: {}", resp.status()));
        }

        resp.bytes()
            .map(|b| b.to_vec())
            .map_err(|e| format!("Blob read error: {}", e))
    }

    fn upload_blob_internal(&self, data: &[u8], content_type: &str) -> Result<String, String> {
        let url = self.upload_url.replace("{accountId}", &self.account_id);

        let resp = self
            .client
            .post(&url)
            .timeout(Duration::from_secs(self.request_timeout_secs))
            .basic_auth(&self.username, Some(self.password.as_str()))
            .header("Content-Type", content_type)
            .body(data.to_vec())
            .send()
            .map_err(|e| format!("Blob upload failed: {}", e))?;

        if !resp.status().is_success() {
            return Err(format!("Blob upload HTTP error: {}", resp.status()));
        }

        let upload_resp: UploadResponseData = resp
            .json()
            .map_err(|e| format!("Upload response parse error: {}", e))?;

        Ok(upload_resp.blob_id)
    }

    fn fetch_identity_id(&self) -> Result<String, String> {
        let resp = self.jmap_request(vec![json!([
            "Identity/get",
            {"accountId": &self.account_id},
            "i0"
        ])])?;

        let payload =
            find_method_response(&resp, "Identity/get").ok_or("Missing Identity/get response")?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or("Missing identity list")?;

        if list.is_empty() {
            return Err("No identities found".to_string());
        }

        let identity: IdentityGetData = serde_json::from_value(list[0].clone())
            .map_err(|e| format!("Identity parse error: {}", e))?;

        Ok(identity.id)
    }

    fn resolve_push_event_source_url(&self) -> Result<Option<Url>, String> {
        let Some(template) = self
            .push_event_source_url
            .as_ref()
            .map(|value| value.trim())
            .filter(|value| !value.is_empty())
        else {
            return Ok(None);
        };

        let expanded = template
            .replace("{types}", push_event_types().as_str())
            .replace("{closeafter}", push_closeafter().as_str())
            .replace("{ping}", &push_ping_secs().to_string());

        let event_url = if let Ok(url) = Url::parse(expanded.as_str()) {
            url
        } else {
            let base_api_url = Url::parse(&self.api_url)
                .map_err(|e| format!("Invalid apiUrl '{}': {}", self.api_url, e))?;
            base_api_url
                .join(expanded.as_str())
                .map_err(|e| format!("Failed to resolve eventSourceUrl '{}': {}", expanded, e))?
        };

        let base_api_url =
            Url::parse(&self.api_url).map_err(|e| format!("Invalid apiUrl '{}': {}", self.api_url, e))?;
        if base_api_url.scheme() == "https" && event_url.scheme() != "https" {
            return Err(format!(
                "Session eventSourceUrl downgraded to non-HTTPS URL '{}'",
                event_url
            ));
        }

        Ok(Some(event_url))
    }

    fn wait_for_push_change_internal(&self, timeout_secs: u64) -> Result<bool, String> {
        if timeout_secs == 0 {
            return Ok(false);
        }

        let Some(event_url) = self.resolve_push_event_source_url()? else {
            return Ok(false);
        };

        let response = match self
            .client
            .get(event_url)
            .timeout(Duration::from_secs(timeout_secs))
            .basic_auth(&self.username, Some(self.password.as_str()))
            .header("Accept", "text/event-stream")
            .send()
        {
            Ok(value) => value,
            Err(error) => {
                if error.is_timeout() {
                    return Ok(false);
                }
                let error_text = error.to_string();
                if error_text.to_ascii_lowercase().contains("timed out") {
                    return Ok(false);
                }
                return Err(format!("Push event source connect failed: {}", error_text));
            }
        };

        if !response.status().is_success() {
            return Err(format!(
                "Push event source returned HTTP {}",
                response.status()
            ));
        }

        let deadline = Instant::now() + Duration::from_secs(timeout_secs);
        let mut reader = BufReader::new(response);
        let mut line = String::new();
        let mut event_data = String::new();

        loop {
            if Instant::now() >= deadline {
                return Ok(false);
            }

            line.clear();
            let bytes_read = match reader.read_line(&mut line) {
                Ok(bytes) => bytes,
                Err(error) => {
                    if error.kind() == std::io::ErrorKind::TimedOut
                        || error.kind() == std::io::ErrorKind::WouldBlock
                    {
                        return Ok(false);
                    }
                    if error.to_string().to_ascii_lowercase().contains("timed out") {
                        return Ok(false);
                    }
                    return Err(format!("Push event source read failed: {}", error));
                }
            };

            if bytes_read == 0 {
                return Ok(false);
            }

            if line.ends_with('\n') {
                line.pop();
                if line.ends_with('\r') {
                    line.pop();
                }
            }

            if line.is_empty() {
                if !event_data.is_empty() {
                    if let Ok(payload) = serde_json::from_str::<Value>(event_data.as_str()) {
                        if push_payload_has_relevant_change(&payload, &self.account_id) {
                            return Ok(true);
                        }
                    }
                    event_data.clear();
                }
                continue;
            }

            if let Some(data_line) = line.strip_prefix("data:") {
                if !event_data.is_empty() {
                    event_data.push('\n');
                }
                event_data.push_str(data_line.trim_start());
            }
        }
    }
}

// ============================================================
// JmapClient - PHP-exposed methods
// ============================================================

#[php_impl]
impl JmapClient {
    pub fn get_account_id(&self) -> String {
        self.account_id.clone()
    }

    pub fn supports_push_notifications(&self) -> bool {
        push_changes_enabled() && self.push_event_source_url.is_some()
    }

    pub fn wait_for_push_change(&self, timeout_secs: i64) -> PhpResult<bool> {
        if !push_changes_enabled() {
            return Ok(false);
        }

        let timeout_secs = timeout_secs.clamp(1, 3600) as u64;
        self.wait_for_push_change_internal(timeout_secs).map_err(php_err)
    }

    pub fn get_mailboxes(&self) -> PhpResult<Vec<JmapMailbox>> {
        let resp = self
            .jmap_request(vec![json!([
                "Mailbox/get",
                {
                    "accountId": &self.account_id,
                    "properties": ["id", "name", "parentId", "role",
                                   "totalEmails", "unreadEmails", "sortOrder"]
                },
                "mbox0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "mbox0") {
            return Err(php_err(format!("Mailbox/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "Mailbox/get")
            .ok_or_else(|| php_err("Missing Mailbox/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing mailbox list"))?;

        let mailboxes: Vec<MailboxGetData> = serde_json::from_value(Value::Array(list.clone()))
            .map_err(|e| php_err(format!("Mailbox parse error: {}", e)))?;

        Ok(mailboxes
            .into_iter()
            .map(|mb| JmapMailbox {
                id: mb.id,
                name: mb.name,
                parent_id: mb.parent_id,
                role: mb.role,
                total_emails: mb.total_emails as i64,
                unread_emails: mb.unread_emails as i64,
                sort_order: mb.sort_order as i64,
            })
            .collect())
    }

    pub fn query_emails(
        &self,
        mailbox_id: String,
        cutoff_date: Option<String>,
        limit: i64,
        position: i64,
    ) -> PhpResult<JmapEmailQueryResult> {
        let mut filter = json!({"inMailbox": mailbox_id});
        if let Some(ref date_str) = cutoff_date {
            filter["after"] = json!(date_str);
        }

        let resp = self
            .jmap_request(vec![json!([
                "Email/query",
                {
                    "accountId": &self.account_id,
                    "filter": filter,
                    "sort": [{"property": "receivedAt", "isAscending": false}],
                    "limit": limit,
                    "position": position,
                    "calculateTotal": true
                },
                "q0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "q0") {
            return Err(php_err(format!("Email/query error: {}", err)));
        }

        let payload = find_method_response(&resp, "Email/query")
            .ok_or_else(|| php_err("Missing Email/query response"))?;

        let query: EmailQueryGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("Email/query parse error: {}", e)))?;

        let total = query.total.unwrap_or(query.ids.len() as u64) as i64;
        let returned = query.ids.len() as i64;
        let pos = query.position.unwrap_or(0) as i64;

        Ok(JmapEmailQueryResult {
            can_load_more: (pos + returned) < total,
            ids: query.ids,
            total,
            query_state: query.query_state,
        })
    }

    pub fn get_email_metadata(&self, ids: Vec<String>) -> PhpResult<Vec<JmapEmailMeta>> {
        if ids.is_empty() {
            return Ok(Vec::new());
        }

        let resp = self
            .jmap_request(vec![json!([
                "Email/get",
                {
                    "accountId": &self.account_id,
                    "ids": ids,
                    "properties": ["id", "keywords", "size", "receivedAt", "mailboxIds"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "g0") {
            return Err(php_err(format!("Email/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "Email/get")
            .ok_or_else(|| php_err("Missing Email/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing email list"))?;

        let emails: Vec<EmailMetaGetData> = serde_json::from_value(Value::Array(list.clone()))
            .map_err(|e| php_err(format!("Email metadata parse error: {}", e)))?;

        Ok(emails
            .into_iter()
            .map(|em| JmapEmailMeta {
                id: em.id,
                keywords: keywords_to_vec(&em.keywords),
                size: em.size as i64,
                received_at: em.received_at.unwrap_or_default(),
                mailbox_ids: mailbox_ids_to_vec(&em.mailbox_ids),
            })
            .collect())
    }

    pub fn get_email(&self, id: String) -> PhpResult<JmapEmail> {
        let resp = self
            .jmap_request(vec![json!([
                "Email/get",
                {
                    "accountId": &self.account_id,
                    "ids": [&id],
                    "properties": [
                        "id", "blobId", "subject", "from", "to", "cc", "bcc", "replyTo",
                        "sentAt", "receivedAt", "size", "keywords", "mailboxIds",
                        "bodyValues", "textBody", "htmlBody", "hasAttachment", "attachments",
                        "inReplyTo", "messageId", "references",
                        "header:X-Priority:asText", "header:Importance:asText"
                    ],
                    "fetchTextBodyValues": true,
                    "fetchHTMLBodyValues": true,
                    "maxBodyValueBytes": 524288
                },
                "g0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "g0") {
            return Err(php_err(format!("Email/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "Email/get")
            .ok_or_else(|| php_err("Missing Email/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing email list"))?;

        if list.is_empty() {
            return Err(php_err("Email not found"));
        }

        let email: EmailFullGetData = serde_json::from_value(list[0].clone())
            .map_err(|e| php_err(format!("Email parse error: {}", e)))?;

        let text_body = extract_body_content(&email.text_body, &email.body_values);
        let html_body = extract_body_content(&email.html_body, &email.body_values);
        let att_json = attachments_to_json(&email.attachments);
        let att_count = email.attachments.as_ref().map_or(0, |a| a.len()) as i64;

        Ok(JmapEmail {
            id: email.id,
            blob_id: email.blob_id.unwrap_or_default(),
            subject: email.subject,
            from: format_address_list(&email.from),
            to: format_address_list(&email.to),
            cc: format_address_list(&email.cc),
            bcc: format_address_list(&email.bcc),
            reply_to: format_address_list(&email.reply_to),
            display_to: format_display_list(&email.to),
            display_cc: format_display_list(&email.cc),
            display_bcc: format_display_list(&email.bcc),
            date: email.sent_at,
            received_at: email.received_at.unwrap_or_default(),
            size: email.size as i64,
            keywords: keywords_to_vec(&email.keywords),
            text_body,
            html_body,
            has_attachment: email.has_attachment,
            in_reply_to: email.in_reply_to.map(|v| v.join(" ")),
            message_id: email.message_id.map(|v| v.join(" ")),
            references_header: email.references.map(|v| v.join(" ")),
            importance: parse_importance(&email.x_priority, &email.importance_header),
            attachments_json: att_json,
            attachment_count: att_count,
        })
    }

    /// Download blob to a file. Returns size in bytes.
    pub fn download_blob_to_file(&self, blob_id: String, output_path: String) -> PhpResult<i64> {
        let bytes = self.download_blob_internal(&blob_id).map_err(php_err)?;
        let size = bytes.len() as i64;
        std::fs::write(&output_path, &bytes)
            .map_err(|e| php_err(format!("File write error: {}", e)))?;
        Ok(size)
    }

    pub fn set_email_keywords(
        &self,
        id: String,
        keywords: HashMap<String, bool>,
    ) -> PhpResult<bool> {
        let mut keyword_patch = serde_json::Map::new();
        for (key, value) in &keywords {
            keyword_patch.insert(format!("keywords/{}", key), json!(value));
        }

        let mut update_map = serde_json::Map::new();
        update_map.insert(id.clone(), Value::Object(keyword_patch));

        let resp = self
            .jmap_request(vec![json!([
                "Email/set",
                {
                    "accountId": &self.account_id,
                    "update": Value::Object(update_map)
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("Email/set error: {}", err)));
        }

        let payload = find_method_response(&resp, "Email/set")
            .ok_or_else(|| php_err("Missing Email/set response"))?;

        if let Some(not_updated) = payload.get("notUpdated").and_then(Value::as_object) {
            if let Some(err) = not_updated.get(&id) {
                return Err(php_err(format!("Email update failed: {}", err)));
            }
        }

        Ok(true)
    }

    pub fn move_email(
        &self,
        id: String,
        from_mailbox: String,
        to_mailbox: String,
    ) -> PhpResult<bool> {
        let mut patch = serde_json::Map::new();
        patch.insert(format!("mailboxIds/{}", from_mailbox), Value::Null);
        patch.insert(format!("mailboxIds/{}", to_mailbox), json!(true));

        let mut update_map = serde_json::Map::new();
        update_map.insert(id.clone(), Value::Object(patch));

        let resp = self
            .jmap_request(vec![json!([
                "Email/set",
                {
                    "accountId": &self.account_id,
                    "update": Value::Object(update_map)
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("Email/set error: {}", err)));
        }

        let payload = find_method_response(&resp, "Email/set")
            .ok_or_else(|| php_err("Missing Email/set response"))?;

        if let Some(not_updated) = payload.get("notUpdated").and_then(Value::as_object) {
            if let Some(err) = not_updated.get(&id) {
                return Err(php_err(format!("Email move failed: {}", err)));
            }
        }

        Ok(true)
    }

    pub fn destroy_email(&self, id: String) -> PhpResult<bool> {
        let resp = self
            .jmap_request(vec![json!([
                "Email/set",
                {
                    "accountId": &self.account_id,
                    "destroy": [&id]
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("Email/set error: {}", err)));
        }

        Ok(true)
    }

    pub fn trash_email(&self, id: String, trash_mailbox_id: String) -> PhpResult<bool> {
        let meta = self.get_email_metadata(vec![id.clone()])?;
        if meta.is_empty() {
            return Err(php_err("Email not found"));
        }

        let mut patch = serde_json::Map::new();
        for mbox_id in &meta[0].mailbox_ids {
            patch.insert(format!("mailboxIds/{}", mbox_id), Value::Null);
        }
        patch.insert(format!("mailboxIds/{}", trash_mailbox_id), json!(true));

        let mut update_map = serde_json::Map::new();
        update_map.insert(id.clone(), Value::Object(patch));

        let resp = self
            .jmap_request(vec![json!([
                "Email/set",
                {
                    "accountId": &self.account_id,
                    "update": Value::Object(update_map)
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("Email/set error: {}", err)));
        }

        Ok(true)
    }

    pub fn send_email(
        &mut self,
        mime_file_path: String,
        sent_mailbox_id: String,
    ) -> PhpResult<bool> {
        // Get identity ID (cached after first fetch)
        let identity_id = if let Some(ref id) = self.identity_id {
            id.clone()
        } else {
            let id = self.fetch_identity_id().map_err(php_err)?;
            self.identity_id = Some(id.clone());
            id
        };

        // Read raw MIME from file
        let raw_mime = std::fs::read(&mime_file_path)
            .map_err(|e| php_err(format!("Read MIME file error: {}", e)))?;

        // Upload MIME as blob
        let blob_id = self
            .upload_blob_internal(&raw_mime, "message/rfc822")
            .map_err(php_err)?;

        // Build Email/import args with dynamic mailbox key
        let mut mbox_ids = serde_json::Map::new();
        mbox_ids.insert(sent_mailbox_id, json!(true));

        let mut email_obj = serde_json::Map::new();
        email_obj.insert("blobId".to_string(), json!(&blob_id));
        email_obj.insert("mailboxIds".to_string(), Value::Object(mbox_ids));
        email_obj.insert("keywords".to_string(), json!({"$seen": true}));

        let mut emails = serde_json::Map::new();
        emails.insert("k0".to_string(), Value::Object(email_obj));

        let mut import_args = serde_json::Map::new();
        import_args.insert("accountId".to_string(), json!(&self.account_id));
        import_args.insert("emails".to_string(), Value::Object(emails));

        // Batch: import + submit
        let resp = self
            .jmap_request(vec![
                json!(["Email/import", Value::Object(import_args), "i0"]),
                json!([
                    "EmailSubmission/set",
                    {
                        "accountId": &self.account_id,
                        "create": {
                            "s0": {
                                "emailId": "#k0",
                                "identityId": &identity_id
                            }
                        }
                    },
                    "s0"
                ]),
            ])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "i0") {
            return Err(php_err(format!("Email/import error: {}", err)));
        }
        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("EmailSubmission/set error: {}", err)));
        }

        // Check for import failures
        if let Some(payload) = find_method_response(&resp, "Email/import") {
            if let Some(not_created) = payload.get("notCreated").and_then(Value::as_object) {
                if let Some(err) = not_created.get("k0") {
                    return Err(php_err(format!("Email import failed: {}", err)));
                }
            }
        }

        // Check for submission failures
        if let Some(payload) = find_method_response(&resp, "EmailSubmission/set") {
            if let Some(not_created) = payload.get("notCreated").and_then(Value::as_object) {
                if let Some(err) = not_created.get("s0") {
                    return Err(php_err(format!("Email submission failed: {}", err)));
                }
            }
        }

        Ok(true)
    }

    pub fn get_email_changes(&self, since_state: String) -> PhpResult<JmapChanges> {
        let resp = self
            .jmap_request(vec![json!([
                "Email/changes",
                {
                    "accountId": &self.account_id,
                    "sinceState": &since_state
                },
                "c0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "c0") {
            return Err(php_err(format!("Email/changes error: {}", err)));
        }

        let payload = find_method_response(&resp, "Email/changes")
            .ok_or_else(|| php_err("Missing Email/changes response"))?;

        let changes: ChangesGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("Changes parse error: {}", e)))?;

        let has_changes = !changes.created.is_empty()
            || !changes.updated.is_empty()
            || !changes.destroyed.is_empty();

        Ok(JmapChanges {
            new_state: changes.new_state,
            has_changes,
            created: changes.created,
            updated: changes.updated,
            destroyed: changes.destroyed,
        })
    }

    pub fn get_mailbox_changes(&self, since_state: String) -> PhpResult<JmapChanges> {
        let resp = self
            .jmap_request(vec![json!([
                "Mailbox/changes",
                {
                    "accountId": &self.account_id,
                    "sinceState": &since_state
                },
                "c0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "c0") {
            return Err(php_err(format!("Mailbox/changes error: {}", err)));
        }

        let payload = find_method_response(&resp, "Mailbox/changes")
            .ok_or_else(|| php_err("Missing Mailbox/changes response"))?;

        let changes: ChangesGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("Changes parse error: {}", e)))?;

        let has_changes = !changes.created.is_empty()
            || !changes.updated.is_empty()
            || !changes.destroyed.is_empty();

        Ok(JmapChanges {
            new_state: changes.new_state,
            has_changes,
            created: changes.created,
            updated: changes.updated,
            destroyed: changes.destroyed,
        })
    }

    pub fn get_email_state(&self) -> PhpResult<String> {
        let resp = self
            .jmap_request(vec![json!([
                "Email/get",
                {
                    "accountId": &self.account_id,
                    "ids": [],
                    "properties": ["id"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        let payload = find_method_response(&resp, "Email/get")
            .ok_or_else(|| php_err("Missing Email/get response"))?;

        payload
            .get("state")
            .and_then(Value::as_str)
            .map(|s| s.to_string())
            .ok_or_else(|| php_err("Missing state in Email/get response"))
    }

    pub fn get_mailbox_state(&self) -> PhpResult<String> {
        let resp = self
            .jmap_request(vec![json!([
                "Mailbox/get",
                {
                    "accountId": &self.account_id,
                    "ids": [],
                    "properties": ["id"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        let payload = find_method_response(&resp, "Mailbox/get")
            .ok_or_else(|| php_err("Missing Mailbox/get response"))?;

        payload
            .get("state")
            .and_then(Value::as_str)
            .map(|s| s.to_string())
            .ok_or_else(|| php_err("Missing state in Mailbox/get response"))
    }

    // ========================================================
    // Contact methods
    // ========================================================

    pub fn get_address_books(&self) -> PhpResult<Vec<JmapAddressBook>> {
        let resp = self
            .jmap_request(vec![json!([
                "AddressBook/get",
                {"accountId": &self.account_id},
                "ab0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "ab0") {
            return Err(php_err(format!("AddressBook/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "AddressBook/get")
            .ok_or_else(|| php_err("Missing AddressBook/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing address book list"))?;

        let books: Vec<AddressBookGetData> = serde_json::from_value(Value::Array(list.clone()))
            .map_err(|e| php_err(format!("AddressBook parse error: {}", e)))?;

        Ok(books
            .into_iter()
            .map(|ab| JmapAddressBook {
                id: ab.id,
                name: ab.name,
                is_default: ab.is_default,
            })
            .collect())
    }

    pub fn query_contacts(
        &self,
        address_book_id: String,
        limit: i64,
        position: i64,
    ) -> PhpResult<JmapContactQueryResult> {
        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/query",
                {
                    "accountId": &self.account_id,
                    "filter": {"inAddressBook": address_book_id},
                    "limit": limit,
                    "position": position,
                    "calculateTotal": true
                },
                "q0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "q0") {
            return Err(php_err(format!("ContactCard/query error: {}", err)));
        }

        let payload = find_method_response(&resp, "ContactCard/query")
            .ok_or_else(|| php_err("Missing ContactCard/query response"))?;

        let query: ContactCardQueryGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("ContactCard/query parse error: {}", e)))?;

        Ok(JmapContactQueryResult {
            total: query.total.unwrap_or(query.ids.len() as u64) as i64,
            ids: query.ids,
        })
    }

    pub fn get_contact_metadata(&self, ids: Vec<String>) -> PhpResult<Vec<JmapContactMeta>> {
        if ids.is_empty() {
            return Ok(Vec::new());
        }

        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/get",
                {
                    "accountId": &self.account_id,
                    "ids": ids,
                    "properties": ["id", "addressBookIds"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "g0") {
            return Err(php_err(format!("ContactCard/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "ContactCard/get")
            .ok_or_else(|| php_err("Missing ContactCard/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing contact list"))?;

        let contacts: Vec<ContactCardMetaGetData> =
            serde_json::from_value(Value::Array(list.clone()))
                .map_err(|e| php_err(format!("Contact metadata parse error: {}", e)))?;

        Ok(contacts
            .into_iter()
            .map(|c| JmapContactMeta {
                id: c.id,
                address_book_ids: mailbox_ids_to_vec(&c.address_book_ids),
            })
            .collect())
    }

    pub fn get_contact(&self, id: String) -> PhpResult<JmapContact> {
        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/get",
                {
                    "accountId": &self.account_id,
                    "ids": [&id],
                    "properties": [
                        "id", "uid", "kind", "name", "emails", "phones",
                        "addresses", "anniversaries", "links", "organizations",
                        "titles", "nicknames", "notes", "addressBookIds"
                    ]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "g0") {
            return Err(php_err(format!("ContactCard/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "ContactCard/get")
            .ok_or_else(|| php_err("Missing ContactCard/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing contact list"))?;

        if list.is_empty() {
            return Err(php_err("Contact not found"));
        }

        let card: ContactCardGetData = serde_json::from_value(list[0].clone())
            .map_err(|e| php_err(format!("Contact parse error: {}", e)))?;

        Ok(contact_card_to_jmap_contact(card))
    }

    pub fn create_contact(&self, address_book_id: String, data_json: String) -> PhpResult<String> {
        let data: Value = serde_json::from_str(&data_json)
            .map_err(|e| php_err(format!("Invalid contact JSON: {}", e)))?;

        let mut create_obj = data.as_object().cloned().unwrap_or_default();
        create_obj.insert(
            "addressBookIds".to_string(),
            json!({&address_book_id: true}),
        );

        let mut create_map = serde_json::Map::new();
        create_map.insert("k0".to_string(), Value::Object(create_obj));

        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/set",
                {
                    "accountId": &self.account_id,
                    "create": Value::Object(create_map)
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("ContactCard/set error: {}", err)));
        }

        let payload = find_method_response(&resp, "ContactCard/set")
            .ok_or_else(|| php_err("Missing ContactCard/set response"))?;

        if let Some(not_created) = payload.get("notCreated").and_then(Value::as_object) {
            if let Some(err) = not_created.get("k0") {
                return Err(php_err(format!("Contact create failed: {}", err)));
            }
        }

        let created_id = payload
            .get("created")
            .and_then(|c| c.get("k0"))
            .and_then(|c| c.get("id"))
            .and_then(Value::as_str)
            .ok_or_else(|| php_err("Missing created contact ID"))?;

        Ok(created_id.to_string())
    }

    pub fn update_contact(&self, id: String, data_json: String) -> PhpResult<bool> {
        let data: Value = serde_json::from_str(&data_json)
            .map_err(|e| php_err(format!("Invalid contact JSON: {}", e)))?;

        let mut update_map = serde_json::Map::new();
        update_map.insert(id.clone(), data);

        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/set",
                {
                    "accountId": &self.account_id,
                    "update": Value::Object(update_map)
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("ContactCard/set error: {}", err)));
        }

        let payload = find_method_response(&resp, "ContactCard/set")
            .ok_or_else(|| php_err("Missing ContactCard/set response"))?;

        if let Some(not_updated) = payload.get("notUpdated").and_then(Value::as_object) {
            if let Some(err) = not_updated.get(&id) {
                return Err(php_err(format!("Contact update failed: {}", err)));
            }
        }

        Ok(true)
    }

    pub fn destroy_contact(&self, id: String) -> PhpResult<bool> {
        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/set",
                {
                    "accountId": &self.account_id,
                    "destroy": [&id]
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("ContactCard/set error: {}", err)));
        }

        Ok(true)
    }

    pub fn get_contact_state(&self) -> PhpResult<String> {
        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/get",
                {
                    "accountId": &self.account_id,
                    "ids": [],
                    "properties": ["id"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        let payload = find_method_response(&resp, "ContactCard/get")
            .ok_or_else(|| php_err("Missing ContactCard/get response"))?;

        payload
            .get("state")
            .and_then(Value::as_str)
            .map(|s| s.to_string())
            .ok_or_else(|| php_err("Missing state in ContactCard/get response"))
    }

    pub fn get_contact_changes(&self, since_state: String) -> PhpResult<JmapChanges> {
        let resp = self
            .jmap_request(vec![json!([
                "ContactCard/changes",
                {
                    "accountId": &self.account_id,
                    "sinceState": &since_state
                },
                "c0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "c0") {
            return Err(php_err(format!("ContactCard/changes error: {}", err)));
        }

        let payload = find_method_response(&resp, "ContactCard/changes")
            .ok_or_else(|| php_err("Missing ContactCard/changes response"))?;

        let changes: ChangesGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("Changes parse error: {}", e)))?;

        let has_changes = !changes.created.is_empty()
            || !changes.updated.is_empty()
            || !changes.destroyed.is_empty();

        Ok(JmapChanges {
            new_state: changes.new_state,
            has_changes,
            created: changes.created,
            updated: changes.updated,
            destroyed: changes.destroyed,
        })
    }

    // ========================================================
    // Calendar methods
    // ========================================================

    pub fn get_calendars(&self) -> PhpResult<Vec<JmapCalendar>> {
        let resp = self
            .jmap_request(vec![json!([
                "Calendar/get",
                {"accountId": &self.account_id},
                "cal0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "cal0") {
            return Err(php_err(format!("Calendar/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "Calendar/get")
            .ok_or_else(|| php_err("Missing Calendar/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing calendar list"))?;

        let calendars: Vec<CalendarGetData> = serde_json::from_value(Value::Array(list.clone()))
            .map_err(|e| php_err(format!("Calendar parse error: {}", e)))?;

        Ok(calendars
            .into_iter()
            .map(|c| JmapCalendar {
                id: c.id,
                name: c.name,
                is_default: c.is_default,
            })
            .collect())
    }

    pub fn query_calendar_events(
        &self,
        calendar_id: String,
        after: Option<String>,
        before: Option<String>,
        limit: i64,
        position: i64,
    ) -> PhpResult<JmapCalendarEventQueryResult> {
        let mut filter = json!({"inCalendar": calendar_id});
        if let Some(ref a) = after {
            filter["after"] = json!(a);
        }
        if let Some(ref b) = before {
            filter["before"] = json!(b);
        }

        let resp = self
            .jmap_request(vec![json!([
                "CalendarEvent/query",
                {
                    "accountId": &self.account_id,
                    "filter": filter,
                    "sort": [{"property": "start", "isAscending": true}],
                    "limit": limit,
                    "position": position,
                    "calculateTotal": true
                },
                "q0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "q0") {
            return Err(php_err(format!("CalendarEvent/query error: {}", err)));
        }

        let payload = find_method_response(&resp, "CalendarEvent/query")
            .ok_or_else(|| php_err("Missing CalendarEvent/query response"))?;

        let query: CalendarEventQueryGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("CalendarEvent/query parse error: {}", e)))?;

        Ok(JmapCalendarEventQueryResult {
            total: query.total.unwrap_or(query.ids.len() as u64) as i64,
            ids: query.ids,
        })
    }

    pub fn get_calendar_event_metadata(
        &self,
        ids: Vec<String>,
    ) -> PhpResult<Vec<JmapCalendarEventMeta>> {
        if ids.is_empty() {
            return Ok(Vec::new());
        }

        let resp = self
            .jmap_request(vec![json!([
                "CalendarEvent/get",
                {
                    "accountId": &self.account_id,
                    "ids": ids,
                    "properties": ["id", "@type", "uid", "calendarIds", "utcStart", "utcEnd"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "g0") {
            return Err(php_err(format!("CalendarEvent/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "CalendarEvent/get")
            .ok_or_else(|| php_err("Missing CalendarEvent/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing event list"))?;

        let events: Vec<CalendarEventMetaGetData> =
            serde_json::from_value(Value::Array(list.clone()))
                .map_err(|e| php_err(format!("Calendar event metadata parse error: {}", e)))?;

        Ok(events
            .into_iter()
            .map(|e| JmapCalendarEventMeta {
                id: e.id,
                object_type: e.object_type,
                uid: e.uid,
                calendar_ids: mailbox_ids_to_vec(&e.calendar_ids),
                utc_start: e.utc_start,
                utc_end: e.utc_end,
            })
            .collect())
    }

    pub fn get_tasks(&self, ids: Vec<String>) -> PhpResult<Vec<JmapTask>> {
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        let resp = self.jmap_request(vec![json!([
            "CalendarEvent/get", {
                "accountId": &self.account_id, "ids": ids,
                    "properties": ["id", "@type", "uid", "calendarIds", "title", "updated", "created",
                    "description", "descriptionContentType", "start", "due", "timeZone",
                    "showWithoutTime", "progress", "progressUpdated", "percentComplete",
                    "priority", "privacy", "alerts", "keywords", "recurrenceRules",
                    "excludedRecurrenceRules", "recurrenceOverrides", "participants"]
            }, "tasks"
        ])]).map_err(php_err)?;
        if let Some(err) = check_jmap_error(&resp, "tasks") {
            return Err(php_err(format!("Task read failed: {}", err)));
        }
        let payload = find_method_response(&resp, "CalendarEvent/get")
            .ok_or_else(|| php_err("Missing task response"))?;
        let list = payload.get("list").and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing task list"))?;
        list.iter().filter(|v| v.get("@type").and_then(Value::as_str) == Some("Task"))
            .map(|v| Ok(JmapTask {
                id: v.get("id").and_then(Value::as_str)
                    .ok_or_else(|| php_err("Missing task ID"))?.to_string(),
                data_json: v.to_string(),
            })).collect()
    }

    pub fn get_calendar_event(&self, id: String) -> PhpResult<JmapCalendarEvent> {
        let resp = self
            .jmap_request(vec![json!([
                "CalendarEvent/get",
                {
                    "accountId": &self.account_id,
                    "ids": [&id],
                    "properties": [
                        "id", "uid", "title", "description", "location", "status",
                        "start", "timeZone", "utcStart", "utcEnd", "duration",
                        "showWithoutTime", "freeBusyStatus", "privacy",
                        "alerts", "participants", "recurrenceRules",
                        "excludedRecurrenceRules", "recurrenceOverrides",
                        "calendarIds"
                    ]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "g0") {
            return Err(php_err(format!("CalendarEvent/get error: {}", err)));
        }

        let payload = find_method_response(&resp, "CalendarEvent/get")
            .ok_or_else(|| php_err("Missing CalendarEvent/get response"))?;

        let list = payload
            .get("list")
            .and_then(Value::as_array)
            .ok_or_else(|| php_err("Missing event list"))?;

        if list.is_empty() {
            return Err(php_err("Calendar event not found"));
        }

        let event: CalendarEventFullGetData = serde_json::from_value(list[0].clone())
            .map_err(|e| php_err(format!("Calendar event parse error: {}", e)))?;

        Ok(calendar_event_to_jmap(event))
    }

    pub fn create_calendar_event(
        &self,
        calendar_id: String,
        data_json: String,
    ) -> PhpResult<String> {
        let data: Value = serde_json::from_str(&data_json)
            .map_err(|e| php_err(format!("Invalid event JSON: {}", e)))?;

        let mut create_obj = data.as_object().cloned().unwrap_or_default();
        create_obj.insert("calendarIds".to_string(), json!({&calendar_id: true}));

        let mut create_map = serde_json::Map::new();
        create_map.insert("k0".to_string(), Value::Object(create_obj));

        let resp = self
            .calendar_set_request(json!({
                "accountId": &self.account_id,
                "create": Value::Object(create_map)
            }))
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("CalendarEvent/set error: {}", err)));
        }

        let payload = find_method_response(&resp, "CalendarEvent/set")
            .ok_or_else(|| php_err("Missing CalendarEvent/set response"))?;

        if let Some(not_created) = payload.get("notCreated").and_then(Value::as_object) {
            if let Some(err) = not_created.get("k0") {
                return Err(php_err(format!("Event create failed: {}", err)));
            }
        }

        let created_id = payload
            .get("created")
            .and_then(|c| c.get("k0"))
            .and_then(|c| c.get("id"))
            .and_then(Value::as_str)
            .ok_or_else(|| php_err("Missing created event ID"))?;

        Ok(created_id.to_string())
    }

    pub fn update_calendar_event(&self, id: String, data_json: String) -> PhpResult<bool> {
        let data: Value = serde_json::from_str(&data_json)
            .map_err(|e| php_err(format!("Invalid event JSON: {}", e)))?;

        let mut update_map = serde_json::Map::new();
        update_map.insert(id.clone(), data);

        let resp = self
            .calendar_set_request(json!({
                "accountId": &self.account_id,
                "update": Value::Object(update_map)
            }))
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("CalendarEvent/set error: {}", err)));
        }

        let payload = find_method_response(&resp, "CalendarEvent/set")
            .ok_or_else(|| php_err("Missing CalendarEvent/set response"))?;

        if let Some(not_updated) = payload.get("notUpdated").and_then(Value::as_object) {
            if let Some(err) = not_updated.get(&id) {
                return Err(php_err(format!("Event update failed: {}", err)));
            }
        }

        if !payload.get("updated").and_then(Value::as_object)
            .map_or(false, |updated| updated.contains_key(&id)) {
            return Err(php_err("Calendar item update was not confirmed"));
        }

        Ok(true)
    }

    pub fn destroy_calendar_event(&self, id: String) -> PhpResult<bool> {
        let resp = self
            .jmap_request(vec![json!([
                "CalendarEvent/set",
                {
                    "accountId": &self.account_id,
                    "destroy": [&id]
                },
                "s0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "s0") {
            return Err(php_err(format!("CalendarEvent/set error: {}", err)));
        }
        let payload = find_method_response(&resp, "CalendarEvent/set")
            .ok_or_else(|| php_err("Missing CalendarEvent/set response"))?;
        if let Some(err) = payload.get("notDestroyed").and_then(|v| v.get(&id)) {
            return Err(php_err(format!("Calendar item deletion failed: {}", err)));
        }
        if !payload.get("destroyed").and_then(Value::as_array)
            .map_or(false, |items| items.iter().any(|item| item.as_str() == Some(&id))) {
            return Err(php_err("Calendar item deletion was not confirmed"));
        }
        Ok(true)
    }

    pub fn get_calendar_event_state(&self) -> PhpResult<String> {
        let resp = self
            .jmap_request(vec![json!([
                "CalendarEvent/get",
                {
                    "accountId": &self.account_id,
                    "ids": [],
                    "properties": ["id"]
                },
                "g0"
            ])])
            .map_err(php_err)?;

        let payload = find_method_response(&resp, "CalendarEvent/get")
            .ok_or_else(|| php_err("Missing CalendarEvent/get response"))?;

        payload
            .get("state")
            .and_then(Value::as_str)
            .map(|s| s.to_string())
            .ok_or_else(|| php_err("Missing state in CalendarEvent/get response"))
    }

    pub fn get_calendar_event_changes(&self, since_state: String) -> PhpResult<JmapChanges> {
        let resp = self
            .jmap_request(vec![json!([
                "CalendarEvent/changes",
                {
                    "accountId": &self.account_id,
                    "sinceState": &since_state
                },
                "c0"
            ])])
            .map_err(php_err)?;

        if let Some(err) = check_jmap_error(&resp, "c0") {
            return Err(php_err(format!("CalendarEvent/changes error: {}", err)));
        }

        let payload = find_method_response(&resp, "CalendarEvent/changes")
            .ok_or_else(|| php_err("Missing CalendarEvent/changes response"))?;

        let changes: ChangesGetData = serde_json::from_value(payload.clone())
            .map_err(|e| php_err(format!("Changes parse error: {}", e)))?;

        let has_changes = !changes.created.is_empty()
            || !changes.updated.is_empty()
            || !changes.destroyed.is_empty();

        Ok(JmapChanges {
            new_state: changes.new_state,
            has_changes,
            created: changes.created,
            updated: changes.updated,
            destroyed: changes.destroyed,
        })
    }
}

// ============================================================
// Standalone PHP functions
// ============================================================

#[php_function]
pub fn abq_evaluate_device(
    device_id: Option<String>,
    device_type: Option<String>,
    user_agent: Option<String>,
    allowed_rules: Vec<String>,
    blocked_rules: Vec<String>,
    quarantined_rules: Vec<String>,
    quarantine_by_default: bool,
) -> PhpResult<AbqDecision> {
    let device_id = device_id.unwrap_or_default();
    let device_type = device_type.unwrap_or_default();
    let user_agent = user_agent.unwrap_or_default();

    let allowed_rules = normalize_rule_list(allowed_rules);
    let blocked_rules = normalize_rule_list(blocked_rules);
    let quarantined_rules = normalize_rule_list(quarantined_rules);

    let block_match = first_matching_rule(&blocked_rules, &device_id, &device_type, &user_agent);
    if let Some((rule, field)) = block_match {
        return Ok(AbqDecision {
            action: "block".to_string(),
            matched_rule: Some(rule),
            matched_field: Some(field),
        });
    }

    let allow_match = first_matching_rule(&allowed_rules, &device_id, &device_type, &user_agent);
    let quarantine_match =
        first_matching_rule(&quarantined_rules, &device_id, &device_type, &user_agent);

    if !allowed_rules.is_empty() {
        if let Some((rule, field)) = allow_match {
            return Ok(AbqDecision {
                action: "allow".to_string(),
                matched_rule: Some(rule),
                matched_field: Some(field),
            });
        }

        if let Some((rule, field)) = quarantine_match {
            return Ok(AbqDecision {
                action: "quarantine".to_string(),
                matched_rule: Some(rule),
                matched_field: Some(field),
            });
        }

        if quarantine_by_default {
            return Ok(AbqDecision {
                action: "quarantine".to_string(),
                matched_rule: Some("default".to_string()),
                matched_field: Some("default".to_string()),
            });
        }

        return Ok(AbqDecision {
            action: "block".to_string(),
            matched_rule: Some("default".to_string()),
            matched_field: Some("default".to_string()),
        });
    }

    if let Some((rule, field)) = allow_match {
        return Ok(AbqDecision {
            action: "allow".to_string(),
            matched_rule: Some(rule),
            matched_field: Some(field),
        });
    }

    if let Some((rule, field)) = quarantine_match {
        return Ok(AbqDecision {
            action: "quarantine".to_string(),
            matched_rule: Some(rule),
            matched_field: Some(field),
        });
    }

    if quarantine_by_default {
        return Ok(AbqDecision {
            action: "quarantine".to_string(),
            matched_rule: Some("default".to_string()),
            matched_field: Some("default".to_string()),
        });
    }

    Ok(AbqDecision {
        action: "allow".to_string(),
        matched_rule: None,
        matched_field: None,
    })
}

#[php_function]
pub fn jmap_connect(base_url: String, username: String, password: String) -> PhpResult<JmapClient> {
    let password = Zeroizing::new(password);
    let trimmed_base_url = base_url.trim_end_matches('/').to_string();
    let parsed_base_url = Url::parse(&trimmed_base_url)
        .map_err(|e| php_err(format!("Invalid STALWART_URL '{}': {}", trimmed_base_url, e)))?;

    let allow_insecure_http = env_bool("STALWART_ALLOW_INSECURE_HTTP", false);
    validate_base_url_security(&parsed_base_url, allow_insecure_http).map_err(php_err)?;

    let verify_peer = env_bool("STALWART_SSL_VERIFYPEER", true);
    let verify_host = env_bool("STALWART_SSL_VERIFYHOST", true);
    let connect_timeout_secs = env_u64("STALWART_CONNECT_TIMEOUT_SECS", 10, 1, 300);
    let request_timeout_secs = env_u64("STALWART_REQUEST_TIMEOUT_SECS", 60, 1, 3600);
    let blob_timeout_secs = env_u64("STALWART_BLOB_TIMEOUT_SECS", 180, 5, 7200);

    let mut client_builder = Client::builder()
        .connect_timeout(Duration::from_secs(connect_timeout_secs))
        .timeout(Duration::from_secs(request_timeout_secs));

    if !verify_peer || !verify_host {
        client_builder = client_builder.danger_accept_invalid_certs(true);
    }

    let client = client_builder
        .build()
        .map_err(|e| php_err(format!("Failed to create HTTP client: {}", e)))?;

    let session_url = format!("{}/.well-known/jmap", trimmed_base_url);

    let resp = client
        .get(&session_url)
        .basic_auth(&username, Some(password.as_str()))
        .header("Accept", "application/json")
        .send()
        .map_err(|e| php_err(format!("Session fetch failed: {}", e)))?;

    if !resp.status().is_success() {
        return Err(php_err(format!(
            "Authentication failed: HTTP {}",
            resp.status()
        )));
    }

    let session: SessionResource = resp
        .json()
        .map_err(|e| php_err(format!("Session parse error: {}", e)))?;

    let account_id = session
        .primary_accounts
        .get("urn:ietf:params:jmap:mail")
        .ok_or_else(|| php_err("No primary mail account found"))?
        .clone();

    let api_url = resolve_url(&trimmed_base_url, &session.api_url);
    let download_url = resolve_url(&trimmed_base_url, &session.download_url);
    let upload_url = resolve_url(&trimmed_base_url, &session.upload_url);
    let push_event_source_url = session
        .event_source_url
        .as_ref()
        .map(|url| url.trim())
        .filter(|url| !url.is_empty())
        .map(|url| resolve_url(&trimmed_base_url, url));

    validate_resolved_url(&parsed_base_url, &api_url, "apiUrl").map_err(php_err)?;
    validate_resolved_url(&parsed_base_url, &download_url, "downloadUrl").map_err(php_err)?;
    validate_resolved_url(&parsed_base_url, &upload_url, "uploadUrl").map_err(php_err)?;
    if let Some(event_source_url) = &push_event_source_url {
        validate_resolved_url(&parsed_base_url, event_source_url, "eventSourceUrl").map_err(php_err)?;
    }

    Ok(JmapClient {
        client,
        api_url,
        download_url,
        upload_url,
        push_event_source_url,
        account_id,
        username,
        password,
        identity_id: None,
        request_timeout_secs,
        blob_timeout_secs,
    })
}

#[cfg(test)]
mod tests {
    use super::{
        abq_evaluate_device, abq_match_rule, abq_pattern_matches, abq_regex_cache_stats,
        wildcard_match_case_insensitive, ABQ_MAX_REGEX_PATTERN_LEN,
    };

    #[test]
    fn wildcard_match_works() {
        assert!(wildcard_match_case_insensitive("*iphone*", "Apple-iPhone16,2"));
        assert!(wildcard_match_case_insensitive("sm-??-ultra", "SM-S24-ULTRA"));
        assert!(!wildcard_match_case_insensitive("ipad*", "android"));
    }

    #[test]
    fn regex_and_wildcard_patterns_work() {
        assert!(abq_pattern_matches("re:^iphone\\d+$", "iPhone16"));
        assert!(abq_pattern_matches("*galaxy*", "Samsung Galaxy S24"));
        assert!(!abq_pattern_matches("re:^iphone\\d+$", "Pixel9"));
    }

    #[test]
    fn regex_cache_stats_report_activity() {
        let before = abq_regex_cache_stats().expect("abq_regex_cache_stats should succeed");

        assert!(abq_pattern_matches("re:^iphone\\d+$", "iPhone16"));
        assert!(abq_pattern_matches("re:^iphone\\d+$", "iPhone17"));

        let after = abq_regex_cache_stats().expect("abq_regex_cache_stats should succeed");

        let before_total = before.hits + before.misses;
        let after_total = after.hits + after.misses;

        assert!(after_total >= before_total + 2);
        assert!(after.hits >= before.hits);
    }

    #[test]
    fn regex_length_limit_is_enforced() {
        let oversized = format!("re:{}", "a".repeat(ABQ_MAX_REGEX_PATTERN_LEN + 1));
        assert!(!abq_pattern_matches(&oversized, "aaaa"));

        let allowed = format!("re:{}", "a".repeat(ABQ_MAX_REGEX_PATTERN_LEN));
        let candidate = "a".repeat(ABQ_MAX_REGEX_PATTERN_LEN);
        assert!(abq_pattern_matches(&allowed, &candidate));
    }

    #[test]
    fn prefixed_rule_targets_expected_field() {
        let hit = abq_match_rule(
            "ua:*ios*",
            "abc123",
            "iphone",
            "Apple-iOS/18.0",
        );
        assert_eq!(hit.as_deref(), Some("user_agent"));

        let miss = abq_match_rule(
            "id:corp-*",
            "abc123",
            "iphone",
            "Apple-iOS/18.0",
        );
        assert!(miss.is_none());
    }

    #[test]
    fn blocked_rule_takes_priority_over_allow_rule() {
        let decision = abq_evaluate_device(
            Some("corp-iphone-01".to_string()),
            Some("iphone".to_string()),
            Some("Apple-iOS/18.0".to_string()),
            vec!["id:corp-*".to_string()],
            vec!["ua:*ios*".to_string()],
            Vec::new(),
            false,
        )
        .expect("abq_evaluate_device should succeed");

        assert_eq!(decision.action, "block");
        assert_eq!(decision.matched_field.as_deref(), Some("user_agent"));
    }

    #[test]
    fn allowlist_blocks_non_matching_device_by_default() {
        let decision = abq_evaluate_device(
            Some("pixel-01".to_string()),
            Some("android".to_string()),
            Some("Android/15".to_string()),
            vec!["dt:iphone".to_string()],
            Vec::new(),
            Vec::new(),
            false,
        )
        .expect("abq_evaluate_device should succeed");

        assert_eq!(decision.action, "block");
        assert_eq!(decision.matched_rule.as_deref(), Some("default"));
    }

    #[test]
    fn allowlist_can_quarantine_non_matching_device() {
        let decision = abq_evaluate_device(
            Some("pixel-01".to_string()),
            Some("android".to_string()),
            Some("Android/15".to_string()),
            vec!["dt:iphone".to_string()],
            Vec::new(),
            Vec::new(),
            true,
        )
        .expect("abq_evaluate_device should succeed");

        assert_eq!(decision.action, "quarantine");
        assert_eq!(decision.matched_rule.as_deref(), Some("default"));
    }
}

// ============================================================
// Module registration
// ============================================================

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
