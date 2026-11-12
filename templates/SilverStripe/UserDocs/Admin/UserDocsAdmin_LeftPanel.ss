<div class="cms-content-header north vertical-align-items">
    <div class="cms-content-header-info fill-width vertical-align-items">
        <% include SilverStripe\\Admin\\CMSBreadcrumbs %>
        <% if $searchIsSupported %>$SearchForm.FilterButton($TreeIsFiltered)<% end_if %>
    </div>
</div>
<div class="panel panel--scrollable cms-panel-content flexbox-area-grow fill-height">
    <% if $searchIsSupported %>$SearchForm.Placeholder($TreeIsFiltered)<% end_if %>
    <% if $TreeIsFiltered %>
        $SearchResults
    <% else %>
    <div
        tabindex="-1"
        class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
        data-url="$Link('treeview')"
        data-url-treeview="$Link('treeview')"
        data-no-ajax="false"
    >
    </div>
    <% end_if %>
</div>
<%-- This is a bit of a hack to get the current doc slug passed in by jstree when calling treeview --%>
<div class="cms-edit-form visually-hidden"><input type="hidden" value="$CurrentDocSlugAsId.RAWURLATT.HTMLATT" name="ID"></div>
