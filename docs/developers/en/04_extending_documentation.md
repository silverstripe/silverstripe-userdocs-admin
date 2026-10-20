---
title: Extending documentation
summary: Add content to documentation from other modules without replacing it
---

# Extending documentation

## Extending a page of documentation {#extending-pages}

Sometimes you might want to just add additional information to an existing page of documentation without replacing the page completely.

You can do this using [frontmatter](./writing_documentation#frontmatter-metadata), with the `extends` key. By setting `extends: true` in your frontmatter, the content in that file will be added to the bottom of the existing content from the original file instead of replacing it. If multiple modules extend the same page, the files are all appended in the reverse of the module order defined by the `ModuleManifest`, so content from the highest priority module is added last.

If there is no original file for the page, its extensions aren't shown.

> [!TIP]
> Other frontmatter metadata in the file will still take effect. For example if you include a title in the frontmatter for your extension page, and the extension comes after the original page, that title will be used instead of the title from the original file. This doesn't happen if the extension falls back to a locale that comes later in the [fallback order](./localising_documentation#fallback-order) than the original page, so an untranslated extension doesn't replace a translated title. To change only the title of a page, use an extension that has a title in its frontmatter and no other content.

## Replacing, removing and translating extensions

Each extension belongs to a module. With `extends: true`, that's the module that has the file. Set `extends` to the name of another module to provide that module's extension instead:

```yml
---
extends: 'other/module'
---
```

- To **override** an extension, add a file with the same path to a module that has a higher priority than the module with the extension (for example your project), and set `extends` to the name of that module. The content in your file is used instead of the content in the other module's extension. See [setting module priority](./overriding_documentation#setting-module-priority).
- To **remove** an extension, override it with a file that only has frontmatter.
- To **translate** an extension, see [localising extensions](#localising-extensions).

If no installed module has the name, the extension isn't used, because the content it extends isn't there. If the value isn't a module name at all, a warning is logged and the extension isn't used.

Extensions are ordered by the module they belong to, so an override goes in the same place as the extension it replaces.

> [!WARNING]
> `extends` must be the boolean `true` (without quotes) or the name of a module. Other values such as `"true"` (in quotes), `yes` and `on` are strings. They aren't module names, so the extension isn't used.

## Localising extensions

To localise an extension, add a file with the same path under the appropriate locale folder, and set `extends` to the module the extension belongs to. For example if `other/module` extends a page, use `extends: 'other/module'` in your translation. If the translation is in the same module as the extension, `extends: true` works too.

Each extension falls back to other locales separately from the page it extends, so a translated page still shows extensions that haven't been translated. As with pages, the module with the highest priority that has the extension in any locale in the [fallback order](./localising_documentation#fallback-order) is used, so if you translate an extension from another module, your module must have a higher priority than that module and any module that overrides the extension.
