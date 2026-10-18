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

The site keeps the short 💡 and the newsletter carries the long one. This split is a trial from the first issue: if the team finds the long version is more than needed, both go back to being the same.

## Layout

Design: the mock newsletter `1006-4` (approved 10/18). `tools/newsletter/newsletter.py` renders it with inline styles (mail apps can drop `<style>`), so write `issue.json` and let the tool do the markup:

```
python3 tools/newsletter/newsletter.py upload <issue>   # photos in images/ → site media (media.json)
python3 tools/newsletter/newsletter.py build <issue>    # out/stibee.html (open it in a browser to preview)
```

`issue.json` fields: `issue` (`26.10 4주차`), `dateline`, `title` (lines), `letter` (paragraphs), `picks`, optional `notice` (`title`, `text`, `button`, `url`), `cards` (each: `title`, `image` = file in `images/` or a URL, optional `video` {`url`, `label`}, `credit`, `what` = the card text, `point` = the researched 💡 paragraphs, `sources` [{`label`, `url`}]), optional `closing`.

Fixed parts the tool adds: the header, the table of contents, the closing with the report button and the gift line, and the footer with Stibee's `$%permalink%$` and `$%unsubscribe%$` (the unsubscribe link must stay: it is an ad-consent mailing).

## Report event

Readers who send a report that is featured in the newsletter get a Starbucks Americano gift card (notice on the report board, uid 6). Reports are taken Saturday to Thursday, starting with those sent on 10/24; the first featured reports go in the 10/30 issue. Reporters leave an email address that only the operators can see. The welcome mail and every issue's closing mention the gift.
