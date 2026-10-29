#!/usr/bin/env python3
"""
Generate app/Support/Holidays/data/<CC>.json: the official holidays of every
country and territory the python-holidays project covers (MIT licence), for
the years YEAR_FROM-YEAR_TO, in the country's own language and in English.

The countries with a hand-written rule table in HolidayRules.php are still
generated (the tests compare both), but Pinakes reads their rules first.

Run with the pinned version, from the repository root:
    python3 -m venv /tmp/hvenv && /tmp/hvenv/bin/pip install holidays==0.107
    /tmp/hvenv/bin/python -I scripts/generate-holiday-dataset.py
"""
import json
import os
import re
import sys
import unicodedata

import holidays

VERSION = "0.107"
YEAR_FROM, YEAR_TO = 2010, 2060
# Holidays with no date from this year on are left out (past one-off days).
KEEP_FROM = 2026
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "app", "Support", "Holidays", "data")

if holidays.__version__ != VERSION:
    sys.exit(f"python-holidays {VERSION} required, found {holidays.__version__}")

ESTIMATED = re.compile(r"\s*\*?\s*\((estimated|estimate)\)", re.I)
OBSERVED = re.compile(r"\s*\((observed|substitute|in lieu)[^)]*\)", re.I)
PAREN = re.compile(r"\s*[(（][^)）]*\d[^)）]*[)）]")
TRAILING_PAREN = re.compile(r"\s*[(（][^)）]*[)）]\s*$")


def english(cls):
    langs = getattr(cls, "supported_languages", ()) or ()
    for lang in ("en_US", "en_GB"):
        if lang in langs:
            return lang
    return next((lang for lang in langs if lang.startswith("en")), None)


def slug(name):
    base = OBSERVED.sub("", ESTIMATED.sub("", name))
    base = PAREN.sub("", base).strip()
    ascii_name = unicodedata.normalize("NFKD", base).encode("ascii", "ignore").decode()
    s = re.sub(r"[^a-z0-9]+", "_", ascii_name.lower()).strip("_")
    return s[:48] or "holiday"


def clean(name):
    return PAREN.sub("", OBSERVED.sub("", ESTIMATED.sub("", name))).strip()


def collect(cc, subdiv, category, lang_native, lang_en):
    years = range(YEAR_FROM, YEAR_TO + 1)
    kwargs = {"years": years, "categories": (category,)}
    if subdiv:
        kwargs["subdiv"] = subdiv
    eng = holidays.country_holidays(cc, language=lang_en, **kwargs) if lang_en else holidays.country_holidays(cc, **kwargs)
    # The own-language name without the "estimated" mark (the English run keeps it as a flag).
    try:
        native = holidays.country_holidays(cc, language=lang_native, islamic_show_estimated=False, **kwargs)
    except TypeError:
        native = holidays.country_holidays(cc, language=lang_native, **kwargs)
    out = []
    for day, label in sorted(native.items()):
        names = label.split("; ")
        en_names = eng.get(day, label).split("; ")
        if len(en_names) != len(names):
            en_names = names
        for n, e in zip(names, en_names):
            est = bool(ESTIMATED.search(e) or ESTIMATED.search(n))
            if est:
                # The own-language mark ("(تقديري)") is the last parenthesis: the flag replaces it.
                n = TRAILING_PAREN.sub("", n)
            out.append((day.strftime("%Y%m%d"), n, e, est))
    return out


def main():
    os.makedirs(OUT, exist_ok=True)
    countries = holidays.list_supported_countries(include_aliases=False)
    for cc, subdivs in sorted(countries.items()):
        cls = holidays.country_holidays(cc).__class__
        lang_native = getattr(cls, "default_language", None)
        lang_en = english(cls)
        # "workday": commemorations that are working days, never a closure.
        categories = [c for c in (getattr(cls, "supported_categories", ("public",)) or ("public",)) if c != "workday"]
        aliases = {}
        for alias, code in (getattr(cls, "subdivisions_aliases", {}) or {}).items():
            aliases.setdefault(code, alias)
        entries = {}

        def add(rows, category, region):
            for ymd, name, en, est in rows:
                key = (category, slug(en))
                derived = bool(OBSERVED.search(en) or ESTIMATED.search(en))
                e = entries.setdefault(key, {"name": clean(name), "en": clean(en), "category": category, "dates": set(), "regional": {}, "estimated": set(), "derived": derived})
                # Name the holiday after its own day, not after a day in lieu or an estimate.
                if e["derived"] and not derived:
                    e.update(name=clean(name), en=clean(en), derived=False)
                (e["dates"] if region is None else e["regional"].setdefault(region, set())).add(ymd)
                if est:
                    e["estimated"].add(ymd)

        for category in categories:
            national = collect(cc, None, category, lang_native, lang_en)
            add(national, category, None)
            nat = {(d, slug(e)) for d, _, e, _ in national}
            for sd in subdivs:
                rows = [r for r in collect(cc, sd, category, lang_native, lang_en) if (r[0], slug(r[2])) not in nat]
                add(rows, category, sd)

        items = []
        seen_ids = {}
        ordered = sorted(entries.items(), key=lambda kv: (kv[0][0] != "public", first_day(kv[1]), kv[0][1]))
        for (category, sid), e in ordered:
            # One-off days already past (a day of mourning in 2015) are not a choice any more.
            every = set(e["dates"]).union(*e["regional"].values()) if e["regional"] else set(e["dates"])
            if max(every, default="0")[:4] < str(KEEP_FROM):
                continue
            hid = sid if category == "public" else f"{sid}_{category}"
            n = seen_ids.get(hid, 0)
            seen_ids[hid] = n + 1
            if n:
                hid = f"{hid}_{n + 1}"
            regional = {sd: encode(ds - e["dates"]) for sd, ds in sorted(e["regional"].items()) if ds - e["dates"]}
            item = {"id": hid, "name": e["name"], "en": e["en"], "category": category}
            if e["dates"]:
                item["dates"] = encode(e["dates"])
            if regional:
                item["regional"] = regional
            if e["estimated"]:
                item["estimated"] = encode(e["estimated"])
            if not e["dates"] and not regional:
                continue
            items.append(item)
        data = {
            "source": f"python-holidays {VERSION}",
            "country": cc,
            "language": lang_native,
            "years": [YEAR_FROM, YEAR_TO],
            "subdivisions": {sd: aliases.get(sd, sd) for sd in subdivs},
            "holidays": items,
        }
        with open(os.path.join(OUT, f"{cc}.json"), "w", encoding="utf-8") as fh:
            json.dump(data, fh, ensure_ascii=False, separators=(",", ":"))
            fh.write("\n")
    print(f"{len(countries)} countries written to {os.path.normpath(OUT)}")


def first_day(e):
    days = set(e["dates"]).union(*e["regional"].values()) if e["regional"] else set(e["dates"])
    return min((d[4:] for d in days), default="9999")


def encode(dates):
    """
    A YYYYMMDD set as the shorter of two forms:
      d|MMDD:2010-2060,2063|MMDD:...   by day of the year (fixed holidays);
      y|2026:0319-0324,1225|2027:...   by year, with runs of days (movable ones).
    """
    import datetime as dt
    by_day = {}
    for d in dates:
        by_day.setdefault(d[4:], []).append(int(d[:4]))
    day_parts = [mmdd + ":" + ",".join(runs(sorted(years))) for mmdd, years in sorted(by_day.items())]
    by_year = {}
    for d in sorted(dates):
        by_year.setdefault(d[:4], []).append(dt.date(int(d[:4]), int(d[4:6]), int(d[6:])))
    year_parts = []
    for year, days in sorted(by_year.items()):
        chunks, start, prev = [], days[0], days[0]
        for day in days[1:] + [None]:
            if day is not None and day == prev + dt.timedelta(days=1) and day.year == prev.year:
                prev = day
                continue
            a, b = start.strftime("%m%d"), prev.strftime("%m%d")
            chunks.append(a if a == b else f"{a}-{b}")
            if day is not None:
                start = prev = day
        year_parts.append(year + ":" + ",".join(chunks))
    by_d = "d|" + "|".join(day_parts)
    by_y = "y|" + "|".join(year_parts)
    return by_d if len(by_d) <= len(by_y) else by_y


def runs(years):
    out, start, prev = [], years[0], years[0]
    for y in years[1:] + [None]:
        if y is not None and y == prev + 1:
            prev = y
            continue
        out.append(str(start) if start == prev else f"{start}-{prev}")
        if y is not None:
            start = prev = y
    return out


if __name__ == "__main__":
    main()
