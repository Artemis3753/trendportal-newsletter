# Post formats

How a trendportal.kr post is put together from an Instagram cardnews. This file is the **single source of truth** for both people working in Claude Code and the cardnews helper (셰프 트란시스, `tools/cardnews/PROMPT.md`). When a format rule is decided, it goes here, not only into someone's notes.

Reference posts are on trendportal.kr as `/archives/<id>/`.

## Every post

**Title**
- Start from the cover text and put the nouns a reader would search for (brand, product, place, person) into it, so they come early. **Write one natural line: no dash between two parts** (`—`, `–`, ` - `; site owner, 10/18). Join with words or a comma instead, for example `포카리스웨트·칼로리메이트까지, 울림 있는 일본 광고 카피 15선` or `발가락 양말부터 카프리 팬츠까지, 요즘 촌스러움이 유행하는 이유`, not `일본 카피에는 울림이 있다 — 포카리스웨트·칼로리메이트 등 광고 카피 15선`. Keep it to about two phone lines (30 Korean characters).
- On a phone the title wraps at about 15 Korean characters. Join a short phrase that belongs together with no-break spaces (U+00A0), but only a few: binding long runs pushes whole chunks to the next line. The middle dot `·` is also a break point, on both sides: keep `인스타·스레드` together with a word joiner (U+2060, `\u2060` in JSON) before and after the dot. Check the title at phone width (`shot`, or 360/390/430px) and bind only where a phrase splits, as was done for posts 729, 763 and 857 on 10/18.
- Weekly roundups keep their issue title (`… (26.10 3주차)`).

**Category** (exactly one)

| id | name | use for |
| :- | :- | :- |
| 3663 | 브랜드 | a brand's campaign, product, pop-up, collaboration, ad copy collections |
| 18233 | 트렌드 | a trend, meme or consumer behaviour across many brands |
| 11788 | 인터뷰 | interviews (`[트포 인터뷰 : …]` in the caption, `TP INTERVIEW` on the cards) |
| 18540 | 광고 | only paid ads (`광고`, `AD`, `유료 광고`, `협찬`). A post *about* ads is not one |
| 5180795 | 뉴스 | not for cardnews |

**Caption**
- The caption paragraphs go right after the cover photo, at the top of the body. Its first line is the post title, so it is left out.
- The closing lines ("여러분은…? / 댓글로 여러분의 의견을 남겨주세요!") become one last paragraph, joined with line breaks.
- Instagram actions that do not work on the site change: `태그해주세요`, `DM 주세요` → `댓글로 남겨주세요`.
- Series episode numbers are dropped, the series name stays: `[ 트포 인터뷰 제 8화 : … ]` → `[ 트포 인터뷰 : … ]`.
- Fix only obvious typos. Names, brands, numbers, dates and quotes never change.

**Card text**
- Paragraphs follow the card: a blank line on the card is a new paragraph. Do not split or join on your own.
- Bold text on the card stays bold (`<strong>`). We never add new emphasis. (The Figma plugin does not read bold yet; until it does, a person checks it.)
- Keep the card's quotes (straight or curly) and the title's line breaks.

**Source**: one small paragraph under each card's text, `© 출처명` (`tp-source`, small font). Never guess which source belongs to which card.

**Cover**: the post header shows the title only (grey grain area). The cover photo without text is the first block (`tp-cover-photo`); the cover with its text is the featured image for lists and link previews. The cover is not repeated in the body.

**Alt text**: in Korean, what is actually visible: people, objects, place, the brand when it is visible.

**Publishing**: a person reviews and publishes. The Kakao share button is added by the template. Do not remove the `.sharedaddy { display: none }` CSS line.

## Design

Make the post look as close to the original cards as our resources allow, and still read well on a phone and on PC.

- Look at the card previews first (`figma/cards/*.jpg`) and the font, colour and alignment in `figma/scan.json`.
- If an existing format fits, use it. If the cards need something new, new CSS goes only under a new class (`tp-fmt-…`), so it cannot touch other posts.
- Fonts: use the ones the site already has (all SIL OFL 1.1): Pretendard, Gowun Batang, Manrope, Orbitron. A new font only when it is truly needed **and** its licence explicitly allows web use. If unsure, do not use it.
- Body text stays centred. Card photos: 16px side margins and 14px corners on phones, 480px wide on PC.

## Formats

The script (`tools/cardnews/cardnews.py build`) picks the format from `order.json` → `"layout"`. Without it, the format is standard.

### Standard (post 242)
For each card: photo → card title (H2, boxed like the card title) → text → source.

### Video card (posts 351, 636)
- Someone else's video: still image → right under it `▶ 인스타그램에서 영상 보기 (@account)` linking to the original post → H2 → text → source. Links end at `/reel/<id>/`, no tracking tail. Keep the exact original link; do not replace it with the account page.
- Our own video: muted H.264 720p clip that autoplays and loops (`autoplay loop muted playsinline`), with a poster image. No source line.

### Copy collection (post 729) — `"layout": "copy"`
All cards on one dark band across the screen, copy in Gowun Batang, `— brand —` under it. **Only post 729 uses it for now**; do not choose it for other posts even if their cards have `-brand-` lines.

### Interview Q&A (post 498) — `"layout": "qa"`
One interviewee, several questions.
- A profile card right under the first line (`[ 트포 인터뷰 : … ]`) and again after the last paragraph: `<a class="tp-ad-profile">` with the avatar (`tp-ad-avatar`, empty alt: the name is right next to it), name (`<b>`), `@account` (`<small>`) and "프로필 보기". The Instagram account and profile photo come from a person (the interview form), never guessed. The photo may be a raw screenshot: crop a square just inside the profile picture's circle, without Instagram's story ring or white edge (`cardnews.py profile`), because the site draws its own ring.
- Each question: `<h2 class="wp-block-heading tp-iv-q"><span class="tp-q">QUESTION 01</span> question</h2>`. Keep the space after the label. A heading that is not a question (a closing quote) has the same class and no label.
- Order inside a question: heading → photo → answer (the opposite of standard cards).
- The cover photo has no text on it. If only a cover with text is available, ask for one without.

### Interview panel — `"layout": "panel"`
One question answered by many people. It follows the Q&A post 498, not the card look. There is no live reference post: the approved example (843) was deleted unpublished on 10/18, so follow these rules and 498's markup.
- The intro card's text (a card without an `@account` line), then the question once as the interview heading (`tp-iv-q`, label `QUESTION`; `QUESTION 01`, `02`… when there are several questions).
- For each person: a white profile card (`tp-ad-profile`, as in 498) → their answer paragraphs. A 40px spacer block goes between people, so no extra CSS is needed.
- Profile photo and link: from the interviewee given in the interview form with the same Instagram account; otherwise the face cut from the card and the card's `@account`.
- The script reads `@account / name` from the card (`cards.json` → `who`).
- Not the dark card-shaped panels (`.tp-pn-card`) tried first: the site owner chose the 498-style look described here instead.

### Paid ad (post 439) — 광고 form
Built as standard, plus:
- First paragraph: `<strong>[광고]</strong> 이 글은 유료 광고를 포함하고 있습니다.` A caption line that is only an ad marker (`[광고]`, `#광고`, `AD`) is left out, because this line replaces it.
- Right under it, and again after the last paragraph: the advertiser's profile card (`tp-ad-profile`, as in the interview), with `rel="noreferrer noopener sponsored"` because it is a paid link. Name, Instagram account and profile photo come from the 광고 form (Instagram link required), never guessed. The photo is cropped like the interview one.
- Category 광고 (18540).

### Weekly roundup (posts 636, 479, 351)
Built from the Thursday Figma zip, together with the newsletter (`docs/newsletter.md`). The markup comes from `build_weekly` in `tools/cardnews/cardnews.py`.
- Reading the zip: `order.json` with `"kind": "weekly"` and `issue`, the caption in `caption.txt`, then `cardnews.py fetch`. Card 1 is the cover and the last card is the end card. Each card between is one item, read like a standard card: title = largest text (two lines joined with a space), body = longest text, `©` line = source, any other small text = an extra body paragraph. From the caption: the first line (the post title), the "특히 이번 주 에디터가 주목한 이슈는…" line and everything from "매주 금요일…" on (the site's fixed ending replaces it) are left out; lines starting with ✅ are the editor picks (reversed quotes `’…‘` fixed); a line right after the picks starts a new letter paragraph. Tested on 851 and its real caption = post 636: titles, sources, letter and picks match exactly.
- After fetch, look at every card preview (`figma/cards/`) and fix what the script cannot know: spacing at Figma line breaks inside a word (`핸드크림 이` → `핸드크림이`; fetch flags the ones before a particle), obvious spelling (`수 밖에` → `수밖에`), and text boxes that sat on the photo (fetch warns; they come out as empty boxes, so crop from the card with `visual` if the text is needed). Older cards (before autumn 2026) end the title with a brand logo image: read the brand from the card and add it to the title.
- Title `이번 주 일어난 마케팅 이슈 총정리 (26.10 3주차)`, category 뉴스 (5180795), tag 주간 총정리 `weekly` (5180798), published Friday.
- Everything sits in one `tp-wk` group, in this order: issue line `WEEKLY · 26.10 3주차 · 금요일 발행` → editor letter (the Instagram caption's opening, then a line like `2026년 10월 3주차, 지금 실제로 터진 마케팅 이슈만 모았습니다.`, signed 트렌드포털) → 이번 주 에디터 픽 (three items) → the table-of-contents placeholder → one `tp-wk-item` per card → the fixed ending.
- Each card: H2 title → photo (or a video still with a link, or our own clip) → `© source` → 무슨 일이었나 (the card text as written) → 💡 주목 포인트 box.
- 💡 on the site: plain `~다` sentences, three or four, saying why it worked or why it matters (see 636 and 479). Short on purpose: the researched long version goes to the newsletter only.
- The TP Weekly Lock plugin fills in card numbers, the table of contents and the subscriber lock from card 02 when the page is shown. Do not write those by hand.
- The ending says 댓글로 (the newsletter says 답장으로) and ends with the "매주 금요일…" paragraph.

## Reference posts

Posts the site owner approved as the model for their format. When writing a post of that kind, look at the reference's actual markup (`python3 tools/cardnews/cardnews.py ref <id>`) and copy its structure, not only the rules above.

| format | post |
| :- | :- |
| standard cardnews | 242 |
| video card | 351 (someone else's video), 636 (our own clip) |
| copy collection | 729 |
| interview Q&A | 498 |
| interview panel | none live (follow the rules above and 498) |
| paid ad | 439 (one profile card at the top; new ad posts also get one at the end) |
| weekly roundup | 636, 479 |

When a format decision changes in a conversation, update this file in the same session, and add the approved post here. A decision that lives only in someone's notes or a chat does not reach the helper.

## Order forms

The helper tab (트란시스 카드뉴스 도우미) takes three kinds of orders. Weekly roundups are not ordered there.
- **카드뉴스**: Figma zip, Instagram caption, video cards, memo. Built as standard.
- **인터뷰**: the same, plus the interview format (질문 여러 개 = `qa` / 패널 = `panel`, chosen by the person who orders) and the interviewees (name, Instagram link, profile photo, all optional) for the profile cards.
- **광고**: the same as 카드뉴스, plus the advertiser (name, Instagram link required, profile photo) for the profile cards at the top and the end.

## Order memo and revision requests

The memo in the order tab and "고쳐 주세요" come from the site team. Through them a person can ask for:
- a format: `카드 그림처럼 어두운 판으로` (interviews are ordered with the interview form)
- a category or title
- design changes for this post (new CSS stays under its own `tp-fmt-…` class)
- facts the cards do not have: an Instagram account, a corrected name

The helper follows them inside the order's own draft. Facts on the cards still change only when the request gives the corrected fact.
