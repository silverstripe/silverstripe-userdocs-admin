<% if $node.isRoot %><ul><% end_if %>
<li id="record-{$controller.CurrentDocSlugAsId($node.slug).RAWURLATT.HTMLATT}" data-id="{$controller.CurrentDocSlugAsId($node.slug).RAWURLATT.HTMLATT}" class="<% if $node.isRoot %>Root nodelete <% end_if %>$node.markingClasses">
    <span class="jstree-icon jstree-icon--arrow"><span class="font-icon-right-dir" aria-hidden="true"></span>&nbsp;</span>
    <% if $node.slug && $node.hasContent %>
        <%-- IMPORTANT: There MUST NOT be any whitespace between the <a> element and the <ins> element below or it will break things in the JS --%>
        <%-- IMPORTANT: The <ins> element is required by jstree but is hidden because we dont actually want to render an icon there --%>
        <a title="$node.title.ATT" href="{$controller.Link($controller.join_links('docs', $node.slug)).ATT}" <% if $node.isCurrentPage %>tabindex="0" aria-current="page"<% else_if not $controller.CurrentDocSlug && $node.isFirstPage %>tabindex="0"<% else %>tabindex="-1"<% end_if %>><ins class="jstree-icon" style="display:none;"></ins>
            <span class="text">
                <% if $node.isRoot %><strong><% end_if %>$node.title<% if $node.isRoot %></strong><% end_if %>
            </span>
        </a>
    <% else %>
        <%-- The hidden a tag is necessary to allow root nodes to be included in the arrow key tree navigation --%>
        <a class="fake-link visually-hidden" aria-hidden="true"></a>
        <strong title="$node.title.ATT" class="no-link" tabindex="-1">$node.title</strong>
    <% end_if %>
<% if $children %>
    <ul class="jstree-child-list">
        <% loop $children %>
            <% include SilverStripe/UserDocs/Admin/UserDocsAdmin_SubTree controller=$Up.controller %>
        <% end_loop %>
    </ul>
<% end_if %>
</li>
<% if $node.isRoot %></ul><% end_if %>
