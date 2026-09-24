/* Comportamentos gerais (sem scripts inline, compatível com a CSP). */
(function () {
    'use strict';
    var base = document.body.dataset.base || '';

    // PWA: registra o service worker.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () {});
        });
    }

    // Confirmação antes de ações destrutivas.
    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        var submitter = ev.submitter;
        var msg = (submitter && submitter.dataset.confirm) || f.dataset.confirm;
        if (msg && !window.confirm(msg)) ev.preventDefault();
    });

    // Copiar link.
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copy],[data-copy-text]');
        if (!btn) return;
        var text = btn.dataset.copyText || (document.querySelector(btn.dataset.copy) || {}).value || '';
        var done = function () {
            var old = btn.textContent;
            btn.textContent = 'Copiado!';
            setTimeout(function () { btn.textContent = old; }, 1500);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copie o link:', text); });
        } else {
            window.prompt('Copie o link:', text);
        }
    });

    // Filtros que se aplicam ao mudar.
    document.querySelectorAll('form[data-autosubmit]').forEach(function (f) {
        f.addEventListener('change', function () { f.submit(); });
    });

    // Bloqueio de dia inteiro esconde os campos de hora.
    var allDay = document.getElementById('b-allday');
    if (allDay) {
        var toggle = function () {
            document.querySelectorAll('[data-time-field]').forEach(function (el) { el.hidden = allDay.checked; });
        };
        allDay.addEventListener('change', toggle);
        toggle();
    }

    // Nova reserva no painel.
    var adminForm = document.getElementById('admin-booking-form');
    if (adminForm) {
        var clientSel = document.getElementById('client_id');
        var newClient = document.getElementById('new-client');
        var toggleClient = function () { newClient.hidden = clientSel.value !== 'nova'; };
        clientSel.addEventListener('change', toggleClient);
        toggleClient();

        var slotsBox = document.getElementById('admin-slots');
        var timeIn = document.getElementById('time');
        var fields = ['service_id', 'professional_id', 'location_type', 'service_area_id', 'date'];
        var load = function () {
            var v = function (id) { return document.getElementById(id).value; };
            slotsBox.innerHTML = '';
            if (!v('service_id') || !v('date')) return;
            var q = new URLSearchParams({
                servico: v('service_id'), profissional: v('professional_id'), data: v('date'),
                local: v('location_type'), area: v('service_area_id')
            });
            fetch(adminForm.dataset.slotsUrl + '?' + q, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    slotsBox.innerHTML = '';
                    var p = document.createElement('p');
                    p.className = 'slots-title small';
                    p.textContent = data.slots && data.slots.length ? 'Horários livres (clique para usar):' : 'Nenhum horário livre dentro do expediente nesta data.';
                    slotsBox.appendChild(p);
                    (data.slots || []).forEach(function (s) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'slot' + (timeIn.value === s ? ' selected' : '');
                        b.textContent = s;
                        b.onclick = function () {
                            timeIn.value = s;
                            slotsBox.querySelectorAll('.slot').forEach(function (el) { el.classList.toggle('selected', el === b); });
                        };
                        slotsBox.appendChild(b);
                    });
                })
                .catch(function () {});
        };
        fields.forEach(function (id) { document.getElementById(id).addEventListener('change', load); });
        load();
    }
})();
