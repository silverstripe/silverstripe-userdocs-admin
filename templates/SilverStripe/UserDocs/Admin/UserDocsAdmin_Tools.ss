<div class="cms-content-tools fill-height cms-panel cms-panel-layout" data-expandOnClick="true" data-layout-type="border" id="cms-content-tools-UserDocsAdmin">

    <%-- left panel --%>
    <% include SilverStripe\\UserDocs\\Admin\\UserDocsAdmin_LeftPanel %>

    <%-- left panel (collapsed) --%>
    <div class="cms-panel-content-collapsed">
        <h3 class="cms-panel-header"><%t SilverStripe\UserDocs\Admin\UserDocsAdmin.UserDocumentationNavigation 'User Documentation Navigation' %></h3>
    </div>
    <div class="toolbar toolbar--south cms-panel-toggle">
        <button
            class="cms-panel-toggle__button"
            title="<%t SilverStripe\\Admin\\LeftAndMain.CollapsePanel "Collapse panel" %>"
            data-bs-toggle="tooltip"
            aria-expanded="true"
            aria-controls="cms-content-tools-UserDocsAdmin"
            data-expanded-label="&laquo;"
            data-expanded-title="<%t SilverStripe\\Admin\\LeftAndMain.CollapsePanel "Collapse panel" %>"
            data-collapsed-label="&raquo;"
            data-collapsed-title="<%t SilverStripe\\Admin\\LeftAndMain.ExpandPanel "Expand panel" %>"
        >&laquo;</button>
    </div>

</div>
