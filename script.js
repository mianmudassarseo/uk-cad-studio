/* UKCADStudio — site behaviour
   Sticky header, mobile navigation, dropdown menus, scroll reveals,
   before/after slider and validated contact form submission. */
(function () {
  'use strict';

  /* ---------------- Sticky header ---------------- */
  var header = document.querySelector('#site-header');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('is-scrolled', window.scrollY > 12);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------------- Mobile navigation ---------------- */
  var navToggle = document.querySelector('.nav-toggle');
  var nav = document.querySelector('#primary-nav');

  function closeMenu() {
    if (!nav) return;
    nav.classList.remove('is-open');
    if (navToggle) {
      navToggle.setAttribute('aria-expanded', 'false');
      navToggle.setAttribute('aria-label', 'Open menu');
    }
  }

  if (navToggle && nav) {
    navToggle.addEventListener('click', function () {
      var open = navToggle.getAttribute('aria-expanded') === 'true';
      navToggle.setAttribute('aria-expanded', String(!open));
      navToggle.setAttribute('aria-label', open ? 'Open menu' : 'Close menu');
      nav.classList.toggle('is-open', !open);
      if (open) closeAllSubmenus();
    });
  }

  /* ---------------- Dropdown submenus ---------------- */
  var parents = Array.prototype.slice.call(document.querySelectorAll('.nav-parent'));

  function closeAllSubmenus(except) {
    parents.forEach(function (btn) {
      if (btn !== except) btn.setAttribute('aria-expanded', 'false');
    });
  }

  parents.forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = btn.getAttribute('aria-expanded') === 'true';
      closeAllSubmenus(btn);
      btn.setAttribute('aria-expanded', String(!open));
    });
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.nav-item')) closeAllSubmenus();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllSubmenus();
      closeMenu();
    }
  });

  if (nav) {
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        closeMenu();
        closeAllSubmenus();
      });
    });
  }

  /* ---------------- Scroll reveal ---------------- */
  var revealItems = document.querySelectorAll('.reveal');
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (reduced || !('IntersectionObserver' in window)) {
    revealItems.forEach(function (item) { item.classList.add('is-visible'); });
  } else {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealItems.forEach(function (item) { observer.observe(item); });
  }

  /* ---------------- Before / after slider ---------------- */
  var compareRange = document.querySelector('#compare-range');
  var compareCard = document.querySelector('[data-compare]');

  if (compareRange && compareCard) {
    var setCompare = function (value) {
      compareCard.style.setProperty('--pos', value + '%');
    };
    setCompare(compareRange.value);
    compareRange.addEventListener('input', function (e) { setCompare(e.target.value); });
  }

  /* ---------------- Contact form ---------------- */
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function fieldWrap(input) {
    return input.closest('label') || input.parentNode;
  }

  function setError(input, message) {
    var wrap = fieldWrap(input);
    var msg = wrap.querySelector('.field-error');
    if (!msg) {
      msg = document.createElement('span');
      msg.className = 'field-error';
      wrap.appendChild(msg);
    }
    msg.textContent = message;
    wrap.classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
  }

  function clearError(input) {
    var wrap = fieldWrap(input);
    wrap.classList.remove('is-invalid');
    input.removeAttribute('aria-invalid');
  }

  function validate(form) {
    var ok = true;
    var firstBad = null;

    form.querySelectorAll('input, select, textarea').forEach(function (input) {
      if (input.type === 'hidden' || input.name === 'website') return;
      clearError(input);

      var value = (input.value || '').trim();

      if (input.type === 'checkbox') {
        if (input.required && !input.checked) {
          setError(input, 'Please tick this box to continue.');
          ok = false; firstBad = firstBad || input;
        }
        return;
      }

      if (input.required && !value) {
        setError(input, 'This field is required.');
        ok = false; firstBad = firstBad || input;
        return;
      }

      if (input.type === 'email' && value && !EMAIL_RE.test(value)) {
        setError(input, 'Enter a valid email address, e.g. name@example.com');
        ok = false; firstBad = firstBad || input;
        return;
      }

      if (input.type === 'tel' && value && value.replace(/[^\d]/g, '').length < 7) {
        setError(input, 'Enter a valid phone number.');
        ok = false; firstBad = firstBad || input;
        return;
      }

      if (input.name === 'message' && input.required && value.length < 10) {
        setError(input, 'Please give us a little more detail (at least 10 characters).');
        ok = false; firstBad = firstBad || input;
      }
    });

    if (firstBad) firstBad.focus();
    return ok;
  }

  function showStatus(form, type, text) {
    var box = form.querySelector('.form-status');
    if (!box) {
      box = document.createElement('div');
      box.className = 'form-status';
      box.setAttribute('role', 'status');
      form.insertBefore(box, form.firstChild);
    }
    box.className = 'form-status is-shown form-status--' + type;
    box.textContent = text;
    box.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
  }

  document.querySelectorAll('form[data-mail]').forEach(function (form) {
    // Clear errors as the user corrects them.
    form.addEventListener('input', function (e) {
      if (e.target.matches('input, select, textarea')) clearError(e.target);
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!validate(form)) return;

      var button = form.querySelector('[type="submit"]');
      var original = button ? button.textContent : '';
      if (button) {
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'Sending…';
      }

      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) { return res.json().catch(function () { return { ok: res.ok }; }); })
        .then(function (data) {
          if (data && data.ok) {
            showStatus(form, 'ok', data.message || 'Thank you — your enquiry has been sent. We will reply within one working day.');
            form.reset();
          } else {
            showStatus(form, 'err', (data && data.message) || 'Sorry, your message could not be sent. Please email us directly at raotipusultaan@gmail.com.');
          }
        })
        .catch(function () {
          showStatus(form, 'err', 'Sorry, there was a network problem. Please email us directly at raotipusultaan@gmail.com.');
        })
        .finally(function () {
          if (button) {
            button.removeAttribute('aria-busy');
            button.textContent = original;
          }
        });
    });
  });

  /* ---------------- Hidden form metadata ---------------- */
  document.querySelectorAll('input[name="ts"]').forEach(function (el) {
    el.value = String(Math.floor(Date.now() / 1000));
  });
  document.querySelectorAll('input[name="page"]').forEach(function (el) {
    el.value = document.title + ' — ' + window.location.pathname;
  });

  /* ---------------- Current year in footer ---------------- */
  document.querySelectorAll('[data-year]').forEach(function (el) {
    el.textContent = String(new Date().getFullYear());
  });
})();
