#!/usr/bin/env python3
"""Validate translation keys, placeholders and localized route parity."""

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
