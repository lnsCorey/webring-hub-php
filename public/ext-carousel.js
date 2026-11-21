/**
 * Progressive enhancement for the home-page Hub Extensions carousel.
 * Works without JS via CSS scroll-snap; this adds prev/next buttons, dots,
 * keyboard arrows, touch swipe, and polite screen-reader slide announcements.
 * No auto-rotation.
 */

/**
 * Pure slide-index wrap-around helper (tested directly in Node).
 *
 * @param {number} current
 * @param {number} delta
 * @param {number} count
 * @returns {number}
 */
export function stepCarouselIndex(current, delta, count) {
  if (!Number.isFinite(count) || count <= 0) return 0;
  const n = Math.floor(count);
  const cur = Number.isFinite(current) ? Math.floor(current) : 0;
  const d = Number.isFinite(delta) ? Math.trunc(delta) : 0;
  return (((cur + d) % n) + n) % n;
}

/**
 * Clamps an index into [0, count - 1], returning 0 when count <= 0.
 *
 * @param {number} index
 * @param {number} count
 * @returns {number}
 */
export function clampCarouselIndex(index, count) {
  if (!Number.isFinite(count) || count <= 0) return 0;
  const n = Math.floor(count);
  const i = Number.isFinite(index) ? Math.floor(index) : 0;
  return Math.max(0, Math.min(n - 1, i));
}

function initCarousel(root) {
  const track = root.querySelector('.ext-carousel-track');
  const slides = Array.from(root.querySelectorAll('.ext-card'));
  const prevBtn = root.querySelector('.ext-carousel-prev');
  const nextBtn = root.querySelector('.ext-carousel-next');
  const dots = Array.from(root.querySelectorAll('.ext-carousel-dot'));
  const statusEl = root.querySelector('.ext-carousel-status');

  if (!track || slides.length === 0) return;

  let activeIndex = 0;

  function prefersReducedMotion() {
    return typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function updateUi(index) {
    activeIndex = clampCarouselIndex(index, slides.length);
    if (statusEl) {
      statusEl.textContent = `Slide ${activeIndex + 1} of ${slides.length}`;
    }
    dots.forEach((dot, i) => {
      dot.setAttribute('aria-current', i === activeIndex ? 'true' : 'false');
    });
  }

  function goTo(index) {
    const next = stepCarouselIndex(0, index, slides.length);
    updateUi(next);
    const target = slides[next];
    if (target && typeof track.scrollTo === 'function') {
      track.scrollTo({
        left: target.offsetLeft - track.offsetLeft,
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
      });
    }
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      goTo(stepCarouselIndex(activeIndex, -1, slides.length));
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      goTo(stepCarouselIndex(activeIndex, 1, slides.length));
    });
  }

  dots.forEach((dot, idx) => {
    dot.addEventListener('click', () => goTo(idx));
  });

  root.addEventListener('keydown', (e) => {
    if (slides.length <= 1) return;
    if (e.key === 'ArrowLeft') {
      e.preventDefault();
      goTo(stepCarouselIndex(activeIndex, -1, slides.length));
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      goTo(stepCarouselIndex(activeIndex, 1, slides.length));
    }
  });

  // Touch swipe support
  let touchStartX = null;
  let touchStartY = null;
  track.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1) return;
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
  }, { passive: true });

  track.addEventListener('touchend', (e) => {
    if (touchStartX === null || touchStartY === null || slides.length <= 1) return;
    const t = e.changedTouches[0];
    const dx = t.clientX - touchStartX;
    const dy = t.clientY - touchStartY;
    touchStartX = null;
    touchStartY = null;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
      goTo(stepCarouselIndex(activeIndex, dx < 0 ? 1 : -1, slides.length));
    }
  }, { passive: true });

  // Keep dots & status in sync if the visitor scrolls the strip directly
  let scrollTimer = null;
  track.addEventListener('scroll', () => {
    if (scrollTimer) clearTimeout(scrollTimer);
    scrollTimer = setTimeout(() => {
      const scrollLeft = track.scrollLeft;
      let nearest = 0;
      let bestDist = Infinity;
      slides.forEach((slide, idx) => {
        const dist = Math.abs((slide.offsetLeft - track.offsetLeft) - scrollLeft);
        if (dist < bestDist) {
          bestDist = dist;
          nearest = idx;
        }
      });
      if (nearest !== activeIndex) {
        updateUi(nearest);
      }
    }, 80);
  }, { passive: true });

  updateUi(0);
}

if (typeof document !== 'undefined') {
  const boot = () => {
    document.querySelectorAll('.ext-carousel').forEach(initCarousel);
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}
