#!/usr/bin/env python3
"""트란시스 특파원(처음 이름 트렌드 레이더) — 탭에서 체크한 글을 카드뉴스 형식의 비공개 임시글(모의 글)로 만들고, 피드 제목을 번역하며 우리 거리가 아닌 글을 접는다.

    python3 tools/radar/radar.py asks               (탭에서 들어온 모의 글 부탁 가져오기 → 글마다 본문을 보여 주고 주문 폴더·사진 준비)
    python3 tools/cardnews/cardnews.py cook local/radar/mock/<부탁id>-<번호>   (셰프의 build → draft → verify → shot을 그대로)
    python3 tools/radar/radar.py answer <id> [--fail 이유]  (만든 임시글 링크·확인할 것 → 탭 모의 글 칸)
    python3 tools/radar/radar.py titles [--save]    (아직 안 훑은 피드 글 / local/radar/titles-ko.json → 탭 피드에 한국어 제목·접기)

1.6.0(10/20 사용자): 매일 아침 리포트(collect·body·send)와 알림 메일은 범위에서 뺐다 — 필요할 때 탭에 들어와 고르는 도구라서.
2.0.0(10/28 사용자): 글자 요약 리포트(picks·points) 대신 카드뉴스 형식의 임시글. 글 만들기·사진 올리기·원문 대조는 셰프 코드(cardnews.py)를
그대로 쓴다 — 새로 짠 건 "기사 → 주문 폴더"뿐이라 사이트 글 형식 규칙(docs/post-formats.md)이 한 곳에만 있다.
정보원 읽기는 사이트(플러그인의 실시간 피드)가 하고, 이 스크립트는 사람이 고른 글의 본문과 대표 사진만 읽는다.

토큰을 아끼는 나눔(10/20 사용자: "토큰 최소화 구조로"):
  · 손 노동(본문 읽기, 사진 받기, 주문 폴더 만들기, 링크·원문 제목·날짜 붙이기)은 이 스크립트가 한다
  · Claude는 본문을 읽고 한국어 제목·카드 글만 쓴다
  · 링크·원문 제목·날짜는 Claude가 베껴 쓰지 않고 answer가 번호로 다시 붙인다

주문 폴더(local/radar/mock/<부탁id>-<번호>/): 셰프의 아카이빙 주문과 같은 모양이다.
    order.json   (이 스크립트가 만듦: kind archive, 사진 이름 / Claude가 title·categories를 채움)
    cards.json   {"intro": [...], "cards": [{"title", "what": [...], "src"}], "outro": [...]}   (Claude)
    notes.json   {"cover_alt", "featured_alt", "cards": [{"alt": ""}, ...]}                      (Claude)
    changes.json ["제목: …", "확인 필요: …"]                                                      (Claude)
    images/cover.<확장자>   (이 스크립트가 기사 대표 사진을 받아 둠)

사이트 인증은 셰프(tools/cardnews/cardnews.py)와 같다: 이 맥은 local/cardnews.env, 클라우드는 환경의 network secret.
표준 라이브러리만 쓴다 — 클라우드 routine에서 설치 없이 돌리려고.
"""
import html
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "cardnews"))
from cardnews import wp  # noqa: E402  재시도·인증을 셰프와 똑같이 쓰려고

ROOT = Path(__file__).resolve().parents[2]
RADAR_API = "https://trendportal.kr/wp-json/tp-radar/v1/"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36"


def get(url):
    # 기사 페이지가 잠깐 흔들리면(연결 끊김·시간 초과) 쉬었다 두 번 더
    for wait in (5, 15, None):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            # 308(영구 이동)은 urllib이 따라가지 않는다 — Daily Dot이 주소 끝 /를 떼려고 308을 준다(10/20)
            if e.code == 308 and e.headers.get("Location"):
                url = urllib.parse.urljoin(url, e.headers["Location"])
                continue
            raise
        except (urllib.error.URLError, TimeoutError):
            if wait is None:
                raise
            time.sleep(wait)


def plain(s):
    # HTML 조각 → 한 줄 글자
    s = re.sub(r"<[^>]+>", " ", s or "")
    return re.sub(r"\s+", " ", html.unescape(s)).strip()


# 기사 하나에서 Claude에게 넘기는 글자 수 상한. 영어 원문은 핵심 정리를 더 풍부하게 쓰니 넉넉히(10/20 사용자),
# 한국어 원문은 독자가 원문을 바로 읽을 수 있어 짧게 — 한국어는 글자당 토큰이 더 들기도 한다
BODY_MAX_EN = 3000
BODY_MAX_KO = 1500
# 기사 뒤에 붙는 구독 안내·출처·사이트 소개 — 여기서부터는 본문이 아니다
BODY_STOP = re.compile(r"Subscribe to|newsletter and get|is a brandzine|자료 및 사진 출처|팝업 큐레이션 플랫폼|© HEYPOP|All rights reserved|Xユーザは、Xの利用規約", re.I)
# 본문 중간에 끼는 안내 문단 — 건너뛰기만 한다(10/20 정보원 15곳 실측: 뉴스레터 광고·행사 광고·옛 브라우저 경고·후원 배너)
BODY_SKIP = re.compile(r"free newsletter|Register now|Internet Explorer|サーバー運営|Sign up for|Meme Of The Month|Get all the best|Hype 계정|HYPE 앱|트렌드를 앞서가고", re.I)
# 진짜 <p> 태그만 — 예전 패턴 <p[^>]*>는 <path>(SVG)·<picture>까지 문단으로 잡아서
# 메뉴·아이콘 코드가 다음 </p>까지 통째로 딸려 왔다(10/20 The Verge·TechCrunch·GIGAZINE에서 발견)
PARA = re.compile(r"<p(?:\s[^>]*)?>(.*?)</p>", re.S)


def body_text(c):
    """기사 하나의 본문(글자 수 상한까지). 핵심 정리(10/20 사용자: 모든 거리에)를 쓸 재료.
    유튜브는 페이지 대신 피드의 영상 설명(text)을 쓴다."""
    cap = BODY_MAX_EN if is_english(c) else BODY_MAX_KO
    if "youtube.com" in c["link"]:
        return "(영상 설명) " + c.get("text", "")[:cap]
    # 구글 뉴스 링크는 원문으로 바로 넘어가지 않고 자바스크립트 화면(약 600KB)이 온다 — 읽어 봐야 쓰레기 글자뿐
    if "news.google.com" in c["link"]:
        return f"(구글 뉴스 링크라 본문을 못 읽어요. 제목·요약만 보고 쓰세요) {c.get('text', '')}"
    try:
        h = get(c["link"])
    except Exception as e:
        return f"(본문을 못 읽었어요 — {type(e).__name__}. 제목·요약만 보고 쓰세요) {c.get('text', '')}"
    out = []
    for p in PARA.findall(h):
        p = plain(p)
        if BODY_STOP.search(p):
            break
        if len(p) > 40 and not BODY_SKIP.search(p):  # 짧은 줄은 메뉴·사진 설명·공유 버튼이라 뺀다
            out.append(p)
    return "\n".join(out)[:cap]


def is_english(c):
    # 원문 제목에 한글이 하나도 없으면 영어 원문으로 본다
    return not re.search(r"[가-힣]", c["orig"])


def og_image(link):
    """원문 페이지의 대표 사진 주소(og:image). 고른 글에만 부르니 Claude 토큰과 무관하다. 못 찾으면 ''.
    구글 뉴스 링크는 원문이 아니라 구글 뉴스 화면이라 대표 사진도 구글 로고뿐이다"""
    if "news.google.com" in link:
        return ""
    try:
        h = get(link)
    except Exception:
        return ""
    m = (re.search(r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)', h)
         or re.search(r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image', h))
    return html.unescape(m.group(1)) if m else ""


def get_bytes(url):
    for wait in (5, None):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read(), r.headers.get("Content-Type", "")
        except (urllib.error.URLError, TimeoutError):
            if wait is None:
                raise
            time.sleep(wait)


def download_photo(link, folder):
    """기사 대표 사진을 folder/images/cover.<확장자>로 받아 파일 이름을 돌려준다. 못 받으면 ''.
    WordPress.com 사진 변환기(Photon)로 폭 1400px로 줄여 받는다 — 원본이 3000px·800KB인 곳(heyPOP)이 있어서.
    유튜브 썸네일은 Photon이 받지 않아서(302) 그대로 받는다"""
    url = og_image(link)
    if not url:
        return ""
    if "ytimg.com" not in url:
        url = "https://i0.wp.com/" + re.sub(r"^https?://", "", url).split("?")[0] + "?w=1400&ssl=1"
    try:
        data, ctype = get_bytes(url)
    except Exception:
        return ""
    ext = {"image/jpeg": "jpg", "image/png": "png", "image/webp": "webp"}.get(ctype.split(";")[0].strip())
    if not ext or len(data) < 2000:  # 이미지가 아니거나(오류 화면) 너무 작으면(추적 픽셀) 쓰지 않는다
        return ""
    (folder / "images").mkdir(parents=True, exist_ok=True)
    name = f"cover.{ext}"
    (folder / "images" / name).write_bytes(data)
    return name


# ── 모의 글 부탁: 탭의 실시간 피드에서 사람이 고른 글을 카드뉴스 형식의 임시글로(10/28 사용자) ──────────

def asks_dir():
    d = ROOT / "local" / "radar" / "asks"
    d.mkdir(parents=True, exist_ok=True)
    return d


def mock_dir(aid, n):
    return ROOT / "local" / "radar" / "mock" / f"{aid}-{n}"


def cmd_asks():
    """기다리는 모의 글 부탁을 가져오고(사이트에서 '만드는 중'으로 바뀐다) 글마다 본문을 보여 준다.
    글마다 주문 폴더와 대표 사진도 미리 만들어 둔다. 고르는 일은 없다 — 사람이 이미 골랐으니 전부 만든다."""
    asks = wp("asks", base=RADAR_API)
    if not asks:
        print("기다리는 모의 글 부탁이 없어요")
        return
    for a in asks:
        (asks_dir() / f"{a['id']}.json").write_text(json.dumps(a, ensure_ascii=False), encoding="utf-8")
        print(f"# 모의 글 부탁 {a['id']} — {len(a['items'])}개 전부\n")
        for n, c in enumerate(a["items"], 1):
            d = mock_dir(a["id"], n)
            d.mkdir(parents=True, exist_ok=True)
            photo = download_photo(c["link"], d)
            order = {"kind": "archive", "title": "", "categories": [], "tags": []}
            if photo:
                order.update(cover_photo=photo, featured=photo)  # 기사 사진이 한 장뿐이라 표지·대표가 같은 사진이다
            (d / "order.json").write_text(json.dumps(order, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            print(f"## {n} · {c['source']} · {c['orig']}\n주문 폴더: {d.relative_to(ROOT)} · "
                  + (f"대표 사진: images/{photo}" if photo else "대표 사진을 못 받았어요(사진 없는 글로 만들어요)")
                  + "\n" + body_text(c) + "\n")


def read_json(p, default):
    return json.loads(p.read_text(encoding="utf-8")) if p.exists() else default


def cmd_answer(aid, fail=None):
    """만든 임시글(또는 실패 이유)을 사이트로. 결과는 탭의 모의 글 칸에 링크로 쌓인다.
    권리 확인 줄은 Claude에게 맡기지 않고 여기서 붙인다 — 남의 기사 사진을 쓰는 글이라 빠지면 안 되어서."""
    if fail:
        wp(f"asks/{aid}", {"error": fail}, base=RADAR_API)
        print(f"모의 글 부탁 {aid}: 실패로 알렸어요")
        return
    a = json.loads((asks_dir() / f"{aid}.json").read_text(encoding="utf-8"))
    items = []
    for n, c in enumerate(a["items"], 1):
        d = mock_dir(aid, n)
        base = {"source": c["source"], "orig": c["orig"], "link": c["link"], "date": c["date"]}
        result = read_json(d / "result.json", {})
        order = read_json(d / "order.json", {})
        if not result.get("post_id"):
            items.append({**base, "error": "임시글을 만들지 못했어요. 특파원 다시 부르기를 눌러 보세요"})
            continue
        changes = list(read_json(d / "changes.json", []))
        must = ([f"꼭 확인: 표지 사진은 {c['source']} 기사의 사진이에요. 발행 전에 사용 권리를 확인하거나 직접 구한 사진으로 바꿔 주세요"]
                if order.get("cover_photo") else [])
        info = ["확인 필요: 인스타 카드뉴스 없이 기사에서 만든 모의 글이에요. 발행해도 되는 글인지 한 번 봐 주세요"]
        items.append({**base, "title": order.get("title", ""), "post_id": result["post_id"], "preview": result["preview"],
                      "edit": result["edit"], "changes": must + info + changes})
    res = wp(f"asks/{aid}", {"items": items}, base=RADAR_API)
    made = sum(1 for i in items if i.get("post_id"))
    print(f"모의 글 부탁 {aid}: 임시글 {made}개, 못 만든 글 {len(items) - made}개 → {res['status']}")


# ── 번역·거르기: 피드의 외국어 제목을 우리 말투로 + 우리 거리 아닌 글 접기(10/20 사용자) ──────────

def cmd_titles(save=False):
    """아직 안 훑은 피드 글을 번호 붙여 보여 주거나(save=False), Claude가 쓴 titles-ko.json을 사이트에 저장한다.
    링크는 Claude가 베껴 쓰지 않는다 — 번호로 다시 붙인다(answer와 같은 원칙).
    titles-ko.json: {"titles": {"번호": "한국어 제목"}, "hide": [접을 번호, ...], "warm": [사람 냄새 번호, ...]}"""
    f = ROOT / "local" / "radar" / "titles.json"
    if save:
        todo = json.loads(f.read_text(encoding="utf-8"))
        out = json.loads((f.parent / "titles-ko.json").read_text(encoding="utf-8"))
        link = lambda n: todo[int(n) - 1]["link"]  # noqa: E731
        res = wp("titles", {"titles": {link(n): s for n, s in out.get("titles", {}).items()},
                            "hide": [link(n) for n in out.get("hide", [])],
                            "warm": [link(n) for n in out.get("warm", [])],
                            "judged": [c["link"] for c in todo]}, base=RADAR_API)
        print(f"{res['saved']}개를 훑어 저장했어요 (접은 글 {res['folded']}개, 사람 냄새 {res['warm']}개)")
        return
    todo = wp("titles", base=RADAR_API)
    f.parent.mkdir(parents=True, exist_ok=True)
    f.write_text(json.dumps(todo, ensure_ascii=False), encoding="utf-8")
    if not todo:
        print("훑을 글이 없어요")
        return
    print(f"# 훑을 글 {len(todo)}개 — 번호 | 정보원 | 원문 제목 — 요약 앞부분")
    print("# [번역] = 한국어 제목이 필요한 외국어 글, [번역됨] = 제목은 이미 있음(접을지만 판단)")
    for n, c in enumerate(todo, 1):
        tag = "" if not c["lang"] else ("[번역됨] " if c["ko"] else "[번역] ")
        print(f"{n} | {c['source']} | {tag}{c['orig']} — {c['text'][:80]}")


def main(argv):
    if argv[:1] == ["titles"]:
        cmd_titles(save="--save" in argv)
    elif argv[:1] == ["asks"]:
        cmd_asks()
    elif argv[:1] == ["answer"] and argv[1:]:
        cmd_answer(argv[1], fail=argv[3] if argv[2:3] == ["--fail"] and argv[3:] else None)
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main(sys.argv[1:])
