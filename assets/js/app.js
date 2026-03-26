(() => {
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

