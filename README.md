# Trend Portal Newsletter

Website and newsletter for Trend Portal (트렌드포털), an Instagram trend magazine.

**Live site:** <https://trendportal.kr>

## Status

- 🟢 **Site** — live since September 2026. New posts go up every week.
- 🚧 **Newsletter** — launching around October 20, 2026.

## What's on the Site

- Posts adapted from the Instagram card news: a cover, image cards, and a source credit under each card
- Two series sections on the home page: weekly roundups (주간 총정리) and TP 1,000
- Weekly roundups use the newsletter's layout; everything after the first card opens for newsletter subscribers
- A reader tip board, built on KBoard
- A KakaoTalk share button and Open Graph tags for link previews
- A mobile-first layout, since most readers come from Instagram
- Registered with Google Search Console and Naver Search Advisor

## Newsletter

Weekly, on Fridays. Each issue is a longer take on that week's roundup, with notes from the editors. It is written in Stibee, separately from the site, and sent manually.

## Repository Layout

| Path | Contents |
|---|---|
| `plugins/` | Source for the custom WordPress plugins |
| `dist/` | The same plugins as zips, for upload to WordPress.com |
| `site/additional.css` | A copy of the site's Additional CSS, pasted into the editor by hand |
| `docs/PRD.md` | Requirements, decisions, and open questions |

Block templates (home, single post, tag archive) are edited in the WordPress.com site editor and live only on the site.

## Custom Plugins

| Plugin | What it does |
|---|---|
| TP Kakao Share | Adds a KakaoTalk share button to posts |
| TP KBoard Skin | Skin for the reader tip board, based on KBoard's default skin |
| TP Naver Verification | Prints Naver's ownership tag, related-channel markup, and a bilingual site name. Keep it active: Naver re-checks the tag |
| TP Video Preview | Shows a video's first frame before playback on iOS and mobile Chrome |
| TP Weekly Links | Fixed links that always point to the latest weekly roundups, for the welcome email |
| TP Weekly Lock | Numbers the cards, builds the table of contents, and adds the subscriber lock on weekly roundups. Shows them with the `tp-weekly` template (made in the Site Editor) for a newsletter-style head. Also registers the "weekly roundup" block pattern |

## Tech Stack

- **Site** — WordPress.com (Premium plan), Spiel theme
- **Newsletter** — Stibee, sent manually
- **Domain / DNS** — `trendportal.kr`, nameservers on WordPress.com
- **Email** — `contact@trendportal.kr` on Google Workspace
