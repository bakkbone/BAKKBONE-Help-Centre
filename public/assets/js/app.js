// Progressive enhancement only: every page must still work without JavaScript.
(function () {
  'use strict';
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  // Translations come from the page (I18n::jsStrings()); a missing one falls back to the English text.
  var L = (function () { try { return JSON.parse(document.getElementById('hc-i18n').textContent) || {}; } catch (e) { return {}; } })();
  var num = function (n) {
    var p = String(Math.abs(n)).split('.'), sep = L._thousands === undefined ? ',' : L._thousands;
    return (n < 0 ? '-' : '') + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, sep) + (p[1] ? (L._decimal || '.') + p[1] : '');
  };
  var fill = function (s, v) { Object.keys(v || {}).forEach(function (k) { s = s.split('{' + k + '}').join(v[k]); }); return s; };
  var __ = function (s, v) { return fill(typeof L[s] === 'string' && L[s] !== '' ? L[s] : s, v); };
  var _n = function (one, many, n, v) {
    var forms = L['plural:' + one], t = L._plural, i = t ? (n < t.length ? t[n] : t[100 + n % 100]) : (n === 1 ? 0 : 1);
    var s = forms && forms[i] ? forms[i] : (n === 1 ? one : many);
    return fill(s, Object.assign({ n: num(n) }, v || {}));
  };
  var rtl = document.documentElement.dir === 'rtl';

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.submitter && e.submitter.getAttribute('formmethod') === 'dialog') return; // just closing a dialog
    var dlg = f.getAttribute('data-dialog') && document.getElementById(f.getAttribute('data-dialog'));
    if (dlg && typeof dlg.showModal === 'function') { e.preventDefault(); dlg.showModal(); return; }
    var sub = e.submitter;
    var picked = f.hasAttribute('data-bulk') ? $$('input[name="ids[]"]:checked', f).length : 0;
    var need = sub && parseInt(sub.getAttribute('data-min') || '0', 10);
    if (need && picked < need) { e.preventDefault(); window.alert(_n('Select at least {n} ticket for this.', 'Select at least {n} tickets for this.', need)); return; }
    var msg = (sub && sub.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg) msg = msg.replace(/\{n\}/g, num(picked)).replace(/\{tickets\}/g, _n('the selected ticket', '{n} selected tickets', picked));
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    $$('.editor', f).forEach(function (ed) { if (ed._sync) ed._sync(); });
    var btn = sub && sub.type === 'submit' && !sub.hasAttribute('data-no-disable') ? sub : f.querySelector('button[type=submit]:not([data-no-disable])');
    if (btn && !f.hasAttribute('data-no-disable')) { setTimeout(function () { btn.disabled = true; }, 0); setTimeout(function () { btn.disabled = false; }, 8000); }
  }, true);
  $$('[data-confirm-click]').forEach(function (el) {
    el.addEventListener('click', function (e) { if (!window.confirm(el.getAttribute('data-confirm-click'))) e.preventDefault(); });
  });

  $$('[data-href]').forEach(function (tr) {
    tr.classList.add('row-link');
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, label, select')) return;
      if (e.metaKey || e.ctrlKey) { window.open(tr.getAttribute('data-href')); return; }
      window.location = tr.getAttribute('data-href');
    });
  });

  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  $$('[data-tabs]').forEach(function (wrap) {
    var buttons = $$('[data-tab]', wrap);
    function show(name) {
      buttons.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-tab') === name); });
      $$('[data-pane]', wrap).forEach(function (p) { p.classList.toggle('hidden', p.getAttribute('data-pane') !== name); });
      var input = $('input[name=type]', wrap);
      if (input) input.value = name;
      wrap.setAttribute('data-current', name);
    }
    buttons.forEach(function (b) { b.addEventListener('click', function (e) { e.preventDefault(); show(b.getAttribute('data-tab')); }); });
    if (buttons[0]) show(wrap.getAttribute('data-default') || buttons[0].getAttribute('data-tab'));
  });

  function sanitizePaste(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    $$('script,style,meta,link,iframe,object,embed,form,input,button,o\\:p', doc).forEach(function (n) { n.remove(); });
    $$('*', doc.body).forEach(function (n) {
      Array.prototype.slice.call(n.attributes).forEach(function (a) {
        if (/^on/i.test(a.name) || a.name === 'class' || a.name === 'id' || (a.name === 'style' && n.tagName !== 'SPAN')) n.removeAttribute(a.name);
      });
    });
    return doc.body.innerHTML;
  }
  function makeEditor(textarea) {
    var wrap = document.createElement('div');
    wrap.className = 'editor';
    var bar = document.createElement('div');
    bar.className = 'editor-bar';
    var area = document.createElement('div');
    area.className = 'editor-area';
    area.contentEditable = 'true';
    area.setAttribute('role', 'textbox');
    area.setAttribute('aria-multiline', 'true');
    area.setAttribute('data-placeholder', textarea.getAttribute('placeholder') || '');
    area.innerHTML = textarea.value;
    if (textarea.style.minHeight) area.style.minHeight = textarea.style.minHeight;
    var tools = [
      [__('B'), 'bold', __('Bold')], [__('I'), 'italic', __('Italic')], [__('U'), 'underline', __('Underline')], ['|'],
      [__('• List'), 'insertUnorderedList', __('Bulleted list')], [__('1. List'), 'insertOrderedList', __('Numbered list')], ['❝', 'formatBlock:blockquote', __('Quote')], ['|'],
      [__('Link'), 'link', __('Insert link')], [__('Clear'), 'removeFormat', __('Clear formatting')], ['|'], ['</>', 'source', __('Edit HTML source')]
    ];
    var source = null;
    tools.forEach(function (t) {
      if (t[0] === '|') { var s = document.createElement('span'); s.className = 'sep'; bar.appendChild(s); return; }
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = t[0]; b.title = t[2]; b.setAttribute('aria-label', t[2]);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var cmd = t[1];
        if (cmd === 'source') {
          if (!source) {
            source = document.createElement('textarea');
            source.className = 'editor-source';
            source.value = area.innerHTML;
            area.replaceWith(source);
            b.classList.add('on');
          } else {
            area.innerHTML = source.value;
            source.replaceWith(area);
            source = null;
            b.classList.remove('on');
          }
          return;
        }
        area.focus();
        if (cmd === 'link') {
          var url = window.prompt(__('Link URL (https://...)'), 'https://');
          if (url && /^(https?:|mailto:|tel:)/i.test(url)) document.execCommand('createLink', false, url);
          return;
        }
        if (cmd.indexOf('formatBlock:') === 0) { document.execCommand('formatBlock', false, cmd.split(':')[1]); return; }
        document.execCommand(cmd, false, null);
      });
      bar.appendChild(b);
    });
    area.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd) return;
      var html = cd.getData('text/html');
      if (html) { e.preventDefault(); document.execCommand('insertHTML', false, sanitizePaste(html)); }
    });
    wrap.appendChild(bar);
    wrap.appendChild(area);
    textarea.style.display = 'none';
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap._sync = function () { textarea.value = source ? source.value : area.innerHTML; };
    wrap._insert = function (html) {
      if (source) { source.value += html; return; }
      area.focus();
      var sel = window.getSelection();
      if (!sel.rangeCount || !area.contains(sel.anchorNode)) {
        var r = document.createRange(); r.selectNodeContents(area); r.collapse(false); sel.removeAllRanges(); sel.addRange(r);
      }
      document.execCommand('insertHTML', false, html);
      wrap._sync();
    };
    wrap._isEmpty = function () { var t = (source ? source.value : area.innerHTML).replace(/<(?!img)[^>]*>|&nbsp;|\s/g, ''); return t === ''; };
    area.addEventListener('input', function () { wrap._sync(); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
    textarea._editor = wrap;
    return wrap;
  }
  $$('textarea[data-editor]').forEach(makeEditor);

  $$('select[data-canned]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var id = sel.value;
      if (!id) return;
      var target = document.getElementById(sel.getAttribute('data-target'));
      var url = sel.getAttribute('data-canned').replace('__ID__', id);
      fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.html && target && target._editor) target._editor._insert(d.html);
          else if (d.html && target) target.value += d.html;
          sel.value = '';
        });
    });
  });

  $$('[data-bulk]').forEach(function (form) {
    var all = $('[data-check-all]', form);
    var bar = $('.bulkbar', form);
    function update() {
      var n = $$('input[name="ids[]"]:checked', form).length;
      if (bar) { bar.classList.toggle('show', n > 0); var c = $('[data-count]', bar); if (c) c.textContent = _n('{n} selected', '{n} selected', n); }
    }
    if (all) all.addEventListener('change', function () { $$('input[name="ids[]"]', form).forEach(function (c) { c.checked = all.checked; }); update(); });
    form.addEventListener('change', update);
    $$('[data-open-dialog]', form).forEach(function (b) {
      b.addEventListener('click', function () {
        var n = $$('input[name="ids[]"]:checked', form).length;
        $$('[data-count-text]', form).forEach(function (el) { el.textContent = _n('{n} ticket', '{n} tickets', n); });
      });
    });
  });

  document.addEventListener('click', function (e) {
    var open = e.target.closest('[data-open-dialog]');
    if (open) {
      var d = document.getElementById(open.getAttribute('data-open-dialog'));
      if (d && d.showModal) { d.showModal(); var first = $('select, input:not([type=hidden])', d); if (first) first.focus(); }
      return;
    }
    var close = e.target.closest('[data-close-dialog]');
    if (close) { var dl = close.closest('dialog'); if (dl) dl.close(); }
  });

  var openMenu = null;
  var closeMenu = function (focusToggle) {
    if (!openMenu) return;
    openMenu.menu.hidden = true; openMenu.toggle.setAttribute('aria-expanded', 'false');
    if (focusToggle) openMenu.toggle.focus();
    openMenu = null;
  };
  $$('[data-menu]').forEach(function (t) {
    var menu = document.getElementById(t.getAttribute('data-menu'));
    if (!menu) return;
    t.setAttribute('aria-haspopup', 'menu'); t.setAttribute('aria-expanded', 'false'); t.setAttribute('aria-controls', menu.id);
    t.addEventListener('click', function (e) {
      e.preventDefault();
      var wasOpen = openMenu && openMenu.menu === menu;
      closeMenu(false);
      if (wasOpen) return;
      menu.hidden = false; t.setAttribute('aria-expanded', 'true'); openMenu = { menu: menu, toggle: t };
      menu.classList.remove('menu-right');
      var box = menu.getBoundingClientRect();
      if (rtl ? box.left < 8 : box.right > window.innerWidth - 8) menu.classList.add('menu-right');
      var first = $('[role=menuitem]', menu); if (first) first.focus();
    });
    menu.addEventListener('keydown', function (e) {
      var items = $$('[role=menuitem]', menu), i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
      else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
      else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
      else if (e.key === 'Tab') { closeMenu(false); }
    });
  });
  document.addEventListener('click', function (e) { if (openMenu && !openMenu.menu.contains(e.target) && !openMenu.toggle.contains(e.target)) closeMenu(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openMenu) { e.preventDefault(); closeMenu(true); } });

  var lockEl = $('[data-lock-url]');
  if (lockEl) {
    var lockUrl = lockEl.getAttribute('data-lock-url');
    var renew = function () {
      var body = new FormData(); body.append('_csrf', csrf);
      fetch(lockUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var warn = $('[data-lock-warning]');
          if (warn) { warn.classList.toggle('hidden', d.ok); if (!d.ok && d.locked_by) warn.querySelector('[data-who]').textContent = d.locked_by; }
        }).catch(function () {});
    };
    var active = false;
    document.addEventListener('input', function () { active = true; });
    setInterval(function () { if (active || document.hasFocus()) renew(); active = false; }, 60000);
  }

  $$('select[data-topic-select]').forEach(function (sel) {
    var form = sel.form;
    function apply() {
      var topic = sel.value;
      $$('[data-topics]', form).forEach(function (el) {
        var list = el.getAttribute('data-topics');
        var show = !list || (topic && list.split(',').indexOf(topic) !== -1);
        el.classList.toggle('hidden', !show);
        $$('input,select,textarea', el).forEach(function (i) { i.disabled = !show; });
      });
    }
    sel.addEventListener('change', apply); apply();
  });

  var lookup = $('[data-user-lookup]');
  if (lookup) {
    var list = document.getElementById(lookup.getAttribute('list'));
    var nameInput = $('input[name=name]', lookup.form);
    var phoneInput = $('input[name=phone]', lookup.form);
    var cache = {};
    var timer;
    lookup.addEventListener('input', function () {
      clearTimeout(timer);
      var q = lookup.value.trim();
      if (cache[q.toLowerCase()]) {
        var u = cache[q.toLowerCase()];
        if (nameInput && !nameInput.value) nameInput.value = u.name;
        if (phoneInput && !phoneInput.value && u.phone) phoneInput.value = u.phone;
        return;
      }
      if (q.length < 2) return;
      timer = setTimeout(function () {
        fetch(lookup.getAttribute('data-user-lookup') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (rows) {
            list.innerHTML = '';
            rows.forEach(function (u) {
              cache[u.email.toLowerCase()] = u;
              var o = document.createElement('option'); o.value = u.email; o.label = u.name; list.appendChild(o);
            });
          });
      }, 250);
    });
  }

  $$('[data-rules]').forEach(function (box) {
    var tpl = $('template', box);
    var rows = $('[data-rule-rows]', box);
    $('[data-add-rule]', box).addEventListener('click', function () { rows.appendChild(tpl.content.cloneNode(true)); });
    box.addEventListener('click', function (e) {
      if (e.target.matches('[data-remove-rule]')) { e.preventDefault(); e.target.closest('.rule-row').remove(); }
    });
    if (!rows.children.length) rows.appendChild(tpl.content.cloneNode(true));
  });

  $$('[data-qr]').forEach(function (el) {
    if (typeof window.qrcode !== 'function') return;
    var qr = window.qrcode(0, 'M');
    qr.addData(el.getAttribute('data-qr'));
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
  });

  $$('[data-preview-source]').forEach(function (box) {
    var src = document.getElementById(box.getAttribute('data-preview-source'));
    if (!src) return;
    var render = function () { box.innerHTML = src.value.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/\son\w+=/gi, ' data-x='); };
    src.addEventListener('input', render); render();
  });

  $$('[data-insert-target] code').forEach(function (code) {
    code.title = __('Click to insert');
    code.addEventListener('click', function () {
      var target = document.getElementById(code.closest('[data-insert-target]').getAttribute('data-insert-target'));
      if (!target) return;
      var tag = code.textContent;
      if (target._editor) { target._editor._insert(tag); return; }
      var s = target.selectionStart || target.value.length;
      target.value = target.value.slice(0, s) + tag + target.value.slice(target.selectionEnd || s);
      target.focus();
      target.dispatchEvent(new Event('input'));
    });
  });

  // data-disable-hidden also disables fields in hidden sections, so they aren't required or submitted.
  var showWhen = $$('[data-show-when]');
  var applyShowWhen = function () {
    // Outer conditions first, so a nested condition can't re-enable fields its parent disabled.
    showWhen.forEach(function (el) {
      var spec = el.getAttribute('data-show-when').split('=');
      var ctrls = $$('[name="' + spec[0] + '"]');
      if (!ctrls.length) return;
      var c = ctrls[0], v;
      if (c.type === 'radio') { var on = ctrls.filter(function (r) { return r.checked; })[0]; v = on ? on.value : ''; }
      else v = c.type === 'checkbox' ? (c.checked ? '1' : '0') : c.value;
      var hiddenByParent = el.parentElement && el.parentElement.closest('[data-show-when].hidden');
      var show = spec[1].split('|').indexOf(v) !== -1 && !hiddenByParent;
      el.classList.toggle('hidden', !show);
      if (el.hasAttribute('data-disable-hidden')) {
        $$('input, select, textarea', el).forEach(function (f) { f.disabled = !show || !!f.closest('[data-show-when].hidden'); });
      }
    });
  };
  showWhen.forEach(function (el) {
    $$('[name="' + el.getAttribute('data-show-when').split('=')[0] + '"]').forEach(function (c) { c.addEventListener('change', applyShowWhen); });
  });
  applyShowWhen();

  $$('input[data-mirror]').forEach(function (hidden) {
    hidden.form.addEventListener('submit', function (e) {
      var src = document.getElementById(hidden.getAttribute('data-mirror'));
      hidden.value = src ? src.value : '';
      if (!hidden.value) { e.preventDefault(); if (src) { src.focus(); src.reportValidity && src.reportValidity(); } }
    }, true);
  });

  $$('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = document.getElementById(b.getAttribute('data-copy'));
      if (!t) return;
      navigator.clipboard.writeText(t.value || t.textContent).then(function () { var o = b.textContent; b.textContent = __('Copied'); setTimeout(function () { b.textContent = o; }, 1500); });
    });
  });

  $$('input[type=file][multiple]').forEach(function (inp) {
    var out = document.createElement('div');
    out.className = 'hint';
    inp.parentNode.insertBefore(out, inp.nextSibling);
    inp.addEventListener('change', function () {
      out.textContent = Array.prototype.map.call(inp.files, function (f) { return __('{name} ({size} KB)', { name: f.name, size: num(Math.round(f.size / 1024)) }); }).join(', ');
    });
  });

  // The choice is kept in a cookie so the server renders the right theme without a flash.
  var root = document.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var current = function () { return root.getAttribute('data-theme') || (mq && mq.matches ? 'dark' : 'light'); };
  var toggles = $$('[data-theme-toggle]');
  var label = function () {
    var next = current() === 'dark' ? 'light' : 'dark';
    var text = next === 'dark' ? __('Switch to dark mode') : __('Switch to light mode');
    toggles.forEach(function (b) { b.setAttribute('aria-label', text); b.title = text; });
  };
  toggles.forEach(function (b) {
    b.hidden = false;
    b.addEventListener('click', function () {
      var next = current() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      document.cookie = 'hc_theme=' + next + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      var meta = $('meta[name="theme-color"]');
      if (meta && !meta.hasAttribute('data-brand')) meta.setAttribute('data-brand', meta.content);
      if (meta) meta.content = next === 'dark' ? getComputedStyle(document.body).backgroundColor : meta.getAttribute('data-brand');
      label();
    });
  });
  if (mq && mq.addEventListener) mq.addEventListener('change', label);
  label();

  document.addEventListener('keydown', function (e) {
    var box = $('#nav-toggle');
    if (e.key === 'Escape' && box && box.checked) box.checked = false;
  });

  $$('[data-bind-from]').forEach(function (span) {
    var input = document.querySelector('[name="' + span.getAttribute('data-bind-from') + '"]');
    if (!input) return;
    var sync = function () { span.textContent = input.value.trim() || span.getAttribute('data-fallback'); };
    input.addEventListener('input', sync); sync();
  });
  $$('[data-ms-preset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = $('form input[name="email"]') ? $('form input[name="email"]').form : null;
      if (!form) return;
      var set = function (name, value, onlyIfEmpty) {
        var el = form.elements[name];
        if (!el || (onlyIfEmpty && el.value.trim() !== '')) return;
        if (el.type === 'checkbox') el.checked = !!value; else el.value = value;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      };
      var email = (form.elements.email.value || '').trim();
      set('auth_type', 'oauth_ms');
      if (btn.getAttribute('data-ms-preset') === 'graph') {
        set('inbound_protocol', 'graph'); set('outbound_protocol', 'graph'); set('in_folder', 'Inbox');
      } else {
        set('inbound_protocol', 'imap'); set('in_host', 'outlook.office365.com'); set('in_port', '993'); set('in_encryption', 'ssl'); set('in_folder', 'INBOX');
        set('outbound_protocol', 'smtp'); set('smtp_host', 'smtp.office365.com'); set('smtp_port', '587'); set('smtp_encryption', 'tls'); set('smtp_auth', true);
        if (email) set('smtp_username', email, true);
      }
      if (email) set('in_username', email, true);
      var target = form.elements.ms_tenant;
      if (target) { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(function () { target.focus({ preventScroll: true }); }, 350); }
    });
  });

  var imp = $('[data-import-run]');
  if (imp) {
    var url = imp.getAttribute('data-import-run');
    var bar = $('.progress > span', imp), pct = $('[data-import-percent]', imp), list = $('[data-import-steps]', imp);
    var title = $('[data-import-title]', imp), failures = 0, stopped = false;
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
    var iconUse = function (name, cls) {
      var use = $('svg.icon use'); var href = use ? use.getAttribute('href').split('#')[0] : '';
      return '<svg class="icon ' + cls + '" aria-hidden="true"><use href="' + href + '#' + name + '"></use></svg>';
    };
    var render = function (d) {
      if (bar) bar.style.width = d.percent + '%';
      if (pct) pct.textContent = d.percent;
      var pb = $('.progress', imp); if (pb) pb.setAttribute('aria-valuenow', d.percent);
      if (list) list.innerHTML = d.steps.map(function (s) {
        var cur = s.key === d.current;
        var count = s.key === 'finish' ? '' : (s.total ? num(Math.min(s.done, Math.max(s.total, s.done))) + ' / ' + num(Math.max(s.total, s.done)) : (s.complete ? '' : '—'));
        return '<li class="import-step' + (s.complete ? ' is-done' : '') + (cur ? ' is-current' : '') + '">' +
          (s.complete ? iconUse('check_circle', 'ok') : cur ? iconUse('sync', 'spin') : iconUse('schedule', 'faint')) +
          '<span class="import-step-label">' + esc(s.label) + '</span><span class="import-step-count">' + count + '</span></li>';
      }).join('');
    };
    var tick = function () {
      if (stopped) return;
      fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (d) {
          failures = 0; render(d);
          if (d.status === 'running') { setTimeout(tick, 250); return; }
          stopped = true; window.location.reload();
        })
        .catch(function () {
          failures++;
          if (failures >= 5) { stopped = true; if (title) title.textContent = __('Lost contact with the server. Reload the page to continue.'); return; }
          setTimeout(tick, 2000 * failures);
        });
    };
    window.addEventListener('beforeunload', function (e) { if (!stopped) { e.preventDefault(); e.returnValue = ''; } });
    tick();
  }

  $$('[data-fill-source]').forEach(function (b) {
    b.addEventListener('click', function () {
      var url = $('#bhc_url'), key = $('#bhc_key');
      if (url) url.value = b.getAttribute('data-fill-source');
      if (key) { key.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(function () { key.focus({ preventScroll: true }); }, 350); }
    });
  });
})();
