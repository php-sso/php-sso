// Admin panel behaviour. Everything degrades gracefully without JavaScript.
(() => {
  // Live filter for lists: <input data-filter="#table-id"> hides rows whose data-search doesn't match.
  document.querySelectorAll('[data-filter]').forEach((input) => {
    const rows = document.querySelectorAll(`${input.dataset.filter} tbody tr[data-search]`);
    const empty = document.querySelector('[data-filter-empty]');
    const update = () => {
      const q = input.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach((row) => {
        const hit = row.dataset.search.includes(q);
        row.hidden = !hit;
        if (hit) shown++;
      });
      if (empty) empty.hidden = shown > 0;
    };
    input.addEventListener('input', update);
    // Deep link: /admin/users?q=alice starts with the list filtered.
    const initial = new URLSearchParams(location.search).get('q');
    if (initial) { input.value = initial; update(); }
  });

  // Confirmation dialog for buttons with data-confirm.
  const dialog = document.getElementById('confirm-dialog');
  let pending = null;
  if (dialog && dialog.showModal) {
    document.addEventListener('submit', (event) => {
      const button = event.submitter;
      if (!button || !button.dataset.confirm || event.target.dataset.confirmed) return;
      event.preventDefault();
      pending = event.target;
      dialog.querySelector('[data-title]').textContent = button.dataset.confirmTitle || 'Are you sure?';
      dialog.querySelector('[data-message]').textContent = button.dataset.confirm;
      const ok = dialog.querySelector('[data-ok]');
      ok.textContent = button.dataset.confirmLabel || button.textContent.trim();
      dialog.returnValue = '';
      dialog.showModal();
    });
    dialog.addEventListener('close', () => {
      if (dialog.returnValue === 'ok' && pending) {
        pending.dataset.confirmed = '1';
        pending.submit();
      }
      pending = null;
    });
  }

  // A freshly generated client secret opens in a modal (without JS it stays visible as a plain dialog).
  const secretDialog = document.getElementById('secret-dialog');
  if (secretDialog && secretDialog.showModal) {
    secretDialog.removeAttribute('open');
    secretDialog.showModal();
  }

  // Copy-to-clipboard buttons.
  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const label = button.textContent;
      try {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = 'Copied';
      } catch {
        button.textContent = 'Select and copy manually';
      }
      setTimeout(() => { button.textContent = label; }, 2000);
    });
  });

  // Command palette: Ctrl/Cmd+K (or "/") opens a search. Actions and pages come from #search-index;
  // people, groups and clients are looked up on the server as you type (/admin/search).
  const palette = document.getElementById('palette');
  const indexEl = document.getElementById('search-index');
  if (palette && palette.showModal && indexEl) {
    const items = JSON.parse(indexEl.textContent);
    let remote = [];
    let ticket = 0;
    let timer = null;
    const input = document.getElementById('palette-input');
    const list = document.getElementById('palette-results');
    const kinds = { action: 'Action', page: 'Page', user: 'User', group: 'Group', client: 'Client' };
    let shown = [];
    let active = 0;

    const score = (item, words) => {
      const title = item.title.toLowerCase();
      const hay = `${title} ${item.sub.toLowerCase()} ${item.k}`;
      let total = 0;
      for (const w of words) {
        if (!hay.includes(w)) return -1;
        total += title.startsWith(w) ? 3 : title.includes(w) ? 2 : 1;
      }
      return total;
    };

    const row = (item, i) => {
      const li = document.createElement('li');
      li.className = 'pal-item';
      li.id = `pal-${i}`;
      li.setAttribute('role', 'option');
      li.dataset.url = item.url;
      const glyph = document.createElement('span');
      glyph.className = item.t === 'user' || item.t === 'client' || item.t === 'group' ? 'glyph avatar sm' : 'glyph';
      if (glyph.classList.contains('avatar')) glyph.style.setProperty('--h', item.h);
      glyph.textContent = item.t === 'action' ? '+' : item.t === 'page' ? '→' : item.i;
      const title = document.createElement('span');
      title.className = 'title';
      title.textContent = item.title;
      const sub = document.createElement('span');
      sub.className = 'sub';
      sub.textContent = item.sub;
      const kind = document.createElement('span');
      kind.className = 'kind';
      kind.textContent = kinds[item.t];
      li.append(glyph, title, sub, kind);
      li.addEventListener('mousemove', () => select(i));
      li.addEventListener('click', () => go(item.url));
      return li;
    };

    const select = (i) => {
      active = i;
      list.querySelectorAll('.pal-item').forEach((el, n) => el.setAttribute('aria-selected', String(n === i)));
      const el = document.getElementById(`pal-${i}`);
      if (el) { input.setAttribute('aria-activedescendant', el.id); el.scrollIntoView({ block: 'nearest' }); }
    };

    const render = () => {
      const words = input.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
      const local = words.length
        ? items.map((item) => [score(item, words), item]).filter(([s]) => s >= 0).sort((a, b) => b[0] - a[0]).map(([, item]) => item)
        : items;
      shown = words.length ? local.concat(remote).slice(0, 30) : local;
      list.replaceChildren();
      if (!shown.length) {
        const none = document.createElement('li');
        none.className = 'pal-empty';
        none.textContent = 'Nothing matches. Try a name, username, email or client ID.';
        list.append(none);
        return;
      }
      shown.forEach((item, i) => list.append(row(item, i)));
      select(0);
    };

    const go = (url) => { location.href = url; };
    const open = () => { input.value = ''; remote = []; render(); palette.showModal(); input.focus(); };

    document.querySelectorAll('[data-open-palette]').forEach((b) => b.addEventListener('click', open));
    document.addEventListener('keydown', (e) => {
      const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing && !palette.open)) {
        e.preventDefault();
        palette.open ? palette.close() : open();
      }
    });
    input.addEventListener('input', () => {
      render();
      clearTimeout(timer);
      const q = input.value.trim();
      if (q === '') { remote = []; return; }
      timer = setTimeout(async () => {
        const mine = ++ticket;
        try {
          const response = await fetch(`/admin/search?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
          if (!response.ok || mine !== ticket) return;
          remote = await response.json();
          render();
        } catch { /* offline or signed out: the local entries still work */ }
      }, 150);
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); select(Math.min(active + 1, shown.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); select(Math.max(active - 1, 0)); }
      else if (e.key === 'Enter' && shown[active]) { e.preventDefault(); go(shown[active].url); }
    });
    palette.addEventListener('click', (e) => { if (e.target === palette) palette.close(); });
  }
})();

// Dropdowns ("New", the account menu and the menu at the end of a table row): a <details>, closed by Esc or a click elsewhere.
(() => {
  const menus = document.querySelectorAll('.newmenu, .usermenu, .rowmenu');
  if (!menus.length) return;
  document.addEventListener('click', (e) => menus.forEach((menu) => { if (!menu.contains(e.target)) menu.open = false; }));
  menus.forEach((menu) => menu.addEventListener('keydown', (e) => { if (e.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); } }));

  // A table clips what overflows it, so a row menu is placed on the screen instead of inside the table: under its button,
  // right-aligned with it, or above it when there is no room below. It closes when the page scrolls or resizes.
  let openedAt = 0;
  document.querySelectorAll('.rowmenu').forEach((menu) => {
    const list = menu.querySelector('.menu');
    menu.addEventListener('toggle', () => {
      if (!menu.open) { list.style.cssText = ''; return; }
      openedAt = performance.now();
      const button = menu.querySelector('summary').getBoundingClientRect();
      list.style.position = 'fixed';
      const height = list.offsetHeight;
      const below = button.bottom + 4 + height <= window.innerHeight;
      list.style.top = (below ? button.bottom + 4 : Math.max(8, button.top - 4 - height)) + 'px';
      list.style.left = Math.max(8, button.right - list.offsetWidth) + 'px';
      list.style.right = 'auto';
    });
  });
  // (A scroll that was already on its way when the menu opened, such as the page bringing the button into view, does not count.)
  const closeRowMenus = () => { if (performance.now() - openedAt > 250) document.querySelectorAll('.rowmenu[open]').forEach((menu) => { menu.open = false; }); };
  window.addEventListener('scroll', closeRowMenus, true);
  window.addEventListener('resize', closeRowMenus);
})();

// Dashboard: filter the sign-in attempts table to failed ones.
(() => {
  const seg = document.querySelector('[data-attempt-filter]');
  const table = document.getElementById('attempt-table');
  if (!seg || !table) return;
  seg.hidden = false;
  const empty = document.getElementById('attempt-empty');
  seg.addEventListener('click', (e) => {
    const button = e.target.closest('button[data-show]');
    if (!button) return;
    seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    let shown = 0;
    table.querySelectorAll('tbody tr').forEach((row) => {
      const hit = button.dataset.show === 'all' || row.dataset.result === 'bad';
      row.hidden = !hit;
      if (hit) shown++;
    });
    table.hidden = shown === 0;
    if (empty) empty.hidden = shown > 0;
  });
})();

// Group page: live search in the "add people" box, driven from the keyboard.
// The server renders the result list; without JavaScript the Search button loads the same list as part of the page.
//   type to search, ArrowDown/ArrowUp to choose, Enter to add, Escape to clear.
document.querySelectorAll('[data-find]').forEach((form) => {
  const input = form.querySelector('input[type=search]');
  const results = document.getElementById(input.getAttribute('aria-controls'));
  const submit = form.querySelector('[data-find-submit]');
  let timer = null;
  let latest = 0;
  let active = -1;
  submit.hidden = true;

  const options = () => [...results.querySelectorAll('[role=option]')];
  const choose = (index) => {
    const list = options();
    active = list.length ? Math.max(-1, Math.min(index, list.length - 1)) : -1;
    list.forEach((li, n) => li.setAttribute('aria-selected', String(n === active)));
    if (active >= 0) {
      input.setAttribute('aria-activedescendant', list[active].id);
      list[active].scrollIntoView({ block: 'nearest' });
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  };
  /** Call after the result list changed: give options ids, clear the selection. */
  const resultsChanged = () => {
    options().forEach((li, n) => {
      li.id = `member-option-${n}`;
      li.addEventListener('mousemove', () => { if (active !== n) choose(n); });
    });
    input.setAttribute('aria-expanded', String(options().length > 0));
    choose(-1);
  };
  resultsChanged();

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q === '') { results.innerHTML = ''; resultsChanged(); return; }
    timer = setTimeout(async () => {
      const ticket = ++latest;
      try {
        const response = await fetch(`${form.dataset.endpoint}?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (response.ok && ticket === latest) { results.innerHTML = await response.text(); resultsChanged(); }
      } catch { /* keep the previous results; the Search button path still works */ }
    }, 180);
  });

  input.addEventListener('keydown', (e) => {
    const count = options().length;
    if (e.key === 'ArrowDown' && count) { e.preventDefault(); choose(active + 1 >= count ? 0 : active + 1); }
    else if (e.key === 'ArrowUp' && count) { e.preventDefault(); choose(active <= 0 ? count - 1 : active - 1); }
    else if (e.key === 'Enter') {
      e.preventDefault(); // never reload the page just by pressing Enter in the search box
      if (active >= 0) options()[active].querySelector('form').requestSubmit();
      else if (count) choose(0); // nothing chosen yet: Enter picks the first match, a second Enter adds it
    } else if (e.key === 'Escape' && (input.value || count)) {
      e.preventDefault();
      input.value = ''; results.innerHTML = ''; resultsChanged();
    }
  });

  // Back on the page after adding or removing someone: ready for the next name.
  const section = form.closest('[id]');
  if (section && location.hash === `#${section.id}` && document.querySelector('.notice')) input.focus();
});

// Dashboard: filter the sign-in attempts table to failed ones.
(() => {
  const seg = document.querySelector('[data-attempt-filter]');
  const table = document.getElementById('attempt-table');
  if (!seg || !table) return;
  seg.hidden = false;
  const empty = document.getElementById('attempt-empty');
  seg.addEventListener('click', (e) => {
    const button = e.target.closest('button[data-show]');
    if (!button) return;
    seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    let shown = 0;
    table.querySelectorAll('tbody tr').forEach((row) => {
      const hit = button.dataset.show === 'all' || row.dataset.result === 'bad';
      row.hidden = !hit;
      if (hit) shown++;
    });
    table.hidden = shown === 0;
    if (empty) empty.hidden = shown > 0;
  });
})();

// Group page: live search in the "add people" box. The server renders the result list;
// without JavaScript the Search button loads the same list as part of the page.
document.querySelectorAll('[data-find]').forEach((form) => {
  const input = form.querySelector('input[type=search]');
  const results = document.getElementById(input.getAttribute('aria-controls'));
  const submit = form.querySelector('[data-find-submit]');
  let timer = null;
  let latest = 0;
  submit.hidden = true;

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q === '') { results.innerHTML = ''; return; }
    timer = setTimeout(async () => {
      const ticket = ++latest;
      try {
        const response = await fetch(`${form.dataset.endpoint}?q=${encodeURIComponent(q)}`, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (response.ok && ticket === latest) results.innerHTML = await response.text();
      } catch { /* keep the previous results; the Search button path still works */ }
    }, 180);
  });
  form.addEventListener('submit', (e) => { if (input.value.trim() === '') e.preventDefault(); });
});

// Forms that apply as soon as a choice is made (client access): the button is only needed without JavaScript.
document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
  const fallback = form.querySelector('[data-autosubmit-fallback]');
  if (fallback) fallback.hidden = true;
  form.addEventListener('change', () => form.requestSubmit());
});

// Identifier fields (client ID): keep what is typed or pasted to lowercase letters, digits, - and _ while typing.
// Spaces become dashes, and the caret stays where it was.
document.querySelectorAll('input[data-slug]').forEach((input) => {
  const clean = (text) => text.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '');
  input.addEventListener('input', () => {
    const caret = input.selectionStart ?? input.value.length;
    const before = clean(input.value.slice(0, caret));
    const value = clean(input.value);
    if (value === input.value) return;
    input.value = value;
    input.setSelectionRange(before.length, before.length);
  });
});

// "Select all / none" above a grid of checkboxes (only the ones shown, when the grid is filtered). The links only exist with
// JavaScript, so they start hidden.
document.querySelectorAll('[data-pick-all]').forEach((bar) => {
  bar.hidden = false;
  const boxes = () => [...bar.parentElement.querySelectorAll('.pick-grid input[type="checkbox"], .checklist input[type="checkbox"]')].filter((box) => !box.closest('.pick, .check-row').hidden);
  bar.addEventListener('click', (e) => {
    const button = e.target.closest('button[data-pick]');
    if (!button) return;
    boxes().forEach((box) => { box.checked = button.dataset.pick === 'all'; });
    bar.parentElement.dispatchEvent(new Event('change', { bubbles: true }));
  });
});

// A checklist with a search box that hides what does not match, and a count of what is ticked.
document.querySelectorAll('.scope-group').forEach((group) => {
  const picks = [...group.querySelectorAll('.check-row')];
  const count = group.querySelector('[data-pick-count]');
  const filter = group.querySelector('[data-pick-filter]');
  const empty = group.querySelector('[data-pick-empty]');
  const recount = () => {
    if (count) count.textContent = group.querySelectorAll('.check-row input:checked').length + ' of ' + picks.length + ' allowed';
  };
  group.addEventListener('change', recount);

  // The menu next to the search box: select or clear what is shown (so a search narrows it). These only work with JavaScript.
  group.querySelectorAll('[data-js-only]').forEach((item) => { item.hidden = false; });
  group.addEventListener('click', (e) => {
    const button = e.target.closest('button[data-pick]');
    if (!button) return;
    picks.filter((pick) => !pick.hidden).forEach((pick) => { pick.querySelector('input').checked = button.dataset.pick === 'all'; });
    recount();
    const menu = button.closest('.rowmenu');
    if (menu) menu.open = false;
  });

  if (!filter) return;
  filter.hidden = false;
  // Enter in the search box searches; it must not submit the whole form.
  filter.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
  filter.addEventListener('input', () => {
    const needle = filter.value.trim().toLowerCase();
    let shown = 0;
    picks.forEach((pick) => {
      const match = needle === '' || pick.textContent.toLowerCase().includes(needle);
      pick.hidden = !match;
      if (match) shown++;
    });
    if (empty) empty.hidden = shown > 0;
  });
});
