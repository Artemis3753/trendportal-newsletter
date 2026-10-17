/**
 * 트란시스 일정표 — 구글 시트(일정 저장소) + Apps Script 웹앱
 *
 * 세 가지 길로 쓰인다.
 *  1. 일정표 화면(공개 링크): doGet → Index.html. 성현 님이 ✓를 누르면 markDone()으로 바로 시트에 저장
 *  2. 트란시스가 일정 보내기: doPost(JSON + 비밀 토큰) → 시트에 추가·갱신
 *  3. 트란시스가 현재 상태 읽기: doGet?api=items&token=... → 완료 여부까지 담긴 전체 목록
 *
 * 날짜(date)가 빈 일정 = '일정 미정'. 메일에 일정은 있는데 날짜가 아직 안 정해진 것도 빠뜨리지 않고 남기기 위해서다.
 * 삭제는 행을 지우지 않고 removed 표시만 한다. 행을 지우면 다음 날 트란시스가 같은 메일을 읽고 다시 넣어 버리기 때문.
 *
 * 공개 화면에는 PUBLIC_FIELDS만 보낸다. 입금자명·금액·원본 메일 제목은 시트와 트란시스(메일)에만 있다.
 */

const SHEET = 'items';
const META = 'meta';
// key = 원본 메일 스레드 id. 같은 메일에서 뽑은 일정이 두 번 들어가지 않게 하는 이름표
// 새 칸은 맨 뒤에만 붙인다 (이미 쌓인 시트의 칸 순서가 어긋나지 않게)
const FIELDS = ['no', 'key', 'type', 'brand', 'title', 'date', 'time', 'done', 'doneAt', 'source', 'payer', 'amount', 'updatedAt', 'doneBy', 'removed', 'tentative', 'manual', 'mailDate', 'mailTime'];
// tentative = 확답 전: 날짜는 제안됐지만 한쪽이 아직 확정하지 않은 일정. 빈 칸(옛 줄)은 확정으로 읽는다
// manual = 성현 님이 일정표에서 날짜·시각을 직접 바꾼 일. mailDate·mailTime = 그때 트란시스가 메일에서 읽었던 날짜·시각.
// 트란시스가 같은 메일을 다시 읽어도 메일이 여전히 그 옛 날짜를 말하면 손으로 바꾼 날짜를 지키고,
// 메일에 새 날짜가 나오면(새 정보) 그 날짜를 따른다 (10/17 사용자 A안)
const PUBLIC_FIELDS = ['no', 'type', 'brand', 'title', 'date', 'time', 'done', 'doneAt', 'doneBy', 'tentative', 'manual'];
// 트란시스가 메일에서 취소를 확인해 뺀 일은 removed + doneBy 이름표로 구분한다 (성현 님이 지운 일은 doneBy가 사람 이름)
// 취소된 일은 완료 칸에 줄 그은 채 남고, 휴지통을 누르면 이름표만 '지움'으로 바뀌어 화면에서 사라진다
const CANCELLED = '트란시스(취소)';
const CANCELLED_GONE = '트란시스(취소·지움)';
const TYPES = ['upload', 'draft', 'shoot', 'pay'];
// 메모: 성현 님이 일정표에서 직접 적는 할 일. 트란시스는 보내지도 바꾸지도 않는다(key가 'memo-'로 시작)
const MEMO = 'memo';
const TZ = 'Asia/Seoul';
// 매주 돌아오는 고정 일정(10/15). 메일에서 오는 일이 아니라서 트란시스가 시트에 보낼 때(doPost) 여기서 직접 줄을 만든다.
// key에 날짜를 붙여(fixed-weekly-2026-10-16) 날짜마다 한 번만 만든다 → 성현 님이 어떤 주를 지워도 다시 생기지 않는다
// weekday: 1 = 월요일 … 7 = 일요일
const FIXED = [
  { key: 'fixed-weekly', weekday: 5, type: 'upload', brand: '주간 총정리', title: '주간 총정리 포스팅', time: '' },
];
const FIXED_DAYS = 14;  // 오늘부터 2주 안의 날짜까지 미리 만든다 (메일 맨 위 2주 달력에 보이게)

/** 처음 한 번만 실행: 시트·머리줄·비밀 토큰·예시 일정을 만든다. 토큰은 실행 로그에 찍힌다 */
function setup() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sh = ss.getSheetByName(SHEET) || ss.insertSheet(SHEET);
  const meta = ss.getSheetByName(META) || ss.insertSheet(META);
  // 시트가 "2026-10-14"를 날짜로, "14:00"을 시각으로 바꿔 버리지 않게 전부 글자 형식으로
  sh.getRange(1, 1, sh.getMaxRows(), FIELDS.length).setNumberFormat('@');
  sh.getRange(1, 1, 1, FIELDS.length).setValues([FIELDS]).setFontWeight('bold');
  sh.setFrozenRows(1);
  meta.getRange('A:B').setNumberFormat('@');

  const props = PropertiesService.getScriptProperties();
  let token = props.getProperty('TOKEN');
  if (!token) {
    token = Utilities.getUuid().replace(/-/g, '');
    props.setProperty('TOKEN', token);
  }

  if (readItems_().length === 0) {
    const d = offsetDate_;
    writeItems_([
      { no: 1, key: 'sample-1', type: 'upload', brand: '모모커피', title: '카페 브랜드 협업 릴스 업로드', date: d(1), time: '14:00', source: '릴스 업로드 일정 확인 부탁드립니다' },
      { no: 2, key: 'sample-2', type: 'draft', brand: '라온뷰티', title: '뷰티 브랜드 기획안 전달', date: d(3), time: '18:00', source: '10월 캠페인 기획안 일정 공유' },
      { no: 3, key: 'sample-3', type: 'shoot', brand: '성수 팝업', title: '성수 팝업스토어 방문 촬영', date: d(3), time: '14:00', source: '팝업 현장 촬영 가능 일정 회신드립니다' },
      { no: 4, key: 'sample-4', type: 'pay', brand: '하루음료', title: '음료 브랜드 협업 정산', date: d(7), time: '', source: '정산 일정 안내드립니다', payer: '(주)예시음료', amount: '○○○만 원' },
      { no: 5, key: 'sample-5', type: 'upload', brand: '노바게임즈', title: '게임 출시 기념 카드뉴스 업로드', date: d(9), time: '12:00' },
      { no: 6, key: 'sample-6', type: 'draft', brand: '편의점 신제품', title: '편의점 신제품 초안 전달', date: d(14), time: '17:00' },
      { no: 7, key: 'sample-7', type: 'shoot', brand: '현대미술 전시', title: '전시회 현장 촬영', date: d(16), time: '11:00' },
      { no: 8, key: 'sample-8', type: 'upload', brand: '주간 총정리', title: '주간 총정리 업로드', date: d(-4), time: '18:00', done: true, doneAt: now_() },
      { no: 9, key: 'sample-9', type: 'draft', brand: '패션 브랜드', title: '패션 브랜드 기획안 전달', date: d(-3), time: '15:00', done: true, doneAt: now_() },
    ]);
    setMeta_('sample', 'true');
    setMeta_('updatedAt', now_());
  }
  Logger.log('TOKEN = ' + token);
}

/** 일정표 화면, 또는 트란시스용 목록(api=items) */
function doGet(e) {
  const p = (e && e.parameter) || {};
  if (p.api === 'items') {
    if (!checkToken_(p.token)) return json_({ ok: false, error: 'bad token' });
    return json_({ ok: true, updatedAt: getMeta_('updatedAt'), sample: isTrue_(getMeta_('sample')),
                   fullScanAt: getMeta_('fullScanAt'), fullQueue: getQueue_(), items: readItems_() });
  }
  if (p.a === 'done' || p.a === 'undo') return mailLink_(p);
  const t = HtmlService.createTemplateFromFile('Index');
  // </script> 같은 글자가 들어와도 스크립트가 끊기지 않게 '<'를 바꿔서 넣는다
  t.dataJson = JSON.stringify(publicData_()).replace(/</g, '\\u003c');
  return t.evaluate()
    .setTitle('트란시스 일정표')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1, viewport-fit=cover');
}

/**
 * 트란시스가 일정을 보낸다: { token, updatedAt, reset?, scan?, items: [{ key, type, brand, title, date, time?, source?, payer?, amount?, tentative?, done? }] }
 * - date는 YYYY-MM-DD 또는 ''(일정 미정)
 * - tentative: true면 확답 전 (제안된 날짜는 달력에 두되 아직 확정 아님을 표시). 확정되면 트란시스가 false로 다시 보낸다
 * - 같은 key가 있으면 내용만 갱신하고 번호·완료·삭제 여부는 그대로 둔다 (성현 님이 누른 ✓·삭제를 덮어쓰지 않게)
 * - cancelled: true = 메일에서 진행 취소가 확인된 일. 아직 안 끝낸 일이면 삭제 표시(removed)로 빼고
 *   doneBy에 '트란시스(취소)'를 남긴다 → 다음 아침 메일에 '취소돼서 뺀 일'로 한 번 알린다(잘못 뺐으면 성현 님이 알아채게)
 * - queue: 전체 훑기(FULL)에서 읽을 스레드 id 목록. 한 번에 다 못 읽어서 시트에 남겨 두고 여러 번 나눠 읽는다
 * - readThreads: 이번에 끝까지 읽은 스레드 id. 남은 목록(queue)에서 뺀다
 * - scan: 'FULL'이고 남은 목록이 비었을 때만 전체 훑기를 마친 시각을 남긴다 (다음부터는 최근 메일만 읽어도 되는지 판단용)
 * - 새 key면 다음 번호를 붙인다
 * - reset: true면 예시 일정을 지우고 새로 시작한다 (첫 실제 실행 때 한 번)
 */
function doPost(e) {
  let body;
  try {
    body = JSON.parse(e.postData.contents);
  } catch (err) {
    return json_({ ok: false, error: 'bad json' });
  }
  if (!checkToken_(body.token)) return json_({ ok: false, error: 'bad token' });

  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    let items = body.reset ? [] : readItems_();
    addFixed_(items);
    const byKey = {};
    items.forEach(it => { byKey[it.key] = it; });
    let maxNo = items.reduce((m, it) => Math.max(m, it.no), 0);
    const errors = [];
    // 이번에 바뀐 것: 일정표의 "최근 바뀐 일정"에 띄운다(10/17 사용자: 동기화 뒤 무엇이 바뀌었는지 보고 싶다).
    // 다시 보내도 똑같은 항목은 안 적는다 — 새 일정·날짜 변경·확정·취소만
    const changes = [];
    const note = (k, it, extra) => changes.push(Object.assign({ k: k, type: it.type, brand: it.brand, title: it.title, date: it.date, time: it.time }, extra || {}));

    (body.items || []).forEach((raw, i) => {
      const it = clean_(raw);
      if (!it.key || TYPES.indexOf(it.type) < 0 || (it.date && !/^\d{4}-\d{2}-\d{2}$/.test(it.date))) {
        errors.push('item ' + i + ': key and type(' + TYPES.join('/') + ') are required; date must be YYYY-MM-DD or empty');
        return;
      }
      const old = byKey[it.key];
      if (old) {
        const was = { date: old.date, time: old.time, tentative: old.tentative, removed: old.removed };
        ['type', 'brand', 'title', 'source', 'payer', 'amount', 'tentative'].forEach(f => { old[f] = it[f]; });
        // 손으로 바꾼 날짜는 메일 날짜가 그대로면 지키고, 메일 날짜가 달라졌으면 메일을 따른다(손 표시도 지움)
        if (!(old.manual && it.date === old.mailDate && it.time === old.mailTime)) {
          old.date = it.date;
          old.time = it.time;
          old.manual = false;
          old.mailDate = '';
          old.mailTime = '';
        }
        if (raw.done === true && !old.done) { old.done = true; old.doneAt = now_(); }
        if (raw.cancelled === true && !old.done && !old.removed) { old.removed = true; old.doneBy = CANCELLED; old.doneAt = now_(); }
        old.updatedAt = now_();
        if (!old.removed && !old.done) {
          if (old.date !== was.date || old.time !== was.time) note('date', old, { from: was.date, fromTime: was.time });
          else if (was.tentative && !old.tentative) note('confirmed', old);
        }
        if (old.removed && !was.removed) note('cancelled', old);
      } else if (raw.cancelled === true) {
        return;  // 시트에 없던 일이 이미 취소된 거면 새로 넣지 않는다
      } else {
        it.no = ++maxNo;
        it.done = raw.done === true;
        it.doneAt = it.done ? now_() : '';
        it.updatedAt = now_();
        items.push(it);
        byKey[it.key] = it;
        if (!it.done) note('new', it);
      }
    });
    if (changes.length) addChanges_(changes);

    writeItems_(items);
    setMeta_('updatedAt', body.updatedAt || now_());
    setMeta_('sample', body.reset ? 'false' : getMeta_('sample') || 'false');
    // 남은 목록: 새로 받으면 통째로 바꾸고, 읽은 스레드는 뺀다
    let queue = Array.isArray(body.queue) ? body.queue.map(String) : getQueue_();
    if (Array.isArray(body.readThreads)) {
      const read = {};
      body.readThreads.forEach(id => { read[String(id)] = true; });
      queue = queue.filter(id => !read[id]);
    }
    setMeta_('fullQueue', JSON.stringify(queue));
    // 남은 스레드가 하나라도 있으면 아직 전체 훑기를 마친 게 아니다
    if (body.scan === 'FULL' && errors.length === 0 && queue.length === 0) setMeta_('fullScanAt', now_());
    return json_({ ok: errors.length === 0, errors: errors, fullQueue: queue, items: readItems_() });
  } finally {
    lock.releaseLock();
  }
}

/** 일정표의 ✓ 버튼. by = 누른 사람 이름(그 기기에 기억해 둔 것). 화면은 결과를 받아 다시 그린다 */
function markDone(no, done, by) {
  return editItem_(no, it => {
    it.done = done === true;
    it.doneAt = it.done ? now_() : '';
    it.doneBy = it.done ? String(by || '').trim().slice(0, 20) : '';
  });
}

/** 완료 목록의 삭제 버튼. 끝낸 일과 취소된 일만 지울 수 있다. removed = false면 되돌리기 */
function removeItem(no, removed) {
  return editItem_(no, it => {
    // 취소된 일은 이미 removed라서 이름표만 바꾼다 (되돌리면 다시 완료 칸의 '취소'로)
    if (it.doneBy === CANCELLED || it.doneBy === CANCELLED_GONE) {
      it.doneBy = removed === false ? CANCELLED : CANCELLED_GONE;
      return;
    }
    if (removed !== false && !it.done) throw new Error('only done items can be removed');
    it.removed = removed !== false;
  });
}

/**
 * 취소된 일의 동그라미. cancelled = false면 되살리기(다시 할 일로), true면 그 되살리기를 되돌리기.
 * 메일만 보고 판단한 취소라 틀릴 수 있어서, 성현 님이 바로 되살릴 수 있게 둔다
 */
function setCancelled(no, cancelled) {
  return editItem_(no, it => {
    if (cancelled === false) {
      if (!isCancelled_(it)) throw new Error('only cancelled items can be restored');
      it.removed = false;
      it.doneBy = '';
      it.doneAt = '';
    } else {
      if (it.done || it.removed) throw new Error('only open items can be cancelled');
      it.removed = true;
      it.doneBy = CANCELLED;
      it.doneAt = now_();
    }
  });
}

/**
 * 일정표에서 날짜·시각 직접 바꾸기. date = '' 이면 일정 미정으로 돌린다.
 * 처음 손댈 때 트란시스가 읽었던 날짜를 mailDate·mailTime에 남겨 두고(doPost가 비교용으로 씀),
 * 다시 그 메일 날짜로 되돌리면 손 표시를 지운다.
 * 메모·고정 일정은 트란시스가 건드리지 않는 줄이라 표시 없이 날짜만 바꾼다
 */
function setWhen(no, date, time) {
  const d = String(date || '').trim();
  const tm = String(time || '').trim();
  if (d && !/^\d{4}-\d{2}-\d{2}$/.test(d)) throw new Error('bad date');
  if (tm && !/^\d{2}:\d{2}$/.test(tm)) throw new Error('bad time');
  return editItem_(no, it => {
    if (it.done || it.removed) throw new Error('only open items can be moved');
    const fromMail = it.type !== MEMO && String(it.key).indexOf('fixed-') !== 0;
    if (fromMail && !it.manual) {
      it.mailDate = it.date;
      it.mailTime = it.time;
    }
    it.date = d;
    it.time = d ? tm : '';
    it.manual = fromMail && !(it.date === it.mailDate && it.time === it.mailTime);
    if (!it.manual) { it.mailDate = ''; it.mailTime = ''; }
    it.updatedAt = now_();
  });
}

/** 일정표의 메모 칸: 글(필수)과 날짜·시각(선택)으로 회색 메모 일정을 만든다 */
function addMemo(text, date, time) {
  const title = String(text || '').trim().slice(0, 200);
  if (!title) throw new Error('memo is empty');
  const d = String(date || '').trim();
  const tm = String(time || '').trim();
  if (d && !/^\d{4}-\d{2}-\d{2}$/.test(d)) throw new Error('bad date');
  if (tm && !/^\d{2}:\d{2}$/.test(tm)) throw new Error('bad time');
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const items = readItems_();
    const no = items.reduce((m, it) => Math.max(m, it.no), 0) + 1;
    items.push({ no: no, key: 'memo-' + Utilities.getUuid(), type: MEMO, brand: '메모', title: title,
                 date: d, time: tm, done: false, doneAt: '', source: '', payer: '', amount: '', updatedAt: now_() });
    writeItems_(items);
    return publicData_();
  } finally {
    lock.releaseLock();
  }
}

function editItem_(no, change) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const items = readItems_();
    const it = items.filter(x => x.no === Number(no))[0];
    if (!it) throw new Error('no such item: ' + no);
    change(it);
    writeItems_(items);
    return publicData_();
  } finally {
    lock.releaseLock();
  }
}

/**
 * 메일의 '✓ 완료' 링크: ?a=done&no=17&sig=… (되돌리기는 a=undo)
 * 메일 안에서는 일정표(스크립트)가 돌지 않아서, 링크 한 번으로 완료하고 결과 화면을 보여 준다(10/15 사용자 B안).
 * sig = HMAC-SHA256(TOKEN, 'done:17') — 토큰은 링크에 안 들어가고, 번호만 바꿔서는 다른 일을 건드릴 수 없다.
 * 잘못 누른 경우를 위해 결과 화면에 되돌리기 버튼을 둔다
 */
const MAIL_DONE_BY = '성현(메일)';

function mailLink_(p) {
  const no = Number(p.no);
  if (!no || !checkSig_(p.a + ':' + no, p.sig)) return linkPage_('링크가 올바르지 않아요', '메일의 링크를 그대로 눌러 주세요.', null);
  // 폰에서 누르고 기다리는 화면이라 빨라야 한다: 시트 전체를 읽고 다시 쓰는 editItem_ 대신
  // 번호 칸만 읽어 그 줄을 찾고, 그 한 줄만 고친다(10/15 로딩이 길다는 사용자 피드백)
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  let it, was;
  try {
    const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET);
    const nos = sh.getLastRow() > 1 ? sh.getRange(2, 1, sh.getLastRow() - 1, 1).getDisplayValues() : [];
    const i = nos.findIndex(r => Number(r[0]) === no);
    if (i < 0) return linkPage_('일정표에서 빠진 일이에요', '이미 지웠거나 취소된 일이라 바꾸지 않았어요.', null);
    const range = sh.getRange(i + 2, 1, 1, FIELDS.length);
    const row = range.getDisplayValues()[0];
    it = {};
    FIELDS.forEach((f, k) => { it[f] = row[k]; });
    if (isTrue_(it.removed)) return linkPage_('일정표에서 빠진 일이에요', '이미 지웠거나 취소된 일이라 바꾸지 않았어요.', null);
    was = isTrue_(it.done);
    const want = p.a === 'done';
    if (was !== want) {
      row[FIELDS.indexOf('done')] = want ? 'true' : 'false';
      row[FIELDS.indexOf('doneAt')] = want ? now_() : '';
      row[FIELDS.indexOf('doneBy')] = want ? MAIL_DONE_BY : '';
      range.setNumberFormat('@').setValues([row]);
    }
  } finally {
    lock.releaseLock();
  }
  const name = (it.type !== MEMO && it.brand && String(it.title).indexOf(it.brand) !== 0 ? it.brand + ' ' : '') + it.title;
  if (p.a === 'done') return linkPage_(was ? '이미 완료돼 있어요' : '완료했어요', name, { a: 'undo', no: no, label: '되돌리기' });
  return linkPage_('되돌렸어요', name + ' — 다시 할 일로 돌아갔어요.', { a: 'done', no: no, label: '다시 완료' });
}

function sign_(msg) {
  const token = PropertiesService.getScriptProperties().getProperty('TOKEN');
  return Utilities.computeHmacSha256Signature(msg, token)
    .map(b => ('0' + (b & 0xff).toString(16)).slice(-2)).join('');
}

function checkSig_(msg, sig) {
  return !!sig && sign_(msg) === String(sig);
}

function linkPage_(head, body, again) {
  const url = ScriptApp.getService().getUrl();
  const btn = 'display:inline-block;margin:6px 4px 0;padding:10px 16px;border-radius:999px;font-weight:800;text-decoration:none;font-size:15px;';
  const againHtml = again
    ? '<a href="' + esc_(url + '?a=' + again.a + '&no=' + again.no + '&sig=' + sign_(again.a + ':' + again.no)) + '" target="_top" style="' + btn + 'border:1.5px solid #23242b;color:#23242b;">' + esc_(again.label) + '</a>'
    : '';
  const html = '<div style="font-family:-apple-system,\'Apple SD Gothic Neo\',sans-serif;max-width:420px;margin:48px auto;padding:0 20px;text-align:center;color:#23242b;word-break:keep-all;">'
    + '<div style="font-size:22px;font-weight:800;">' + esc_(head) + '</div>'
    + '<div style="font-size:15px;color:#7b7e8c;margin:10px 0 18px;line-height:1.5;">' + esc_(body) + '</div>'
    + '<a href="' + esc_(url) + '" target="_top" style="' + btn + 'background-color:#23242b;color:#ffffff;">일정표 열기</a>' + againHtml + '</div>';
  return HtmlService.createHtmlOutput(html).setTitle('트란시스 일정표')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1');
}

function esc_(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** 화면을 다시 열거나 돌아왔을 때 최신 상태를 받아 간다 */
function getPublicData() {
  return publicData_();
}

// ---------- 내부 함수 ----------

// 지운 일은 화면에 안 보내지만, 트란시스가 취소로 뺀 일은 완료 칸에 남기려고 cancelled 표시를 붙여 보낸다
function publicData_() {
  const items = readItems_().filter(it => !it.removed || isCancelled_(it)).map(it => {
    const o = {};
    PUBLIC_FIELDS.forEach(f => { o[f] = it[f]; });
    o.cancelled = isCancelled_(it);
    return o;
  });
  return { updatedAt: getMeta_('updatedAt'), sample: isTrue_(getMeta_('sample')), items: items, changes: getChanges_() };
}

// 최근 바뀐 일정 기록: 메타 시트 'changeLog'에 JSON 목록으로. 정리할 때마다 쌓고, 사흘 넘은 것과 50개 넘는 것은 버린다.
// 일정표는 그중 최근 24시간 치만 보여 준다(화면 쪽에서 거른다). 입금자명·금액은 담지 않는다
function addChanges_(list) {
  const at = now_();
  const keep = Date.now() - 3 * 86400000;
  const log = getChanges_().filter(c => new Date(c.at).getTime() > keep)
    .concat(list.map(c => Object.assign({ at: at }, c)));
  setMeta_('changeLog', JSON.stringify(log.slice(-50)));
}

function getChanges_() {
  try {
    const log = JSON.parse(getMeta_('changeLog') || '[]');
    return Array.isArray(log) ? log : [];
  } catch (err) {
    return [];
  }
}

/** 앞으로 FIXED_DAYS 안에 고정 일정이 빠진 날이 있으면 items에 새 줄로 넣는다 */
function addFixed_(items) {
  const keys = {};
  items.forEach(it => { keys[it.key] = true; });
  let maxNo = items.reduce((m, it) => Math.max(m, it.no), 0);
  for (let d = 0; d < FIXED_DAYS; d++) {
    const day = new Date(Date.now() + d * 86400000);
    const date = Utilities.formatDate(day, TZ, 'yyyy-MM-dd');
    const weekday = Number(Utilities.formatDate(day, TZ, 'u'));
    FIXED.forEach(f => {
      const key = f.key + '-' + date;
      if (f.weekday !== weekday || keys[key]) return;
      items.push({ no: ++maxNo, key: key, type: f.type, brand: f.brand, title: f.title, date: date, time: f.time,
                   done: false, doneAt: '', source: '', payer: '', amount: '', updatedAt: now_() });
      keys[key] = true;
    });
  }
}

function isCancelled_(it) {
  return it.removed && it.doneBy === CANCELLED;
}

function readItems_() {
  const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET);
  if (!sh || sh.getLastRow() < 2) return [];
  const rows = sh.getRange(2, 1, sh.getLastRow() - 1, FIELDS.length).getDisplayValues();
  return rows.filter(r => r[0] !== '').map(r => {
    const o = {};
    FIELDS.forEach((f, i) => { o[f] = r[i]; });
    o.no = Number(o.no);
    o.done = isTrue_(o.done);
    o.removed = isTrue_(o.removed);
    o.tentative = isTrue_(o.tentative);
    o.manual = isTrue_(o.manual);
    return o;
  });
}

function writeItems_(items) {
  const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET);
  // 머리줄도 매번 맞춘다 (칸이 늘어난 뒤 처음 저장할 때 새 칸 이름이 생기게)
  sh.getRange(1, 1, 1, FIELDS.length).setValues([FIELDS]).setFontWeight('bold');
  if (sh.getLastRow() > 1) sh.getRange(2, 1, sh.getLastRow() - 1, FIELDS.length).clearContent();
  if (!items.length) return;
  items.sort((a, b) => a.no - b.no);
  const rows = items.map(it => FIELDS.map(f => {
    if (f === 'done' || f === 'removed' || f === 'tentative' || f === 'manual') return it[f] ? 'true' : 'false';
    return it[f] === undefined || it[f] === null ? '' : String(it[f]);
  }));
  sh.getRange(2, 1, rows.length, FIELDS.length).setNumberFormat('@').setValues(rows);
}

function clean_(raw) {
  const s = v => (v === undefined || v === null ? '' : String(v).trim());
  return {
    key: s(raw.key), type: s(raw.type), brand: s(raw.brand), title: s(raw.title),
    date: s(raw.date), time: s(raw.time), source: s(raw.source), payer: s(raw.payer), amount: s(raw.amount),
    tentative: isTrue_(raw.tentative),
  };
}

// 시트가 'true'라는 글자를 참/거짓 값(TRUE)으로 바꿔 저장하는 일이 있어서 대소문자 없이 비교한다
function isTrue_(v) {
  return String(v).toLowerCase() === 'true';
}

function checkToken_(t) {
  const token = PropertiesService.getScriptProperties().getProperty('TOKEN');
  return !!token && t === token;
}

function getQueue_() {
  try {
    const q = JSON.parse(getMeta_('fullQueue') || '[]');
    return Array.isArray(q) ? q.map(String) : [];
  } catch (err) {
    return [];
  }
}

function getMeta_(k) {
  const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(META);
  if (!sh || sh.getLastRow() < 1) return '';
  const row = sh.getRange(1, 1, sh.getLastRow(), 2).getDisplayValues().filter(r => r[0] === k)[0];
  return row ? row[1] : '';
}

function setMeta_(k, v) {
  const sh = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(META);
  const n = sh.getLastRow();
  const keys = n ? sh.getRange(1, 1, n, 1).getDisplayValues().map(r => r[0]) : [];
  const i = keys.indexOf(k);
  if (i >= 0) sh.getRange(i + 1, 2).setValue(String(v));
  else sh.appendRow([k, String(v)]);
}

function now_() {
  return Utilities.formatDate(new Date(), TZ, "yyyy-MM-dd'T'HH:mm:ssXXX");
}

function offsetDate_(days) {
  return Utilities.formatDate(new Date(Date.now() + days * 86400000), TZ, 'yyyy-MM-dd');
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
