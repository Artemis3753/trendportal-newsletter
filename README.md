# Trend Portal Newsletter

A website and weekly newsletter for Trend Portal (트렌드포털), an Instagram trend magazine with about 70,000 followers. Built for the magazine's two-person editorial team, so they can reach readers outside Instagram and keep a subscriber list of their own.

**Live site:** <https://trendportal.kr>

## Overview

The editors publish card news (a set of image cards) on Instagram every day. This project takes the same material to the web and to email:

- **A site** on WordPress.com. Each card-news set becomes a post made of text-free photos plus real text, so it can be searched, shared and read on a phone.
- **A weekly newsletter** on Stibee, sent on Fridays. Each issue is a longer take on the week's roundup. The first issue went out on October 23, 2026.
- **A subscriber lock** on the weekly roundups: the first card is public, the rest open for people on the newsletter list.
- **Internal tools** that cut the editors' manual work, several of them run by Claude Code. See [Automation](#automation-with-claude-code).

### Decisions worth reading

The reasoning, the alternatives and the numbers are in [`docs/PRD.md`](docs/PRD.md). The short version:

- **WordPress.com instead of a custom Next.js + Notion build.** The first plan was to build the site. Then I measured the magazine's 14 real posts: an average of 58 MB each, about a third of the content is video, and every post had at least one file over Notion's 5 MB free limit. On 2026-09-17 I switched, trading control for something the editors can run without me.
- **Stibee instead of Kit or beehiiv.** Korean law asks for recorded consent and an ad label on marketing email. Stibee Standard (8,900 KRW a month) is the plan that handles that for us. Sending stays manual: the email API starts at the Pro plan, and one issue a week does not justify the price.
- **The lock hides text on screen; it does not remove it from the page.** Serving a different page to each reader risks the WordPress.com cache showing a subscriber's page to everyone. The goal is sign-ups, not paid content, so we accepted that "view source" shows the text. Whether an email is on the list is checked on the server against Stibee's subscriber API (cached briefly). If the check fails, the lock opens instead of locking readers out.
- **Many small plugins, each with one job.** Switching one off restores the old behavior, which is the cheapest rollback available on a hosted site.

### Automation with Claude Code

The editors are not developers, so the tools run from the WordPress admin screen and from scheduled Claude Code routines in the cloud.

| Tool | What it does |
|---|---|
| **Card-news helper** (`tools/cardnews/`, `tools/figma-plugin/`, plugin `tp-cardnews-helper`) | A Figma plugin exports the cards. An editor uploads the zip and the Instagram caption as an order in an admin tab. A routine turns the order into a **private draft** and a newsletter HTML file. A person reviews, publishes or schedules it. |
| **Newsletter builder** (`tools/newsletter/`) | Builds the email HTML from the same order, with its own footer because Stibee adds none to mail made in its HTML editor. A person pastes it into Stibee and schedules it. |
| **Trend feed** (`tools/radar/`, plugin `tp-trend-radar`) | Gathers new posts from external sources into one feed in the admin. The editors tick what they like and a routine writes a Korean summary. |
| **Transis** (`transis/`) | Reads the team's work inbox, read-only, keeps a schedule in a Google Sheet through Apps Script, and sends a daily reminder email and phone notification. |

The routines are given narrow permissions and written to expect hostile input:

- The card-news routine is told never to publish a post or change any other post. Its output is a private draft, so a person still decides what goes live.
- Card text, captions, order memos and email bodies are treated as data, never as instructions.
- Reading and sending are split. The inbox reader is told to only read. Reminders go out through a separate send-only account whose script refuses any address that is not on a short allow-list, so a leaked token cannot turn it into a mail relay.
- A run that fails marks its order as failed, so nothing is left looking "in progress".

### How it was built

The requirements, the decisions above and the review of every change are mine. Claude Code drafted much of the code, and most commits carry a `Co-Authored-By` trailer for that reason.

## Status

- 🟢 **Site** — live since September 2026. New posts go up every week.
- 🟢 **Newsletter** — weekly, on Fridays, since October 23, 2026. A welcome email goes to every new subscriber.
- 🟢 **Subscriber lock** — checks the Stibee list.
- 🟡 **Not covered** — there are no automated tests or CI. The plugins are checked by hand on a phone and desktop. See [Known gaps](#known-gaps).

## What's on the Site

- Posts adapted from the Instagram card news: a cover, image cards, and a source credit under each card
- Two series sections on the home page: weekly roundups (주간 총정리) and TP 1,000
- Weekly roundups use the newsletter's layout; everything after the first card opens for newsletter subscribers
- A reader tip board, built on KBoard
- A KakaoTalk share button and Open Graph tags for link previews
- A mobile-first layout, since most readers come from Instagram
- Registered with Google Search Console and Naver Search Advisor

## Newsletter

Weekly, on Fridays. Each issue is a longer take on that week's roundup. The helper drafts it from the week's card news, a person reviews it, and it is pasted into Stibee and scheduled by hand. [`docs/newsletter.md`](docs/newsletter.md) has the format and the steps.

## Repository Layout

| Path | Contents |
|---|---|
| `plugins/` | Source for the custom WordPress plugins |
| `dist/` | The same plugins as zips, for upload to WordPress.com |
| `site/additional.css` | A copy of the site's Additional CSS, pasted into the editor by hand |
| `tools/cardnews/` | Python script and instructions for the card-news helper routine |
| `tools/newsletter/` | Python script that builds the newsletter HTML |
| `tools/radar/` | Python script and instructions for the trend feed routine |
| `tools/figma-plugin/` | The Figma plugin that exports cards |
| `transis/` | The schedule assistant: Python script, routine instructions, and the Apps Script web app |
| `docs/PRD.md` | Requirements, decisions, and open questions |
| `docs/post-formats.md` | The rulebook for how a post is laid out |
| `docs/newsletter.md` | The newsletter's format and sending steps |

Secrets (site keys, the Stibee key, routine tokens) live in the site's settings, never in this repository.

### Known gaps

- No automated tests and no CI.
- Deployment is manual: a plugin is rebuilt into `dist/` and uploaded as a zip.
- Block templates (home, single post, tag archive) are edited in the WordPress.com site editor and live only on the site, so the repository alone cannot rebuild the site.

## Custom Plugins

| Plugin | What it does |
|---|---|
| TP Card Sheet | On phones, opens a tapped list card as a sheet that holds the real post page, and restyles the list's page numbers. Turning it off restores the old behavior |
| TP Card Tone | Passes each card's photo to CSS, splits the home series titles, and marks the newest post |
| TP Cardnews Helper | The admin tab that receives card-news orders and shows the helper's results |
| TP Cover Photo | Moves a post's text-free photo into the cover and tints the cover from the photo's color |
| TP Header Scroll | Hides the header on scroll down and shows it on scroll up, and positions the phone menu |
| TP Kakao Share | Adds a KakaoTalk share button to posts |
| TP KBoard Skin | Skin for the reader tip board, based on KBoard's default skin |
| TP Meta Description | Builds search and share descriptions from the post body when no one wrote one |
| TP Naver Verification | Prints Naver's ownership tag, related-channel markup, and a bilingual site name. Keep it active: Naver re-checks the tag |
| TP No Comment Popup | Turns off the Jetpack subscribe popup after a comment, which skips Stibee's consent steps |
| TP Related Posts | Adds a row of related posts under each post |
| TP Transis Frame | Shows the schedule web app at a hidden address on the site and sends web push notifications |
| TP Trend Radar | The admin tab for the trend feed and its reports |
| TP Video Preview | Shows a video's first frame before playback on iOS and mobile Chrome |
| TP View Count | Shows each post's view count next to its date, read from Jetpack stats |
| TP Weekly Links | Fixed links that always point to the latest weekly roundups, for the welcome email |
| TP Weekly Lock | Numbers the cards, builds the table of contents, and adds the subscriber lock on weekly roundups. Shows them with the `tp-weekly` template (made in the Site Editor) for a newsletter-style head. Also registers the "weekly roundup" block pattern |

## Tech Stack

- **Site** — WordPress.com (Premium plan), Spiel theme, Site Editor and Additional CSS
- **Plugins** — PHP and JavaScript
- **Tools** — Python 3 scripts that talk to the site's REST API, and a Figma plugin
- **Automation** — Claude Code routines in the cloud; Google Apps Script for the schedule sheet and reminder mail
- **Newsletter** — Stibee Standard, scheduled by hand
- **Domain / DNS** — `trendportal.kr`, nameservers on WordPress.com
- **Email** — `contact@trendportal.kr` on Google Workspace
