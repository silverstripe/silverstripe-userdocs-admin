---
title: Getting started
summary: Register user documentation and add your first page
---

# Getting started

## Registering documentation

User documentation can be registered by adding it to the [`UserDocsManifest.documentation_roots`](api:SilverStripe\UserDocs\Admin\UserDocsManifest->documentation_roots) configuration array. The path is a key in the array, and `true` is the value.

The path can either be in module:path format (i.e. the composer name of the module and the relative path within that module, separated by a colon), or can be a path relative to the root of your project.

Note that documentation must be within a Silverstripe CMS module (including your project itself, i.e. in the `app/` directory).

```yml
SilverStripe\UserDocs\Admin\UserDocsManifest:
  documentation_roots:
    # module:path format
    'my/module:docs/userhelp': true
    # relative path format
    'app/docs/userhelp': true
```

To unregister documentation from another module, see [unregistering documentation](./overriding_documentation#unregistering-documentation).

> [!WARNING]
> The documentation manifest is cached, which means if you add/remove/rename any files or folders (including frontmatter changes) in your user documentation you will need to flush the cache to see those changes reflected.
> Changes to documentation content do not require a flush to be reflected in the rendered documentation.

## Folder structure

The first directory under the registered base directory is for the locale. The default is `en/` for English.

Each directory directly under the locale directory is the root of a navigation tree. Pages and sections go inside these root directories.

> [!WARNING]
> You must not have a markdown file directly under the locale directory. You need to define the root node for the navigation tree first by adding a directory under the locale directory, and put your markdown files in there.

```text
app/docs/userhelp
└─ en
        └─ 01_my_section
                 ├─ index.md
                 └─ 01_my_page.md
```

See [writing documentation](./writing_documentation) for how the folder structure becomes the navigation tree.

## Adding your first page

1. Register the `app/docs/userhelp` folder in the `documentation_roots` configuration, as shown above.
1. Create the `app/docs/userhelp/en/01_my_section/index.md` file.
1. Add a `h1` heading and some content to the file:

   ```text
   # My section

   This is the first page of my documentation.
   ```

1. Flush the cache.

The page shows in the "User docs" section of the CMS, with the title "My section" in the navigation tree.
