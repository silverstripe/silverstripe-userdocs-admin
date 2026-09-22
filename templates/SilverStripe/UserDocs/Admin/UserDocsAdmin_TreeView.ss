<% if $TreeIsFiltered %>
    <div class="cms-tree-filtered cms-notice flexbox-area-grow">
        <strong><%t SilverStripe\UserDocs\Admin\UserDocsAdmin.TreeFiltered 'Showing search results.' %></strong>
        <a href="javascript:void(0)" class="clear-filter">
            <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.TreeFilteredClear 'Clear' %>
        </a>

        <nav class="cms-tree userdocs-tree filtered-list no-context-menu"
            aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.DocumentationNavigation 'Documentation Navigation' %>"
            data-extra-params="SecurityID=$SecurityID.ATT"
        >
            $TreeAsUL
        </nav>
    </div>
<% else %>
    <nav class="cms-tree userdocs-tree flexbox-area-grow no-context-menu"
        aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.DocumentationNavigation 'Documentation Navigation' %>"
        data-extra-params="SecurityID=$SecurityID.ATT"
    >
        $TreeAsUL
    </nav>
<% end_if %>
