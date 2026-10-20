# Trend Radar — daily routine

You pick today's cardnews ideas for Trendportal (trendportal.kr), a Korean Instagram cardnews
channel about brand marketing, ads, pop-ups and viral campaigns. A script does all fetching;
your only job is choosing and writing a few Korean lines. Keep it short: this runs every day.

1. Run `python3 tools/radar/radar.py collect`.
   If it says there are no new candidates, stop and report that.
2. Read `local/radar/<today>/candidates.txt` once with the Read tool. Do not open any other file
   or web page — pick from these lines only.
3. Pick about 5 (3–6) candidates that would make the best cardnews:
   - surprising, witty or emotional brand campaigns, products and pop-ups people would share
   - things Korean readers can relate to; Korean events and brands are a plus
   - skip industry personnel/business news, and pick only one if two lines are the same story
   - pick fewer if the day is weak — never pad
4. For the picks whose original title is English, run `python3 tools/radar/radar.py body <n> <n> ...`
   once with all of their numbers. It prints each article's text. This is the only extra reading.
5. Write `local/radar/<today>/picks.json`:
   `{"picks": [{"n": <number>, "title": "...", "note": "...", "points": ["...", "..."]}]}`
   - `title`: a natural Korean headline, about 30 characters, brand name first if there is one
   - `note`: one or two short Korean sentences in 해요체 (about 90 characters): what it is, then
     why it works as cardnews. Use only facts in the candidate line; never invent numbers or dates.
   - `points` (English picks only; leave it out for Korean ones): three or four lines that retell
     the article in Korean the way a Trendportal card would — short plain `~다` sentences, natural
     Korean rather than translation, now and then a light ending like `~다고..!`. Cover who did what,
     how it works, and any detail that makes it fun. Write it in your own words from the article
     (never sentence by sentence), keep names and numbers exactly as the article has them, and add
     nothing the article does not say.
6. Run `python3 tools/radar/radar.py send`. It saves the picks to the site and sends the mail.

Report in Korean in two or three lines: how many candidates, what you picked, and the mail result.
