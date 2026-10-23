#!/usr/bin/env python3
"""Writes tests/mock/state/scenario.json for the mock JOA API.

Usage examples:
  scenario.py --jobs default=J1,J2,J3,J4,J5 dup=J1DUP --feed 1
  scenario.py --jobs default=J5 --feed 0 --closed J2 --gone J4 --withdrawn J5
  scenario.py ... --set J2.title="Renamed role" --mode 429 --expired J1,J3
"""
import argparse, json, os, re

def job(n, **kw):
    base = {
        "id": "00000000-0000-4000-8000-00000000000%d" % n,
        "slug": "role-%d" % n,
        "title": "Role %d" % n,
        "company": "Example Co %d" % n,
        "company_slug": "example-co-%d" % n,
        "company_logo": "https://logos.example.com/%d.png" % n,
        "employer_website": "https://example%d.example" % n,
        "country": "DE", "city": "Berlin", "region": None, "location": "Berlin, Germany",
        "remote": "on_site", "remote_inferred": False,
        "employment_type": "Full-time", "seniority": "Mid", "category": "Engineering",
        "posted_at": "2026-10-0%dT09:00:00Z" % n, "first_seen_at": "2026-10-0%dT09:05:00Z" % n,
        "status": "live", "closed_at": None, "closed_reason": None,
        "apply_url": "https://jobs.example.com/example-co-%d/%d" % (n, n),
        "source": "greenhouse", "source_type": "ats",
        "has_description": True,
        "description": "About the role %d\nYou will build things.\n\nRequirements:\n- Curiosity" % n,
        "field_sources": {"salary": "absent", "remote": "published"},
    }
    base.update(kw)
    return base

FIX = {
    "J1": job(1, title="Senior Backend Engineer", company="Acme GmbH", remote="remote",
              employment_type="Full-time", salary_min=60000, salary_max=80000, salary_currency="EUR",
              salary_period="year", field_sources={"salary": "published"},
              apply_url="https://jobs.example.com/acme/1",
              description="Line one&nbsp;with an entity\nLine two <script>alert(1)</script>\n\nSecond paragraph & more"),
    "J2": job(2, title="Product Designer", remote="hybrid", employment_type="Part-time",
              expires_at="2026-12-31T00:00:00Z"),
    "J3": job(3, title="Data Contractor", employment_type="Contract", salary_min=40, salary_max=None,
              salary_currency="EUR", salary_period="hour", field_sources={"salary": "inferred"}),
    "J4": job(4, title="Marketing Intern", employment_type="Internship", has_description=False,
              description=None),
    "J5": job(5, title="Platform Engineer", attribution="via Erioun for Teams",
              canonical_url="https://erioun.example/roles/5", apply_url="https://erioun.example/apply/5",
              salary_min=30, salary_max=45, salary_currency="USD", salary_period="hour",
              field_sources={"salary": "published"}),
    "J6": job(6, title="Support Specialist", employment_type="Temporary"),
    # Same role as J1 (same employer apply link, written differently) under another JOA id.
    "J1DUP": job(7, title="Senior Backend Engineer (duplicate)", apply_url="https://www.jobs.example.com/acme/1/"),
}

def ids(csv):
    """A comma-separated list of fixture keys (J1, J1DUP, ...) and/or
    generic jobs G<n> (fixture n generated on the fly)."""
    out = []
    for k in csv.split(","):
        if not k:
            continue
        if k.startswith("G"):
            out.append(job(int(k[1:]))["id"])
        else:
            out.append(FIX[k]["id"])
    return out

def parse_reset(spec):
    """'' -> None; '123' -> 123; '+10d' / '-1h' / '+30m' -> relative to now."""
    if not spec:
        return None
    if spec.isdigit():
        return int(spec)
    import time
    m = re.fullmatch(r"([+-])(\d+)([dhm])", spec)
    if not m:
        raise SystemExit("bad --records-reset: %r" % spec)
    mult = {"h": 3600, "d": 86400, "m": 60}[m.group(3)]
    return int(time.time()) + (1 if m.group(1) == "+" else -1) * int(m.group(2)) * mult

p = argparse.ArgumentParser()
p.add_argument("--jobs", nargs="*", default=[])
p.add_argument("--feed", type=int, default=1)
p.add_argument("--mode", default="normal")
p.add_argument("--expired", default="")
p.add_argument("--closed", default="")
p.add_argument("--gone", default="")
p.add_argument("--withdrawn", default="")
p.add_argument("--set", nargs="*", default=[])
p.add_argument("--records", default="",
               help="'' (off, no headers) | unlimited | limit=N,remaining=M")
p.add_argument("--records-reset", default="",
               help="unix ts or +10d / -1h / +30m relative (needs --records limit=...)")
a = p.parse_args()

for s in a.set:
    k, v = s.split("=", 1)
    fx, field = k.split(".", 1)
    FIX[fx][field] = v

scenario = {"mode": a.mode, "feed": bool(a.feed), "jobs": {},
            "expired": [{"seq": i + 1, "id": j, "closed_reason": "employer_closed"} for i, j in enumerate(ids(a.expired))],
            "closed": ids(a.closed), "gone": ids(a.gone), "withdrawn": ids(a.withdrawn)}
for spec in a.jobs:
    name, csv = spec.split("=", 1)
    scenario["jobs"]["_default" if name == "default" else name] = [job(int(k[1:])) if k.startswith("G") else FIX[k] for k in csv.split(",") if k]
if a.records:
    if a.records == "unlimited":
        scenario["records"] = "unlimited"
    else:
        import time
        rec = {}
        for part in a.records.split(","):
            k, v = part.split("=", 1)
            rec[k] = int(v)
        reset = parse_reset(a.records_reset)
        if reset is None:
            reset = int(time.time()) + 30 * 86400  # a month out, like the real API
        rec["reset"] = reset
        scenario["records"] = rec

here = os.path.dirname(os.path.abspath(__file__))
with open(os.path.join(here, "mock", "state", "scenario.json"), "w") as f:
    json.dump(scenario, f, indent=1)
