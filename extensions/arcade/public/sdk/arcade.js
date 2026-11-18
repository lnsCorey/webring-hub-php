(function () {
  'use strict';

  var params = new URLSearchParams(window.location.search || '');
  var sessionId = params.get('session') || '';
  var startTime = Date.now();
  var submitted = false;

  function post(payload) {
    if (!window.parent || window.parent === window) return;
    window.parent.postMessage(payload, '*');
  }

  window.Arcade = {
    protocol: 1,
    sessionId: sessionId,
    ready: function () {
      startTime = Date.now();
      submitted = false;
      post({
        type: 'arcade:ready',
        protocol: 1,
        session: sessionId
      });
    },
    score: function (opts) {
      if (submitted) return false;
      var rawScore = opts && typeof opts.score === 'number' ? Math.floor(opts.score) : 0;
      var durationMs =
        opts && typeof opts.durationMs === 'number'
          ? Math.max(0, Math.floor(opts.durationMs))
          : Math.max(0, Date.now() - startTime);
      var meta = opts && opts.meta && typeof opts.meta === 'object' ? opts.meta : undefined;
      submitted = true;
      post({
        type: 'arcade:score',
        protocol: 1,
        session: sessionId,
        score: rawScore,
        durationMs: durationMs,
        meta: meta
      });
      return true;
    }
  };
})();
