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
# call sites and must not be demanded of the catalogue.
COMMENT_LINE = re.compile(r"^\s*(?:\*|//|#|/\*)")


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
            for pattern, groups in (
                (TRANSLATE_CALL, ((1, 2),)),
                (TRANSLATE_PLURAL_CALL, ((1, 2), (3, 4))),
            ):
                for match in pattern.finditer(text):
                    line_start = text.rfind("\n", 0, match.start()) + 1
                    if COMMENT_LINE.match(text[line_start : match.start() + 1]):
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
