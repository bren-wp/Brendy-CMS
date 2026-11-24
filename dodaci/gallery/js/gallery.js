(function () {
  'use strict';

  var lightbox = null;
  var lbImage = null;
  var lbPrev = null;
  var lbNext = null;
  var lbClose = null;
  var lbCounter = null;
  var currentGroup = [];
  var currentIndex = 0;
  var touchStartX = 0;
  var touchEndX = 0;
  var lastFocusedElement = null;
  var previousBodyOverflow = '';
  var closeTimer = null;
  var labels = { dialog: '', previous: '', next: '', close: '' };

  function loadLabels() {
    var node = document.getElementById('brendy-gallery-frontend-config');
    if (!node) return;
    try {
      var config = JSON.parse(node.textContent || '{}');
      if (!config || typeof config !== 'object' || !config.labels || typeof config.labels !== 'object') return;
      ['dialog', 'previous', 'next', 'close'].forEach(function (key) {
        if (typeof config.labels[key] === 'string' && config.labels[key].trim() !== '') labels[key] = config.labels[key].trim().slice(0, 200);
      });
    } catch (error) {
      return;
    }
  }

  function setLabel(element, value) {
    if (element && typeof value === 'string' && value !== '') element.setAttribute('aria-label', value);
  }

  function buildLightbox() {
    lightbox = document.createElement('div');
    lightbox.className = 'brendy-lightbox';
    lightbox.hidden = true;
    lightbox.setAttribute('role', 'dialog');
    lightbox.setAttribute('aria-modal', 'true');
    setLabel(lightbox, labels.dialog);
    lightbox.setAttribute('aria-hidden', 'true');

    lbImage = document.createElement('img');
    lbImage.className = 'brendy-lightbox-image';

    lbPrev = document.createElement('button');
    lbPrev.type = 'button';
    lbPrev.className = 'brendy-lightbox-prev';
    setLabel(lbPrev, labels.previous);

    lbNext = document.createElement('button');
    lbNext.type = 'button';
    lbNext.className = 'brendy-lightbox-next';
    setLabel(lbNext, labels.next);

    lbClose = document.createElement('button');
    lbClose.type = 'button';
    lbClose.className = 'brendy-lightbox-close';
    setLabel(lbClose, labels.close);

    lbCounter = document.createElement('span');
    lbCounter.className = 'brendy-lightbox-counter';
    lbCounter.setAttribute('aria-live', 'polite');
    lbCounter.setAttribute('aria-atomic', 'true');

    lightbox.appendChild(lbImage);
    lightbox.appendChild(lbPrev);
    lightbox.appendChild(lbNext);
    lightbox.appendChild(lbClose);
    lightbox.appendChild(lbCounter);
    document.body.appendChild(lightbox);

    lbClose.addEventListener('click', function (e) { e.stopPropagation(); closeLightbox(); });
    lbPrev.addEventListener('click', function (e) { e.stopPropagation(); navigate(-1); });
    lbNext.addEventListener('click', function (e) { e.stopPropagation(); navigate(1); });
    lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });

    lbImage.addEventListener('touchstart', function (e) {
      if (e.changedTouches && e.changedTouches[0]) touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    lbImage.addEventListener('touchend', function (e) {
      if (!e.changedTouches || !e.changedTouches[0] || currentGroup.length < 2) return;
      touchEndX = e.changedTouches[0].screenX;
      var delta = touchStartX - touchEndX;
      if (Math.abs(delta) >= 50) navigate(delta > 0 ? 1 : -1);
    }, { passive: true });
  }

  function openLightbox(items, startIndex) {
    if (!Array.isArray(items) || !items.length) return;
    if (!lightbox) buildLightbox();
    if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }

    currentGroup = items;
    currentIndex = Math.max(0, Math.min(Number(startIndex) || 0, items.length - 1));
    lastFocusedElement = document.activeElement;
    previousBodyOverflow = document.body.style.overflow;

    if (!showImage(currentIndex)) return;
    lightbox.hidden = false;
    lightbox.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(function () {
      lightbox.classList.add('brendy-lightbox-active');
      lbClose.focus({ preventScroll: true });
    });
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeyDown);
  }

  function finishClose() {
    if (!lightbox || lightbox.classList.contains('brendy-lightbox-active')) return;
    lightbox.hidden = true;
    lightbox.setAttribute('aria-hidden', 'true');
    lbImage.removeAttribute('src');
    currentGroup = [];
    document.body.style.overflow = previousBodyOverflow;
    if (lastFocusedElement && lastFocusedElement.isConnected && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus({ preventScroll: true });
    }
    lastFocusedElement = null;
  }

  function closeLightbox() {
    if (!lightbox || lightbox.hidden) return;
    lightbox.classList.remove('brendy-lightbox-active');
    document.removeEventListener('keydown', onKeyDown);

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) {
      finishClose();
      return;
    }

    var onEnd = function (event) {
      if (event.target !== lightbox || event.propertyName !== 'opacity') return;
      lightbox.removeEventListener('transitionend', onEnd);
      if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
      finishClose();
    };
    lightbox.addEventListener('transitionend', onEnd);
    closeTimer = window.setTimeout(function () {
      lightbox.removeEventListener('transitionend', onEnd);
      closeTimer = null;
      finishClose();
    }, 450);
  }

  function showImage(index) {
    var item = currentGroup[index];
    if (!item || typeof item.getAttribute !== 'function') return false;
    var href = String(item.getAttribute('href') || '').trim();
    if (!href) return false;
    var thumb = item.querySelector('img');
    var alt = thumb ? String(thumb.alt || '') : '';

    lbImage.src = href;
    lbImage.alt = alt;
    lbCounter.textContent = (index + 1) + ' / ' + currentGroup.length;

    var multiple = currentGroup.length > 1;
    lbPrev.disabled = !multiple;
    lbNext.disabled = !multiple;
    lbPrev.setAttribute('aria-hidden', multiple ? 'false' : 'true');
    lbNext.setAttribute('aria-hidden', multiple ? 'false' : 'true');
    lbPrev.style.visibility = multiple ? 'visible' : 'hidden';
    lbNext.style.visibility = multiple ? 'visible' : 'hidden';
    if (multiple) preloadAdjacent(index);
    return true;
  }

  function navigate(direction) {
    var len = currentGroup.length;
    if (len < 2) return;
    currentIndex = (currentIndex + direction + len) % len;
    showImage(currentIndex);
  }

  function preloadAdjacent(index) {
    var len = currentGroup.length;
    [-1, 1].forEach(function (offset) {
      var adjItem = currentGroup[(index + offset + len) % len];
      var src = adjItem && adjItem.getAttribute('href');
      if (src) { var img = new Image(); img.src = src; }
    });
  }

  function focusableControls() {
    return [lbPrev, lbNext, lbClose].filter(function (node) {
      return node && !node.disabled && node.getAttribute('aria-hidden') !== 'true';
    });
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') { e.preventDefault(); closeLightbox(); return; }
    if (e.key === 'ArrowLeft') { e.preventDefault(); navigate(-1); return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); navigate(1); return; }
    if (e.key !== 'Tab') return;

    var controls = focusableControls();
    if (!controls.length) { e.preventDefault(); return; }
    var first = controls[0];
    var last = controls[controls.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!lightbox.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  }

  function collectGroup(groupName, fallbackItem) {
    if (!groupName) return fallbackItem ? [fallbackItem] : [];
    return Array.prototype.slice.call(document.querySelectorAll('.brendy-gallery-item[data-gallery-group]')).filter(function (item) {
      return item.getAttribute('data-gallery-group') === groupName && String(item.getAttribute('href') || '').trim() !== '';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    loadLabels();

  document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var target = e.target instanceof Element ? e.target : e.target.parentElement;
      var item = target && target.closest ? target.closest('.brendy-gallery-item') : null;
      if (!item || !String(item.getAttribute('href') || '').trim()) return;

      var items = collectGroup(item.getAttribute('data-gallery-group'), item);
      var index = items.indexOf(item);
      if (index < 0) return;
      e.preventDefault();
      openLightbox(items, index);
    });
  });
}());
