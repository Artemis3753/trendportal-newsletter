#!/usr/bin/env python3
"""트렌드 레이더 — 매일 아침 카드뉴스 거리 모으기.

    python3 tools/radar/radar.py collect            (정보원 새 글 → local/radar/<오늘>/candidates.txt·.json)
    python3 tools/radar/radar.py send [--preview]   (Claude가 쓴 picks.json → 사이트 "트렌드 레이더" 탭 + 알림 메일)

토큰을 아끼는 나눔(10/20 사용자: "토큰 최소화 구조로"):
  · 손 노동(정보원 읽기, 날짜·중복 거르기, 링크 붙이기)은 이 스크립트가 한다
  · Claude는 candidates.txt 한 파일만 읽고, 고른 번호와 한국어 제목·한두 문장만 쓴다 (원문 페이지를 열지 않는다)
  · 링크·원문 제목·날짜는 Claude가 베껴 쓰지 않고 send가 번호로 다시 붙인다

picks.json (Claude가 쓰는 파일):
    {"picks": [{"n": 3, "title": "한국어 제목", "note": "무엇이고, 왜 카드뉴스 거리인지 한두 문장"}]}

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

# 정보원(10/20 실측). 이름은 탭·메일에 그대로 보인다.
# 빠진 곳: exchange4media(Cloudflare가 스크립트를 막음 403), X·틱톡·레딧(로그인·API 필요) → 전체 버전에서
SOURCES = [
    ("Famous Campaigns", "rss", "https://www.famouscampaigns.com/feed/"),  # www 없는 주소는 526
    # heyPOP의 /feed/는 2021년에 멈춘 기본 글 종류라 쓸 수 없고, 실제 기사는 'n' 글 종류
    ("heyPOP", "wpjson", "https://heypop.kr/wp-json/wp/v2/n?per_page=20&_fields=date,link,title,excerpt"),
    ("WLDO", "youtube", "UCijBTYEiKT1OJO54C6PnRqw"),   # youtube.com/@WLDO100
    ("a:mag", "youtube", "UCgrtVQLRR8GIR2D1UEKXPaQ"),  # youtube.com/@aamag
]


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

def read_rss(url):
    root = ET.fromstring(get(url))
    for it in root.iter("item"):
        yield (it.findtext("title"), it.findtext("link"),
               parsedate_to_datetime(it.findtext("pubDate")), it.findtext("description"))


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


READERS = {"rss": read_rss, "wpjson": read_wpjson, "youtube": read_youtube}


def cmd_collect():
    seen = set(wp("seen", base=RADAR_API)["links"])
    cut = datetime.now(KST) - timedelta(days=MAX_AGE_DAYS)
    cands, notes = [], []
    for name, kind, url in SOURCES:
        try:
            got = list(READERS[kind](url))
        except Exception as e:  # 한 곳이 막혀도 나머지로 계속 — 막힌 곳은 후보 파일 맨 위에 적는다
            notes.append(f"{name} 읽기 실패: {type(e).__name__} {str(e)[:80]}")
            continue
        for title, link, when, desc in got:
            if when < cut or link in seen:
                continue
            cands.append({"source": name, "orig": plain(title), "link": link,
                          "date": when.astimezone(KST).date().isoformat(), "text": plain(desc)[:SNIPPET]})
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


def cmd_send(preview=False):
    d = day_dir()
    cands = {c["n"]: c for c in json.loads((d / "candidates.json").read_text(encoding="utf-8"))}
    picks = json.loads((d / "picks.json").read_text(encoding="utf-8"))["picks"]
    items = []
    for p in picks:
        c = cands.get(int(p["n"]))
        if not c:
            sys.exit(f"picks.json의 {p['n']}번은 후보에 없어요")
        items.append({"source": c["source"], "title": p["title"], "note": p["note"],
                      "orig": c["orig"], "link": c["link"], "date": c["date"]})
    res = wp("days", {"date": today().isoformat(), "items": items, "preview": preview,
                      # 고르지 않은 후보도 "봤다"로 남긴다 — 내일 같은 글을 다시 읽지 않게
                      "offered": [c["link"] for c in cands.values()]}, base=RADAR_API)
    print(f"{res['date']} 거리 {res['count']}개 저장, 메일: {res['mail']}")
    if res["mail"] not in ("sent", "duplicate"):
        sys.exit(1)


def main(argv):
    if argv[:1] == ["collect"]:
        cmd_collect()
    elif argv[:1] == ["send"]:
        cmd_send(preview="--preview" in argv)
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main(sys.argv[1:])
