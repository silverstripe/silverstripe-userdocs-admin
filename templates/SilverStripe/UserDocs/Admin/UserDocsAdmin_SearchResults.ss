<div class="userdocs-search-results flexbox-area-grow">
    <nav class="search-results__nav" aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.SearchResults 'Documentation Search Results' %>">
        <% if $Results %>
            <h2 class="search-results__heading">
                <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.DisplayingResults 'Displaying {first}-{last} of {total} results' first=$Results.FirstItem last=$Results.LastItem total=$Results.TotalItems %>
            </h2>
            <ul class="search-results__list">
            <% loop $Results %>
                <li class="search-results__result">
                    <h3 class="search-results__result-heading"><a class="search-results__result-link" href="$Top.DocLink($slug).ATT">$title</a></h3>
                    <div class="search-results__result-summary">$Top.SearchResultSummary($id)</div>
                    <% if $Top.BreadcrumbsForSlug($slug) %>
                        <div class="search-results__result-breadcrumb">
                            <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.FoundIn 'Found in:' %>
                            <ol class="search-results__result-crumbs-list">
                            <% loop $Top.BreadcrumbsForSlug($slug) %>
                                <li class="search-results__result-crumbs-list-item">
                                <% if $href %>
                                    <a class="search-results__result-crumb" href="$href">$title</a>
                                <% else %>
                                    <span class="search-results__result-crumb">$title</span>
                                <% end_if %>
                                </li>
                            <% end_loop %>
                            </ol>
                        </div>
                    <% end_if %>
                </li>
            <% end_loop %>
            </ul>

            <% with $Results %>
            <% if $MoreThanOnePage %>
                <nav class="pagination__nav" aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.Pagination 'Pagination' %>">
                    <a <% if $FirstLink && $NotFirstPage %>href="$FirstLink"<% end_if %> class="pagination__first btn btn-secondary btn--no-text btn-sm<% if not $FirstLink || $FirstPage %> disabled<% end_if %>"
                        title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationFirst 'First' %>"
                        aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationFirst 'First' %>"
                    >
                        <span aria-hidden="true" class="btn__icon font-icon-angle-double-left"></span>
                    </a>
                    <a <% if $PrevLink %>href="$PrevLink"<% end_if %> class="pagination__prev btn btn-secondary btn--no-text btn-sm <% if not $PrevLink %> disabled<% end_if %>"
                        title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationPrevious 'Previous' %>"
                        aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationPrevious 'Previous' %>"
                    >
                        <span aria-hidden="true" class="btn__icon font-icon-angle-left"></span>
                    </a>
                    <span class="pagination__page-number field">
                        <label class="pagination__label" for="userdocs-search-results-page">
                            <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationPage 'Page' %>
                        </label>
                        <input
                        id="userdocs-search-results-page"
                        class="pagination__input text no-change-track pagination"
                        inputmode="numeric"
                        min="1"
                        max="$TotalPages"
                        title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationInputTitle 'Current page, currently {current} of {total}. Enter a page number and press Enter to go to that page.' current=$CurrentPage total=$TotalPages %>"
                        value="$CurrentPage"
                        data-current-page="$CurrentPage"
                        data-page-length="$PageLength"
                        data-pagination-var="$PaginationGetVar"
                        />
                        <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationOf 'of' is 'Example: View 1 of 2' %>
                        $TotalPages
                    </span>
                    <a <% if $NextLink %>href="$NextLink"<% end_if %> class="pagination__next btn btn-secondary btn--no-text btn-sm<% if not $NextLink %> disabled<% end_if %>"
                        title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationNext 'Next' %>"
                        aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationNext 'Next' %>"
                    >
                        <span aria-hidden="true" class="btn__icon font-icon-angle-right"></span>
                    </a>
                    <a <% if $LastLink && $NotLastPage %>href="$LastLink"<% end_if %> class="pagination__last btn btn-secondary btn--no-text btn-sm<% if not $LastLink || $LastPage %> disabled<% end_if %>"
                        title="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationLast 'Last' %>"
                        aria-label="<%t SilverStripe\UserDocs\Admin\UserDocsAdmin.PaginationLast 'Last' %>"
                    >
                        <span aria-hidden="true" class="btn__icon font-icon-angle-double-right"></span>
                    </a>
                    </nav>

            <% end_if %>
            <% end_with %>
        <% else_if $SearchQuery('terms') && $SearchQueryRecommendationLink %>
            <h2 class="search-results__heading">
                <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.NoResultsWithSuggestion 'No results for "{searchterm}". Did you mean {suggestion}?' searchterm=$SearchQuery('terms') suggestion=$SearchQueryRecommendationLink %>
            </h2>
        <% else %>
            <h2 class="search-results__heading">
                <%t SilverStripe\UserDocs\Admin\UserDocsAdmin.NoResults 'No results found' %>
            </h2>
        <% end_if %>
    </nav>
</div>
