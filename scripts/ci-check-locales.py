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
#
# The same reasoning extends past PHP. A view also ships a JavaScript __(),
# defined as window.__ in the layouts, and calls it from inside <script> blocks
# that sit OUTSIDE <?php … ?>. Those are real translation calls — their keys
# must exist in it_IT.json or a German reader silently gets Italian — so the
# scan must keep reading the whole file rather than retreat into PHP ranges.
# What it may do is recognise a JavaScript comment for what it is, and only
# inside a <script> region: "//" is ordinary text in HTML (every protocol-
# relative URL contains one), so comment detection outside <script> would be
# exactly the permissive mistake this lexer exists to avoid.
_PHP_OPEN = re.compile(r"<\?(?:php\b|=)?", re.IGNORECASE)
_PHP_TOKEN = re.compile(r"""['"`]|//|\#|/\*|\?>|<<<""")
_SQ_END = re.compile(r"(?:[^'\\]|\\.)*'", re.DOTALL)
_DQ_END = re.compile(r'(?:[^"\\]|\\.)*"', re.DOTALL)
_BT_END = re.compile(r"(?:[^`\\]|\\.)*`", re.DOTALL)
_HEREDOC_START = re.compile(
    r"<<<[ \t]*(?:'([A-Za-z_\x80-\xff][\w\x80-\xff]*)'"
    r'|"?([A-Za-z_\x80-\xff][\w\x80-\xff]*)"?)\r?\n'
)

_SCRIPT_OPEN = re.compile(r"<script\b", re.IGNORECASE)
# Inside the <script …> tag itself only three things matter: a quoted attribute
# value (which may hide a ">"), an embedded <?php … ?> (src="<?= … ?>" is the
# common shape, and its "?>" would otherwise be mistaken for the tag's end),
# and the ">" that actually opens the body.
_SCRIPT_TAG_TOKEN = re.compile(r"""['"]|<\?|>""")
_JS_TOKEN = re.compile(r"""['"`]|//|/\*|</script\b|<\?|/""", re.IGNORECASE)
# A JavaScript '…' or "…" cannot cross a newline unless the break is escaped.
# Bounding them at the line end matters: an apostrophe in prose ("don't") inside
# a <script type="text/template"> would otherwise swallow the rest of the file.
_JS_SQ_END = re.compile(r"(?:[^'\\\n]|\\[\s\S])*'")
_JS_DQ_END = re.compile(r'(?:[^"\\\n]|\\[\s\S])*"')
_JS_BT_END = re.compile(r"(?:[^`\\]|\\[\s\S])*`")
# A "/" preceded by one of these closes a value, so it divides; anywhere else it
# opens a regex literal. "}" is deliberately absent: after a block a regex is
# legal, and misreading a regex as division is the dangerous half of this guess
# (a /[//]/ would then look like a line comment), while misreading a division as
# a regex only skips a span — it can never invent a comment.
_JS_DIVIDES_AFTER = frozenset(
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$)]"
)


def _script_body_start(text: str, pos: int) -> int | None:
    """Offset just past the ">" of a <script …> tag opened at `pos`."""
    length = len(text)
    while pos < length:
        token = _SCRIPT_TAG_TOKEN.search(text, pos)
        if token is None:
            return None
        kind = token.group()
        if kind == ">":
            return token.end()
        if kind == "<?":
            closing = text.find("?>", token.end())
            pos = length if closing == -1 else closing + 2
        else:
            closer = _JS_SQ_END if kind == "'" else _JS_DQ_END
            end = closer.match(text, token.end())
            pos = end.end() if end else token.end()
    return None


def _js_regex_end(text: str, start: int) -> int | None:
    """Offset just past a regex literal opened by the "/" at `start`."""
    length = len(text)
    index = start + 1
    in_class = False
    while index < length:
        char = text[index]
        if char == "\\":
            index += 2
            continue
        if char == "\n":
            # A regex literal cannot span lines, so this "/" was a division.
            return None
        if char == "[":
            in_class = True
        elif char == "]":
            in_class = False
        elif char == "/" and not in_class:
            return index + 1
        index += 1
    return None


def comment_spans(text: str) -> list[tuple[int, int]]:
    """Half-open [start, end) offsets of every comment in one source file.

    PHP comments anywhere, plus JavaScript comments inside a <script> body that
    is not itself inside <?php … ?>.
    """
    spans: list[tuple[int, int]] = []
    length = len(text)
    pos = 0
    mode = "html"
    php_resume = "html"
    while pos < length:
        if mode == "html":
            opening = _PHP_OPEN.search(text, pos)
            script = _SCRIPT_OPEN.search(text, pos)
            if opening is None and script is None:
                break
            if script is None or (
                opening is not None and opening.start() <= script.start()
            ):
                assert opening is not None
                pos, mode, php_resume = opening.end(), "php", "html"
                continue
            body = _script_body_start(text, script.end())
            if body is None:
                break
            pos, mode = body, "js"
            continue

        if mode == "js":
            token = _JS_TOKEN.search(text, pos)
            if token is None:
                break
            start, kind = token.start(), token.group()

            if kind in ("'", '"', "`"):
                closer = {"'": _JS_SQ_END, '"': _JS_DQ_END, "`": _JS_BT_END}[kind]
                end = closer.match(text, start + 1)
                # An unterminated quote is not a string; step over it rather than
                # skipping to end of file and lexing the remainder out of phase.
                pos = end.end() if end else start + 1
            elif kind in ("//", "/*"):
                if kind == "//":
                    newline = text.find("\n", start)
                    stop = length if newline == -1 else newline
                else:
                    closing = text.find("*/", start + 2)
                    stop = length if closing == -1 else closing + 2
                # PHP does not care that it was commented out in JavaScript:
                # "// <?= __('x') ?>" still calls __(). So the span ends where
                # the PHP does, and lexing resumes there — the exact mirror of
                # "?>" closing a PHP line comment above.
                php_start = text.find("<?", start, stop)
                end = stop if php_start == -1 else php_start
                spans.append((start, end))
                pos = end
            elif kind == "<?":
                opening = _PHP_OPEN.match(text, start)
                pos = opening.end() if opening else start + 2
                mode, php_resume = "php", "js"
            elif kind.lower().startswith("</script"):
                mode, pos = "html", token.end()
            else:  # a bare "/": regex literal or division
                index = start - 1
                while index >= 0 and text[index] in " \t\r\n":
                    index -= 1
                if index >= 0 and text[index] in _JS_DIVIDES_AFTER:
                    pos = start + 1
                else:
                    end_offset = _js_regex_end(text, start)
                    pos = end_offset if end_offset is not None else start + 1
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
            mode = php_resume
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


# PHP's double-quoted escapes, matched left to right so "\\n" is a backslash
# followed by "n", never a newline. Order inside the alternation matters only
# for the shared "\" prefix: octal and hex are tried before the catch-all.
_DQ_ESCAPE = re.compile(
    r"\\(?:([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\}|(.))", re.DOTALL
)
_DQ_SIMPLE = {
    "\\": "\\", "$": "$", '"': '"', "n": "\n", "t": "\t", "r": "\r",
    "v": "\v", "e": "\x1b", "f": "\f",
}


def _dq_escape(match: re.Match[str]) -> str:
    octal, hexa, codepoint, other = match.groups()
    if octal is not None:
        # PHP wraps "\400" and above to a single byte, as chr() does.
        return chr(int(octal, 8) & 0xFF)
    if hexa is not None:
        return chr(int(hexa, 16))
    if codepoint is not None:
        return chr(int(codepoint, 16))
    # Any other sequence ("\q", "\'") is not an escape in PHP: it stays verbatim.
    return _DQ_SIMPLE.get(other, match.group())


def _unescape(single: str | None, double: str | None) -> str:
    """Turn a PHP string literal's source text into its runtime value."""
    if single is not None:
        # Single quotes know only two escapes; "\$" or "\n" stay as written.
        return single.replace("\\\\", "\x00").replace("\\'", "'").replace("\x00", "\\")
    assert double is not None
    return _DQ_ESCAPE.sub(_dq_escape, double)


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
