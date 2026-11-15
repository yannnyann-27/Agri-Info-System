/* AIS interface behaviour. Presentation only: no business logic lives here. */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var $  = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    /* ---- stagger index for orchestrated entrances ---- */
    $$('.stagger').forEach(function (group) {
        Array.prototype.forEach.call(group.children, function (el, i) { el.style.setProperty('--i', i); });
    });
    $$('tbody.rows-in').forEach(function (tb) {
        Array.prototype.forEach.call(tb.children, function (tr, i) { tr.style.setProperty('--i', Math.min(i, 10)); });
    });

    /* ---- mobile drawer ---- */
    var sidebar = $('#sidebar'), overlay = $('#sidebar-overlay'), menuBtn = $('#menu-btn');
    function setDrawer(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        overlay && overlay.classList.toggle('show', open);
        menuBtn && menuBtn.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';
    }
    menuBtn && menuBtn.addEventListener('click', function () { setDrawer(!sidebar.classList.contains('open')); });
    overlay && overlay.addEventListener('click', function () { setDrawer(false); });

    /* ---- today's date (client side so it matches the visitor's timezone) ---- */
    $$('[data-today]').forEach(function (el) {
        el.textContent = new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    });

    /* ---- count-up numbers ---- */
    function countUp(el) {
        var target = parseFloat(el.getAttribute('data-count')) || 0;
        var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
        var fmt = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); };
        if (reduceMotion) { el.textContent = fmt(target); return; }
        var dur = 1400, start = null;
        function tick(ts) {
            if (start === null) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 4);
            el.textContent = fmt(target * eased);
            if (p < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }
    $$('[data-count]').forEach(countUp);

    /* ---- toasts ---- */
    $$('.toast').forEach(function (t) {
        var closed = false;
        function close() {
            if (closed) return; closed = true;
            t.classList.add('leaving');
            setTimeout(function () { t.remove(); }, 350);
        }
        var timer = setTimeout(close, 5000);
        t.addEventListener('mouseenter', function () { clearTimeout(timer); var b = $('.toast-bar', t); b && (b.style.animationPlayState = 'paused'); });
        t.addEventListener('mouseleave', function () { timer = setTimeout(close, 1800); var b = $('.toast-bar', t); b && (b.style.animationPlayState = 'running'); });
        var x = $('.toast-close', t);
        x && x.addEventListener('click', close);
    });

    /* ---- confirm dialog (replaces window.confirm for [data-confirm] buttons and links) ---- */
    var trashSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" width="26" height="26"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>';
    var lastFocus = null;
    function openConfirm(message, onYes) {
        lastFocus = document.activeElement;
        var wrap = document.createElement('div');
        wrap.className = 'modal-backdrop';
        wrap.setAttribute('role', 'presentation');
        wrap.innerHTML =
            '<div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="cf-t" aria-describedby="cf-d">' +
            '<div class="modal-icon">' + trashSvg + '</div>' +
            '<h3 id="cf-t">Confirm deletion</h3>' +
            '<p id="cf-d"></p>' +
            '<div class="modal-actions"><button type="button" class="btn btn-secondary" data-no>Cancel</button>' +
            '<button type="button" class="btn btn-danger" data-yes>Delete</button></div></div>';
        $('#cf-d', wrap).textContent = message;
        document.body.appendChild(wrap);
        var cancel = $('[data-no]', wrap);
        cancel.focus();
        function close() {
            wrap.classList.add('out');
            document.removeEventListener('keydown', onKey);
            setTimeout(function () { wrap.remove(); lastFocus && lastFocus.focus && lastFocus.focus(); }, 200);
        }
        function onKey(e) {
            if (e.key === 'Escape') close();
            if (e.key === 'Tab') {            // simple focus trap
                var f = $$('button, a[href]', wrap);
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }
        document.addEventListener('keydown', onKey);
        cancel.addEventListener('click', close);
        $('[data-yes]', wrap).addEventListener('click', function () { close(); onYes(); });
        wrap.addEventListener('mousedown', function (e) { if (e.target === wrap) close(); });
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('[data-confirm]');
        if (!a) return;
        e.preventDefault();
        // a delete button inside a POST form submits that form; a plain link is followed
        openConfirm(a.getAttribute('data-confirm'), function () {
            if (a.form) a.form.submit();
            else if (a.getAttribute('href')) window.location.href = a.getAttribute('href');
        });
    });

    /* ---- submit feedback ---- */
    $$('form[method="POST"], form[method="post"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = $('button[type="submit"]', form);
            if (btn) setTimeout(function () { btn.classList.add('is-loading'); }, 0);
        });
    });

    /* clear the invalid state as soon as someone fixes the field */
    $$('.is-invalid').forEach(function (el) {
        var clear = function () {
            el.classList.remove('is-invalid');
            var msg = el.parentNode.querySelector('.field-error');
            msg && msg.remove();
        };
        el.addEventListener('input', clear, { once: true });
        el.addEventListener('change', clear, { once: true });
    });

    /* ---- press "/" to jump to search ---- */
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        if (e.key !== '/' || tag === 'input' || tag === 'textarea' || tag === 'select') return;
        var s = $('input[name="search"]');
        if (s) { e.preventDefault(); s.focus(); s.select(); }
    });

    /* ---- login helpers ---- */
    var pw = $('#password'), toggle = $('#pw-toggle');
    if (pw && toggle) {
        toggle.addEventListener('click', function () {
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.classList.toggle('on', show);
        });
    }
})();
