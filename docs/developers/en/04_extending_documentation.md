---
title: Extending documentation
summary: Add content to documentation from other modules without replacing it
---

# Extending documentation

## Extending a page of documentation {#extending-pages}

Sometimes you might want to just add additional information to an existing page of documentation without replacing the page completely.

You can do this using [frontmatter](./writing_documentation#frontmatter-metadata), with the `extends` key. By setting `extends: true` in your frontmatter, the content in that file will be added to the bottom of the existing content from the original file instead of replacing it. If multiple modules extend the same page, the files are all appended in the reverse of the module order defined by the `ModuleManifest`, so content from the highest priority module is added last.

If there is no original file for the page, the first extension is used as the page. If that extension has no content, the page isn't shown.

> [!TIP]
> Other frontmatter metadata in the file will still take effect. For example if you include a title in the frontmatter for your extension page, and the extension comes after the original page, that title will be used instead of the title from the original file. This doesn't happen if the extension falls back to a locale that comes later in the [fallback order](./localising_documentation#fallback-order) than the original page, so an untranslated extension doesn't replace a translated title. To change only the title of a page, use an extension that has a title in its frontmatter and no other content.

## Adding content partway through a page {#extension-points}

By default, extension content is added to the end of the page. You can also add extension content around headings in the original page, or in specifically specified extension points.

### Adding content near a heading {#heading-inserts}

To add content before or after a heading, or at the end of a section, put an HTML comment on its own line in your extension like below.

Name the heading by its ID with `#` in front, the same as in a link to the heading:

| Comment | Where the content goes |
| --- | --- |
| `<!-- userdocs-insert-before: #configuration -->` | Before the heading |
| `<!-- userdocs-insert-after: #configuration -->` | Directly after the heading, before the rest of its section |
| `<!-- userdocs-insert-end: #configuration -->` | At the end of the section, after its subsections and before the next heading of the same or a higher level |

For example, with this original page:

```text
## Configuration

Some information about configuration.

### Advanced configuration

Some advanced information.

## Next steps
```

this extension adds content in three places:

```text
---
extends: true
---

<!-- userdocs-insert-after: #configuration -->

This content is added before "Some information about configuration."

<!-- userdocs-insert-end: #configuration -->

This content is added after "Some advanced information."

<!-- userdocs-insert-before: #next-steps -->

This content is added after the content for the end of the "Configuration" section.
```

The ID of a heading is its [custom ID](./writing_documentation#supported-markdown-syntax) if it has one. If it doesn't, the ID comes from the heading text, so it changes if the text changes. It is also different in each translation of the page, unless the translation [keeps the IDs](./localising_documentation). Note that the ID will have a prefix when rendered - this prefix must not be included in your insert comment.

### Adding content at an extension point

The original page can declare named extension points. Add an extension point with an HTML comment on its own line:

```text
## Configuration

Some information about configuration.

<!-- userdocs-extension-point: configuration -->

## Next steps
```

In your extension, put the content for an extension point after a `userdocs-insert-at` comment that names it:

```text
---
extends: true
---

This content is added to the end of the page.

<!-- userdocs-insert-at: configuration -->

This content is added at the "configuration" extension point.
```

- Extension point names can only have lowercase letters, numbers, `-` and `_`. Don't translate them, because each translation of a page must use the same names. If a `userdocs-insert-at` comment has a name that isn't valid, a warning is logged and the content after it isn't shown.
- Only the original page can declare extension points. Extension points in an extension are ignored, and a warning is logged.

### Rules for adding content partway through a page

- Content before the first `userdocs-insert-*` comment is added to the end of the page. It comes after content for the end of the last section.
- Only headings and extension points in the original page can be used. Headings in extensions can't be used.
- If the page has no heading or extension point with the name, that content isn't shown. This happens if the original page is replaced by a page without it, but can also happen if you mistype the name.
- If several modules add content to the same place, the content is added in the module priority order.

> [!WARNING]
> An extension point or heading can be inside a list or a block quote, but we don't recommend using it. The content from extensions becomes part of that list item or block quote, which might not look the way you expect.
>
> The `userdocs-insert-*` comment in the extension must not be inside a list or a block quote. If it is, a warning is logged, and that list or block quote and the content after it up to the next `userdocs-insert-*` comment aren't shown.

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
> `extends` must be the boolean `true` or the name of a module. YAML reads `true`, `yes` and `on` as booleans, but `"true"` (in quotes) is a string. It isn't a module name, so the extension isn't used.

## Localising extensions

To localise an extension, add a file with the same path under the appropriate locale folder, and set `extends` to the module the extension belongs to. For example if `other/module` extends a page, use `extends: 'other/module'` in your translation. If the translation is in the same module as the extension, `extends: true` works too.

Each extension falls back to other locales separately from the page it extends, so a translated page still shows extensions that haven't been translated. As with pages, the module with the highest priority that has the extension in any locale in the [fallback order](./localising_documentation#fallback-order) is used, so if you translate an extension from another module, your module must have a higher priority than that module and any module that overrides the extension.

> [!NOTE]
> The [search](./search) index for each locale only has content in that locale. If a translated page shows an extension that hasn't been translated, the content of that extension isn't found when searching in the language of the page. Translate the extension to make its content searchable.
