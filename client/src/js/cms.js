/* global jQuery, ss */
jQuery.entwine('ss', ($) => {
  // Handle flyout menu functionality and accessibility
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

  // Holds the index request so all search buttons and search panels share it
  $('.UserDocsAdmin.cms-content').entwine({
    IndexRequest: null,
  });

  // Trigger index when opening search panel
  $('.UserDocsAdmin button[name="showFilter"]').entwine({
    // Note we can't use the entwine onclick because of entwine event handler priority
    // Specifically, the click handler that opens the search panel doesn't use this._super()
    // so our click handler wouldn't have been called.
    onmatch() {
      this.one('click', this.performclick);
    },
    onunmatch() {
      this.off('click', this.performclick);
    },
    performclick() {
      const content = this.closest('.UserDocsAdmin.cms-content');
      // Another search button can have already started the index
      const existingRequest = content.getIndexRequest();
      if (existingRequest && existingRequest.state() !== 'rejected') {
        return;
      }
      const url = content.data('searchIndexUrl');
      if (url) {
        content.setIndexRequest($.ajax(url).fail(() => {
          jQuery.noticeAdd({
            text: ss.i18n._t('UserDocsAdmin.IndexError', 'Failed to create search index'),
            type: 'error',
            stayTime: 5000,
            inEffect: { left: '0', opacity: 'show' }
          });
        }));
      }
    }
  });

  // Go to the page number typed into the search results pagination field
  $('.UserDocsAdmin .pagination__input').entwine({
    onkeydown(e) {
      if (e.key !== 'Enter') {
        return;
      }
      e.preventDefault();
      const url = this.getPageUrl();
      if (!url) {
        return;
      }
      $('.cms-container').loadPanel(url);
    },
    /**
     * Get the URL for the page number in this field.
     * Returns null if the value is not valid or is already the current page.
     * Values outside the range of pages are changed to the nearest valid page.
     */
    getPageUrl() {
      const currentPage = parseInt(this.data('currentPage'), 10);
      const totalPages = parseInt(this.attr('max'), 10);
      const pageLength = parseInt(this.data('pageLength'), 10);
      const paginationVar = this.data('paginationVar');
      const value = String(this.val()).trim();
      if (!/^\d+$/.test(value)
        || !(totalPages > 0)
        || !(pageLength > 0)
        || !paginationVar
      ) {
        this.val(currentPage);
        return null;
      }
      // Constrain value to valid page numbers
      const page = Math.min(Math.max(parseInt(value, 10), 1), totalPages);
      this.val(page);
      if (page === currentPage) {
        return null;
      }
      const url = new URL(window.location.href);
      url.searchParams.set(paginationVar, String((page - 1) * pageLength));
      return url.toString();
    },
  });

  // Wait for the index to finish building, so the search doesn't start a second build
  $('.js-injector-boot .UserDocsAdmin .search-holder').entwine({
    search(data) {
      const content = this.closest('.UserDocsAdmin.cms-content');
      const indexRequest = content.getIndexRequest();
      if (indexRequest?.state() === 'pending') {
        content.addClass('loading');
        indexRequest.always(() => {
          content.removeClass('loading');
          this.search(data);
        });
        return;
      }
      this._super(data);
    },
  });
});
