---
title: Writing documentation
summary: Structure documentation pages, and use frontmatter, links, images and markdown in them
---

# Writing documentation

## Navigation tree

The navigation tree is created based on the folder structure under the locale directory of the registered documentation directories (see [folder structure](./getting_started#folder-structure)). All registered documentation shares the same tree. See [overriding documentation](./overriding_documentation) for what happens when two modules have the same folder structure.

Consider this documentation structure:

```text
vendor/my/module/docs/userhelp/en
├─ 00_global_features
        ├─ 00_navigation_menus
                 ├─ index.md
                 ├─ 00_main_navigation.md
                 └─ 01_minor_navigation.md
        ├─ 01_header
                 └─ index.md
        └─ 02_footer
                 └─ index.md
└─ 01_something_else
        └─ index.md
```

That will render the following navigation tree:

- Global features
  - Navigation menus
    - Main navigation
    - Minor navigation
  - Header
  - Footer
- Something else

The content for a folder comes from the `index.md` file in that folder. If a root folder has no `index.md` file, or the `index.md` file has no content other than frontmatter, the root has no associated documentation and therefore no link. No level under the root level is allowed to exclude documentation.

### Sort order

> [!TIP]
> To get a predictable order, use a numeric prefix for all folders and files at the same level. Otherwise the order can be different in each language, because it depends on the titles.

Documentation at the same level of the navigation tree is sorted in a similar way to the [Silverstripe CMS developer documentation](https://docs.silverstripe.org):

- If two folders/files both have a numeric prefix, the lower number comes first (e.g. `01_file50.md` comes before `02_file1.md`). If the number is the same, folders come before files, and then they are sorted by title.
- Otherwise, files come before folders.
- Folders/files with a numeric prefix come before folders/files with no numeric prefix.
- Other folders/files are sorted alphabetically by their [navigation tree title](#navigation-tree-titles). Numbers and version numbers (e.g. `1.10.0`) are sorted by value.

The navigation tree title comes from the frontmatter if it's set, so localised documentation is sorted by its localised titles. Titles are compared with the rules of the CMS user's language, so case and accents don't put words out of order.

### Navigation tree titles

The title of a page of documentation in the navigation tree is derived by default from the name of the folder/file that represents it. For example `00_some_documentation.md` will have the title "Some documentation" by default.

If you want to update the navigation tree title for a page, you can do this using [frontmatter](#frontmatter-metadata). For a folder, set the title in the frontmatter of its `index.md` file.

Note that the navigation tree title is not used in the documentation page itself. You should still include a `h1` heading to title your documentation page.

## Frontmatter metadata

Documentation pages can include metadata in the form of frontmatter. This is YAML in a block fenced by `---` at the top of the file. For example:

```text
---
title: My page title
moredata: This is just some more data
---
```

You can include any information you want in the frontmatter, but the only information that will be used by this module is the title, which is used to override the default navigation tree title.

## Links between docs

To link to another page of documentation, use the path to its markdown file. The link is changed to the URL of that page in the CMS. The numeric prefixes and the `.md` extension are removed, so the link still works if the numeric prefix of a file changes in an override (see [overriding documentation](./overriding_documentation)).

A path that doesn't start with `/` is relative to the current file. A path that starts with `/` is relative to the locale folder. All registered documentation shares the same tree, so use `/` to link to pages from other modules.

To link to a page that has child pages, link to the `index.md` file in its folder.

```text
<!-- A page in the same folder -->
[Minor navigation](01_minor_navigation.md)

<!-- A heading in a page in another folder -->
[Footer links](../02_footer/index.md#links)

<!-- A page from any registered documentation -->
[Something else](/01_something_else/index.md)

<!-- A heading in the current page -->
[See below](#some-heading)
```

Links are made from the file names, which are the same in all locales, so a link goes to the translated version of the page if there is one.

Links that start with a protocol such as `http://` or `https://` aren't changed.

## Images in docs

To include images in your documentation, use relative paths the same way you normally would. Images must be in the same documentation root as the doc which uses them, and must be AVIF, GIF, JPEG, PNG, SVG, or WebP files.

You don't need to expose images in the public folder. Only users who can access the user docs section can see them.

Translated documentation doesn't need its own copy of every image (see [localising images](./localising_documentation#images)).

## Supported markdown syntax

Out of the box, all [CommonMark](https://commonmark.org/) and [GitHub flavored](https://github.github.com/gfm/) markdown is supported. GitHub's [alert syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax#alerts) is also supported.

In addition to this, a table of contents (generated from h2 and lower headings) is added automatically after the first `h1`, and you can customise heading anchors by adding `{#id}` after the heading markdown (for example `## My heading {#custom-id}` will render a `h2` heading with the text "My heading" that has the anchor `#custom-id`). Custom heading anchors apply to the heading permalink as well as the table of contents and can be used in your own relative links. If you don't provide a custom anchor, one will be generated based on the heading text.

To stop IDs in the documentation from clashing with IDs in the CMS, each ID is rendered with a `userdocs-` prefix. For example `## My heading {#custom-id}` renders `<h2 id="userdocs-custom-id">`. Don't include the prefix in your links. Write `[my heading](#custom-id)`, and the prefix is added to the link for you. This also applies to custom IDs on other elements, for example a paragraph followed by `{#custom-id}`. IDs in raw HTML (for example `<div id="custom-id">`) don't get the prefix, so links to them won't work.

### Markdown extensions and configuration

You can include additional extensions to support additional markdown syntax by updating the injector configuration:

```yml
SilverStripe\Core\Injector\Injector:
  League\CommonMark\Environment\Environment.userdocs:
    constructor:
      extensions:
        MyCustomMarkdownExtension: '%$App\Markdown\MyCustomMarkdownExtension'
```

You can also set configuration for the markdown environment using YAML:

```yml
SilverStripe\Core\Injector\Injector:
  League\CommonMark\Environment\Environment.userdocs:
    constructor:
      config:
        table_of_contents:
          min_heading_level: 1
```
