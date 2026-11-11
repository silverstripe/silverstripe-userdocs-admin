---
title: Overriding documentation
summary: Merge, replace or unregister documentation from other modules
---

# Overriding documentation

## Overriding and merging documentation

Documentation is merged so that when multiple modules have the same folder structure, the documentation shares the same navigation tree. Any collisions where two modules have the same documentation file will be resolved by the module with the highest priority (as defined in the module manifest in the framework itself) taking precedence, even if a module with a lower priority has a translation of that file. The project itself is always the highest priority module. See [setting module priority](#setting-module-priority) and [overrides and translations](./localising_documentation#overrides-and-translations) for details.

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

## Setting module priority

The project always has the highest priority. Other modules have a lower priority than the project, and their order relative to each other is not guaranteed unless a module declares it.

If your module overrides or translates documentation from another module, you can declare that your module has a higher priority than that module. For example, this configuration in your module gives it a higher priority than `silverstripe/cms`:

```yml
# _config/modules.yml
---
Name: modules-mymodule
After: '#modules-other'
Before: '#modules-cms'
---
SilverStripe\Core\Manifest\ModuleManifest:
  module_priority:
    - myvendor/mymodule
```

> [!NOTE]
> `modules-other` is a fragment in `silverstripe/framework`. It adds `$other_modules` to the priority list. `$other_modules` stands for all modules that aren't named in `module_priority`.

The `Before` value must be the name of the configuration fragment that sets the priority of the other module. To find it, look in the other module's `_config/` directory for the fragment that sets `SilverStripe\Core\Manifest\ModuleManifest.module_priority`. For example, `silverstripe/cms` sets its priority in a fragment with `Name: modules-cms`.

If the other module doesn't set its priority, you can use `Before: '#modules-other'` instead, and leave out `After`. This gives your module a higher priority than all modules that don't set their priority.

> [!WARNING]
> If your module has a lower priority than the module it overrides or translates, your documentation is not shown for any page that the other module has in English. See [overrides and translations](./localising_documentation#overrides-and-translations).

## Adding new tree nodes

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

## Unregistering documentation

If you want to unregister documentation for a given module, set its value in the [`UserDocsManifest.documentation_roots`](api:SilverStripe\UserDocs\Admin\UserDocsManifest->documentation_roots) configuration array to `false`. This is useful for example if overriding a lot of functionality that a module provides, to the extent that the majority of its documentation is no longer valid for your project.

```yml
SilverStripe\UserDocs\Admin\UserDocsManifest:
  documentation_roots:
    'my/module:docs/userhelp': false
```
