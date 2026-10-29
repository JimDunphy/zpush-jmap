#!/usr/bin/env python3
"""Diagnostic correctness, acknowledgment boundaries and privacy regressions."""
import base64
import json
import unittest
from test_eas_lifecycle import encode, trace


def sync(key, command=None):
    fields = [('AirSync:SyncKey', key), ('AirSync:CollectionId', 'dummy-inbox'), ('AirSync:Status', '1')]
    if command:
        fields.append(('AirSync:Commands', [(command, [('AirSync:ServerId', 'dummy-mail')])]))
    return ('AirSync:Sync', [('AirSync:Collections', [('AirSync:Collection', fields)])])


def record(request=None, response=None):
    return {'request_body_base64': base64.b64encode(encode(request)).decode() if request else '',
            'response_body_base64': base64.b64encode(encode(response)).decode() if response else ''}


class TraceTests(unittest.TestCase):
    def test_only_explicit_request_key_can_acknowledge_a_prior_delete(self):
        sent = record(sync('old'), sync('new', 'AirSync:Delete'))
        replay = record(sync('old'), sync('new', 'AirSync:Delete'))
        ack = record(sync('new'), sync('new'))
        output = trace.summarize([sent, replay, ack])
        self.assertFalse(output[1]['requests'][0]['acknowledges_previous_response'])
        self.assertEqual(output[2]['requests'][0]['acknowledged_delete_ids'], [trace.pseudonym('dummy-mail')])

    def test_response_only_and_ping_do_not_establish_acknowledgment(self):
        sent = record(response=sync('new', 'AirSync:Delete'))
        later = record(response=sync('new'))
        ping = record(response=('Ping:Ping', [('Ping:Status', '2')]))
        for item in trace.summarize([sent, later, ping]):
            self.assertEqual(item['requests'], [])
            self.assertIn('acknowledgment_evidence', item)

    def test_application_content_and_arbitrary_metadata_are_not_printed(self):
        canary = 'private-canary@example.test'
        tree = sync('new', 'AirSync:Add')
        tree[1][0][1][0][1][-1][1][0][1].append(('AirSync:ApplicationData', [('AirSync:ClientId', canary)]))
        item = record(response=tree)
        item.update(command=canary, utc=canary, http_status=canary, Authorization=canary)
        rendered = json.dumps(trace.summarize([item]))
        self.assertNotIn(canary, rendered)
        self.assertNotIn('dummy-mail', rendered)
        self.assertNotIn('dummy-inbox', rendered)

    def test_malformed_and_oversized_metadata_fail_without_echoing_payload(self):
        for data in (b'not-wbxml', b'\x03\x01\x6a\x00\x45',
                     encode(sync('x' * 513))):
            output = trace.summarize([{'response_body_base64': base64.b64encode(data).decode()}])
            self.assertIn('decode_error', output[0])
            self.assertNotIn('x' * 513, json.dumps(output))


if __name__ == '__main__':
    unittest.main()
