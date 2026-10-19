# Newsletter

Shared rules for the weekly newsletter (Stibee), for people and for Claude. Anything decided about the newsletter in a conversation goes into this file the same day; a decision that lives only in notes or a chat does not reach whoever writes the next issue.

The site's ultimate goal is an active reader-report board. The newsletter serves it: every issue invites reports, and from 10/30 it features the reports chosen that week.

## Weekly flow

| when | what |
| :- | :- |
| by Thursday | The weekly roundup card news for Instagram is finished. The team sends the Figma zip (and the Instagram caption). |
| Thursday | Claude researches and writes the issue (`issue.json`), builds it, and sends a preview for review. The site's weekly roundup post is made from the same zip (`docs/post-formats.md`). |
| Thursday, after review | The HTML goes into Stibee's HTML editor and is scheduled. |
| Friday 08:30 | The card news goes up on Instagram and the newsletter goes out. |

The first issue (26.10 4주차, 10/23) is made in Claude Code. From the second issue (10/30) the same work moves to the helper tab and the cloud chef.

## Who writes

Claude writes the whole issue: letter, editor picks, every card, notices, closing. The team reviews before it is scheduled. (This replaced the 10/6 plan where the team wrote it themselves.)

## 💡 주목 포인트 in the newsletter

- **Go beyond the card.** For each card, research the background, numbers, similar cases and why it is happening now, and write the insight from that. The card is the starting point, not the limit.
- **Length is fine.** Several paragraphs per card is expected; spending more searches and tokens for quality is approved.
- **Facts need sources.** Write only what a source supports, and list the sources for each card (`sources`, shown as 참고 links under the box). If something cannot be confirmed, leave it out or flag it for review; do not guess.
- **No copying.** Summarize and link; never paste article sentences or use article photos.
- Tone: the same plain `~다` sentences as the site's 💡. The letter, picks, notices and closing speak to the reader in `~요`.
- **Highlight only what matters (10/19).** Wrap a key phrase in `**…**` and it shows bold with a yellow marker, the same as on the site. One or two per card (at most one per paragraph), and at most one in the letter. A phrase, not a whole sentence. Unmarked text has no highlight.

The site keeps the short 💡 and the newsletter always carries the long, researched one. This split is fixed (decided 10/19): never send the site's short version to Stibee.

## Layout and tools

Design: the mock newsletter `1006-4` (approved 10/18). The site's weekly post and the newsletter are made from **one order folder** (`local/cardnews/<order>/`, `"kind": "weekly"`): `cardnews.py fetch` reads the zip into `cards.json` (titles, photos, sources, card text, letter and picks from the caption), which both use. Only the newsletter's own writing goes into `newsletter.json`.

```
python3 tools/cardnews/cardnews.py fetch <order>         # zip + caption → cards.json (see post-formats.md, Weekly roundup)
python3 tools/newsletter/newsletter.py init <order>      # newsletter.json skeleton
python3 tools/newsletter/newsletter.py upload <order>    # photos not yet on the site → media (same media.json as the site post)
python3 tools/newsletter/newsletter.py build <order>     # out/stibee.html (open in a browser to preview)
python3 tools/newsletter/newsletter.py shot <order>      # the mail at 390px and 600px → shots/newsletter-*.png; look at every part
```

`newsletter.json`: `letter` (paragraphs to the reader in `~요`; starts as the caption's letter and is rewritten), `picks`, optional `notice` {`title`, `text`, `button`, `url`}, optional `reports` (the week's chosen reader reports, from 10/30: each `url`, `title`, `user` = the name shown on the board, `text` = one or two sentences on what was reported), `cards` (one per card, matched by `no`: `point` = the researched 💡 paragraphs, `sources` [{`label`, `url`}]), optional `closing`. `build` flags cards whose 💡 is empty or has no sources.

The builder writes inline styles (mail apps can drop `<style>`), sends site photos through Jetpack's image server at mail size, shows a vertical video still small and centred with a `▶ … 영상 보기` link, and points our own clips to the site's weekly post (build again after the site draft exists). Fixed parts: the header, the table of contents, the closing with the report button and the gift line, and the footer with Stibee's `$%permalink%$` and `$%unsubscribe%$` (the unsubscribe link must stay: it is an ad-consent mailing).

## Stibee facts

- Paste `out/stibee.html` into Stibee's HTML editor. A mail made in the HTML editor cannot be saved as a Stibee template, which is fine: the builder is the template.
- `$%permalink%$`, `$%unsubscribe%$` and the like are filled in by Stibee when it sends; a browser preview shows them as text.
- The welcome mail is sent automatically only on the Standard plan or above (the team plans to pay this week). Its design is the calendar mock `1006-2c`, with a line about the report gift added on 10/18.
- Stibee has no automatic "latest site posts" block, so links to the site are written into each issue.

## Report event

Readers who send a report that is featured in the newsletter get a Starbucks Americano gift card (notice on the report board, uid 6). Reports are taken Saturday to Thursday, starting with those sent on 10/24; the first featured reports go in the 10/30 issue. Reporters leave an email address that only the operators can see. The welcome mail and every issue's closing mention the gift.
