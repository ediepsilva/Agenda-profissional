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

    // "Nova cliente" x cliente existente.
    var clientSel = document.querySelector('select[data-toggle-new-client]');
    var newClient = document.getElementById('new-client');
    if (clientSel && newClient) {
        var toggleClient = function () { newClient.hidden = clientSel.value !== 'nova'; };
        clientSel.addEventListener('change', toggleClient);
        toggleClient();
    }

    // Evento: consulta quais profissionais estão livres no período.
    var eventForm = document.getElementById('event-form');
    var checkTeam = document.getElementById('check-team');
    if (eventForm && checkTeam) {
        checkTeam.addEventListener('click', function () {
            var v = function (id) { return document.getElementById(id).value; };
            if (!v('service_id') || !v('date') || !v('time')) {
                window.alert('Preencha serviço, data e início para consultar a equipe.');
                return;
            }
            var q = new URLSearchParams({
                servico: v('service_id'), data: v('date'), hora: v('time'), duracao: v('duration_minutes'),
                local: v('location_type'), area: v('service_area_id')
            });
            fetch(eventForm.dataset.suggestUrl + '?' + q, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    (data.professionals || []).forEach(function (p) {
                        var row = document.querySelector('#team-pick [data-pro="' + p.id + '"] .pro-status');
                        if (row) {
                            row.textContent = p.free ? '· livre (' + p.day_load + ' no dia)' : '· ocupada ou fora do expediente';
                            row.className = 'small pro-status ' + (p.free ? 'text-ok' : 'text-danger');
                        }
                    });
                })
                .catch(function () {});
        });
    }

    // Nova reserva no painel.
    var adminForm = document.getElementById('admin-booking-form');
    if (adminForm) {
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
