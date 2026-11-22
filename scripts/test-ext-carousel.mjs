import assert from 'node:assert/strict';
import { stepCarouselIndex, clampCarouselIndex } from '../public/ext-carousel.js';

// Forward and backward wrap-around
assert.equal(stepCarouselIndex(0, 1, 3), 1);
assert.equal(stepCarouselIndex(2, 1, 3), 0);
assert.equal(stepCarouselIndex(0, -1, 3), 2);
assert.equal(stepCarouselIndex(1, -2, 3), 2);
assert.equal(stepCarouselIndex(0, 5, 3), 2);

// Single-slide and empty edge cases
assert.equal(stepCarouselIndex(0, 1, 1), 0);
assert.equal(stepCarouselIndex(0, -1, 1), 0);
assert.equal(stepCarouselIndex(0, 1, 0), 0);
assert.equal(stepCarouselIndex(0, 1, -4), 0);

// Clamping
assert.equal(clampCarouselIndex(-1, 3), 0);
assert.equal(clampCarouselIndex(5, 3), 2);
assert.equal(clampCarouselIndex(1, 3), 1);
assert.equal(clampCarouselIndex(2, 0), 0);

console.log('ext-carousel tests passed');
