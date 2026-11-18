(function () {
  'use strict';

  const canvas = document.getElementById('stage');
  const ctx = canvas.getContext('2d');
  const scoreEl = document.getElementById('score');
  const waveEl = document.getElementById('wave');
  const livesEl = document.getElementById('lives');

  const W = canvas.width;
  const H = canvas.height;

  // 2-frame pixel-art sprites (original designs, 11x8 / 12x8)
  const SPRITES = {
    squid: [
      [
        '....###....',
        '...#####...',
        '..#######..',
        '.##.###.##.',
        '.#########.',
        '...#...#...',
        '..#.#.#.#..',
        '.#.......#.',
      ],
      [
        '....###....',
        '...#####...',
        '..#######..',
        '.##.###.##.',
        '.#########.',
        '..#..#..#..',
        '.#.......#.',
        '..#.....#..',
      ],
    ],
    crab: [
      [
        '..#.....#..',
        '...#...#...',
        '..#######..',
        '.##.###.##.',
        '###########',
        '#.#######.#',
        '#.#.....#.#',
        '...##.##...',
      ],
      [
        '..#.....#..',
        '#..#...#..#',
        '#.#######.#',
        '###.###.###',
        '###########',
        '.#########.',
        '..#.....#..',
        '.#.......#.',
      ],
    ],
    drone: [
      [
        '....####....',
        '.##########.',
        '############',
        '###..##..###',
        '############',
        '...##..##...',
        '..##.##.##..',
        '##........##',
      ],
      [
        '....####....',
        '.##########.',
        '############',
        '###..##..###',
        '############',
        '..###..###..',
        '.##..##..##.',
        '..##....##..',
      ],
    ],
    ufo: [
      '.....######.....',
      '...##########...',
      '..############..',
      '.##.##.##.##.##.',
      '################',
      '..###..##..###..',
      '...#........#...',
    ],
    cannon: [
      '......#......',
      '.....###.....',
      '.....###.....',
      '.###########.',
      '#############',
      '#############',
      '#############',
    ],
  };

  let audioCtx = null;
  function tone(freq, dur, type, vol) {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = type || 'square';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(vol || 0.045, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + dur);
    } catch {
      // Optional audio
    }
  }

  const MARCH_NOTES = [98, 87.31, 82.41, 73.42];
  let marchNoteIdx = 0;

  // Starfield
  const stars = Array.from({ length: 75 }, () => ({
    x: Math.random() * W,
    y: Math.random() * H,
    r: Math.random() < 0.25 ? 1.8 : 1,
    speed: 0.15 + Math.random() * 0.45,
    alpha: 0.25 + Math.random() * 0.65,
  }));

  const keys = {};
  let playerX = W / 2;
  let invulnFrames = 0;
  let muzzleFlash = 0;
  let score = 0;
  let wave = 1;
  let lives = 3;
  let gameOver = false;
  let startedAt = Date.now();

  let bullets = [];
  let enemyBullets = [];
  let enemies = [];
  let divers = [];
  let bunkers = [];
  let particles = [];
  let ufo = null;
  let ufoTimer = 420;

  let dir = 1;
  let marchFrame = 0;
  let marchTimer = 0;
  let playerCooldown = 0;
  let enemyFireTimer = 65;
  let diveTimer = 260;

  function drawBitmap(bitmap, x, y, scale, color, glow) {
    ctx.save();
    ctx.fillStyle = color;
    if (glow) {
      ctx.shadowColor = color;
      ctx.shadowBlur = 8;
    }
    for (let r = 0; r < bitmap.length; r++) {
      const row = bitmap[r];
      for (let c = 0; c < row.length; c++) {
        if (row[c] === '#') {
          ctx.fillRect(Math.round(x + c * scale), Math.round(y + r * scale), scale, scale);
        }
      }
    }
    ctx.restore();
  }

  function spawnParticles(cx, cy, color, count) {
    for (let i = 0; i < count; i++) {
      const angle = Math.random() * Math.PI * 2;
      const speed = 1 + Math.random() * 3.4;
      particles.push({
        x: cx,
        y: cy,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        life: 18 + Math.floor(Math.random() * 14),
        maxLife: 32,
        color,
        size: Math.random() < 0.4 ? 3 : 2,
      });
    }
  }

  function spawnWave() {
    enemies = [];
    divers = [];
    const cols = 9;
    const rows = 4;
    const startX = 88;
    const startY = 64;
    for (let r = 0; r < rows; r++) {
      const kind = r === 0 ? 'squid' : r <= 2 ? 'crab' : 'drone';
      const color = r === 0 ? '#f43f5e' : r === 1 ? '#fb923c' : r === 2 ? '#a78bfa' : '#34d399';
      const pts = r === 0 ? 150 : r <= 2 ? 100 : 50;
      for (let c = 0; c < cols; c++) {
        enemies.push({
          x: startX + c * 52,
          y: startY + r * 36,
          w: 33,
          h: 24,
          row: r,
          col: c,
          kind,
          color,
          pts,
          alive: true,
        });
      }
    }
    dir = 1;
    marchTimer = 0;
    enemyFireTimer = 60;
    diveTimer = Math.max(160, 280 - wave * 18);
  }

  function resetBunkers() {
    // 4 arched pixel-cell bunkers (11 cols x 6 rows of 5px blocks)
    const pattern = [
      '..#######..',
      '.#########.',
      '###########',
      '###########',
      '####...####',
      '###.....###',
    ];
    bunkers = [110, 245, 395, 530].map((cx) => {
      const cells = [];
      const bx = cx - (11 * 5) / 2;
      const by = 374;
      for (let r = 0; r < pattern.length; r++) {
        for (let c = 0; c < pattern[r].length; c++) {
          if (pattern[r][c] === '#') {
            cells.push({ x: bx + c * 5, y: by + r * 5, w: 5, h: 5, hp: 2 });
          }
        }
      }
      return cells;
    });
  }

  function resetGame() {
    playerX = W / 2;
    invulnFrames = 0;
    muzzleFlash = 0;
    score = 0;
    wave = 1;
    lives = 3;
    gameOver = false;
    startedAt = Date.now();
    bullets = [];
    enemyBullets = [];
    particles = [];
    ufo = null;
    ufoTimer = 420;
    resetBunkers();
    spawnWave();
    updateHud();
    if (window.Arcade) window.Arcade.ready();
  }

  function updateHud() {
    scoreEl.textContent = String(score);
    waveEl.textContent = String(wave);
    livesEl.textContent = String(lives);
  }

  window.addEventListener('keydown', (e) => {
    keys[e.key] = true;
    if (gameOver && (e.key === 'Enter' || e.key === 'r' || e.key === 'R')) {
      resetGame();
    }
    if (e.key === ' ') e.preventDefault();
  });
  window.addEventListener('keyup', (e) => {
    keys[e.key] = false;
  });

  function damageBunkersAt(px, py, radius) {
    let hitAny = false;
    for (const bunker of bunkers) {
      for (let i = bunker.length - 1; i >= 0; i--) {
        const cell = bunker[i];
        if (
          px + radius >= cell.x &&
          px - radius <= cell.x + cell.w &&
          py + radius >= cell.y &&
          py - radius <= cell.y + cell.h
        ) {
          cell.hp--;
          if (cell.hp <= 0) bunker.splice(i, 1);
          hitAny = true;
        }
      }
    }
    return hitAny;
  }

  function hitPlayer() {
    if (invulnFrames > 0 || gameOver) return;
    lives--;
    invulnFrames = 90;
    spawnParticles(playerX, 434, '#facc15', 18);
    tone(110, 0.25, 'sawtooth', 0.08);
    updateHud();
    if (lives <= 0) endRun();
  }

  function endRun() {
    if (gameOver) return;
    gameOver = true;
    if (window.Arcade) {
      window.Arcade.score({
        score,
        durationMs: Date.now() - startedAt,
        meta: { wave },
      });
    }
  }

  function step() {
    // Twinkle starfield even on game over
    for (const s of stars) {
      s.y += s.speed;
      if (s.y > H) {
        s.y = 0;
        s.x = Math.random() * W;
      }
    }

    for (let i = particles.length - 1; i >= 0; i--) {
      const p = particles[i];
      p.x += p.vx;
      p.y += p.vy;
      p.life--;
      if (p.life <= 0) particles.splice(i, 1);
    }

    if (!gameOver) {
      if (invulnFrames > 0) invulnFrames--;
      if (muzzleFlash > 0) muzzleFlash--;

      if (keys.ArrowLeft || keys.a || keys.A) playerX = Math.max(28, playerX - 4.6);
      if (keys.ArrowRight || keys.d || keys.D) playerX = Math.min(W - 28, playerX + 4.6);
      if (playerCooldown > 0) playerCooldown--;
      if ((keys[' '] || keys.z || keys.Z) && playerCooldown === 0 && bullets.length < 2) {
        bullets.push({ x: playerX, y: 416 });
        playerCooldown = 14;
        muzzleFlash = 5;
        tone(640, 0.06, 'square', 0.04);
      }

      // Bonus UFO cruiser
      if (!ufo) {
        ufoTimer--;
        if (ufoTimer <= 0) {
          const fromLeft = Math.random() < 0.5;
          ufo = {
            x: fromLeft ? -48 : W + 48,
            y: 26,
            vx: fromLeft ? 2.1 : -2.1,
            w: 48,
            h: 21,
          };
          ufoTimer = 550 + Math.floor(Math.random() * 350);
        }
      } else {
        ufo.x += ufo.vx;
        if (Math.random() < 0.12) tone(440 + Math.sin(ufo.x * 0.1) * 90, 0.05, 'triangle', 0.02);
        if (ufo.x < -60 || ufo.x > W + 60) ufo = null;
      }

      const aliveEnemies = enemies.filter((e) => e.alive);
      if (aliveEnemies.length === 0 && divers.length === 0) {
        wave++;
        spawnWave();
        updateHud();
      } else {
        // Stepped arcade march: faster as fewer enemies remain
        const marchInterval = Math.max(7, Math.floor(8 + aliveEnemies.length * 0.85 - wave * 2));
        marchTimer++;
        if (marchTimer >= marchInterval && aliveEnemies.length > 0) {
          marchTimer = 0;
          marchFrame = 1 - marchFrame;
          tone(MARCH_NOTES[marchNoteIdx % MARCH_NOTES.length], 0.06, 'triangle', 0.03);
          marchNoteIdx++;

          const stepX = (10 + Math.min(6, wave)) * dir;
          let hitEdge = false;
          for (const e of aliveEnemies) {
            if (e.x + stepX < 22 || e.x + e.w + stepX > W - 22) {
              hitEdge = true;
              break;
            }
          }
          if (hitEdge) {
            dir = -dir;
            for (const e of aliveEnemies) {
              e.y += 16;
              if (e.y + e.h >= 412) {
                lives = 0;
                endRun();
              }
            }
          } else {
            for (const e of aliveEnemies) {
              e.x += stepX;
            }
          }
        }

        // Occasional diving raider so enemies don't all move in one rigid block
        diveTimer--;
        if (diveTimer <= 0 && aliveEnemies.length > 2 && divers.length < 1 + Math.floor(wave / 3)) {
          const pick = aliveEnemies[Math.floor(Math.random() * aliveEnemies.length)];
          pick.alive = false;
          divers.push({
            x: pick.x,
            y: pick.y,
            w: pick.w,
            h: pick.h,
            vx: (playerX - pick.x) * 0.012,
            vy: 2.3 + Math.min(1.5, wave * 0.2),
            phase: Math.random() * Math.PI * 2,
            kind: pick.kind,
            color: pick.color,
            pts: pick.pts * 2,
          });
          diveTimer = Math.max(150, 290 - wave * 20);
        }

        // Fair, paced enemy firing: only bottom-most enemy per column can shoot, capped on screen
        const maxEnemyBullets = Math.min(4, 2 + Math.floor(wave / 2));
        enemyFireTimer--;
        if (enemyFireTimer <= 0 && enemyBullets.length < maxEnemyBullets && aliveEnemies.length > 0) {
          const bottomByCol = new Map();
          for (const e of aliveEnemies) {
            const cur = bottomByCol.get(e.col);
            if (!cur || e.row > cur.row) bottomByCol.set(e.col, e);
          }
          const shooters = Array.from(bottomByCol.values());
          const shooter = shooters[Math.floor(Math.random() * shooters.length)];
          enemyBullets.push({
            x: shooter.x + shooter.w / 2,
            y: shooter.y + shooter.h,
            vy: 3.1 + Math.min(2.0, wave * 0.25),
            zig: Math.random() * Math.PI * 2,
          });
          enemyFireTimer = Math.max(28, 72 - wave * 6) + Math.floor(Math.random() * 24);
        }
      }

      // Move diving raiders
      for (let i = divers.length - 1; i >= 0; i--) {
        const d = divers[i];
        d.phase += 0.1;
        d.x += d.vx + Math.sin(d.phase) * 2.1;
        d.y += d.vy;
        if (d.x < 16) d.x = 16;
        if (d.x + d.w > W - 16) d.x = W - 16 - d.w;

        if (Math.abs(d.x + d.w / 2 - playerX) < 22 && d.y + d.h >= 420 && d.y <= 448) {
          divers.splice(i, 1);
          spawnParticles(d.x + d.w / 2, d.y + d.h / 2, d.color, 12);
          hitPlayer();
          continue;
        }
        if (d.y > H + 20) {
          divers.splice(i, 1);
        }
      }

      // Player lasers
      for (let i = bullets.length - 1; i >= 0; i--) {
        const b = bullets[i];
        b.y -= 8;
        if (b.y < 0) {
          bullets.splice(i, 1);
          continue;
        }
        if (damageBunkersAt(b.x, b.y, 2)) {
          bullets.splice(i, 1);
          continue;
        }

        // Intercept enemy bolts
        let intercepted = false;
        for (let j = enemyBullets.length - 1; j >= 0; j--) {
          const eb = enemyBullets[j];
          if (Math.abs(b.x - eb.x) < 6 && Math.abs(b.y - eb.y) < 10) {
            enemyBullets.splice(j, 1);
            spawnParticles(b.x, b.y, '#38bdf8', 5);
            intercepted = true;
            break;
          }
        }
        if (intercepted) {
          bullets.splice(i, 1);
          continue;
        }

        // Hit UFO
        if (ufo && b.x >= ufo.x && b.x <= ufo.x + ufo.w && b.y >= ufo.y && b.y <= ufo.y + ufo.h) {
          bullets.splice(i, 1);
          score += 250 * wave;
          spawnParticles(ufo.x + ufo.w / 2, ufo.y + ufo.h / 2, '#facc15', 18);
          tone(520, 0.14, 'sawtooth', 0.06);
          ufo = null;
          updateHud();
          continue;
        }

        // Hit divers
        let hitDiver = false;
        for (let j = divers.length - 1; j >= 0; j--) {
          const d = divers[j];
          if (b.x >= d.x && b.x <= d.x + d.w && b.y >= d.y && b.y <= d.y + d.h) {
            divers.splice(j, 1);
            score += d.pts * wave;
            spawnParticles(d.x + d.w / 2, d.y + d.h / 2, d.color, 14);
            tone(290, 0.09, 'sawtooth', 0.05);
            updateHud();
            hitDiver = true;
            break;
          }
        }
        if (hitDiver) {
          bullets.splice(i, 1);
          continue;
        }

        // Hit formation enemies
        for (const e of enemies) {
          if (e.alive && b.x >= e.x && b.x <= e.x + e.w && b.y >= e.y && b.y <= e.y + e.h) {
            e.alive = false;
            bullets.splice(i, 1);
            score += e.pts * wave;
            spawnParticles(e.x + e.w / 2, e.y + e.h / 2, e.color, 12);
            tone(240, 0.08, 'sawtooth', 0.05);
            updateHud();
            break;
          }
        }
      }

      // Enemy bolts
      for (let i = enemyBullets.length - 1; i >= 0; i--) {
        const eb = enemyBullets[i];
        eb.y += eb.vy;
        eb.zig += 0.35;
        if (eb.y > H) {
          enemyBullets.splice(i, 1);
          continue;
        }
        if (damageBunkersAt(eb.x, eb.y, 3)) {
          spawnParticles(eb.x, eb.y, '#38bdf8', 4);
          enemyBullets.splice(i, 1);
          continue;
        }
        if (Math.abs(eb.x - playerX) < 18 && eb.y >= 422 && eb.y <= 446) {
          enemyBullets.splice(i, 1);
          hitPlayer();
        }
      }
    }

    draw();
    requestAnimationFrame(step);
  }

  function draw() {
    ctx.fillStyle = '#04070f';
    ctx.fillRect(0, 0, W, H);

    // Starfield
    for (const s of stars) {
      ctx.fillStyle = `rgba(148, 163, 184, ${s.alpha})`;
      ctx.fillRect(s.x, s.y, s.r, s.r);
    }

    // Horizon defense line
    ctx.fillStyle = '#1e293b';
    ctx.fillRect(16, 456, W - 32, 2);

    // Bunkers
    ctx.fillStyle = '#38bdf8';
    for (const bunker of bunkers) {
      for (const cell of bunker) {
        ctx.globalAlpha = cell.hp === 2 ? 0.92 : 0.45;
        ctx.fillRect(cell.x, cell.y, cell.w - 0.5, cell.h - 0.5);
      }
    }
    ctx.globalAlpha = 1;

    // Bonus UFO
    if (ufo) {
      drawBitmap(SPRITES.ufo, ufo.x, ufo.y, 3, '#facc15', true);
    }

    // Player cannon (blinks while invulnerable)
    if (invulnFrames === 0 || Math.floor(invulnFrames / 5) % 2 === 0) {
      drawBitmap(SPRITES.cannon, playerX - 19.5, 424, 3, '#facc15', true);
      if (muzzleFlash > 0) {
        ctx.fillStyle = '#fef08a';
        ctx.fillRect(playerX - 2, 416, 4, 8);
      }
    }

    // Formation enemies
    for (const e of enemies) {
      if (!e.alive) continue;
      drawBitmap(SPRITES[e.kind][marchFrame], e.x, e.y, 3, e.color, false);
    }

    // Diving raiders
    for (const d of divers) {
      drawBitmap(SPRITES[d.kind][marchFrame], d.x, d.y, 3, d.color, true);
    }

    // Player lasers
    ctx.fillStyle = '#fde047';
    for (const b of bullets) {
      ctx.fillRect(b.x - 1.5, b.y - 10, 3, 12);
    }

    // Zig-zag enemy plasma bolts
    ctx.strokeStyle = '#fb7185';
    ctx.lineWidth = 2;
    for (const eb of enemyBullets) {
      const z = Math.sin(eb.zig) * 3;
      ctx.beginPath();
      ctx.moveTo(eb.x - z, eb.y - 6);
      ctx.lineTo(eb.x + z, eb.y);
      ctx.lineTo(eb.x - z, eb.y + 6);
      ctx.stroke();
    }

    // Particles
    for (const p of particles) {
      ctx.globalAlpha = Math.max(0, p.life / p.maxLife);
      ctx.fillStyle = p.color;
      ctx.fillRect(p.x, p.y, p.size, p.size);
    }
    ctx.globalAlpha = 1;

    if (gameOver) {
      ctx.fillStyle = 'rgba(4, 7, 15, 0.82)';
      ctx.fillRect(0, 0, W, H);
      ctx.fillStyle = '#f8fafc';
      ctx.font = 'bold 30px monospace';
      ctx.textAlign = 'center';
      ctx.fillText('GAME OVER', W / 2, H / 2 - 16);
      ctx.font = '15px monospace';
      ctx.fillStyle = '#34d399';
      ctx.fillText(`FINAL SCORE: ${score}  •  PRESS ENTER TO PLAY AGAIN`, W / 2, H / 2 + 22);
    }
  }

  resetGame();
  requestAnimationFrame(step);
})();
