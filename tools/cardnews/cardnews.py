#!/usr/bin/env python3
"""트란시스 카드뉴스 도우미 — 주방(1단계).

주문 폴더 하나(local/cardnews/<주문>/)를 받아 단계별로 처리한다.

    python3 tools/cardnews/cardnews.py pull                (관리자 탭 "트란시스 카드뉴스 도우미"의 새 주문 → local/cardnews/order-<번호>/)
    python3 tools/cardnews/cardnews.py fetch   <주문>     (아카이빙만, 피그마 플러그인의 figma/scan.json 또는 MCP 결과 파일 필요)
    python3 tools/cardnews/cardnews.py build   <주문>
    python3 tools/cardnews/cardnews.py draft   <주문>     (사진 올리기 + 비공개 초안. 예전 이름 publish도 됨)
    python3 tools/cardnews/cardnews.py verify  <주문> [--against 글번호]
    python3 tools/cardnews/cardnews.py report  <주문> [--fail "이유"]   (결과를 관리자 탭에 적는다)
    python3 tools/cardnews/cardnews.py css     <주문> <파일.css> | --remove  (새 디자인 CSS를 사이트에 붙이기·빼기)

주문 폴더:
    order.json   주문서: kind(weekly|archive), issue(주간 호수) 또는 title(아카이빙 제목),
                 featured(대표 이미지), cover_photo(아카이빙 글자 없는 커버), categories, tags
    cards.json   카드 글자·사진 이름 (fetch 단계 결과 — 아직은 손으로/임시 스크립트로 만든다)
                 글자 속 줄바꿈은 "\\n"으로 적는다 (<br>로 바뀜)
    notes.json   Claude가 쓰는 칸만: 카드별 alt·💡(주간), cover_alt, poster_alt
    images/      사진·영상 파일
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


def wp(path, data=None, headers=None, method=None, base=API, raw=False):
    h = dict(auth_header(), **(headers or {}))
    if isinstance(data, (dict, list)):
        data = json.dumps(data).encode()
        h["Content-Type"] = "application/json"
    url = path if path.startswith("https://") else base + path
    # 429(요청이 너무 많음)·5xx는 잠깐 기다렸다 다시 — 클라우드에서 첫 요청이 429로 막힌 적이 있다(10/17 환경 시험).
    # 429는 서버가 아무것도 안 하고 돌려보낸 것이라 다시 보내도 안전하다. 5xx는 드물게 저장은 된 채 오류만 올 수 있어
    # 사진·초안이 하나 더 생길 수 있지만, 주문이 통째로 멈추는 것보다 낫다
    for wait in (10, 30, 60, None):
        req = urllib.request.Request(url, data=data, headers=h, method=method)
        try:
            with urllib.request.urlopen(req, timeout=300) as r:
                return r.read() if raw else json.load(r)
        except urllib.error.HTTPError as e:
            if wait and (e.code == 429 or e.code >= 500):
                print(f"WordPress {e.code} — {wait}초 뒤 다시 해요", file=sys.stderr)
                time.sleep(wait)
                continue
            # 응답 본문 앞부분만 보여 준다 (덩어리 전체를 찍으면 읽기도 어렵고 길다)
            sys.exit(f"WordPress 오류 {e.code} {path}: {e.read()[:300].decode('utf-8', 'replace')}")


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

def build_archive(order, cards, notes, media):
    if len(notes["cards"]) != len(cards["cards"]):
        sys.exit(f"카드 {len(cards['cards'])}장인데 notes는 {len(notes['cards'])}장이에요")
    out = ""
    if order.get("cover_photo"):
        # TP Cover Photo가 이 이름표(tp-cover-photo) 사진을 글 머리 커버로 옮긴다
        out += image_block(media, order["cover_photo"], notes.get("cover_alt", ""),
                           size="large", cls="tp-cover-photo", link_none=True)
    out += "".join(para(esc(p)) for p in cards["intro"])
    # 카피 모음형(카드마다 "-브랜드-" 줄이 있는 764식)은 원본처럼 어두운 전시장 띠 하나에 카드를 이어 붙인다
    # (사용자 결정 C, 10/17 — 시안 docs/트렌드포털-시안비교/1017-1-*). 모양은 additional.css의 .tp-copy-stage
    copy = bool(cards["cards"]) and all(c.get("brand") for c in cards["cards"])
    if copy:
        out += ('<!-- wp:group {"align":"full","className":"tp-copy-stage","layout":{"type":"default"}} -->'
                '<div class="wp-block-group alignfull tp-copy-stage">')
    for card, note in zip(cards["cards"], notes["cards"]):
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
    else:
        # MCP 메타데이터엔 글자 크기가 없어서: 큰 글씨 한 줄(높이 60~120)
        lines = [t for t in others if 60 <= float(t.get("height")) <= 120]
    # 두 줄 제목이 레이어 둘로 나뉘어 있으면 위에서부터 띄어쓰기로 잇고, 레이어 하나 안의 줄바꿈은 살린다
    lines.sort(key=lambda t: float(t.get("y")))
    extra = [t.get("name") for t in others if t not in lines]
    card = {"title": " ".join((t.get("lines") or t.get("name")).strip() for t in lines), "what": [body.get("name").strip()]}
    # 카피 모음형(764)의 "-가텐 (구인 정보지)-" 줄 = 그 카피를 쓴 브랜드
    brand = next((m.group(1) for m in (BRAND_LINE.match(x) for x in extra) if m), None)
    if brand:
        card["brand"] = brand
        extra = [x for x in extra if not BRAND_LINE.match(x)]
    if src:
        card["src"] = SRC_MARK.sub("", src[0].get("name")).strip()
    return card, len(lines), extra


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
    return label, note


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


def fetch(d):
    order = load(d, "order.json")
    if order["kind"] != "archive":
        sys.exit("fetch는 아직 아카이빙(archive)만 돼요")
    (d / "images").mkdir(exist_ok=True)
    # 플러그인 결과가 있으면 그걸, 없으면 예전처럼 MCP 메타데이터를 읽는다
    if (d / "figma" / "scan.json").exists():
        group = scan_group(d)
    else:
        group = figma_group(d, order["figma"]["group"])
    frames = reading_order([c for c in group if c.tag == "frame"])
    videos = {int(k): v for k, v in order.get("videos", {}).items()}
    bg = background_hashes(frames[1:-1])
    cards, warn = [], []
    # 첫 장 = 표지, 마지막 장 = 끝 카드(계정 이름 + 제목 한 줄)라서 빼고, 그 사이가 내용 카드
    for i, fr in enumerate(frames[1:-1], start=1):
        n = frame_no(fr, i)
        got = read_card(fr)
        if not got:
            warn.append(f"{n}번 카드에서 글자를 못 찾았어요")
            continue
        card, nlines, extra = got
        card["no"] = n
        if not card.get("title"):
            warn.append(f"{n}번 제목을 못 찾았어요 (큰 글씨 한 줄이 없음)")
        if not card.get("src") and not card.get("brand"):
            warn.append(f"{n}번 출처가 없어요")
        if nlines > 1:
            warn.append(f"{n}번 제목이 {nlines}줄이라 띄어쓰기로 이었어요 — 줄바꿈이 맞으면 cards.json에서 \\n으로")
        if extra:
            warn.append(f"{n}번 카드의 꾸밈 글자는 뺐어요: {extra}")
        if n in videos:
            link = clean_link(videos[n])
            fn = f"{n:02d}-video.jpg"
            label, note = video_still(link, d / "images" / fn)
            card.update(image=fn, video={"url": link, "label": label})
            size = jpeg_size(d / "images" / fn)
            if size and size[0] > size[1]:
                card["wide"] = True  # 주간 틀에서만 쓰지만 같이 적어 둔다
            if note:
                warn.append(f"{n}번 영상: {note}")
        else:
            # 사람이 내보낸 사진(images/NN.*)이 먼저, 없으면 글자 뺀 카드에서 자르기(플러그인 3판),
            # 예전 zip이면 플러그인 사진 원본, 그것도 없으면 MCP rawImages(figma/cardNN-raw-*)에서 고른다
            el = photo_layer(fr, bg)
            layer = el.get("name") if el is not None else None
            fn = find_image(d, n, layer)
            # 플러그인 3판: 글자 뺀 카드 그림에서 사진 칸을 잘라 쓴다 — 수동 작업 때처럼 카드에서 보던 구도 그대로,
            # 말풍선·작은 사진·좌우 두 장까지(10/17 사용자 B). 사진 원본은 zip에 표지 것만 있다
            layers = [e for e in fr if e.get("hash") and e.get("hash") not in bg]
            if not fn and layers:
                fn = composite_photo(d, fr, layers, d / "images" / f"{n:02d}.jpg")
            if not fn and scan_image(d, el):
                # 예전 zip(플러그인 2판): 사진 원본. 사진 위에 다른 사진이 겹친 카드는 원본 한 장만 들어간다
                fn = to_web(scan_image(d, el), d / "images" / f"{n:02d}.jpg")
                deco = [e.get("name") for e in layers if e.get("hash") != el.get("hash")]
                if deco:
                    warn.append(f"{n}번 사진 위에 겹친 그림 {deco}은 빠져요 — 피그마 플러그인으로 zip을 다시 받거나, 카드에서 글자만 숨겨 images/{n:02d}.jpg로")
            if not fn and raws(d, f"card{n:02d}"):
                fn = to_web(pick_photo(raws(d, f"card{n:02d}")), d / "images" / f"{n:02d}.jpg")
            if fn:
                card["image"] = fn
            else:
                warn.append(f"{n}번 사진이 없어요 — 피그마 '{layer}' 레이어를 images/에 내보내 주세요")
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
        notes = {"cover_alt": notes.get("cover_alt", ""), "cards": [{"alt": ""} for _ in cards]}
        save(d, "notes.json", notes)
    print(f"카드 {len(cards)}장 · 캡션 앞 {len(intro)}문단 · 끝 {len(outro)}문단")
    print("\n".join("· " + w for w in warn) if warn else "확인할 것 없음")


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
    return out


def publish(d):
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

def pull():
    """접수된 주문 → local/cardnews/order-<번호>/ (zip 풀기 + caption.txt + order.json), 탭 상태는 '만드는 중'으로."""
    orders = wp("orders?status=new", base=ORDER_API)
    if not orders:
        print("새 주문 없음")
        return
    for o in orders:
        d = ORDERS / f"order-{o['id']}"
        d.mkdir(parents=True, exist_ok=True)
        z = d / "order.zip"
        z.write_bytes(wp(o["zip"], raw=True))
        with zipfile.ZipFile(z) as zf:
            # 플러그인이 만든 zip이지만 내려받은 파일이라 폴더 밖으로 나가는 이름은 막는다
            bad = [n for n in zf.namelist() if n.startswith("/") or ".." in Path(n).parts]
            if bad:
                wp(f"orders/{o['id']}", {"status": "failed", "error": f"zip 안 파일 이름이 이상해요: {bad[0]}"}, base=ORDER_API)
                continue
            zf.extractall(d)
        (d / "caption.txt").write_text(o["caption"] + "\n", encoding="utf-8")
        order = load(d, "order.json", {})
        order.update(kind="archive", order_id=o["id"], group=o["group"], videos=o["videos"], memo=o["memo"])
        save(d, "order.json", order)
        if o.get("post_id") and not (d / "result.json").exists():
            # "셰프 다시 부르기"로 다시 온 주문: 클라우드는 매번 빈 컴퓨터라 지난 초안 번호를 탭에서 받아 와야
            # draft가 새 초안을 또 만들지 않고 그 초안을 고친다
            save(d, "result.json", {"post_id": o["post_id"]})
        wp(f"orders/{o['id']}", {"status": "working"}, base=ORDER_API)
        print(f"주문 {o['id']} {o['group']} → {d.relative_to(ROOT)}" + (f"  · 메모: {o['memo']}" if o["memo"] else ""))


def report(d, fail=None):
    """결과를 탭에 적는다: 초안 번호·제목·고친 것 목록(changes.json), 또는 멈춘 이유."""
    order = load(d, "order.json")
    if "order_id" not in order:
        sys.exit("탭에서 온 주문이 아니에요 (order.json에 order_id가 없음)")
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
    o = wp(f"orders/{order['order_id']}", body, base=ORDER_API)
    print(f"탭에 적었어요: 주문 {o['id']} → {o['status']}" + (f" · 초안 {o['post_id']}" if o.get("post_id") else ""))


def main(argv):
    if argv[:1] == ["pull"]:
        return pull()
    if len(argv) < 2 or argv[0] not in ("fetch", "build", "draft", "publish", "verify", "report", "css"):
        sys.exit(__doc__)
    d = order_dir(argv[1])
    if not d.is_dir():
        sys.exit(f"주문 폴더가 없어요: {d}")
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
        report(d, argv[argv.index("--fail") + 1] if "--fail" in argv else None)
    else:
        against = argv[argv.index("--against") + 1] if "--against" in argv else None
        verify(d, against)


if __name__ == "__main__":
    main(sys.argv[1:])
