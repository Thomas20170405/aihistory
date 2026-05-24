#!/usr/bin/env python3
"""Export local Codex and Cursor AI histories to unified JSONL.

This script is intended to run on the Windows host, not inside the PHP
container. It uses only the Python standard library.
"""

import argparse
import glob
import json
import os
import sqlite3
from datetime import datetime, timezone
from pathlib import Path


DEFAULT_CODEX_HOME = Path.home() / ".codex"
DEFAULT_CURSOR_DB = Path.home() / "AppData" / "Roaming" / "Cursor" / "User" / "globalStorage" / "state.vscdb"


def main():
    parser = argparse.ArgumentParser(description="Export Codex and Cursor histories to unified JSONL")
    parser.add_argument("--codex-home", default=str(DEFAULT_CODEX_HOME))
    parser.add_argument("--cursor-db", default=str(DEFAULT_CURSOR_DB))
    parser.add_argument("--output", default="storage/app/imports/ai-history-export.jsonl")
    parser.add_argument("--source", choices=["all", "codex", "cursor"], default="all")
    args = parser.parse_args()

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)

    count = 0
    with output.open("w", encoding="utf-8", newline="\n") as fh:
        if args.source in ("all", "codex"):
            for session in export_codex(Path(args.codex_home)):
                fh.write(json.dumps(session, ensure_ascii=False, separators=(",", ":")) + "\n")
                count += 1

        if args.source in ("all", "cursor"):
            for session in export_cursor(Path(args.cursor_db)):
                fh.write(json.dumps(session, ensure_ascii=False, separators=(",", ":")) + "\n")
                count += 1

    print(f"Exported {count} sessions to {output}")


def export_codex(codex_home):
    files = []
    files.extend(glob.glob(str(codex_home / "sessions" / "**" / "*.jsonl"), recursive=True))
    files.extend(glob.glob(str(codex_home / "archived_sessions" / "*.jsonl")))

    for file_path in sorted(set(files)):
        session = {
            "source": "codex",
            "external_id": Path(file_path).stem,
            "title": None,
            "workspace_path": None,
            "model": None,
            "started_at": None,
            "ended_at": None,
            "source_path": file_path,
            "metadata": {"parse_errors": []},
            "messages": [],
        }

        seq = 0
        try:
            with open(file_path, "r", encoding="utf-8", errors="replace") as fh:
                for line_number, line in enumerate(fh, start=1):
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        event = json.loads(line)
                    except Exception as exc:
                        session["metadata"]["parse_errors"].append({"line": line_number, "error": str(exc)})
                        continue

                    timestamp = event.get("timestamp")
                    payload = event.get("payload") if isinstance(event.get("payload"), dict) else {}
                    event_type = event.get("type")

                    if timestamp:
                        session["started_at"] = min_iso(session["started_at"], timestamp)
                        session["ended_at"] = max_iso(session["ended_at"], timestamp)

                    if event_type == "session_meta":
                        session["metadata"]["codex_session_id"] = payload.get("id")
                        session["workspace_path"] = payload.get("cwd") or session["workspace_path"]
                        session["metadata"]["session_meta"] = shrink_dict(payload)
                        continue

                    if event_type == "turn_context":
                        session["workspace_path"] = payload.get("cwd") or session["workspace_path"]
                        session["model"] = payload.get("model") or session["model"]
                        continue

                    message = normalize_codex_message(seq, event, payload, timestamp)
                    if message:
                        session["messages"].append(message)
                        if not session["title"] and message["role"] == "user" and message.get("content"):
                            session["title"] = make_title(message["content"])
                        seq += 1
        except Exception as exc:
            session["metadata"]["parse_errors"].append({"file": file_path, "error": str(exc)})

        if not session["title"]:
            session["title"] = Path(file_path).stem

        yield session


def normalize_codex_message(seq, event, payload, timestamp):
    role = payload.get("role")
    content = payload.get("content")
    item_type = payload.get("type") or event.get("type")
    tool_name = payload.get("tool_name") or payload.get("name")

    if content is None and "message" in payload:
        content = payload.get("message")
    if isinstance(content, list):
        content = extract_text_from_parts(content)
    elif isinstance(content, dict):
        content = json.dumps(content, ensure_ascii=False)
    elif content is not None:
        content = str(content)

    if not role:
        if item_type and "tool" in str(item_type):
            role = "tool"
        elif event.get("type") == "event_msg":
            role = "event"
        else:
            role = "assistant"

    if content is None and not tool_name and event.get("type") not in ("response_item", "event_msg"):
        return None

    return {
        "seq": seq,
        "role": role,
        "type": item_type,
        "occurred_at": timestamp,
        "content": content,
        "tool_name": tool_name,
        "metadata": {"event_type": event.get("type")},
        "raw": event,
    }


def export_cursor(cursor_db):
    if not cursor_db.exists():
        return

    con = sqlite3.connect(f"file:{cursor_db}?mode=ro", uri=True)
    con.row_factory = sqlite3.Row
    try:
        headers = load_json_value(con, "ItemTable", "composer.composerHeaders") or {}
        header_map = {}
        for header in headers.get("allComposers", []):
            composer_id = header.get("composerId")
            if composer_id:
                header_map[composer_id] = header

        rows = con.execute(
            "select key, value from cursorDiskKV where key like 'composerData:%' order by key"
        ).fetchall()

        for row in rows:
            composer_id = row["key"].split(":", 1)[1]
            data = decode_json_blob(row["value"]) or {}
            header = header_map.get(composer_id, {})
            messages = cursor_messages(con, composer_id, data)
            created_at = ms_to_iso(data.get("createdAt") or header.get("createdAt"))
            updated_at = ms_to_iso(data.get("lastUpdatedAt") or header.get("lastUpdatedAt"))

            title = data.get("name") or header.get("name")
            if not title:
                title = first_user_title(messages) or composer_id

            if is_empty_cursor_session(data, header, messages):
                continue

            yield {
                "source": "cursor",
                "external_id": composer_id,
                "title": make_title(title),
                "workspace_path": cursor_workspace(header, data),
                "model": cursor_model(data),
                "started_at": created_at,
                "ended_at": updated_at,
                "source_path": f"{cursor_db}#composerData:{composer_id}",
                "metadata": {
                    "header": header,
                    "status": data.get("status"),
                    "unified_mode": data.get("unifiedMode") or header.get("unifiedMode"),
                    "force_mode": data.get("forceMode") or header.get("forceMode"),
                    "context_usage_percent": data.get("contextUsagePercent"),
                },
                "messages": messages,
            }
    finally:
        con.close()


def cursor_messages(con, composer_id, data):
    messages = []
    headers = data.get("fullConversationHeadersOnly") or []
    for seq, header in enumerate(headers):
        bubble_id = header.get("bubbleId")
        bubble = load_json_value(con, "cursorDiskKV", f"bubbleId:{composer_id}:{bubble_id}") if bubble_id else None
        body = bubble or header
        role = cursor_role(body)
        content = cursor_content(body)
        messages.append({
            "seq": seq,
            "role": role,
            "type": str(body.get("type") if isinstance(body, dict) else header.get("type")),
            "occurred_at": ms_to_iso(body.get("createdAt") if isinstance(body, dict) else None),
            "content": content,
            "tool_name": cursor_tool_name(body),
            "metadata": {
                "bubble_id": bubble_id,
                "server_bubble_id": header.get("serverBubbleId"),
                "grouping": header.get("grouping"),
            },
            "raw": body,
        })

    if not messages and data.get("text"):
        messages.append({
            "seq": 0,
            "role": "user",
            "type": "text",
            "occurred_at": ms_to_iso(data.get("createdAt")),
            "content": data.get("text"),
            "tool_name": None,
            "metadata": {},
            "raw": {"text": data.get("text")},
        })

    return messages


def is_empty_cursor_session(data, header, messages):
    return not messages


def cursor_role(body):
    if not isinstance(body, dict):
        return "unknown"
    if body.get("isAgentic") or body.get("type") in (2, 3, 4):
        return "assistant"
    if body.get("text") or body.get("richText"):
        return "user"
    if body.get("toolResults") or body.get("toolCallId"):
        return "tool"
    return "event"


def cursor_content(body):
    if not isinstance(body, dict):
        return None
    for key in ("text", "richText", "content", "thinking"):
        value = body.get(key)
        if isinstance(value, str) and value:
            return value
    for key in ("toolResults", "interpreterResults", "lints", "diffsForCompressingFiles"):
        value = body.get(key)
        if value:
            return json.dumps(value, ensure_ascii=False)
    return None


def cursor_tool_name(body):
    if not isinstance(body, dict):
        return None
    if body.get("toolName"):
        return body.get("toolName")
    if body.get("toolCallId"):
        return str(body.get("toolCallId"))
    grouping = body.get("grouping") if isinstance(body.get("grouping"), dict) else {}
    if grouping.get("toolFormerTool") is not None:
        return str(grouping.get("toolFormerTool"))
    return None


def cursor_workspace(header, data):
    workspace = header.get("workspaceIdentifier") if isinstance(header.get("workspaceIdentifier"), dict) else {}
    if workspace.get("id"):
        return workspace.get("id")
    for mapping in (data.get("originalFileStates") or {}).keys():
        return mapping
    return None


def cursor_model(data):
    config = data.get("modelConfig") if isinstance(data.get("modelConfig"), dict) else {}
    if config.get("modelName"):
        return config.get("modelName")
    models = config.get("selectedModels")
    if isinstance(models, list) and models:
        return models[0].get("modelId")
    return None


def load_json_value(con, table, key):
    row = con.execute(f"select value from {table} where key = ?", (key,)).fetchone()
    if not row:
        return None
    return decode_json_blob(row["value"])


def decode_json_blob(value):
    if isinstance(value, bytes):
        value = value.decode("utf-8", errors="replace")
    try:
        return json.loads(value)
    except Exception:
        return None


def extract_text_from_parts(parts):
    chunks = []
    for part in parts:
        if isinstance(part, str):
            chunks.append(part)
        elif isinstance(part, dict):
            if isinstance(part.get("text"), str):
                chunks.append(part["text"])
            elif isinstance(part.get("content"), str):
                chunks.append(part["content"])
    return "\n".join(chunks) if chunks else None


def first_user_title(messages):
    for message in messages:
        if message.get("role") == "user" and message.get("content"):
            return make_title(message["content"])
    return None


def make_title(value, length=80):
    value = " ".join(str(value).split())
    return value[:length] if value else None


def shrink_dict(value):
    ignored = {"base_instructions", "developer_instructions", "user_instructions", "dynamic_tools"}
    return {key: item for key, item in value.items() if key not in ignored}


def ms_to_iso(value):
    if not value:
        return None
    try:
        value = int(value)
        if value > 10_000_000_000:
            value = value / 1000
        return datetime.fromtimestamp(value, timezone.utc).isoformat()
    except Exception:
        return None


def min_iso(current, candidate):
    if not current:
        return candidate
    return min(current, candidate)


def max_iso(current, candidate):
    if not current:
        return candidate
    return max(current, candidate)


if __name__ == "__main__":
    main()
