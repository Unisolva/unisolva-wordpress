# Unisolva WordPress

Open-source WordPress and WooCommerce plugins by [Unisolva](https://unisolva.com/). Free, GPL-licensed, and built on real client projects.

## Plugins

| Plugin | Version | What it does | Download |
|---|---|---|---|
| [Quote Requests](plugins/quote-requests/) | 0.3.0 | Lets visitors collect WooCommerce products into a quote list and send one quote request. Catalog mode, configurable form fields and regions for any country. | [quote-requests.zip](https://github.com/Unisolva/unisolva-wordpress/releases/download/quote-requests-0.3.0/quote-requests.zip) |

Each plugin lives in its own folder under `plugins/`, with its own README, changelog and tests.

## Installing a plugin

1. Download the plugin's zip from the table above.
2. In WordPress, go to Plugins > Add New > Upload Plugin and choose the zip.
3. Activate it and follow the plugin's README.

Downloading this repository as a zip does not give an installable plugin, because it contains every plugin. Use the plugin's own zip.

## Versions and releases

Every plugin has its own version, stated in its main file and its changelog. The repository itself has no version.

A release belongs to one plugin. Its tag is the plugin's folder name followed by the plugin's version, for example `quote-requests-0.2.0`, and it carries that plugin's zip. Older versions of a plugin stay available on the Releases page under their own tags.

## Requirements

Stated in each plugin's README. Quote Requests needs WordPress 6.5 or newer, PHP 8.1 or newer and WooCommerce 8.0 or newer.

## Issues and contributions

Open an issue on this repository and name the plugin in the title. Pull requests are welcome; please include a test for the change.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright (C) 2026 Unisolva for Information Technology and App Development L.L.C.
