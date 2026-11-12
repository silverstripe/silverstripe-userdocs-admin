---
title: Search results
summary: Configure result ranking, excluded content and how results are shown
---

# Search results

## Result ranking

A search finds matches in the text of each page. Matches in the page title and in headings can be given more weight than matches in the rest of the page, so that pages about a topic rank higher than pages that only mention it.

The weights are set in the [`SearchIndex.zone_weights`](api:SilverStripe\UserDocs\Search\SearchIndex->zone_weights) configuration array. The keys can be `title` for the page title, or `h1` to `h6` for headings of that level. The rest of the page has a weight of `1`, so a weight above `1` makes matches more important and a weight below `1` makes them less important.

These are the default weights:

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  zone_weights:
    title: 5
    h1: 4
    h2: 2
    h3: 1
```

To give a heading level its own weight, add it. To treat a heading level or the title the same as the rest of the page, set its weight to `null`:

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  zone_weights:
    h3: null
    h4: 1.5
```

## Excluding content from search

Some content isn't useful in search results, such as the table of contents. Markdown nodes of the classes in the [`SearchIndex.excluded_node_classes`](api:SilverStripe\UserDocs\Search\SearchIndex->excluded_node_classes) configuration array are left out of the search index and the search result summaries. Subclasses of those classes and the content inside those nodes are left out too.

For example, to leave code blocks out of search:

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  excluded_node_classes:
    - 'League\CommonMark\Extension\CommonMark\Node\Block\FencedCode'
    - 'League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode'
```

## Results display

Search results are shown 20 at a time. To change this, set the [`UserDocsAdmin.search_results_per_page`](api:SilverStripe\UserDocs\Admin\UserDocsAdmin->search_results_per_page) configuration property. Set it to `0` to show all results on one page.

Each result shows a summary of the page text around the words that matched. To change the length of the summary in characters, set the [`SearchIndex.summary_length`](api:SilverStripe\UserDocs\Search\SearchIndex->summary_length) configuration property. The default is `300`.

```yml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  search_results_per_page: 10
SilverStripe\UserDocs\Search\SearchIndex:
  summary_length: 200
```
