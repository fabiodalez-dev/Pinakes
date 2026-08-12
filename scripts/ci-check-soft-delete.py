#!/usr/bin/env python3
"""Fail when a PHP SQL statement reads ``libri`` without a soft-delete guard.

The old CI check worked at file scope: one guarded query made every other query
in that file pass.  This scanner splits PHP at statement boundaries (ignoring
semicolons inside strings/comments) and validates each SQL expression on its
own.  Deliberate reads of deleted rows must carry a reasoned exemption in the
same PHP statement's leading comment:

    // CI-SOFT-DELETE-EXEMPT: closed-loan reconciliation must lock deleted books.
    $stmt = $db->prepare('SELECT ... FROM libri ...');
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

TABLE_REF = re.compile(r"\b(?:FROM|JOIN)\s+`?libri`?\b", re.IGNORECASE)
GUARD = re.compile(r"\b(?:[a-z_][a-z0-9_]*\.)?deleted_at\s+IS\s+NULL\b", re.IGNORECASE)
EXEMPT = re.compile(r"CI-SOFT-DELETE-EXEMPT:\s*(\S.{11,})", re.IGNORECASE)
METADATA_ONLY = re.compile(r"\bSHOW\s+COLUMNS\s+FROM\s+`?libri`?\b", re.IGNORECASE)


def php_statements(source: str):
    """Yield (offset, statement) for semicolon-terminated PHP expressions."""
    start = 0
    index = 0
    state = "normal"
    while index < len(source):
        char = source[index]
        nxt = source[index + 1] if index + 1 < len(source) else ""

        if state == "normal":
            if char == "'":
                state = "single"
            elif char == '"':
                state = "double"
            elif char == "/" and nxt == "/":
                state = "line_comment"
                index += 1
            elif char == "#":
                state = "line_comment"
            elif char == "/" and nxt == "*":
                state = "block_comment"
                index += 1
            elif char == ";":
                yield start, source[start : index + 1]
                start = index + 1
        elif state == "single":
            if char == "\\":
                index += 1
            elif char == "'":
                state = "normal"
        elif state == "double":
            if char == "\\":
                index += 1
            elif char == '"':
                state = "normal"
        elif state == "line_comment":
            if char in "\r\n":
                state = "normal"
        elif state == "block_comment" and char == "*" and nxt == "/":
            state = "normal"
            index += 1
        index += 1

    if start < len(source):
        yield start, source[start:]


def without_php_comments(source: str) -> str:
    """Remove PHP comments while preserving quoted SQL strings."""
    result = list(source)
    index = 0
    state = "normal"
    while index < len(source):
        char = source[index]
        nxt = source[index + 1] if index + 1 < len(source) else ""
        if state == "normal":
            if char == "'":
                state = "single"
            elif char == '"':
                state = "double"
            elif char == "/" and nxt == "/":
                state = "line_comment"
                result[index] = result[index + 1] = " "
                index += 1
            elif char == "#":
                state = "line_comment"
                result[index] = " "
            elif char == "/" and nxt == "*":
                state = "block_comment"
                result[index] = result[index + 1] = " "
                index += 1
        elif state == "single":
            if char == "\\":
                index += 1
            elif char == "'":
                state = "normal"
        elif state == "double":
            if char == "\\":
                index += 1
            elif char == '"':
                state = "normal"
        elif state == "line_comment":
            if char in "\r\n":
                state = "normal"
            else:
                result[index] = " "
        elif state == "block_comment":
            result[index] = "\n" if char == "\n" else " "
            if char == "*" and nxt == "/":
                result[index + 1] = " "
                state = "normal"
                index += 1
        index += 1
    return "".join(result)


def check_source(path: Path, source: str) -> list[str]:
    violations: list[str] = []
    for offset, statement in php_statements(source):
        executable = without_php_comments(statement)
        refs = list(TABLE_REF.finditer(executable))
        if not refs:
            continue
        # Schema introspection does not expose application rows.
        without_metadata = METADATA_ONLY.sub("", executable)
        if not TABLE_REF.search(without_metadata):
            continue
        # A prose comment mentioning the required text is not a SQL guard.
        if GUARD.search(executable) or EXEMPT.search(statement):
            continue
        first = refs[0]
        line = source.count("\n", 0, offset + first.start()) + 1
        compact = " ".join(statement[first.start() :].split())[:180]
        violations.append(
            f"{path}:{line}: query reads libri without deleted_at IS NULL "
            f"or a statement-scoped CI-SOFT-DELETE-EXEMPT reason: {compact}"
        )
    return violations


def check_file(path: Path) -> list[str]:
    source = path.read_text(encoding="utf-8", errors="replace")
    return check_source(path, source)


def self_test() -> None:
    cases = [
        ("guarded", "<?php $q='SELECT id FROM libri WHERE deleted_at IS NULL';", 0),
        (
            "second statement cannot borrow first guard",
            "<?php $a='SELECT id FROM libri WHERE deleted_at IS NULL'; $b='SELECT id FROM libri';",
            1,
        ),
        (
            "file-level exemption cannot cover a later statement",
            "<?php // CI-SOFT-DELETE-EXEMPT: first query deliberately audits deleted rows.\n"
            "$a='SELECT id FROM libri'; $b='SELECT id FROM libri';",
            1,
        ),
        (
            "same-statement exemption",
            "<?php // CI-SOFT-DELETE-EXEMPT: integrity audit deliberately includes deleted rows.\n"
            "$q='SELECT id FROM libri';",
            0,
        ),
        (
            "comment is not a guard",
            "<?php // Remember to add deleted_at IS NULL later.\n$q='SELECT id FROM libri';",
            1,
        ),
        ("schema metadata", "<?php $q=\"SHOW COLUMNS FROM libri LIKE 'x'\";", 0),
    ]
    for name, source, expected in cases:
        actual = len(check_source(Path(f"<{name}>"), source))
        if actual != expected:
            raise AssertionError(f"{name}: expected {expected} violation(s), got {actual}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("paths", nargs="*", default=["app"], help="PHP files or directories")
    args = parser.parse_args()
    self_test()

    files: list[Path] = []
    for raw_path in args.paths:
        path = Path(raw_path)
        if path.is_dir():
            files.extend(sorted(path.rglob("*.php")))
        elif path.suffix == ".php" and path.is_file():
            files.append(path)

    violations = [message for path in files for message in check_file(path)]
    if violations:
        print("\n".join(violations), file=sys.stderr)
        print(f"FAIL: {len(violations)} unguarded libri SQL statement(s)", file=sys.stderr)
        return 1
    print(f"OK: {len(files)} PHP files; every libri read is guarded or statement-exempt")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
