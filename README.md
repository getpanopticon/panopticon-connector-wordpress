# Akeeba Panopticon Connector for WordPress

Allows you to connect WordPress sites to [Akeeba Panopticon](https://github.com/getpanopticon/panopticon).

> [!IMPORTANT]
> You can only connect to WordPress sites using Akeeba Panopticon 1.2.0 and later versions.

> [!IMPORTANT]
> Developing and maintaining world-class software is neither easy nor free. The development of this software is subsidised by sales of our commercial offerings. If you like this software and would like to see it maintained in the future, please consider [purchasing a subscription](https://www.akeeba.com/subscribe.html) to one of our commercial offerings. _Thank you!_

## Requirements

* WordPress 5.0 or later
* PHP 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, or 8.3
* (Optional) Akeeba Backup Professional 7.x or later — to enable backup features
* (Optional) Admin Tools Professional 1.2.x or later — to enable security features

## Build instructions

Check out this repository and Akeeba Build Tools — Public Packager using the following directory names:

- `panopticon-connector-wordpress` This repository.
- `buildfiles` [Akeeba Build Tools — Public Packager](https://github.com/akeeba/buildfiles-public)
- `build.properties` A file created as per the instructions in `buildfiles/README.md`

Then:

```bash
cd panopticon-connector-wordpress
composer install
phing git
```

The generated package is under `panopticon-connector-wordpress/release`.
## Configuration

### Allow Remote Extension Installation

The connector includes an option to allow or disallow remote extension installation from Panopticon. This option is found in the WordPress Settings page (Settings > Panopticon).

When set to **Yes** (the default), Panopticon can remotely install plugins and themes on the site using the mass extension installation feature. When set to **No**, the site will refuse remote installation requests and report the status as "Disabled" in the installation summary.

## License

Akeeba Panopticon Connector for WordPress

Copyright (C) 2023-2025  Nikolaos Dionysopoulos / Akeeba Ltd

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more details.

You should have received a copy of the [GNU Affero General Public License](LICENSE.txt) along with this program.  If not, see <https://www.gnu.org/licenses/>.