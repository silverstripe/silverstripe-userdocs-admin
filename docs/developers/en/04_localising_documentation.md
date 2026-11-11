---
title: Localising documentation
summary: Provide documentation in other languages
---

# Localising documentation

While commercially supported modules will only include English documentation, anyone can include documentation for other languages, including translations of the official documentation.

You can do this by setting up documentation with the same file structure (including folder and file names) under the appropriate locale folder (for example `pt/` for Portuguese). Titles can be localised using [frontmatter](./writing_documentation#frontmatter-metadata).

> [!IMPORTANT]
> When localising documentation, you *must* match the folder and file names! Do not translate the names of folders or files. Instead, to update the title use frontmatter.

If you want to localise the name of a root that has no content directly associated with it, you can create an `index.md` file for that root folder and only include the frontmatter data.

## Fallback order

Content that hasn't been localised will fallback to unlocalised documentation. For example if your current locale is `pt_BR` and your default locale is `fr_FR`, the order in which documentation is looked up is as follows: `pt_BR`, `pt`, `fr_FR`, `fr`, `en`. English is always the final fallback that is checked, unless your current locale is also English in which case it takes priority.

## Overrides and translations

For each page of documentation, the module with the highest priority that has the page in any locale in the [fallback order](#fallback-order) is used. The page is shown in the first locale in the fallback order that this module has, even if a module with a lower priority has the page in a locale that comes earlier in the fallback order. If you translate documentation from another module in your own module, your module must have a higher priority than the other module. See [setting module priority](./overriding_documentation#setting-module-priority).

For example, if your project overrides `en/00_global_features/index.md` from module one, and module one also has `fr/00_global_features/index.md`, a user with a French locale sees your project's English page. This means your overrides are never hidden by a translation of the documentation you replaced. If you want French users to see a French version of your override, add `fr/00_global_features/index.md` to your project.

If a module only has a page in locales that aren't in the fallback order, the next module with that page is checked. For example, a German-only page in your project doesn't hide the English page from module one for English users.

## Images

Translated documentation doesn't need its own copy of every image. Images are looked up in the same locale folders as the documentation itself, in the same [fallback order](#fallback-order).
