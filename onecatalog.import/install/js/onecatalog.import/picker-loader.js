/**
 * OneCatalog Picker loader (§2.4): встраивает iframe виджета выбора товаров и
 * принимает выбор через postMessage. Origin виджета ФИКСИРОВАН настройкой
 * (cfg.pickerBase) и проверяется на каждое сообщение (event.origin).
 *
 * Выбор передаётся по productPublicIds (стабильные ключи), не по числовым id.
 */
(function () {
    'use strict';

    window.OneCatalogPicker = {
        /**
         * @param {{pickerBase:string, token:string, parentOrigin:string}} cfg
         * @param {function(string[])} onSelected
         */
        open: function (cfg, onSelected) {
            var base = String(cfg.pickerBase || '').replace(/\/+$/, '');
            if (!base) { return; }

            var url = base + '/picker.html'
                + '?token=' + encodeURIComponent(cfg.token || '')
                + '&parentOrigin=' + encodeURIComponent(cfg.parentOrigin || '');

            var overlay = document.createElement('div');
            overlay.style.cssText =
                'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;';

            var frame = document.createElement('iframe');
            frame.src = url;
            frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-same-origin');
            frame.style.cssText =
                'position:absolute;top:4%;left:4%;width:92%;height:92%;border:0;'
                + 'background:#fff;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.3);';

            overlay.appendChild(frame);
            document.body.appendChild(overlay);

            function cleanup() {
                window.removeEventListener('message', handler);
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            }

            function handler(e) {
                // Проверка origin — принимаем только от зафиксированного виджета.
                if (e.origin !== base) { return; }
                var data = e.data || {};
                if (data.type === 'ONECATALOG_SELECTED') {
                    cleanup();
                    if (typeof onSelected === 'function') {
                        onSelected(data.productPublicIds || []);
                    }
                } else if (data.type === 'ONECATALOG_CLOSE') {
                    cleanup();
                }
            }

            window.addEventListener('message', handler);
            overlay.addEventListener('click', function (ev) {
                if (ev.target === overlay) { cleanup(); }
            });
        }
    };
})();
