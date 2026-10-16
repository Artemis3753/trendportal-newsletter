#!/usr/bin/env python3
"""트란시스 카드뉴스 도우미 — 주방(1단계).

주문 폴더 하나(local/cardnews/<주문>/)를 받아 단계별로 처리한다.

    python3 tools/cardnews/cardnews.py fetch   <주문>     (아카이빙만, 피그마 MCP 결과 파일 필요)
    python3 tools/cardnews/cardnews.py build   <주문>
    python3 tools/cardnews/cardnews.py publish <주문>
    python3 tools/cardnews/cardnews.py verify  <주문> [--against 글번호]

주문 폴더:
    order.json   주문서: kind(weekly|archive), issue(주간 호수) 또는 title(아카이빙 제목),
                 featured(대표 이미지), cover_photo(아카이빙 글자 없는 커버), categories, tags
    cards.json   카드 글자·사진 이름 (fetch 단계 결과 — 아직은 손으로/임시 스크립트로 만든다)
                 글자 속 줄바꿈은 "\\n"으로 적는다 (<br>로 바뀜)
    notes.json   Claude가 쓰는 칸만: 카드별 alt·💡(주간), cover_alt, poster_alt
    images/      사진·영상 파일
    media.json   publish가 채움: 파일 이름 → 미디어 번호·주소 (다시 돌려도 두 번 올리지 않게)
    post.html    build 결과 (블록 HTML)
    result.json  publish·verify 결과 (초안 번호·미리보기 링크·대조 결과)

표준 라이브러리만 쓴다 — 나중에 클라우드 routine에서 설치 없이 돌리려고.
"""
import base64
import html
import json
import mimetypes
import re
import shutil
import sys
import xml.etree.ElementTree as ET
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ORDERS = ROOT / "local" / "cardnews"
ENV = ROOT / "local" / "cardnews.env"
API = "https://trendportal.kr/wp-json/wp/v2/"


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
    # 값에 공백이 있어서(애플리케이션 비밀번호) 셸 source 대신 직접 읽는다
    env = {}
    for line in ENV.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.lstrip().startswith("#"):
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    token = base64.b64encode(f"{env['WP_USER']}:{env['WP_APP_PASSWORD']}".encode()).decode()
    return {"Authorization": "Basic " + token}


def wp(path, data=None, headers=None, method=None):
    h = dict(auth_header(), **(headers or {}))
    if isinstance(data, (dict, list)):
        data = json.dumps(data).encode()
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(API + path, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
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
    for card, note in zip(cards["cards"], notes["cards"]):
        if card.get("clip"):
            out += clip_block(media, card["clip"], card["poster"])
        if card.get("image"):
            out += image_block(media, card["image"], note.get("alt", ""), size="large")
        if card.get("video"):
            out += video_link(card["video"])
        out += h2(card["title"])
        out += "".join(para(esc(p)) for p in card["what"])
        if card.get("src"):
            out += ('<!-- wp:paragraph {"className":"tp-source","fontSize":"small"} -->'
                    f'<p class="tp-source has-small-font-size">© {esc(card["src"])}</p><!-- /wp:paragraph -->')
    out += "".join(para(esc(p)) for p in cards.get("outro", []))
    return out


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
# 피그마는 1단계에선 MCP로 받는다(사용자 결정 A'). Claude가 MCP 결과를 figma/ 폴더에 파일로 두면 여기서 읽는다:
#   figma/metadata.xml   get_metadata(묶음 노드) 결과 XML (MCP 결과 파일 통째로 둬도 됨)
#   figma/cover-export.png, figma/cover-raw-1.*   download_assets(표지 프레임)의 export와 rawImages
# 카드 사진은 사람이 글자 레이어를 숨기고 내보내서 images/02.jpg처럼 카드 번호로 넣는다(사용자 결정 A)

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


def frame_no(fr, i):
    # 프레임 이름 끝 번호("트렌드포털 802-3" → 3)가 카드 번호. 없으면 왼쪽부터 센 순서
    m = re.search(r"-(\d+)\s*$", fr.get("name", ""))
    return int(m.group(1)) if m else i + 1


def read_card(fr):
    """카드 프레임 하나 → 제목·본문·출처. 글자 레이어 이름 = 카드에 적힌 글자 전체(10/16 242로 확인)."""
    texts = [t for t in fr.iter("text") if t.get("name", "").strip()]
    src = [t for t in texts if t.get("name").startswith("©")]
    if not src:
        return None  # 표지·끝 카드에는 출처가 없다
    rest = [t for t in texts if t not in src]
    body = max(rest, key=lambda t: len(t.get("name")))
    # 제목 = 큰 글씨 한 줄(높이 60~120). 두 줄 제목은 레이어가 둘로 나뉘어 있어 위에서부터 잇는다
    lines = sorted((t for t in rest if t is not body and 60 <= float(t.get("height")) <= 120), key=lambda t: float(t.get("y")))
    extra = [t.get("name") for t in rest if t is not body and t not in lines]
    return {
        "title": " ".join(t.get("name").strip() for t in lines),
        "what": [body.get("name").strip()],
        "src": src[0].get("name")[1:].strip(),
    }, len(lines), extra


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


def find_image(d, n):
    for ext in ("jpg", "jpeg", "png", "webp"):
        if (d / "images" / f"{n:02d}.{ext}").exists():
            return f"{n:02d}.{ext}"
    return None


def fetch(d):
    order = load(d, "order.json")
    if order["kind"] != "archive":
        sys.exit("fetch는 아직 아카이빙(archive)만 돼요")
    (d / "images").mkdir(exist_ok=True)
    group = figma_group(d, order["figma"]["group"])
    frames = sorted((c for c in group if c.tag == "frame"), key=lambda e: float(e.get("x")))
    videos = {int(k): v for k, v in order.get("videos", {}).items()}
    cards, warn = [], []
    for i, fr in enumerate(frames):
        n = frame_no(fr, i)
        got = read_card(fr)
        if not got:
            continue
        card, nlines, extra = got
        card["no"] = n
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
            fn = find_image(d, n)
            if fn:
                card["image"] = fn
            else:
                warn.append(f"{n}번 사진이 없어요 — images/{n:02d}.jpg로 내보내 주세요")
        cards.append(card)

    # 표지: rawImages 첫 장 = 글자 없는 원본 사진(커버), export = 글자 있는 표지(대표 이미지)
    raws = sorted((d / "figma").glob("cover-raw-*"), key=lambda p: int(re.search(r"(\d+)", p.stem.split("-")[-1]).group(1)))
    if raws:
        ext = raws[0].suffix or ".png"
        shutil.copy(raws[0], d / "images" / f"cover{ext}")
        order["cover_photo"] = f"cover{ext}"
    else:
        warn.append("figma/cover-raw-1.* 가 없어요 (표지 프레임 download_assets의 rawImages)")
    if (d / "figma" / "cover-export.png").exists():
        shutil.copy(d / "figma" / "cover-export.png", d / "images" / "featured.png")
        order["featured"] = "featured.png"
    else:
        warn.append("figma/cover-export.png 가 없어요 (표지 프레임 download_assets의 export)")

    intro, outro, w = read_caption(d)
    warn += w
    save(d, "cards.json", {"intro": intro, "cards": cards, "outro": outro})
    save(d, "order.json", order)
    # notes.json은 Claude가 채울 칸만 비워서 만든다 (이미 있으면 카드 수가 같을 때 그대로 둔다)
    notes = load(d, "notes.json", {})
    if len(notes.get("cards", [])) != len(cards):
        notes = {"cover_alt": notes.get("cover_alt", ""), "cards": [{"alt": ""} for _ in cards]}
        save(d, "notes.json", notes)
    print(f"카드 {len(cards)}장 · 캡션 앞 {len(intro)}문단 · 끝 {len(outro)}문단")
    print("\n".join("· " + w for w in warn) if warn else "확인할 것 없음")


# ── publish: 사진 올리기 → 다시 build → 비공개 초안 ─────────

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
        # 이미 만든 초안이 있으면 새로 만들지 않고 그 초안을 고친다
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


def main(argv):
    if len(argv) < 2 or argv[0] not in ("fetch", "build", "publish", "verify"):
        sys.exit(__doc__)
    d = order_dir(argv[1])
    if not d.is_dir():
        sys.exit(f"주문 폴더가 없어요: {d}")
    if argv[0] == "fetch":
        fetch(d)
    elif argv[0] == "build":
        build(d)
    elif argv[0] == "publish":
        publish(d)
    else:
        against = argv[argv.index("--against") + 1] if "--against" in argv else None
        verify(d, against)


if __name__ == "__main__":
    main(sys.argv[1:])
