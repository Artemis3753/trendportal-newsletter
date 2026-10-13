"""트란시스: 매일 아침 일정 시트와 리마인더 메일을 맞추는 정해진 계산 부분.

메일을 읽고 일정을 뽑는 일(판단이 필요한 일)은 트란시스(Claude)가 하고,
이 스크립트는 그 결과를 받아 시트에 보내고, D-day로 고르고, 메일 HTML을 만든다.

    python3 transis/transis.py state                 # 시트의 지금 일정 (중복 판단용, 금액·입금자명은 뺌)
    python3 transis/transis.py run extracted.json    # 시트에 보내기 → 다시 읽기 → 고르기 → /tmp/transis/에 메일 파일

환경 변수 TRANSIS_URL(웹앱 주소), TRANSIS_TOKEN(비밀 열쇠)이 필요하다. 둘 다 출력하지 않는다.
표준 라이브러리만 쓴다(클라우드 환경에 따로 설치할 게 없게).
"""

import datetime as dt
import html
import json
import os
import sys
import urllib.parse
import urllib.request

OUT = '/tmp/transis'
SEOUL = dt.timezone(dt.timedelta(hours=9))
DOW = '월화수목금토일'  # date.weekday(): 월=0
NAME = {'upload': '업로드', 'draft': '초안·기획안', 'shoot': '방문 촬영', 'pay': '정산', 'memo': '메모'}
# 성현 님이 정한 미리 알림: 업로드 하루 전, 초안·기획안 3일 전, 방문 촬영 3일 전, 정산은 입금 확인될 때까지 매일
# 메모(성현 님이 일정표에서 직접 적은 할 일)는 날짜가 있으면 하루 전부터, 없으면 완료할 때까지 매일
LEAD = {'upload': 1, 'draft': 3, 'shoot': 3, 'memo': 1}
COLOR = {  # 일정표와 같은 색 (진한색, 옅은 바탕)
    'upload': ('#1fa971', '#d9f3e7'),
    'draft': ('#ec4a5a', '#ffe2e5'),
    'shoot': ('#3a86ff', '#dde9ff'),
    'pay': ('#c49a1c', '#f6edd0'),
    'memo': ('#6b6f80', '#e9eaf0'),
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


def when(it):
    if not it.get('date'):
        return '날짜 없음'
    tail = '입금 예정' if it['type'] == 'pay' else (it.get('time') or ('' if it['type'] == 'memo' else '시간 미정'))
    return f"{md(d_of(it['date']))} {tail}".strip()


def dday(it, t):
    if not it.get('date'):
        return '메모'
    n = (d_of(it['date']) - t).days
    if it['type'] == 'pay':
        return f'입금 D-{n}' if n > 0 else '입금 오늘' if n == 0 else '입금 확인'
    return f'D-{n}' if n > 0 else 'D-DAY' if n == 0 else f'{-n}일 지남'


def is_due(it, t):
    """오늘 메일의 '오늘 챙길 일'에 들어가는지"""
    if it['done']:
        return False
    if not it.get('date'):
        return True  # 날짜 없는 메모
    n = (d_of(it['date']) - t).days
    if it['type'] == 'pay' or n < 0:
        return True  # 정산은 확인될 때까지, 지난 일은 끝낼 때까지 매일
    return n <= LEAD[it['type']]


def sort_key(it):
    # 날짜 없는 메모는 맨 위
    return (it.get('date') or '0000', it.get('time') or '99', it['no'])


# '미정'은 노란 형광펜으로 (시간을 아직 확인해야 한다는 표시)
TBD = ('시간 <span style="background:#fff1a8;color:#23242b;font-weight:700;padding:0 4px;border-radius:4px;">'
       '미정</span>')


def e(s):
    return html.escape(str(s or ''))


# ---------- 메일 HTML (Gmail은 <style>을 제대로 안 읽어서 전부 인라인 스타일, 표 구조) ----------

def card(it, t):
    c, soft = COLOR[it['type']]
    brand = f"{e(it['brand'])} · " if it.get('brand') and it['type'] != 'memo' else ''
    late = bool(it.get('date')) and (d_of(it['date']) - t).days < 0
    pay = ''
    if it['type'] == 'pay' and (it.get('payer') or it.get('amount')):
        bits = []
        if it.get('payer'):
            bits.append(f"입금자명 <b>{e(it['payer'])}</b>")
        if it.get('amount'):
            bits.append(f"금액 <b>{e(it['amount'])}</b>")
        pay = (f'<div style="font-size:12.5px;color:#23242b;margin-top:4px;background:{soft};'
               f'border-radius:8px;padding:6px 8px;">{" · ".join(bits)}</div>')
    src = (f'<div style="font-size:11.5px;color:#a0a3b1;margin-top:3px;">원본 메일: “{e(it["source"])}”</div>'
           if it.get('source') else '')
    return (
        f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        f'style="border:1.5px solid {c};border-radius:14px;margin-bottom:8px;"><tr>'
        f'<td width="44" style="padding:12px 0 12px 12px;vertical-align:top;"><div style="width:32px;height:32px;'
        f'line-height:32px;border-radius:10px;background:{c};color:#ffffff;font-weight:800;font-size:16px;'
        f'text-align:center;">{it["no"]}</div></td>'
        f'<td style="padding:12px 8px;vertical-align:top;">'
        f'<div style="font-size:15px;font-weight:700;line-height:1.4;">{e(it["title"])}</div>'
        f'<div style="font-size:12.5px;color:#7b7e8c;">{brand}{NAME[it["type"]]} · {when(it).replace("시간 미정", TBD)}</div>{pay}{src}</td>'
        f'<td width="72" style="padding:12px 12px 12px 0;vertical-align:top;text-align:right;font-size:16px;'
        f'font-weight:800;color:{"#e5484d" if late else c};white-space:nowrap;">{dday(it, t)}</td>'
        f'</tr></table>'
    )


def week_strip(open_items, t):
    days = [t + dt.timedelta(days=i) for i in range(7)]
    head = ''.join(
        f'<td style="font-size:11.5px;font-weight:700;color:{"#e5484d" if d.weekday() == 6 else "#7b7e8c"};'
        f'padding-bottom:4px;">{DOW[d.weekday()]}</td>' for d in days)
    cells = ''
    for d in days:
        chips = ''.join(
            f'<div style="margin-top:3px;padding:1px 2px;border-radius:4px;background:{COLOR[it["type"]][1]};'
            f'color:{COLOR[it["type"]][0]};font-size:10px;font-weight:700;line-height:1.35;white-space:nowrap;'
            f'overflow:hidden;text-align:left;">{e(it.get("brand") or it["title"])}</div>'
            for it in sorted([x for x in open_items if x['date'] == d.isoformat()], key=sort_key))
        bg = 'background:#fff1a8;border-radius:10px;font-weight:800;' if d == t else ''
        red = 'color:#e5484d;' if d.weekday() == 6 else ''
        cells += (f'<td style="padding:2px;vertical-align:top;"><div style="{bg}padding:6px 2px 7px;'
                  f'font-size:14px;{red}">{d.day}{chips}</div></td>')
    return (f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            f'style="table-layout:fixed;text-align:center;"><tr>{head}</tr><tr>{cells}</tr></table>')


def later_rows(items, t):
    rows = ''
    for it in items:
        c = COLOR[it['type']][0]
        rows += (f'<tr><td width="30" style="padding:5px 0;"><span style="display:inline-block;width:22px;height:22px;'
                 f'line-height:22px;border-radius:7px;background:{c};color:#fff;font-weight:800;font-size:12px;'
                 f'text-align:center;">{it["no"]}</span></td><td style="padding:5px 0;">{e(it["title"])}</td>'
                 f'<td style="padding:5px 0;text-align:right;color:#7b7e8c;white-space:nowrap;">'
                 f'{d_of(it["date"]).month}/{d_of(it["date"]).day} ({DOW[d_of(it["date"]).weekday()]})'
                 f'{" " + it["time"] if it.get("time") and it["type"] != "pay" else ""} · {dday(it, t)}</td></tr>')
    return rows


def render(items, unclear, t, url, coverage=None):
    open_items = [x for x in items if not x['done']]
    due = sorted([x for x in items if is_due(x, t)], key=sort_key)
    due_nos = {x['no'] for x in due}
    later = sorted([x for x in open_items if x['no'] not in due_nos and x.get('date') and (d_of(x['date']) - t).days <= 30],
                   key=sort_key)
    subject = f'[트란시스] {md(t)} 챙길 일 {len(due)}건'
    # 얼마나 읽었는지 매일 보이게 (놓친 메일이 없는지 성현 님이 가늠할 수 있도록)
    cov = coverage or {}
    cov_line = (f"오늘 협업 관련 메일 {cov.get('candidates')}개 스레드를 찾아 {cov.get('read')}개를 끝까지 읽었어요."
                if cov.get('candidates') is not None else '')
    panel = 'background:#ffffff;border-radius:18px;padding:16px;'

    if due:
        today_html = ''.join(card(x, t) for x in due) + (
            '<div style="margin-top:12px;padding:10px 12px;border-radius:12px;background:#f5f6fa;font-size:13px;'
            'color:#7b7e8c;line-height:1.55;">끝낸 일은 <b style="color:#23242b;">트란시스 일정표에서 동그라미</b>를 '
            '누르면 바로 빠지고, 내일 메일에도 안 나와요.</div>')
    else:
        today_html = '<div style="font-size:14px;color:#7b7e8c;padding:4px 2px;">오늘 챙길 일은 없어요. 스토리만 올리면 끝! 🐺</div>'

    unclear_html = ''
    if unclear:
        lis = ''.join(f'<li style="margin:4px 0;">“{e(u.get("subject"))}” — {e(u.get("reason"))}</li>' for u in unclear)
        unclear_html = (f'<tr><td style="height:12px;line-height:12px;font-size:0;">&nbsp;</td></tr>'
                        f'<tr><td style="{panel}"><div style="font-size:15px;font-weight:800;margin-bottom:6px;">'
                        f'날짜를 확인해 주세요</div><div style="font-size:12.5px;color:#7b7e8c;margin-bottom:6px;">'
                        f'일정 같은데 날짜가 분명하지 않아서 일정표에 넣지 않았어요.</div>'
                        f'<ul style="margin:0;padding-left:18px;font-size:13.5px;line-height:1.5;">{lis}</ul></td></tr>')

    later_html = ''
    if later:
        later_html = (f'<div style="font-size:13px;font-weight:800;color:#7b7e8c;margin:14px 0 6px;">그 다음</div>'
                      f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                      f'style="font-size:13.5px;">{later_rows(later, t)}</table>')

    body = f'''<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>트란시스 아침 브리핑</title></head>
<body style="margin:0;padding:0;background:#f5f6fa;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6fa;"><tr><td align="center" style="padding:20px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;word-break:keep-all;overflow-wrap:break-word;font-family:-apple-system,'Apple SD Gothic Neo','Malgun Gothic',sans-serif;color:#23242b;">
<tr><td style="padding:0 4px 14px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td style="vertical-align:middle;padding-right:12px;"><img src="cid:husky.png" width="48" height="48" alt="트란시스" style="display:block;border:0;"></td>
<td style="vertical-align:middle;"><div style="font-size:12px;color:#7b7e8c;font-weight:700;">트란시스 아침 브리핑</div>
<div style="font-size:21px;font-weight:800;line-height:1.3;">{md(t)} 챙길 일 {len(due)}건</div></td></tr></table></td></tr>
<tr><td style="{panel}"><div style="font-size:15px;font-weight:800;margin-bottom:10px;">오늘 챙길 일</div>{today_html}</td></tr>
<tr><td style="height:12px;line-height:12px;font-size:0;">&nbsp;</td></tr>
<tr><td style="{panel}"><div style="font-size:15px;font-weight:800;margin-bottom:10px;">앞으로 7일</div>{week_strip(open_items, t)}{later_html}</td></tr>
{unclear_html}
<tr><td align="center" style="padding:16px 0 6px;"><a href="{e(url)}" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#23242b;color:#ffffff;font-size:14px;font-weight:800;text-decoration:none;">트란시스 일정표 열기</a></td></tr>
<tr><td align="center" style="padding:6px 0 0;font-size:11.5px;color:#a0a3b1;line-height:1.6;">{e(cov_line) + '<br>' if cov_line else ''}트란시스가 contact@trendportal.kr 메일을 읽고 매일 아침 정리해요.<br>날짜가 틀려 보이면 원본 메일을 꼭 확인해 주세요.</td></tr>
</table></td></tr></table></body></html>'''

    text = [f'트란시스 아침 브리핑 - {md(t)} 챙길 일 {len(due)}건', '']
    for x in due:
        line = f"{x['no']}. ({NAME[x['type']]}) {x['title']} - {when(x)} - {dday(x, t)}"
        if x.get('brand') and x['type'] != 'memo':
            line = f"{x['no']}. [{x['brand']}] ({NAME[x['type']]}) {x['title']} - {when(x)} - {dday(x, t)}"
        if x['type'] == 'pay' and x.get('payer'):
            line += f" / 입금자명 {x['payer']}"
        text.append(line)
    if not due:
        text.append('오늘 챙길 일은 없어요.')
    if unclear:
        text += ['', '날짜를 확인해 주세요:'] + [f"- {u.get('subject')} ({u.get('reason')})" for u in unclear]
    text += ['', '끝낸 일은 트란시스 일정표에서 동그라미를 눌러 주세요.', f'일정표: {url}']
    if cov_line:
        text.append(cov_line)
    return subject, body, '\n'.join(text), due, later


def public(it):
    return {k: it.get(k) for k in ('no', 'key', 'type', 'brand', 'title', 'date', 'time', 'done', 'source')}


def cmd_state():
    res = api_get()
    print(json.dumps({'sample': res.get('sample'), 'updatedAt': res.get('updatedAt'),
                      'items': [public(x) for x in res['items']]}, ensure_ascii=False, indent=1))


def cmd_run(path):
    with open(path, encoding='utf-8') as f:
        ext = json.load(f)
    before = api_get()
    now = dt.datetime.now(SEOUL).replace(microsecond=0).isoformat()
    # 시트에 아직 예시 일정만 있으면 첫 실제 실행이므로 비우고 시작한다
    post = api_post({'updatedAt': now, 'reset': bool(before.get('sample')), 'items': ext.get('items', [])})
    if not post.get('ok'):
        print('POST errors:', post.get('errors') or post.get('error'))
    after = api_get()
    t = today()
    subject, body, text, due, later = render(after['items'], ext.get('unclear', []), t, env('TRANSIS_URL'),
                                             ext.get('coverage'))
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
        'subject': subject,
        'files': [os.path.join(OUT, n) for n in ('subject.txt', 'email.html', 'email.txt')],
    }, ensure_ascii=False, indent=1))


if __name__ == '__main__':
    if len(sys.argv) >= 2 and sys.argv[1] == 'state':
        cmd_state()
    elif len(sys.argv) >= 3 and sys.argv[1] == 'run':
        cmd_run(sys.argv[2])
    else:
        sys.exit(__doc__)
