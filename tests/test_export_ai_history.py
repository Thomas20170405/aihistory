import unittest

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))

import export_ai_history


class ExportAiHistoryTest(unittest.TestCase):
    def test_cursor_session_without_messages_is_empty(self):
        data = {
            "composerId": "composer-1",
            "text": "",
            "fullConversationHeadersOnly": [],
        }
        header = {
            "composerId": "composer-1",
            "name": None,
        }

        self.assertTrue(export_ai_history.is_empty_cursor_session(data, header, []))

    def test_named_cursor_session_without_messages_is_empty(self):
        data = {
            "composerId": "composer-1",
            "text": "",
            "fullConversationHeadersOnly": [],
        }
        header = {
            "composerId": "composer-1",
            "name": "Named but no messages",
        }

        self.assertTrue(export_ai_history.is_empty_cursor_session(data, header, []))

    def test_cursor_session_with_message_is_not_empty(self):
        data = {
            "composerId": "composer-1",
            "text": "",
            "fullConversationHeadersOnly": [{"bubbleId": "bubble-1"}],
        }

        self.assertFalse(export_ai_history.is_empty_cursor_session(data, {}, [{"seq": 0}]))


if __name__ == "__main__":
    unittest.main()
