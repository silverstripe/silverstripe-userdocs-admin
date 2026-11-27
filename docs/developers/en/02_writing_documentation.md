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
- Folders/files with a numeric prefix come before folders/files with no numeric prefix.
- If neither has a numeric prefix, files come before folders.
- Other folders/files are sorted alphabetically by their [navigation tree title](#navigation-tree-titles). Numbers and version numbers (e.g. `1.10.0`) are sorted by value.

The navigation tree title comes from the frontmatter if it's set, so localised documentation is sorted by its localised titles. Titles are compared with the rules of the CMS user's language, so case and accents don't put words out of order.

### Navigation tree titles

The title of a page of documentation in the navigation tree is derived by default from the name of the folder/file that represents it. For example `00_some_documentation.md` will have the title "Some documentation" by default.

If you want to update the navigation tree title for a page, you can do this using [frontmatter](#frontmatter-metadata). For a folder, set the title in the frontmatter of its `index.md` file. To change the title of a page from another module without altering content, see [extending documentation](./extending_documentation#extending-pages).

Note that the navigation tree title is not used in the documentation page itself. You should still include a `h1` heading to title your documentation page.

## Frontmatter metadata

Documentation pages can include metadata in the form of frontmatter. This is YAML in a block fenced by `---` at the top of the file. For example:

```yml
---
title: "My page title"
extends: true
moredata: "This is just some more data"
---
```

You can include any information you want in the frontmatter, but the only information that will be used by this module is as follows:

- `title`: (string) The navigation tree title for the page (see [navigation tree titles](#navigation-tree-titles))
- `extends`: (`true` or a module name) If set, the content from this file is appended to the content from the original instead of replacing it. A module name replaces, removes or translates that module's extension (see [extending documentation](./extending_documentation))
- `classes`: (class name or list of class names) The classes that the page documents (see [docs for classes](#docs-for-classes))
- `primary_for`: (class name or list of class names) The classes that this page is the main page for (see [main page for a class](#main-page-for-a-class))
- `related_docs`: (boolean) Set to `false` to leave out the [related docs](#related-docs) links. Other values have no effect.

A translation or an [override](./overriding_documentation) of a page can leave out frontmatter keys. Those keys get their values from the file that the translation or override replaces, in the same order that content falls back in (see [localising documentation](./localising_documentation#frontmatter)). This doesn't apply to `title` and `extends`, which only apply to the file they're in. If the translation or override sets a key, its own value is used, even if that value is empty, such as `[]` or `false`.

## Docs for classes

A page can name the classes it documents with the `classes` key in its frontmatter. This is used to link [related docs](#related-docs). The value can be one class name or a list of them, so a page can document classes that aren't related to each other:

```yml
---
classes:
  - 'App\Model\AccordionBlock'
  - 'App\Model\AccordionItem'
---
```

Use the fully qualified class name in single quotes. Don't use double quotes, because a backslash in double quotes starts an escape sequence in YAML.

A page can name a class from a module that isn't installed. That class name is ignored until the module is installed.

Any module can name any class, including classes from other modules. For example, your project can name `SilverStripe\CMS\Model\SiteTree` in a page about how pages work on your site. To add a class to a page from another module without replacing it, use an [extension](./extending_documentation#extending-pages) that has `classes` in its frontmatter. The classes from extensions are added to the classes of the page, so an extension can't remove a class.

### Main page for a class

More than one page can name the same class. One of them is the main page for that class. To choose the main page, add the class to the `primary_for` key of that page as well:

```yml
---
classes: 'App\Model\Product'
primary_for: 'App\Model\Product'
---
```

A class in `primary_for` only has an effect if the page also names it in `classes`. If no page has the class in `primary_for`, the first page in the navigation tree that names the class is the main page. If more than one page has it, the first of those pages in the navigation tree is the main page.

## Related docs

A "Related docs" heading and a list of links are added to the end of each page. The list has links to:

- For each class that the page names, the [main page](#main-page-for-a-class) for the nearest ancestor class that a page names. For example, if a page names `App\PageTypes\NewsPage` and no page names its parent class `Page`, the link goes to the main page for `SiteTree`.
- The parent page of the page.
- The child pages of the page.
- Other pages that name one of the same classes, except pages that contain this page or are inside it in the navigation tree. Main pages come first.

If there are no related docs, the heading isn't added. To leave out the related docs for a page, set `related_docs: false` in its frontmatter.

The related docs aren't part of the page content, so extensions can't add content near the heading (see [adding content near a heading](./extending_documentation#heading-inserts)). Content appended by an extension comes before the related docs.

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

If the linked page doesn't exist, the link text shows as plain text. This lets you link to documentation from modules that are often installed with yours, but aren't required by it. A link to a missing heading in a page that exists is still a link to that page.

Links that start with a protocol such as `http://` or `https://` aren't changed.

## Images in docs

To include images in your documentation, use relative paths the same way you normally would. Images must be in the same documentation root as the doc which uses them, and must be AVIF, GIF, JPEG, PNG, SVG, or WebP files.

You don't need to expose images in the public folder. Only users who can access the user docs section can see them.

Translated documentation doesn't need its own copy of every image (see [localising images](./localising_documentation#images)).

## Supported markdown syntax

Out of the box, all [CommonMark](https://commonmark.org/) and [GitHub flavored](https://github.github.com/gfm/) markdown is supported. GitHub's [alert syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax#alerts) is also supported.

In addition to this, a [table of contents](#table-of-contents) is added automatically, and you can customise heading anchors by adding `{#id}` after the heading markdown (for example `## My heading {#custom-id}` will render a `h2` heading with the text "My heading" that has the anchor `#custom-id`). Custom heading anchors apply to the heading permalink as well as the table of contents and can be used in your own relative links. If you don't provide a custom anchor, one will be generated based on the heading text.

To stop IDs in the documentation from clashing with IDs in the CMS, each ID is rendered with a `userdocs-` prefix. For example `## My heading {#custom-id}` renders `<h2 id="userdocs-custom-id">`. Don't include the prefix in your links. Write `[my heading](#custom-id)`, and the prefix is added to the link for you. This also applies to custom IDs on other elements, for example a paragraph followed by `{#custom-id}`. IDs in raw HTML (for example `<div id="custom-id">`) don't get the prefix, so links to them won't work.

### Table of contents

A table of contents is generated from the `h2` and lower headings, and is added before the first of these headings. This means it comes after the page title and the intro.

Only put a short intro between the `h1` and the first `h2`. Any other content in that space comes before the table of contents, so users can miss the headings below it.

If a page has no `h2` or lower headings, no table of contents is added. The [related docs](#related-docs) heading is in the table of contents, but it doesn't cause a table of contents to be added by itself.

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
