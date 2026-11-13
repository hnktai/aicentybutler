/* AICENTY Salepage — interactions */
(function () {
  'use strict';

  /* Mobile nav toggle */
  var toggle = document.getElementById('navToggle');
  var links = document.getElementById('navLinks');
  if (toggle && links) {
    toggle.addEventListener('click', function () {
      links.classList.toggle('open');
    });
    links.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        links.classList.remove('open');
      });
    });
  }

  /* Sticky CTA bar — hiện sau khi cuộn qua hero */
  var sticky = document.getElementById('stickyBar');
  if (sticky) {
    var hero = document.querySelector('.hero');
    var threshold = hero ? hero.offsetHeight * 0.6 : 500;
    var onScroll = function () {
      if (window.scrollY > threshold) {
        sticky.classList.add('visible');
      } else {
        sticky.classList.remove('visible');
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* Stars nền hero */
  var starsWrap = document.getElementById('heroStars');
  if (starsWrap) {
    var frag = document.createDocumentFragment();
    for (var i = 0; i < 60; i++) {
      var s = document.createElement('span');
      var size = Math.random() * 2 + 1;
      s.style.cssText =
        'position:absolute;left:' + Math.random() * 100 + '%;top:' + Math.random() * 100 + '%;' +
        'width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:#fff;' +
        'opacity:' + (Math.random() * 0.5 + 0.15) + ';' +
        'animation:twinkle ' + (Math.random() * 4 + 2) + 's ease-in-out infinite;';
      frag.appendChild(s);
    }
    starsWrap.appendChild(frag);
    var style = document.createElement('style');
    style.textContent = '@keyframes twinkle { 0%,100%{opacity:.15} 50%{opacity:.7} }';
    document.head.appendChild(style);
  }

  /* Scroll reveal nhẹ */
  var revealEls = document.querySelectorAll('.feature-card, .studio-card, .testi-card, .pipe-step, .faq-item');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'none';
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08 });
    revealEls.forEach(function (el) {
      el.style.opacity = '0';
      el.style.transform = 'translateY(14px)';
      el.style.transition = 'opacity .5s ease, transform .5s ease';
      io.observe(el);
    });
  }
})();
