#!/usr/bin/env python3
"""트렌드포털 뉴스레터 조립 도구 (10/18 시작, 첫 호 10/23)

흐름(10/18 사용자 확정): 목요일 주간 총정리 피그마 zip → Claude가 리서치·작성해 issue.json을 채움
→ 이 도구가 사진을 사이트에 올리고 스티비에 붙여 넣을 HTML을 만듦 → 검토 → 스티비 금요일 08:30 예약 발송.
글을 쓰는 건 이 도구가 아니라 Claude(규칙: 주목 포인트는 카드 밖까지 리서치, 출처 필수).
디자인 = 모의 뉴스레터 docs/트렌드포털-시안비교/1006-4 (사용자 10/18).

명령
  python3 tools/newsletter/newsletter.py upload <호>   issue.json이 가리키는 images/ 사진을 사이트 미디어에 올림(media.json에 기록, 이미 올린 건 건너뜀)
  python3 tools/newsletter/newsletter.py build <호>    out/stibee.html — 스티비 HTML 편집기에 붙여 넣을 것(브라우저로 열면 미리보기)
<호> = local/newsletter/ 아래 폴더 이름(예: 26.10-4) 또는 경로
"""

import html
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools" / "cardnews"))
import cardnews  # noqa: E402  사이트 REST(wp)·사진 줄이기(to_web)를 그대로 쓴다

ISSUES = ROOT / "local" / "newsletter"
LOGO = "https://i0.wp.com/trendportal.kr/wp-content/uploads/2026/09/cropped-tp-icon-square.png?fit=128%2C128&ssl=1"
FONT = "-apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif"

# 메일 프로그램(특히 Gmail)이 <style>을 지우는 경우가 있어서 꾸밈은 전부 태그에 직접 적는다(인라인).
# 값은 모의본 1006-4의 class 값을 그대로 옮긴 것
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


def e(s):
    # 글자 안전하게 + json에서 줄바꿈("\n")으로 적은 곳은 <br>
    return "<br>".join(html.escape(line) for line in str(s).split("\n"))


def issue_dir(name):
    p = Path(name)
    return p if p.is_dir() else ISSUES / name


def photon(url, w):
    # 사이트 사진은 Jetpack 사진 서버(i0.wp.com)로 폭을 줄여 보낸다 — 메일은 600px 칸이라 2배(1200)면 충분, 용량은 훨씬 작다
    if url.startswith("https://trendportal.kr/wp-content/uploads/"):
        return "https://i0.wp.com/" + url[len("https://"):] + f"?w={w}&ssl=1"
    return url


def image_url(media, ref):
    # issue.json의 사진 = 이미 있는 주소(https://…) 또는 images/ 안 파일 이름(upload 명령이 media.json에 주소를 적어 둔다)
    if ref.startswith("https://"):
        return ref
    if ref not in media:
        sys.exit(f"사진 {ref} 를 아직 안 올렸어요 — 먼저 upload")
    return media[ref]


# ── upload ──────────────────────────────────────────────

def upload(d):
    issue = cardnews.load(d, "issue.json")
    media = cardnews.load(d, "media.json", {})
    refs = [c["image"] for c in issue["cards"] if c.get("image") and not c["image"].startswith("https://")]
    for ref in refs:
        if ref in media:
            continue
        src = d / "images" / ref
        if not src.exists():
            sys.exit(f"{src} 가 없어요")
        web = d / "web"
        web.mkdir(exist_ok=True)
        fn = cardnews.to_web(src, web / (Path(ref).stem + ".jpg")) if src.suffix.lower() == ".png" else ref
        path = web / fn if (web / fn).exists() else src
        # 올리는 이름 앞에 호 이름을 붙인다 — 매주 01.jpg끼리 겹치면 WordPress가 01-1.jpg로 바꿔 버려서
        name = f"newsletter-{d.name}-{Path(fn).name}"
        m = cardnews.wp("media", path.read_bytes(), {"Content-Type": "image/jpeg" if path.suffix.lower() in (".jpg", ".jpeg") else "image/png",
                                                     "Content-Disposition": f'attachment; filename="{name}"'})
        media[ref] = m["source_url"]
        cardnews.save(d, "media.json", media)
        print(f"올림 {ref} → {m['source_url']}")
    print(f"사진 {len(refs)}장 확인, media.json 저장")


# ── build ───────────────────────────────────────────────

def card_html(i, c, media):
    out = [f'<p style="{S["num"]}">{i:02d}</p>', f'<h2 style="{S["h2"]}">{e(c["title"])}</h2>']
    if c.get("image"):
        url = image_url(media, c["image"])
        if c.get("video"):
            # 영상 카드: 세로 썸네일을 가운데 작게 + 영상 보기 링크(메일 안에서는 영상이 재생되지 않는다)
            v = c["video"]
            out.append(f'<a href="{html.escape(v["url"])}"><img src="{html.escape(photon(url, 480))}" alt="" width="240" '
                       f'style="display:block;width:240px;max-width:70%;height:auto;margin:0 auto;border:0;"></a>')
            out.append(f'<a href="{html.escape(v["url"])}" style="display:block;text-align:center;margin:10px 0 4px;font-size:13px;font-weight:700;color:#111;">'
                       f'▶ {e(v.get("label", "영상 보기"))}</a>')
        else:
            out.append(f'<img src="{html.escape(photon(url, 1200))}" alt="" width="536" style="display:block;width:100%;height:auto;border:0;">')
    if c.get("credit"):
        center = "text-align:center;" if c.get("video") else ""
        out.append(f'<p style="{S["src"]}{center}">{e(c["credit"])}</p>')
    out.append(f'<p style="{S["h4"]}">무슨 일이었나</p>')
    out += [f'<p style="{S["p"]}">{e(p)}</p>' for p in c["what"]]
    inner = [f'<p style="{S["h4"]}color:#111;">💡 주목 포인트</p>']
    inner += [f'<p style="{S["insight_p"]}">{e(p)}</p>' for p in c["point"]]
    if c.get("sources"):
        links = " · ".join(f'<a href="{html.escape(s["url"])}" style="color:#888;">{e(s["label"])}</a>' for s in c["sources"])
        inner.append(f'<p style="{S["refs"]}">참고: {links}</p>')
    out.append(f'<div style="{S["box"]}">{"".join(inner)}</div>')
    return f'<div style="padding:0 32px;">{"".join(out)}</div>'


def mail_html(issue, media):
    rule = f'<div style="{S["rule"]}"></div>'
    cards = issue["cards"]
    parts = []
    # 머리: 웰컴메일과 같은 모양 + 오른쪽에 호수
    parts.append(f'<div style="padding:28px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;"><tr>'
                 f'<td style="width:46px;"><img src="{LOGO}" alt="트렌드포털" width="36" height="36" style="display:block;border-radius:8px;"></td>'
                 f'<td style="font-size:13px;font-weight:800;letter-spacing:0.5px;">TRENDPORTAL</td>'
                 f'<td style="text-align:right;font-size:11px;color:#888;letter-spacing:1px;font-weight:800;">WEEKLY · {e(issue["issue"])}</td></tr></table></div>')
    parts.append(f'<div style="padding:40px 32px 0;"><p style="{S["issue"]}">{e(issue["dateline"])}</p>'
                 f'<h1 style="{S["h1"]}">{"<br>".join(e(t) for t in issue["title"])}</h1></div>')
    letter = "".join(f'<p style="{S["letter_p"]}">{e(p)}</p>' for p in issue["letter"])
    parts.append(f'<div style="padding:28px 32px 0;">{letter}<p style="{S["sig"]}">트렌드포털</p></div>')
    if issue.get("picks"):
        lis = "".join(f'<li>{e(p)}</li>' for p in issue["picks"])
        parts.append(f'<div style="margin:8px 32px 0;{S["box"]}"><span style="{S["box_t"]}">이번 주 에디터 픽</span>'
                     f'<ol style="margin:0;padding-left:18px;font-size:14px;line-height:1.8;font-weight:700;">{lis}</ol></div>')
    # 공지(이벤트 등): 첫 호는 제보 이벤트 시작 안내. 사이트 목표가 제보 게시판 활성화라 위쪽에 둔다
    if issue.get("notice"):
        n = issue["notice"]
        body = "".join(f'<p style="margin:0 0 10px;font-size:14.5px;line-height:1.8;">{e(p)}</p>' for p in n["text"])
        btn = f'<p style="margin:14px 0 0;"><a href="{html.escape(n["url"])}" style="{S["btn"]}">{e(n["button"])}</a></p>' if n.get("url") else ""
        parts.append(f'<div style="margin:16px 32px 0;background:#f1f5ff;border-radius:10px;padding:18px 20px;">'
                     f'<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#2f6bff;color:#fff;font-size:11px;font-weight:800;margin-bottom:10px;">공지</span>'
                     f'<p style="margin:0 0 8px;font-size:16px;font-weight:800;line-height:1.5;">{e(n["title"])}</p>{body}{btn}</div>')
    parts.append(rule)
    # 목차: 메일 프로그램 다수가 #앵커 이동을 지원하지 않아 링크 없이 목록만(모의본과 같음)
    rows = []
    for i, c in enumerate(cards, 1):
        last = "border-bottom:0;" if i == len(cards) else ""
        rows.append(f'<tr><td style="{S["toc_td"]}{last}width:34px;font-weight:800;color:#aaa;">{i:02d}</td>'
                    f'<td style="{S["toc_td"]}{last}">{e(c["title"])}</td></tr>')
    parts.append(f'<div style="padding:0 32px;"><p style="{S["toc_h"]}">이번 주 {len(cards)}가지</p>'
                 f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">{"".join(rows)}</table></div>')
    for i, c in enumerate(cards, 1):
        parts.append(rule)
        parts.append(card_html(i, c, media))
    parts.append(rule)
    closing = issue.get("closing") or [
        "이번 주 가장 인상 깊었던 마케팅이 있다면 이 메일에 답장으로 알려 주세요.",
        "먼저 본 트렌드가 있다면 제보해 주세요. 뉴스레터에 실린 제보를 보내 주신 분께는 스타벅스 아메리카노 기프티콘을 드려요.",
    ]
    cl = "".join(f'<p style="margin:0 0 14px;font-size:15px;line-height:1.8;">{e(p)}</p>' for p in closing)
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
    issue = cardnews.load(d, "issue.json")
    media = cardnews.load(d, "media.json", {})
    mail = mail_html(issue, media)
    title = f"트렌드포털 위클리 {issue['issue']}"
    doc = (f'<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           f'<title>{e(title)}</title></head><body style="{S["page"]}">{mail}</body></html>')
    out = d / "out"
    out.mkdir(exist_ok=True)
    (out / "stibee.html").write_text(doc, encoding="utf-8")
    print(f"{out / 'stibee.html'} ({len(doc.encode()) // 1024}KB, 카드 {len(issue['cards'])}장)")


def main(argv):
    if len(argv) < 3 or argv[1] not in ("upload", "build"):
        sys.exit(__doc__)
    d = issue_dir(argv[2])
    {"upload": upload, "build": build}[argv[1]](d)


if __name__ == "__main__":
    main(sys.argv)
