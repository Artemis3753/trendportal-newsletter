#!/usr/bin/env python3
"""트란시스 카드뉴스 도우미 — 주방(1단계).

주문 폴더 하나(local/cardnews/<주문>/)를 받아 단계별로 처리한다.

    python3 tools/cardnews/cardnews.py pull                (관리자 탭 "트란시스 카드뉴스 도우미"의 새 주문 → local/cardnews/order-<번호>/)
    python3 tools/cardnews/cardnews.py fetch   <주문>     (아카이빙만, 피그마 플러그인의 figma/scan.json 또는 MCP 결과 파일 필요)
    python3 tools/cardnews/cardnews.py build   <주문>
    python3 tools/cardnews/cardnews.py draft   <주문>     (사진 올리기 + 비공개 초안. 예전 이름 publish도 됨)
    python3 tools/cardnews/cardnews.py verify  <주문> [--against 글번호]
    python3 tools/cardnews/cardnews.py report  <주문> [--fail "이유" | --ask "질문"]   (결과·멈춘 이유·질문을 관리자 탭에 적는다)
    python3 tools/cardnews/cardnews.py css     <주문> <파일.css> | --remove  (새 디자인 CSS를 사이트에 붙이기·빼기)
    python3 tools/cardnews/cardnews.py still   <주문> <카드 번호> <초>        (영상 카드 정지 화면·포스터를 그 초 장면으로)
    python3 tools/cardnews/cardnews.py profile <주문> <대상 번호> [x y 한 변]  (인터뷰 대상 프로필 사진을 캡처에서 정사각형으로)
    python3 tools/cardnews/cardnews.py ref     <글 번호>                     (정답 예시 글의 블록 원문 보기, 읽기만)
    python3 tools/cardnews/cardnews.py cook    <주문> [--pc]                 (build → draft → verify → shot 한 번에, 판단이 끝난 뒤)
    python3 tools/cardnews/cardnews.py shot    <주문> [--pc]                 (초안이 폰에서(--pc면 PC도) 어떻게 보이는지 찍기 → shots/)
    python3 tools/cardnews/cardnews.py visual  <주문> <카드> <위%> <아래%>   (사진 없는 카드의 그림 칸을 카드 미리보기에서 다시 자르기)

주문 폴더:
    order.json   주문서: kind(weekly|archive), issue(주간 호수) 또는 title(아카이빙 제목),
                 featured(대표 이미지), cover_photo(아카이빙 글자 없는 커버), categories, tags
    cards.json   카드 글자·사진 이름 (fetch 단계 결과 — 아직은 손으로/임시 스크립트로 만든다)
                 글자 속 줄바꿈은 "\\n"으로 적는다 (<br>로 바뀜)
    notes.json   Claude가 쓰는 칸만: 카드별 alt·💡(주간), cover_alt, poster_alt
    images/      사진·영상 파일 (사이트에 올릴 것)
    uploads/     탭 영상 카드 칸에서 받은 원본 파일 (NN-src.*, 사이트에 그대로 올리지 않음)
    media.json   draft가 채움: 파일 이름 → 미디어 번호·주소 (다시 돌려도 두 번 올리지 않게)
    post.html    build 결과 (블록 HTML)
    result.json  draft·verify 결과 (초안 번호·미리보기 링크·대조 결과)
    changes.json Claude가 고친 것·확인할 것 목록(문장 배열) — report가 탭에 그대로 보여 준다

표준 라이브러리만 쓴다 — 나중에 클라우드 routine에서 설치 없이 돌리려고.
"""
import base64
import html
import json
import mimetypes
import os
import re
import shutil
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
import zipfile
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ORDERS = ROOT / "local" / "cardnews"
ENV = ROOT / "local" / "cardnews.env"
API = "https://trendportal.kr/wp-json/wp/v2/"
ORDER_API = "https://trendportal.kr/wp-json/tp-cardnews/v1/"  # TP Cardnews Helper 플러그인의 주문 창구


# ── 파일 ────────────────────────────────────────────────

def order_dir(name):
    # 이름만 주면 local/cardnews/ 아래에서, 경로를 주면 그대로
    p = Path(name)
    return p if p.is_dir() else ORDERS / name


def load(d, fn, default=None):
    p = d / fn
    if not p.exists():
        if default is not None:
            return default
        sys.exit(f"{p} 가 없어요")
    return json.loads(p.read_text(encoding="utf-8"))


def save(d, fn, data):
    (d / fn).write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


# ── WordPress REST ─────────────────────────────────────

def auth_header():
    # 이 맥: local/cardnews.env / 클라우드 routine: 비밀번호가 환경의 "network secret"에 있어서
    # 요청이 VM을 나간 뒤 Anthropic 쪽 프록시가 붙여 준다 — 그래서 여기선 아무것도 안 붙인다(셰프가 값을 못 보게)
    env = {}
    if ENV.exists():
        # 값에 공백이 있어서(애플리케이션 비밀번호) 셸 source 대신 직접 읽는다
        for line in ENV.read_text(encoding="utf-8").splitlines():
            if "=" in line and not line.lstrip().startswith("#"):
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    if not env.get("WP_USER"):
        return {}
    token = base64.b64encode(f"{env['WP_USER']}:{env['WP_APP_PASSWORD']}".encode()).decode()
    return {"Authorization": "Basic " + token}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    # urllib은 POST가 301·302를 받으면 몰래 GET으로 바꿔 따라간다 → 주문 보고가 "그런 창구 없음(404)"으로 끝났다(10/17 주문 816).
    # 우리 요청은 늘 정확한 주소로 가니 넘겨받는 일 자체가 이상한 상황 → 따라가지 않고 오류로 받아서 아래에서 다시 시도한다
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def wp(path, data=None, headers=None, method=None, base=API, raw=False):
    h = dict(auth_header(), **(headers or {}))
    if isinstance(data, (dict, list)):
        data = json.dumps(data).encode()
        h["Content-Type"] = "application/json"
    url = path if path.startswith("https://") else base + path
    # 잠깐 흔들리는 경우는 기다렸다 다시 보낸다:
    # · 429(요청이 너무 많음)·5xx — 클라우드에서 첫 요청이 429로 막힌 적이 있다(10/17 환경 시험)
    # · 연결 끊김, 넘겨받기(3xx), 401 — 10/17 주문 816: 클라우드와 사이트 사이 중계가 끊긴 40초 동안 비밀번호 없이 도착해 401,
    #   보고는 GET으로 바뀌어 404가 났고, 바로 앞뒤 요청은 같은 인증으로 멀쩡했다. 비밀번호가 정말 틀렸다면 네 번 다 401이라 결국 멈춘다
    # 5xx는 드물게 저장은 된 채 오류만 올 수 있어 사진·초안이 하나 더 생길 수 있지만, 주문이 통째로 멈추는 것보다 낫다
    for wait in (10, 30, 60, None):
        req = urllib.request.Request(url, data=data, headers=h, method=method)
        try:
            with OPENER.open(req, timeout=300) as r:
                return r.read() if raw else json.load(r)
        except urllib.error.HTTPError as e:
            if wait and (e.code in (401, 429) or e.code >= 500 or 300 <= e.code < 400):
                print(f"WordPress {e.code} — {wait}초 뒤 다시 해요", file=sys.stderr)
                time.sleep(wait)
                continue
            # 응답 본문 앞부분만 보여 준다 (덩어리 전체를 찍으면 읽기도 어렵고 길다)
            sys.exit(f"WordPress 오류 {e.code} {path}: {e.read()[:300].decode('utf-8', 'replace')}")
        except (urllib.error.URLError, ConnectionError, TimeoutError) as e:
            if not wait:
                sys.exit(f"사이트에 연결하지 못했어요 {path}: {e}")
            print(f"연결이 끊겼어요({e}) — {wait}초 뒤 다시 해요", file=sys.stderr)
            time.sleep(wait)


# ── build: 주간 총정리 틀 ───────────────────────────────

def esc(s):
    # 본문 글자: &, <, > 만 바꾼다 (WordPress 편집기가 저장하는 모양과 같게)
    # json에서 줄바꿈("\n")으로 적은 곳은 <br>로 — 사람이 json을 고칠 때 태그를 안 써도 되게
    return "<br>".join(html.escape(line, quote=False) for line in s.split("\n"))


def attr(s):
    # 속성은 큰따옴표로 감싸니 "만 더 바꾼다 (작은따옴표까지 바꾸면 편집기 저장본과 달라짐)
    return html.escape(s, quote=False).replace('"', "&quot;")


def para(text, cls=None):
    if cls:
        return f'<!-- wp:paragraph {{"className":"{cls}"}} --><p class="{cls}">{text}</p><!-- /wp:paragraph -->'
    return f"<!-- wp:paragraph --><p>{text}</p><!-- /wp:paragraph -->"


def group(cls, inner):
    return (f'<!-- wp:group {{"className":"{cls}","layout":{{"type":"default"}}}} -->'
            f'<div class="wp-block-group {cls}">{inner}</div><!-- /wp:group -->')


def h2(text):
    return f'<!-- wp:heading --><h2 class="wp-block-heading">{esc(text)}</h2><!-- /wp:heading -->'


def h4(text):
    # 틀에는 h4로 두고 TP Weekly Lock이 그릴 때 h3로 바꾼다 (기존 글과 같은 방식)
    return f'<!-- wp:heading {{"level":4}} --><h4 class="wp-block-heading">{text}</h4><!-- /wp:heading -->'


class Media:
    """파일 이름 → 미디어 번호·주소. 아직 안 올린 파일은 자리표시로 두고 missing에 모은다."""

    def __init__(self, table):
        self.table, self.missing = table, []

    def get(self, fn):
        m = self.table.get(fn)
        if m:
            return m["id"], m["url"]
        if fn not in self.missing:
            self.missing.append(fn)
        return f"{{{{id:{fn}}}}}", f"{{{{url:{fn}}}}}"


def image_block(media, fn, alt, wide=False, size="full", cls=None, link_none=False):
    mid, url = media.get(fn)
    # 가로 정지 화면(X·유튜브)은 tp-wk-wide를 붙여야 CSS가 240px로 줄이지 않는다
    cls = "tp-wk-wide" if wide else cls
    extra = ',"linkDestination":"none"' if link_none else ""
    extra += f',"className":"{cls}"' if cls else ""
    return (f'<!-- wp:image {{"id":{mid},"sizeSlug":"{size}"{extra}}} -->'
            f'<figure class="wp-block-image size-{size}{" " + cls if cls else ""}">'
            f'<img src="{url}" alt="{attr(alt)}" class="wp-image-{mid}"/></figure><!-- /wp:image -->')


def video_link(v, cls=None):
    # 남의 영상: 정지 화면 아래 "▶ ○○에서 영상 보기" 한 줄 (영상 자체는 원본 계정에서)
    return para(f'▶ <a href="{attr(v["url"])}" target="_blank" rel="noreferrer noopener">{esc(v["label"])}</a>', cls)


def clip_block(media, fn, poster):
    # 우리가 가진 영상(소리 뺀 것): 버튼 없이 자동 재생·반복, 아이폰도 자동 재생되게 playsinline
    mid, url = media.get(fn)
    _, purl = media.get(poster)
    return (f'<!-- wp:video {{"id":{mid}}} --><figure class="wp-block-video"><video autoplay loop muted '
            f'poster="{purl}" preload="auto" src="{url}" playsinline></video></figure><!-- /wp:video -->')


def card_block(media, card, note):
    out = h2(card["title"])
    if card.get("clip"):
        out += clip_block(media, card["clip"], card["poster"])
    if card.get("image"):
        out += image_block(media, card["image"], note.get("alt", ""), card.get("wide", False))
    if card.get("video"):
        out += video_link(card["video"], "tp-wk-video")
    if card.get("src"):
        out += para("© " + esc(card["src"]), "tp-wk-src")
    out += h4("무슨 일이었나")
    out += "".join(para(esc(p)) for p in card["what"])
    out += group("tp-wk-insight", h4("💡 주목 포인트") + para(esc(note["insight"])))
    return group("tp-wk-item", out)


# 매주 같은 끝맺음 (주간 총정리 규칙: "매주 금요일…" 문단은 맨 끝)
WEEKLY_END = group("tp-wk-end", (
    h2("여러분의 이번 주 픽은?")
    + para("이번 주 가장 인상 깊었던 마케팅이 있다면 <strong>댓글로</strong> 알려 주세요. 다음 호에서 소개할게요.")
    + para("먼저 본 트렌드가 있다면 제보도 언제든 환영이에요.")
    + para('<a href="/report/">제보하러 가기</a>', "tp-wk-btn")
    + para("매주 금요일, 국내외에서 실제로 터진 마케팅만 골라 보내 드릴게요.<br>그럼 다음 주 금요일에 만나요!", "tp-wk-sig")
))

# 목차·카드 번호·잠금은 플러그인(TP Weekly Lock)이 그릴 때 채운다
WEEKLY_TOC = ('<!-- wp:html --><nav class="tp-wk-tocbox"><!-- 목차·카드 번호·잠금은 플러그인이 자동으로 채워요. '
              '이 칸은 지우지 마세요 --></nav><!-- /wp:html -->')


def build_weekly(order, cards, notes, media):
    if len(notes["cards"]) != len(cards["cards"]):
        sys.exit(f"카드 {len(cards['cards'])}장인데 notes는 {len(notes['cards'])}장이에요")
    letter = "".join(para(esc(p)) for p in cards["letter"])
    picks = "".join(f"<!-- wp:list-item --><li>{esc(p)}</li><!-- /wp:list-item -->" for p in cards["picks"])
    body = (
        para(f"WEEKLY · {esc(order['issue'])} · 금요일 발행", "tp-wk-issue")
        + group("tp-wk-letter", letter + para("트렌드포털", "tp-wk-sig"))
        + group("tp-wk-pick", para("이번 주 에디터 픽", "tp-wk-label")
                + f'<!-- wp:list {{"ordered":true}} --><ol class="wp-block-list">{picks}</ol><!-- /wp:list -->')
        + WEEKLY_TOC
        + "".join(card_block(media, c, n) for c, n in zip(cards["cards"], notes["cards"]))
        + WEEKLY_END
    )
    return group("tp-wk", body)


# ── build: 아카이빙 틀 (기준 글 242) ─────────────────────
# 글자 없는 커버 사진 → 인스타 캡션 앞부분 → 카드마다 사진 → (영상 링크) → H2 → 설명 → 출처 → 캡션 끝맺음

def iv_heading(label, text):
    # 인터뷰 질문 소제목: 498과 같은 마크업. 라벨 뒤 공백은 검색·글자 추출에서 "QUESTION 01대체…"로 붙지 않게(10/15)
    return ('<!-- wp:heading {"className":"tp-iv-q"} -->'
            f'<h2 class="wp-block-heading tp-iv-q"><span class="tp-q">{label}</span> {esc(text)}</h2><!-- /wp:heading -->')


def insta_handle(url):
    # "https://www.instagram.com/luxmag.kr/?igsh=…" → "luxmag.kr"
    return re.sub(r"^https?://(www\.)?instagram\.com/", "", url or "").split("/")[0].split("?")[0]


SPACER = ('<!-- wp:spacer {"height":"40px"} -->'
          '<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->')


def panel_cards(media, cards, notes, people=()):
    """인터뷰 패널형(734 — 질문 하나에 여러 명이 답한 카드). 정답 예시였던 글 843(10/17 승인, 498 방식)은 10/18 발행 없이 삭제됐다 — 모양은 docs/post-formats.md 규칙대로.
    같은 질문 카드끼리 묶어 QUESTION 소제목 한 번 → 답한 사람(cards[].who)마다 흰 프로필 카드(498의 tp-ad-profile) → 답.
    사람 사이엔 40px 여백(spacer 블록, CSS 없이). 답한 사람이 없는 카드(734의 2번 안내 카드)는 글만 그 질문 소제목 앞에 둔다.
    프로필 사진·인스타 주소 = 인터뷰 폼에 넣은 대상 중 계정이 같은 사람 것, 없으면 카드에서 잘라 낸 얼굴과 카드의 계정.
    (처음 만든 '카드 모양 어두운 판(.tp-pn-card)'은 웹폼 실전 뒤 사용자가 498식 모양을 골라서 쓰지 않는다)"""
    by_handle = {insta_handle(p.get("insta")).lower(): p for p in people if p.get("insta")}
    groups = []
    for card, note in zip(cards, notes):
        if groups and groups[-1][0] == card["title"]:
            groups[-1][1].append((card, note))
        else:
            groups.append((card["title"], [(card, note)]))
    out = ""
    for i, (title, items) in enumerate(groups, start=1):
        out += "".join(para(esc(p)) for c, _ in items if not c.get("who") for p in c["what"])
        out += iv_heading("QUESTION" if len(groups) == 1 else f"QUESTION {i:02d}", re.sub(r"^Q\.\s*", "", title).replace("\n", " "))
        first = True
        for c, note in items:
            if not c.get("who"):
                continue
            w = c["who"]
            p = by_handle.get(w["handle"].lower(), {})
            person = {"name": w.get("name") or p.get("name") or w["handle"],
                      "insta": p.get("insta") or f"https://www.instagram.com/{w['handle']}/",
                      "photo": p.get("photo") or c.get("image")}
            out += ("" if first else SPACER) + profile_cards(media, [person])
            out += "".join(para(esc(x)) for x in c["what"])
            first = False
    return out


def qa_cards(media, cards, notes):
    """인터뷰 질문 여러 개(498식, 탭 1.8.0 인터뷰 폼). 카드 하나 = 질문 소제목 → 사진 → 답.
    카드뉴스 순서(사진 → 소제목)와 반대다: 소제목 위 검정 선이 사진을 앞 문답에서 끊어 주려고(10/13 사용자).
    셰프가 cards.json에 "quote": true를 단 카드는 질문이 아닌 인용 소제목이라 QUESTION 번호 없이 쓴다"""
    out, n = "", 0
    for card, note in zip(cards, notes):
        text = card["title"].replace("\n", " ")
        if card.get("quote"):
            out += ('<!-- wp:heading {"className":"tp-iv-q"} -->'
                    f'<h2 class="wp-block-heading tp-iv-q">{esc(text)}</h2><!-- /wp:heading -->')
        else:
            n += 1
            out += iv_heading(f"QUESTION {n:02d}", re.sub(r"^Q\s*\d*\s*[.:]\s*", "", text))
        if card.get("image"):
            out += image_block(media, card["image"], note.get("alt", ""), size="large", link_none=True)
        out += "".join(para(esc(p)) for p in card["what"])
        if card.get("src"):
            out += ('<!-- wp:paragraph {"className":"tp-source","fontSize":"small"} -->'
                    f'<p class="tp-source has-small-font-size">© {esc(card["src"])}</p><!-- /wp:paragraph -->')
    return out


def profile_cards(media, people, sponsored=False):
    """인터뷰 대상 프로필 카드(498·광고 글 439와 같은 마크업). 인스타 주소가 있는 사람만 — 카드 전체가 그 링크라서.
    사진은 이름 옆 꾸밈이라 alt를 비운다(이름이 바로 옆에 글자로 있다).
    광고 글의 브랜드 카드는 돈 받은 링크라 rel에 sponsored를 단다(439와 같다, 구글이 유료 링크에 요구하는 표시)"""
    rel = "noreferrer noopener sponsored" if sponsored else "noreferrer noopener"
    out = ""
    for p in people:
        if not p.get("insta"):
            continue
        handle = insta_handle(p["insta"])
        url = f"https://www.instagram.com/{handle}/"
        avatar = ""
        if p.get("photo"):
            _, src = media.get(p["photo"])
            avatar = f'<span class="tp-ad-avatar"><img src="{src}" alt=""></span>'
        name = p.get("name") or handle
        out += ('<!-- wp:html -->'
                f'<a class="tp-ad-profile" href="{attr(url)}" target="_blank" rel="{rel}">{avatar}'
                f'<span class="tp-ad-name"><b>{esc(name)}</b><small>@{esc(handle)}</small></span>'
                '<span class="tp-ad-btn">프로필 보기</span></a><!-- /wp:html -->')
    return out


def build_archive(order, cards, notes, media):
    if len(notes["cards"]) != len(cards["cards"]):
        sys.exit(f"카드 {len(cards['cards'])}장인데 notes는 {len(notes['cards'])}장이에요")
    out = ""
    if order.get("cover_photo"):
        # TP Cover Photo가 이 이름표(tp-cover-photo) 사진을 글 머리 커버로 옮긴다
        out += image_block(media, order["cover_photo"], notes.get("cover_alt", ""),
                           size="large", cls="tp-cover-photo", link_none=True)
    intro = [para(esc(p)) for p in cards["intro"]]
    # 인터뷰 프로필 카드: 시작 카드는 첫 줄 "[ 트포 인터뷰 : … ]" 바로 아래(광고 글 439의 [광고] 줄 자리와 같다), 끝 카드는 맨 끝
    # 패널형은 대상을 판 안 얼굴로 쓰니 프로필 카드를 따로 안 넣는다(panel_cards 참고)
    ad = bool(order.get("ad"))
    profiles = "" if order.get("layout") == "panel" else profile_cards(media, order.get("people") or [], sponsored=ad)
    if ad:
        # 광고 폼(탭 1.11.0, 10/18 사용자): 맨 위에 439와 같은 [광고] 표시 줄 → 바로 아래 브랜드 카드, 끝에 한 번 더.
        # 표시 줄은 캡션에 없더라도 늘 넣는다(유료 광고는 글 첫머리에서 밝혀야 해서)
        intro.insert(0, para("<strong>[광고]</strong> 이 글은 유료 광고를 포함하고 있습니다."))
        intro.insert(1, profiles)
    elif profiles:
        at = 1 if cards["intro"] and cards["intro"][0].lstrip().startswith("[") else 0
        intro.insert(at, profiles)
    out += "".join(intro)
    # 카피 모음형(764식)은 원본처럼 어두운 전시장 띠 하나에 카드를 이어 붙인다
    # (사용자 결정 C, 10/17 — 시안 docs/트렌드포털-시안비교/1017-1-*). 모양은 additional.css의 .tp-copy-stage
    # 처음엔 카드마다 "-브랜드-" 줄이 있으면 자동으로 골랐지만, 카드 모양만 보고 틀을 붙이면 엉뚱한 글에도 띠가 생길 수 있어
    # order.json에 "layout": "copy"라고 적은 주문만 쓴다(10/17 사용자: 일단 764 글에만)
    copy = order.get("layout") == "copy"
    # 인터뷰 형식(패널·질문 여러 개)은 카드 줄을 따로 만든다 → 아래 일반 카드 줄은 건너뛴다
    interview = order.get("layout") in ("panel", "qa")
    if order.get("layout") == "panel":
        out += panel_cards(media, cards["cards"], notes["cards"], order.get("people") or [])
    elif order.get("layout") == "qa":
        out += qa_cards(media, cards["cards"], notes["cards"])
    if copy:
        out += ('<!-- wp:group {"align":"full","className":"tp-copy-stage","layout":{"type":"default"}} -->'
                '<div class="wp-block-group alignfull tp-copy-stage">')
    for card, note in ([] if interview else zip(cards["cards"], notes["cards"])):
        if copy:
            out += '<!-- wp:group {"className":"tp-copy","layout":{"type":"default"}} --><div class="wp-block-group tp-copy">'
        if card.get("clip"):
            out += clip_block(media, card["clip"], card["poster"])
        if card.get("image"):
            out += image_block(media, card["image"], note.get("alt", ""), size="large")
        if card.get("video"):
            out += video_link(card["video"])
        out += h2(card["title"])
        if card.get("brand"):
            # 카피 모음형: 카드처럼 카피(제목) 바로 아래 브랜드 (사용자 결정 B, 10/17). 앞뒤 "—"는 CSS가 붙인다
            out += para(esc(card["brand"]), "tp-brand")
        out += "".join(para(esc(p)) for p in card["what"])
        if card.get("src"):
            out += ('<!-- wp:paragraph {"className":"tp-source","fontSize":"small"} -->'
                    f'<p class="tp-source has-small-font-size">© {esc(card["src"])}</p><!-- /wp:paragraph -->')
        if copy:
            out += "</div><!-- /wp:group -->"
    if copy:
        out += "</div><!-- /wp:group -->"
    out += "".join(para(esc(p)) for p in cards.get("outro", []))
    out += profiles
    if order.get("design"):
        # 셰프가 새 디자인을 만든 글: 커버 뒤 본문 전체를 이름표 하나로 감싼다.
        # `css` 명령은 이 이름표로 시작하는 규칙만 받아서, 새 CSS가 다른 글에 닿지 않는다(10/17 사용자: 새 규칙은 새 class 안에만)
        cls = design_class(order)
        cover = image_block(media, order["cover_photo"], notes.get("cover_alt", ""), size="large",
                            cls="tp-cover-photo", link_none=True) if order.get("cover_photo") else ""
        out = cover + group(cls, out[len(cover):])
    return out


def design_class(order):
    cls = order["design"]
    if not re.fullmatch(r"tp-fmt-[a-z0-9-]+", cls):
        sys.exit(f"design 이름표는 tp-fmt-영문소문자 모양이어야 해요: {cls}")
    return cls


def title_of(order):
    if order["kind"] == "weekly":
        return order.get("title") or f"이번 주 일어난 마케팅 이슈 총정리 ({order['issue']})"
    if not order.get("title"):
        sys.exit("order.json에 title이 없어요 (아카이빙 제목은 사람이 골라서 적어요)")
    return order["title"]


BUILDERS = {"weekly": build_weekly, "archive": build_archive}


def build(d, quiet=False):
    order, cards, notes = load(d, "order.json"), load(d, "cards.json"), load(d, "notes.json")
    media = Media(load(d, "media.json", {}))
    if order["kind"] not in BUILDERS:
        sys.exit(f"모르는 종류예요: {order['kind']} (weekly / archive)")
    hand = d / "body.html"
    if hand.exists():
        # 셰프가 직접 짠 본문: 맞는 틀이 없을 때 셰프도 여기(Claude Code)처럼 직접 짠다(10/17 사용자 — 웹폼 결과가 대화 결과만 못해서).
        # 사진 자리는 {{url:파일}}·{{id:파일}}로 적으면 올린 뒤 실제 주소·번호로 바뀐다. 틀 대신 이걸 쓰니 draft가 덮어쓰지 않는다
        def fill(m):
            mid, url = media.get(m.group(2))
            return str(mid) if m.group(1) == "id" else url
        out = re.sub(r"\{\{(url|id):([^}]+)\}\}", fill, hand.read_text(encoding="utf-8"))
    else:
        out = BUILDERS[order["kind"]](order, cards, notes, media)
    (d / "post.html").write_text(out, encoding="utf-8")
    if not quiet:
        print(f"post.html {len(out):,}자 · 카드 {len(cards['cards'])}장 · 아직 안 올린 파일 {len(media.missing)}개")
    return out, media.missing


# ── fetch: 피그마 묶음 → cards.json (+ 커버·대표 사진, 영상 정지 화면) ──
# 기본은 피그마 플러그인(tools/figma-plugin)의 zip을 주문 폴더에 푼 것 (10/17, 한도 없음):
#   figma/scan.json          카드별 글자·사진 레이어(hash)
#   figma/images/<hash>.*    사진 원본 (카드에서 잘라 보인 구도가 아니라 올린 파일 그대로)
#   figma/cover-export.png   글자까지 다 있는 표지
# 예비로 MCP 결과도 읽는다(성현 님이 데스크톱 앱을 못 쓰면 필요 — 그 답 전까지 남겨 둠, 10/17 사용자 결정 A):
#   figma/metadata.xml   get_metadata(묶음 노드) 결과 XML (MCP 결과 파일 통째로 둬도 됨)
#   figma/cover-export.png, figma/cover-raw-N.*   download_assets(표지 프레임)의 export와 rawImages
#   figma/card02-raw-N.*                          download_assets(카드 프레임)의 rawImages — 카드 1장에 1회
# 카드 사진은 사람이 내보낸 images/02.jpg가 있으면 그걸 쓰고, 없으면 rawImages에서 고른다
# (사용자 결정 A + 10/16 328 시험: rawImages에 글자 없는 원래 사진이 통째로 들어 있었다)

UA_FB = "facebookexternalhit/1.1"  # 인스타는 이 이름으로 물어야 미리보기 태그를 준다


def get(url, headers=None):
    req = urllib.request.Request(url, headers=dict({"User-Agent": "Mozilla/5.0"}, **(headers or {})))
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read()


def figma_group(d, group_id):
    raw = (d / "figma" / "metadata.xml").read_text(encoding="utf-8")
    if raw.lstrip().startswith("["):
        # MCP 결과 파일(JSON 목록) 그대로 둔 경우: XML 덩어리만 꺼낸다
        raw = next(b["text"] for b in json.loads(raw) if b["text"].lstrip().startswith("<"))
    root = ET.fromstring(raw)
    g = next((e for e in root.iter() if e.get("id") == group_id), None)
    if g is None:
        sys.exit(f"metadata.xml에 묶음 {group_id}가 없어요")
    return g


def scan_group(d):
    """피그마 플러그인(tools/figma-plugin)이 만든 figma/scan.json → figma_group과 같은 모양의 나무.
    플러그인은 앱 안에서 직접 읽어서 MCP 월 20회 한도를 안 쓴다(10/17). 판단 규칙은 아래 그대로 쓰려고 모양만 맞춘다.
    글자는 레이어 이름 대신 실제 글자(characters)를 쓴다. 카드 속 줄바꿈은 메타데이터 때처럼 띄어쓰기로 잇는다."""
    s = load(d / "figma", "scan.json")
    g = ET.Element("frame", id=s["group"]["id"], name=s["group"]["name"])
    for f in s["frames"]:
        fr = ET.SubElement(g, "frame", id=f["id"], name=f["name"], x=str(f["x"]), y=str(f["y"]),
                           width=str(f["width"]), height=str(f["height"]))
        if f.get("composite"):
            fr.set("composite", f["composite"])  # 글자 뺀 카드 그림(플러그인 3판, 사진이 여러 장인 카드만)
        for t in f["texts"]:
            text = re.sub(r"\s*[\n]\s*", " ", t["characters"]).strip()
            # lines = 줄바꿈을 살린 글자 (카피 제목은 끊어 읽는 자리가 리듬이라 그대로 쓴다, 764 사용자 결정 10/17)
            el = ET.SubElement(fr, "text", name=text, lines=t["characters"].strip(), x=str(t["x"]), y=str(t["y"]),
                               width=str(t["width"]), height=str(t["height"]))
            if t.get("fontSize"):
                el.set("font", str(t["fontSize"]))
        for im in f["images"]:
            ET.SubElement(fr, "rounded-rectangle", name=im["name"], hash=im["hash"], x=str(im["x"]), y=str(im["y"]),
                          width=str(im["width"]), height=str(im["height"]))
        for v in f.get("videos", []):
            # 영상이 채워진 칸(플러그인 1.6.0 무렵부터). 영상 자체는 플러그인이 못 꺼내서 칸 위치만 — 정지 화면 구도용
            ET.SubElement(fr, "video", name=v["name"], x=str(v["x"]), y=str(v["y"]),
                          width=str(v["width"]), height=str(v["height"]))
    return g


def scan_image(d, el):
    # 플러그인이 담은 사진 원본(figma/images/<hash>.*). MCP 메타데이터에서 온 레이어는 hash가 없어 None
    h = el.get("hash") if el is not None else None
    return next(iter(sorted((d / "figma" / "images").glob(f"{h}.*"))), None) if h else None


def reading_order(frames):
    """카드를 책 읽듯이: 윗줄부터, 줄 안에서는 왼쪽부터.
    242는 한 줄이었지만 764는 두 줄(1~10 / 11~17)이라 x만으로 세면 1, 11, 2, 12…로 섞였다(10/17).
    y가 카드 높이 절반 안쪽으로 비슷하면 같은 줄로 본다."""
    rows = []
    for fr in sorted(frames, key=lambda e: float(e.get("y", 0))):
        y = float(fr.get("y", 0))
        if rows and y - float(rows[-1][0].get("y", 0)) < float(fr.get("height") or 0) / 2:
            rows[-1].append(fr)
        else:
            rows.append([fr])
    return [fr for row in rows for fr in sorted(row, key=lambda e: float(e.get("x")))]


def frame_no(fr, i):
    # 프레임 이름 끝 번호("트렌드포털 802-3" → 3)가 카드 번호. 없으면 왼쪽부터 센 순서
    m = re.search(r"-(\d+)\s*$", fr.get("name", ""))
    return int(m.group(1)) if m else i + 1


# 출처 표기는 시기마다 다르다: 2026년 가을 카드는 "© 한국경제", 그 전 카드는 "출처ㅣVogue"
SRC_MARK = re.compile(r"^\s*(?:©|ⓒ|출처\s*[ㅣ|:])\s*")
BRAND_LINE = re.compile(r"^\s*-\s*(.+?)\s*-\s*$")
WHO_LINE = re.compile(r"^\s*@([A-Za-z0-9._]+)\s*(?:/\s*(.+))?$")


def paragraphs(body):
    """본문 글자 → 문단 목록. 카드의 빈 줄이 문단 나눔이다(post-style 규칙, 10/10 사용자).
    플러그인 결과엔 줄바꿈을 살린 글자(lines)가 있어서 빈 줄로 나누고, 문단 안 줄바꿈은 띄어쓰기로 잇는다.
    MCP 메타데이터엔 줄바꿈이 없어서 예전처럼 한 문단"""
    raw = (body.get("lines") or body.get("name")).strip()
    return [re.sub(r"\s*\n\s*", " ", p).strip() for p in re.split(r"\n\s*\n", raw) if p.strip()]


def read_card(fr):
    """카드 프레임 하나 → 제목·본문·출처. 글자 레이어 이름 = 카드에 적힌 글자 전체(10/16 242로 확인).
    단 레이어 이름에서는 카드 속 줄바꿈이 띄어쓰기로 바뀌어 있다(328로 확인) — 끊어 읽기는 사람·Claude가 다듬는다."""
    texts = [t for t in fr.iter("text") if t.get("name", "").strip()]
    if not texts:
        return None
    src = [t for t in texts if SRC_MARK.match(t.get("name"))]
    rest = [t for t in texts if t not in src]
    if not rest:
        return None
    body = max(rest, key=lambda t: len(t.get("name")))
    others = [t for t in rest if t is not body]
    font = lambda t: float(t.get("font") or 0)
    top = max(map(font, others), default=0)
    if top > font(body):
        # 플러그인 결과엔 글자 크기가 있다: 제목 = 가장 큰 글씨. 242(한 줄짜리 레이어 여럿)와
        # 764(두 줄 레이어 하나, 높이 210)가 둘 다 100이라 높이보다 이게 맞다(10/17)
        lines = [t for t in others if font(t) == top]
        # 가장 큰 글씨가 여러 덩어리로 흩어져 있으면(845의 9번: 그림 속 01~05 목록이 제목과 같은 100) 맨 아래 덩어리가 제목이다.
        # 위에서 아래로 그림 → 제목 → 본문 순서라서. 덩어리 = 위 줄과 줄 높이 반 이내로 붙어 있는 줄들
        if len(lines) > 2:
            lines.sort(key=lambda t: float(t.get("y")))
            block = [lines[-1]]
            for t in reversed(lines[:-1]):
                if float(block[-1].get("y")) - (float(t.get("y")) + float(t.get("height"))) <= float(t.get("height")) / 2:
                    block.append(t)
                else:
                    break
            lines = block
    else:
        # MCP 메타데이터엔 글자 크기가 없어서: 큰 글씨 한 줄(높이 60~120)
        lines = [t for t in others if 60 <= float(t.get("height")) <= 120]
    # 두 줄 제목이 레이어 둘로 나뉘어 있으면 위에서부터 띄어쓰기로 잇고, 레이어 하나 안의 줄바꿈은 살린다
    lines.sort(key=lambda t: float(t.get("y")))
    extra = [t.get("name") for t in others if t not in lines]
    card = {"title": " ".join((t.get("lines") or t.get("name")).strip() for t in lines), "what": paragraphs(body)}
    # 인터뷰 패널형(734)의 "@luxmag.kr / 룩스매거진" 줄 = 답한 사람. 예전엔 꾸밈 글자로 버렸다(10/17)
    who = next((m for m in (WHO_LINE.match(x) for x in extra) if m), None)
    if who:
        card["who"] = {"handle": who.group(1), "name": (who.group(2) or "").strip()}
        extra = [x for x in extra if not WHO_LINE.match(x)]
    # 카피 모음형(764)의 "-가텐 (구인 정보지)-" 줄 = 그 카피를 쓴 브랜드
    brand = next((m.group(1) for m in (BRAND_LINE.match(x) for x in extra) if m), None)
    if brand:
        card["brand"] = brand
        extra = [x for x in extra if not BRAND_LINE.match(x)]
    if src:
        card["src"] = SRC_MARK.sub("", src[0].get("name")).strip()
    # 제목 맨 윗줄 높이: 사진 레이어가 없는 카드(인포그래픽 등)는 카드 그림에서 이 위쪽을 잘라 쓴다(fetch 참고)
    top = min((float(t.get("y")) for t in lines), default=None)
    return card, len(lines), extra, top


def clean_link(url):
    # 공유할 때 붙는 추적용 꼬리(?s=46, ?si=…, ?igsh=…)를 뗀다. 유튜브는 v= 만 남긴다
    p = urllib.parse.urlsplit(url.strip())
    q = urllib.parse.parse_qs(p.query)
    query = urllib.parse.urlencode({"v": q["v"][0]}) if "v" in q else ""
    return urllib.parse.urlunsplit((p.scheme, p.netloc, p.path, query, ""))


def video_still(url, out):
    """영상 주소 → (정지 화면 파일, 링크 문구). 세 곳 다 10/16 실측한 방법."""
    host = urllib.parse.urlsplit(url).netloc
    if "youtu" in host:
        vid = re.search(r"(?:youtu\.be/|v=|/shorts/)([\w-]{11})", url).group(1)
        meta = json.loads(get(f"https://www.youtube.com/oembed?url=https://youtu.be/{vid}&format=json"))
        try:
            img = get(f"https://img.youtube.com/vi/{vid}/maxresdefault.jpg")
        except urllib.error.HTTPError:
            img = get(f"https://img.youtube.com/vi/{vid}/hqdefault.jpg")
        label = f"유튜브에서 영상 보기 ({meta['author_name']})"
        note = "유튜브 썸네일은 양옆 검은 띠가 있을 수 있어요 — 눈으로 확인"
    elif "x.com" in host or "twitter.com" in host:
        user, sid = re.search(r"/([^/]+)/status/(\d+)", url).groups()
        t = json.loads(get(f"https://api.fxtwitter.com/{user}/status/{sid}"))["tweet"]
        m = t["media"]["all"][0]
        img = get((m.get("thumbnail_url") or m["url"]) + "?name=orig")
        label, note = f"X에서 영상 보기 (@{t['author']['screen_name']})", None
    elif "instagram.com" in host:
        page = get(url, {"User-Agent": UA_FB}).decode("utf-8", "replace")
        og = lambda k: html.unescape(re.search(rf'property="og:{k}" content="([^"]+)"', page).group(1))
        acct = urllib.parse.urlsplit(og("url")).path.strip("/").split("/")[0]
        img = get(og("image"))
        label = f"인스타그램에서 영상 보기 (@{acct})"
        note = "인스타 정지 화면은 최대 640px이고 재생 버튼이 박혀 있을 수 있어요"
    else:
        sys.exit(f"모르는 영상 주소예요: {url}")
    out.write_bytes(img)
    if "youtu" in host and drop_black_bars(out):
        # 636 때 손으로 하던 일(10/16 "검은 띠 잘라냄")을 자동으로. 잘랐으면 경고 대신 알림만
        note = "유튜브 썸네일의 검은 띠를 잘라냈어요 — 눈으로 확인"
    return label, note


def drop_black_bars(path):
    """정지 화면 둘레의 검은 띠를 잘라낸다(유튜브 maxresdefault는 4:3·세로 영상이면 띠가 붙는다).
    ffmpeg cropdetect로 검은 테두리를 재고, 실제로 줄어들 때만 덮어쓴다. 잘랐으면 True"""
    if not shutil.which("ffmpeg"):
        return False
    # cropdetect는 앞 몇 장면을 건너뛰고 재서 사진 한 장으론 아무것도 안 나온다 → 0.2초 동안 반복시켜 잰다
    r = subprocess.run(["ffmpeg", "-hide_banner", "-loop", "1", "-t", "0.2", "-i", str(path), "-vf", "cropdetect=limit=24:round=2:reset=0", "-f", "null", "-"],
                       capture_output=True, text=True)
    m = re.findall(r"crop=(\d+):(\d+):(\d+):(\d+)", r.stderr)
    size = jpeg_size(path)
    if not m or not size:
        return False
    w, h, x, y = map(int, m[-1])
    # 몇 픽셀 차이는 압축 얼룩이라 무시 — 한 변이라도 4% 넘게 줄 때만 자른다
    if w >= size[0] * 0.96 and h >= size[1] * 0.96:
        return False
    tmp = path.with_name(path.stem + "-crop" + path.suffix)
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(path), "-vf", f"crop={w}:{h}:{x}:{y}", "-q:v", "2", str(tmp)],
                   check=True, capture_output=True)
    tmp.replace(path)
    return True


# ── 영상 카드 (탭 1.6.0, 10/17 사용자 A) ─────────────────
# 탭의 한 줄 = 카드 번호 + 파일(선택) + 원본 주소(선택). 할 일은 "주소가 있느냐"로 정한다:
#   주소 있음 → 남의 영상: 정지 화면 + "▶ ○○에서 영상 보기" 링크(글 351 방식). 영상 자체는 사이트에 안 올린다
#               정지 화면 = 영상 파일에서 뽑은 장면 > 사진 파일 > 원본 게시물의 정지 화면(video_still) 순
#   주소 없음 → 영상 파일이면 우리가 찍은 영상(소리 빼고 자동 재생), 사진 파일이면 그 카드의 사진

# 짧은 변 720px까지만 줄인다(늘리지 않음). -2 = 비율 유지하며 짝수로 (H.264는 짝수 크기만 받는다)
SCALE_720 = "scale='if(gte(iw,ih),-2,trunc(min(720,iw)/2)*2)':'if(gte(iw,ih),trunc(min(720,ih)/2)*2,-2)'"


def need_ffmpeg():
    if not shutil.which("ffmpeg"):
        sys.exit("영상 카드를 처리하려면 ffmpeg가 있어야 해요 (클라우드 환경은 설치 스크립트가 넣어요)")


def video_layer_aspect(fr):
    """피그마 카드에서 영상이 보이던 칸의 가로/세로 비율. 정지 화면을 카드에서 보던 구도로 자르는 데 쓴다.
    플러그인이 영상 레이어(<video>)를 적어 줬으면 그 칸(카드 밖으로 나간 부분은 뺀다), 없으면 카드 전체
    (레거시 영상 카드는 영상이 카드를 꽉 채운다고 보고). 피그마의 영상 채우기 기본값이 '가운데 맞춰 꽉 채우기'라 가운데를 자른다."""
    fx, fy, fw, fh = (float(fr.get(k)) for k in ("x", "y", "width", "height"))
    vids = [e for e in fr if e.tag == "video"]
    if vids:
        v = max(vids, key=lambda e: float(e.get("width")) * float(e.get("height")))
        w = min(fx + fw, float(v.get("x")) + float(v.get("width"))) - max(fx, float(v.get("x")))
        h = min(fy + fh, float(v.get("y")) + float(v.get("height"))) - max(fy, float(v.get("y")))
        if w > 0 and h > 0:
            return w / h
    return fw / fh


def video_frame(src, dst, aspect=None, at=None, scale="scale='min(2000,iw)':-2"):
    """영상에서 장면 한 장 → JPEG. at(초)을 주면 그 장면, 아니면 ffmpeg thumbnail 필터가 앞 100장면 중
    가장 '대표적인'(평균에 가까운) 장면을 고른다 — 시작의 검은 화면·전환 장면을 피하려고. 1초 뒤부터 본다."""
    need_ffmpeg()
    vf = [] if at is not None else ["thumbnail=100"]
    if aspect:
        # 가운데를 기준으로 aspect 비율만큼 잘라 낸다 (넘치는 쪽만 자르고 늘리지 않음)
        vf.append(f"crop='trunc(min(iw,ih*{aspect:.4f})/2)*2':'trunc(min(ih,iw/{aspect:.4f})/2)*2'")
    vf.append(scale)
    seek = ["-ss", str(at)] if at is not None else ["-ss", "1"]
    run = lambda s: subprocess.run(["ffmpeg", "-y", "-loglevel", "error", *s, "-i", str(src), "-vf", ",".join(vf),
                                    "-frames:v", "1", "-q:v", "3", str(dst)], capture_output=True)
    if run(seek).returncode or not dst.exists():
        # 1초보다 짧은 영상이면 처음부터
        r = run([])
        if r.returncode or not dst.exists():
            sys.exit(f"{src.name}에서 장면을 못 뽑았어요: {r.stderr.decode('utf-8', 'replace')[-300:]}")
    return dst.name


def make_clip(src, dst):
    # 우리 영상: 소리 빼고(-an, 자동 재생은 무음이어야 브라우저가 허락한다) H.264 720p, faststart = 받는 중에 바로 재생
    need_ffmpeg()
    r = subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(src), "-an", "-vf", SCALE_720,
                        "-c:v", "libx264", "-preset", "medium", "-crf", "24", "-pix_fmt", "yuv420p",
                        "-movflags", "+faststart", str(dst)], capture_output=True)
    if r.returncode:
        sys.exit(f"{src.name}을 사이트용 영상으로 못 바꿨어요: {r.stderr.decode('utf-8', 'replace')[-300:]}")
    return dst.name


LINK_SITE = (("instagram.com", "인스타그램"), ("youtu", "유튜브"), ("x.com", "X"), ("twitter.com", "X"), ("tiktok.com", "틱톡"))


def link_label(link, card):
    # 원본 게시물을 못 읽었을 때 쓰는 문구: 사이트 이름 + 카드에 적힌 출처(계정)
    host = urllib.parse.urlsplit(link).netloc
    site = next((name for key, name in LINK_SITE if key in host), "원본")
    return f"{site}에서 영상 보기" + (f" ({card['src']})" if card.get("src") else "")


def video_card(d, n, card, fr, url, f):
    """영상 카드 한 장을 채운다. 돌려주는 값 = 경고 목록."""
    warn = []
    src = d / f["path"] if f else None
    if f and not src.exists():
        return [f"{n}번 영상 카드 파일({f['path']})이 없어요"]
    if not url:
        if f["kind"] == "video":
            card["clip"] = make_clip(src, d / "images" / f"{n:02d}-clip.mp4")
            # 포스터는 재생 전 잠깐 보이는 첫 화면이라 영상과 같은 크기로
            card["poster"] = video_frame(src, d / "images" / f"{n:02d}-poster.jpg", scale=SCALE_720)
            mb = (d / "images" / card["clip"]).stat().st_size / 1048576
            if mb > 15:
                warn.append(f"{n}번 우리 영상이 {mb:.0f}MB예요 — 폰에서 늦게 뜰 수 있으니 짧게 잘라도 될지 확인")
        else:
            card["image"] = to_web(src, d / "images" / f"{n:02d}.jpg")
        return warn
    link = clean_link(url)
    fn = f"{n:02d}-video.jpg"
    try:
        # 파일이 있어도 원본 게시물은 읽는다 — 링크 문구의 계정 이름(@…)을 거기서 가져와서
        label, note = video_still(link, d / "images" / fn)
    except (OSError, AttributeError, KeyError, ValueError, SystemExit) as e:  # OSError = 연결·시간 초과(URLError 포함)
        if not f:
            return [f"{n}번 영상 정지 화면을 원본에서 못 가져왔어요 ({e}) — 탭에서 그 장면 캡처나 영상 파일을 같이 넣어 주세요"]
        label, note = link_label(link, card), None
        warn.append(f"{n}번 원본 게시물을 못 읽어서 링크 문구를 카드 출처로 만들었어요: {label}")
    if f and f["kind"] == "video":
        fn = video_frame(src, d / "images" / f"{n:02d}-video.jpg", aspect=video_layer_aspect(fr))
        note = None
        warn.append(f"{n}번 정지 화면은 영상 파일에서 골랐어요 — 이상하면 `still` 명령으로 다른 초를 지정")
    elif f:
        fn = to_web(src, d / "images" / f"{n:02d}-video.jpg")
        note = None
    card.update(image=fn, video={"url": link, "label": label})
    size = jpeg_size(d / "images" / fn)
    if size and size[0] > size[1]:
        card["wide"] = True  # 주간 틀에서만 쓰지만 같이 적어 둔다
    if note:
        warn.append(f"{n}번 영상: {note}")
    return warn


def still(d, n, at):
    """정지 화면(또는 우리 영상의 포스터)을 영상 파일의 at초 장면으로 바꾼다. 수정 요청 "3번 정지 화면 5초 장면으로"용.
    파일 이름에 초를 붙여 새 이름으로 만든다 — media.json에 옛 이름이 이미 올라가 있어서, 같은 이름이면 draft가 다시 안 올린다."""
    order, cards = load(d, "order.json"), load(d, "cards.json")
    f = order.get("files", {}).get(str(n))
    if not f or f["kind"] != "video":
        sys.exit(f"{n}번 카드엔 영상 파일이 없어요 (탭 영상 카드 칸에 파일을 넣은 카드만 돼요)")
    card = next((c for c in cards["cards"] if c.get("no") == n), None)
    if card is None:
        sys.exit(f"cards.json에 {n}번 카드가 없어요")
    tag = f"{at:g}s".replace(".", "_")
    src = d / f["path"]
    if card.get("clip"):
        card["poster"] = video_frame(src, d / "images" / f"{n:02d}-poster-{tag}.jpg", at=at, scale=SCALE_720)
    else:
        group = scan_group(d) if (d / "figma" / "scan.json").exists() else None
        fr = next((x for i, x in enumerate(reading_order([c for c in group if c.tag == "frame"]))
                   if frame_no(x, i) == n), None) if group is not None else None
        aspect = video_layer_aspect(fr) if fr is not None else None
        card["image"] = video_frame(src, d / "images" / f"{n:02d}-video-{tag}.jpg", aspect=aspect, at=at)
    save(d, "cards.json", cards)
    print(f"{n}번 → {card.get('poster') if card.get('clip') else card['image']}")


def profile(d, n, box=None):
    """인터뷰 대상 n번(1부터) 프로필 사진을 받은 캡처에서 정사각형으로 잘라낸다(10/17 사용자: 캡처만 주면 셰프가 알아서 예쁘게).
    어디를 자를지는 셰프가 사진을 보고 정하고(판단), 자르기는 여기서 한다(손일) — 영상 정지 화면 고르기와 같은 나눔.
    box 없이 부르면 캡처 크기만 알려 준다. 늘 받은 원본(uploads/profile-n)에서 자르니 여러 번 다시 잘라도 화질이 안 깎인다.
    파일 이름에 자른 자리를 붙여 새 이름으로 — media.json에 옛 이름이 올라가 있으면 draft가 다시 안 올려서(still과 같은 이유)"""
    order = load(d, "order.json")
    people = order.get("people") or []
    if not 1 <= n <= len(people):
        sys.exit(f"프로필 대상(인터뷰 대상·광고 브랜드)은 {len(people)}개예요 (1부터)")
    src = d / "uploads" / f"profile-{n}"
    if not src.exists():
        sys.exit(f"{n}번 대상은 받은 프로필 사진이 없어요")
    need_ffmpeg()
    probe = subprocess.run(["ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries", "stream=width,height",
                            "-of", "csv=p=0", str(src)], capture_output=True, text=True, check=True)
    w, h = (int(v) for v in probe.stdout.strip().split(",")[:2])
    if box is None:
        print(f"{n}번 캡처 {w}×{h}px — 자르려면: profile <주문> {n} <왼쪽 x> <위 y> <한 변>")
        return
    x, y, size = box
    if x < 0 or y < 0 or size < 40 or x + size > w or y + size > h:
        sys.exit(f"자를 칸이 캡처({w}×{h}) 밖으로 나가요: x {x}, y {y}, 한 변 {size}")
    dst = d / "images" / f"profile-{n}-{x}-{y}-{size}.jpg"
    # 프로필 카드 동그라미는 44px라 400px이면 레티나 화면에서도 넉넉하다
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(src), "-vf", f"crop={size}:{size}:{x}:{y},scale=400:400",
                    "-q:v", "2", str(dst)], check=True, capture_output=True)
    people[n - 1]["photo"] = dst.name
    save(d, "order.json", order)
    print(f"{n}번 → {dst.name}")


SHELL_URL = "https://trendportal.kr/archives/242/"  # 찍을 때 빌리는 글 페이지(사이트 머리·글꼴·추가 CSS가 다 들어 있다)


def browser():
    """화면을 찍을 크롬. 이 맥은 설치된 크롬, 클라우드는 playwright가 받아 둔 chromium. 없으면 한 번 받는다(몇 분)"""
    # 클라우드 환경은 PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers에 받는다(10/17 환경 시험: 우분투 24.04, 설치 46초)
    roots = [Path(p) for p in (os.environ.get("PLAYWRIGHT_BROWSERS_PATH"), "/opt/pw-browsers", Path.home() / ".cache/ms-playwright") if p]
    pats = ("chromium-*/chrome-linux*/chrome", "chromium_headless_shell-*/chrome-*/chrome-headless-shell",
            "chromium_headless_shell-*/chrome-linux/headless_shell")
    # 설치 뒤에 다시 찾아야 해서 부를 때마다 훑는다
    found = lambda: next((str(p) for p in [os.environ.get("CHROME"), "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
                                            shutil.which("google-chrome"), shutil.which("chromium"), shutil.which("chromium-browser"),
                                            *[x for r in roots for pat in pats for x in sorted(r.glob(pat))]]
                          if p and Path(p).exists()), None)
    if not found():
        print("화면 찍을 브라우저가 없어서 받는 중이에요 (처음 한 번, 몇 분)", file=sys.stderr)
        subprocess.run([sys.executable, "-m", "pip", "install", "-q", "playwright"], check=True)
        subprocess.run([sys.executable, "-m", "playwright", "install", "--with-deps", "chromium"], check=True,
                       stdout=subprocess.DEVNULL)
    return found()


def sheet(d, frames, cols=4, w=360, h=450, gap=8):
    """카드 미리보기를 한 장에 모은다(10/18 토큰 절약): 셰프가 카드 10장을 따로 열면 열 때마다 대화가 길어져서,
    처음엔 이 한 장으로 전체를 훑고 글자를 자세히 봐야 하는 카드만 figma/cards/에서 따로 연다.
    클라우드엔 PIL이 없어서 ffmpeg xstack으로 붙인다. 못 만들면 None(셰프는 예전처럼 한 장씩 본다)"""
    srcs = [p for p in (d / "figma" / "cards" / f"{fr.get('name')}.jpg" for fr in frames) if p.exists()]
    if not srcs or not shutil.which("ffmpeg"):
        return None
    cw, ch = w + gap, h + gap
    pre = "".join(f"[{i}:v]scale={w}:{h}:force_original_aspect_ratio=decrease,"
                  f"pad={cw}:{ch}:(ow-iw)/2:(oh-ih)/2:white[v{i}];" for i in range(len(srcs)))
    layout = "|".join(f"{(i % cols) * cw}_{(i // cols) * ch}" for i in range(len(srcs)))
    dst = d / "figma" / "sheet.jpg"
    args = ["ffmpeg", "-y", "-loglevel", "error"]
    for p in srcs:
        args += ["-i", str(p)]
    if len(srcs) == 1:
        args += ["-vf", f"scale={w}:{h}:force_original_aspect_ratio=decrease"]
    else:
        args += ["-filter_complex", pre + "".join(f"[v{i}]" for i in range(len(srcs)))
                 + f"xstack=inputs={len(srcs)}:layout={layout}:fill=white"]
    try:
        subprocess.run(args + ["-frames:v", "1", "-q:v", "3", str(dst)], check=True, capture_output=True, timeout=120)
    except (subprocess.SubprocessError, OSError):
        return None
    return dst


def shot(d, widths=(390,)):
    """셰프의 눈(10/17 사용자): 초안이 사이트에서 어떻게 보이는지 폰·PC로 찍어 shots/에 1600px 높이씩 나눠 둔다.
    10/18부터 기본은 폰만 — PC는 디자인 CSS를 붙였을 때만 --pc로(PC는 본문 폭이 고정이라 틀은 잘 안 깨진다).
    비공개 초안 미리보기는 로그인이 필요해서, 공개 글 페이지(SHELL_URL)를 빌려 본문 자리만 이 초안으로 바꿔 찍는다 —
    Claude Code에서 시안을 찍던 방식과 같다. 머리·글꼴·추가 CSS는 라이브 그대로라 실제와 같게 보인다"""
    order = load(d, "order.json")
    out, _ = build(d, quiet=True)
    out = re.sub(r"\{\{url:([^}]+)\}\}", lambda m: (d / "images" / m.group(1)).resolve().as_uri(), out)
    out = re.sub(r"\{\{id:[^}]+\}\}", "0", out)
    title = order.get("title") or ""
    cat = {3663: "브랜드", 18233: "트렌드", 11788: "인터뷰", 18540: "광고"}.get((order.get("categories") or [0])[0], "")
    page = get(SHELL_URL).decode("utf-8")
    # 빌린 글의 제목·카테고리·커버 사진을 이 주문 것으로 바꾼다. 커버는 사이트에선 TP Cover Photo 플러그인이
    # 본문 맨 위 .tp-cover-photo 사진을 머리(.tp-cover-bg)로 옮기는데, 여기선 그 일을 대신 한다(머리 물든 색은 빌린 글 것 그대로)
    inject = (f"<script>addEventListener('DOMContentLoaded', () => {{"
              f"const c = document.querySelector('.wp-block-post-content'); c.innerHTML = {json.dumps(out)};"
              f"const t = document.querySelector('.wp-block-post-title'); if (t) t.textContent = {json.dumps(title)};"
              f"const k = document.querySelector('.tp-cover .wp-block-post-terms a'); if (k && {json.dumps(cat)}) k.textContent = {json.dumps(cat)};"
              f"const f = c.querySelector('figure.tp-cover-photo'), bg = document.querySelector('.tp-cover-bg img');"
              f"if (f && bg) {{ bg.removeAttribute('srcset'); bg.src = f.querySelector('img').src; f.remove(); }}"
              f"document.querySelectorAll('img[loading=lazy]').forEach(i => i.loading = 'eager');}});</script>")
    sd = d / "shots"
    sd.mkdir(exist_ok=True)
    for old in sd.glob("*.png"):
        old.unlink()
    html_page = sd / "page.html"
    html_page.write_text(page.replace("</head>", inject + "</head>", 1), encoding="utf-8")
    chrome = browser()
    need_ffmpeg()
    made = []
    for w in widths:
        h = 7200 if w < 600 else 4800
        win = max(w, 600)  # 헤드리스 크롬 창은 500px보다 좁게 안 그려서, 정확한 폭의 iframe을 넣고 가운데만 잘라 낸다
        frame = sd / f"frame-{w}.html"
        frame.write_text(f'<body style="margin:0;display:flex;justify-content:center"><iframe src="{html_page.resolve().as_uri()}" '
                         f'style="width:{w}px;height:{h}px;border:0;display:block"></iframe>', encoding="utf-8")
        raw = sd / f"raw-{w}.png"
        subprocess.run([chrome, "--headless=new", "--no-sandbox", "--disable-gpu", "--hide-scrollbars", "--allow-file-access-from-files",
                        f"--window-size={win},{h}", "--virtual-time-budget=10000", f"--screenshot={raw}", frame.resolve().as_uri()],
                       check=True, capture_output=True, timeout=180)
        for k in range(h // 1600 + (1 if h % 1600 else 0)):
            part = sd / f"{w}-{k + 1}.png"
            subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(raw), "-vf",
                            f"crop={w}:{min(1600, h - k * 1600)}:{(win - w) // 2}:{k * 1600}", str(part)], check=True)
            made.append(part.relative_to(d).as_posix())
        raw.unlink()
    print("찍었어요 (위에서부터, 아래쪽 빈 화면은 글이 끝난 것):", " ".join(made))


def ref(post_id):
    """정답 예시 글(docs/post-formats.md의 Reference posts)의 블록 원문을 보여 준다. 읽기만 한다.
    셰프가 틀에 없는 모양을 직접 짤 때 말 규칙보다 실제 마크업을 보고 따라 하라고(10/17 사용자: 대화 결과와 웹폼 결과를 같게)"""
    p = wp(f"posts/{post_id}?context=edit&_fields=id,status,title,categories,content")
    print(f"# 글 {p['id']} ({p['status']}) · {p['title']['raw']} · 카테고리 {p['categories']}")
    print(p["content"]["raw"])


def jpeg_size(path):
    # 가로/세로만 알면 돼서 JPEG 머리만 읽는다 (사진 라이브러리 설치 없이)
    data = path.read_bytes()
    i = 2
    while i < len(data):
        if data[i] != 0xFF:
            i += 1
            continue
        marker, length = data[i + 1], int.from_bytes(data[i + 2:i + 4], "big")
        if marker in (0xC0, 0xC1, 0xC2):
            return int.from_bytes(data[i + 7:i + 9], "big"), int.from_bytes(data[i + 5:i + 7], "big")
        i += 2 + length
    return None


def png_info(path):
    # PNG 머리(IHDR)에서 가로·세로·투명 여부만 읽는다. 색 형식 4·6 = 투명 칸이 있는 그림(로고·국기 오린 것)
    data = path.read_bytes()[:32]
    if data[:8] != b"\x89PNG\r\n\x1a\n":
        size = jpeg_size(path)
        return (size[0], size[1], False) if size else (0, 0, False)
    w, h = int.from_bytes(data[16:20], "big"), int.from_bytes(data[20:24], "big")
    return w, h, data[25] in (4, 6)


def pick_photo(paths):
    """download_assets의 rawImages 중 진짜 사진 고르기: 투명 배경 없는 것 중 가장 큰 그림.
    순서로는 못 고른다 — 328 표지는 첫 장이 그래프 그림, 사진은 둘째 장이었다(10/16)."""
    info = [(p, *png_info(p)) for p in paths]
    solid = [x for x in info if not x[3]] or info
    return max(solid, key=lambda x: x[1] * x[2])[0] if solid else None


def to_web(src, dst):
    # 피그마 원본은 PNG 수 MB라 사이트용 JPEG(긴 변 2000px)로 줄인다.
    # 이 맥은 기본 sips, 클라우드(우분투)는 환경 설치 스크립트로 넣은 ffmpeg. 둘 다 없으면 그대로 복사
    w, h, _ = png_info(src)
    if shutil.which("sips"):
        # 줄이기만 한다 — 작은 원본을 늘리면 용량만 커지고 더 흐려진다
        resize = ["-Z", "2000"] if max(w, h) > 2000 else []
        subprocess.run(["sips", "-s", "format", "jpeg", "-s", "formatOptions", "85", *resize,
                        str(src), "--out", str(dst)], check=True, capture_output=True)
        return dst.name
    if shutil.which("ffmpeg"):
        # 긴 변이 2000을 넘을 때만 그 변을 2000으로(-2 = 비율 유지·짝수). q:v 3 ≈ sips 품질 85 정도
        scale = ["-vf", "scale=2000:-2" if w >= h else "scale=-2:2000"] if max(w, h) > 2000 else []
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(src), *scale, "-q:v", "3", str(dst)],
                       check=True, capture_output=True)
        return dst.name
    dst = dst.with_suffix(src.suffix)
    shutil.copy(src, dst)
    return dst.name


def composite_photo(d, fr, layers, dst):
    """사진이 여러 장 겹친 카드: 플러그인이 글자만 숨겨 내보낸 카드 그림에서 사진들이 차지한 칸만 잘라 쓴다.
    10/16 주간 총정리 때 손으로 만든 "사진 칸 합성본"과 같은 결과(말풍선·작은 사진·좌우 두 장이 카드 모습 그대로).
    자르는 칸 = 사진 레이어들을 모두 덮는 사각형(카드 밖으로 삐져나간 부분은 뺀다). 못 쓰면 None."""
    rel = fr.get("composite")
    src = d / rel if rel else None
    if not src or not src.exists() or not layers:
        return None
    fx, fy, fw, fh = (float(fr.get(k)) for k in ("x", "y", "width", "height"))
    size = jpeg_size(src)
    # 그림 크기가 카드 모양과 다르면(그룹 밖으로 삐져나간 레이어 등) 좌표를 못 믿으니 쓰지 않는다
    if not size or abs(size[0] / size[1] - fw / fh) > 0.01:
        return None
    s = size[0] / fw
    left = max(0, min(float(e.get("x")) - fx for e in layers))
    top = max(0, min(float(e.get("y")) - fy for e in layers))
    right = min(fw, max(float(e.get("x")) + float(e.get("width")) - fx for e in layers))
    bottom = min(fh, max(float(e.get("y")) + float(e.get("height")) - fy for e in layers))
    # ffmpeg는 짝수 크기를 좋아해서 2의 배수로 맞춘다
    even = lambda v: int(v * s) // 2 * 2
    x, y, w, h = even(left), even(top), even(right - left), even(bottom - top)
    if w < 100 or h < 100:
        return None
    if shutil.which("ffmpeg"):
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(src), "-vf", f"crop={w}:{h}:{x}:{y}",
                        "-q:v", "3", str(dst)], check=True, capture_output=True)
    elif shutil.which("sips"):
        subprocess.run(["sips", "-c", str(h), str(w), "--cropOffset", str(y), str(x), str(src), "--out", str(dst)],
                       check=True, capture_output=True)
    else:
        return None
    return dst.name


def split_logos(fr, layers):
    """사진 레이어 → (사진, 로고). 카드 넓이의 5% 이상 = 큰 사진. 작은 그림은 중심이 큰 사진들의 칸 안에 있으면
    사진 위 꾸밈(744의 말풍선·작은 사진)이라 남기고, 칸 밖(글자 칸)에 있으면 로고·아이콘으로 본다"""
    area = float(fr.get("width")) * float(fr.get("height"))
    size = lambda e: float(e.get("width")) * float(e.get("height"))
    big = [e for e in layers if size(e) >= area * 0.05]
    if not big:
        return layers, []
    left = min(float(e.get("x")) for e in big)
    top = min(float(e.get("y")) for e in big)
    right = max(float(e.get("x")) + float(e.get("width")) for e in big)
    bottom = max(float(e.get("y")) + float(e.get("height")) for e in big)
    keep, logos = list(big), []
    for e in layers:
        if e in big:
            continue
        cx = float(e.get("x")) + float(e.get("width")) / 2
        cy = float(e.get("y")) + float(e.get("height")) / 2
        (keep if left <= cx <= right and top <= cy <= bottom else logos).append(e)
    return keep, logos


def texts_over_photo(fr, layers):
    # 사진 칸(사진 레이어들을 덮는 사각형) 안에 통째로 들어가는 글자 레이어 = 사진 위에 얹은 글자
    left = min(float(e.get("x")) for e in layers)
    top = min(float(e.get("y")) for e in layers)
    right = max(float(e.get("x")) + float(e.get("width")) for e in layers)
    bottom = max(float(e.get("y")) + float(e.get("height")) for e in layers)
    return [t.get("name")[:20] for t in fr.iter("text")
            if float(t.get("x")) >= left and float(t.get("y")) >= top
            and float(t.get("x")) + float(t.get("width")) <= right and float(t.get("y")) + float(t.get("height")) <= bottom]


def card_visual(d, fr, title_top, dst):
    """사진 레이어가 없는 카드: 플러그인의 카드 미리보기(글자 포함, 가로 500)에서 제목 위 영역을 잘라 쓴다.
    10/18 주문 845: 3번(76%·75%·52% 동그라미)·9번(01~05 목록)이 글자와 도형으로 그린 인포그래픽이라 사진이 없었고,
    배경 질감은 카드마다 같은 그림이라 배경으로 빠져서 '사진 없음'으로 주문이 멈췄다. 여기서라면 카드에서 그 부분을 잘라 썼을 것이라 그렇게 한다.
    제목 위가 카드 높이의 20%도 안 되면 그림이 없는 글자 카드로 보고 None"""
    prev = d / "figma" / "cards" / f"{fr.get('name')}.jpg"
    if title_top is None or not prev.exists():
        return None
    size = jpeg_size(prev)
    if not size:
        return None
    fy, fh = float(fr.get("y")), float(fr.get("height"))
    k = size[1] / fh
    h = int(((title_top - fy) - fh * 0.02) * k) // 2 * 2  # 제목 바로 위 여백 조금 남기고
    if h < size[1] * 0.2:
        return None
    need_ffmpeg()
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(prev), "-vf", f"crop={size[0] // 2 * 2}:{h}:0:0",
                    "-q:v", "2", str(dst)], check=True, capture_output=True)
    return dst.name


def visual(d, n, top, bottom):
    """n번 카드의 그림 칸을 셰프가 정한 높이(카드 위에서부터 %)로 카드 미리보기에서 다시 자른다.
    fetch의 자동 판별(가장 큰 글씨 = 제목)이 틀리는 카드용 — 845의 3번은 그림 속 숫자(52%·75%·76%)가 제목보다 컸다.
    어디를 자를지는 셰프가 카드를 보고 정하고, 자르기는 여기서(profile·still과 같은 나눔). 새 이름으로 만든다(still과 같은 이유)"""
    cards = load(d, "cards.json")
    card = next((c for c in cards["cards"] if c.get("no") == n), None)
    group = scan_group(d) if (d / "figma" / "scan.json").exists() else None
    fr = next((x for i, x in enumerate(reading_order([c for c in group if c.tag == "frame"])) if frame_no(x, i) == n), None) if group is not None else None
    prev = d / "figma" / "cards" / f"{fr.get('name')}.jpg" if fr is not None else None
    if card is None or not prev or not prev.exists():
        sys.exit(f"{n}번 카드나 그 미리보기(figma/cards/)가 없어요")
    if not 0 <= top < bottom <= 100:
        sys.exit("위·아래는 카드 높이의 %로, 0 ≤ 위 < 아래 ≤ 100")
    w, h = jpeg_size(prev)
    y0, y1 = int(h * top / 100) // 2 * 2, int(h * bottom / 100) // 2 * 2
    dst = d / "images" / f"{n:02d}-visual-{round(top)}-{round(bottom)}.jpg"
    need_ffmpeg()
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(prev), "-vf", f"crop={w // 2 * 2}:{y1 - y0}:0:{y0}", "-q:v", "2", str(dst)],
                   check=True, capture_output=True)
    card["image"] = dst.name
    save(d, "cards.json", cards)
    print(f"{n}번 → {dst.name}")


def raws(d, prefix):
    return sorted((d / "figma").glob(f"{prefix}-raw-*"))


def read_caption(d):
    """인스타 캡션 → 앞부분(intro)·끝맺음(outro). 빈 줄로 문단을 나누고, 마지막 문단을 끝맺음으로 본다."""
    p = d / "caption.txt"
    if not p.exists():
        return [], [], ["caption.txt가 없어요 (인스타 캡션을 붙여 넣어 주세요)"]
    paras = []
    for block in re.split(r"\n\s*\n", p.read_text(encoding="utf-8").strip()):
        # 해시태그만 있는 줄은 사이트 글에 안 넣는다
        lines = [l.strip() for l in block.splitlines() if l.strip() and not all(w.startswith("#") for w in l.split())]
        if lines:
            paras.append("\n".join(lines))
    if len(paras) < 2:
        return paras, [], []
    return paras[:-1], paras[-1:], []


def background_hashes(frames):
    # 내용 카드 절반 넘게 똑같이 깔린 사진 = 종이 질감 같은 배경 (764는 15장 모두 같은 배경, 10/17)
    seen = Counter(h for fr in frames for h in {e.get("hash") for e in fr if e.get("hash")})
    return {h for h, k in seen.items() if k >= 3 and k > len(frames) / 2}


def photo_layer(fr, skip=()):
    # 카드에 깔린 사진 = 카드 안에서 가장 넓은 사진 레이어 (그라데이션 판·국기·로고보다 크다)
    # MCP 메타데이터는 사진인지 표시가 없어 이름("image …")으로, 플러그인 결과는 hash가 있으면 사진
    imgs = [e for e in fr if (e.get("hash") or e.get("name", "").startswith("image")) and e.get("hash") not in skip]
    return max(imgs, key=lambda e: float(e.get("width")) * float(e.get("height"))) if imgs else None


def find_image(d, n, layer=None):
    # 사람이 내보낸 사진: 카드 번호(02.jpg) 또는 피그마가 붙인 레이어 이름(image 4621.jpg) 그대로
    # 레이어 이름을 받아 주면 여러 장을 한 번에 골라 내보내고 이름을 안 바꿔도 된다
    for stem in filter(None, (f"{n:02d}", layer)):
        for ext in ("jpg", "jpeg", "png", "webp"):
            if (d / "images" / f"{stem}.{ext}").exists():
                return f"{stem}.{ext}"
    return None


def squash(s):
    # 제목 비교용: 띄어쓰기·문장부호를 빼고 비교 ("2026 동계 올림픽, 나라별…" = "2026 동계 올림픽 나라별…")
    return re.sub(r"[\W_]+", "", s)


WEEKLY_TITLE_LINE = re.compile(r"^이번\s*주\s*일어난\s*마케팅\s*이슈\s*총정리")
WEEKLY_PICKS_INTRO = re.compile(r"에디터가\s*주목한")
WEEKLY_FIXED_END = re.compile(r"^매주\s*금요일")


def read_weekly_caption(d):
    """주간 총정리 캡션 → 에디터 레터 문단·에디터 픽. 실제 캡션(26.10 3주차, 10/18 사용자)과 그걸로 만든 글 636에 맞춘 규칙:
    · 첫 줄 "이번 주 일어난 마케팅 이슈 총정리 (…)" = 글 제목과 겹쳐서 뺀다
    · "특히 이번 주 에디터가 주목한 이슈는…" = 에디터 픽 상자가 대신해서 뺀다
    · ✅ 줄 = 에디터 픽. ✅ 줄 뒤에 빈 줄 없이 이어진 글은 레터의 다음 문단
    · "매주 금요일…"부터 끝(📌 저장, 댓글로…) = 사이트 고정 끝맺음(WEEKLY_END)이 대신해서 뺀다
    · 해시태그 줄은 뺀다"""
    p = d / "caption.txt"
    if not p.exists():
        return [], [], ["caption.txt가 없어요 (인스타 캡션을 붙여 넣어 주세요) — 레터·에디터 픽이 비어요"]
    letter, picks, warn = [], [], []
    for block in re.split(r"\n\s*\n", p.read_text(encoding="utf-8").strip()):
        lines = [l.strip() for l in block.splitlines() if l.strip() and not all(w.startswith("#") for w in l.split())]
        if lines and WEEKLY_FIXED_END.match(lines[0]):
            break
        para = []
        for l in lines:
            if WEEKLY_TITLE_LINE.match(l) or WEEKLY_PICKS_INTRO.search(l):
                continue
            if l.startswith("✅"):
                # 앞뒤가 뒤바뀐 따옴표(’중급닌자시험‘)는 바로잡는다 — 636에서 사람이 고친 모양
                picks.append(re.sub(r"’([^’‘]+)‘", r"‘\1’", l.lstrip("✅").strip()))
                if para:
                    letter.append("\n".join(para))
                    para = []
                continue
            para.append(l)
        if para:
            letter.append("\n".join(para))
    if not picks:
        warn.append("캡션에 ✅ 줄이 없어서 에디터 픽이 비었어요")
    return letter, picks, warn


def fetch(d):
    order = load(d, "order.json")
    # 10/18: 주간 총정리(weekly)도 읽는다 — 뉴스레터와 사이트 주간 글을 같은 zip에서 만들기로 해서(docs/newsletter.md).
    # 카드 한 장 읽는 규칙은 카드뉴스와 같고, 결과 모양만 build_weekly가 받는 letter·picks·cards
    weekly = order["kind"] == "weekly"
    if order["kind"] not in ("archive", "weekly"):
        sys.exit("fetch는 아카이빙(archive)과 주간 총정리(weekly)만 돼요")
    (d / "images").mkdir(exist_ok=True)
    # 플러그인 결과가 있으면 그걸, 없으면 예전처럼 MCP 메타데이터를 읽는다
    if (d / "figma" / "scan.json").exists():
        group = scan_group(d)
    else:
        group = figma_group(d, order["figma"]["group"])
    frames = reading_order([c for c in group if c.tag == "frame"])
    videos = {int(k): v for k, v in order.get("videos", {}).items()}
    files = {int(k): v for k, v in order.get("files", {}).items()}
    bg = background_hashes(frames[1:-1])
    cards, warn = [], []
    # 첫 장 = 표지, 마지막 장 = 끝 카드(계정 이름 + 제목 한 줄)라서 빼고, 그 사이가 내용 카드
    for i, fr in enumerate(frames[1:-1], start=1):
        n = frame_no(fr, i)
        got = read_card(fr)
        if not got:
            warn.append(f"{n}번 카드에서 글자를 못 찾았어요")
            continue
        card, nlines, extra, title_top = got
        card["no"] = n
        if weekly:
            # 주간 카드 제목은 두 줄이 기본이라 사이트·메일에선 한 줄로 잇는다(636 정답과 같게)
            card["title"] = re.sub(r"\s*\n\s*", " ", card["title"]).strip()
            nlines = 1
            # 출처가 아닌 작은 글씨 = 본문 덧붙임(851의 2번 "추가로 10.12 - 10.19 동안 …") → 본문 다음 문단 하나.
            # 851은 이 덧붙임이 글자 레이어 두 개로 나뉘어 있어서 한 문단으로 잇는다(636 정답과 같게)
            if extra:
                card["what"].append(" ".join(extra))
            extra = []
            card["what"] = [re.sub(r" {2,}", " ", p) for p in card["what"]]
            # 피그마에서 칸 폭 때문에 바꾼 줄은 띄어쓰기로 이어지는데, 낱말 중간이면 붙여 써야 한다(851: "핸드크림 이", "14대 의").
            # 기계로는 다 못 가려서 줄바꿈 뒤가 조사로 시작하는 곳만 짚고, 나머지는 판단 단계(명백한 띄어쓰기 고치기)에 맡긴다
            body = max((t for t in fr.iter("text") if not SRC_MARK.match(t.get("name", ""))), key=lambda t: len(t.get("name", "")), default=None)
            raw = body.get("lines", "") if body is not None else ""
            joins = re.findall(r"(\S{1,6})\n(이|가|을|를|은|는|의|에|로|와|과|도|만)(?=[\s,.!?…]|$)", raw)
            if joins:
                warn.append(f"{n}번 본문 줄바꿈 뒤가 조사라 띄어 쓴 곳: {', '.join(a + ' ' + b for a, b in joins)} — 붙여 써야 하면 고치기")
        if not card.get("title"):
            warn.append(f"{n}번 제목을 못 찾았어요 (큰 글씨 한 줄이 없음)")
        if not card.get("src") and not card.get("brand") and not card.get("who"):
            # 인터뷰 답 카드는 답한 사람이 곧 출처라 출처 줄이 없다
            warn.append(f"{n}번 출처가 없어요")
        if nlines > 1:
            warn.append(f"{n}번 제목이 {nlines}줄이라 띄어쓰기로 이었어요 — 줄바꿈이 맞으면 cards.json에서 \\n으로")
        if extra:
            warn.append(f"{n}번 카드의 꾸밈 글자는 뺐어요: {extra}")
        if n in videos or n in files:
            warn += video_card(d, n, card, fr, videos.get(n), files.get(n))
        else:
            # 사람이 내보낸 사진(images/NN.*)이 먼저, 없으면 글자 뺀 카드에서 자르기(플러그인 3판),
            # 예전 zip이면 플러그인 사진 원본, 그것도 없으면 MCP rawImages(figma/cardNN-raw-*)에서 고른다
            el = photo_layer(fr, bg)
            layer = el.get("name") if el is not None else None
            fn = find_image(d, n, layer)
            # 플러그인 3판: 글자 뺀 카드 그림에서 사진 칸을 잘라 쓴다 — 수동 작업 때처럼 카드에서 보던 구도 그대로,
            # 말풍선·작은 사진·좌우 두 장까지(10/17 사용자 B). 사진 원본은 zip에 표지 것만 있다
            layers = [e for e in fr if e.get("hash") and e.get("hash") not in bg]
            # 큰 사진들 바깥의 작은 그림 = 로고·아이콘. 319(2026년 2월 주간) 카드는 제목 끝 브랜드가 로고 그림이라
            # 이걸 사진으로 치면 자르는 칸이 제목 줄까지 내려가 흰 띠+로고가 붙었다(10/18). 자르기에서 빼고, 제목에 브랜드를 넣으라고 짚는다
            layers, logos = split_logos(fr, layers)
            if logos:
                warn.append(f"{n}번 제목 옆에 로고 그림이 있어요 — 카드를 보고 브랜드 이름을 제목에 넣기 (지금 제목: {card['title']})")
            if not fn and layers:
                fn = composite_photo(d, fr, layers, d / "images" / f"{n:02d}.jpg")
                # 사진 위에 얹은 글자 상자(851의 2번 "추가로 …")는 글자 뺀 그림에서 글자만 사라져 빈 상자로 남는다(10/18).
                # 사람은 글자째 살렸다 — 어느 쪽이 맞는지는 카드를 봐야 해서 짚기만 한다
                inside = texts_over_photo(fr, layers) if fn else []
                if inside:
                    warn.append(f"{n}번 사진 칸 안 글자 {inside}가 사진에서 빠져 빈 상자로 보일 수 있어요 — 카드 미리보기와 비교, "
                                f"글자째 필요하면 `visual {n} <위%> <아래%>`로 카드 그림에서 자르기")
            if not fn and scan_image(d, el):
                # 예전 zip(플러그인 2판): 사진 원본. 사진 위에 다른 사진이 겹친 카드는 원본 한 장만 들어간다
                fn = to_web(scan_image(d, el), d / "images" / f"{n:02d}.jpg")
                deco = [e.get("name") for e in layers if e.get("hash") != el.get("hash")]
                if deco:
                    warn.append(f"{n}번 사진 위에 겹친 그림 {deco}은 빠져요 — 피그마 플러그인으로 zip을 다시 받거나, 카드에서 글자만 숨겨 images/{n:02d}.jpg로")
            if not fn and raws(d, f"card{n:02d}"):
                fn = to_web(pick_photo(raws(d, f"card{n:02d}")), d / "images" / f"{n:02d}.jpg")
            if not fn:
                fn = card_visual(d, fr, title_top, d / "images" / f"{n:02d}.jpg")
                if fn:
                    warn.append(f"{n}번은 사진 레이어가 없어서(숫자·목록을 글자와 도형으로 그린 카드 등) 카드 그림에서 제목 위를 잘라 썼어요 — shot으로 확인")
            if fn:
                card["image"] = fn
            else:
                warn.append(f"{n}번 사진이 없어요 — 카드 미리보기(figma/cards/)를 보고 판단해 주세요")
        cards.append(card)

    # 표지: 표지에 쓰인 사진 중 진짜 사진 = 글자 없는 커버, export = 글자 있는 표지(대표 이미지)
    cover_raws = [p for p in (scan_image(d, e) for e in frames[0]) if p] if frames else []
    cover_raws = cover_raws or raws(d, "cover")
    if cover_raws:
        order["cover_photo"] = to_web(pick_photo(cover_raws), d / "images" / "cover.jpg")
    else:
        warn.append("표지 사진 원본이 없어요 (플러그인 zip의 figma/images/, 또는 MCP figma/cover-raw-*)")
    # 글자 있는 표지: 플러그인 3판은 JPG, 그 전 zip·MCP는 PNG
    cover_export = next((p for p in (d / "figma" / "cover-export.jpg", d / "figma" / "cover-export.png") if p.exists()), None)
    if cover_export:
        order["featured"] = to_web(cover_export, d / "images" / "featured.jpg")
    else:
        warn.append("figma/cover-export.jpg 가 없어요 (플러그인 zip, 또는 표지 프레임 download_assets의 export)")

    if weekly:
        letter, picks, w = read_weekly_caption(d)
        warn += w
        save(d, "cards.json", {"letter": letter, "picks": picks, "cards": cards})
        save(d, "order.json", order)
        # notes.json: Claude가 채울 칸 — 사진 alt와 사이트용 짧은 💡(insight). 뉴스레터용 긴 💡는 newsletter 쪽에서
        notes = load(d, "notes.json", {})
        if len(notes.get("cards", [])) != len(cards):
            notes = {"cards": [{"poster_alt": "", "insight": ""} if c.get("clip") else {"alt": "", "insight": ""} for c in cards]}
            save(d, "notes.json", notes)
        print(f"카드 {len(cards)}장 · 레터 {len(letter)}문단 · 에디터 픽 {len(picks)}개")
        print_sheet(d, frames)
        print("\n".join("· " + w for w in warn) if warn else "확인할 것 없음")
        return
    intro, outro, w = read_caption(d)
    warn += w
    # 캡션 첫 줄이 표지 제목과 같으면 뺀다 — 사이트에선 글 제목이 바로 위에 있어서 두 번 나온다
    cover_text = " ".join(t.get("name", "") for t in frames[0].iter("text")) if frames else ""
    if intro and "\n" not in intro[0] and squash(intro[0]) and squash(intro[0]) in squash(cover_text):
        warn.append(f"캡션 첫 줄은 표지 제목과 같아서 뺐어요: {intro[0]}")
        intro = intro[1:]
    save(d, "cards.json", {"intro": intro, "cards": cards, "outro": outro})
    save(d, "order.json", order)
    # notes.json은 Claude가 채울 칸만 비워서 만든다 (이미 있으면 카드 수가 같을 때 그대로 둔다)
    notes = load(d, "notes.json", {})
    if len(notes.get("cards", [])) != len(cards):
        # 우리 영상 카드는 사진 대신 포스터(재생 전 첫 화면)라 alt 칸 이름이 다르다
        notes = {"cover_alt": notes.get("cover_alt", ""),
                 "cards": [{"poster_alt": ""} if c.get("clip") else {"alt": ""} for c in cards]}
        save(d, "notes.json", notes)
    print(f"카드 {len(cards)}장 · 캡션 앞 {len(intro)}문단 · 끝 {len(outro)}문단")
    print_sheet(d, frames)
    print("\n".join("· " + w for w in warn) if warn else "확인할 것 없음")


def print_sheet(d, frames):
    s = sheet(d, frames)
    print(f"카드 미리보기 한 장: {s.relative_to(d).as_posix()} (왼쪽 위부터 한 줄에 4장, 표지 → 내용 카드 → 끝 카드)"
          if s else "카드 미리보기 한 장을 못 만들었어요 — figma/cards/를 한 장씩 보기")


def cook(d, pc=False):
    """판단이 끝난 주문을 한 번에 굽는다(10/18 토큰 절약): build → draft → verify → shot.
    예전엔 셰프가 네 명령을 따로 불러서 부를 때마다 대화가 한 바퀴씩 늘었다. 중간에 멈추면 그 단계 메시지로 끝난다"""
    build(d)
    publish(d)
    verify(d)
    shot(d, (390, 1280) if pc else (390,))


# ── draft: 사진 올리기 → 다시 build → 비공개 초안 ─────────
# 10/17: 명령 이름이 publish(발행)였을 때 클라우드 권한 검사기가 "발행 금지" 지시와 엇갈린다며 막았다.
# 실제로는 초안만 만들어서 이름을 draft로 바꿨다(함수 이름 publish는 그대로)

def upload(d, fn, alt):
    path = d / "images" / fn
    if not path.exists():
        sys.exit(f"{path} 가 없어요")
    ctype = mimetypes.guess_type(fn)[0] or "application/octet-stream"
    # 올릴 때 이름 앞에 주문 이름을 붙인다 — "02.jpg"끼리 겹치면 WordPress가 02-1.jpg처럼 바꿔 버려서
    name = f"{d.name}-{fn}"
    m = wp("media", path.read_bytes(), {"Content-Type": ctype, "Content-Disposition": f'attachment; filename="{name}"'})
    if alt:
        wp(f"media/{m['id']}", {"alt_text": alt})
    return {"id": m["id"], "url": m["source_url"]}


def alts(d):
    # 사진 파일마다 alt: 카드 사진은 notes의 alt, 영상 포스터는 notes의 poster_alt
    order, cards, notes = load(d, "order.json"), load(d, "cards.json"), load(d, "notes.json")
    out = {}
    if order.get("cover_photo"):
        out[order["cover_photo"]] = notes.get("cover_alt", "")
    if order.get("featured"):
        out.setdefault(order["featured"], notes.get("featured_alt", ""))
    for c, n in zip(cards["cards"], notes["cards"]):
        if c.get("image"):
            out[c["image"]] = n.get("alt", "")
        if c.get("poster"):
            out[c["poster"]] = n.get("poster_alt", "")
    # 셰프가 직접 짠 본문(body.html)에만 쓰인 사진의 alt
    out.update(notes.get("files") or {})
    # 인터뷰 대상 프로필 사진: 본문에선 alt를 비우지만 미디어 보관함에선 누구 사진인지 알 수 있게
    for p in order.get("people") or []:
        if p.get("photo"):
            out[p["photo"]] = f"{p.get('name') or '인터뷰 대상'} 인스타그램 프로필 사진"
    return out


def guard(d):
    """수정 때 덮어쓰기 전에: 사람이 편집기에서 초안을 직접 고쳤거나 이미 발행했으면 멈춘다(10/17 사용자 A — 사람 손은 절대 안 지운다).
    지난번 셰프가 올린 그대로인지는 published.json(올린 직후 라이브에서 다시 읽은 제목·본문)과 비교한다."""
    post_id = load(d, "result.json", {}).get("post_id")
    sent = load(d, "published.json", {})
    if not post_id or not sent:
        return  # 처음 만드는 초안, 또는 이 기능 전에 만든 초안
    live = wp(f"posts/{post_id}?context=edit")
    if live["status"] == "publish":
        sys.exit("이미 발행된 글이라 셰프는 고치지 않아요 — 편집기에서 직접 고쳐 주세요")
    if live["content"]["raw"] != sent.get("content") or live["title"]["raw"] != sent.get("title"):
        sys.exit("편집기에서 직접 고친 내용이 있어서 덮어쓰지 않았어요 — 남은 수정도 편집기에서 해 주세요")


def publish(d):
    guard(d)
    order = load(d, "order.json")
    table = load(d, "media.json", {})
    _, missing = build(d, quiet=True)
    # 대표 이미지(목록·공유 미리보기에 쓰이는 사진)는 본문에 없을 수도 있어서 따로 챙긴다
    featured = order.get("featured")
    if featured and featured not in table and featured not in missing:
        missing.append(featured)
    alt = alts(d)
    for fn in missing:
        # 하나 올릴 때마다 media.json에 적는다 — 중간에 끊겨도 다시 돌리면 이어서 올린다
        table[fn] = upload(d, fn, alt.get(fn, ""))
        save(d, "media.json", table)
        print(f"올림 {fn} → 미디어 {table[fn]['id']}")
    content, still = build(d, quiet=True)
    if still:
        sys.exit(f"아직 안 올라간 파일: {still}")

    post = {
        "title": title_of(order),
        "content": content,
        "status": "draft",  # 발행은 사람이 미리보기 보고 직접
        "categories": order.get("categories", []),
        "tags": order.get("tags", []),
        "jetpack_sharing_enabled": False,  # 글 스타일 규칙: 공유 버튼 끔
    }
    if featured:
        post["featured_media"] = table[featured]["id"]
    result = load(d, "result.json", {})
    if result.get("post_id"):
        # 이미 만든 초안이 있으면 새로 만들지 않고 그 초안을 고친다.
        # 상태는 안 보낸다 — 사람이 발행한 뒤 고쳐 올려도 다시 초안으로 내려가지 않게
        post.pop("status")
        p = wp(f"posts/{result['post_id']}", post)
    else:
        p = wp("posts", post)
    result.update(post_id=p["id"], status=p["status"],
                  preview=f"https://trendportal.kr/?p={p['id']}&preview=true",
                  edit=f"https://trendportal.kr/wp-admin/post.php?post={p['id']}&action=edit")
    save(d, "result.json", result)
    # 올린 그대로를 라이브에서 다시 읽어 남긴다 — 다음 수정 때 guard가 "그 사이 사람이 고쳤나"를 이걸로 본다
    live = wp(f"posts/{p['id']}?context=edit")
    save(d, "published.json", {"title": live["title"]["raw"], "content": live["content"]["raw"]})
    print(f"초안 {p['id']} ({p['status']}) · 미리보기 {result['preview']}")


# ── verify: 올라간 초안이 post.html과 같은지, 다른 곳만 출력 ──────

def first_diff(a, b, width=60):
    i = next((k for k in range(min(len(a), len(b))) if a[k] != b[k]), min(len(a), len(b)))
    return i, a[max(0, i - width):i + width], b[max(0, i - width):i + width]


def verify(d, against=None):
    result = load(d, "result.json")
    mine = (d / "post.html").read_text(encoding="utf-8")
    live = wp(f"posts/{result['post_id']}?context=edit")
    problems = []
    if live["content"]["raw"] != mine:
        i, a, b = first_diff(mine, live["content"]["raw"])
        problems.append(f"본문이 post.html과 달라요 ({i}번째 글자)\n  파일: …{a}…\n  초안: …{b}…")
    if live.get("jetpack_sharing_enabled"):
        problems.append("공유 버튼이 켜져 있어요")
    # 본문이 가리키는 사진·영상 주소가 전부 열리는지
    for fn, m in load(d, "media.json", {}).items():
        if m["url"] in mine:
            try:
                # 한글 파일 이름(예전 글의 '사이트1-2.jpg')은 주소에 그대로 못 실어서 %로 바꿔 보낸다
                url = urllib.parse.quote(m["url"], safe=":/%?&=")
                urllib.request.urlopen(urllib.request.Request(url, method="HEAD"), timeout=30)
            except urllib.error.URLError as e:
                problems.append(f"{fn} 주소가 안 열려요: {e}")
    if against:
        # 시험용: 손으로 만든 글과 똑같이 나오는지
        # 블록 사이 공백·줄바꿈은 빼고 비교한다 (편집기에서 만든 글은 빈 줄 수가 제각각이고 화면엔 차이가 없다)
        ref = wp(f"posts/{against}?context=edit")
        squeeze = lambda s: re.sub(r"\s*(<!-- /?wp:[^>]*-->)\s*", r"\1", s).strip()
        a_raw, l_raw = squeeze(ref["content"]["raw"]), squeeze(live["content"]["raw"])
        if a_raw != l_raw:
            i, a, b = first_diff(a_raw, l_raw)
            problems.append(f"글 {against}와 본문이 달라요 ({i}번째 글자)\n  {against}: …{a}…\n  초안: …{b}…")
        for k in ("title", "featured_media", "categories", "tags"):
            rv = ref[k]["raw"] if k == "title" else ref[k]
            lv = live[k]["raw"] if k == "title" else live[k]
            if rv != lv:
                problems.append(f"{k}: {against} = {rv} / 초안 = {lv}")
    result["verify"] = problems or "ok"
    save(d, "result.json", result)
    print("\n".join(problems) if problems else f"초안 {result['post_id']}: 차이 없음")


# ── css: 새 디자인 CSS를 사이트 추가 CSS 맨 끝에 붙이기 ──────────
# 10/17 사용자: 클라우드 셰프도 필요하면 CSS를 바꿔도 된다. 대신 안전장치를 사람 손이 아니라 이 명령이 지킨다:
# ① 규칙마다 선택자가 그 주문의 이름표(order.json design, 예: .tp-fmt-shop-grid)로 시작해야 한다 → 다른 글엔 안 닿음
# ② 주문 번호 표시로 감싸서 붙인다 → 다시 돌리면 그 덩어리만 바뀌고, --remove로 뺄 수 있다
# ③ 저장한 뒤 라이브를 다시 읽어 대조한다
# 저장소의 site/additional.css는 클라우드에서 커밋할 수 없으니 다음 로컬 세션에서 라이브와 맞춘다

def css_rules(css):
    """CSS → [(선택자 머리, 몸통)]. @media·@supports 안쪽까지 내려간다. 괄호가 어긋나면 ("?", 이유)."""
    out, i = [], 0
    while i < len(css):
        j = css.find("{", i)
        if j < 0:
            if css[i:].strip():
                out.append(("?", f"규칙 밖 글자: {css[i:].strip()[:40]}"))
            break
        depth, k = 1, j + 1
        while k < len(css) and depth:
            depth += {"{": 1, "}": -1}.get(css[k], 0)
            k += 1
        if depth:
            out.append(("?", "괄호가 안 닫혔어요"))
            break
        head, body = css[i:j].strip(), css[j + 1:k - 1]
        if re.match(r"@(media|supports)\b", head):
            out += css_rules(body)
        else:
            out.append((head, body))
        i = k
    return out


def css_problems(css, cls):
    bad = []
    for head, body in css_rules(css):
        if head == "?":
            bad.append(body)
        elif head.startswith("@"):
            # 애니메이션 이름도 이름표로 시작해야 겹치지 않는다. @import·@font-face 등은 안 받는다(글꼴은 라이선스 확인이 먼저)
            if not re.fullmatch(rf"@keyframes\s+{re.escape(cls)}[\w-]*", head):
                bad.append(f"이 규칙은 못 받아요: {head[:40]}")
        else:
            for sel in head.split(","):
                if not re.match(rf"\.{re.escape(cls)}(?![\w-])", sel.strip()):
                    bad.append(f".{cls}로 시작하지 않는 선택자: {sel.strip()[:60]}")
        for u in re.findall(r"url\(\s*['\"]?([^'\")]*)", body):
            if not u.startswith("https://trendportal.kr/"):
                bad.append(f"사이트 밖 그림 주소는 못 써요: {u[:60]}")
    if "</" in css:
        bad.append("</ 는 CSS에 쓸 수 없어요")
    return bad


def css_marks(order_id):
    return f"/* cardnews order {order_id} start */", f"/* cardnews order {order_id} end */"


def styles_url():
    # 블록 테마의 "추가 CSS"는 전역 스타일 글(global-styles/<번호>)의 styles.css에 들어 있다(10/17 확인: 라이브 = site/additional.css)
    theme = wp("themes?status=active&_fields=_links")[0]
    return theme["_links"]["wp:user-global-styles"][0]["href"]


def css(d, src=None, remove=False):
    order = load(d, "order.json")
    oid = order.get("order_id") or d.name
    start, end = css_marks(oid)
    url = styles_url()
    g = wp(url + "?context=edit")
    live = g["styles"].get("css", "")
    # 이 주문이 전에 붙인 덩어리는 먼저 뺀다(다시 돌려도 두 번 붙지 않게)
    base = re.sub(rf"\n*{re.escape(start)}.*?{re.escape(end)}\n?", "\n", live, flags=re.S).rstrip() + "\n"
    if remove:
        new = base
    else:
        cls = design_class(order)
        # 주석은 뺀다: 표시 주석을 흉내 내는 글자를 막고, 한글 주석이 저장에서 문제를 일으킨 적이 있어서(9/20 MCP)
        add = re.sub(r"/\*.*?\*/", "", Path(src).read_text(encoding="utf-8"), flags=re.S).strip()
        bad = css_problems(add, cls)
        if bad:
            sys.exit("CSS를 붙이지 않았어요:\n" + "\n".join("· " + b for b in bad))
        new = f"{base}\n{start}\n{add}\n{end}\n"
        (d / "design.css").write_text(add + "\n", encoding="utf-8")
    if new == live:
        print("바뀐 것 없음")
        return
    styles = dict(g["styles"], css=new)  # styles는 통째로 덮어쓰므로 글꼴·색 설정도 그대로 같이 보낸다
    wp(url, {"styles": styles})
    after = wp(url + "?context=edit")["styles"].get("css", "")
    if "".join(after.split()) != "".join(new.split()):
        i, a, b = first_diff(new, after)
        sys.exit(f"저장했는데 라이브가 달라요 ({i}번째 글자)\n  보낸 것: …{a}…\n  라이브: …{b}…")
    done = f"추가 CSS에서 주문 {oid} 덩어리를 뺐어요" if remove else "추가 CSS 맨 끝에 붙였어요"
    print(f"{done} · 라이브 대조 일치 ({len(after):,}자)")


# ── pull·report: 관리자 탭(TP Cardnews Helper)과 주고받기 ────────
# 탭은 주문을 받아 두기만 하고, 요리는 여기서 한다. 클라우드 routine도 같은 명령을 쓴다(10/17 사용자 결정 A)

# 셰프가 다음 수정 때 이어받을 작업 기록. 클라우드는 매번 빈 컴퓨터라 탭(주문 메타)에 맡겨 둔다(10/17 수정 요청 기능)
STATE_FILES = ("order.json", "cards.json", "notes.json", "media.json", "result.json", "published.json", "design.css", "body.html",
               "newsletter.json")  # 뉴스레터 주문(탭 1.20.0)의 뉴스레터 글


def pull_people(d, o):
    """인터뷰 대상 사진: 원본은 uploads/profile-N, 사이트용은 images/profile-N.jpg (cardnews.py profile이 원본에서 다시 자른다)"""
    people = []
    for i, p in enumerate(o.get("people") or [], start=1):
        person = {"name": p["name"], "insta": p["insta"]}
        if p.get("photo"):
            (d / "uploads").mkdir(exist_ok=True)
            src = d / "uploads" / f"profile-{i}"
            src.write_bytes(wp(p["photo"], raw=True))
            (d / "images").mkdir(exist_ok=True)
            person["photo"] = to_web(src, d / "images" / f"profile-{i}.jpg")
        people.append(person)
    return people


def pull():
    """접수된 주문 → local/cardnews/order-<번호>/, 탭 상태는 '요리 중'으로.
    새 주문: zip 풀기 + caption.txt + order.json. 수정 요청: 지난 작업 기록(state) + feedback.txt (+ 남아 있으면 zip)."""
    orders = (wp("orders?status=new", base=ORDER_API) + wp("orders?status=revise", base=ORDER_API)
              + wp("orders?status=answered", base=ORDER_API))
    if not orders:
        print("새 주문 없음")
        return
    for o in orders:
        d = ORDERS / f"order-{o['id']}"
        d.mkdir(parents=True, exist_ok=True)
        revise = o["status"] == "revise"
        answered = o["status"] == "answered"  # 셰프가 물어본 것에 사람이 답한 주문: 물을 때 맡긴 작업 기록에서 이어 간다
        if o.get("has_zip", True):
            z = d / "order.zip"
            z.write_bytes(wp(o["zip"], raw=True))
            with zipfile.ZipFile(z) as zf:
                # 플러그인이 만든 zip이지만 내려받은 파일이라 폴더 밖으로 나가는 이름은 막는다
                bad = [n for n in zf.namelist() if n.startswith("/") or ".." in Path(n).parts]
                if bad:
                    wp(f"orders/{o['id']}", {"status": "failed", "error": f"zip 안 파일 이름이 이상해요: {bad[0]}"}, base=ORDER_API)
                    continue
                zf.extractall(d)
        # 영상 카드 파일(1.6.0): 수정 때도 받는다 — "3번 정지 화면 다른 장면으로"에 원본 영상이 필요해서. 발행하면 탭이 지운다
        files = {}
        for no, f in (o.get("files") or {}).items():
            ext = Path(f["name"]).suffix.lower()
            rel = f"uploads/{int(no):02d}-src{ext}"
            (d / "uploads").mkdir(exist_ok=True)
            (d / rel).write_bytes(wp(f["url"], raw=True))
            files[str(int(no))] = {"path": rel, "kind": f["kind"]}
        if answered:
            state = {fn: text for fn, text in (o.get("state") or {}).items() if fn in STATE_FILES}
            (d / "caption.txt").write_text(o["caption"] + "\n", encoding="utf-8")
            for fn, text in state.items():
                (d / fn).write_text(text, encoding="utf-8")
            # 작업 기록엔 JSON만 있어서, 아직 안 올린 사진(카드에서 자른 것 등)은 zip에서 다시 만든 뒤 셰프가 고친 기록을 다시 덮는다
            if (d / "figma" / "scan.json").exists():
                fetch(d)
                for fn, text in state.items():
                    (d / fn).write_text(text, encoding="utf-8")
            pull_people(d, o)  # 대상 사진 원본도 다시 받는다(자른 사진이 없으면 셰프가 profile로 다시 자른다)
            (d / "answer.txt").write_text(f"질문: {o.get('question', '')}\n답: {o.get('answer', '')}\n", encoding="utf-8")
            if o.get("feedback"):  # 수정 요청을 하다가 물은 것이면 그 요청도 다시 둔다
                (d / "feedback.txt").write_text(o["feedback"] + "\n", encoding="utf-8")
        elif revise:
            # 지난번 끝난 상태 그대로 되살린다 — fetch를 다시 돌리지 않아도 되고, 사진도 다시 올리지 않는다(media.json)
            for fn, text in (o.get("state") or {}).items():
                if fn in STATE_FILES:
                    (d / fn).write_text(text, encoding="utf-8")
            (d / "feedback.txt").write_text(o["feedback"] + "\n", encoding="utf-8")
            (d / "changes.json").unlink(missing_ok=True)  # 이번 수정에서 손본 것만 새로 적는다
        else:
            (d / "caption.txt").write_text(o["caption"] + "\n", encoding="utf-8")
            order = load(d, "order.json", {})
            order.update(kind="archive", order_id=o["id"], group=o["group"], videos=o["videos"], files=files,
                         memo=o["memo"])
            # 인터뷰 폼(탭 1.8.0): 형식은 주문한 사람이 고른 것 그대로, 대상 사진은 images/profile-N.jpg로
            if o.get("kind") == "interview":
                order["layout"] = o.get("layout") or None
                order["people"] = pull_people(d, o)
            # 광고 폼(탭 1.11.0): 광고 브랜드도 같은 people 칸으로 온다. 광고 폼으로 온 주문이 곧 유료 광고라 카테고리는 광고로 둔다
            elif o.get("kind") == "ad":
                order["ad"] = True
                order["people"] = pull_people(d, o)
                order["categories"] = [18540]
            # 뉴스레터 폼(탭 1.20.0, 10/18): 주간 총정리 zip 하나로 사이트 주간 글 초안 + 뉴스레터(docs/newsletter.md).
            # 카테고리·태그는 주간 글 고정값, 주차(issue)는 셰프가 표지에서 읽어 적는다
            elif o.get("kind") == "newsletter":
                order.update(kind="weekly", newsletter=True, issue=order.get("issue", ""), categories=[5180795], tags=[5180798],
                             reports=o.get("reports") or [])
            save(d, "order.json", order)
            if o.get("post_id") and not (d / "result.json").exists():
                # "셰프 다시 부르기"로 다시 온 주문: 클라우드는 매번 빈 컴퓨터라 지난 초안 번호를 탭에서 받아 와야
                # draft가 새 초안을 또 만들지 않고 그 초안을 고친다
                save(d, "result.json", {"post_id": o["post_id"]})
        wp(f"orders/{o['id']}", {"status": "working"}, base=ORDER_API)
        what = (f"질문 답: {o.get('question', '')} → {o.get('answer', '')}" if answered
                else f"수정 요청: {o['feedback']}" if revise else (f"메모: {o['memo']}" if o["memo"] else ""))
        print(f"주문 {o['id']} {o['group']} → {d.relative_to(ROOT)}" + (f"  · {what}" if what else ""))


def report(d, fail=None, ask=None):
    """결과를 탭에 적는다: 초안 번호·제목·고친 것 목록(changes.json), 또는 멈춘 이유, 또는 사람에게 묻는 질문.
    질문(ask)일 땐 지금까지 한 작업 기록도 맡긴다 — 답이 오면 빈 컴퓨터에서 여기부터 이어 가려고(pull 참고)"""
    order = load(d, "order.json")
    if "order_id" not in order:
        sys.exit("탭에서 온 주문이 아니에요 (order.json에 order_id가 없음)")
    if ask:
        body = {"status": "question", "question": ask,
                "state": {fn: (d / fn).read_text(encoding="utf-8") for fn in STATE_FILES if (d / fn).exists()}}
        made = load(d, "result.json", {}).get("post_id")
        if made:
            body["post_id"] = made
        o = wp(f"orders/{order['order_id']}", body, base=ORDER_API)
        print(f"탭에 질문을 남겼어요: 주문 {o['id']} → {o['status']}")
        return
    if fail:
        body = {"status": "failed", "error": fail}
        # 초안까지 만든 뒤 멈췄으면 그 번호도 남긴다 — 다시 요리할 때 같은 초안을 고치게(pull 참고)
        made = load(d, "result.json", {}).get("post_id")
        if made:
            body["post_id"] = made
    else:
        result = load(d, "result.json")
        body = {"status": "done", "post_id": result["post_id"], "title": title_of(order),
                "changes": load(d, "changes.json", [])}
        if order.get("newsletter"):
            mail = d / "out" / "stibee.html"
            if not mail.exists():
                sys.exit("뉴스레터 주문인데 out/stibee.html이 없어요 — newsletter.py build 먼저")
            body["newsletter"] = mail.read_text(encoding="utf-8")
        # 작업 기록도 같이 맡긴다 — 다음 "고쳐 주세요" 때 셰프가 이어받는다(pull 참고).
        # 끝까지 된 때만: 멈춘 수정의 반쯤 고친 기록을 맡기면 다시 부를 때 그 위에 또 고치게 된다
        body["state"] = {fn: (d / fn).read_text(encoding="utf-8") for fn in STATE_FILES if (d / fn).exists()}
    o = wp(f"orders/{order['order_id']}", body, base=ORDER_API)
    print(f"탭에 적었어요: 주문 {o['id']} → {o['status']}" + (f" · 초안 {o['post_id']}" if o.get("post_id") else ""))


def main(argv):
    if argv[:1] == ["pull"]:
        return pull()
    if argv[:1] == ["ref"] and len(argv) == 2:
        return ref(int(argv[1]))
    if argv[:1] == ["shot"] and len(argv) in (2, 3):
        return shot(order_dir(argv[1]), (390, 1280) if "--pc" in argv else (390,))
    if argv[:1] == ["cook"] and len(argv) in (2, 3):
        return cook(order_dir(argv[1]), "--pc" in argv)
    if argv[:1] == ["visual"] and len(argv) == 5:
        return visual(order_dir(argv[1]), int(argv[2]), float(argv[3]), float(argv[4]))
    if len(argv) < 2 or argv[0] not in ("fetch", "build", "draft", "publish", "verify", "report", "css", "still", "profile"):
        sys.exit(__doc__)
    d = order_dir(argv[1])
    if not d.is_dir():
        sys.exit(f"주문 폴더가 없어요: {d}")
    if argv[0] == "profile":
        if len(argv) not in (3, 6):
            sys.exit("profile <주문> <대상 번호> [<왼쪽 x> <위 y> <한 변>]")
        return profile(d, int(argv[2]), tuple(int(v) for v in argv[3:6]) if len(argv) == 6 else None)
    if argv[0] == "still":
        if len(argv) < 4:
            sys.exit("still <주문> <카드 번호> <초>")
        return still(d, int(argv[2]), float(argv[3]))
    if argv[0] == "css":
        if "--remove" in argv:
            return css(d, remove=True)
        if len(argv) < 3:
            sys.exit("css <주문> <파일.css> 또는 css <주문> --remove")
        return css(d, argv[2])
    if argv[0] == "fetch":
        fetch(d)
    elif argv[0] == "build":
        build(d)
    elif argv[0] in ("draft", "publish"):
        publish(d)
    elif argv[0] == "report":
        report(d, argv[argv.index("--fail") + 1] if "--fail" in argv else None,
               argv[argv.index("--ask") + 1] if "--ask" in argv else None)
    else:
        against = argv[argv.index("--against") + 1] if "--against" in argv else None
        verify(d, against)


if __name__ == "__main__":
    main(sys.argv[1:])
