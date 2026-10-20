#!/usr/bin/env python3
"""트란시스 특파원(처음 이름 트렌드 레이더) — 매일 아침 카드뉴스 거리 모으기.

    python3 tools/radar/radar.py collect            (정보원 새 글 → local/radar/<오늘>/candidates.txt·.json)
    python3 tools/radar/radar.py body <번호> ...     (고른 거리의 본문 — 핵심 정리 재료)
    python3 tools/radar/radar.py send [--preview]   (Claude가 쓴 picks.json → 사이트 "트란시스 특파원" 탭 + 알림 메일)
    python3 tools/radar/radar.py asks               (탭에서 들어온 정리 부탁 가져오기 + 글마다 본문)
    python3 tools/radar/radar.py answer <id> [--fail 이유]  (local/radar/asks/<id>-picks.json → 탭 리포트 칸)

토큰을 아끼는 나눔(10/20 사용자: "토큰 최소화 구조로"):
  · 손 노동(정보원 읽기, 날짜·중복 거르기, 링크 붙이기)은 이 스크립트가 한다
  · Claude는 candidates.txt 한 파일만 읽고, 고른 번호와 한국어 제목·한두 문장만 쓴다 (원문 페이지를 열지 않는다)
  · 링크·원문 제목·날짜는 Claude가 베껴 쓰지 않고 send가 번호로 다시 붙인다

picks.json (Claude가 쓰는 파일):
    {"picks": [{"n": 3, "title": "한국어 제목", "note": "무엇이고, 왜 카드뉴스 거리인지 한두 문장",
                "points": ["본문을 우리 카드 말투로 녹인 핵심 정리 — 한국어 원문 3~4줄, 영어 원문 5~7줄"]}]}

사이트 인증은 셰프(tools/cardnews/cardnews.py)와 같다: 이 맥은 local/cardnews.env, 클라우드는 환경의 network secret.
표준 라이브러리만 쓴다 — 클라우드 routine에서 설치 없이 돌리려고.
"""
import html
import json
import re
import sys
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "cardnews"))
from cardnews import wp  # noqa: E402  재시도·인증을 셰프와 똑같이 쓰려고

ROOT = Path(__file__).resolve().parents[2]
RADAR_API = "https://trendportal.kr/wp-json/tp-radar/v1/"
KST = timezone(timedelta(hours=9))
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36"

MAX_AGE_DAYS = 4   # 이보다 오래된 글은 후보에서 뺀다 (주말을 끼어도 놓치지 않을 만큼)
SNIPPET = 140      # 후보 한 줄에 붙이는 요약 글자 수 — Claude가 읽는 양을 정하는 값
PER_SOURCE = 5     # 정보원마다 최신 몇 개까지만 — 하루 30개씩 내는 곳(GIGAZINE·Mashable)이 후보를 덮지 않게(10/20 사용자)

# 정보원 표는 플러그인(plugins/tp-trend-radar, TP_RADAR_SOURCES) 한 곳에만 있다 — 탭의 실시간 피드와 같은 표를
# 쓰려고(10/20). collect가 REST /sources로 받아 온다: [{name, kind, url, skip}]
# 고른 근거는 플러그인 주석과 정보원 보고서에.


def today():
    return datetime.now(KST).date()


def day_dir():
    d = ROOT / "local" / "radar" / today().isoformat()
    d.mkdir(parents=True, exist_ok=True)
    return d


def get(url):
    # 유튜브 RSS는 방금 되던 주소가 가끔 404를 낸다(10/20 실측: WLDO가 몇 분 사이에 200 → 404) → 쉬었다 두 번 더
    for wait in (5, 15, None):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read().decode("utf-8", "replace")
        except (urllib.error.URLError, TimeoutError):
            if wait is None:
                raise
            time.sleep(wait)


def plain(s):
    # HTML 조각 → 한 줄 글자
    s = re.sub(r"<[^>]+>", " ", s or "")
    return re.sub(r"\s+", " ", html.unescape(s)).strip()


# ── 정보원별 읽기: 모두 (제목, 링크, 날짜, 요약) 목록으로 ──────────

ATOM = {"a": "http://www.w3.org/2005/Atom"}


def when(s):
    """날짜 글자 → 시각. 정보원마다 표기가 다르다(10/20 실측):
    RSS 표준 'Mon, 19 Oct 2026 18:37:45 -0400', Atom '2026-10-19T14:12:45-04:00',
    매드타임스 '2026-10-20 09:00:00'(시간대 없음 → 한국 매체라 KST로 본다)"""
    s = (s or "").strip()
    try:
        return parsedate_to_datetime(s)
    except (TypeError, ValueError):
        d = datetime.fromisoformat(s.replace("Z", "+00:00"))
        return d if d.tzinfo else d.replace(tzinfo=KST)


def read_rss(url):
    # RSS 2.0과 Atom(The Verge)을 같이 읽는다 — 정보원마다 리더를 따로 두지 않으려고
    root = ET.fromstring(get(url))
    for it in root.iter("item"):
        yield (it.findtext("title"), it.findtext("link"), when(it.findtext("pubDate")), it.findtext("description"))
    for e in root.findall("a:entry", ATOM):
        yield (e.findtext("a:title", namespaces=ATOM), e.find("a:link", ATOM).get("href"),
               when(e.findtext("a:published", namespaces=ATOM) or e.findtext("a:updated", namespaces=ATOM)),
               e.findtext("a:summary", namespaces=ATOM) or e.findtext("a:content", namespaces=ATOM))


def read_reddit(url):
    """r/InternetIsBeautiful: 글 하나 = 사이트 하나. 레딧 글 주소 대신 소개된 사이트 주소를 링크로 쓴다
    — 거리는 그 사이트이고, 레딧 페이지는 본문·사진을 읽으려 하면 자주 막힌다."""
    for title, link, t, content in read_rss(url):
        m = re.search(r'<a href="([^"]+)">\[link\]</a>', content or "")
        yield (title, html.unescape(m.group(1)) if m else link, t, "")


def read_gtrends(url):
    """Google 트렌드 한국 급상승 검색어: 검색어 하나에 관련 기사 몇 개가 붙은 모양(10/20 실측).
    제목 = 검색어와 검색량, 링크 = 첫 기사, 요약 = 기사 제목들 → 왜 뜨는지가 한 줄에 보인다."""
    ns = {"ht": "https://trends.google.com/trending/rss"}
    for it in ET.fromstring(get(url)).iter("item"):
        news = it.findall("ht:news_item", ns)
        if not news:
            continue
        heads = " / ".join(n.findtext("ht:news_item_title", namespaces=ns) or "" for n in news)
        yield (f"[검색 급상승 {it.findtext('ht:approx_traffic', namespaces=ns)}] {it.findtext('title')}",
               news[0].findtext("ht:news_item_url", namespaces=ns), when(it.findtext("pubDate")), heads)


def read_wpjson(url):
    raw = get(url)
    # heyPOP은 JSON 앞에 <style> 덩어리를 붙여서 내보낸다(테마 버그) → 글 목록이 시작하는 '[{'부터 읽는다
    # ('['만 찾으면 CSS의 input[readonly]에 걸린다)
    for p in json.loads(raw[raw.index("[{"):]):
        yield (p["title"]["rendered"], p["link"],
               datetime.fromisoformat(p["date"]).replace(tzinfo=KST), p.get("excerpt", {}).get("rendered"))


def read_youtube(channel):
    ns = {"a": "http://www.w3.org/2005/Atom", "media": "http://search.yahoo.com/mrss/"}
    root = ET.fromstring(get("https://www.youtube.com/feeds/videos.xml?channel_id=" + channel))
    for e in root.findall("a:entry", ns):
        yield (e.findtext("a:title", namespaces=ns), e.find("a:link", ns).get("href"),
               datetime.fromisoformat(e.findtext("a:published", namespaces=ns)),
               e.findtext("media:group/media:description", namespaces=ns))


READERS = {"rss": read_rss, "reddit": read_reddit, "gtrends": read_gtrends,
           "wpjson": read_wpjson, "youtube": read_youtube}


MORNING = 8  # 이 시(KST) 전에는 아침 리포트를 만들지 않는다 — 정리 부탁으로 새벽에 깨어나도 9시 리포트를 앞당기지 않게


def cmd_collect():
    s = wp("seen", base=RADAR_API)
    # 정리 부탁(초인종)으로 낮에 깨어난 날에도 아침 리포트를 또 만들지 않게(10/20)
    if today().isoformat() in s.get("reported", []):
        print("오늘 아침 리포트는 이미 보냈어요 — 할 일 끝")
        return
    if datetime.now(KST).hour < MORNING:
        print("아직 아침 리포트 시간 전이에요 — 할 일 끝")
        return
    seen = set(s["links"])
    cut = datetime.now(KST) - timedelta(days=MAX_AGE_DAYS)
    cands, notes = [], []
    for src in wp("sources", base=RADAR_API):
        name, kind = src["name"], src["kind"]
        try:
            got = list(READERS[kind](src["url"]))
        except Exception as e:  # 한 곳이 막혀도 나머지로 계속 — 막힌 곳은 후보 파일 맨 위에 적는다
            notes.append(f"{name} 읽기 실패: {type(e).__name__} {str(e)[:80]}")
            continue
        skip = re.compile(src["skip"], re.I) if src.get("skip") else None
        fresh = [g for g in got if g[2] >= cut and g[1] not in seen and not (skip and skip.search(g[0] or ""))]
        fresh.sort(key=lambda g: g[2], reverse=True)
        for title, link, t, desc in fresh[:PER_SOURCE]:
            cands.append({"source": name, "orig": plain(title), "link": link,
                          "date": t.astimezone(KST).date().isoformat(), "text": plain(desc)[:SNIPPET],
                          # 유튜브 영상 설명 전체는 body가 핵심 정리 재료로 쓴다(candidates.txt엔 안 들어가 토큰과 무관)
                          "desc": plain(desc) if kind == "youtube" else ""})
    d = day_dir()
    for i, c in enumerate(cands, 1):
        c["n"] = i
    (d / "candidates.json").write_text(json.dumps(cands, ensure_ascii=False, indent=1), encoding="utf-8")
    # Claude가 읽는 파일: JSON 대신 한 줄에 하나 (괄호·따옴표·키 이름만큼 토큰이 준다)
    lines = [f"# {today().isoformat()} 새 후보 {len(cands)}개 — 번호 | 정보원 | 날짜 | 원문 제목 — 요약"]
    lines += [f"# {n}" for n in notes]
    lines += [f"{c['n']} | {c['source']} | {c['date'][5:]} | {c['orig']} — {c['text']}" for c in cands]
    (d / "candidates.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"후보 {len(cands)}개 → {d / 'candidates.txt'}" + ("".join("\n" + n for n in notes)))
    if not cands:
        print("새 후보가 없어요 — 오늘은 보낼 거리가 없어요")


# 기사 하나에서 Claude에게 넘기는 글자 수 상한. 영어 원문은 핵심 정리를 더 풍부하게 쓰니 넉넉히(10/20 사용자),
# 한국어 원문은 독자가 원문을 바로 읽을 수 있어 짧게 — 한국어는 글자당 토큰이 더 들기도 한다
BODY_MAX_EN = 3000
BODY_MAX_KO = 1500
# 기사 뒤에 붙는 구독 안내·출처·사이트 소개 — 여기서부터는 본문이 아니다
BODY_STOP = re.compile(r"Subscribe to|newsletter and get|is a brandzine|자료 및 사진 출처|팝업 큐레이션 플랫폼|© HEYPOP", re.I)
# 본문 중간에 끼는 안내 문단 — 건너뛰기만 한다(10/20 정보원 15곳 실측: 뉴스레터 광고·행사 광고·옛 브라우저 경고·후원 배너)
BODY_SKIP = re.compile(r"free newsletter|Register now|Internet Explorer|サーバー運営|Sign up for|Meme Of The Month|Get all the best", re.I)
# 진짜 <p> 태그만 — 예전 패턴 <p[^>]*>는 <path>(SVG)·<picture>까지 문단으로 잡아서
# 메뉴·아이콘 코드가 다음 </p>까지 통째로 딸려 왔다(10/20 The Verge·TechCrunch·GIGAZINE에서 발견)
PARA = re.compile(r"<p(?:\s[^>]*)?>(.*?)</p>", re.S)


def body_text(c):
    """기사 하나의 본문(글자 수 상한까지). 핵심 정리(10/20 사용자: 모든 거리에)를 쓸 재료.
    유튜브는 페이지 대신 RSS의 영상 설명(collect가 남겨 둔 desc, 피드에서 온 정리 부탁은 text)을 쓴다."""
    cap = BODY_MAX_EN if is_english(c) else BODY_MAX_KO
    if "youtube.com" in c["link"]:
        return "(영상 설명) " + (c.get("desc") or c.get("text", ""))[:cap]
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


def cmd_body(nums):
    """고른 거리의 본문을 꺼내 보여 준다. 고른 5개 안팎에만 부르고 글자 수 상한이 있어서 Claude가 읽는 양이 정해져 있다."""
    cands = {c["n"]: c for c in json.loads((day_dir() / "candidates.json").read_text(encoding="utf-8"))}
    for n in nums:
        c = cands[int(n)]
        print(f"## {n} · {c['orig']}\n" + body_text(c) + "\n")


def is_english(c):
    # 원문 제목에 한글이 하나도 없으면 영어 원문으로 본다
    return not re.search(r"[가-힣]", c["orig"])


def image_of(link):
    """원문 페이지의 대표 사진(og:image). 고른 거리에만 부르니 Claude 토큰과 무관하다.
    메일에 바로 넣으면 heyPOP은 3000px·800KB에 webp까지 있어서, WordPress.com 사진 변환기(Photon)로 560×234 jpg로 줄인다.
    납작하게 자르는 이유(10/20 사용자: 사진은 작게, 글을 더): 메일(Gmail)은 CSS 자르기를 지워서 사진 자체를 잘라 와야 한다.
    유튜브 썸네일은 Photon이 받지 않아서(302) 그대로 쓴다 — 메일에서만 16:9로 조금 크게 나온다."""
    try:
        h = get(link)
    except Exception:
        return ""
    m = (re.search(r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)', h)
         or re.search(r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image', h))
    if not m:
        return ""
    url = html.unescape(m.group(1))
    if "ytimg.com" in url:
        return url
    return "https://i0.wp.com/" + re.sub(r"^https?://", "", url).split("?")[0] + "?resize=560,234&ssl=1"


def make_item(c, p):
    """후보(링크·원문 제목·날짜는 스크립트가 받아 둔 것) + Claude가 쓴 pick(제목·설명·핵심 정리) → 사이트에 보낼 거리 하나"""
    points = [str(x) for x in p.get("points", [])][:5]
    if not points:
        sys.exit(f"{p['n']}번에 points(핵심 정리)가 없어요")
    return {"source": c["source"], "title": p["title"], "note": p["note"], "points": points,
            "orig": c["orig"], "link": c["link"], "date": c["date"], "image": image_of(c["link"])}


def cmd_send(preview=False):
    d = day_dir()
    cands = {c["n"]: c for c in json.loads((d / "candidates.json").read_text(encoding="utf-8"))}
    picks = json.loads((d / "picks.json").read_text(encoding="utf-8"))["picks"]
    items = []
    for p in picks:
        c = cands.get(int(p["n"]))
        if not c:
            sys.exit(f"picks.json의 {p['n']}번은 후보에 없어요")
        items.append(make_item(c, p))
    res = wp("days", {"date": today().isoformat(), "items": items, "preview": preview,
                      # 고르지 않은 후보도 "봤다"로 남긴다 — 내일 같은 글을 다시 읽지 않게
                      "offered": [c["link"] for c in cands.values()]}, base=RADAR_API)
    print(f"{res['date']} 거리 {res['count']}개 저장, 메일: {res['mail']}")
    if res["mail"] not in ("sent", "duplicate"):
        sys.exit(1)


# ── 정리 부탁: 탭의 실시간 피드에서 사람이 고른 글만 정리(10/20 사용자) ──────────

def asks_dir():
    d = ROOT / "local" / "radar" / "asks"
    d.mkdir(parents=True, exist_ok=True)
    return d


def cmd_asks():
    """기다리는 정리 부탁을 가져오고(사이트에서 '정리 중'으로 바뀐다) 글마다 본문을 보여 준다.
    고르는 일은 없다 — 사람이 이미 골랐으니 전부 정리한다."""
    asks = wp("asks", base=RADAR_API)
    if not asks:
        print("기다리는 정리 부탁이 없어요")
        return
    for a in asks:
        (asks_dir() / f"{a['id']}.json").write_text(json.dumps(a, ensure_ascii=False), encoding="utf-8")
        print(f"# 정리 부탁 {a['id']} — {len(a['items'])}개 전부 → local/radar/asks/{a['id']}-picks.json\n")
        for n, c in enumerate(a["items"], 1):
            print(f"## {n} · {c['source']} · {c['orig']}\n" + body_text(c) + "\n")


def cmd_answer(aid, fail=None):
    """정리 결과(또는 실패 이유)를 사이트로. 결과는 탭의 리포트 칸에 '정리 부탁'으로 쌓인다(메일 없음)."""
    if fail:
        wp(f"asks/{aid}", {"error": fail}, base=RADAR_API)
        print(f"정리 부탁 {aid}: 실패로 알렸어요")
        return
    a = json.loads((asks_dir() / f"{aid}.json").read_text(encoding="utf-8"))
    picks = json.loads((asks_dir() / f"{aid}-picks.json").read_text(encoding="utf-8"))["picks"]
    items = [make_item(a["items"][int(p["n"]) - 1], p) for p in picks]
    res = wp(f"asks/{aid}", {"items": items}, base=RADAR_API)
    print(f"정리 부탁 {aid}: {len(items)}개 → {res['status']}")


def main(argv):
    if argv[:1] == ["collect"]:
        cmd_collect()
    elif argv[:1] == ["body"] and argv[1:]:
        cmd_body(argv[1:])
    elif argv[:1] == ["send"]:
        cmd_send(preview="--preview" in argv)
    elif argv[:1] == ["asks"]:
        cmd_asks()
    elif argv[:1] == ["answer"] and argv[1:]:
        cmd_answer(argv[1], fail=argv[3] if argv[2:3] == ["--fail"] and argv[3:] else None)
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main(sys.argv[1:])
