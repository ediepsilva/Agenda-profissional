/* Fluxo de agendamento público: serviço → local → data (calendário) → horário. */
(function () {
    'use strict';
    var form = document.getElementById('booking-form');
    if (!form) return;

    var MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    var DOWS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    var cal = document.getElementById('calendar');
    var slotsEl = document.getElementById('slots');
    var dateIn = document.getElementById('date');
    var timeIn = document.getElementById('time');
    var clientLoc = document.getElementById('client-location');
    var areaSel = document.getElementById('service_area_id');
    var summary = document.getElementById('summary');
    var reqId = 0;

    var today = new Date();
    var thisMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    var viewMonth = thisMonth;
    var pendingTime = form.dataset.oldTime || '';
    if (form.dataset.oldDate) {
        var p = form.dataset.oldDate.split('-');
        viewMonth = new Date(+p[0], +p[1] - 1, 1);
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ym(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
    function ymd(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function brDate(s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }

    function service() { return form.querySelector('input[name=service_id]:checked'); }
    function locationType() {
        var r = form.querySelector('input[name=location_type]:checked');
        return r ? r.value : 'studio';
    }
    function ready() {
        return !!service() && (locationType() !== 'client' || !!areaSel.value);
    }
    function query(extra) {
        var q = new URLSearchParams({
            servico: service().value,
            local: locationType(),
            area: locationType() === 'client' ? areaSel.value : ''
        });
        Object.keys(extra).forEach(function (k) { q.set(k, extra[k]); });
        return q.toString();
    }
    function message(el, text) {
        el.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'muted';
        p.textContent = text;
        el.appendChild(p);
    }

    function applyServiceMode() {
        var s = service();
        var mode = s ? s.dataset.mode : 'both';
        var studio = form.querySelector('[data-loc=studio]');
        var client = form.querySelector('[data-loc=client]');
        studio.hidden = mode === 'client';
        client.hidden = mode === 'studio';
        if (mode === 'client') client.querySelector('input').checked = true;
        if (mode === 'studio') studio.querySelector('input').checked = true;
        clientLoc.hidden = locationType() !== 'client';
    }

    function resetSelection() {
        dateIn.value = '';
        timeIn.value = '';
        pendingTime = '';
        slotsEl.innerHTML = '';
        updateSummary();
    }

    function loadMonth() {
        if (!ready()) {
            message(cal, service() ? 'Escolha a região do atendimento para ver as datas.' : 'Escolha um serviço para ver as datas disponíveis.');
            slotsEl.innerHTML = '';
            return;
        }
        var my = ++reqId;
        message(cal, 'Carregando datas…');
        fetch(form.dataset.daysUrl + '?' + query({ mes: ym(viewMonth) }), { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (my !== reqId) return;
                renderCalendar(new Set(data.days || []));
            })
            .catch(function () { message(cal, 'Não foi possível carregar as datas. Tente novamente.'); });
    }

    function renderCalendar(available) {
        cal.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'cal-head';
        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'btn btn-ghost btn-sm';
        prev.textContent = '‹';
        prev.setAttribute('aria-label', 'Mês anterior');
        prev.disabled = viewMonth <= thisMonth;
        prev.onclick = function () { viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() - 1, 1); loadMonth(); };
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn btn-ghost btn-sm';
        next.textContent = '›';
        next.setAttribute('aria-label', 'Próximo mês');
        next.onclick = function () { viewMonth = new Date(viewMonth.getFullYear(), viewMonth.getMonth() + 1, 1); loadMonth(); };
        var title = document.createElement('strong');
        title.textContent = MONTHS[viewMonth.getMonth()] + ' de ' + viewMonth.getFullYear();
        head.append(prev, title, next);
        cal.appendChild(head);

        var grid = document.createElement('div');
        grid.className = 'cal-grid';
        DOWS.forEach(function (d) {
            var h = document.createElement('div');
            h.className = 'cal-dow';
            h.textContent = d;
            grid.appendChild(h);
        });
        var y = viewMonth.getFullYear(), m = viewMonth.getMonth();
        var first = new Date(y, m, 1).getDay();
        var days = new Date(y, m + 1, 0).getDate();
        for (var i = 0; i < first; i++) {
            var e = document.createElement('div');
            e.className = 'cal-empty';
            grid.appendChild(e);
        }
        for (var d = 1; d <= days; d++) {
            var key = ymd(y, m, d);
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cal-day';
            b.textContent = d;
            b.dataset.date = key;
            if (available.has(key)) {
                b.classList.add('available');
                b.setAttribute('aria-label', brDate(key) + ' — com horários');
                b.onclick = function () { selectDate(this.dataset.date); };
            } else {
                b.disabled = true;
                b.setAttribute('aria-label', brDate(key) + ' — indisponível');
            }
            if (key === dateIn.value) b.classList.add('selected');
            grid.appendChild(b);
        }
        cal.appendChild(grid);

        if (!available.size) {
            var p = document.createElement('p');
            p.className = 'muted small';
            p.textContent = 'Sem horários livres neste mês. Veja o próximo mês.';
            cal.appendChild(p);
        }
        if (dateIn.value && available.has(dateIn.value)) {
            loadSlots();
        } else if (dateIn.value && dateIn.value.indexOf(ym(viewMonth)) === 0) {
            // A data escolhida deste mês deixou de ter horários.
            resetSelection();
        }
    }

    function selectDate(date) {
        dateIn.value = date;
        timeIn.value = '';
        cal.querySelectorAll('.cal-day.selected').forEach(function (el) { el.classList.remove('selected'); });
        var btn = cal.querySelector('[data-date="' + date + '"]');
        if (btn) btn.classList.add('selected');
        loadSlots();
    }

    function loadSlots() {
        var my = ++reqId;
        message(slotsEl, 'Carregando horários…');
        fetch(form.dataset.slotsUrl + '?' + query({ data: dateIn.value }), { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (my !== reqId) return;
                renderSlots(data.slots || []);
            })
            .catch(function () { message(slotsEl, 'Não foi possível carregar os horários.'); });
    }

    function renderSlots(slots) {
        slotsEl.innerHTML = '';
        if (!slots.length) {
            message(slotsEl, 'Não há mais horários livres nesta data.');
            return;
        }
        var t = document.createElement('p');
        t.className = 'slots-title';
        t.textContent = 'Horários em ' + brDate(dateIn.value) + ':';
        slotsEl.appendChild(t);
        slots.forEach(function (s) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'slot';
            b.textContent = s;
            b.setAttribute('aria-pressed', 'false');
            if (s === pendingTime) {
                timeIn.value = s;
                pendingTime = '';
            }
            if (s === timeIn.value) {
                b.classList.add('selected');
                b.setAttribute('aria-pressed', 'true');
            }
            b.onclick = function () {
                timeIn.value = s;
                slotsEl.querySelectorAll('.slot').forEach(function (el) {
                    el.classList.toggle('selected', el === b);
                    el.setAttribute('aria-pressed', el === b ? 'true' : 'false');
                });
                updateSummary();
            };
            slotsEl.appendChild(b);
        });
        updateSummary();
    }

    function updateSummary() {
        var s = service();
        if (!s || !dateIn.value || !timeIn.value) {
            summary.hidden = true;
            return;
        }
        var where = locationType() === 'client'
            ? 'No seu endereço (' + areaSel.options[areaSel.selectedIndex].text + ')'
            : 'No estúdio';
        summary.innerHTML = '';
        var strong = document.createElement('strong');
        strong.textContent = s.dataset.name;
        summary.append(strong, document.createElement('br'),
            document.createTextNode(brDate(dateIn.value) + ' às ' + timeIn.value + ' · ' + where),
            document.createElement('br'),
            document.createTextNode('A partir de ' + s.dataset.price));
        summary.hidden = false;
    }

    form.querySelectorAll('input[name=service_id]').forEach(function (r) {
        r.addEventListener('change', function () { applyServiceMode(); resetSelection(); loadMonth(); });
    });
    form.querySelectorAll('input[name=location_type]').forEach(function (r) {
        r.addEventListener('change', function () { clientLoc.hidden = locationType() !== 'client'; resetSelection(); loadMonth(); });
    });
    areaSel.addEventListener('change', function () { resetSelection(); loadMonth(); });

    form.addEventListener('submit', function (ev) {
        if (!dateIn.value || !timeIn.value) {
            ev.preventDefault();
            message(slotsEl, 'Escolha uma data e um horário antes de enviar.');
            slotsEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        var btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Enviando…';
    });

    applyServiceMode();
    loadMonth();
})();
