#!/usr/bin/env python3
"""트렌드포털 뉴스레터 조립 도구 (10/18 시작, 첫 호 10/23) — 규칙은 docs/newsletter.md

주간 총정리 주문 폴더 하나에서 사이트 글과 뉴스레터를 같이 만든다(10/18 사용자):
  cardnews.py fetch <주문>     → cards.json(카드 제목·사진·출처·본문, 레터·픽) — 사이트 글과 뉴스레터가 같이 쓴다
  newsletter.py init <주문>    → newsletter.json 뼈대. 뉴스레터에만 들어가는 글(레터·긴 💡·출처·공지)을 Claude가 채운다
  newsletter.py upload <주문>  → 아직 안 올린 사진만 사이트 미디어로(사이트 글과 같은 media.json — 두 번 안 올린다)
  newsletter.py build <주문>   → out/stibee.html (스티비 HTML 편집기에 붙여 넣을 것, 브라우저로 열면 미리보기)
  newsletter.py shot <주문>    → 메일용 눈: 완성된 메일을 폰(390)·메일 폭(600)으로 찍어 shots/newsletter-*.png
<주문> = local/cardnews/ 아래 폴더 이름 또는 경로
"""

import html
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools" / "cardnews"))
import cardnews  # noqa: E402  주문 폴더·사이트 REST·사진 올리기·브라우저를 그대로 쓴다

LOGO = "https://i0.wp.com/trendportal.kr/wp-content/uploads/2026/09/cropped-tp-icon-square.png?fit=128%2C128&ssl=1"
FONT = "-apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif"

# 메일 프로그램(특히 Gmail)이 <style>을 지우는 경우가 있어서 꾸밈은 전부 태그에 직접 적는다(인라인).
# 값은 모의 뉴스레터 docs/트렌드포털-시안비교/1006-4 의 class 값을 그대로 옮긴 것(디자인 확정 10/18)
S = {
    "page": f"margin:0;padding:24px 0;background:#ececec;font-family:{FONT};color:#111;word-break:keep-all;",
    "mail": "width:100%;max-width:600px;margin:0 auto;background:#fff;",
    "rule": "border-top:1px solid #111;margin:36px 32px;",
    "issue": "margin:0 0 10px;font-size:12px;font-weight:800;letter-spacing:1px;color:#888;",
    "h1": "margin:0;font-size:30px;font-weight:800;line-height:1.3;letter-spacing:-1.1px;color:#111;",
    "letter_p": "margin:0 0 14px;font-size:15px;line-height:1.8;",
    "sig": "margin:24px 0 0;padding-top:14px;border-top:1px solid #e5e5e5;color:#888;font-size:12px;font-weight:800;letter-spacing:1px;",
    "box": "background:#f1f1f1;border-radius:10px;padding:18px 20px;",
    "box_t": "display:block;font-size:12px;letter-spacing:1px;color:#888;margin-bottom:8px;font-weight:800;",
    "toc_h": "margin:0 0 12px;font-size:13px;font-weight:800;letter-spacing:1px;color:#888;",
    "toc_td": "padding:7px 0;border-bottom:1px solid #eee;vertical-align:top;font-size:14px;line-height:1.5;",
    "num": "margin:0 0 6px;font-size:13px;font-weight:800;color:#aaa;letter-spacing:1px;",
    "h2": "margin:0 0 16px;font-size:21px;font-weight:800;line-height:1.4;letter-spacing:-0.6px;color:#111;",
    "src": "margin:6px 0 18px;font-size:11px;color:#999;",
    "h4": "margin:0 0 6px;font-size:13px;font-weight:800;color:#888;letter-spacing:0.5px;",
    "p": "margin:0 0 18px;font-size:15px;line-height:1.8;",
    "insight_p": "margin:0 0 10px;font-size:14.5px;line-height:1.8;",
    "refs": "margin:10px 0 0;font-size:12px;line-height:1.7;color:#888;",
    "btn": "display:inline-block;background:#111;color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:13px 24px;border-radius:999px;",
    "foot": "padding:28px 32px 32px;font-size:12px;line-height:1.8;color:#999;background:#f7f7f7;margin-top:40px;",
}
DEFAULT_CLOSING = [
    "이번 주 가장 인상 깊었던 마케팅이 있다면 이 메일에 답장으로 알려 주세요.",
    "먼저 본 트렌드가 있다면 제보해 주세요. 뉴스레터에 실린 제보를 보내 주신 분께는 스타벅스 아메리카노 기프티콘을 드려요.",
]


def e(s):
    # 글자 안전하게 + json에서 줄바꿈("\n")으로 적은 곳은 <br>
    return "<br>".join(html.escape(line) for line in str(s).split("\n"))


def photon(url, w):
    # 사이트 사진은 Jetpack 사진 서버(i0.wp.com)로 폭을 줄여 보낸다 — 메일은 600px 칸이라 2배(1200)면 충분, 용량은 훨씬 작다
    if url.startswith("https://trendportal.kr/wp-content/uploads/"):
        return "https://i0.wp.com/" + url[len("https://"):] + f"?w={w}&ssl=1"
    return url


def dateline(issue):
    # "26.10 3주차" → "2026년 10월 3주차 · 금요일 발행"
    m = re.match(r"(\d{2})\.(\d{1,2})\s*(\d)\s*주차", issue)
    return f"20{m.group(1)}년 {int(m.group(2))}월 {m.group(3)}주차 · 금요일 발행" if m else f"{issue} · 금요일 발행"


def photo_of(c):
    # 메일에 넣을 사진 파일: 사진·남의 영상 정지 화면은 image, 우리 영상은 재생이 안 되니 포스터
    return c.get("image") or c.get("poster")


# ── init ────────────────────────────────────────────────

def init(d):
    cards = cardnews.load(d, "cards.json")
    if (d / "newsletter.json").exists():
        sys.exit("newsletter.json이 이미 있어요 — 덮어쓰지 않아요(지우고 다시 하거나 직접 고치기)")
    # 레터·픽은 캡션에서 읽은 것을 출발점으로 둔다. 레터는 독자에게 말하는 ~요 말투로 Claude가 다시 쓴다
    data = {
        "letter": cards["letter"],
        "picks": cards["picks"],
        "notice": None,
        # 탭에서 고른 이번 주 소개할 제보(제보 글 주소) — 셰프가 게시판에서 제목·제보자·내용을 읽어 채운다
        "reports": [{"url": u, "title": "", "user": "", "text": ""} for u in cardnews.load(d, "order.json").get("reports", [])],
        "cards": [{"no": c["no"], "title": c["title"], "point": [], "sources": []} for c in cards["cards"]],
        "closing": None,
    }
    cardnews.save(d, "newsletter.json", data)
    print(f"newsletter.json 뼈대 — 카드 {len(data['cards'])}장. 채울 것: letter(다시 쓰기), cards[].point·sources(리서치), 필요하면 notice")


# ── upload ──────────────────────────────────────────────

def upload(d):
    cards = cardnews.load(d, "cards.json")
    table = cardnews.load(d, "media.json", {})
    alt = cardnews.alts(d) if (d / "notes.json").exists() else {}
    need = [photo_of(c) for c in cards["cards"] if photo_of(c) and photo_of(c) not in table]
    for fn in need:
        # 사이트 글(cardnews draft)과 같은 이름·같은 기록 — 어느 쪽이 먼저 올려도 다른 쪽은 다시 안 올린다
        table[fn] = cardnews.upload(d, fn, alt.get(fn, ""))
        cardnews.save(d, "media.json", table)
        print(f"올림 {fn} → 미디어 {table[fn]['id']}")
    print(f"사진 {len(need)}장 올림 (이미 있던 것 {len(cards['cards']) - len(need)}장)")


# ── build ───────────────────────────────────────────────

def card_html(i, c, nl, table, post_url):
    out = [f'<p style="{S["num"]}">{i:02d}</p>', f'<h2 style="{S["h2"]}">{e(c["title"])}</h2>']
    fn = photo_of(c)
    url = table.get(fn, {}).get("url") if fn else None
    if fn and not url:
        sys.exit(f"{c['no']}번 사진 {fn}을 아직 안 올렸어요 — 먼저 upload")
    link = c.get("video", {}).get("url") or (post_url if c.get("clip") else None)
    label = c.get("video", {}).get("label") or ("사이트에서 영상 보기" if link else None)
    if url:
        if link and not c.get("wide"):
            # 세로 영상의 정지 화면은 가운데 작게(모의본 02번). 가로 정지 화면·사진은 칸 가득
            img = (f'<img src="{html.escape(photon(url, 480))}" alt="" width="240" '
                   f'style="display:block;width:240px;max-width:70%;height:auto;margin:0 auto;border:0;">')
        else:
            img = f'<img src="{html.escape(photon(url, 1200))}" alt="" width="536" style="display:block;width:100%;height:auto;border:0;">'
        out.append(f'<a href="{html.escape(link)}">{img}</a>' if link else img)
    if link:
        # 메일 안에서는 영상이 재생되지 않아서 원본(우리 영상은 사이트 글)으로 보내는 링크
        out.append(f'<a href="{html.escape(link)}" style="display:block;text-align:center;margin:10px 0 4px;font-size:13px;font-weight:700;color:#111;">▶ {e(label)}</a>')
    if c.get("src"):
        center = "text-align:center;" if link and not c.get("wide") else ""
        out.append(f'<p style="{S["src"]}{center}">© {e(c["src"])}</p>')
    elif url:
        out.append('<div style="height:18px;line-height:18px;">&nbsp;</div>')
    out.append(f'<p style="{S["h4"]}">무슨 일이었나</p>')
    out += [f'<p style="{S["p"]}">{e(p)}</p>' for p in c["what"]]
    inner = [f'<p style="{S["h4"]}color:#111;">💡 주목 포인트</p>']
    inner += [f'<p style="{S["insight_p"]}">{e(p)}</p>' for p in nl.get("point", [])] or [f'<p style="{S["insight_p"]}color:#c00;">(주목 포인트 작성 전)</p>']
    if nl.get("sources"):
        links = " · ".join(f'<a href="{html.escape(s["url"])}" style="color:#888;">{e(s["label"])}</a>' for s in nl["sources"])
        inner.append(f'<p style="{S["refs"]}">참고: {links}</p>')
    out.append(f'<div style="{S["box"]}">{"".join(inner)}</div>')
    return f'<div style="padding:0 32px;">{"".join(out)}</div>'


def mail_html(order, cards, nl, table, post_url):
    rule = f'<div style="{S["rule"]}"></div>'
    cs = cards["cards"]
    by_no = {x["no"]: x for x in nl["cards"]}
    parts = []
    # 머리: 웰컴메일과 같은 모양 + 오른쪽에 호수
    parts.append(f'<div style="padding:28px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>'
                 f'<td style="width:46px;"><img src="{LOGO}" alt="트렌드포털" width="36" height="36" style="display:block;border-radius:8px;"></td>'
                 f'<td style="font-size:13px;font-weight:800;letter-spacing:0.5px;">TRENDPORTAL</td>'
                 f'<td style="text-align:right;font-size:11px;color:#888;letter-spacing:1px;font-weight:800;">WEEKLY · {e(order["issue"])}</td></tr></table></div>')
    parts.append(f'<div style="padding:40px 32px 0;"><p style="{S["issue"]}">{e(dateline(order["issue"]))}</p>'
                 f'<h1 style="{S["h1"]}">이번 주 일어난<br>마케팅 이슈 총정리</h1></div>')
    letter = "".join(f'<p style="{S["letter_p"]}">{e(p)}</p>' for p in nl["letter"])
    parts.append(f'<div style="padding:28px 32px 0;">{letter}<p style="{S["sig"]}">트렌드포털</p></div>')
    if nl.get("picks"):
        lis = "".join(f'<li>{e(p)}</li>' for p in nl["picks"])
        parts.append(f'<div style="margin:8px 32px 0;{S["box"]}"><span style="{S["box_t"]}">이번 주 에디터 픽</span>'
                     f'<ol style="margin:0;padding-left:18px;font-size:14px;line-height:1.8;font-weight:700;">{lis}</ol></div>')
    # 공지(이벤트 등) — 사이트 목표가 제보 게시판 활성화라 위쪽에 둔다. 모양은 홈 공지 줄의 파란 '공지' 알약과 같은 계열
    if nl.get("notice"):
        n = nl["notice"]
        body = "".join(f'<p style="margin:0 0 10px;font-size:14.5px;line-height:1.8;">{e(p)}</p>' for p in n["text"])
        btn = f'<p style="margin:14px 0 0;"><a href="{html.escape(n["url"])}" style="{S["btn"]}">{e(n["button"])}</a></p>' if n.get("url") else ""
        parts.append(f'<div style="margin:16px 32px 0;background:#f1f5ff;border-radius:10px;padding:18px 20px;">'
                     f'<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#2f6bff;color:#fff;font-size:11px;font-weight:800;margin-bottom:10px;">공지</span>'
                     f'<p style="margin:0 0 8px;font-size:16px;font-weight:800;line-height:1.5;">{e(n["title"])}</p>{body}{btn}</div>')
    # 이번 주 선정 제보(10/30 호부터, 제보 이벤트): 누가 무엇을 제보했는지 + 제보 글 링크. 제보자 이름은 게시판에 공개된 그대로
    if nl.get("reports"):
        items = []
        for r in nl["reports"]:
            text = f'<p style="margin:4px 0 0;font-size:14px;line-height:1.7;">{e(r["text"])}</p>' if r.get("text") else ""
            link = f' <a href="{html.escape(r["url"])}" style="color:#111;font-weight:700;">제보 보기 →</a>' if r.get("url") else ""
            items.append(f'<div style="padding:12px 0;border-top:1px solid #e5e5e5;"><p style="margin:0;font-size:15px;font-weight:800;line-height:1.5;">{e(r["title"])}</p>'
                         f'<p style="margin:2px 0 0;font-size:12px;color:#888;">제보 {e(r.get("user", ""))}{link}</p>{text}</div>')
        parts.append(f'<div style="margin:16px 32px 0;{S["box"]}"><span style="{S["box_t"]}">이번 주 선정 제보 ☕</span>{"".join(items)}'
                     f'<p style="margin:10px 0 0;font-size:12px;color:#888;">선정된 분께는 적어 주신 이메일로 스타벅스 아메리카노 기프티콘을 보내 드려요.</p></div>')
    parts.append(rule)
    # 목차: 메일 프로그램 다수가 #앵커 이동을 지원하지 않아 링크 없이 목록만(모의본과 같음)
    rows = []
    for i, c in enumerate(cs, 1):
        last = "border-bottom:0;" if i == len(cs) else ""
        rows.append(f'<tr><td style="{S["toc_td"]}{last}width:34px;font-weight:800;color:#aaa;">{i:02d}</td>'
                    f'<td style="{S["toc_td"]}{last}">{e(c["title"])}</td></tr>')
    parts.append(f'<div style="padding:0 32px;"><p style="{S["toc_h"]}">이번 주 {len(cs)}가지</p>'
                 f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">{"".join(rows)}</table></div>')
    for i, c in enumerate(cs, 1):
        parts.append(rule)
        parts.append(card_html(i, c, by_no.get(c["no"], {}), table, post_url))
    parts.append(rule)
    cl = "".join(f'<p style="margin:0 0 14px;font-size:15px;line-height:1.8;">{e(p)}</p>' for p in (nl.get("closing") or DEFAULT_CLOSING))
    parts.append(f'<div style="padding:0 32px;"><p style="margin:0 0 10px;font-size:16px;font-weight:800;">여러분의 이번 주 픽은?</p>{cl}'
                 f'<p style="margin:0 0 14px;"><a href="https://trendportal.kr/report/" style="{S["btn"]}">제보하러 가기</a></p>'
                 f'<p style="margin:28px 0 0;font-size:15px;line-height:1.8;">매주 금요일, 국내외에서 실제로 터진 마케팅만 골라 보내 드릴게요.<br>그럼 다음 주 금요일에 만나요!<br><b>트렌드포털</b> 드림</p></div>')
    # 푸터: 광고성 메일이라 수신거부 링크가 꼭 있어야 한다. $%…%$ 는 스티비가 발송할 때 채우는 자리
    parts.append(f'<div style="{S["foot"]}">트렌드포털 · contact@trendportal.kr<br>'
                 f'<a href="$%permalink%$" style="color:#999;">웹에서 보기</a> · <a href="https://www.instagram.com/trend__portal/" style="color:#999;">인스타그램</a> · '
                 f'<a href="https://trendportal.kr" style="color:#999;">trendportal.kr</a><br>'
                 f'더 이상 받고 싶지 않으시면 <a href="$%unsubscribe%$" style="color:#999;">수신거부</a>를 눌러 주세요.</div>')
    return f'<div style="{S["mail"]}">{"".join(parts)}</div>'


def build(d):
    order = cardnews.load(d, "order.json")
    cards = cardnews.load(d, "cards.json")
    nl = cardnews.load(d, "newsletter.json")
    table = cardnews.load(d, "media.json", {})
    result = cardnews.load(d, "result.json", {})
    # 우리 영상은 메일에서 재생이 안 돼서 사이트 주간 글로 보낸다(사이트 초안을 만든 뒤에만 주소가 있다)
    post_url = f"https://trendportal.kr/?p={result['post_id']}" if result.get("post_id") else None
    mail = mail_html(order, cards, nl, table, post_url)
    doc = (f'<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           f'<title>트렌드포털 위클리 {e(order["issue"])}</title></head><body style="{S["page"]}">{mail}</body></html>')
    out = d / "out"
    out.mkdir(exist_ok=True)
    (out / "stibee.html").write_text(doc, encoding="utf-8")
    empty = [x["no"] for x in nl["cards"] if not x.get("point")]
    nosrc = [x["no"] for x in nl["cards"] if x.get("point") and not x.get("sources")]
    print(f"{out / 'stibee.html'} ({len(doc.encode()) // 1024}KB, 카드 {len(cards['cards'])}장)")
    for msg in ([f"주목 포인트가 빈 카드: {empty}"] if empty else []) + ([f"출처 없는 주목 포인트: {nosrc} — 리서치한 사실엔 출처를 단다"] if nosrc else []) \
            + ([] if post_url or not any(c.get("clip") for c in cards["cards"]) else ["우리 영상 카드의 '사이트에서 영상 보기' 링크는 사이트 초안을 만든 뒤 다시 build"]):
        print("· " + msg)


# ── shot: 메일용 눈 ──────────────────────────────────────

def shot(d, widths=(390, 600)):
    """완성된 메일을 폰(390)·메일 칸(600) 폭으로 찍어 shots/newsletter-<폭>-<n>.png(1600px씩)로 나눈다.
    사이트 초안용 cardnews shot과 같은 방법(헤드리스 크롬은 500px보다 좁게 안 그려서 정확한 폭의 iframe을 가운데 두고 자른다)"""
    page = d / "out" / "stibee.html"
    if not page.exists():
        sys.exit("out/stibee.html이 없어요 — 먼저 build")
    sd = d / "shots"
    sd.mkdir(exist_ok=True)
    for old in sd.glob("newsletter-*.png"):
        old.unlink()
    chrome = cardnews.browser()
    cardnews.need_ffmpeg()
    for w in widths:
        h = 16000
        win = max(w, 600)
        frame = sd / f"newsletter-frame-{w}.html"
        frame.write_text(f'<body style="margin:0;display:flex;justify-content:center"><iframe src="{page.resolve().as_uri()}" '
                         f'style="width:{w}px;height:{h}px;border:0;display:block"></iframe>', encoding="utf-8")
        raw = sd / f"newsletter-raw-{w}.png"
        subprocess.run([chrome, "--headless=new", "--no-sandbox", "--disable-gpu", "--hide-scrollbars", "--allow-file-access-from-files",
                        f"--window-size={win},{h}", "--virtual-time-budget=15000", f"--screenshot={raw}", frame.resolve().as_uri()],
                       check=True, capture_output=True, timeout=240)
        x = (win - w) // 2
        # 메일 끝(회색 바탕만 남은 곳)까지 자르면 빈 조각이 생기니, 1600px씩 자르다 내용 높이를 넘으면 멈춘다
        n = 0
        for k in range(h // 1600):
            part = sd / f"newsletter-{w}-{k + 1}.png"
            subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(raw), "-vf", f"crop={w}:1600:{x}:{k * 1600}", str(part)],
                           check=True, capture_output=True)
            n += 1
            # 조각 전체가 한 가지 색(메일이 끝난 뒤의 빈 바탕)뿐이면 끝. 밝기 최저·최고가 거의 같으면 빈 조각
            # (측정값은 info 단계로 찍혀서 -loglevel error면 안 보인다 — 처음 판에서 그래서 빈 조각 3장이 남았다)
            stat = subprocess.run(["ffmpeg", "-hide_banner", "-i", str(part), "-vf", "signalstats,metadata=print", "-f", "null", "-"],
                                  capture_output=True, text=True).stderr
            hi = re.search(r"YMAX=(\d+)", stat)
            lo = re.search(r"YMIN=(\d+)", stat)
            if hi and lo and int(hi.group(1)) - int(lo.group(1)) < 6:
                part.unlink()
                n -= 1
                break
        raw.unlink()
        frame.unlink()
        print(f"{w}px → shots/newsletter-{w}-1~{n}.png")


def main(argv):
    cmds = {"init": init, "upload": upload, "build": build, "shot": shot}
    if len(argv) < 3 or argv[1] not in cmds:
        sys.exit(__doc__)
    cmds[argv[1]](cardnews.order_dir(argv[2]))


if __name__ == "__main__":
    main(sys.argv)
