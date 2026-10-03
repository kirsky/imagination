# Indigit Imagination

Make WordPress image processing very fast with [libvips](https://www.libvips.org/). It resizes and converts WebP, AVIF, HEIC and more. All local, no external services involved.

This page is for people who work with the source code. The description, the FAQ and the changelog for site owners are in [readme.txt](readme.txt).

## Requirements

- PHP 7.4 or newer ([install guide](https://www.php.net/manual/en/install.php)).
- The [PHP FFI extension](https://www.php.net/manual/en/book.ffi.php), with [`ffi.enable`](https://www.php.net/manual/en/ffi.configuration.php) set to `true` for the PHP that runs WordPress. The default value `preload` is not enough.
- libvips 8.12 or newer, installed as a system library ([install guide](https://www.libvips.org/install.html)). PHP-FPM must be able to load it, not only the command line PHP.
- [Composer](https://getcomposer.org/download/) to install the PHP libraries.
- `curl` and `sha256sum`, which the Composer scripts use to fetch and check Strauss (see below).

Indigit Imagination is tested on Linux only.

## Install from source

1. Clone this repository into `wp-content/plugins/indigit-imagination`.
2. Run this in the plugin folder:

   ```bash
   composer install --no-dev -o
   ```

3. Activate Indigit Imagination in WordPress.

The plugin needs the `vendor/` folder that Composer creates. Without it, wp-admin shows an error notice and the plugin does nothing.

## The bundled libraries

Indigit Imagination uses [php-vips](https://github.com/libvips/php-vips) to call libvips from PHP. It also uses [PSR Log](https://github.com/php-fig/log), which php-vips needs. Both are MIT licensed.

Other plugins can ship their own copy of php-vips. To avoid clashes, this plugin renames the namespaces of both libraries with [Strauss](https://github.com/BrianHenryIE/strauss). The result is committed in `vendor-prefixed/`. The code there is third party code with a new namespace prefix (`Indigit\Imagination\Vendor\`). It is not edited by hand.

`composer install` and `composer update` run Strauss for you:

1. Composer downloads `bin/strauss.phar` (version [0.30.0](https://github.com/BrianHenryIE/strauss/releases/tag/0.30.0)) and checks its SHA-256 sum.
2. Strauss copies php-vips and PSR Log into `vendor-prefixed/` and renames their namespaces.
3. Composer rebuilds the autoloader.

On a clean checkout this leaves `vendor-prefixed/` exactly as it is committed. `git status` shows no change.

[composer/installers](https://github.com/composer/installers) is also required. It installs Composer packages of type `wordpress-plugin`, like this one, into `wp-content/plugins`.

## Development

Install the development tools as well:

```bash
composer install
```

Then use these commands:

| Command | What it does |
| --- | --- |
| `composer lint:php` | Checks the code style with [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) |
| `composer lint:php:fix` | Fixes what it can fix automatically |
| `composer phpstan` | Runs [PHPStan](https://phpstan.org/) at level 6 |

The tools and rule sets, all installed by Composer:

- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards), including its `WordPress-Extra` rules. Indentation is tabs.
- [Slevomat Coding Standard](https://github.com/slevomat/coding-standard).
- [phpstan-wordpress](https://github.com/szepeviktor/phpstan-wordpress), the WordPress extension for PHPStan.
- [PHP_CodeSniffer Standards Composer Installer Plugin](https://github.com/Dealerdirect/phpcodesniffer-composer-installer), which registers the rule sets with PHP_CodeSniffer.

The settings are in [phpcs.xml](phpcs.xml) and [phpstan.neon](phpstan.neon). Neither tool checks `vendor/` or `vendor-prefixed/`.

## License

Indigit Imagination is free software under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). The full text is in [LICENSE](LICENSE).
