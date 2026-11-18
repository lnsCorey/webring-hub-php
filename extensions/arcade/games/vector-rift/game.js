(function () {
  'use strict';

  const canvas = document.getElementById('stage');
  const ctx = canvas.getContext('2d');
  const scoreEl = document.getElementById('score');
  const waveEl = document.getElementById('wave');
  const livesEl = document.getElementById('lives');

  const LANES = 12;
  const CX = canvas.width / 2;
  const CY = canvas.height / 2;
  const OUTER_R = 195;
  const INNER_R = 38;

  let audioCtx = null;
  function beep(freq, dur, type) {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = type || 'sawtooth';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.06, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + dur);
    } catch {
      // Audio optional
    }
  }

  let lane = 0;
  let score = 0;
  let wave = 1;
  let lives = 3;
  let gameOver = false;
  let startedAt = Date.now();
  let shots = [];
  let foes = [];
  let spawnTimer = 0;
  let killsThisWave = 0;

  function resetGame() {
    lane = 0;
    score = 0;
    wave = 1;
    lives = 3;
    gameOver = false;
    startedAt = Date.now();
    shots = [];
    foes = [];
    spawnTimer = 0;
    killsThisWave = 0;
    updateHud();
    if (window.Arcade) window.Arcade.ready();
  }

  function updateHud() {
    scoreEl.textContent = String(score);
    waveEl.textContent = String(wave);
    livesEl.textContent = String(lives);
  }

  function lanePoint(laneIndex, depth) {
    const angle = ((laneIndex % LANES) / LANES) * Math.PI * 2 - Math.PI / 2;
    const r = INNER_R + (OUTER_R - INNER_R) * depth;
    return { x: CX + Math.cos(angle) * r, y: CY + Math.sin(angle) * r };
  }

  window.addEventListener('keydown', (e) => {
    if (gameOver && (e.key === 'Enter' || e.key === 'r' || e.key === 'R')) {
      resetGame();
      return;
    }
    if (gameOver) return;
    if (e.key === 'ArrowLeft' || e.key === 'a' || e.key === 'A') {
      lane = (lane + LANES - 1) % LANES;
      beep(320, 0.04, 'triangle');
    } else if (e.key === 'ArrowRight' || e.key === 'd' || e.key === 'D') {
      lane = (lane + 1) % LANES;
      beep(360, 0.04, 'triangle');
    } else if (e.key === ' ' || e.key === 'z' || e.key === 'Z') {
      e.preventDefault();
      if (shots.length < 6) {
        shots.push({ lane, depth: 0.96 });
        beep(680, 0.07, 'square');
      }
    }
  });

  function step() {
    if (!gameOver) {
      spawnTimer++;
      const spawnInterval = Math.max(20, 62 - wave * 5);
      if (spawnTimer >= spawnInterval) {
        spawnTimer = 0;
        foes.push({
          lane: Math.floor(Math.random() * LANES),
          depth: 0.02,
          speed: 0.0045 + wave * 0.0009,
        });
      }

      for (let i = shots.length - 1; i >= 0; i--) {
        shots[i].depth -= 0.032;
        if (shots[i].depth <= 0) shots.splice(i, 1);
      }

      for (let i = foes.length - 1; i >= 0; i--) {
        const f = foes[i];
        f.depth += f.speed;
        let hit = false;
        for (let j = shots.length - 1; j >= 0; j--) {
          if (shots[j].lane === f.lane && Math.abs(shots[j].depth - f.depth) < 0.07) {
            shots.splice(j, 1);
            hit = true;
            break;
          }
        }
        if (hit) {
          foes.splice(i, 1);
          score += 100 * wave;
          killsThisWave++;
          if (killsThisWave >= 10 + wave * 2) {
            wave++;
            killsThisWave = 0;
          }
          beep(220, 0.09, 'sawtooth');
          updateHud();
          continue;
        }
        if (f.depth >= 1.0) {
          foes.splice(i, 1);
          lives--;
          beep(110, 0.2, 'sawtooth');
          updateHud();
          if (lives <= 0) {
            gameOver = true;
            if (window.Arcade) {
              window.Arcade.score({
                score,
                durationMs: Date.now() - startedAt,
                meta: { wave },
              });
            }
          }
        }
      }
    }
    draw();
    requestAnimationFrame(step);
  }

  function draw() {
    ctx.fillStyle = '#03050c';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Draw tunnel web
    ctx.strokeStyle = '#0ea5e9';
    ctx.lineWidth = 1.2;
    for (let i = 0; i < LANES; i++) {
      const o1 = lanePoint(i, 1);
      const o2 = lanePoint(i + 1, 1);
      const i1 = lanePoint(i, 0);
      const i2 = lanePoint(i + 1, 0);
      ctx.beginPath();
      ctx.moveTo(o1.x, o1.y);
      ctx.lineTo(o2.x, o2.y);
      ctx.moveTo(i1.x, i1.y);
      ctx.lineTo(i2.x, i2.y);
      ctx.moveTo(i1.x, i1.y);
      ctx.lineTo(o1.x, o1.y);
      ctx.stroke();
    }

    // Highlight active player lane
    const pA = lanePoint(lane, 1);
    const pB = lanePoint(lane + 1, 1);
    const pMid = { x: (pA.x + pB.x) / 2, y: (pA.y + pB.y) / 2 };
    ctx.strokeStyle = '#facc15';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(pA.x, pA.y);
    ctx.lineTo(pMid.x * 0.92 + CX * 0.08, pMid.y * 0.92 + CY * 0.08);
    ctx.lineTo(pB.x, pB.y);
    ctx.stroke();

    // Draw shots
    ctx.fillStyle = '#facc15';
    shots.forEach((s) => {
      const a = lanePoint(s.lane, s.depth);
      const b = lanePoint(s.lane + 1, s.depth);
      ctx.beginPath();
      ctx.arc((a.x + b.x) / 2, (a.y + b.y) / 2, 4, 0, Math.PI * 2);
      ctx.fill();
    });

    // Draw foes
    ctx.strokeStyle = '#f43f5e';
    ctx.lineWidth = 2.2;
    foes.forEach((f) => {
      const a = lanePoint(f.lane, f.depth);
      const b = lanePoint(f.lane + 1, f.depth);
      const mx = (a.x + b.x) / 2;
      const my = (a.y + b.y) / 2;
      const r = 4 + f.depth * 10;
      ctx.beginPath();
      ctx.moveTo(mx, my - r);
      ctx.lineTo(mx + r, my);
      ctx.lineTo(mx, my + r);
      ctx.lineTo(mx - r, my);
      ctx.closePath();
      ctx.stroke();
    });

    if (gameOver) {
      ctx.fillStyle = 'rgba(3, 5, 12, 0.78)';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#f8fafc';
      ctx.font = 'bold 28px monospace';
      ctx.textAlign = 'center';
      ctx.fillText('GAME OVER', CX, CY - 16);
      ctx.font = '16px monospace';
      ctx.fillStyle = '#38bdf8';
      ctx.fillText(`FINAL SCORE: ${score}  •  PRESS ENTER TO PLAY AGAIN`, CX, CY + 22);
    }
  }

  resetGame();
  requestAnimationFrame(step);
})();
