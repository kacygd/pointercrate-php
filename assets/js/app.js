(() => {
  console.info('list v1.01');

  const closeAllDropdowns = () => {
    document.querySelectorAll('.dropdown').forEach((dropdown) => {
      dropdown.style.display = 'none';
    });

    document.querySelectorAll('.js-toggle.active').forEach((toggle) => {
      toggle.classList.remove('active');
    });
  };

  const openDropdown = (id, toggle) => {
    const dropdown = document.getElementById(id);
    if (!dropdown) {
      return;
    }

    closeAllDropdowns();
    dropdown.style.display = 'block';
    toggle.classList.add('active');
  };

  document.querySelectorAll('.js-toggle[data-dropdown-id]').forEach((toggle) => {
    toggle.addEventListener('click', (event) => {
      event.stopPropagation();
      const id = toggle.getAttribute('data-dropdown-id');
      if (!id) {
        return;
      }

      const dropdown = document.getElementById(id);
      if (!dropdown) {
        return;
      }

      const isOpen = dropdown.style.display === 'block';
      if (isOpen) {
        closeAllDropdowns();
      } else {
        openDropdown(id, toggle);
      }
    });
  });

  document.querySelectorAll('.js-search input').forEach((input) => {
    input.addEventListener('input', () => {
      const container = input.closest('.dropdown');
      if (!container) {
        return;
      }

      const value = input.value.trim().toLowerCase();
      const queries = value
        .split(';')
        .map((q) => q.trim())
        .filter((q) => q.length > 0);

      container.querySelectorAll('li').forEach((item) => {
        const content = (item.textContent || '').toLowerCase();
        const match =
          queries.length === 0 || queries.some((query) => content.includes(query));
        item.style.display = match ? '' : 'none';
      });
    });
  });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      closeAllDropdowns();
      return;
    }

    if (!target.closest('#lists')) {
      closeAllDropdowns();
    }
  });

  const firstToggle = document.querySelector('.js-toggle[data-dropdown-id]');
  if (firstToggle instanceof HTMLElement) {
    firstToggle.classList.add('active');
  }

  const navToggle = document.getElementById('mobile-nav-toggle');
  const mobileDropdown = document.getElementById('mobile-nav-dropdown');

  if (navToggle instanceof HTMLInputElement && mobileDropdown instanceof HTMLElement) {
    const syncMobileMenu = () => {
      mobileDropdown.classList.toggle('extended', navToggle.checked);
    };

    navToggle.addEventListener('change', syncMobileMenu);
    window.addEventListener('resize', () => {
      if (window.innerWidth >= 1072) {
        navToggle.checked = false;
        mobileDropdown.classList.remove('extended');
      }
    });

    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      if (!target.closest('header nav')) {
        navToggle.checked = false;
        mobileDropdown.classList.remove('extended');
      }
    });

    syncMobileMenu();
  }

  const cardSearchInput = document.querySelector('[data-live-search]');
  if (cardSearchInput instanceof HTMLInputElement) {
    const cards = Array.from(document.querySelectorAll('[data-search-value]'));

    cardSearchInput.addEventListener('input', () => {
      const term = cardSearchInput.value.trim().toLowerCase();
      cards.forEach((card) => {
        if (!(card instanceof HTMLElement)) {
          return;
        }

        const source = (card.getAttribute('data-search-value') || '').toLowerCase();
        card.style.display = term === '' || source.includes(term) ? '' : 'none';
      });
    });
  }

  const typeSelect = document.getElementById('submission-type');
  if (typeSelect instanceof HTMLSelectElement) {
    const sections = document.querySelectorAll('[data-only]');
    const syncFields = () => {
      const current = typeSelect.value;
      sections.forEach((section) => {
        if (section instanceof HTMLElement) {
          section.hidden = section.getAttribute('data-only') !== current;
        }
      });
    };

    typeSelect.addEventListener('change', syncFields);
    syncFields();
  }

  const setupHistoryToggleAnimation = () => {
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const openDurationMs = 260;

    document.querySelectorAll('.history-toggle').forEach((details) => {
      if (!(details instanceof HTMLDetailsElement)) {
        return;
      }

      const summary = details.querySelector('summary');
      const content = details.querySelector('.history-toggle-content');
      if (!(summary instanceof HTMLElement) || !(content instanceof HTMLElement)) {
        return;
      }

      const setClosedState = () => {
        content.style.maxHeight = '0px';
        content.style.opacity = '0';
        content.style.marginTop = '0px';
        content.style.overflow = 'hidden';
      };

      const setOpenState = () => {
        content.style.maxHeight = 'none';
        content.style.opacity = '1';
        content.style.marginTop = '12px';
        content.style.overflow = 'hidden';
      };

      if (details.open) {
        setOpenState();
      } else {
        setClosedState();
      }

      summary.addEventListener('click', (event) => {
        if (details.dataset.animating === '1') {
          event.preventDefault();
          return;
        }

        if (prefersReducedMotion.matches) {
          details.open = !details.open;
          if (details.open) {
            setOpenState();
          } else {
            setClosedState();
          }
          event.preventDefault();
          return;
        }

        event.preventDefault();
        details.dataset.animating = '1';

        const finishAnimation = (onDone) => {
          let finished = false;
          const complete = () => {
            if (finished) {
              return;
            }

            finished = true;
            details.dataset.animating = '0';
            onDone();
          };

          const timeoutId = window.setTimeout(complete, openDurationMs + 90);
          const onTransitionEnd = (transitionEvent) => {
            if (transitionEvent.target !== content || transitionEvent.propertyName !== 'max-height') {
              return;
            }

            window.clearTimeout(timeoutId);
            content.removeEventListener('transitionend', onTransitionEnd);
            complete();
          };

          content.addEventListener('transitionend', onTransitionEnd);
        };

        if (!details.open) {
          details.open = true;
          content.style.transition = 'none';
          setClosedState();

          const targetHeight = content.scrollHeight;
          void content.offsetHeight;

          content.style.transition = 'max-height 260ms ease, opacity 180ms ease, margin-top 260ms ease';
          content.style.maxHeight = `${targetHeight}px`;
          content.style.opacity = '1';
          content.style.marginTop = '12px';

          finishAnimation(() => {
            content.style.transition = '';
            setOpenState();
          });
          return;
        }

        const currentHeight = content.scrollHeight;
        content.style.transition = 'none';
        content.style.maxHeight = `${currentHeight}px`;
        content.style.opacity = '1';
        content.style.marginTop = '12px';
        void content.offsetHeight;

        content.style.transition = 'max-height 260ms ease, opacity 180ms ease, margin-top 260ms ease';
        content.style.maxHeight = '0px';
        content.style.opacity = '0';
        content.style.marginTop = '0px';

        finishAnimation(() => {
          details.open = false;
          content.style.transition = '';
          setClosedState();
        });
      });
    });
  };

  setupHistoryToggleAnimation();

  const setupTextSuggestions = () => {
    const controls = [];

    document.querySelectorAll('input[data-suggest-list]').forEach((input) => {
      if (!(input instanceof HTMLInputElement)) {
        return;
      }

      const listId = input.getAttribute('data-suggest-list');
      if (!listId) {
        return;
      }

      const list = document.getElementById(listId);
      if (!(list instanceof HTMLDataListElement)) {
        return;
      }

      const options = Array.from(list.options)
        .map((opt) => ({
          value: (opt.value || '').trim(),
          label: (opt.label || '').trim(),
          code: (opt.getAttribute('data-code') || '').trim(),
          flagUrl: (opt.getAttribute('data-flag-url') || '').trim(),
        }))
        .filter((opt) => opt.value !== '');

      if (options.length === 0) {
        return;
      }

      const hiddenInputId = input.getAttribute('data-suggest-hidden');
      const hiddenInput = hiddenInputId
        ? document.getElementById(hiddenInputId)
        : null;

      const toNorm = (value) => value.trim().toLowerCase();
      const syncHiddenFromInput = () => {
        if (!(hiddenInput instanceof HTMLInputElement)) {
          return;
        }

        const raw = toNorm(input.value);
        if (raw === '') {
          hiddenInput.value = '';
          return;
        }

        const exact = options.find((opt) => {
          const valueNorm = toNorm(opt.value);
          const codeNorm = toNorm(opt.code);
          const labelNorm = toNorm(opt.label);
          return (
            raw === valueNorm ||
            (codeNorm !== '' && raw === codeNorm) ||
            (labelNorm !== '' && raw === labelNorm) ||
            (codeNorm !== '' &&
              labelNorm !== '' &&
              raw === toNorm(`${opt.code} ${opt.label}`))
          );
        });

        hiddenInput.value = exact !== undefined ? exact.code : '';
      };

      const menu = document.createElement('div');
      menu.className = 'suggest-menu';
      menu.style.display = 'none';
      input.insertAdjacentElement('afterend', menu);

      const render = () => {
        const query = input.value.trim().toLowerCase();
        const matches = options
          .filter((opt) => {
            if (query === '') return true;
            const haystack = `${opt.value} ${opt.label} ${opt.code}`.toLowerCase();
            return haystack.includes(query);
          })
          .sort((a, b) => {
            const aStarts = a.value.toLowerCase().startsWith(query);
            const bStarts = b.value.toLowerCase().startsWith(query);
            if (aStarts !== bStarts) return aStarts ? -1 : 1;
            return a.value.length - b.value.length;
          })
          .slice(0, 8);

        if (matches.length === 0) {
          menu.style.display = 'none';
          menu.innerHTML = '';
          return;
        }

        menu.innerHTML = '';
        matches.forEach((opt) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'suggest-item';
          button.setAttribute('data-value', opt.value);
          button.setAttribute('data-code', opt.code);

          const main = document.createElement('span');
          main.className = 'suggest-main';

          if (opt.flagUrl !== '') {
            const flag = document.createElement('span');
            flag.className = 'flag-icon suggest-flag';
            flag.style.backgroundImage = `url('${opt.flagUrl}')`;
            main.appendChild(flag);
          }

          const text = document.createElement('span');
          text.className = 'suggest-text';
          text.textContent = opt.value;
          main.appendChild(text);
          button.appendChild(main);

          const meta = document.createElement('span');
          meta.className = 'suggest-meta';
          if (opt.code !== '' && opt.label !== '') {
            meta.textContent = opt.code;
          } else {
            meta.textContent = opt.label || '';
          }
          button.appendChild(meta);

          menu.appendChild(button);
        });

        menu.style.display = 'block';
      };

      input.addEventListener('input', () => {
        if (hiddenInput instanceof HTMLInputElement) {
          hiddenInput.value = '';
        }
        render();
      });
      input.addEventListener('focus', render);
      input.addEventListener('blur', () => {
        syncHiddenFromInput();
        setTimeout(() => {
          menu.style.display = 'none';
        }, 120);
      });
      input.addEventListener('change', syncHiddenFromInput);

      menu.addEventListener('mousedown', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
          return;
        }

        const button = target.closest('.suggest-item');
        if (!(button instanceof HTMLElement)) {
          return;
        }

        event.preventDefault();
        const value = button.getAttribute('data-value') || '';
        const code = button.getAttribute('data-code') || '';
        input.value = value;
        if (hiddenInput instanceof HTMLInputElement) {
          hiddenInput.value = code;
        }
        menu.style.display = 'none';
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });

      if (input.form instanceof HTMLFormElement) {
        input.form.addEventListener('submit', syncHiddenFromInput);
      }

      controls.push({ input, menu });
    });

    if (controls.length === 0) {
      return;
    }

    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        controls.forEach((item) => {
          item.menu.style.display = 'none';
        });
        return;
      }

      controls.forEach((item) => {
        if (!item.menu.contains(target) && target !== item.input) {
          item.menu.style.display = 'none';
        }
      });
    });
  };

  setupTextSuggestions();

  const setupClosablePanels = () => {
    const revealPanel = (target) => {
      if (!(target instanceof HTMLElement)) {
        return;
      }

      target.hidden = false;
      target.style.display = '';
    };

    const revealHash = (hash, shouldScroll) => {
      const id = String(hash || '').replace(/^#/, '');
      if (id === '') {
        return;
      }

      const target = document.getElementById(decodeURIComponent(id));
      if (!(target instanceof HTMLElement)) {
        return;
      }

      revealPanel(target);
      if (shouldScroll) {
        window.setTimeout(() => {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 0);
      }
    };

    document.querySelectorAll('.plus.cross').forEach((button) => {
      if (!(button instanceof HTMLElement)) {
        return;
      }

      const close = () => {
        const panel = button.closest('.closable');
        if (panel instanceof HTMLElement) {
          panel.style.display = 'none';
        }
      };

      button.addEventListener('click', close);
      button.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          close();
        }
      });
    });

    document.querySelectorAll('a[href*="#"]').forEach((link) => {
      if (!(link instanceof HTMLAnchorElement)) {
        return;
      }

      link.addEventListener('click', (event) => {
        let url;
        try {
          url = new URL(link.href, window.location.href);
        } catch (error) {
          return;
        }

        if (
          url.origin === window.location.origin &&
          url.pathname === window.location.pathname &&
          url.hash !== ''
        ) {
          event.preventDefault();
          revealHash(url.hash, true);
          window.history.replaceState(
            null,
            '',
            `${window.location.pathname}${window.location.search}${url.hash}`
          );
        }
      });
    });

    if (window.location.hash !== '') {
      revealHash(window.location.hash, false);
    }
  };

  setupClosablePanels();

  const setupRoulette = () => {
    const panel = document.getElementById('roulette-panel');
    const dataScript = document.getElementById('roulette-data');
    if (!(panel instanceof HTMLElement) || !(dataScript instanceof HTMLScriptElement)) {
      return;
    }

    let items = [];
    try {
      const parsed = JSON.parse(dataScript.textContent || '[]');
      if (Array.isArray(parsed)) {
        items = parsed;
      }
    } catch (error) {
      items = [];
    }

    const normalizeItem = (item) => {
      if (!item || typeof item !== 'object') {
        return null;
      }

      const position = Number(item.position);
      const currentPosition = Number(item.currentPosition);
      const id = Number(item.id);
      const name = String(item.name || '').trim();
      const bucket = String(item.bucket || '').trim();
      if (!Number.isFinite(position) || position < 1 || name === '' || bucket === '') {
        return null;
      }

      return {
        id: Number.isFinite(id) ? id : 0,
        bucket,
        shown: Boolean(item.shown),
        position,
        currentPosition: Number.isFinite(currentPosition) && currentPosition > 0 ? currentPosition : position,
        name,
        creator: String(item.creator || '').trim(),
        url: String(item.url || '#'),
        videoUrl: String(item.videoUrl || '').trim(),
        thumb: String(item.thumb || '').trim(),
        levelId: String(item.levelId || '').trim(),
        byline: String(item.byline || '').trim(),
        score: String(item.score || '').trim(),
      };
    };

    items = items.map(normalizeItem).filter((item) => item !== null);

    if (items.length === 0) {
      return;
    }

    const bucketInputs = Array.from(panel.querySelectorAll('[data-roulette-bucket]'))
      .filter((input) => input instanceof HTMLInputElement);
    const startButton = panel.querySelector('[data-roulette-start]');
    const saveButton = panel.querySelector('[data-roulette-save]');
    const loadInput = panel.querySelector('[data-roulette-load]');
    const stackEl = panel.querySelector('[data-roulette-stack]');
    const resultsEl = panel.querySelector('[data-roulette-results]');
    const showRemainingButton = panel.querySelector('[data-roulette-show-remaining]');
    const remainingEl = panel.querySelector('[data-roulette-remaining]');
    const remainingListEl = panel.querySelector('[data-roulette-remaining-list]');

    if (
      !(startButton instanceof HTMLButtonElement) ||
      !(saveButton instanceof HTMLButtonElement) ||
      !(loadInput instanceof HTMLInputElement) ||
      !(stackEl instanceof HTMLElement) ||
      !(resultsEl instanceof HTMLElement) ||
      !(showRemainingButton instanceof HTMLButtonElement) ||
      !(remainingEl instanceof HTMLElement) ||
      !(remainingListEl instanceof HTMLElement)
    ) {
      return;
    }

    const selectedBuckets = () => {
      return bucketInputs
        .filter((input) => input.checked)
        .map((input) => String(input.getAttribute('data-roulette-bucket') || '').trim())
        .filter((bucket) => bucket !== '');
    };

    const newState = () => ({
      playing: false,
      demons: [],
      current: 0,
      percent: 1,
      percents: [],
      selectedBuckets: selectedBuckets(),
      showRemaining: false,
    });

    let state = newState();

    const syncListFocus = () => {
      document.querySelectorAll('[data-roulette-target].roulette-focus').forEach((card) => {
        card.classList.remove('roulette-focus');
      });

      if (!state.playing || state.demons.length === 0) {
        return;
      }

      const active = state.demons[state.current];
      if (!active || !Number.isFinite(Number(active.id))) {
        return;
      }

      const selector = `[data-roulette-target="${String(active.id)}"]`;
      const target = document.querySelector(selector);
      if (target instanceof HTMLElement) {
        target.classList.add('roulette-focus');
      }
    };

    const shuffle = (input) => {
      const output = input.slice();
      for (let i = output.length - 1; i > 0; i -= 1) {
        const j = Math.floor(Math.random() * (i + 1));
        const temp = output[i];
        output[i] = output[j];
        output[j] = temp;
      }
      return output;
    };

    const setBackground = (element, url) => {
      if (url !== '') {
        element.style.backgroundImage = `url('${url.replace(/'/g, '%27')}')`;
      } else {
        element.style.backgroundImage = 'linear-gradient(135deg, #1f3048 0%, #101824 100%)';
      }
    };

    const createText = (tagName, className, text) => {
      const element = document.createElement(tagName);
      if (className !== '') {
        element.className = className;
      }
      element.textContent = text;
      return element;
    };

    const createDemonCard = (demon, index, options = {}) => {
      const active = Boolean(options.active);
      const completedPercent = Number(options.completedPercent || 0);
      const plannedPercent = Number(options.plannedPercent || 0);

      const article = document.createElement('article');
      article.className = 'roulette-demon-card';
      if (active) {
        article.classList.add('is-active', 'fade-in-up');
      }

      const thumb = document.createElement('a');
      thumb.className = 'roulette-demon-thumb';
      thumb.href = demon.videoUrl !== '' ? demon.videoUrl : demon.url;
      if (demon.videoUrl !== '') {
        thumb.target = '_blank';
        thumb.rel = 'noreferrer';
      }
      thumb.setAttribute('aria-label', `Open ${demon.name}`);
      setBackground(thumb, demon.thumb);
      article.appendChild(thumb);

      const body = document.createElement('div');
      body.className = 'roulette-demon-body';

      const title = document.createElement('h3');
      const link = document.createElement('a');
      link.href = demon.url;
      link.textContent = `#${demon.position} - ${demon.name}`;
      title.appendChild(link);
      body.appendChild(title);

      const creator = demon.creator !== '' ? `by ${demon.creator}` : demon.byline;
      body.appendChild(createText('p', 'roulette-demon-creator', creator));

      if (demon.levelId !== '') {
        body.appendChild(createText('p', 'roulette-demon-meta', `Level ID: ${demon.levelId}`));
      }

      article.appendChild(body);

      const controls = document.createElement('div');
      controls.className = 'roulette-demon-controls';

      if (active) {
        const input = document.createElement('input');
        input.type = 'number';
        input.min = String(state.percent);
        input.max = '100';
        input.step = '1';
        input.placeholder = `At least ${state.percent}%`;
        input.inputMode = 'numeric';
        input.setAttribute('aria-label', `Progress for ${demon.name}`);

        const error = createText('p', 'error roulette-progress-error', '');
        const actions = document.createElement('div');
        actions.className = 'homepage-tool-actions roulette-card-actions';

        const doneButton = document.createElement('button');
        doneButton.type = 'button';
        doneButton.className = 'button blue hover';
        doneButton.textContent = 'Done';
        doneButton.addEventListener('click', () => {
          completeActiveDemon(input, error);
        });

        const giveUpButton = document.createElement('button');
        giveUpButton.type = 'button';
        giveUpButton.className = 'button red hover';
        giveUpButton.textContent = 'Give up';
        giveUpButton.addEventListener('click', giveUp);

        actions.appendChild(doneButton);
        actions.appendChild(giveUpButton);

        if (demon.levelId !== '') {
          const copyButton = document.createElement('button');
          copyButton.type = 'button';
          copyButton.className = 'button white hover';
          copyButton.textContent = 'Copy ID';
          copyButton.addEventListener('click', () => {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
              navigator.clipboard.writeText(demon.levelId).then(() => {
                copyButton.textContent = 'Copied';
                window.setTimeout(() => {
                  copyButton.textContent = 'Copy ID';
                }, 900);
              });
            }
          });
          actions.appendChild(copyButton);
        }

        input.addEventListener('keydown', (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            completeActiveDemon(input, error);
          }
        });

        controls.appendChild(input);
        controls.appendChild(error);
        controls.appendChild(actions);
      } else if (completedPercent > 0) {
        controls.appendChild(createText('strong', 'roulette-percent-badge', `${completedPercent}%`));
      } else if (plannedPercent > 0) {
        controls.appendChild(createText('span', 'roulette-percent-badge muted', `${plannedPercent}%`));
      } else {
        controls.appendChild(createText('span', 'roulette-percent-badge muted', `#${index + 1}`));
      }

      article.appendChild(controls);
      return article;
    };

    function renderRemaining() {
      remainingListEl.innerHTML = '';
      const remaining = state.demons
        .slice(state.current + 1)
        .filter((_, index) => state.percent + index + 1 <= 100);

      remaining.forEach((demon, index) => {
        remainingListEl.appendChild(
          createDemonCard(demon, state.current + index + 1, {
            plannedPercent: state.percent + index + 1,
          })
        );
      });

      remainingEl.hidden = remaining.length === 0;
    }

    function render() {
      const hasRun = state.demons.length > 0;
      bucketInputs.forEach((input) => {
        input.disabled = state.playing;
        input.checked = state.selectedBuckets.includes(String(input.getAttribute('data-roulette-bucket') || ''));
      });

      startButton.textContent = state.playing ? 'Restart' : 'Start';
      saveButton.disabled = !hasRun;
      resultsEl.hidden = state.playing || !hasRun;

      if (!hasRun) {
        stackEl.innerHTML = '';
        remainingEl.hidden = true;
        syncListFocus();
        return;
      }

      stackEl.innerHTML = '';
      state.demons.slice(0, state.current + 1).forEach((demon, index) => {
        stackEl.appendChild(
          createDemonCard(demon, index, {
            active: state.playing && index === state.current,
            completedPercent: Number(state.percents[index] || 0),
          })
        );
      });

      showRemainingButton.hidden = state.percent > 100 || state.current >= state.demons.length - 1;

      if (state.showRemaining) {
        renderRemaining();
      } else {
        remainingEl.hidden = true;
      }

      syncListFocus();
    }

    function start() {
      const buckets = selectedBuckets();
      if (buckets.length === 0) {
        window.alert('Select at least one list.');
        return;
      }

      const candidates = items.filter((item) => buckets.includes(item.bucket));
      if (candidates.length === 0) {
        window.alert('No demons available in the selected lists.');
        return;
      }

      state = {
        playing: true,
        demons: shuffle(candidates),
        current: 0,
        percent: 1,
        percents: [],
        selectedBuckets: buckets,
        showRemaining: false,
      };
      render();
    }

    function completeActiveDemon(input, error) {
      const value = Number.parseInt(input.value, 10);
      if (!Number.isFinite(value) || value < state.percent) {
        error.textContent = `Enter at least ${state.percent}%.`;
        return;
      }

      const percent = Math.min(value, 100);
      state.percents[state.current] = percent;

      if (percent >= 100 || state.current >= state.demons.length - 1) {
        state.playing = false;
      } else {
        state.current += 1;
      }

      state.percent = percent + 1;
      state.showRemaining = false;
      render();

      const activeCard = panel.querySelector('.roulette-demon-card.is-active');
      if (activeCard instanceof HTMLElement) {
        activeCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }

    function giveUp() {
      if (!window.confirm('Give up on this roulette run?')) {
        return;
      }

      state.playing = false;
      state.showRemaining = false;
      render();
    }

    function exportSave() {
      if (state.demons.length === 0) {
        return;
      }

      const payload = {
        version: 2,
        savedAt: new Date().toISOString(),
        state,
      };
      const blob = new Blob([JSON.stringify(payload, null, 2)], {
        type: 'application/json',
      });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'roulette-save.json';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => {
        URL.revokeObjectURL(link.href);
      }, 0);
    }

    function applyLoadedState(rawState) {
      if (!rawState || typeof rawState !== 'object') {
        throw new Error('Invalid save file.');
      }

      const demons = Array.isArray(rawState.demons)
        ? rawState.demons.map(normalizeItem).filter((item) => item !== null)
        : [];
      if (demons.length === 0) {
        throw new Error('Save file has no demons.');
      }

      const selected = Array.isArray(rawState.selectedBuckets)
        ? rawState.selectedBuckets.map((bucket) => String(bucket)).filter((bucket) => bucket !== '')
        : selectedBuckets();

      const percents = Array.isArray(rawState.percents)
        ? rawState.percents
            .map((percent) => Number.parseInt(percent, 10))
            .filter((percent) => Number.isFinite(percent) && percent > 0)
        : [];

      state = {
        playing: Boolean(rawState.playing),
        demons,
        current: Math.max(0, Math.min(Number.parseInt(rawState.current, 10) || 0, demons.length - 1)),
        percent: Math.max(1, Math.min(Number.parseInt(rawState.percent, 10) || 1, 101)),
        percents,
        selectedBuckets: selected,
        showRemaining: false,
      };

      if (state.current >= demons.length) {
        state.current = demons.length - 1;
      }
    }

    startButton.addEventListener('click', start);
    saveButton.addEventListener('click', exportSave);
    showRemainingButton.addEventListener('click', () => {
      state.showRemaining = !state.showRemaining;
      render();
      if (state.showRemaining) {
        remainingEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });

    loadInput.addEventListener('change', () => {
      const file = loadInput.files && loadInput.files[0] ? loadInput.files[0] : null;
      if (file === null) {
        return;
      }

      file.text()
        .then((text) => {
          const payload = JSON.parse(text);
          applyLoadedState(payload && payload.state ? payload.state : payload);
          window.alert('Roulette save loaded.');
        })
        .catch(() => {
          window.alert('Could not load that roulette save.');
        })
        .finally(() => {
          loadInput.value = '';
        });
    });

    render();
  };

  setupRoulette();

  const setupLevelInfoRows = () => {
    document.querySelectorAll('[data-level-info-builder]').forEach((builder) => {
      if (!(builder instanceof HTMLElement)) {
        return;
      }

      const rows = builder.querySelector('[data-level-info-rows]');
      const template = builder.querySelector('template[data-level-info-template]');
      const addButton = builder.querySelector('[data-level-info-add]');
      if (!(rows instanceof HTMLElement) || !(template instanceof HTMLTemplateElement) || !(addButton instanceof HTMLButtonElement)) {
        return;
      }

      const bindRemove = (scope) => {
        scope.querySelectorAll('[data-level-info-remove]').forEach((button) => {
          if (!(button instanceof HTMLButtonElement) || button.dataset.bound === '1') {
            return;
          }

          button.dataset.bound = '1';
          button.addEventListener('click', () => {
            const row = button.closest('[data-level-info-row]');
            if (row instanceof HTMLElement) {
              row.remove();
            }
          });
        });
      };

      addButton.addEventListener('click', () => {
        const fragment = template.content.cloneNode(true);
        rows.appendChild(fragment);
        bindRemove(rows);
      });

      bindRemove(builder);
    });
  };

  setupLevelInfoRows();

  document.querySelectorAll('[data-confirm]').forEach((element) => {
    element.addEventListener('click', (event) => {
      const button = event.currentTarget;
      if (!(button instanceof HTMLElement)) {
        return;
      }
      const question = button.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(question)) {
        event.preventDefault();
      }
    });
  });
})();
