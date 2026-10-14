/**
 * 트란시스 발신함 — transis.trendportal@gmail.com 소유 Apps Script 웹앱
 *
 * 트란시스가 contact@를 읽는 Gmail 커넥터로 메일까지 보내면 성현 님 작업메일 보낸편지함에 리마인더가 매일 쌓여서(10/14),
 * 보내는 일만 이 발신 전용 계정으로 옮겼다. transis.py가 만든 메일 HTML을 그대로 받아 MailApp으로 보낸다.
 * 덤으로 Gmail 커넥터의 send_message처럼 <img>·background 줄임 표기를 지우지 않고, 에이전트가 54KB HTML을 다시 쓸 일도 없다.
 *
 *   doPost { token, to, bcc?, subject, html, text, id? } → { ok, duplicate?, remaining? }
 *
 * 받는 주소는 아래 목록만 허락한다. 토큰이 새더라도 이 계정이 아무에게나 메일을 보내는 통로가 되지 않게.
 */

const ALLOWED_TO = ['kwonross5@gmail.com', 'yd30421@gmail.com'];
const ALLOWED_BCC = ['yd30421@gmail.com'];
const SENDER_NAME = '트란시스';
// 같은 id는 한 번만 보낸다. 웹앱이 응답 도중 끊기면 transis.py가 다시 보내는데(재시도), 그때 같은 메일이 두 통 가지 않게
const SENT_PREFIX = 'sent:';
const KEEP_DAYS = 7;

/** 처음 한 번만 실행: 비밀 토큰을 만들어 로그에 찍고, 지환 님께 시험 메일을 보낸다(메일 보내기 권한 승인도 이때 뜬다) */
function setup() {
  const props = PropertiesService.getScriptProperties();
  let token = props.getProperty('TOKEN');
  if (!token) {
    token = Utilities.getUuid().replace(/-/g, '');
    props.setProperty('TOKEN', token);
  }
  MailApp.sendEmail({
    to: 'yd30421@gmail.com',
    name: SENDER_NAME,
    subject: '[트란시스 발신함] 시험 메일',
    htmlBody: '<p style="font-size:15px;">트란시스 발신함이 메일을 보낼 수 있어요.</p>',
  });
  Logger.log('TOKEN = ' + token);
  Logger.log('remaining daily quota = ' + MailApp.getRemainingDailyQuota());
}

function doPost(e) {
  let body;
  try {
    body = JSON.parse(e.postData.contents);
  } catch (err) {
    return json_({ ok: false, error: 'bad json' });
  }
  if (!checkToken_(body.token)) return json_({ ok: false, error: 'bad token' });

  const to = list_(body.to);
  const bcc = list_(body.bcc);
  if (!to.length || to.some(a => ALLOWED_TO.indexOf(a) < 0)) return json_({ ok: false, error: 'to not allowed' });
  if (bcc.some(a => ALLOWED_BCC.indexOf(a) < 0)) return json_({ ok: false, error: 'bcc not allowed' });
  if (!body.subject || !body.html) return json_({ ok: false, error: 'subject and html are required' });

  // 재시도 두 개가 거의 동시에 들어와도 한 통만 나가게 잠그고 확인한다
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    const props = PropertiesService.getScriptProperties();
    if (body.id && props.getProperty(SENT_PREFIX + body.id)) {
      return json_({ ok: true, duplicate: true });
    }
    const mail = { to: to.join(','), name: SENDER_NAME, subject: String(body.subject), htmlBody: String(body.html), body: String(body.text || '') };
    if (bcc.length) mail.bcc = bcc.join(',');
    MailApp.sendEmail(mail);
    if (body.id) {
      props.setProperty(SENT_PREFIX + body.id, String(Date.now()));
      prune_(props);
    }
    return json_({ ok: true, remaining: MailApp.getRemainingDailyQuota() });
  } finally {
    lock.releaseLock();
  }
}

// 보낸 기록은 일주일만 둔다 (스크립트 속성이 끝없이 늘지 않게)
function prune_(props) {
  const cut = Date.now() - KEEP_DAYS * 86400000;
  const all = props.getProperties();
  Object.keys(all).forEach(k => {
    if (k.indexOf(SENT_PREFIX) === 0 && Number(all[k]) < cut) props.deleteProperty(k);
  });
}

function list_(v) {
  if (!v) return [];
  return (Array.isArray(v) ? v : [v]).map(a => String(a).trim().toLowerCase()).filter(Boolean);
}

function checkToken_(t) {
  const token = PropertiesService.getScriptProperties().getProperty('TOKEN');
  return !!token && t === token;
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
