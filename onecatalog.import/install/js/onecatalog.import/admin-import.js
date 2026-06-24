/**
 * AJAX-степпер импорта (§6): берёт public_id из picker'а ИЛИ из textarea, режет на
 * порции по «шагу импорта», шлёт их последовательно на ту же admin-страницу
 * (ajax=Y) и показывает прогресс (спиннер + бар) + лог.
 *
 * Во время импорта обе кнопки и поле ввода блокируются, повторный запуск запрещён —
 * чтобы два способа (модалка / поле ввода) не запускали импорт одновременно.
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
        var status = document.getElementById('oc-status');
        var spinner = document.getElementById('oc-spinner');
        var prog = document.getElementById('oc-progress');
        var bar = document.getElementById('oc-bar');
        var logEl = document.getElementById('oc-log');

        var busy = false;

        function beforeUnload(e) { e.preventDefault(); e.returnValue = ''; return ''; }

        function setBusy(b) {
            busy = b;
            if (pickBtn) { pickBtn.disabled = b; }
            if (impBtn) { impBtn.disabled = b; }
            if (ta) { ta.disabled = b; }
            if (spinner) { spinner.style.display = b ? 'inline-block' : 'none'; }
            if (status) { status.style.display = 'block'; }
            // Предупреждать об уходе со страницы, пока импорт идёт.
            if (b) { window.addEventListener('beforeunload', beforeUnload); }
            else { window.removeEventListener('beforeunload', beforeUnload); }
        }

        function setProgress(done, total, msgKey, cls) {
            var pct = total > 0 ? Math.round((Math.min(done, total) / total) * 100) : 0;
            if (bar) { bar.style.width = pct + '%'; bar.className = 'oc-bar' + (cls ? ' ' + cls : ''); }
            if (prog) {
                prog.textContent = (M[msgKey] || msgKey) + ' ' + Math.min(done, total) + '/' + total;
            }
        }

        if (pickBtn) {
            pickBtn.addEventListener('click', function () {
                if (busy) { return; }
                window.OneCatalogPicker.open(cfg, function (ids) {
                    // НЕ трогаем поле ввода — модалка и поле ввода независимы.
                    runImport(ids || []);
                });
            });
        }
        if (impBtn) {
            impBtn.addEventListener('click', function () {
                if (busy) { return; }
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
            if (busy) { return; }
            ids = (ids || []).filter(Boolean);
            if (!ids.length) { alert(M.empty || 'No IDs'); return; }

            var batches = chunk(ids, cfg.step || 10);
            var total = ids.length;
            var done = 0;
            var i = 0;

            setBusy(true);
            setProgress(0, total, 'importing');

            function finish(msgKey, cls, text) {
                setBusy(false);
                if (bar) { bar.className = 'oc-bar' + (cls ? ' ' + cls : ''); }
                if (prog && text != null) { prog.textContent = text; }
            }

            function next() {
                if (i >= batches.length) {
                    if (bar) { bar.style.width = '100%'; }
                    finish('done', 'oc-ok', (M.done || 'Done') + ' ' + total + '/' + total);
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
                        if (d.error) {
                            finish('error', 'oc-err', (M.error || 'Error') + ': ' + d.error);
                            return;
                        }
                        done += b.length;
                        setProgress(done, total, 'importing');
                        if (d.log) { renderLog(d.log); }
                        next();
                    })
                    .catch(function () { finish('error', 'oc-err', M.error || 'Error'); });
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
