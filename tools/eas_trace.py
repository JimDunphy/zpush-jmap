#!/usr/bin/env python3
"""Summarize body-only ActiveSync traces without printing mail or credentials.

Input: chronological JSON/JSONL records with command, utc, http_status,
request_body_base64 and response_body_base64. Historical response-only records
with body_base64 are also accepted, but cannot establish acknowledgment.
SyncKey reuse establishes protocol receipt, not successful application or UI state.
"""
import argparse
import base64
from dataclasses import dataclass, field
import hashlib
import json
from pathlib import Path
import re

MAX_BODY = 16 * 1024 * 1024
NAMES = {
    0: dict(enumerate(('Sync', 'Responses', 'Add', 'Change', 'Delete', 'Fetch',
                       'SyncKey', 'ClientId', 'ServerId', 'Status', 'Collection',
                       'Class', 'Version', 'CollectionId', 'GetChanges', 'MoreAvailable',
                       'WindowSize', 'Commands', 'Options', 'FilterType', 'Truncation',
                       'RtfTruncation', 'Conflict', 'Collections', 'ApplicationData',
                       'DeletesAsMoves', 'NotifyGUID', 'Supported', 'SoftDelete',
                       'MIMESupport', 'MIMETruncation', 'Wait', 'Limit', 'Partial',
                       'ConversationMode', 'MaxItems', 'HeartbeatInterval'), 5)),
    7: dict(enumerate(('Folders', 'Folder', 'DisplayName', 'ServerId', 'ParentId',
                       'Type', 'Response', 'Status', 'ContentClass', 'Changes', 'Add',
                       'Delete', 'Update', 'SyncKey', 'FolderCreate', 'FolderDelete',
                       'FolderUpdate', 'FolderSync', 'Count', 'Version'), 5)),
    13: {5: 'Ping', 7: 'Status', 8: 'HeartbeatInterval', 9: 'Folders', 10: 'Folder',
         11: 'Id', 12: 'Class'},
    14: {5: 'Provision', 6: 'Policies', 7: 'Policy', 8: 'PolicyType', 9: 'PolicyKey',
         10: 'Data', 11: 'Status'},
    17: {5: 'BodyPreference', 6: 'Type', 7: 'TruncationSize'},
    18: {8: 'Set', 22: 'DeviceInformation', 23: 'Model', 26: 'OS'},
}
PAGES = {0: 'AirSync', 7: 'FolderHierarchy', 13: 'Ping', 14: 'Provision',
         17: 'AirSyncBase', 18: 'Settings'}
TEXT_FIELDS = {'AirSync:' + name for name in ('SyncKey', 'CollectionId', 'Status', 'ServerId')}
TEXT_FIELDS |= {'FolderHierarchy:Status', 'Provision:PolicyKey', 'Provision:Status',
                'Ping:Status', 'Ping:Folder'}


@dataclass
class Node:
    tag: str
    text: str = ''
    children: list = field(default_factory=list)

    def all(self, tag):
        return [child for child in self.children if child.tag == tag]

    def value(self, tag):
        nodes = self.all(tag)
        return nodes[0].text if nodes else None


def decode(data):
    """Decode bounded WBXML structure; unneeded code pages retain numeric names."""
    if not data:
        return None
    if len(data) > MAX_BODY:
        raise ValueError('WBXML body exceeds limit')
    pos = 0

    def byte():
        nonlocal pos
        if pos >= len(data):
            raise ValueError('Truncated WBXML')
        result = data[pos]
        pos += 1
        return result

    def integer():
        result = 0
        for _ in range(5):
            value = byte()
            result = (result << 7) | (value & 127)
            if not value & 128:
                return result
        raise ValueError('Oversized WBXML integer')

    def take(length):
        nonlocal pos
        if length > len(data) - pos:
            raise ValueError('Truncated WBXML content')
        result = data[pos:pos + length]
        pos += length
        return result

    def string():
        nonlocal pos
        end = data.find(b'\0', pos)
        if end < 0:
            raise ValueError('Unterminated WBXML string')
        result = data[pos:end].decode('utf-8', errors='replace')
        pos = end + 1
        return result

    if byte() != 3 or integer() != 1 or integer() != 106:
        raise ValueError('Unsupported WBXML header')
    table = take(integer())
    stack, root, page, count, tokens = [], None, 0, 0, 0
    while pos < len(data):
        tokens += 1
        if tokens > 200000:
            raise ValueError('WBXML token limit exceeded')
        token = byte()
        if token == 0:
            page = byte()
            continue
        if token == 1:
            if not stack:
                raise ValueError('Unmatched WBXML end tag')
            stack.pop()
            continue
        if token in (2, 3, 0x83, 0xC3):
            if not stack:
                raise ValueError('WBXML content outside root')
            if token == 3:
                value = string()
            elif token == 0xC3:
                value = take(integer()).decode('utf-8', errors='replace')
            elif token == 2:
                value = chr(integer())
            else:
                offset = integer()
                end = table.find(b'\0', offset)
                if offset >= len(table) or end < 0:
                    raise ValueError('Invalid WBXML string-table reference')
                value = table[offset:end].decode('utf-8', errors='replace')
            # Retain only bounded protocol metadata, never subjects, addresses,
            # message bodies, policy contents or other application data.
            if stack[-1].tag in TEXT_FIELDS:
                if len(stack[-1].text) + len(value) > 512:
                    raise ValueError('Oversized WBXML metadata')
                stack[-1].text += value
            continue
        if token & 0x80 or (token & 63) < 5:
            raise ValueError('Unsupported WBXML token or attributes')
        count += 1
        if count > 100000 or len(stack) >= 64:
            raise ValueError('WBXML structure exceeds limit')
        local = NAMES.get(page, {}).get(token & 63, str(token & 63))
        node = Node(PAGES.get(page, 'page' + str(page)) + ':' + local)
        if stack:
            stack[-1].children.append(node)
        elif root is None:
            root = node
        else:
            raise ValueError('Multiple WBXML roots')
        if token & 64:
            stack.append(node)
    if stack or root is None:
        raise ValueError('Incomplete WBXML document')
    return root


def pseudonym(value):
    return hashlib.sha256(value.encode()).hexdigest()[:16] if value is not None else None


def collections(root):
    if root is None or root.tag != 'AirSync:Sync':
        return []
    return [folder for group in root.all('AirSync:Collections') for folder in group.all('AirSync:Collection')]


def summarize(records):
    sent = {}
    output = []
    for record in records:
        summary = {}
        command = record.get('command')
        if command in ('Sync', 'Ping', 'FolderSync', 'Provision'):
            summary['command'] = command
        timestamp = record.get('utc', '')
        if isinstance(timestamp, str) and re.fullmatch(r'[0-9T:.+Z-]{19,40}', timestamp):
            summary['utc'] = timestamp
        status = record.get('http_status')
        if isinstance(status, int) and 100 <= status <= 599:
            summary['http_status'] = status

        def body(key, fallback=None):
            value = record.get(key, record.get(fallback, '') if fallback else '')
            if not isinstance(value, str) or len(value) > (MAX_BODY + 2) // 3 * 4:
                raise ValueError('Invalid or oversized base64 body')
            return decode(base64.b64decode(value, validate=True))

        try:
            request = body('request_body_base64')
            response = body('response_body_base64', 'body_base64')
            summary['response_type'] = response.tag if response else 'empty'
            requested = collections(request)
            summary['requests'] = []
            for folder in requested:
                identity, key = folder.value('AirSync:CollectionId'), folder.value('AirSync:SyncKey')
                prior = sent.get((identity, key))
                summary['requests'].append({'collection': pseudonym(identity), 'sync_key': pseudonym(key),
                                            'acknowledges_previous_response': prior is not None,
                                            'acknowledged_delete_ids': prior or []})
            summary['responses'] = []
            for folder in collections(response):
                identity, key = folder.value('AirSync:CollectionId'), folder.value('AirSync:SyncKey')
                commands = [{'kind': command.tag.split(':')[-1],
                             'server_id': pseudonym(command.value('AirSync:ServerId'))}
                            for group in folder.all('AirSync:Commands') for command in group.children]
                summary['responses'].append({'collection': pseudonym(identity), 'sync_key': pseudonym(key),
                                             'status': folder.value('AirSync:Status'), 'commands': commands})
                if key and folder.value('AirSync:Status') == '1':
                    sent[(identity, key)] = [c['server_id'] for c in commands if c['kind'] in ('Delete', 'SoftDelete')]
            if response and response.tag == 'Ping:Ping':
                summary['ping_status'] = response.value('Ping:Status')
                summary['ping_folders'] = [pseudonym(folder.text) for group in response.all('Ping:Folders')
                                           for folder in group.all('Ping:Folder')]
            if not requested:
                summary['acknowledgment_evidence'] = 'No explicit request SyncKey; receipt cannot be established here'
        except (ValueError, UnicodeError) as error:
            summary['decode_error'] = str(error)
        output.append(summary)
    return output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('trace', type=Path)
    args = parser.parse_args()
    if args.trace.stat().st_size > 64 * 1024 * 1024:
        raise SystemExit('Trace exceeds 64 MiB; split it into bounded sessions')
    text = args.trace.read_text()
    try:
        records = json.loads(text)
        if isinstance(records, dict):
            records = [records]
    except json.JSONDecodeError:
        records = [json.loads(line) for line in text.splitlines() if line.strip()]
    print(json.dumps(summarize(records), indent=2))


if __name__ == '__main__':
    main()
