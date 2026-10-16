#!/usr/bin/env python3
"""트란시스 카드뉴스 도우미 — 주방(1단계).

주문 폴더 하나(local/cardnews/<주문>/)를 받아 단계별로 처리한다.

    python3 tools/cardnews/cardnews.py build   <주문>
    python3 tools/cardnews/cardnews.py publish <주문>
    python3 tools/cardnews/cardnews.py verify  <주문> [--against 글번호]

주문 폴더:
    order.json   주문서 (종류, 호수, 표지 사진, 카테고리·태그)
    cards.json   카드 글자·사진 이름 (fetch 단계 결과 — 아직은 손으로/임시 스크립트로 만든다)
    notes.json   Claude가 쓰는 칸만: 카드별 alt·💡
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
import sys
import urllib.error
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
    return html.escape(s, quote=False)


def attr(s):
    return html.escape(s, quote=True)


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


def image_block(media, fn, alt, wide):
    mid, url = media.get(fn)
    # 가로 정지 화면(X·유튜브)은 tp-wk-wide를 붙여야 CSS가 240px로 줄이지 않는다
    extra, cls = (',"className":"tp-wk-wide"', " tp-wk-wide") if wide else ("", "")
    return (f'<!-- wp:image {{"id":{mid},"sizeSlug":"full"{extra}}} --><figure class="wp-block-image size-full{cls}">'
            f'<img src="{url}" alt="{attr(alt)}" class="wp-image-{mid}"/></figure><!-- /wp:image -->')


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
        v = card["video"]
        out += para(f'▶ <a href="{attr(v["url"])}" target="_blank" rel="noreferrer noopener">{esc(v["label"])}</a>', "tp-wk-video")
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
    letter = "".join(para("<br>".join(esc(l) for l in lines)) for lines in cards["letter"])
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


def title_of(order):
    return order.get("title") or f"이번 주 일어난 마케팅 이슈 총정리 ({order['issue']})"


def build(d, quiet=False):
    order, cards, notes = load(d, "order.json"), load(d, "cards.json"), load(d, "notes.json")
    media = Media(load(d, "media.json", {}))
    if order["kind"] != "weekly":
        sys.exit("아직 주간 총정리(weekly) 틀만 있어요")
    out = build_weekly(order, cards, notes, media)
    (d / "post.html").write_text(out, encoding="utf-8")
    if not quiet:
        print(f"post.html {len(out):,}자 · 카드 {len(cards['cards'])}장 · 아직 안 올린 파일 {len(media.missing)}개")
    return out, media.missing


# ── publish: 사진 올리기 → 다시 build → 비공개 초안 ─────────

def upload(d, fn, alt):
    path = d / "images" / fn
    if not path.exists():
        sys.exit(f"{path} 가 없어요")
    ctype = mimetypes.guess_type(fn)[0] or "application/octet-stream"
    m = wp("media", path.read_bytes(), {"Content-Type": ctype, "Content-Disposition": f'attachment; filename="{fn}"'})
    if alt:
        wp(f"media/{m['id']}", {"alt_text": alt})
    return {"id": m["id"], "url": m["source_url"]}


def alts(d):
    # 사진 파일마다 alt: 카드 사진은 notes의 alt, 영상 포스터는 notes의 poster_alt
    cards, notes = load(d, "cards.json"), load(d, "notes.json")
    out = {}
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
    cover = order.get("cover")
    if cover and cover not in table:
        missing.append(cover)
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
    if cover:
        post["featured_media"] = table[cover]["id"]
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
                urllib.request.urlopen(urllib.request.Request(m["url"], method="HEAD"), timeout=30)
            except urllib.error.URLError as e:
                problems.append(f"{fn} 주소가 안 열려요: {e}")
    if against:
        # 시험용: 손으로 만든 글과 똑같이 나오는지
        ref = wp(f"posts/{against}?context=edit")
        if ref["content"]["raw"] != live["content"]["raw"]:
            i, a, b = first_diff(ref["content"]["raw"], live["content"]["raw"])
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
    if len(argv) < 2 or argv[0] not in ("build", "publish", "verify"):
        sys.exit(__doc__)
    d = order_dir(argv[1])
    if not d.is_dir():
        sys.exit(f"주문 폴더가 없어요: {d}")
    if argv[0] == "build":
        build(d)
    elif argv[0] == "publish":
        publish(d)
    else:
        against = argv[argv.index("--against") + 1] if "--against" in argv else None
        verify(d, against)


if __name__ == "__main__":
    main(sys.argv[1:])
