#!/usr/bin/env python3
"""Validate translation keys, placeholders and localized route parity.

Also scans the PHP sources for __()/__n() literals that are not keys in
it_IT.json. Cross-comparing the five JSON files can only catch a string that is
missing from *some* of them; a string missing from all five — the shape that let
a whole plugin ship untranslated — is invisible to it, because it_IT is the key
set every other locale is measured against and the admin translation editor
derives from.
"""

import json
import re
import sys
from bisect import bisect_right
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LOCALE_DIR = ROOT / "locale"
FULL_LOCALES = ("en_US", "de_DE", "fr_FR", "da_DK")
PLACEHOLDER_LOCALES = FULL_LOCALES
ROUTE_LOCALES = ("routes_en_US", "routes_de_DE", "routes_fr_FR", "routes_da_DK")
PLACEHOLDER = re.compile(r"%(?:\d+\$)?[sd]")

# Sources scanned for translatable literals.
SOURCE_DIRS = ("app", "storage/plugins", "installer")
# A single-quoted or double-quoted PHP literal. A double-quoted string
# containing "$" is interpolated, so it is not a literal and is left out.
_STRING = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\$]|\\.)*)")"""
# Only a first argument that IS a literal is checked: __($message),
# __($labels[$k]) and every other dynamic argument cannot be verified
# statically and are deliberately skipped rather than guessed at.
TRANSLATE_CALL = re.compile(r"\b__\(\s*" + _STRING + r"\s*(?=[,)])")
# __n() carries two literals and translatePlural() looks up whichever one the
# count selects, so both have to be registered.
TRANSLATE_PLURAL_CALL = re.compile(
    r"\b__n\(\s*" + _STRING + r"\s*,\s*" + _STRING + r"\s*(?=[,)])"
)
# Docblocks document __() with example strings ("Welcome %s"); they are not
# call sites and must not be demanded of the catalogue. Deciding that from the
# start of the physical line missed two shapes — a trailing "$x = 1; // __('x')"
# and the inner lines of a /* … */ block that do not open with "*" — so the
# comment regions are lexed instead.
#
# The lexer is deliberately more than a "is there a // before me" test, because
# the cheap version is wrong in the DANGEROUS direction: an href="https://…"
# earlier on the same line would mark every later __() on it as commented out,
# and a genuinely missing key would stop being reported. Strings, heredocs and
# the HTML outside <?php … ?> therefore all have to be skipped properly.
_PHP_OPEN = re.compile(r"<\?(?:php\b|=)?", re.IGNORECASE)
_PHP_TOKEN = re.compile(r"""['"`]|//|\#|/\*|\?>|<<<""")
_SQ_END = re.compile(r"(?:[^'\\]|\\.)*'", re.DOTALL)
_DQ_END = re.compile(r'(?:[^"\\]|\\.)*"', re.DOTALL)
_BT_END = re.compile(r"(?:[^`\\]|\\.)*`", re.DOTALL)
_HEREDOC_START = re.compile(
    r"<<<[ \t]*(?:'([A-Za-z_\x80-\xff][\w\x80-\xff]*)'"
    r'|"?([A-Za-z_\x80-\xff][\w\x80-\xff]*)"?)\r?\n'
)


def comment_spans(text: str) -> list[tuple[int, int]]:
    """Half-open [start, end) offsets of every PHP comment in one source file."""
    spans: list[tuple[int, int]] = []
    length = len(text)
    pos = 0
    in_php = False
    while pos < length:
        if not in_php:
            opening = _PHP_OPEN.search(text, pos)
            if opening is None:
                break
            pos, in_php = opening.end(), True
            continue

        token = _PHP_TOKEN.search(text, pos)
        if token is None:
            break
        start, kind = token.start(), token.group()

        if kind in ("'", '"', "`"):
            closer = {"'": _SQ_END, '"': _DQ_END, "`": _BT_END}[kind]
            end = closer.match(text, start + 1)
            pos = end.end() if end else length
        elif kind == "<<<":
            heredoc = _HEREDOC_START.match(text, start)
            if heredoc is None:
                pos = start + 3
                continue
            label = heredoc.group(1) or heredoc.group(2)
            terminator = re.compile(
                r"^[ \t]*" + re.escape(label) + r"\b", re.MULTILINE
            ).search(text, heredoc.end())
            pos = terminator.end() if terminator else length
        elif kind == "/*":
            closing = text.find("*/", start + 2)
            end = length if closing == -1 else closing + 2
            spans.append((start, end))
            pos = end
        elif kind == "?>":
            in_php = False
            pos = start + 2
        else:  # "//" or "#" — a line comment, unless it is a #[Attribute]
            if kind == "#" and text.startswith("#[", start):
                pos = start + 2
                continue
            newline = text.find("\n", start)
            newline = length if newline == -1 else newline
            # "?>" closes a line comment as surely as a newline does.
            closing_tag = text.find("?>", start, newline)
            end = newline if closing_tag == -1 else closing_tag
            spans.append((start, end))
            pos = end
    return spans


def _unescape(single: str | None, double: str | None) -> str:
    """Turn a PHP string literal's source text into its runtime value."""
    if single is not None:
        return single.replace("\\\\", "\x00").replace("\\'", "'").replace("\x00", "\\")
    assert double is not None
    return (
        double.replace("\\\\", "\x00")
        .replace('\\"', '"')
        .replace("\\n", "\n")
        .replace("\\t", "\t")
        .replace("\\r", "\r")
        .replace("\x00", "\\")
    )


def translatable_literals() -> dict[str, set[str]]:
    """Every statically known __()/__n() literal, mapped to the files using it."""
    found: dict[str, set[str]] = {}
    for directory in SOURCE_DIRS:
        base = ROOT / directory
        if not base.is_dir():
            continue
        for path in sorted(base.rglob("*.php")):
            text = path.read_text(encoding="utf-8", errors="replace")
            spans = comment_spans(text)
            starts = [start for start, _ in spans]
            for pattern, groups in (
                (TRANSLATE_CALL, ((1, 2),)),
                (TRANSLATE_PLURAL_CALL, ((1, 2), (3, 4))),
            ):
                for match in pattern.finditer(text):
                    # The call sits in a comment when the nearest span opening
                    # at or before it has not closed yet.
                    index = bisect_right(starts, match.start()) - 1
                    if index >= 0 and match.start() < spans[index][1]:
                        continue
                    for first, second in groups:
                        literal = _unescape(match.group(first), match.group(second))
                        found.setdefault(literal, set()).add(
                            str(path.relative_to(ROOT))
                        )
    return found


def load(name: str) -> dict[str, object]:
    with (LOCALE_DIR / f"{name}.json").open(encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"{name}.json must contain a JSON object")
    return value


def main() -> int:
    failed = False
    italian = load("it_IT")

    for locale_name in FULL_LOCALES:
        translated = load(locale_name)
        missing = sorted(set(italian) - set(translated))
        extra = sorted(set(translated) - set(italian))
        if missing or extra:
            failed = True
            print(f"✗ Translation key drift in {locale_name}.json")
            for key in missing:
                print(f"  missing: {key}")
            for key in extra:
                print(f"  extra: {key}")

    for locale_name in PLACEHOLDER_LOCALES:
        translated = load(locale_name)
        for key, source_value in italian.items():
            if key not in translated:
                continue
            expected = sorted(PLACEHOLDER.findall(str(source_value)))
            actual = sorted(PLACEHOLDER.findall(str(translated[key])))
            if expected != actual:
                failed = True
                print(
                    f'✗ Placeholder mismatch for "{key[:80]}": '
                    f"it_IT={expected}, {locale_name}={actual}"
                )

    italian_routes = load("routes_it_IT")
    for locale_name in ROUTE_LOCALES:
        translated = load(locale_name)
        missing = sorted(set(italian_routes) - set(translated))
        extra = sorted(set(translated) - set(italian_routes))
        if missing or extra:
            failed = True
            print(f"✗ Route key drift in {locale_name}.json")
            for key in missing:
                print(f"  missing: {key}")
            for key in extra:
                print(f"  extra: {key}")

    unregistered = sorted(
        (literal, sorted(files))
        for literal, files in translatable_literals().items()
        if literal not in italian
    )
    if unregistered:
        failed = True
        print("✗ __() strings missing from locale/it_IT.json")
        for literal, files in unregistered:
            print(f'  missing: "{literal[:100]}"  ({files[0]})')

    if failed:
        return 1
    print("✓ Translation keys, placeholders and localized routes are aligned")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"✗ Locale validation could not run: {error}", file=sys.stderr)
        raise SystemExit(1) from error
