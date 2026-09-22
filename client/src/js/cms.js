import jQuery from 'jquery';

jQuery.entwine('ss', ($) => {
  $('.UserDocsAdmin .menu-button').entwine({
    onclick() {
      const flyoutPanelId = this.attr('aria-controls');
      const flyoutPanel = $(`#${flyoutPanelId}`);
      flyoutPanel.toggleClass('flyout-menu__open');

      if (flyoutPanel.hasClass('flyout-menu__open')) {
        // Add the tree the first time the flyout opens
        const treeTemplate = flyoutPanel.find('template.flyout-menu__tree-template');
        if (treeTemplate.length) {
          treeTemplate.replaceWith(treeTemplate.prop('content').cloneNode(true));
        }
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
