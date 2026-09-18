"""Count the messages Mike has typed about Kaiki, from the Claude Code transcripts.

Run it with `python docs/mockups/count-prompts.py`. It reads the session logs in
`~/.claude/projects/C--WINDOWS-system32/*.jsonl` — every session ever run from
that working directory — and counts only what a human typed.

What is deliberately *not* counted:
  * the assistant's own messages;
  * tool results and system reminders, which are delivered as user-role
    messages and would otherwise triple the figure;
  * the resumed-session caveat line;
  * anything in a session whose log never mentions Kaiki.

Messages are de-duplicated by their uuid, because a resumed session can carry a
copy of earlier entries, and the same message counted twice is the easiest way
for a number like this to be quietly wrong.
"""

from __future__ import annotations

import glob
import io
import json
import os
from collections import Counter

LOGS = os.path.expanduser("~/.claude/projects/C--WINDOWS-system32/*.jsonl")

SKIP_PREFIXES = ("<system-reminder>", "[Request interrupted")


def prompts_in(path: str) -> tuple[bool, list[tuple[str, str, str]]]:
    """Every human message in one session log, and whether it mentions Kaiki."""
    mentions_kaiki = False
    found: list[tuple[str, str, str]] = []

    with io.open(path, encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if not mentions_kaiki and "kaiki" in line.lower():
                mentions_kaiki = True

            try:
                entry = json.loads(line)
            except ValueError:
                continue

            if entry.get("type") != "user" or entry.get("isMeta"):
                continue

            content = (entry.get("message") or {}).get("content")

            if isinstance(content, str):
                parts = [content]
            elif isinstance(content, list):
                parts = [
                    block.get("text", "")
                    for block in content
                    if isinstance(block, dict) and block.get("type") == "text"
                ]
            else:
                continue

            text = "\n".join(parts).strip()

            if not text or text.startswith(SKIP_PREFIXES):
                continue

            if "Caveat: The messages below" in text[:200]:
                continue

            found.append((entry.get("uuid", ""), (entry.get("timestamp") or "")[:10], text))

    return mentions_kaiki, found


def main() -> None:
    seen: set[str] = set()
    per_day: Counter[str] = Counter()
    sessions = 0
    words = 0

    for path in sorted(glob.glob(LOGS)):
        mentions_kaiki, found = prompts_in(path)

        if not mentions_kaiki:
            continue

        sessions += 1

        for uuid, day, text in found:
            if uuid in seen:
                continue

            seen.add(uuid)
            per_day[day] += 1
            words += len(text.split())

    days = sorted(per_day)

    print(f"{len(seen)} messages, {sessions} sessions, {len(days)} days of work")
    print(f"{days[0]} to {days[-1]}, about {words:,} words")

    for day in days:
        print(f"  {day}  {per_day[day]:3}")


if __name__ == "__main__":
    main()
