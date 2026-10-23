import jQuery from 'jquery';

jQuery.entwine('ss', ($) => {
  $('.UserDocsAdmin .docs-shadow-root').entwine({
    // Because of quirks with how entwine works, we can't just have the callback
    // as a normal entwine function, because instead of setting that function as
    // the hashchange event handler, it will set an internal entwine proxy
    // function as the event handler which doesn't do what we want.
    ScrollToCurrentHash: null,

    onmatch() {
      const self = this;
      // When the hash changes and when the page first loads, if the hash in the
      // location bar matches the ID of an element in the shadow DOM, scroll to
      // that element.
      this.setScrollToCurrentHash(() => {
        const hash = window.location.hash.slice(1);
        if (!hash) {
          return;
        }
        // Use requestAnimationFrame to ensure the Shadow DOM layout has rendered
        requestAnimationFrame(() => {
          const targetElement = self[0].shadowRoot.getElementById(hash);
          if (targetElement) {
            targetElement.scrollIntoView({ behavior: 'smooth' });
          }
        });
      });
      window.addEventListener('hashchange', this.getScrollToCurrentHash());
      this.getScrollToCurrentHash()();
    },
    onunmatch() {
      window.removeEventListener('hashchange', this.getScrollToCurrentHash());
    },

    /**
     * When clicking an anchor link inside the shadow root, mimic normal browser
     * scroll-to-element behaviour for the element with the matching ID.
     */
    onclick(e) {
      const target = e.originalEvent?.originalTarget;
      if (!target || target.tagName.toLowerCase() !== 'a' || !target.href.includes('#')) {
        return;
      }
      const rootNode = target.getRootNode();
      const path = target.href.split('#').at(0);
      const anchorId = target.href.split('#').at(-1);
      const anchorElement = rootNode.getElementById(anchorId);
      if (path === window.location.pathname && anchorElement) {
        e.preventDefault();
        anchorElement.scrollIntoView({ behavior: 'smooth' });
        // Only alter history state if we're changing the hash
        if (window.location.hash !== `#${anchorId}`) {
          const newUrl = `${window.location.pathname}${window.location.search}#${anchorId}`;
          history.pushState({ hash: anchorId }, '', newUrl);
        }
      }
    },
  });

  $('.UserDocsAdmin .menu-button').entwine({
    onclick() {
      const flyoutPanelId = this.attr('aria-controls');
      const flyoutPanel = $(`#${flyoutPanelId}`);
      flyoutPanel.toggleClass('flyout-menu__open');

      if (flyoutPanel.hasClass('flyout-menu__open')) {
        $('.UserDocsAdmin .menu-button--close').focus();
      } else {
        $('.UserDocsAdmin .menu-button--open').focus();
      }
    },
  });

  $('.UserDocsAdmin .flyout-menu__open').entwine({
    onclick(e) {
      // Only handle clicks outside the actual nav area
      if ($(e.target).closest('.flyout-menu__tree').length === 0) {
        this.handleEvent(e);
      }
    },
    onkeyup(e) {
      // Only handle keypress for escape key
      if (e.key === 'Escape') {
        this.handleEvent(e);
      }
    },
    /** Close the menu */
    handleEvent(e) {
      e.preventDefault();
      this.removeClass('flyout-menu__open');
      $('.UserDocsAdmin .menu-button--open').focus();
    }
  });

  $('.UserDocsAdmin .menu-button--close').entwine({
    onkeydown(e) {
      // If shift+tab on close button, loop back to the tree
      if (e.key === 'Tab' && e.shiftKey) {
        e.preventDefault();
        $('.flyout-menu .cms-tree-view-sidebar [tabIndex="0"]').focus();
      }
    },
  });

  $('.UserDocsAdmin .flyout-menu .cms-tree-view-sidebar').entwine({
    onkeydown(e) {
      // If tab on tree, loop back to the close button
      if (e.key === 'Tab' && !e.shiftKey) {
        e.preventDefault();
        $('.menu-button--close').focus();
      }
    },
  });

  $('.UserDocsAdmin a.fake-link').entwine({
    onfocusin() {
      this.siblings('.no-link').focus();
    }
  });
});
