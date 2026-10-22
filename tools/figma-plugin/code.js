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

function hasVideo(n) {
  if (!('fills' in n) || n.fills === figma.mixed) return false;
  return n.fills.some(p => p.type === 'VIDEO' && p.visible !== false);
}

// 단색으로 칠한 레이어면 #rrggbb, 아니면(그라데이션·여러 색) null
function solidColor(n) {
  if (!('fills' in n) || n.fills === figma.mixed) return null;
  const f = n.fills.find(p => p.type === 'SOLID' && p.visible !== false);
  if (!f) return null;
  const hex = v => Math.round(v * 255).toString(16).padStart(2, '0');
  return `#${hex(f.color.r)}${hex(f.color.g)}${hex(f.color.b)}`;
}

// 어두운 막: 글자가 잘 읽히게 사진 위·아래에 깐 "검정 → 투명" 그라데이션. 글자를 지운 카드에는 쓸모없는 검은 띠로 남는다(10/22 사용자: "어두운 그라데이션만 없어지면 돼").
// 색이 있는 그라데이션(배경 등)은 건드리지 않도록 "검정에 가까운 색에서 투명으로" 사라지는 것만 막으로 본다
// ---- overlay-rule-begin
const DARK = 0.22; // r·g·b가 모두 이 값 이하면 "검정에 가깝다"
function isDarkFadePaint(p) {
  if (!p || p.visible === false || p.type !== 'GRADIENT_LINEAR' || !p.gradientStops || p.gradientStops.length < 2) return false;
  const op = p.opacity === undefined ? 1 : p.opacity;
  let strong = false, clear = false;
  for (const st of p.gradientStops) {
    const a = (st.color.a === undefined ? 1 : st.color.a) * op;
    const dark = st.color.r <= DARK && st.color.g <= DARK && st.color.b <= DARK;
    if (a > 0.05 && !dark) return false; // 눈에 보이는 색이 검정이 아니면 막이 아니다
    if (a >= 0.35 && dark) strong = true; // 어딘가는 꽤 진한 검정이고
    if (a <= 0.15) clear = true;          // 어딘가는 거의 투명해야(= 사라지는 막) 한다
  }
  return strong && clear;
}
const paintsOf = n => ('fills' in n && Array.isArray(n.fills)) ? n.fills : []; // figma.mixed는 배열이 아니라서 빈 목록이 된다
// 복사본에서 어두운 막을 없앤다. 막만 칠한 레이어는 통째로 숨기고, 사진과 한 레이어에 같이 칠해져 있으면 막 칠만 뺀다.
// 작은 장식(버튼 그림자 등)을 건드리지 않도록 카드 폭의 40%·높이의 8% 이상인 레이어만 본다. 없앤 것의 목록을 돌려준다
function stripDarkFades(root) {
  const removed = [];
  root.findAll(n => n.visible !== false && n.type !== 'TEXT' && n.width >= root.width * 0.4 && n.height >= root.height * 0.08).forEach(n => {
    const fills = paintsOf(n);
    if (!fills.some(isDarkFadePaint)) return;
    const rest = fills.filter(p => p.visible !== false && !isDarkFadePaint(p));
    if (rest.length === 0) { n.visible = false; removed.push({ layer: n.name, how: 'layer' }); }
    else { n.fills = fills.filter(p => !isDarkFadePaint(p)); removed.push({ layer: n.name, how: 'paint' }); }
  });
  return removed;
}
// ---- overlay-rule-end

function readFrame(fr) {
  const nodes = [];
  fr.children.forEach(c => walk(c, nodes));
  const texts = [];
  const images = [];
  const videos = [];
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
    // 영상이 채워진 칸: 영상 파일은 플러그인이 꺼낼 수 없어서 위치만 적는다 — 셰프가 탭에서 받은 영상의 장면을 이 칸 비율로 자른다
    if (hasVideo(n)) videos.push(Object.assign({ id: n.id, name: n.name }, box(n)));
  });
  return Object.assign({ id: fr.id, name: fr.name }, box(fr), { texts: texts, images: images, videos: videos });
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
  const dropDark = msg.dropDark !== false; // 기본 켬: 어두운 그라데이션(검은 띠) 지우기
  const got = pickGroup();
  if (got.error) { figma.ui.postMessage({ type: 'error', error: got.error }); return; }
  const g = got.group;
  const frames = got.frames.map(readFrame);
  const scan = {
    tool: 'transis-cardnews-reader',
    version: 3, // 3 = 카드마다 composite(글자 뺀 카드 JPG), 사진 원본은 표지 것만, 표지 export는 JPG. overlaysHidden은 카드마다 선택적으로 붙는 참고 기록

    scannedAt: new Date().toISOString(),
    file: figma.fileKey || null,  // 개발용 플러그인에서만 채워진다 (manifest의 enablePrivatePluginApi)
    page: figma.currentPage.name,
    group: Object.assign({ id: g.id, name: g.name }, box(g)),
    frames: frames,
  };
  const files = [];
  try {
    // 표지 = 책 읽는 순서의 첫 장 = 윗줄 맨 왼쪽 카드
    // (764처럼 두 줄로 놓인 묶음은 맨 왼쪽만 보면 아랫줄 첫 장과 겹친다. fetch의 reading_order와 같은 기준)
    const top = Math.min(...got.frames.map(f => box(f).y));
    const cover = got.frames.filter(f => box(f).y - top < f.height / 2)
      .reduce((a, b) => (box(b).x < box(a).x ? b : a));
    // 사진 원본은 표지 것만 담는다: 글 머리 커버에 쓰는 "글자 없는 표지 사진"이라서.
    // 카드 사진은 아래 "글자 뺀 카드"에서 잘라 쓴다(10/17 사용자 B — 수동 작업처럼 전부 JPEG, zip 16MB → 절반 이하).
    // 같은 사진이 여러 레이어에 쓰여도 hash가 같아서 한 번만 담는다
    const coverScan = frames[got.frames.indexOf(cover)];
    const hashes = Array.from(new Set(coverScan.images.map(im => im.hash)));
    for (let i = 0; i < hashes.length; i++) {
      say(`표지 사진 원본 꺼내는 중… ${i + 1}/${hashes.length}`);
      const img = figma.getImageByHash(hashes[i]);
      if (!img) continue;
      const bytes = await img.getBytesAsync();
      files.push({ name: `figma/images/${hashes[i]}.${ext(bytes)}`, bytes: bytes });
    }
    // 대표 이미지 = 글자까지 다 있는 표지 (목록·공유 미리보기용). 사이트에선 어차피 JPEG로 바뀌어서 처음부터 JPG로
    say('표지 내보내는 중…');
    const coverJpg = await cover.exportAsync({ format: 'JPG', constraint: { type: 'SCALE', value: 1 } });
    files.push({ name: 'figma/cover-export.jpg', bytes: coverJpg });
    // 카드 미리보기: 디자인을 보고 사이트 글을 맞추고, 사이트 화면과 나란히 대조하는 용도라 작게(가로 500px)
    for (let i = 0; i < got.frames.length; i++) {
      const fr = got.frames[i];
      say(`카드 미리보기 ${i + 1}/${got.frames.length}…`);
      const jpg = await fr.exportAsync({ format: 'JPG', constraint: { type: 'WIDTH', value: 500 } });
      files.push({ name: `figma/cards/${fr.name}.jpg`, bytes: jpg });
    }
    // 글자 뺀 카드: 카드 사진은 전부 여기서 나온다. 10/16 주간 총정리 때 손으로 만든 "사진 칸 합성본"과 같은 것
    // (말풍선·작은 사진·좌우 두 장이 카드 모습 그대로 — 10/17 744는 2~6번이 다 콜라주였다).
    // 원본 카드는 건드리지 않게 복사본에서 글자만 숨겨 내보내고 복사본은 바로 지운다. 사진 칸 자르기는 fetch가 한다
    for (let i = 0; i < got.frames.length; i++) {
      const fr = got.frames[i];
      if (!frames[i].images.length) continue; // 사진이 없는 카드(글자만 있는 끝 카드 등)는 자를 것도 없다
      say(`글자 뺀 카드 ${i + 1}/${got.frames.length}…`);
      const copy = fr.clone();
      try {
        copy.findAll(n => n.type === 'TEXT').forEach(t => { t.visible = false; });
        // 글자와 같이 쓸모없어진 어두운 막도 지운다. 무엇을 지웠는지 scan.json에 남겨서 나중에 눈으로 확인할 수 있게 한다
        // 먼저 카드 안의 그라데이션 레이어를 참고용으로 적어 둔다(검은 띠가 안 지워질 때 어떤 구조인지 알려고)
        const seen = copy.findAll(n => n.type !== 'TEXT' && paintsOf(n).some(p => p.type && p.type.indexOf('GRADIENT') === 0));
        if (seen.length) {
          frames[i].gradientLayers = seen.slice(0, 8).map(n => ({ layer: n.name, type: n.type, w: Math.round(n.width), h: Math.round(n.height),
            fills: paintsOf(n).map(p => p.type + (isDarkFadePaint(p) ? '(어두운 막)' : '')) }));
        }
        if (dropDark) {
          const gone = stripDarkFades(copy);
          if (gone.length) frames[i].overlaysHidden = gone;
        }
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
