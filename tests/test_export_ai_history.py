import unittest

import json
import sys
import tempfile
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

    def test_codex_external_import_exports_claude_messages(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            codex_home = root / ".codex"
            codex_home.mkdir()
            claude_file = root / ".claude" / "projects" / "project-1" / "session-1.jsonl"
            claude_file.parent.mkdir(parents=True)
            self.write_jsonl(claude_file, [
                {
                    "type": "system",
                    "timestamp": "2026-05-01T00:00:00.000Z",
                    "cwd": "G:\\dnmp\\dnmp\\www\\aiHistory",
                    "message": {"role": "system", "content": "system note"},
                },
                {
                    "type": "user",
                    "timestamp": "2026-05-01T00:01:00.000Z",
                    "cwd": "G:\\dnmp\\dnmp\\www\\aiHistory",
                    "message": {"role": "user", "content": "请修复 Codex 导入"},
                },
                {
                    "type": "assistant",
                    "timestamp": "2026-05-01T00:02:00.000Z",
                    "message": {
                        "role": "assistant",
                        "model": "claude-sonnet-4",
                        "content": [{"type": "text", "text": "已经修复"}],
                    },
                },
            ])
            (codex_home / "external_agent_session_imports.json").write_text(json.dumps([
                {
                    "source_path": str(claude_file),
                    "content_sha256": "abc123",
                    "imported_thread_id": "thread-1",
                    "imported_at": 1778734091,
                }
            ]), encoding="utf-8")

            sessions = list(export_ai_history.export_codex(codex_home))

        self.assertEqual(1, len(sessions))
        session = sessions[0]
        self.assertEqual("codex", session["source"])
        self.assertEqual("external:thread-1", session["external_id"])
        self.assertEqual("请修复 Codex 导入", session["title"])
        self.assertEqual("G:\\dnmp\\dnmp\\www\\aiHistory", session["workspace_path"])
        self.assertEqual("claude-sonnet-4", session["model"])
        self.assertEqual("claude_external_import", session["metadata"]["origin"])
        self.assertEqual("thread-1", session["metadata"]["imported_thread_id"])
        self.assertEqual(["system", "user", "assistant"], [message["role"] for message in session["messages"]])
        self.assertEqual("已经修复", session["messages"][2]["content"])

    def test_codex_external_import_keeps_parse_errors(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            codex_home = root / ".codex"
            codex_home.mkdir()
            claude_file = root / "bad.jsonl"
            claude_file.write_text(
                '{"type":"user","timestamp":"2026-05-01T00:00:00.000Z","message":{"role":"user","content":"hello"}}\n'
                '{bad json}\n',
                encoding="utf-8",
            )
            (codex_home / "external_agent_session_imports.json").write_text(json.dumps([
                {
                    "source_path": str(claude_file),
                    "content_sha256": "abc123",
                    "imported_thread_id": "thread-2",
                    "imported_at": 1778734092,
                }
            ]), encoding="utf-8")

            session = list(export_ai_history.export_codex(codex_home))[0]

        self.assertEqual(1, len(session["messages"]))
        self.assertEqual(1, len(session["metadata"]["parse_errors"]))
        self.assertEqual(2, session["metadata"]["parse_errors"][0]["line"])

    def write_jsonl(self, path, rows):
        path.write_text(
            "\n".join(json.dumps(row, ensure_ascii=False) for row in rows) + "\n",
            encoding="utf-8",
        )


if __name__ == "__main__":
    unittest.main()
