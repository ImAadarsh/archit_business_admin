#!/usr/bin/env python3
"""
Hit mockup generator one product at a time. Continues after success or IMAGE_SAFETY skip.
Stops on any other AI/generation failure, HTTP errors, or empty queue.

Usage:
  python3 run_mockups_loop.py
  python3 run_mockups_loop.py --max 50
  python3 run_mockups_loop.py --url 'https://dashboard.invoicemate.in/generate_product_mockups_v2.php?GEMINI_API_KEY=...'
"""

from __future__ import annotations

import argparse
import os
import re
import sys
import time
import urllib.error
import urllib.request
import urllib.parse

BASE_URL = "https://dashboard.invoicemate.in/generate_product_mockups_v2.php"

STATUS_RE = re.compile(r"Status:\s*(.+)", re.IGNORECASE)
PRODUCT_RE = re.compile(r"Processing Product ID:\s*(\d+)", re.IGNORECASE)
REMAINING_RE = re.compile(r"Products remaining after this run:\s*(\d+)", re.IGNORECASE)


def default_url() -> str:
    key = os.environ.get("GEMINI_API_KEY", "").strip()
    if not key:
        raise SystemExit("Set GEMINI_API_KEY or pass --url with the full generator URL")
    params = urllib.parse.urlencode({"GEMINI_API_KEY": key})
    return f"{BASE_URL}?{params}"


def fetch(url: str, timeout: int) -> tuple[int, str]:
    req = urllib.request.Request(
        url,
        headers={"User-Agent": "mockup-loop/1.0"},
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        return resp.status, body


def classify(body: str) -> tuple[str, str]:
    """Return (action, reason) where action is continue|stop_ok|stop_fail."""
    if "No more products to process" in body or "No unprocessed products found" in body:
        return "stop_ok", "queue empty"

    status_m = STATUS_RE.search(body)
    status = status_m.group(1).strip() if status_m else ""

    # Strip HTML tags from status if present
    status_plain = re.sub(r"<[^>]+>", "", status).strip()

    if "Marked as processed" in status_plain:
        return "continue", status_plain
    if "IMAGE_SAFETY" in status_plain or "Skipped (IMAGE_SAFETY)" in body:
        return "continue", "IMAGE_SAFETY skip"
    if "NOT processed" in status_plain or "will retry" in status_plain.lower():
        return "stop_fail", status_plain or "generation failed (non-safety)"

    # Fallback signals
    if "rate-limited (HTTP 429)" in body and "Mockups Generated: 0" in body:
        return "stop_fail", "rate limited (429)"
    if "Mockups Uploaded: 0" in body and "IMAGE_SAFETY" not in body:
        return "stop_fail", "zero uploads without IMAGE_SAFETY"

    return "stop_fail", status_plain or "unrecognized response / failed run"


def main() -> int:
    parser = argparse.ArgumentParser(description="Sequential mockup generator runner")
    parser.add_argument("--url", default=None, help="Generator URL including GEMINI_API_KEY")
    parser.add_argument("--max", type=int, default=0, help="Max products to process (0 = unlimited)")
    parser.add_argument("--timeout", type=int, default=240, help="HTTP timeout seconds per product")
    parser.add_argument("--sleep", type=float, default=1.0, help="Pause between products (seconds)")
    args = parser.parse_args()
    url = args.url or default_url()

    n = 0
    while True:
        if args.max and n >= args.max:
            print(f"Reached --max {args.max}. Stopping.")
            return 0

        n += 1
        print(f"\n=== Run #{n} ===")
        try:
            code, body = fetch(url, args.timeout)
        except urllib.error.HTTPError as e:
            print(f"STOP: HTTP {e.code} — {e.reason}")
            return 1
        except Exception as e:
            print(f"STOP: request error — {e}")
            return 1

        if code != 200:
            print(f"STOP: HTTP {code}")
            return 1

        product_m = PRODUCT_RE.search(body)
        remaining_m = REMAINING_RE.search(body)
        product_id = product_m.group(1) if product_m else "?"
        remaining = remaining_m.group(1) if remaining_m else "?"

        action, reason = classify(body)
        print(f"Product #{product_id} | remaining after: {remaining} | {reason}")

        if action == "continue":
            time.sleep(args.sleep)
            continue
        if action == "stop_ok":
            print("Done — no more products.")
            return 0

        print(f"STOP: {reason}")
        # Print a short tail of the log for debugging
        lines = [ln for ln in body.splitlines() if ln.strip().startswith("[")]
        for ln in lines[-12:]:
            print(ln)
        return 1


if __name__ == "__main__":
    sys.exit(main())
