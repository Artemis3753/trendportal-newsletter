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
const FIELDS = ['no', 'key', 'type', 'brand', 'title', 'date', 'time', 'done', 'doneAt', 'source', 'payer', 'amount', 'updatedAt', 'doneBy', 'removed'];
const PUBLIC_FIELDS = ['no', 'type', 'brand', 'title', 'date', 'time', 'done', 'doneAt', 'doneBy'];
const TYPES = ['upload', 'draft', 'shoot', 'pay'];
// 메모: 성현 님이 일정표에서 직접 적는 할 일. 트란시스는 보내지도 바꾸지도 않는다(key가 'memo-'로 시작)
const MEMO = 'memo';
const TZ = 'Asia/Seoul';

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
                   fullScanAt: getMeta_('fullScanAt'), items: readItems_() });
  }
  const t = HtmlService.createTemplateFromFile('Index');
  // </script> 같은 글자가 들어와도 스크립트가 끊기지 않게 '<'를 바꿔서 넣는다
  t.dataJson = JSON.stringify(publicData_()).replace(/</g, '\\u003c');
  return t.evaluate()
    .setTitle('트란시스 일정표')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1, viewport-fit=cover');
}

/**
 * 트란시스가 일정을 보낸다: { token, updatedAt, reset?, scan?, items: [{ key, type, brand, title, date, time?, source?, payer?, amount?, done? }] }
 * - date는 YYYY-MM-DD 또는 ''(일정 미정)
 * - 같은 key가 있으면 내용만 갱신하고 번호·완료·삭제 여부는 그대로 둔다 (성현 님이 누른 ✓·삭제를 덮어쓰지 않게)
 * - scan: 'FULL'이면 전체 훑기를 마친 시각을 남긴다 (다음부터는 최근 메일만 읽어도 되는지 판단용)
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
    const byKey = {};
    items.forEach(it => { byKey[it.key] = it; });
    let maxNo = items.reduce((m, it) => Math.max(m, it.no), 0);
    const errors = [];

    (body.items || []).forEach((raw, i) => {
      const it = clean_(raw);
      if (!it.key || TYPES.indexOf(it.type) < 0 || (it.date && !/^\d{4}-\d{2}-\d{2}$/.test(it.date))) {
        errors.push('item ' + i + ': key and type(' + TYPES.join('/') + ') are required; date must be YYYY-MM-DD or empty');
        return;
      }
      const old = byKey[it.key];
      if (old) {
        ['type', 'brand', 'title', 'date', 'time', 'source', 'payer', 'amount'].forEach(f => { old[f] = it[f]; });
        if (raw.done === true && !old.done) { old.done = true; old.doneAt = now_(); }
        old.updatedAt = now_();
      } else {
        it.no = ++maxNo;
        it.done = raw.done === true;
        it.doneAt = it.done ? now_() : '';
        it.updatedAt = now_();
        items.push(it);
        byKey[it.key] = it;
      }
    });

    writeItems_(items);
    setMeta_('updatedAt', body.updatedAt || now_());
    setMeta_('sample', body.reset ? 'false' : getMeta_('sample') || 'false');
    if (body.scan === 'FULL' && errors.length === 0) setMeta_('fullScanAt', now_());
    return json_({ ok: errors.length === 0, errors: errors, items: readItems_() });
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

/** 완료 목록의 삭제 버튼. 끝낸 일만 지울 수 있다. removed = false면 되돌리기 */
function removeItem(no, removed) {
  return editItem_(no, it => {
    if (removed !== false && !it.done) throw new Error('only done items can be removed');
    it.removed = removed !== false;
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

/** 화면을 다시 열거나 돌아왔을 때 최신 상태를 받아 간다 */
function getPublicData() {
  return publicData_();
}

// ---------- 내부 함수 ----------

function publicData_() {
  const items = readItems_().filter(it => !it.removed).map(it => {
    const o = {};
    PUBLIC_FIELDS.forEach(f => { o[f] = it[f]; });
    return o;
  });
  return { updatedAt: getMeta_('updatedAt'), sample: isTrue_(getMeta_('sample')), items: items };
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
    if (f === 'done' || f === 'removed') return it[f] ? 'true' : 'false';
    return it[f] === undefined || it[f] === null ? '' : String(it[f]);
  }));
  sh.getRange(2, 1, rows.length, FIELDS.length).setNumberFormat('@').setValues(rows);
}

function clean_(raw) {
  const s = v => (v === undefined || v === null ? '' : String(v).trim());
  return {
    key: s(raw.key), type: s(raw.type), brand: s(raw.brand), title: s(raw.title),
    date: s(raw.date), time: s(raw.time), source: s(raw.source), payer: s(raw.payer), amount: s(raw.amount),
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
