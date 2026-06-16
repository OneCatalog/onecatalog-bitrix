/**
 * AJAX-степпер импорта (§6): берёт public_id из picker'а или textarea, режет на
 * порции по «шагу импорта», шлёт их последовательно на ту же admin-страницу
 * (ajax=Y) и показывает прогресс + лог.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        var cfg = window.OneCatalogCfg;
        if (!cfg) { return; }
        var M = cfg.messages || {};

        var pickBtn = document.getElementById('oc-open-picker');
        var impBtn = document.getElementById('oc-import-btn');
        var ta = document.getElementById('oc-ids');
        var prog = document.getElementById('oc-progress');
        var logEl = document.getElementById('oc-log');

        if (pickBtn) {
            pickBtn.addEventListener('click', function () {
                window.OneCatalogPicker.open(cfg, function (ids) {
                    ids = ids || [];
                    ta.value = ids.join(', ');
                    runImport(ids);
                });
            });
        }
        if (impBtn) {
            impBtn.addEventListener('click', function () {
                runImport(splitIds(ta.value));
            });
        }

        function splitIds(s) {
            return String(s || '').split(/[\s,;]+/).filter(Boolean);
        }
        function chunk(a, n) {
            var r = [];
            for (var i = 0; i < a.length; i += n) { r.push(a.slice(i, i + n)); }
            return r;
        }

        function runImport(ids) {
            ids = (ids || []).filter(Boolean);
            if (!ids.length) { alert(M.empty || 'No IDs'); return; }

            var batches = chunk(ids, cfg.step || 10);
            var total = ids.length;
            var done = 0;
            var i = 0;

            prog.textContent = (M.importing || 'Importing...') + ' 0/' + total;

            function next() {
                if (i >= batches.length) {
                    prog.textContent = (M.done || 'Done') + ' ' + total + '/' + total;
                    return;
                }
                var b = batches[i++];
                var body = 'ajax=Y&act=import&sessid=' + encodeURIComponent(cfg.sessid);
                b.forEach(function (id) { body += '&ids[]=' + encodeURIComponent(id); });

                fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.error) { prog.textContent = (M.error || 'Error') + ': ' + d.error; return; }
                        done += b.length;
                        prog.textContent = (M.importing || 'Importing...') + ' '
                            + Math.min(done, total) + '/' + total;
                        if (d.log) { renderLog(d.log); }
                        next();
                    })
                    .catch(function () { prog.textContent = M.error || 'Error'; });
            }
            next();
        }

        function renderLog(log) {
            logEl.textContent = log.slice(-50).map(function (e) {
                return '[' + e.status + '] ' + e.public_id + (e.message ? ' — ' + e.message : '');
            }).join('\n');
        }
    });
})();
