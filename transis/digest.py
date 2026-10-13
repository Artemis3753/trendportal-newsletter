"""Gmail get_thread 결과(JSON)를 읽기 쉬운 요약으로 줄인다.

긴 스레드는 답장마다 이전 메일이 통째로 인용돼서 수십만 자가 되고, 그러면 앞쪽 메일(일정이 적힌 가이드 등)을
건너뛰게 된다. 인용 부분을 잘라 내고 메일마다 날짜·보낸 사람·본문만 남겨서, 스레드 전체를 처음부터 끝까지 읽게 한다.

    python3 transis/digest.py <get_thread 결과 파일> [...]
    cat result.json | python3 transis/digest.py -
"""

import datetime as dt
import json
import re
import sys

SEOUL = dt.timezone(dt.timedelta(hours=9))
# 여기서부터 아래는 이전 메일 인용이라 버린다
QUOTE_START = re.compile(
    r'^\s*(On .{5,200}wrote:\s*$|.{0,80}님이 작성:\s*$|-{2,}\s*Original Message|보낸 사람\s*:|From:\s.+@|\d{4}년 \d{1,2}월 \d{1,2}일.{0,60}작성)',
    re.M)
MAX_PER_MESSAGE = 4000


def clean(body):
    body = body or ''
    m = QUOTE_START.search(body)
    if m:
        body = body[:m.start()]
    lines = [l for l in body.splitlines() if not l.lstrip().startswith('>')]
    text = re.sub(r'\n{3,}', '\n\n', '\n'.join(lines)).strip()
    text = text.replace('&nbsp;', ' ')
    if len(text) > MAX_PER_MESSAGE:
        text = text[:MAX_PER_MESSAGE] + ' …(이하 생략)'
    return text


def seoul(iso):
    try:
        return dt.datetime.fromisoformat(iso.replace('Z', '+00:00')).astimezone(SEOUL).strftime('%Y-%m-%d (%a) %H:%M')
    except Exception:
        return iso


def digest(thread):
    msgs = thread.get('messages', [])
    out = [f"=== thread {thread.get('id')} · {len(msgs)} messages"]
    subject = next((m.get('subject') for m in msgs if m.get('subject')), '')
    out.append(f'subject: {subject}')
    for i, m in enumerate(msgs, 1):
        sent = 'SENT' in (m.get('labelIds') or [])
        out.append(f"--- [{i}/{len(msgs)}] {seoul(m.get('date', ''))} KST · {'우리(트렌드포털)가 보냄' if sent else m.get('sender', '')}"
                   f" → {', '.join(m.get('toRecipients') or [])}")
        out.append(clean(m.get('plaintextBody') or m.get('snippet') or ''))
    return '\n'.join(out)


def main(paths):
    for p in paths:
        raw = sys.stdin.read() if p == '-' else open(p, encoding='utf-8').read()
        data = json.loads(raw)
        threads = data if isinstance(data, list) else [data]
        for t in threads:
            print(digest(t))
            print()


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1:])
