"""트란시스: 매일 아침 일정 시트와 리마인더 메일을 맞추는 정해진 계산 부분.

메일을 읽고 일정을 뽑는 일(판단이 필요한 일)은 트란시스(Claude)가 하고,
이 스크립트는 그 결과를 받아 시트에 보내고, D-day로 고르고, 메일 HTML을 만든다.

    python3 transis/transis.py state                 # 이번에 읽을 범위(FULL/DAILY) + 이번 묶음 스레드 + 시트의 지금 일정 (금액·입금자명은 뺌)
    python3 transis/transis.py queue ids.txt         # FULL 시작: 읽을 스레드 id 목록(한 줄에 하나)을 시트에 저장
    python3 transis/transis.py run extracted.json    # 시트에 보내기 → 다시 읽기 → 고르기 → /tmp/transis/에 메일 파일

환경 변수 TRANSIS_URL(웹앱 주소), TRANSIS_TOKEN(비밀 열쇠)이 필요하다. 둘 다 출력하지 않는다.
표준 라이브러리만 쓴다(클라우드 환경에 따로 설치할 게 없게).
"""

import datetime as dt
import html
import json
import os
import re
import sys
import urllib.parse
import urllib.request

OUT = '/tmp/transis'
SEOUL = dt.timezone(dt.timedelta(hours=9))
DOW = '월화수목금토일'  # date.weekday(): 월=0
NAME = {'upload': '업로드', 'draft': '초안·기획안', 'shoot': '방문 촬영', 'pay': '정산', 'memo': '메모'}
# 성현 님이 정한 미리 알림: 업로드 하루 전, 초안·기획안 3일 전, 방문 촬영 3일 전
# 정산은 처음엔 매일이었는데 먼 입금 예정까지 쌓여 오늘 할 일이 묻혀서(10/14) 3일 전부터, 지나면 입금 확인될 때까지 매일
# 메모(성현 님이 일정표에서 직접 적은 할 일)는 날짜가 있으면 하루 전부터, 없으면 완료할 때까지 매일
LEAD = {'upload': 1, 'draft': 3, 'shoot': 3, 'pay': 3, 'memo': 1}
# 전체 훑기(FULL)를 한 지 이만큼 지나면 다시 전체를 훑는다. 매일은 최근 메일만 읽으니, 한 달에 한 번 빠진 게 없는지 다시 확인
FULL_EVERY_DAYS = 30
# 전체 훑기는 한 번 실행에 이만큼씩만 읽는다. 400개를 한 번에 읽으려다 중간에 멈춘 적이 있어서(10/14) 나눠 읽고 이어 간다
BATCH = 60
# 메일엔 그림을 넣지 않는다. 보내는 Gmail 도구 send_message가 <img> 태그를 통째로 지워서(10/14 보낸 원문으로 확인)
# 주소를 바꾸든 cid 첨부든 안 뜬다(create_draft로 만든 초안도 그림이 안 떴다). 같은 이유로 배경은 background: 줄임 표기 대신 background-color:로 쓴다(줄임 표기는 지워짐)
COLOR = {  # 일정표와 같은 색 (진한색, 옅은 바탕)
    'upload': ('#1fa971', '#d9f3e7'),
    'draft': ('#ec4a5a', '#ffe2e5'),
    'shoot': ('#3a86ff', '#dde9ff'),
    'pay': ('#c49a1c', '#f6edd0'),
    'memo': ('#d63384', '#fbe0ee'),
}


def env(name):
    v = os.environ.get(name, '')
    if not v:
        sys.exit(f'{name} is not set')
    return v


def api_get():
    q = urllib.parse.urlencode({'api': 'items', 'token': env('TRANSIS_TOKEN')})
    with urllib.request.urlopen(env('TRANSIS_URL') + '?' + q, timeout=60) as r:
        res = json.loads(r.read())
    if not res.get('ok'):
        sys.exit('sheet read failed: ' + str(res.get('error')))
    return res


def api_post(body):
    body = dict(body, token=env('TRANSIS_TOKEN'))
    req = urllib.request.Request(env('TRANSIS_URL'), data=json.dumps(body).encode(),
                                 headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())


def today():
    return dt.datetime.now(SEOUL).date()


def d_of(s):
    return dt.date.fromisoformat(s)


def md(day):
    return f'{day.month}월 {day.day}일 ({DOW[day.weekday()]})'


def is_due(it, t):
    """오늘 메일의 체크리스트에 들어가는지"""
    if it['done']:
        return False
    if not it.get('date'):
        return it['type'] == 'memo'  # 날짜 없는 메모는 매일. 일정 미정은 '날짜를 확인해 주세요' 칸에 따로
    n = (d_of(it['date']) - t).days
    if n < 0:
        return True  # 지난 일은 끝낼 때까지(정산은 입금 확인될 때까지) 매일
    return n <= LEAD[it['type']]


def sort_key(it):
    # 날짜 없는 메모는 맨 위
    return (it.get('date') or '0000', it.get('time') or '99', it['no'])


# 메일은 배경색에 기대지 않는다: Gmail에서 배경·그림·버튼이 빠져 흰 바탕에 흰 글씨가 된 적이 있어서(10/14)
# 표시는 색 글씨·테두리로만 하고, 배경은 있으면 좋은 꾸밈으로만 쓴다
# '미정': 시간 미정은 주황 글씨, 일정(날짜) 미정은 보라 글씨 (일정표 형광펜의 노랑·라벤더 계열)
TBD = '시간 <span style="color:#c27c0e;font-weight:700;">미정</span>'
TBD_DATE = '일정 <span style="color:#6b4fd8;font-weight:700;">미정</span>'
# 확답 전: 날짜는 제안됐지만 아직 확정 전 (일정표의 라벤더 '확답 전'과 같은 뜻)
TENTATIVE = '<span style="color:#6b4fd8;font-weight:700;white-space:nowrap;">· 확답 전</span>'


def e(s):
    return html.escape(str(s or ''))


# ---------- 메일 HTML (Gmail은 <style>을 제대로 안 읽어서 전부 인라인 스타일, 표 구조) ----------

def title_html(it):
    """제목 맨 앞에 브랜드를 종류 색으로 (일정표와 같은 모양). 제목이 이미 브랜드로 시작하면 한 번만"""
    b = it.get('brand') if it['type'] != 'memo' else ''
    t = str(it.get('title') or '')
    if not b:
        return e(t)
    rest = t[len(b):].strip() if t.startswith(b) else t
    return f'<span style="color:{COLOR[it["type"]][0]};">{e(b)}</span> {e(rest)}'


# 메일은 '무엇을 해야 하나'로 묶는다. 일정표가 날짜로 묶으니, 메일은 같은 일을 다른 각도로 보여 준다(10/14)
# (종류, 칸 제목, 맨 위 요약에 쓰는 이름, 단위)
GROUPS = [
    ('memo', '📝 적어 둔 일', '메모', '건'),
    ('shoot', '📍 가야 할 곳', '촬영', '곳'),
    ('draft', '✉️ 보내야 할 것', '초안', '건'),
    ('upload', '⬆️ 올려야 할 것', '업로드', '건'),
    ('pay', '💰 들어올 돈', '입금', '건'),
]
LINE = '#eef0f4'


def days_left(it, t):
    return (d_of(it['date']) - t).days if it.get('date') else None


def rel_day(it, t):
    """오늘·내일·모레는 말로, 그 뒤는 날짜로. 아침에 훑어볼 때 D-숫자보다 빨리 읽혀서"""
    n = days_left(it, t)
    if n is None:
        return '언제든' if it['type'] == 'memo' else TBD_DATE
    if n < 0:
        return f'{-n}일 지남'
    d = d_of(it['date'])
    return {0: '오늘', 1: '내일', 2: '모레'}.get(n) or f'{d.month}/{d.day} ({DOW[d.weekday()]})'


def sub_line(it, t):
    """날짜 칸 아래 작은 글씨: 지난 일은 원래 날짜, 정산은 '입금 예정', 나머지는 시각"""
    n = days_left(it, t)
    if n is None:
        return ''
    d = d_of(it['date'])
    if n < 0:
        return f'{d.month}/{d.day} ({DOW[d.weekday()]})' + (' 예정' if it['type'] == 'pay' else '')
    if it['type'] == 'pay':
        return '입금 예정'
    if it['type'] == 'memo':
        return it.get('time') or ''
    return e(it['time']) if it.get('time') else TBD


def row(it, t, first, detail=True, when=True, off=False):
    """when=False: 날짜 칸 없이 (날짜를 확인해 주세요 칸은 전부 '일정 미정'이라 날짜 칸이 매 줄 같은 말이 돼서)
    off=True: 취소돼서 뺀 일. 일정표 완료 칸처럼 제목에 줄을 긋고 회색으로, 날짜 칸은 원래 날짜 + 빨간 '✕ 취소'"""
    late = (days_left(it, t) or 0) < 0 and not off
    line = '' if first else f'border-top:1px solid {LINE};'
    tent = f' {TENTATIVE}' if it.get('tentative') and not it.get('done') and not off else ''
    extra = ''
    if detail and it['type'] == 'pay' and (it.get('payer') or it.get('amount')):
        bits = []
        if it.get('payer'):
            bits.append(f"입금자명 <b>{e(it['payer'])}</b>")
        if it.get('amount'):
            bits.append(f"금액 <b>{e(it['amount'])}</b>")
        extra += (f'<div style="font-size:12.5px;margin-top:4px;background-color:{COLOR["pay"][1]};'
                  f'border-left:3px solid {COLOR["pay"][0]};padding:4px 8px;">{" · ".join(bits)}</div>')
    if detail and it.get('source'):
        extra += f'<div style="font-size:11.5px;color:#a0a3b1;margin-top:3px;">원본 메일: “{e(it["source"])}”</div>'
    sub = sub_line(it, t)
    date_td = ''
    if when and off:
        d = d_of(it['date']) if it.get('date') else None
        label = f'{d.month}/{d.day} ({DOW[d.weekday()]})' if d else '일정 미정'
        sub = '<span style="color:#e5484d;font-weight:700;">✕ 취소</span>'
    elif when:
        label = rel_day(it, t)
        if days_left(it, t) == 0:
            # 오늘 할 일은 종류 색 글씨 + 점 (알약은 배경이 빠지면 흰 글씨가 사라져서 글씨로, 10/14)
            label = f'<span style="color:{COLOR[it["type"]][0]};">● {label}</span>'
    if when:
        date_td = (f'<td width="78" style="padding:9px 8px 9px 0;vertical-align:top;{line}">'
                   f'<div style="font-size:14px;font-weight:800;white-space:nowrap;color:{"#a0a3b1" if off else "#e5484d" if late else "#23242b"};">{label}</div>'
                   + (f'<div style="font-size:12px;color:#7b7e8c;white-space:nowrap;margin-top:1px;">{sub}</div>' if sub else '')
                   + '</td>')
    return (
        f'<tr>{date_td}<td style="padding:9px 0;vertical-align:top;{line}">'
        + (f'<div style="font-size:15px;font-weight:700;line-height:1.4;color:#a0a3b1;text-decoration:line-through;">{e(it.get("title"))}</div>'
           if off else
           f'<div style="font-size:15px;font-weight:700;line-height:1.4;">{title_html(it)}{tent}</div>')
        + f'{extra}</td></tr>'
    )


def rows_table(items, t, detail=True, when=True, off=False):
    return ('<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            + ''.join(row(x, t, i == 0, detail, when, off) for i, x in enumerate(items)) + '</table>')


def grouped(items, t, when=True):
    """종류(색)별 소제목 + 줄 목록. 체크리스트와 날짜를 확인해 주세요 칸이 같은 모양을 쓴다"""
    out = ''
    for typ, head, _, _ in GROUPS:
        group = [x for x in items if x['type'] == typ]
        if group:
            out += (f'<div style="font-size:15px;font-weight:800;color:{COLOR[typ][0]};'
                    f'margin:{"14px" if out else "4px"} 0 2px;">{head} {len(group)}</div>' + rows_table(group, t, when=when))
    return out


def text_grouped(items, t, when=True):
    """grouped()의 글자 버전 (HTML을 못 보는 메일 앱용)"""
    out = []
    for typ, head, _, _ in GROUPS:
        group = [x for x in items if x['type'] == typ]
        if not group:
            continue
        out += ['', f'{head} {len(group)}']
        for x in group:
            # 제목이 이미 브랜드로 시작하면 한 번만 (HTML의 title_html과 같은 규칙)
            brand = f"[{x['brand']}] " if x.get('brand') and typ != 'memo' and not x['title'].startswith(x['brand']) else ''
            day = f"{re.sub(r'<[^>]+>', '', rel_day(x, t))} {re.sub(r'<[^>]+>', '', sub_line(x, t))}".rstrip() + ' | ' if when else ''
            line = f"- {day}{brand}{x['title']}"
            if x.get('tentative'):
                line += ' (확답 전)'
            if typ == 'pay' and x.get('payer'):
                line += f" / 입금자명 {x['payer']}"
            if typ == 'pay' and x.get('amount'):
                line += f" / 금액 {x['amount']}"
            out.append(line)
    return out


# 메일 맨 위 2주 달력 (이번 주 + 다음 주). 메일 앱은 스크립트를 안 돌려서 일정표처럼 눌러 펼칠 수 없으니,
# 칸마다 칩을 미리 그려 둔다. 한 달은 폰에서 너무 길어져 체크리스트가 밀려서 2주만(10/14 사용자 A안)
CAL_MAX = 4  # 한 칸에 칩 4개까지, 나머지는 +N (폰에서 칸 높이가 들쭉날쭉해지지 않게)


def calendar_html(items, t):
    start = t - dt.timedelta(days=(t.weekday() + 1) % 7)  # 이번 주 일요일 (일정표와 같이 일요일부터)
    by_day = {}
    for x in items:
        if x.get('date') and not x['done']:  # 끝낸 일은 빼고 남은 일만 (메일은 아침 기준 '남은 일' 보기)
            by_day.setdefault(x['date'], []).append(x)
    head = ''.join(f'<td align="center" style="font-size:11px;font-weight:700;padding-bottom:2px;'
                   f'color:{"#e5484d" if i == 0 else "#7b7e8c"};">{d}</td>' for i, d in enumerate('일월화수목금토'))
    weeks = ''
    for w in range(2):
        cells = ''
        for i in range(7):
            day = start + dt.timedelta(days=w * 7 + i)
            lst = sorted(by_day.get(day.isoformat(), []), key=sort_key)
            if day == t:
                # 오늘 = 숫자에 테두리 동그라미 (채운 동그라미는 배경이 빠지면 흰 숫자가 사라져서)
                num = (f'<span style="display:inline-block;min-width:18px;height:18px;line-height:18px;border-radius:10px;'
                       f'border:2px solid #23242b;color:#23242b;font-weight:800;">{day.day}</span>')
            else:
                color = '#c3c5cf' if day < t else ('#e5484d' if i == 0 else '#23242b')
                num = f'<span style="display:inline-block;height:20px;line-height:20px;color:{color};">{day.day}</span>'
            chips = ''
            for x in lst[:CAL_MAX]:
                strong, soft = COLOR[x['type']]
                label = x['title'] if x['type'] == 'memo' else (x.get('brand') or x['title'])
                # 확답 전 = 바탕 없이 점선 (Gmail은 outline을 안 받아서 border로)
                look = (f'border:1px dashed {strong};' if x.get('tentative')
                        else f'background-color:{soft};border-left:3px solid {strong};')
                chips += (f'<div style="margin-top:2px;padding:0 2px;border-radius:4px;{look}color:{strong};'
                          f'font-size:10px;font-weight:700;line-height:1.4;white-space:nowrap;overflow:hidden;text-align:left;">'
                          f'{e(label)}</div>')
            if len(lst) > CAL_MAX:
                chips += f'<div style="font-size:10px;color:#7b7e8c;text-align:left;padding-left:2px;">+{len(lst) - CAL_MAX}</div>'
            cells += (f'<td width="14%" align="center" style="vertical-align:top;padding:4px 1px 6px;'
                      f'border-top:1px solid {LINE};font-size:12.5px;overflow:hidden;">{num}{chips}</td>')
        weeks += f'<tr>{cells}</tr>'
    end = start + dt.timedelta(days=13)
    return (f'<div style="font-size:15px;font-weight:800;margin-bottom:6px;">이번 주 · 다음 주 '
            f'<span style="font-size:12px;font-weight:700;color:#7b7e8c;">{start.month}/{start.day} – {end.month}/{end.day}</span></div>'
            f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;">'
            f'<tr>{head}</tr>{weeks}</table>')


def recently_cancelled(items, hours=24):
    """트란시스가 메일에서 진행 취소를 확인해 뺀 일 중 최근 것 (저녁 정리에서 뺀 것도 다음 아침 메일에 한 번 보이게)"""
    since = dt.datetime.now(SEOUL) - dt.timedelta(hours=hours)
    out = []
    for x in items:
        if x.get('removed') and x.get('doneBy') == CANCELLED_BY and x.get('doneAt'):
            try:
                if dt.datetime.fromisoformat(x['doneAt']) >= since:
                    out.append(x)
            except ValueError:
                pass
    return sorted(out, key=sort_key)


# Code.gs가 취소로 뺀 일에 남기는 이름 (성현 님이 직접 지운 일과 구분)
CANCELLED_BY = '트란시스(취소)'


def render(items, unclear, t, url, coverage=None):
    cancelled = recently_cancelled(items)
    items = [x for x in items if not x.get('removed')]  # 일정표에서 지운 일은 메일에도 안 나온다
    open_items = [x for x in items if not x['done']]
    tbd = sorted([x for x in open_items if not x.get('date') and x['type'] != 'memo'], key=sort_key)
    due = sorted([x for x in items if is_due(x, t)], key=sort_key)
    due_nos = {x['no'] for x in due}
    later = sorted([x for x in open_items if x['no'] not in due_nos and x.get('date') and (d_of(x['date']) - t).days <= 30],
                   key=sort_key)
    # 건수는 빼고 날짜 + 체크리스트만. 몇 건인지보다 내용을 보고 판단하게(10/14 사용자)
    # 날짜는 꼭 붙인다: 매일 제목이 같으면 Gmail이 한 대화로 묶어 새 메일이 안 보여서
    subject = f'트란시스가 전하는 오전 리마인더 체크리스트 · {md(t)}'
    # 얼마나 읽었는지 매일 보이게 (놓친 메일이 없는지 성현 님이 가늠할 수 있도록)
    cov = coverage or {}
    if cov.get('scan') == 'FULL':
        left = cov.get('left') or 0
        cov_line = (f"지난 60일 협업 메일 {cov.get('candidates')}개 스레드 중 이번에 {cov.get('read')}개를 끝까지 읽었어요. "
                    + (f"남은 {left}개는 다음 실행에서 이어 읽어요(전체 확인 전)." if left else '이번으로 전체 확인을 마쳤어요.'))
    elif cov.get('candidates') is not None:
        cov_line = f"최근 하루 메일 {cov.get('candidates')}개 스레드를 찾아 {cov.get('read')}개를 끝까지 읽었어요."
    else:
        cov_line = ''
    panel = 'background-color:#ffffff;border:1px solid #e6e8ef;border-radius:18px;padding:16px;'
    gap = '<tr><td style="height:12px;line-height:12px;font-size:0;">&nbsp;</td></tr>'

    # 맨 위 한 줄 요약: 오늘이 마감인 일만 종류별로 센다 (오늘 하루가 얼마나 바쁜지 먼저 보이게)
    on_today = [x for x in due if days_left(x, t) == 0]
    bits = [f'{label} {n}{unit}' for typ, _, label, unit in GROUPS
            if (n := sum(1 for x in on_today if x['type'] == typ))]
    late_n = sum(1 for x in due if (days_left(x, t) or 0) < 0)
    summary = ('오늘 ' + ' · '.join(bits)) if bits else '오늘이 마감인 일은 없어요'
    if late_n:
        summary += f' <span style="color:#e5484d;">· 지난 일 {late_n}건</span>'

    if due:
        today_html = (f'<div style="font-size:14px;font-weight:700;padding:10px 12px;border-radius:12px;'
                      f'background-color:#f5f6fa;border:1px solid #e6e8ef;">{summary}</div>{grouped(due, t)}')
    else:
        today_html = '<div style="font-size:14px;color:#7b7e8c;padding:4px 2px;">오늘 체크할 일은 없어요. 스토리만 올리면 끝! 🐺</div>'

    # 날짜를 확인해 주세요 = 일정 미정(시트에 남아 있음) + 이번에 읽다가 애매했던 메일
    # 전부 날짜 미정이라 날짜 칸은 빼고, 체크리스트처럼 종류(색)별로 묶는다.
    # 정산을 브랜드 한 줄로 묶어 봤다가 금액·원본 메일이 안 보여 판단이 애매해서 되돌림(10/14)
    unclear_html = ''
    if tbd or unclear:
        lis = ''.join(f'<li style="margin:4px 0;">“{e(u.get("subject"))}” — {e(u.get("reason"))}</li>' for u in unclear)
        unclear_html = (gap + f'<tr><td style="{panel}"><div style="font-size:15px;font-weight:800;margin-bottom:2px;">'
                        f'날짜를 확인해 주세요 - {len(tbd) + len(unclear)}건</div><div style="font-size:12.5px;color:#7b7e8c;margin-bottom:4px;">'
                        f'일정은 있는데 날짜가 아직 정해지지 않았거나, 확답이 필요한 일이에요.</div>'
                        + grouped(tbd, t, when=False)
                        + (f'<ul style="margin:6px 0 0;padding-left:18px;font-size:13.5px;line-height:1.5;">{lis}</ul>' if lis else '')
                        + '</td></tr>')

    # 취소돼서 뺀 일: 메일만 보고 판단한 거라 틀릴 수 있어서, 근거(원본 메일)와 함께 한 번 알린다
    # 가장 최근에 바뀐 일이라 달력보다도 위, 맨 처음에 둔다(10/14 사용자). 취소 다음 아침 한 번만 나오니 평소엔 없다
    cancelled_html = ''
    if cancelled:
        cancelled_html = (f'<tr><td style="{panel}"><div style="font-size:15px;font-weight:800;margin-bottom:2px;">'
                          f'취소돼서 뺀 일 - {len(cancelled)}건</div><div style="font-size:12.5px;color:#7b7e8c;margin-bottom:4px;">'
                          f'메일에서 진행 취소를 확인해 일정표에서 뺐어요. 잘못 뺐다면 알려 주세요.</div>'
                          + rows_table(cancelled, t, off=True) + '</td></tr>' + gap)

    # 그 다음 30일: 원본 메일 줄은 빼고 짧게 (자세한 건 그날 체크리스트에서)
    later_html = ''
    if later:
        later_html = (gap + f'<tr><td style="{panel}"><div style="font-size:15px;font-weight:800;margin-bottom:2px;">'
                      f'그 다음 30일 - {len(later)}건</div>{rows_table(later, t, detail=False)}</td></tr>')

    body = f'''<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>트란시스 아침 브리핑</title></head>
<body style="margin:0;padding:0;background-color:#f5f6fa;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f6fa;"><tr><td align="center" style="padding:20px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;word-break:keep-all;overflow-wrap:break-word;font-family:-apple-system,'Apple SD Gothic Neo','Malgun Gothic',sans-serif;color:#23242b;">
<tr><td style="padding:0 4px 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="vertical-align:middle;"><div style="font-size:12px;color:#7b7e8c;font-weight:700;">트란시스 아침 브리핑</div>
<div style="font-size:21px;font-weight:800;line-height:1.3;">{md(t)} 체크리스트</div></td>
<td align="right" style="vertical-align:middle;"><a href="{e(url)}" style="display:inline-block;padding:7px 12px;border-radius:999px;border:1.5px solid #23242b;color:#23242b;font-size:13px;font-weight:800;text-decoration:none;white-space:nowrap;">일정표 열기 →</a></td>
</tr></table></td></tr>
{cancelled_html}<tr><td style="{panel}">{calendar_html(items, t)}</td></tr>
{gap}
<tr><td style="{panel}">{today_html}</td></tr>
{unclear_html}{later_html}
<tr><td align="center" style="padding:16px 0 0;font-size:11.5px;color:#a0a3b1;line-height:1.6;">끝낸 일은 <a href="{e(url)}" style="color:#7b7e8c;font-weight:700;">일정표</a>에서 동그라미를 누르면 바로 빠지고, 내일 메일에도 안 나와요.<br>{e(cov_line) + '<br>' if cov_line else ''}트란시스가 contact@trendportal.kr 메일을 읽고 매일 아침 정리해요.<br>날짜가 틀려 보이면 원본 메일을 꼭 확인해 주세요.</td></tr>
</table></td></tr></table></body></html>'''

    text = [f'트란시스 아침 브리핑 - {md(t)} 체크리스트', f'일정표: {url}', '']
    if cancelled:
        text += [f'취소돼서 뺀 일 - {len(cancelled)}건 (잘못 뺐다면 알려 주세요)'] + text_grouped(cancelled, t) + ['']
    text.append(re.sub(r'<[^>]+>', '', summary))
    text += text_grouped(due, t)
    if not due:
        text.append('오늘 체크할 일은 없어요.')
    if tbd or unclear:
        text += ['', f'날짜를 확인해 주세요 - {len(tbd) + len(unclear)}건'] + text_grouped(tbd, t, when=False) \
            + ([''] if unclear else []) + [f"- {u.get('subject')} ({u.get('reason')})" for u in unclear]
    text += ['', '끝낸 일은 트란시스 일정표에서 동그라미를 눌러 주세요.']
    if cov_line:
        text.append(cov_line)
    return subject, body, '\n'.join(text), due, later


def scan_mode(res):
    """FULL = 지난 60일 전체 훑기 (처음, 그리고 30일마다), DAILY = 최근 하루 새 메일만 (아침·저녁 두 번 돌아서 한 번 실패해도 겹친다)"""
    at = res.get('fullScanAt')
    if not at:
        return 'FULL'
    last = dt.datetime.fromisoformat(at).astimezone(SEOUL).date()
    return 'FULL' if (today() - last).days >= FULL_EVERY_DAYS else 'DAILY'


def cmd_state():
    res = api_get()
    mode = scan_mode(res)
    queue = res.get('fullQueue') or []
    print(f"scan: {mode}  (last full scan: {res.get('fullScanAt') or 'never'})")
    if mode == 'FULL' and not queue:
        print('full queue: none yet -> START: build the candidate list and save it with `queue`, then run `state` again')
    elif mode == 'FULL':
        print(f'full queue: {len(queue)} threads left. Read THIS BATCH ({min(BATCH, len(queue))}) this run:')
        print(' '.join(queue[:BATCH]))
    print('no | key | type | brand | title | date | time | tentative | done | removed | source')
    # 한 줄에 하나씩 (토큰 절약). 금액·입금자명은 뺀다
    for x in res['items']:
        print(' | '.join(str(x.get(k) or '') for k in
                         ('no', 'key', 'type', 'brand', 'title', 'date', 'time', 'tentative', 'done', 'removed', 'source')))


def cmd_run(path):
    with open(path, encoding='utf-8') as f:
        ext = json.load(f)
    before = api_get()
    now = dt.datetime.now(SEOUL).replace(microsecond=0).isoformat()
    # 시트에 아직 예시 일정만 있으면 첫 실제 실행이므로 비우고 시작한다
    post = api_post({'updatedAt': now, 'reset': bool(before.get('sample')), 'scan': ext.get('scan', 'DAILY'),
                     'items': ext.get('items', []), 'readThreads': ext.get('read_threads', [])})
    if not post.get('ok'):
        print('POST errors:', post.get('errors') or post.get('error'))
    after = api_get()
    t = today()
    left = len(after.get('fullQueue') or [])
    cov = dict(ext.get('coverage') or {}, scan=ext.get('scan', 'DAILY'), left=left)
    subject, body, text, due, later = render(after['items'], ext.get('unclear', []), t, env('TRANSIS_URL'), cov)
    os.makedirs(OUT, exist_ok=True)
    for name, content in (('subject.txt', subject), ('email.html', body), ('email.txt', text)):
        with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
            f.write(content)
    print(json.dumps({
        'reset_sample': bool(before.get('sample')),
        'sent_items': len(ext.get('items', [])),
        'sheet_items': len(after['items']),
        'due_today': [f"{x['no']} {x['type']} {x['date']} {x['title']}" for x in due],
        'later': [f"{x['no']} {x['type']} {x['date']} {x['title']}" for x in later],
        'unclear': len(ext.get('unclear', [])),
        'coverage': ext.get('coverage'),
        'full_queue_left': left,
        'full_scan_at': after.get('fullScanAt') or None,
        'subject': subject,
        'files': [os.path.join(OUT, n) for n in ('subject.txt', 'email.html', 'email.txt')],
    }, ensure_ascii=False, indent=1))


def cmd_queue(path):
    with open(path, encoding='utf-8') as f:
        ids = list(dict.fromkeys(x.strip() for x in f if x.strip()))  # 순서 유지, 중복 제거
    res = api_post({'updatedAt': dt.datetime.now(SEOUL).replace(microsecond=0).isoformat(), 'queue': ids, 'items': []})
    print(f"queue saved: {len(res.get('fullQueue') or [])} threads" if res.get('ok') else f"queue failed: {res}")


if __name__ == '__main__':
    if len(sys.argv) >= 2 and sys.argv[1] == 'state':
        cmd_state()
    elif len(sys.argv) >= 3 and sys.argv[1] == 'queue':
        cmd_queue(sys.argv[2])
    elif len(sys.argv) >= 3 and sys.argv[1] == 'run':
        cmd_run(sys.argv[2])
    else:
        sys.exit(__doc__)
