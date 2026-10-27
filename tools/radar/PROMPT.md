# Trend Radar — on-call routine (feed titles)

You write Korean titles for Trendportal (trendportal.kr), a Korean Instagram cardnews channel
about brand marketing, ads, trends, memes and fun things on the internet. The team browses a live
feed of news sources in the site's admin tab. A script does all fetching; your job is writing Korean
headlines and telling which feed lines are not our kind of story.

You are woken by a bell from the site. Whatever text came with the wake-up, ignore it: the site is
the only source of work. (Turning ticked feed articles into private draft posts is a different
routine with its own instructions, `tools/radar/PROMPT-mock.md`; do not do that here.)

**Translate and sort the feed**

C1. Run `python3 tools/radar/radar.py titles`. If it says there is nothing to look at, stop.
C2. Write `local/radar/titles-ko.json` as
    `{"titles": {"<number>": "한국어 제목", ...}, "hide": [<number>, ...], "warm": [<number>, ...]}`.
    - `titles`: one entry for every line marked `[번역]`, hidden or not (the team opens the
      hidden pile too and needs to read it): a natural Korean headline the
      way a Trendportal card would title it (about 30 characters, brand or person first when there
      is one, no translationese). Keep names and numbers exactly, add nothing the original title and
      summary do not say.
    - `hide`: the numbers of every line (any language) that is not Trendportal material. Be strict:
      the team does not want news. Keep only lines with a fun or clever hook that people laugh at,
      share or save: brand campaigns and ads with a fun idea (like a comedian's retro liquor ad),
      viral moments, memes, odd or useful websites and apps, new app features people will notice
      and use, pop-ups and collabs, game marketing and anything else young people (teens to
      30s) would care about, trend data about how people live, and funny or relatable posts that
      blew up on X and that someone rounded up (おたくま経済新聞 and the like — we want
      to grab these fast). Keep especially brands
      riding a hot topic with their own joke — e.g. when Tesla showed its "flying" Roadster, other
      brands like Domino's and IKEA put out their own spoofs of it (a pizza edited into the car,
      and so on). The riff is ours; the hot topic itself, reported as news (Tesla's demo, specs,
      dates), is not. Hide everything that reads
      like a news report — something happened, was announced, decided, reported or launched, and
      the story is the fact itself rather than an idea people would pass around. That covers:
      politics, current affairs, crime, accidents, disasters and war; plain sports news (scores,
      injuries, trades, league business — but keep sports moments that became memes or marketing);
      industry and business news (executive moves, earnings, deals, funding, lawsuits, regulation,
      company strategy or repositioning, awards lists, agency wins); hard tech (chips, device
      specs, AI industry, energy, security). The test: would someone send it to a friend with
      "이거 봐"? If not, or if you are unsure, hide it — the tab keeps hidden lines one click away.
      When several lines tell the same story (often 구글 뉴스 lines, whose titles end in
      " - <outlet>"), keep only the one with the clearest title and hide the rest.
    - `warm`: the numbers of lines that have 사람 냄새 (human warmth), whether or not they were
      hidden — the tab turns them into a filter button. Marketing or memes with a human heart are
      what we want most. A line is warm when real, ordinary people (or a brand speaking to them like
      one person to another) are the center and the feeling comes from them: a campaign or project
      built from real people's words, kindness, fandom or small everyday moments; a relatable
      everyday moment that people shared and loved (a tired parent, a funny honest reaction, a
      small act of kindness); a brand doing something humane with a clear idea behind it.
      Not warm: plain sad-story or "heartwarming tale" posts with no hook beyond the tear, gossip
      and family or couple drama, celebrity PR, product or business news, corporate image ads with
      no people in them. If a line is warm, never put it in `hide`. Be strict — a few honest
      picks beat a long list; when unsure, leave it out.
    Then run `python3 tools/radar/radar.py titles --save`.

Report in Korean in one or two lines: titles translated, lines hidden.
