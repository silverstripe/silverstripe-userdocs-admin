---
title: Setup userhelp docs
---

# Setup userhelp docs

User documentation can be registered by adding it to the [`UserDocsAdmin.documentation_roots`](api:SilverStripe\UserDocs\Admin\UserDocsAdmin->documentation_roots) configuration array. The path is a key in the array, and `true` is the value.

The path can either be in module:path format (i.e. the composer name of the module and the relative path within that module, separated by a colon), or can be a path relative to the root of your project.

Note that documentation must be within a Silverstripe CMS module (including your project itself, i.e. in the `app/` directory).

```yml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  documentation_roots:
    # module:path format
    'my/module:docs/userhelp': true
    # relative path format
    'app/docs/userhelp': true
```

If you want to unregister documentation for a given module, set the value in the array to `false`. This is useful for example if overriding a lot of functionality that a module provides, to the extent that the majority of its documentation is no longer valid for your project.

```yml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  documentation_roots:
    'my/module:docs/userhelp': false
```

> [!WARNING]
> The documentation manifest is cached, which means if you add/remove/rename any files or folders (including frontmatter changes) in your user documentation you will need to flush the cache to see those changes reflected.
> Changes to documentation content do not require a flush to be reflected in the rendered documentation.

## Frontmatter metadata

Documentation pages can include metadata in the form of frontmatter. This is YAML in a block fenced by `---` at the top of the file. For example:

```text
---
title: My page title
moredata: This is just some more data
---
```

You can include any information you want in the frontmatter, but the only information that will be used by this module is the title, which is used to override the default navigation tree title.

## Images in docs

To include images in your documentation, expose the folder(s) (see [exposing static resources](/developer_guides/templates/requirements/#exposing-static-resources)) and use relative paths the same way you normally would.

Translated documentation doesn't need its own copy of every image. Images are looked up in the same locale folders as the documentation itself, in the same order (see [localising documentation](./documentation_structure#localising-documentation)).

## CSS to style your docs

The rendered documentation is encapsulated in a shadow DOM, which allows us to include CSS that styles the docs without affecting the rest of the CMS.

You can include your own stylesheet by adding the path for it to the [`UserDocsAdmin.css_files`](api:SilverStripe\UserDocs\Admin\UserDocsAdmin->css_files) configuration property.

```yml
SilverStripe\UserDocs\Admin\UserDocsAdmin:
  css_files:
    - 'my/module:client/dist/docs.css'
```

## Supported markdown syntax

Out of the box, all [CommonMark](https://commonmark.org/) and [GitHub flavored](https://github.github.com/gfm/) markdown is supported. GitHub's [alert syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax#alerts) is also supported.

In addition to this, a table of contents (generated from h2 and lower headings) is added automatically after the first `h1`, and you can customise heading anchors by adding `{#id}` after the heading markdown (for example `## My heading {#custom-id}` will render a `h2` heading with the text "My heading" that has the anchor `#custom-id`). Custom heading anchors apply to the heading permalink as well as the table of contents and can be used in your own relative links. If you don't provide a custom anchor, one will be generated based on the heading text.

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
