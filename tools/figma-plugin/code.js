// 트란시스 카드뉴스 읽기 — 피그마 앱 안에서 도는 "읽기 담당" (10/17)
//
// 선택한 묶음(카드 프레임 여러 장이 든 프레임)에서 카드마다 글자 원문·위치·크기·사진 레이어를 모아
// 화면(ui.html)으로 넘긴다. 화면이 그걸 figma/scan.json에 담아 zip으로 내려받게 한다.
// 무엇이 제목이고 본문인지 같은 판단은 여기서 하지 않는다 — 그 규칙은 tools/cardnews/cardnews.py(fetch) 한곳에만 둔다.
// 피그마 API를 부르는 게 아니라 앱 안에서 직접 읽어서, MCP·REST의 월 20회 한도와 상관없다.

figma.showUI(__html__, { width: 340, height: 280 });

// 카드 한 장이 될 수 있는 레이어 종류. 실제 파일은 묶음도 카드도 '그룹'이었다(10/17 트렌드포털802)
const CARD_TYPES = ['FRAME', 'GROUP', 'COMPONENT', 'INSTANCE'];
// 카드는 인스타 세로형 4:5(2000×2500, 1080×1350). 종류만 보면 카드 안의 꾸밈 그룹도 카드로 착각해서 모양으로 거른다
const isCard = n => CARD_TYPES.includes(n.type) && n.visible !== false && n.width > 0 && Math.abs(n.height / n.width - 1.25) < 0.05;

const round = v => Math.round(v * 100) / 100;

// 좌표는 MCP 메타데이터와 같은 절대 좌표로 (fetch의 정렬 규칙이 그 기준이라)
function box(n) {
  const b = n.absoluteBoundingBox;
  if (!b) return { x: 0, y: 0, width: round(n.width), height: round(n.height) };
  return { x: round(b.x), y: round(b.y), width: round(b.width), height: round(b.height) };
}

// 꺼 둔 레이어(안 쓰는 시안 등)는 카드에 안 보이는 글자라서 그 아래까지 통째로 건너뛴다
function walk(node, out) {
  if (node.visible === false) return;
  out.push(node);
  if ('children' in node) node.children.forEach(c => walk(c, out));
}

// 사진이 채워진 레이어면 그 사진의 이름표(hash). 사진 원본을 꺼낼 때 이걸로 찾는다
function imageHash(n) {
  if (!('fills' in n) || n.fills === figma.mixed) return null;
  const f = n.fills.find(p => p.type === 'IMAGE' && p.visible !== false);
  return f ? f.imageHash : null;
}

// 단색으로 칠한 레이어면 #rrggbb, 아니면(그라데이션·여러 색) null
function solidColor(n) {
  if (!('fills' in n) || n.fills === figma.mixed) return null;
  const f = n.fills.find(p => p.type === 'SOLID' && p.visible !== false);
  if (!f) return null;
  const hex = v => Math.round(v * 255).toString(16).padStart(2, '0');
  return `#${hex(f.color.r)}${hex(f.color.g)}${hex(f.color.b)}`;
}

function readFrame(fr) {
  const nodes = [];
  fr.children.forEach(c => walk(c, nodes));
  const texts = [];
  const images = [];
  nodes.forEach(n => {
    if (n.type === 'TEXT') {
      // characters = 카드에 실제로 적힌 글자 (줄바꿈 그대로). 레이어 이름은 바뀌었을 수 있어서 따로 남긴다
      // 글꼴·색·정렬은 사이트 글을 원본 카드 디자인에 맞출 때 쓴다 (10/17 사용자: "원본 카드 디자인과 최대한 비슷하게")
      texts.push(Object.assign({ id: n.id, name: n.name, characters: n.characters,
        fontSize: n.fontSize === figma.mixed ? null : n.fontSize,
        font: n.fontName === figma.mixed ? null : n.fontName,
        color: solidColor(n), align: n.textAlignHorizontal }, box(n)));
      return;
    }
    const hash = imageHash(n);
    if (hash) images.push(Object.assign({ id: n.id, name: n.name, hash: hash }, box(n)));
  });
  return Object.assign({ id: fr.id, name: fr.name }, box(fr), { texts: texts, images: images });
}

// 묶음 고르기: 묶음을 고르면 그대로, 카드 한 장을 고르면 그 카드가 든 묶음으로
function pickGroup() {
  const sel = figma.currentPage.selection;
  if (sel.length !== 1) return { error: '묶음 하나만 선택해 주세요 (카드 여러 장이 든 프레임)' };
  let g = sel[0];
  const cards = node => ('children' in node ? node.children : []).filter(isCard);
  if (cards(g).length < 2 && g.parent && cards(g.parent).length >= 2) g = g.parent;
  const frames = cards(g);
  if (frames.length < 2) {
    // 왜 못 찾았는지 알 수 있게 안에 든 레이어 종류·크기를 몇 개 보여 준다
    const kids = ('children' in g ? g.children : []).slice(0, 4)
      .map(c => `${c.type} ${Math.round(c.width)}×${Math.round(c.height)}`).join(', ');
    return { error: `'${g.name}' 안에서 4:5 카드를 못 찾았어요. 카드들이 든 묶음을 골라 주세요${kids ? ` (안에 든 것: ${kids})` : ''}` };
  }
  return { group: g, frames: frames };
}

function sendSelection() {
  const got = pickGroup();
  figma.ui.postMessage(got.error ? { type: 'selection', error: got.error }
    : { type: 'selection', name: got.group.name, count: got.frames.length });
}

figma.on('selectionchange', sendSelection);
sendSelection();

// 파일 앞 몇 바이트로 사진 형식을 알아낸다 (피그마는 원본 형식 그대로 돌려준다)
function ext(b) {
  if (b[0] === 0x89 && b[1] === 0x50) return 'png';
  if (b[0] === 0xFF && b[1] === 0xD8) return 'jpg';
  if (b[0] === 0x47 && b[1] === 0x49) return 'gif';
  if (b[8] === 0x57 && b[9] === 0x45 && b[10] === 0x42 && b[11] === 0x50) return 'webp';
  return 'bin';
}

const say = text => figma.ui.postMessage({ type: 'progress', text: text });

figma.ui.onmessage = async msg => {
  if (msg.type !== 'scan') return;
  const got = pickGroup();
  if (got.error) { figma.ui.postMessage({ type: 'error', error: got.error }); return; }
  const g = got.group;
  const frames = got.frames.map(readFrame);
  const scan = {
    tool: 'transis-cardnews-reader',
    version: 3, // 3 = 사진이 여러 장인 카드에 composite(글자 뺀 카드 그림)

    scannedAt: new Date().toISOString(),
    file: figma.fileKey || null,  // 개발용 플러그인에서만 채워진다 (manifest의 enablePrivatePluginApi)
    page: figma.currentPage.name,
    group: Object.assign({ id: g.id, name: g.name }, box(g)),
    frames: frames,
  };
  const files = [];
  try {
    // 사진 원본: 카드에서 잘라 보여 준 구도가 아니라 올린 파일 그대로 (사용자 결정 A, 10/17)
    // 같은 사진이 여러 레이어에 쓰여도 hash가 같아서 한 번만 담는다
    const hashes = Array.from(new Set([].concat(...frames.map(f => f.images.map(im => im.hash)))));
    for (let i = 0; i < hashes.length; i++) {
      say(`사진 원본 꺼내는 중… ${i + 1}/${hashes.length}`);
      const img = figma.getImageByHash(hashes[i]);
      if (!img) continue;
      const bytes = await img.getBytesAsync();
      files.push({ name: `figma/images/${hashes[i]}.${ext(bytes)}`, bytes: bytes });
    }
    // 대표 이미지 = 글자까지 다 있는 표지. 표지는 책 읽는 순서의 첫 장 = 윗줄 맨 왼쪽 카드
    // (764처럼 두 줄로 놓인 묶음은 맨 왼쪽만 보면 아랫줄 첫 장과 겹친다. fetch의 reading_order와 같은 기준)
    say('표지 내보내는 중…');
    const top = Math.min(...got.frames.map(f => box(f).y));
    const cover = got.frames.filter(f => box(f).y - top < f.height / 2)
      .reduce((a, b) => (box(b).x < box(a).x ? b : a));
    const png = await cover.exportAsync({ format: 'PNG', constraint: { type: 'SCALE', value: 1 } });
    files.push({ name: 'figma/cover-export.png', bytes: png });
    // 카드 미리보기: 디자인을 보고 사이트 글을 맞추고, 사이트 화면과 나란히 대조하는 용도라 작게(가로 500px)
    for (let i = 0; i < got.frames.length; i++) {
      const fr = got.frames[i];
      say(`카드 미리보기 ${i + 1}/${got.frames.length}…`);
      const jpg = await fr.exportAsync({ format: 'JPG', constraint: { type: 'WIDTH', value: 500 } });
      files.push({ name: `figma/cards/${fr.name}.jpg`, bytes: jpg });
    }
    // 글자 뺀 카드: 사진이 여러 장 겹친 카드(말풍선·작은 사진·좌우 두 장)는 원본 한 장으론 카드 모습이 안 나온다.
    // 10/16 주간 총정리 때 손으로 만든 "사진 칸 합성본"과 같은 것을 여기서 만든다(10/17 744: 2~6번이 다 콜라주였다).
    // 원본 카드는 건드리지 않게 복사본에서 글자만 숨겨 내보내고 복사본은 바로 지운다. 사진 칸 자르기는 fetch가 한다
    for (let i = 0; i < got.frames.length; i++) {
      const fr = got.frames[i];
      if (frames[i].images.length < 2) continue; // 사진 한 장짜리 카드는 원본이 더 선명해서 필요 없다
      say(`글자 뺀 카드 ${i + 1}/${got.frames.length}…`);
      const copy = fr.clone();
      try {
        copy.findAll(n => n.type === 'TEXT').forEach(t => { t.visible = false; });
        // 1080px짜리 옛 카드도 사이트 사진이 흐리지 않게 가로 2000px 이상으로
        const constraint = fr.width < 2000 ? { type: 'WIDTH', value: 2000 } : { type: 'SCALE', value: 1 };
        const bytes = await copy.exportAsync({ format: 'JPG', constraint: constraint });
        const name = `figma/composites/${fr.name}.jpg`;
        files.push({ name: name, bytes: bytes });
        frames[i].composite = name;
      } finally {
        copy.remove();
      }
    }
  } catch (e) {
    figma.ui.postMessage({ type: 'error', error: `사진을 꺼내다 멈췄어요: ${e.message || e}` });
    return;
  }
  figma.ui.postMessage({ type: 'scan', scan: scan, files: files });
};
