/*
 * TP 카드 효과 시안 v3: 기존 카드뉴스 목록 모양은 그대로 두고, 눌렀을 때의 반응과 눌러서 글로 들어갈 때의 펼침 효과만 더한다.
 * apple-design 스킬(원문)의 규칙을 옮긴 것: 스프링, 끊기 가능한 움직임, 손을 따라 줄어드는 닫기.
 */
(function () {
	'use strict';

	var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	// 참고 영상(릴스)을 60fps 화면 녹화로 프레임마다 재서 얻은 값(10/22):
	//  - 끌었다 놓아 닫힐 때: 감쇠비 약 1.0, 응답 약 0.32초, 목표를 넘지 않는다
	//  - 고무줄 당겼다 놓을 때: 감쇠비 약 0.78, 응답 약 0.5초, 약 2% 지나쳤다 돌아온다
	//  - 끄는 동안 크기: 아래로 화면 높이의 41% 끌 때 83% 크기 → 크기 = 1 - 거리 / (화면 높이 × 2.4)
	var SPR = {
		dismiss: { damping: 1, response: 0.32 },
		back: { damping: 0.78, response: 0.5 },
		open: { damping: 1, response: 0.4 }
	};
	var SHRINK = 2.4;

	/* =========================================================
	 * 공통 도구
	 * ========================================================= */

	// 스프링: "감쇠비(튕김)"와 "응답 시간(초)"으로 움직임을 정한다. 지속 시간이 아니라 성질을 정하는 것이라
	// 도중에 목표가 바뀌어도(손가락이 다시 잡아도) 지금 값과 속도에서 이어서 움직인다.
	// 질량 1 기준: 각진동수 w = 2π/응답, 강성 = w², 감쇠 = 2·감쇠비·w
	function spring(o, onUpdate, onDone) {
		var zeta = o.damping == null ? 1 : o.damping;
		var w = (2 * Math.PI) / (o.response || 0.35);
		var k = w * w;
		var c = 2 * zeta * w;
		var eps = o.eps == null ? 0.3 : o.eps;
		var veps = o.veps == null ? 8 : o.veps;
		var x = o.from;
		var v = o.velocity || 0;
		var target = o.to;
		var last = performance.now();
		var raf = 0;
		var stopped = false;
		function step(now) {
			if (stopped) return;
			// 탭 전환 등으로 프레임이 크게 건너뛰어도 계산이 터지지 않게 dt를 제한한다
			var dt = Math.min((now - last) / 1000, 1 / 30);
			last = now;
			v += (-k * (x - target) - c * v) * dt;
			x += v * dt;
			if (Math.abs(x - target) < eps && Math.abs(v) < veps) {
				onUpdate(target, 0);
				if (onDone) onDone();
				return;
			}
			onUpdate(x, v);
			raf = requestAnimationFrame(step);
		}
		raf = requestAnimationFrame(step);
		return {
			stop: function () {
				stopped = true;
				cancelAnimationFrame(raf);
				return { x: x, v: v };
			}
		};
	}

	// 최근 100ms의 위치로 손가락 속도(px/초)를 잰다. 손을 뗄 때의 속도를 스프링에 넘기려고 필요하다.
	function tracker() {
		var pts = [];
		return {
			add: function (x, y) {
				var t = performance.now();
				pts.push({ x: x, y: y, t: t });
				while (pts.length > 2 && t - pts[0].t > 100) pts.shift();
			},
			velocity: function () {
				if (pts.length < 2) return { x: 0, y: 0 };
				var a = pts[0];
				var b = pts[pts.length - 1];
				// 손가락이 멈춰 있다가 놓은 경우: 멈추기 직전의 속도를 쓰면 안 되므로 0으로 본다
				if (performance.now() - b.t > 80) return { x: 0, y: 0 };
				var dt = (b.t - a.t) / 1000;
				if (dt < 0.001) return { x: 0, y: 0 };
				return { x: (b.x - a.x) / dt, y: (b.y - a.y) / dt };
			}
		};
	}

	// 관성 투영: 지금 속도로 놓으면 어디에서 멈출지 미리 계산한다(감속률 0.998, UIScrollView와 같은 값).
	// 위치만 보면 "많이 끌었나"만 알지만, 이걸 쓰면 "빠르게 던졌나"도 함께 반영된다.
	function project(v) {
		var d = 0.998;
		return (v / 1000) * (d / (1 - d));
	}

	// 고무줄: 경계를 넘어 끌수록 점점 덜 따라온다(벽에 쾅 부딪히지 않고 저항이 생긴다)
	function rubber(x, dim) {
		var c = 0.55;
		return (x * dim * c) / (dim + c * x);
	}

	function clamp(n, a, b) {
		return Math.max(a, Math.min(b, n));
	}

	/* =========================================================
	 * 카드를 누르면 그 자리(사진)에서 화면 가득 펼쳐지고, 사진 부분을 아래로 끌면 손을 따라 줄어들며 카드 자리로 돌아간다.
	 * 목록 모양은 그대로다. 카드는 진짜 링크라서 스크립트가 안 돌아도 그냥 글로 이동한다.
	 * ========================================================= */
	// 시안 전용: 화면 왼쪽 아래의 작은 상자에서 두 가지 모양을 켜고 끌 수 있다(실제 적용 때는 하나로 정해서 없앤다)
	var OPT = { hideCover: true, author: true, layout: 'phone', fx: 'lines', fold: true, vfc: true };
	function initOptions() {
		var h = location.hash;
		OPT.hideCover = !/cover=off/.test(h); // 기본: 맨 위 사진을 원본으로 쓰고 본문 중복 제거
		OPT.author = !/author=0/.test(h);
		var fm = h.match(/fx=(fade|focus|wipe|lines|dust|ripple)/);
		if (fm) OPT.fx = fm[1];
		OPT.fold = !/fold=off/.test(h);
		OPT.vfc = !/vfc=0/.test(h); // 글을 내리면 사진 칸이 접힌다. 끄려면 #fold=off
		var lm = h.match(/layout=(split|blur|head|band|cine|phone)/);
		OPT.layout = lm ? lm[1] : 'phone'; // PC 배치: split(좌우 분할) / blur(위 큰 사진) / head(작은 사진 머리글) / band(사진 띠 위 제목)
		var box = document.createElement('div');
		box.className = 'tp-proto-opt';
		box.innerHTML =
			'<strong>시안 옵션</strong>' +
			'<label><input type="checkbox" data-k="hideCover"' + (OPT.hideCover ? ' checked' : '') + '> 맨 위 사진을 원본 사진(글자 없음)으로 · 본문 중복 제거</label>' +
			'<label><input type="checkbox" data-k="author"' + (OPT.author ? ' checked' : '') + '> 작성자 줄 (트렌드포털 로고·날짜)</label>' +
			'<label>PC 배치 <select data-s="layout"><option value="phone"' + (OPT.layout === 'phone' ? ' selected' : '') + '>⑤ 폰 스타일(좁은 세로 시트)</option><option value="cine"' + (OPT.layout === 'cine' ? ' selected' : '') + '>⓪ 시네마 머리글(사진 전체+제목)</option><option value="band"' + (OPT.layout === 'band' ? ' selected' : '') + '>① 사진 띠 위에 제목(스마트 크롭)</option><option value="blur"' + (OPT.layout === 'blur' ? ' selected' : '') + '>② 위 큰 사진(전체가 보임)</option><option value="head"' + (OPT.layout === 'head' ? ' selected' : '') + '>③ 작은 사진 머리글</option><option value="split"' + (OPT.layout === 'split' ? ' selected' : '') + '>④ 좌우 분할(이전)</option></select></label>' +
			'<label><input type="checkbox" data-k="fold"' + (OPT.fold ? ' checked' : '') + '> 글을 내리면 왼쪽 사진 칸이 접힘</label>' +
			'<label class="tp-dim">사진 어둡게 <input type="range" min="0" max="0.7" step="0.05" value="0.4"> <b>0.4</b></label>';
		document.documentElement.style.setProperty('--tp-hero-dim', '0.4');
		var rng = box.querySelector('input[type=range]');
		rng.addEventListener('input', function () {
			box.querySelector('.tp-dim b').textContent = rng.value;
			document.documentElement.style.setProperty('--tp-hero-dim', rng.value); // 열려 있는 시트에도 바로 반영된다
		});
		box.addEventListener('change', function (e) {
			var k = e.target.getAttribute('data-k');
			if (k) OPT[k] = e.target.checked;
			var s = e.target.getAttribute('data-s');
			if (s) OPT[s] = e.target.value;
		});
		document.body.appendChild(box);
	}
	function initExpand() {
		var sheet = null;
		function esc(t) {
			return String(t).replace(/[&<>"]/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
			});
		}
		function lerp(a, b, p) { return a + (b - a) * p; }

		// 목록 카드 안의 링크(사진·제목)를 누르면 펼친다. 분류 링크나 새 탭 열기(Cmd/Ctrl 클릭)는 그대로 둔다
		document.addEventListener(
			'click',
			function (e) {
				if (window.innerWidth >= 720 || sheet || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return; // PC는 막지 않는다 = 기본 링크 이동
				var li = e.target.closest('.wp-block-post-template.is-layout-grid > li');
				if (!li) return;
				var link = e.target.closest('a');
				if (link && (!li.contains(link) || link.closest('.wp-block-post-terms'))) return; // 분류 링크는 그대로
				// 링크가 아닌 카드의 빈 면을 눌러도 제목 링크를 누른 것으로 본다(버튼·입력칸은 제외)
				if (!link && e.target.closest('button, input, select, textarea, summary')) return;
				var titleA = li.querySelector('.wp-block-post-title a');
				var im = li.querySelector('.wp-block-post-featured-image img');
				if (!titleA || !im) return;
				var terms = li.querySelector('.wp-block-post-terms');
				var date = li.querySelector('.wp-block-post-date');
				e.preventDefault();
				openSheet({
					im: im,
					href: titleA.href,
					title: titleA.textContent.trim(),
					cat: terms ? terms.textContent.trim() : '',
					date: date ? date.textContent.trim() : '',
						tapX: e.clientX,
						tapY: e.clientY
					});
			},
			true
		);

		// 썸네일(글자·어두운 처리 포함)과 원본 사진을 캔버스로 비교해, 썸네일에만 있는 "글자"를 흰색+투명도 층으로 뽑는다.
		// 썸네일 ≈ 원본 × k(어둡게) + 글자 이므로, 글자 투명도 = (썸네일 − k·원본) / (255 − k·원본).
		// 제목 글자가 놓이는 가운데 띠(세로 40~68%)만 쓰고, 줄 단위로 나눈다. 실제 적용 때는 셰프(카드 변환)가 이 층을 미리 PNG로 만들어 올리는 편이 낫다
		function loadImg(u) {
			return new Promise(function (res, rej) {
				var i = new Image();
				i.crossOrigin = 'anonymous';
				i.onload = function () { res(i); };
				i.onerror = function () { rej(new Error('img')); };
				i.src = u;
			});
		}
		// 스마트 크롭: 사진의 세로 방향 "디테일 에너지"(인접 픽셀 밝기 차이의 합)를 구해, 보이는 창(visFrac)이 가장 선명한 구간을 덮도록 위치(%)를 정한다.
		// 흐린 배경(보케)은 에너지가 낮고 초점이 맞은 피사체는 높다
		function focalY(img, visFrac) {
			var w = 96, h = Math.max(8, Math.round(96 * img.naturalHeight / img.naturalWidth));
			var c = document.createElement('canvas');
			c.width = w; c.height = h;
			var x = c.getContext('2d', { willReadFrequently: true });
			x.drawImage(img, 0, 0, w, h);
			var d = x.getImageData(0, 0, w, h).data, row = new Float32Array(h), yy, xx;
			function lum(px, py) { var i = (py * w + px) * 4; return 0.3 * d[i] + 0.59 * d[i + 1] + 0.11 * d[i + 2]; }
			for (yy = 0; yy < h - 1; yy++) for (xx = 0; xx < w - 1; xx++) row[yy] += Math.abs(lum(xx + 1, yy) - lum(xx, yy)) + Math.abs(lum(xx, yy + 1) - lum(xx, yy));
			var win = Math.max(1, Math.min(h, Math.round(visFrac * h)));
			if (win >= h) return 50;
			var sum = 0, best = -1, bestAt = 0;
			for (yy = 0; yy < win; yy++) sum += row[yy];
			for (yy = 0; yy + win <= h; yy++) {
				if (yy > 0) sum += row[yy + win - 1] - row[yy - 1];
				if (sum > best) { best = sum; bestAt = yy; }
			}
			return Math.round((bestAt / (h - win)) * 100);
		}
		function buildTextLayer(featBase, coverBase) {
			var W = 480, H = 600;
			return Promise.all([loadImg(featBase + '?w=600&ssl=1'), loadImg(coverBase + '?w=600&ssl=1')]).then(function (im) {
				function px(img) {
					var c = document.createElement('canvas');
					c.width = W; c.height = H;
					var x = c.getContext('2d', { willReadFrequently: true });
					x.drawImage(img, 0, 0, W, H);
					return x.getImageData(0, 0, W, H).data;
				}
				var F = px(im[0]), C = px(im[1]);
				var num = 0, den = 0, i, k, x, y;
				for (i = 0; i < F.length; i += 28) for (k = 0; k < 3; k++) { var a0 = F[i + k]; if (a0 < 200) { num += a0 * C[i + k]; den += C[i + k] * C[i + k]; } }
				var kk = num / den;
				var y0 = Math.round(H * 0.40), y1 = Math.round(H * 0.68);
				var alpha = new Uint8Array(W * H);
				for (y = y0; y < y1; y++) for (x = 0; x < W; x++) {
					var p = (y * W + x) * 4, al = 1;
					for (var ch = 0; ch < 3; ch++) { var e = kk * C[p + ch]; var a1 = (F[p + ch] - e) / (255 - e + 1); if (a1 < al) al = a1; }
					al = al < 0.12 ? 0 : Math.min(1, (al - 0.12) / 0.5);
					alpha[y * W + x] = Math.round(al * 255);
				}
				// 글자가 있는 가로줄을 찾아 "줄"로 묶는다
				var lines = [], cur = null, gap = 0;
				for (y = y0; y < y1; y++) {
					var s = 0;
					for (x = 0; x < W; x++) if (alpha[y * W + x] > 128) s++;
					if (s > 3) { if (!cur) cur = { y0: y, y1: y }; cur.y1 = y; gap = 0; }
					else if (cur && ++gap > 8) { lines.push(cur); cur = null; gap = 0; }
				}
				if (cur) lines.push(cur);
				lines = lines.filter(function (l) { return l.y1 - l.y0 > 14; });
				if (!lines.length) throw new Error('no text');
				lines.forEach(function (l) {
					var c = document.createElement('canvas');
					c.width = W; c.height = H;
					var cx = c.getContext('2d');
					var d = cx.createImageData(W, H);
					for (var yy = Math.max(y0, l.y0 - 4); yy <= Math.min(y1 - 1, l.y1 + 4); yy++) for (var xx = 0; xx < W; xx++) {
						var a = alpha[yy * W + xx];
						if (a) { var q = (yy * W + xx) * 4; d.data[q] = d.data[q + 1] = d.data[q + 2] = 255; d.data[q + 3] = a; }
					}
					cx.putImageData(d, 0, 0);
					l.canvas = c;
				});
				return { W: W, H: H, alpha: alpha, y0: y0, y1: y1, lines: lines };
			});
		}

		function openSheet(it) {
			var vw = window.innerWidth;
			var vh = window.innerHeight;
			var r = it.rect || it.im.getBoundingClientRect(); // 사진이 있던 자리와 크기에서 시작한다(관련 글 카드는 안쪽 iframe 좌표를 화면 좌표로 바꿔 넘겨 준다)
			var r0 = it.radius != null ? it.radius : (parseFloat(getComputedStyle(it.im.closest('.wp-block-post-featured-image') || it.im).borderRadius) || 10);
			// 폰(좁은 화면)은 전체 화면, PC(넓은 화면)는 화면 가운데의 큰 패널로 펼친다(앱스토어 맥 버전처럼)
			var desk = false; // 폰 전용: PC는 시트를 쓰지 않고 카드가 글 페이지로 바로 이동한다
			var narrow = desk && OPT.layout === 'phone'; // PC에서도 폰처럼 좁은 세로 시트
			var L = narrow || !desk ? 'stack' : OPT.layout; // 폰은 지금처럼 전체 화면(stack)
			if (L === 'split' && vw < 900) L = 'blur';
			var split = L === 'split'; // 좌우 분할(이전 방식)
			var tw = narrow ? Math.min(500, vw - 80) : desk ? Math.min(split ? 1100 : 1000, vw - 80) : vw;
			var th = desk ? Math.min(vh - 80, split ? 820 : 940) : vh;
			var tl = (vw - tw) / 2;
			var tt = (vh - th) / 2;
			var trad = desk ? 26 : 0;
			var lw = split ? Math.min(Math.round(tw * 0.48), Math.round(th * 0.78)) : 0; // 사진 칸 폭(사진은 4:5라서 높이의 0.78배 안팎)
			var cineB = 150; // 시네마 머리글: 사진 아래의 제목 띠 높이
			var heroFull = split ? th : L === 'cine' ? Math.round(Math.min(th * 0.74, 640)) : L === 'blur' ? Math.round(Math.min(th * 0.58, 560)) : L === 'band' ? 320 : L === 'head' ? 300 : Math.round(Math.min(vh * 0.42, (narrow ? tw : vw) * 0.88));
			var headW = 240, headMl = 44, headMt = 34; // 작은 사진 머리글: 사진 크기와 바깥 여백
			var src = it.im.getAttribute('data-large-file') || it.im.currentSrc || it.im.src;

			var root = document.createElement('div');
			root.className = 'tp-sheet';
			root.setAttribute('role', 'dialog');
			root.setAttribute('aria-modal', 'true');
			root.setAttribute('aria-label', it.title);
			var dateOnly = it.date.split('·')[0].trim(); // "2026년 10월 21일" (뒤의 조회수는 뺀다)
			var byline = OPT.author
				? '<div class="tp-sheet-author"><img src="https://trendportal.kr/wp-content/uploads/2026/09/tp_profile.png" alt=""><span><strong>트렌드포털</strong><em>' + esc(dateOnly) + '</em></span></div>'
				: '<p class="tp-sheet-meta">' + esc(it.cat + (it.date ? ' · ' + it.date : '')) + '</p>';
			if (OPT.hideCover) root.classList.add('hide-cover');
			root.innerHTML =
				'<div class="tp-sheet-scrim"></div><div class="tp-sheet-panel"><div class="tp-sheet-scroll">' +
				'<div class="tp-sheet-hero"><img src="' + esc(src) + '" alt="" draggable="false"><span class="tp-sheet-cat">' + esc(it.cat) + '</span></div>' +
				'<div class="tp-sheet-body"><div class="tp-sheet-headline"><h2 class="tp-sheet-title">' + esc(it.title) + '</h2>' + byline + '</div>' +
				'<div class="wp-block-post-content entry-content tp-sheet-content"><p>불러오는 중…</p></div>' +
				'<a class="tp-sheet-more" href="' + esc(it.href) + '">글 전체 보기 →</a></div></div>' +
				'<button type="button" class="tp-sheet-close" aria-label="닫기"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M4 4l12 12M16 4L4 16"/></svg></button></div></div>';
			var scrim = root.querySelector('.tp-sheet-scrim');
			var panel = root.querySelector('.tp-sheet-panel');
			var hero = root.querySelector('.tp-sheet-hero');
			var scroller = root.querySelector('.tp-sheet-scroll');
			hero.classList.add('fx-' + OPT.fx);
			root.classList.add('layout-' + L);
			var bodyEl = root.querySelector('.tp-sheet-body');
			var headline = root.querySelector('.tp-sheet-headline');
			if (L === 'blur' || L === 'cine') {

				// 사진 전체가 보이도록 가운데에 두고, 양옆은 같은 사진을 흐리게 깔아 채운다
				var bg = document.createElement('div');
				bg.className = 'tp-sheet-blurbg';
				bg.style.backgroundImage = 'url(' + JSON.stringify(src) + ')';
				hero.insertBefore(bg, hero.firstChild);
			}
			if (L === 'band' || L === 'cine') {

				// 사진 띠 위에 제목을 얹는다(우리 사이트 글 상단과 같은 방식). 어둡게 깐 그라데이션 위에 흰 글자
				var shade = document.createElement('div');
				shade.className = 'tp-sheet-band-shade';
				hero.appendChild(shade);
				hero.appendChild(headline);
			}
			var closeBtn = root.querySelector('.tp-sheet-close');
			if (split) {
				root.classList.add('split');
				panel.insertBefore(hero, scroller);
				// 글을 내리면 왼쪽 사진 칸이 접혀서 몰입을 방해하지 않고, 올리면 다시 나온다(0~260px 구간에서 천천히)
				scroller.addEventListener('scroll', function () {
					if (!OPT.fold || sheet.closing) return;
					S.c = clamp(scroller.scrollTop / 260, 0, 1);
					render();
				}, { passive: true });
			}
			document.body.appendChild(root);
			var sbw = window.innerWidth - document.documentElement.clientWidth; // PC의 스크롤바 폭(잠그면 사라져서 화면이 밀린다)
			document.documentElement.style.overflow = 'hidden';
			if (sbw > 0) document.documentElement.style.paddingRight = sbw + 'px';
			it.im.style.visibility = 'hidden';

			// p: 0(사진 자리)~1(전체 화면) 펼침 정도, dx·dy·s: 끄는 동안의 이동·크기, a: 끄는 동안 배경 농도
			var S = { p: 0, dx: 0, dy: 0, s: 1, a: 1, c: 0 }; // c: 글을 내릴 때 사진 칸이 접힌 정도(0~1)
			sheet = { it: it, root: root, anims: {}, closing: false, parent: sheet }; // parent: 이 시트 위에 새 시트를 열면 아래 시트를 기억해 두었다가 닫을 때 돌려준다

			// 글자 있는 썸네일 → 글자 없는 원본으로 바뀌는 효과는, 원본이 다 불러와졌고 펼침이 끝난 "뒤에" 시작한다
			// (펼치는 도중에 끝나 버리면 효과가 보이지 않는다)
			var rv = { loaded: false, layer: false, opened: false, done: false };
			var needsLayer = OPT.fx === 'lines' || OPT.fx === 'dust';
			var fxL = null; // 뽑아낸 글자 층
			var fxBox = null; // 글자 층을 담는 상자(사진 칸과 같은 크기를 따라간다)
			var fxT = 0; // 0 = 글자가 있는 썸네일, 1 = 글자가 사라진 원본
			var fxAnim = 0;
			var fxDust = null;
			function ease3(t) { return 1 - Math.pow(1 - t, 4); } // 강한 ease-out(스킬의 cubic-bezier(0.23,1,0.32,1)와 비슷한 곡선)
			function fxRender() {
				if (!fxL) return;
				if (sheet.kind === 'lines') {
					var n = fxL.lines.length, stag = 0.08; // 줄 사이 시차 약 72ms(스킬 권장: 30~80ms)
					fxL.lines.forEach(function (l, i) {
						var lp = clamp((fxT - i * stag) / (1 - (n - 1) * stag), 0, 1);
						var e = ease3(lp);
						l.el.style.opacity = String(1 - e);
						l.el.style.transform = 'translate3d(0,' + (-24 * e).toFixed(2) + 'px,0)';
						l.el.style.filter = e > 0.01 ? 'blur(' + (7 * e).toFixed(2) + 'px)' : '';
					});
				} else if (sheet.kind === 'dust' && fxDust) {
					var d = fxDust, buf = d.buf, N = d.n, w = d.w, h = d.h;
					buf.fill(0);
					for (var i = 0; i < N; i++) {
						var pp = clamp((fxT - d.delay[i]) / 0.55, 0, 1);
						var e = 1 - (1 - pp) * (1 - pp);
						var X = Math.round(d.x[i] + d.dx[i] * e);
						var Y = Math.round(d.y[i] + d.dy[i] * e);
						if (X < 0 || Y < 0 || X >= w - 1 || Y >= h - 1) continue;
						var a = Math.round(d.a[i] * Math.pow(1 - pp, 1.5));
						if (a < 4) continue;
						var c = ((a << 24) | 0xffffff) >>> 0; // 흰색 + 투명도(바이트 순서: 투명도 파랑 초록 빨강)
						buf[Y * w + X] = c; buf[Y * w + X + 1] = c; buf[(Y + 1) * w + X] = c; buf[(Y + 1) * w + X + 1] = c;
					}
					d.ctx.putImageData(d.img, 0, 0);
				}
			}
			function fxTween(to, ms, cb) {
				if (fxAnim) cancelAnimationFrame(fxAnim);
				var from = fxT, t0 = performance.now();
				if (reduce || ms <= 0) { fxT = to; fxRender(); if (cb) cb(); return; }
				(function step(now) {
					var q = clamp((now - t0) / ms, 0, 1);
					fxT = from + (to - from) * q;
					fxRender();
					if (q < 1) fxAnim = requestAnimationFrame(step);
					else { fxAnim = 0; if (cb) cb(); }
				})(t0);
			}
			function fxMount() {
				fxBox = document.createElement('div');
				fxBox.className = 'tp-fx-layer';
				var bw = hero.offsetWidth, bh = hero.offsetHeight - (L === 'cine' ? cineB : 0);
				var contain = L === 'blur' || L === 'cine'; // blur 포맷은 사진 전체를 보여 주므로 contain, 나머지는 cover
				var pos = L === 'stack' ? '50% 40%' : '50% 50%'; // 사진 칸의 object-position과 같아야 글자가 같은 자리에 겹친다
				if (sheet.kind === 'lines') {
					fxL.lines.forEach(function (l) {
						l.el = l.canvas;
						l.el.className = 'tp-fx-line';
						if (L !== 'band') l.el.style.objectPosition = pos; // 띠 포맷은 스마트 크롭 위치(CSS 변수)를 따른다
							l.el.style.objectFit = contain ? 'contain' : 'cover';
						fxBox.appendChild(l.el);
					});
				} else {
					// 먼지: 글자 점마다 한 알갱이. 원본 사진 좌표 → 사진 칸 좌표(object-fit: cover와 같은 계산)
					var c = document.createElement('canvas');
					c.width = Math.round(bw); c.height = Math.round(bh);
					c.className = 'tp-fx-line';
					var ctx = c.getContext('2d');
					var img = ctx.createImageData(c.width, c.height);
					var s = contain ? Math.min(bw / fxL.W, bh / fxL.H) : Math.max(bw / fxL.W, bh / fxL.H);
					var ox = (bw - fxL.W * s) * 0.5, oy = (bh - fxL.H * s) * (L === 'stack' ? 0.4 : 0.5);
					var xs = [], ys = [], as = [];
					fxL.lines.forEach(function (l) {
						for (var y = Math.max(fxL.y0, l.y0 - 4); y <= Math.min(fxL.y1 - 1, l.y1 + 4); y++) for (var x = 0; x < fxL.W; x++) {
							var a = fxL.alpha[y * fxL.W + x];
							if (a > 40) { xs.push(ox + x * s); ys.push(oy + y * s); as.push(a); }
						}
					});
					var N = xs.length;
					var sc = bw / 390; // 화면 크기에 비례해 흩날리는 거리를 정한다
					var dd = { n: N, w: c.width, h: c.height, ctx: ctx, img: img, buf: new Uint32Array(img.data.buffer), x: new Float32Array(xs), y: new Float32Array(ys), a: new Float32Array(as), dx: new Float32Array(N), dy: new Float32Array(N), delay: new Float32Array(N) };
					for (var i = 0; i < N; i++) {
						var r1 = Math.random(), r2 = Math.random(), r3 = Math.random();
						dd.dx[i] = (50 + 230 * r1) * sc;
						dd.dy[i] = (-(20 + 190 * r2) + (r1 - 0.5) * 60) * sc;
						dd.delay[i] = (xs[i] / bw) * 0.32 + r3 * 0.18; // 왼쪽 글자부터 흩어진다
					}
					fxDust = dd;
					fxBox.appendChild(c);
				}
				hero.appendChild(fxBox); // 사진 칸 안에 넣어서 사진과 함께 움직이고 크기도 따라간다
				fxRender();
			}
			function reveal() {
				var kind = OPT.fx;
				if (needsLayer && !fxL) { hero.classList.remove('fx-' + kind); hero.classList.add('fx-wipe'); kind = 'wipe'; } // 글자 층을 못 뽑으면 "쓸어서 지우기"로 대신한다
				sheet.kind = kind;
				if (rv.focal != null) hero.style.setProperty('--tp-focal', rv.focal + '%'); // 펼침이 끝난 뒤 사진이 피사체 쪽으로 부드럽게 움직인다
				
				if (kind === 'lines' || kind === 'dust') {
					fxMount();
					hero.classList.add('is-ready', 'has-orig');
					fxTween(1, kind === 'dust' ? 1150 : 900);
				} else {
					hero.classList.add('is-ready', 'has-orig');
				}
			}
			function tryReveal() {
				if (rv.done || !rv.loaded || !rv.layer || !rv.opened) return;
				rv.done = true;
				setTimeout(reveal, reduce ? 0 : 160);
			}
			// 닫을 때는 효과가 반대로 돌아간다: 사라졌던 글자가 되돌아온 뒤 썸네일로 접힌다
			sheet.fxCover = function (cb) {
				if (!rv.done || !sheet.kind) { cb(); return; }
				if (sheet.kind === 'lines' || sheet.kind === 'dust') {
					fxTween(0, reduce ? 0 : 480, function () { hero.classList.remove('is-ready', 'has-orig'); cb(); });
				} else {
					hero.classList.add('is-closing');
					hero.classList.remove('is-ready', 'has-orig');
					setTimeout(cb, reduce ? 0 : 420);
				}
			};
			// 물결의 중심 = 카드를 누른 자리(사진 칸 안에서의 비율)
			if (it.tapX) {
				hero.style.setProperty('--tp-rx', clamp((it.tapX - r.left) / r.width, 0, 1) * 100 + '%');
				hero.style.setProperty('--tp-ry', clamp((it.tapY - r.top) / r.height, 0, 1) * 100 + '%');
			}

			function render() {
				var p = clamp(S.p, 0, 1.02);
				panel.style.left = lerp(r.left, tl, p) + 'px';
				panel.style.top = lerp(r.top, tt, p) + 'px';
				panel.style.width = lerp(r.width, tw, p) + 'px';
				panel.style.height = lerp(r.height, th, p) + 'px';
				panel.style.borderRadius = lerp(r0, trad, p) + 'px';
				hero.style.height = lerp(r.height, heroFull, p) + 'px';
				hero.style.paddingTop = 'calc(env(safe-area-inset-top, 0px) * ' + p.toFixed(3) + ')'; // 상태바 영역만큼 사진을 위로 더 채운다(펼치는 동안 서서히)
				if (L === 'head') {
					// 사진이 카드 자리에서 왼쪽 위의 작은 사진으로 줄어들고, 그 옆으로 제목이 흘러 들어간다
					hero.style.width = lerp(r.width, headW, p) + 'px';
					hero.style.marginLeft = lerp(0, headMl, p) + 'px';
					hero.style.marginTop = lerp(0, headMt, p) + 'px';
					hero.style.borderRadius = lerp(0, 14, p) + 'px';
				}
				if (L === 'cine') hero.style.setProperty('--tp-pad', lerp(0, cineB, p) + 'px'); // 사진은 위쪽 영역에만 들어가고 아래는 제목 띠
				if (L === 'stack' || L === 'blur') bodyEl.style.opacity = String(clamp((p - 0.6) / 0.4, 0, 1));
				if (L === 'head' || L === 'band' || L === 'cine') {
					var ft = String(clamp((p - 0.6) / 0.4, 0, 1)); // 글은 사진이 자리를 잡은 뒤에 나타난다
					if (L === 'head') bodyEl.style.opacity = ft;
					else headline.style.opacity = ft;
				}
								if (split) {
					var hw = lerp(r.width, lw, p);
					var c = OPT.fold ? S.c : 0;
					hero.style.width = hw + 'px';
					hero.style.transform = c ? 'translateX(' + (-c * hw).toFixed(1) + 'px)' : ''; // 사진 칸이 왼쪽으로 밀려 나가고
					scroller.style.left = hw * (1 - c) + 'px'; // 글 칸이 그만큼 넓어진다
					scroller.style.opacity = String(clamp((p - 0.55) / 0.45, 0, 1)); // 글 칸은 사진이 자리를 잡은 뒤에 나타난다
				}
				panel.style.transform = 'translate(' + S.dx.toFixed(2) + 'px,' + S.dy.toFixed(2) + 'px) scale(' + S.s.toFixed(4) + ')';
				panel.style.backgroundColor = 'rgba(255,255,255,' + clamp((p - 0.6) / 0.4, 0, 1).toFixed(3) + ')';
				scrim.style.opacity = String(clamp(S.p, 0, 1) * clamp(S.a, 0, 1));
				// 폰에서 시트가 화면을 다 덮은 뒤에는 반투명 막을 아예 뺀다: 화면 가장자리에 닿은 반투명 요소를 아이폰 Safari가 상·하단 띠 색으로 삼아 띠가 회색이 되고, 전체 화면 레이어 하나도 줄어든다
				scrim.style.display = (!desk && S.p >= 0.999 && S.a >= 0.999 && S.s >= 0.999 && Math.abs(S.dy) < 0.5 && Math.abs(S.dx) < 0.5) ? 'none' : '';
				if (p > 0.9 && sheet && sheet.startFrame) { var sf = sheet.startFrame; sheet.startFrame = null; sf(); }
				closeBtn.style.opacity = String(clamp((S.p - 0.6) / 0.4, 0, 1) * clamp(S.a, 0, 1));
			}
			function go(key, to, o, done) {
				if (sheet.anims[key]) sheet.anims[key].stop();
				if (reduce) { S[key] = to; render(); if (done) done(); return; }
				sheet.anims[key] = spring(
					{ from: S[key], to: to, velocity: o.velocity || 0, damping: o.damping, response: o.response, eps: 0.002, veps: 0.05 },
					function (x) { S[key] = x; render(); },
					function () { sheet.anims[key] = null; if (done) done(); }
				);
			}
			function stopAll() {
				Object.keys(sheet.anims).forEach(function (k) {
					if (sheet.anims[k]) sheet.anims[k].stop();
					sheet.anims[k] = null;
				});
			}
			render();
			go('p', 1, SPR.open, function () { root.classList.add('is-open'); rv.opened = true; tryReveal(); if (sheet && sheet.startFrame) sheet.startFrame(); if (sheet && sheet.afterOpen) { var ao = sheet.afterOpen; sheet.afterOpen = null; ao(); } });

			// v6(D안): 진짜 글 페이지를 iframe으로 넣는다. 같은 도메인이라 안쪽을 꾸미고(헤더·커버 숨김) 높이를 읽을 수 있다.
			var content = root.querySelector('.tp-sheet-content');
			content.innerHTML = '<div class="tp-sheet-loading">불러오는 중…</div>';
			var loc = window.tpLocalize ? window.tpLocalize(it.href) : it.href; // 로컬 시안에서만: 스냅샷이 있는 글은 같은 도메인 파일로 바꾼다
			// 주소와 뒤로 가기: 시트를 열면 기록을 한 칸 쌓는다. 그러면 폰의 뒤로 가기(가장자리 스와이프 포함)가 시트를 닫는다.
			// 주소는 "홈 주소 + #read=글번호"로 둔다. 글의 진짜 주소로 바꾸면 워드프레스의 페이지 이동 라우터가 "내가 모르는 주소"라며
			// 뒤로 가기 때 페이지를 통째로 다시 불러오기 때문이다(해시는 라우터가 무시하므로 같은 페이지로 취급된다).
			// 시트를 X로 닫을 때는 쌓아 둔 기록을 되돌린다(closeSheet). 절대 주소를 쓰는 이유: 시안 홈의 <base>가 진짜 사이트를 가리켜 상대 주소는 다른 출처로 풀린다
			try {
				var pid = (String(loc).match(/(?:archives\/|post-)(\d+)/) || [])[1];
				history.pushState({ tpSheet: true }, '', location.href.split('#')[0] + '#read=' + (pid || ''));
				sheet.pushed = true;
			} catch (e) { sheet.pushed = false; }
			// 폰 상단 상태바 띠 색: 시트가 하얀 동안은 하얗게(닫으면 원래대로). iOS Safari의 효과는 실기기에서 확인 필요
			var tcm = document.querySelector('meta[name="theme-color"]'), tcPrev = tcm ? tcm.getAttribute('content') : null, tcAdded = false;
			if (!tcm) { tcm = document.createElement('meta'); tcm.setAttribute('name', 'theme-color'); document.head.appendChild(tcm); tcAdded = true; }
			tcm.setAttribute('content', '#ffffff');
			sheet.restoreTheme = function () { if (tcAdded) tcm.remove(); else if (tcPrev != null) tcm.setAttribute('content', tcPrev); };
			// 상단 꽉 채우기(상태바·다이나믹 아일랜드 아래까지 사진): 시트가 열려 있는 동안만 viewport-fit=cover로 바꿔 화면 맨 위까지 그릴 수 있게 한다.
			// 그러면 맨 위 사진이 상태바 뒤로 이어지고(아이폰 Safari가 그 위에 유리 같은 흐림을 얹는다), 안 먹는 브라우저는 지금처럼 상단 띠가 따로 있다.
			// 끄려면 주소 뒤에 #vfc=0. 아이폰 Safari가 열려 있는 중에 바꾼 viewport-fit을 받아들이는지는 실기기에서 확인해야 한다
			if (OPT.vfc && !window.__tpVfc) {
				var vm = document.querySelector('meta[name="viewport"]');
				if (vm) {
					var vprev = vm.getAttribute('content') || '';
					window.__tpVfc = { meta: vm, prev: vprev };
					if (!/viewport-fit/.test(vprev)) vm.setAttribute('content', vprev + ', viewport-fit=cover');
				}
			}
			var topShade = document.createElement('div'); // 상태바 글자가 사진 위에서도 읽히도록 맨 위만 살짝 어둡게
			topShade.className = 'tp-sheet-topshade';
			hero.appendChild(topShade);
			var probe = document.createElement('div'); // 위쪽 안전 영역이 실제로 생겼는지 재는 눈에 안 보이는 칸
			probe.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:0;visibility:hidden;pointer-events:none;padding-top:env(safe-area-inset-top,0px)';
			root.appendChild(probe);
			function checkSafe() { root.classList.toggle('has-safe-top', (parseFloat(getComputedStyle(probe).paddingTop) || 0) > 0); }
			setTimeout(checkSafe, 150); setTimeout(checkSafe, 700); setTimeout(checkSafe, 1600);
			// 안쪽 글은 "최종 폭"으로 미리 고정한다. 시트 상자는 펼치는 동안 폭이 계속 변하는데, 그때마다 6천 px짜리 글이 다시 배치되면 버벅거린다.
			// 폭을 고정해 두면 상자가 안쪽을 잘라 보여 주기만 한다
			content.style.width = tw + 'px';
			var fr = document.createElement('iframe');
			fr.className = 'tp-sheet-frame';
			fr.setAttribute('scrolling', 'no');
			fr.title = it.title;
			fr.style.width = tw + 'px';
			// 읽기 진행바: 글 페이지의 진행바는 "그 문서가 스크롤될 때" 움직이는데, 안쪽 글은 스크롤하지 않으므로 시트 쪽에서 직접 그린다(모양은 사이트 것과 같다)
			var bar = document.createElement('div');
			bar.className = 'tp-sheet-progress';
			panel.appendChild(bar);
			var heroColor = null;
			function updateTheme() {
				var past = !heroColor || scroller.scrollTop > hero.offsetHeight - 80;
				tcm.setAttribute('content', past ? '#ffffff' : heroColor);
			}
			sheet.setHeroColor = function (c) { heroColor = c; updateTheme(); };
			scroller.addEventListener('scroll', function () {
				updateTheme();
				var mx = scroller.scrollHeight - scroller.clientHeight;
				bar.style.transform = 'scaleX(' + (mx > 0 ? clamp(scroller.scrollTop / mx, 0, 1) : 0).toFixed(4) + ')';
			}, { passive: true });
			// 안쪽 글에서 쓸 꾸밈: 사이트 머리글·바닥글·커버·자체 진행바는 시트가 이미 갖고 있으므로 숨기고, 스크롤은 바깥 시트가 맡는다
			var INNER_CSS = '.wp-site-blocks > header, .wp-site-blocks > footer, .tp-cover, #wpadminbar { display: none !important; }' +
				'body.single-post::before { display: none !important; }' +
				'html, body { background: #fff !important; overflow: hidden !important; height: auto !important; min-height: 0 !important; }' +
				'main { margin-top: 0 !important; }' +
				// 로그인한 관리자에게는 워드프레스가 관리 막대 높이(32·46px)만큼 html 위쪽에 여백을 넣는다. 막대는 숨겨도 여백은 남아 본문 위에 빈 칸이 생기므로 없앤다
				'html { margin-top: 0 !important; }' +
				// 글 본문 묶음에는 위쪽 24px 간격이 있다(원래는 커버 아래 간격). 시트에서는 머리글 바로 아래에 붙으므로 없앤다
				'.wp-site-blocks main > .wp-block-group { margin-top: 0 !important; }' +
				'.wp-site-blocks { padding-top: 0 !important; }';
			var decorated = false, coverDone = false, frameStarted = false, ro = null, tries = 0;
			function fit(d) {
				var h = Math.ceil(d.documentElement.getBoundingClientRect().height);
				if (h > 0) fr.style.height = h + 'px';
			}
			function adoptCover(cim) {
				coverDone = true;
				if (!cim) { rv.layer = true; return; }
				var orig = document.createElement('img');
				orig.className = 'tp-sheet-hero-orig';
				orig.alt = cim.getAttribute('alt') || '';
				orig.draggable = false;
				orig.crossOrigin = 'anonymous'; // 사진 내용을 읽어 자르는 위치를 정하려면 필요(Photon이 허용한다)
				orig.onload = function () {
					if (L === 'band') {
						try { rv.focal = focalY(orig, 320 / (tw / (orig.naturalWidth / orig.naturalHeight))); } catch (e) { rv.focal = null; }
					}
					try { // 사진 맨 위 몇 줄의 평균색 → 상단 띠 색(어둡게 깐 만큼 곱한다)
						var tc = document.createElement('canvas'), tw2 = 24, th2 = Math.max(4, Math.round(24 * orig.naturalHeight / orig.naturalWidth));
						tc.width = tw2; tc.height = th2;
						var tx = tc.getContext('2d', { willReadFrequently: true });
						tx.drawImage(orig, 0, 0, tw2, th2);
						var td = tx.getImageData(0, 0, tw2, 2).data, tr = 0, tg = 0, tb = 0, tn = tw2 * 2, tdim = 1 - (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--tp-hero-dim')) || 0.4);
						for (var ti = 0; ti < td.length; ti += 4) { tr += td[ti]; tg += td[ti + 1]; tb += td[ti + 2]; }
						if (sheet && sheet.setHeroColor) sheet.setHeroColor('rgb(' + Math.round(tr / tn * tdim) + ',' + Math.round(tg / tn * tdim) + ',' + Math.round(tb / tn * tdim) + ')');
					} catch (e) {}
					rv.loaded = true;
					tryReveal();
				};
				var cs = cim.getAttribute('src');
				orig.src = cs.split('?')[0] + '?w=1400&ssl=1';
				if (needsLayer) {
					// 글자 층 계산(캔버스)은 무거워서 펼침이 끝난 뒤에 한다: 움직이는 도중에 하면 끊긴다
					var startLayer = function () {
						var featSrc = (it.im.getAttribute('data-large-file') || it.im.currentSrc || it.im.src).split('?')[0];
						buildTextLayer(featSrc, cs.split('?')[0]).then(
							function (L2) { fxL = L2; rv.layer = true; tryReveal(); },
							function () { rv.layer = true; tryReveal(); }
						);
					};
					if (rv.opened) startLayer(); else sheet.afterOpen = startLayer;
				} else rv.layer = true;
				hero.appendChild(orig);
			}
			// 글 HTML만 먼저 받아 둔다(네트워크만 쓰므로 펼치는 동안 부담이 없다). 파싱·커버 분석은 펼침이 끝난 뒤에 한다:
			// 같은 스레드에서 그 일들을 하면 펼침이 끊긴다. 받아 둔 글은 브라우저 캐시에도 남아 뒤의 iframe 로딩이 빨라진다
			var pendingTxt = null, fetched = false;
			fetch(loc, { credentials: 'same-origin' }).then(function (res) { return res.text(); }).then(function (txt) {
				pendingTxt = txt; fetched = true;
				if (frameStarted) useFetched(); // 아직 iframe 시작 전이면 startFrame이 처리한다
			}).catch(function () { fetched = true; });
			function useFetched() {
				if (!root.isConnected || pendingTxt == null) return;
				var txt = pendingTxt;
				pendingTxt = null;
				// 글 전체를 DOM으로 해석하면 무겁다(느린 폰에서 20ms 이상) → 필요한 두 가지(커버 사진 주소, 날짜)만 정규식으로 뽑는다
				var cm = txt.match(/<figure class="tp-cover-bg"><img[^>]*?\ssrc="([^"]+)"/);
				if (!it.date) {
					var dm = txt.match(/class="wp-block-post-date[^"]*"[^>]*>\s*<time[^>]*>([^<]+)<\/time>/);
					var em = root.querySelector('.tp-sheet-author em');
					if (dm && em) em.textContent = dm[1].trim();
				}
				// 못 찾았을 때는 건드리지 않는다: 아래 poll이 iframe에서 직접 찾거나, 끝까지 없으면 커버 없음으로 처리한다
				if (cm && !coverDone) adoptCover({ getAttribute: function (k) { return k === 'src' ? cm[1].replace(/&amp;/g, '&') : ''; } });
			}
			// 안쪽 글이 막 만들어지는 중에도 꾸밈을 입히려고, 짧은 간격으로 살핀다(load를 기다리면 헤더가 잠깐 보인다)
			function poll() {
				if (!root.isConnected) return;
				var d = null;
				try { d = fr.contentDocument; } catch (e) { d = null; }
				if (d && d.location && d.location.href !== 'about:blank' && d.head && d.body) {
					if (!decorated) {
						decorated = true;
						var st = d.createElement('style');
						st.textContent = INNER_CSS;
						d.head.appendChild(st);
						d.addEventListener('click', function (e) {
							var a = e.target.closest && e.target.closest('a[href]');
							if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
							if (/^(#|javascript:)/.test(a.getAttribute('href'))) return;
							// "다른 글도 볼까요?" 카드: 기존 글 포맷으로 빠져나가지 않고, 이 시트 위에 새 시트를 같은 방식으로 펼친다
							var card = a.closest('.tp-related-card');
							var pim = card && card.querySelector('img');
							if (pim && !sheet.closing) {
								e.preventDefault();
								var fb = fr.getBoundingClientRect(), pr = pim.getBoundingClientRect();
								var lab = card.querySelector('.tp-related-label'), ttl = card.querySelector('.tp-related-title');
								openSheet({
									im: pim,
									href: a.href,
									title: (ttl || a).textContent.trim(),
									cat: lab ? lab.textContent.trim() : '',
									date: '',
									rect: { left: fb.left + pr.left, top: fb.top + pr.top, width: pr.width, height: pr.height },
									radius: 10
								});
								return;
							}
							if (!a.target) a.target = '_top'; // 그 밖의 링크는 시트 안이 아니라 진짜 이동으로 연다
						}, true);
						try {
							ro = new fr.contentWindow.ResizeObserver(function () { fit(d); });
							ro.observe(d.documentElement);
						} catch (e) {}
					}
					var cim = d.querySelector('.tp-cover-bg img');
					if (!coverDone && (cim || d.readyState === 'complete')) adoptCover(cim); // 위의 fetch가 실패했을 때의 대비
					if (d.readyState !== 'loading' && decorated) {
						fr.classList.add('is-shown');
						var ld = content.querySelector('.tp-sheet-loading');
						if (ld) ld.remove();
					}
					if (d.readyState === 'complete' && coverDone) { fit(d); return; }
				}
				if (++tries < 400) setTimeout(poll, 50);
				else { if (!coverDone) adoptCover(null); }
			}
			// iframe은 펼침이 끝난 뒤에 불러온다: 같은 도메인 iframe은 부모와 같은 스레드를 써서, 펼치는 도중에 불러오면 움직임이 끊긴다
			function startFrame() {
				if (frameStarted || !root.isConnected) return;
				frameStarted = true;
				useFetched(); // 펼침이 끝난 이 순간에 커버·날짜 처리
				// 한 프레임에 일을 몰지 않도록 iframe은 조금 뒤에 시작한다
				setTimeout(function () {
					if (!root.isConnected) return;
					content.appendChild(fr);
					fr.src = loc;
					poll();
				}, reduce ? 0 : 60);
			}
			sheet.startFrame = startFrame;
			if (rv.opened) startFrame();
			// 닫을 때: 읽다가 닫으면 썸네일 크기로 줄어든 상자 안에 본문 글자만 보이던 문제 → 본문을 먼저 흐리고 맨 위(사진)로 되돌린다
			sheet.toTop = function () {
				content.style.transition = 'opacity .1s';
				content.style.opacity = '0';
				bar.style.opacity = '0';
				setTimeout(function () { scroller.scrollTop = 0; }, 110);
			};

// 사진 부분을 잡고 아래로 끌어서 닫는다.
			//  - 마우스·펜: 사진을 잡고 끌면 방향과 상관없이 따라온다(기존 그대로)
			//  - 손가락: 글이 맨 위(scrollTop 0)일 때 "아래로" 끄는 움직임만 닫기로 쓰고, 그 밖의 세로 움직임(특히 위로 밀어 글을 내리는 것)은
			//    브라우저의 글 스크롤에 맡긴다. 사진이 화면을 크게 차지하는데 그 위에서 스크롤이 막히면 불편하기 때문이다
			var dg = null;
			function dragStart(x, y, pid) {
				stopAll(); // 움직이던 중이어도 끊고 지금 값에서 잡는다
				sheet.closing = false;
				dg = { id: pid, x0: x, y0: y, dx0: S.dx, dy0: S.dy, tr: tracker(), active: false };
				dg.tr.add(x, y);
			}
			function dragMove(x, y) {
				var ddx = x - dg.x0;
				var ddy = y - dg.y0;
				if (!dg.active && Math.hypot(ddx, ddy) < 6) return;
				dg.active = true;
				dg.tr.add(x, y);
				var rawY = dg.dy0 + ddy;
				S.dx = dg.dx0 + ddx;
				S.dy = rawY >= 0 ? rawY : -rubber(-rawY, 100); // 위로는 고무줄
				var down = Math.max(0, S.dy);
				S.s = clamp(1 - down / (vh * SHRINK), 0.7, 1); // 참고 영상에서 잰 값
				S.a = clamp(1 - down / (vh * 0.6), 0.1, 1);
				render();
			}
			function dragEnd(cancelled) {
				var d = dg;
				dg = null;
				if (!d) return;
				var vel = d.tr.velocity();
				if (!d.active) return;
				var py = S.dy + project(vel.y) * 0.5;
				if (!cancelled && S.dy > 20 && (py > vh * 0.22 || vel.y > 800)) closeSheet(vel.x, vel.y);
				else {
					go('dx', 0, { damping: SPR.back.damping, response: SPR.back.response, velocity: vel.x });
					go('dy', 0, { damping: SPR.back.damping, response: SPR.back.response, velocity: vel.y });
					go('s', 1, { damping: 1, response: 0.35 });
					go('a', 1, { damping: 1, response: 0.35 });
				}
			}
			hero.addEventListener('pointerdown', function (e) {
				if (e.pointerType === 'touch') return; // 손가락은 아래 touch 이벤트가 맡는다
				if (e.pointerType === 'mouse' && e.button !== 0) return;
				dragStart(e.clientX, e.clientY, e.pointerId);
				hero.setPointerCapture(e.pointerId);
			});
			hero.addEventListener('pointermove', function (e) {
				if (!dg || e.pointerId !== dg.id) return;
				dragMove(e.clientX, e.clientY);
			});
			function up(e) {
				if (!dg || e.pointerId !== dg.id) return;
				dragEnd(e.type === 'pointercancel');
			}
			hero.addEventListener('pointerup', up);
			hero.addEventListener('pointercancel', up);
			var tg = null; // 손가락 상태: mode가 null이면 아직 어느 쪽인지 모름, 'close'면 닫기 끌기, 'scroll'이면 브라우저 스크롤
			hero.addEventListener('touchstart', function (e) {
				if (e.touches.length > 1) { if (tg && tg.mode === 'close') dragEnd(true); tg = null; return; }
				tg = { x0: e.touches[0].clientX, y0: e.touches[0].clientY, mode: null };
			}, { passive: true });
			hero.addEventListener('touchmove', function (e) {
				if (!tg) return;
				var t = e.touches[0];
				if (!tg.mode) {
					var ax = t.clientX - tg.x0, ay = t.clientY - tg.y0;
					if (Math.hypot(ax, ay) < 6) return;
					// 글이 맨 위이고 아래로(세로 위주) 끌 때만 닫기. 그 밖에는 손대지 않고 스크롤에 맡긴다
					tg.mode = (ay > 0 && scroller.scrollTop <= 0 && Math.abs(ay) >= Math.abs(ax) * 0.5) ? 'close' : 'scroll';
					if (tg.mode === 'close') dragStart(tg.x0, tg.y0, 'touch');
				}
				if (tg.mode === 'close') {
					if (e.cancelable) e.preventDefault(); // 끄는 동안 브라우저가 같이 스크롤하지 않도록
					dragMove(t.clientX, t.clientY);
				}
			}, { passive: false });
			function touchDone(cancelled) {
				if (tg && tg.mode === 'close') dragEnd(cancelled);
				tg = null;
			}
			hero.addEventListener('touchend', function () { touchDone(false); });
			hero.addEventListener('touchcancel', function () { touchDone(true); });
			closeBtn.addEventListener('click', function () { closeSheet(0, 0); });
			scrim.addEventListener('click', function () { closeSheet(0, 0); }); // PC에서 패널 바깥을 누르면 닫는다
			document.addEventListener('keydown', onKey);
			closeBtn.focus({ preventScroll: true });
			sheet.go = go;
			sheet.stopAll = stopAll;
		}
		// 뒤로 가기 신호(popstate)는 캡처 단계에서 먼저 받아, 우리가 처리한 것은 다른 스크립트(워드프레스 라우터)에 넘기지 않는다
		var popsToEat = 0;
		window.addEventListener('popstate', function (e) {
			if (popsToEat > 0) { popsToEat--; e.stopImmediatePropagation(); return; } // 우리가 시트를 닫으며 일으킨 것
			if (sheet && sheet.pushed && !sheet.closing) { sheet.pushed = false; closeInstant(); e.stopImmediatePropagation(); } // 뒤로 가기 = 애니메이션 없이 바로 닫기
			else if (!sheet && history.state && history.state.tpSheet) { popsToEat++; e.stopImmediatePropagation(); history.back(); } // 앞으로 가기로 시트 없는 기록에 들어오면 한 칸 더 되돌린다
		}, true);
		function onKey(e) {
			if (e.key === 'Escape' && sheet) closeSheet(0, 0);
		}
		// 닫기: 사진이 있던 자리와 크기로 돌아간다(펼칠 때와 같은 길). 끌던 이동·크기도 함께 풀린다
		// 뒤로 가기(폰의 가장자리 스와이프 포함)로 닫을 때는 애니메이션 없이 바로 걷어 낸다: 브라우저가 이미 자기 전환을 보여 주므로
		// 그 위에 카드로 줄어드는 움직임이 겹치면 어색하다. X·ESC·끌기로 닫을 때만 카드로 접힌다
		function closeInstant() {
			if (!sheet || sheet.closing) return;
			sheet.closing = true;
			if (sheet.pushed) { sheet.pushed = false; popsToEat++; try { history.back(); } catch (e) { popsToEat--; } } // 시트를 열 때 쌓은 기록을 되돌린다(그때 오는 popstate는 먹는다)
			sheet.stopAll();
			finish();
		}
		function closeSheet(vx, vy) {
			if (!sheet || sheet.closing) return;
			sheet.closing = true;
			sheet.stopAll();
			sheet.root.classList.remove('is-open');
			if (sheet.toTop) sheet.toTop();
			var n = 0;
			function done() { if (++n < 7) return; finish(); }
			sheet.fxCover(done); // 글자가 되돌아오는 효과(끝나야 정리된다)
			sheet.go('p', 0, { damping: SPR.dismiss.damping, response: 0.4 }, done);
			sheet.go('dx', 0, { damping: SPR.dismiss.damping, response: SPR.dismiss.response, velocity: vx }, done);
			sheet.go('dy', 0, { damping: SPR.dismiss.damping, response: SPR.dismiss.response, velocity: vy }, done);
			sheet.go('s', 1, SPR.dismiss, done);
			sheet.go('a', 1, SPR.dismiss, done);
			sheet.go('c', 0, SPR.dismiss, done);
		}
		function finish() {
			if (!sheet) return;
			var par = sheet.parent;
			if (sheet.restoreTheme) sheet.restoreTheme();
			sheet.it.im.style.visibility = '';
			sheet.root.remove();
			sheet = par || null; // 아래에 시트가 남아 있으면 그 시트로 돌아간다
			if (!sheet) {
				document.removeEventListener('keydown', onKey);
				if (window.__tpVfc) { window.__tpVfc.meta.setAttribute('content', window.__tpVfc.prev); window.__tpVfc = null; } // viewport 원래대로
				document.documentElement.style.overflow = '';
				document.documentElement.style.paddingRight = '';
			}
		}
	}

	initExpand();
})();
