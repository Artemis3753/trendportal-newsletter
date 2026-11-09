#!/usr/bin/env python3
"""Keep site/additional.css in step with the live site's additional CSS.

The card-news routine (cardnews.py css) appends its `/* cardnews order N start/end */`
blocks to the live site only, because it cannot commit to this repo. Pasting the whole
repo file into the site then wipes those blocks (this happened on 10/25: posts 1311,
1345, 1369 and 1398 lost their layouts). Run this before every paste.

  python3 tools/cardnews/css-sync.py           check only; exit code 1 if anything differs
  python3 tools/cardnews/css-sync.py --write   copy live-only / changed routine blocks into the repo file
  python3 tools/cardnews/css-sync.py --copy    --write, then put the repo file on the clipboard (macOS)

It needs no login: the live CSS is read from the public home page.
"""
import re
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPO_CSS = ROOT / "site" / "additional.css"
SITE = "https://trendportal.kr/"

# 셰프 블록 한 덩어리 = 시작 표시 ~ 같은 번호의 끝 표시 (cardnews.py의 css_marks와 같은 모양)
BLOCK = re.compile(r"/\* cardnews order (\w+) start \*/.*?/\* cardnews order \1 end \*/", re.S)


def read_live():
    """공개 홈 페이지에서 라이브 추가 CSS를 꺼낸다.

    추가 CSS는 <style> 안의 맨 끝에 글자 그대로 들어 있다(11/09 저장소 파일과 96,804자 일치 확인).
    저장소 파일의 첫 줄들을 닻으로 삼아 시작 위치를 찾는다. 캐시를 피하려고 주소 뒤에 시각을 붙인다."""
    req = urllib.request.Request(SITE + f"?css-sync={int(time.time())}", headers={"User-Agent": "css-sync"})
    html = urllib.request.urlopen(req, timeout=30).read().decode("utf-8")
    anchor = REPO_CSS.read_text(encoding="utf-8").lstrip()[:120]
    for m in re.finditer(r"<style[^>]*>(.*?)</style>", html, re.S):
        i = m.group(1).find(anchor)
        if i >= 0:
            return m.group(1)[i:]
    return None


def blocks(css):
    return {m.group(1): m.group(0) for m in BLOCK.finditer(css)}


def outside(css):
    """셰프 블록을 뺀 나머지. 공백 차이는 무시한다(cardnews.py의 라이브 대조와 같은 기준)."""
    return "".join(BLOCK.sub("", css).split())


# 같은 <style> 안에서 추가 CSS 바로 뒤에는 워드프레스가 만든 블록 스타일이 이어 붙는다(11/09 확인:
# `:root:where(.wp-block-categories){…`부터). 이건 우리 CSS가 아니므로 비교에서 뺀다.
WP_TAIL = ":root:where("


def stray_diff(repo, live):
    """블록 밖에서 라이브와 저장소가 다르면 (위치, 저장소 쪽, 라이브 쪽), 같으면 None.
    저장소 내용이 라이브 앞부분과 같고 그 뒤가 비었거나 워드프레스 꼬리뿐이면 같은 것으로 본다.
    꼬리 자리에 다른 글자가 있으면(= 라이브 끝에 손으로 덧붙인 CSS) 다른 것으로 본다."""
    r, v = outside(repo), outside(live)
    if v.startswith(r):
        rest = v[len(r):]
        return None if not rest or rest.startswith(WP_TAIL) else first_diff("", rest)
    return first_diff(r, v)


def first_diff(a, b):
    i = next((k for k, (x, y) in enumerate(zip(a, b)) if x != y), min(len(a), len(b)))
    return i, a[max(0, i - 40):i + 40], b[max(0, i - 40):i + 40]


def main():
    args = set(sys.argv[1:])
    write = bool(args & {"--write", "--copy"})
    live = read_live()
    if live is None:
        sys.exit("라이브 CSS 시작 부분을 못 찾았어요(저장소 파일 첫 줄이 라이브와 다름). 블록 대조를 못 해서 멈춰요 — "
                 "라이브 CSS를 직접 받아 저장소와 비교하세요")
    repo = REPO_CSS.read_text(encoding="utf-8")
    lb, rb = blocks(live), blocks(repo)

    live_only = [k for k in lb if k not in rb]
    changed = [k for k in lb if k in rb and "".join(lb[k].split()) != "".join(rb[k].split())]
    repo_only = [k for k in rb if k not in lb]
    stray = stray_diff(repo, live)

    print(f"라이브 셰프 블록 {len(lb)}개 · 저장소 {len(rb)}개")
    if live_only:
        print("· 라이브에만 있음(붙여넣으면 사라질 것):", ", ".join(live_only))
    if changed:
        print("· 내용이 다름(라이브가 더 새것):", ", ".join(changed))
    if repo_only:
        print("· 저장소에만 있음(붙여넣으면 라이브에 되살아남. 셰프가 일부러 뺀 거면 저장소에서도 지우세요):", ", ".join(repo_only))
    if stray:
        _, a, b = stray
        print("· 블록 밖에서도 라이브와 다름(라이브에서 손으로 고쳤거나 아직 저장소에 안 옮긴 수정):")
        print(f"    저장소: …{a}…\n    라이브: …{b}…")
    if not (live_only or changed or repo_only or stray):
        print("차이 없음 — 저장소 파일을 그대로 붙여넣어도 안전해요")
        return 0

    if write and (live_only or changed):
        new = repo
        for k in changed:
            new = new.replace(rb[k], lb[k])  # 셰프 블록은 셰프만 쓰므로 라이브 쪽이 정본
        for k in live_only:
            new = new.rstrip("\n") + "\n\n" + lb[k] + "\n"
        REPO_CSS.write_text(new, encoding="utf-8")
        print(f"저장소 파일에 반영했어요: {', '.join(live_only + changed)} (커밋은 따로)")
        repo = new
        live_only, changed = [], []

    if "--copy" in args:
        # 아직 풀리지 않은 차이가 있으면 복사하지 않는다: 붙여넣는 순간 라이브의 그 부분이 덮이거나 되살아나기 때문
        if repo_only or stray:
            if "--force" not in args:
                print("\n복사하지 않았어요. 위 차이를 먼저 정리하세요(그래도 복사하려면 --force)")
                return 1
        subprocess.run(["pbcopy"], input=repo.encode("utf-8"), check=True)
        print(f"저장소 파일 전체를 클립보드에 복사했어요 ({len(repo):,}자)")
        return 0

    # 반영까지 마쳐서 남은 차이가 없으면 0, 아직 남았거나 점검만 했는데 차이가 있으면 1
    return 1 if (live_only or changed or repo_only or stray) else 0


if __name__ == "__main__":
    sys.exit(main())
