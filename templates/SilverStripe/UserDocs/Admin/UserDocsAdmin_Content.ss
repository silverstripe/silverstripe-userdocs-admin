<% if $CurrentDocSlug %>
<%-- Left and right panel together (i.e. we are viewing a docc page) --%>
<div class="has-panel cms-content flexbox-area-grow fill-width fill-height $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">
	<%-- Tools includes the left panel --%>
    $Tools
    <%-- The rest of this is the right panel --%>
    <div class="fill-height flexbox-area-grow">
        <div class="short-width-header">
            <div class="cms-content-header north vertical-align-items">
                <div class="cms-content-header-info fill-width vertical-align-items">
                    <div class="breadcrumbs-wrapper">
                        <span class="crumb last">$SectionTitle</span>
                    </div>
                    <button type="button" class="btn menu-button menu-button--open" aria-controls="docs-tree-flyout-menu" aria-expanded="false">
                        <span class="btn__icon menu-button__icon font-icon-list" aria-hidden="true"></span>
                        <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.TreeContents 'Contents' %>
                    </button>
                </div>
            </div>
        </div>
		<div class="panel panel--padded panel--scrollable flexbox-area-grow fill-height docs-shadow-root"<% if $CurrentDocLang %> lang="$CurrentDocLang.ATT"<% end_if %>>
            <template shadowrootmode="open">
                <div class="user-docs-container">
                    $RenderedDocs
                    <%-- Note we put the CSS files after the rendered content so that we can reliably use :first to target the h1 with no breadcrumbs. --%>
                    <% loop $CssFiles %>
                        <link rel="stylesheet" href="$resourceURL($Me)">
                    <% end_loop %>
                </div>
            </template>
		</div>
	</div>
    <%-- Flyout tree menu for short-width windows --%>
    <div id="docs-tree-flyout-menu" class="flyout-menu" tabindex="-1">
        <div class="flyout-menu__tree panel panel--scrollable cms-panel-content flexbox-area-grow fill-height">
            <div class="breadcrumbs-wrapper flyout-menu__title">
                <span class="crumb last"><%t SilverStripe\UserDocs\Admin\UserDocsAdmin.TreeContents 'Contents' %></span>
                <button type="button" class="btn btn--icon-xl btn--no-text menu-button menu-button--close" aria-controls="docs-tree-flyout-menu" aria-expanded="true">
                    <span class="menu-button__icon btn__icon font-icon-cancel" aria-hidden="true"></span>
                    <span class="visually-hidden"><%t SilverStripe\UserDocs\Admin\UserDocsAdmin.Close 'Close' %></span>
                </button>
            </div>
            <div
                tabindex="-1"
                class="panel panel--padded panel--scrollable flexbox-area-grow fill-height flexbox-display cms-content-view cms-tree-view-sidebar cms-panel-deferred"
                data-url="$Link('treeview')"
                data-url-treeview="$Link('treeview')"
                data-no-ajax="<% if $TreeIsFiltered %>true<% else %>false<% end_if %>"
            >
            </div>
        </div>
    </div>
</div>

<% else %>
<%-- Left panel only --%>
<div id="pages-controller-cms-content" class="flexbox-area-grow fill-height cms-content $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">
    <% include SilverStripe\\UserDocs\\Admin\\UserDocsAdmin_LeftPanel %>
</div>
<% end_if %>
