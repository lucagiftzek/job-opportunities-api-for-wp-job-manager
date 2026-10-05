"""Renders wordpress.org assets (SVG -> PNG) and takes the readme screenshots.

Run inside the Playwright image with host networking (the test site listens on 127.0.0.1:8088):
  docker run --rm --network host --user 1000:1000 -e HOME=/tmp -v "$PWD":/work -w /work \
    mcr.microsoft.com/playwright/python:v1.55.0-noble python3 tests/screenshots.py
"""
import os, sys
from playwright.sync_api import sync_playwright

SITE = "http://127.0.0.1:8088"
OUT = "wporg-assets"
PASS = os.environ.get("JOAWP_ADMIN_PASS", "joawp-local-test")
LISTING_URL = os.environ.get("JOAWP_LISTING_URL", "")


def render_svg(browser, src, dest, w, h, scale):
    page = browser.new_page(viewport={"width": w, "height": h}, device_scale_factor=scale)
    svg = open(os.path.join(OUT, src)).read()
    page.set_content('<html><body style="margin:0">' + svg.replace('width="%d" height="%d"' % (w, h), 'width="%d" height="%d"' % (w, h)) + "</body></html>")
    page.screenshot(path=os.path.join(OUT, dest), clip={"x": 0, "y": 0, "width": w, "height": h})
    page.close()


with sync_playwright() as p:
    browser = p.chromium.launch()

    if "assets" in sys.argv[1:] or len(sys.argv) == 1:
        render_svg(browser, "icon.svg", "icon-128x128.png", 256, 256, 0.5)
        render_svg(browser, "icon.svg", "icon-256x256.png", 256, 256, 1)
        render_svg(browser, "banner.svg", "banner-772x250.png", 772, 250, 1)
        render_svg(browser, "banner.svg", "banner-1544x500.png", 772, 250, 2)

    if "shots" in sys.argv[1:] or len(sys.argv) == 1:
        ctx = browser.new_context(viewport={"width": 1280, "height": 900})
        page = ctx.new_page()
        page.goto(SITE + "/wp-login.php")
        page.fill("#user_login", "admin")
        page.fill("#user_pass", PASS)
        page.click("#wp-submit")
        page.wait_for_url("**/wp-admin/**")
        base = SITE + "/wp-admin/edit.php?post_type=job_listing&page=joawpjm"

        page.goto(base)
        page.screenshot(path=OUT + "/screenshot-1.png", full_page=True)

        page.goto(base + "&tab=searches&action=edit&search=live")
        page.screenshot(path=OUT + "/screenshot-2.png", full_page=True)

        page.goto(SITE + "/wp-admin/edit.php?post_type=job_listing")
        skip = page.locator("a:has-text('Skip Setup')").first
        if skip.count():  # WP Job Manager's own first-run banner: dismiss it like a site owner would.
            skip.click()
            page.wait_for_load_state()
            page.goto(SITE + "/wp-admin/edit.php?post_type=job_listing")
        page.screenshot(path=OUT + "/screenshot-3.png", full_page=False)

        # Front end as a visitor sees it (logged out).
        visitor = browser.new_context(viewport={"width": 1280, "height": 900})
        vpage = visitor.new_page()
        vpage.goto(LISTING_URL)
        btn = vpage.locator("input.application_button, .application_button").first
        if btn.count():
            btn.click()
            vpage.wait_for_timeout(400)
        # Title, description and the Apply area: full page, capped in height.
        height = min(vpage.evaluate("document.documentElement.scrollHeight"), 1900)
        vpage.screenshot(path=OUT + "/screenshot-4.png", full_page=True, clip={"x": 0, "y": 0, "width": 1280, "height": height})
        visitor.close()

        page.goto(base + "&tab=log")
        page.screenshot(path=OUT + "/screenshot-5.png", full_page=True)
        ctx.close()
    browser.close()
print("done")
