---
title: Userhelp documentation structure
---

# Documentation structure

The documentation tree is created based on the folder structure inside the registered documentation directories. Documentation is merged so that when multiple modules have the same folder structure, the documentation shares the same navigation tree. Any collisions where two modules have the same documentation file will be resolved by the module with the highest priority (as defined in the module manifest in the framework itself) taking precedence. The project itself is the highest priority module.

The first directory under the registered base directory is for the locale. The default is `en/` for English.

The root of a documentation navigation tree exists for each directory directly under the locale directory in registered documentation directories. The name of the root is derived from the name of the folder by default. See [navigation tree titles](#navigation-tree-titles) for details. A custom title can be set by creating an `index.md` file under this directory. Doing so will also ensure there is a link on the root node in the tree provided there is content (other than frontmatter) in the file. If there is no `index.md` file directly in the root directory or the `index.md` file lacks content, the root itself will have no associated documentation and therefore no link. No level under the root level is allowed to exclude documentation.

> [!WARNING]
> You must not have a markdown file directly under the locale directory. You need to define the root node for the navigation tree first by adding a directory under the locale directory, and put your markdown files in there.

The order of documentation is derived first by any numeric prefix of the folder/file name (e.g. `01_file50.md` will come before `02_file1.md`). Prefixed documentation always appears before documentation with no numeric prefix. If two folders/files at the same level have the same or no numeric prefix, folders take precedence. The order is then based alphabetically on the navigation title.

For example, consider this documentation structure:

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

## Navigation tree titles

The title of a page of documentation in the navigation tree is derived by default from the name of the folder/file that represents it. For example `00_some_documentation.md` will have the title "Some documentation" by default.

If you want to update the navigation tree title for a page, you can do this using [frontmatter](./setup#frontmatter-metadata).

Note that the navigation tree title is not used in the documentation page itself. You should still include a `h1` heading to title your documentation page.

## Overriding and merging documentation

Some modules may add additional functionality that needs to be documented under an existing section, or completely replace some functionality. This can be achieved by intentionally having a file with the same name in the same directory structure. For example, consider the following two modules:

```text
vendor/module/one/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 ├─ index.md
                 └─ 00_main_navigation.md
```

```text
vendor/module/two/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 ├─ index.md
                 └─ 01_minor_navigation.md
```

Assuming Module two has the higher priority, the documentation navigation tree will look like this:

- Global features
  - Navigation menus (documentation taken from module two)
    - Main navigation (documentation taken from module one)
    - Minor navigation (documentation taken from module two)

Note that the documentation from module two for "Navigation menus" completely overrides the docs from module one for the same file - but where they both provide separate docs, the docs seamlessly merge together into the tree.

> [!TIP]
> The numeric prefix is allowed to differ between your override folder/file and the original. It will still override the content and also update the ordering of the documentation.

### Adding new tree nodes

Sometimes it might be useful to turn a page of documentation into a whole section, adding new tree nodes under a page that previously had no child pages. Take this structure for example:

```text
vendor/module/one/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus.md
```

- Global features
  - Navigation menus

The "Navigation menus" page has no child pages. If you want to add child pages to that section in your module, you can do so by creating a folder named `00_navigation_menus/` and then adding new documentation pages inside that new directory:

```text
vendor/my/module/docs/userhelp/en
└─ 00_global_features
        └─ 00_navigation_menus
                 └─ 00_main_navigation.md
```

- Global features
  - Navigation menus
    - Main navigation

If you also want to override the documentation in the "Navigation menus" page, you would then need to create a `00_navigation_menus/index.md` file (though note the numeric prefix is allowed to differ).

## Localising documentation

While commercially supported modules will only include English documentation, anyone can include documentation for other languages, including translations of the official documentation.

You can do this by setting up documentation with the same file structure (including folder and file names) under the appropriate locale folder (for example `pt/` for Portuguese). Titles can be localised using [frontmatter](./setup#frontmatter-metadata).

> [!IMPORTANT]
> When localising documentation, you *must* match the folder and file names! Do not translate the names of folders or files. Instead, to update the title use frontmatter.

If you want to localise the name of a root that has no content directly associated with it, you can create an `index.md` file for that root folder and only include the frontmatter data.

Note that content that hasn't been localised will fallback to unlocalised documentation. For example if your current locale is `pt_BR` and your default locale is `fr_FR`, the order in which documentation is looked up is as follows: `pt_BR`, `pt`, `fr_FR`, `fr`, `en`. English is always the final fallback that is checked, unless your current locale is also English in which case it takes priority.
