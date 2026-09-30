=== Imagination ===
Contributors: init0
Tags: libvips, image-editor, image-optimization, webp, avif
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make WordPress image processing very fast with libvips. It resizes and converts WebP, AVIF, HEIC and more. All local, no external services involved.

== Description ==

Imagination is a free WordPress plugin that uses libvips as a local image processing backend through the `WP_Image_Editor` API.

It supports JPEG, PNG, GIF, WebP, AVIF, HEIC, and TIFF.

All image processing is performed locally on your server. No API key, account, external service, or cloud processing is required.

Imagination is completely free for any use, including commercial use. There are no ads, tracking, or data collection.

== Requirements ==

Imagination needs three things on your server:

1. PHP 7.4 or newer.
2. The PHP FFI extension, with `ffi.enable` set to `true`.
3. libvips 8.12 or newer installed as a system library.

The FFI setting must allow FFI use by the PHP SAPI that runs WordPress. The default `ffi.enable=preload` setting restricts FFI usage and is not sufficient for Imagination.

libvips must also be available to the PHP environment used by WordPress, such as PHP-FPM. Having libvips available only to the command-line PHP environment is not sufficient.

Imagination has been tested only on Linux.

These requirements make Imagination unsuitable for most shared hosting environments, where users cannot change PHP configuration or install system libraries. Ask your hosting provider whether the required configuration is available.

If the requirements are not met, Imagination is not used. WordPress keeps using another available image editor, such as Imagick or GD. Nothing breaks.

Go to Tools > Site Health, click the Info tab, and find the Imagination section. It shows whether the requirements are met and the detected server configuration.

== Settings ==

Settings > Imagination has these options:

* Resampling kernel: Linear (default) or Lanczos3
* Big image threshold
* Metadata: strip all, keep all, or keep only the color profile
* Process all uploads on the server instead of in the browser (WordPress 7.1 and newer)
* Error log for support requests (off by default)

== Privacy ==

Imagination does not connect to any external service.

The plugin does not make remote requests and does not send images, metadata, diagnostic information, or other data to an external server.

The plugin stores its settings in the options table. The error log is off by default. When you turn it on, it keeps the last 10 image errors on your site. File paths in the messages are replaced with a placeholder such as `<file>.jpg` or `<path>`. Turning the log off deletes it.

== Included Libraries ==

The following libraries are included with Imagination. Their namespaces are prefixed where applicable to avoid conflicts with other WordPress plugins:

* [php-vips](https://github.com/libvips/php-vips) by John Cupitt, MIT License
* [PSR Log](https://github.com/php-fig/log), MIT License
* [Composer Installers](https://github.com/composer/installers), MIT License

libvips is provided by your server and is licensed under the LGPL-2.1-or-later.

== Performance ==

Imagination uses libvips for image processing. The libvips project publishes benchmarks covering image-processing performance, memory usage, and multithreading:

* [libvips Benchmarks](https://github.com/libvips/libvips/wiki/Benchmarks)
* [Speed and memory use](https://github.com/libvips/libvips/wiki/Speed-and-memory-use)

These benchmarks are performed using specific workloads and hardware and should not be treated as a performance guarantee for a particular WordPress installation.

== Source Code ==

The source code and the build steps are on [GitHub](https://github.com/kirsky/imagination).

== Installation ==

1. Make sure your server meets the requirements above.
2. Install libvips 8.12 or newer as a system library.
3. Install the PHP FFI extension and set `ffi.enable` to `true` for the PHP environment used by WordPress.
4. Install and activate Imagination through the WordPress Plugins screen.
5. Go to Tools > Site Health, click the Info tab, and find the Imagination section.

== Frequently Asked Questions ==

= Why does Imagination require FFI? =

Imagination uses [php-vips](https://github.com/libvips/php-vips) to access libvips from PHP. PHP FFI is required for this integration.

= Can I use Imagination on shared hosting? =

Yes, but only when the hosting environment provides all required components and gives you control over the required PHP configuration and system libraries.

Most shared hosting environments do not allow FFI or installation of system libraries such as libvips.

= Does Imagination use an external image optimization service? =

No.

Image processing is performed locally on your server. Imagination does not upload images or other data to an external service.

= What happens if a requirement is missing? =

Imagination is not used as the image editor when its requirements are not met. WordPress keeps using another available image editor, such as Imagick or GD. Nothing breaks.

To see what is missing, go to Tools > Site Health, click the Info tab, and find the Imagination section.

= Which formats work? =

JPEG, PNG, GIF, WebP, AVIF, HEIC/HEIF, and TIFF. AVIF and HEIC depend on how your libvips and libheif were built. AVIF output needs WordPress 6.5 or newer, because older versions do not know the AVIF file type. HEIC needs WordPress 6.7 or newer. The Imagination section on the Site Health Info tab lists the input and output formats your server supports.

= Are my existing images changed? =

No. The plugin only handles new uploads and new edits. Sizes that already exist stay as they are until you regenerate them.

= Does it work with WordPress 7.1 client-side media processing? =

WordPress 7.1 can process images in the browser. Turn on "Process all uploads on the server" in Settings > Imagination to send them through libvips instead.

= Do I need to change my theme or plugins to use Imagination? =

Imagination integrates with WordPress through the `WP_Image_Editor` API. Themes and plugins that use the standard WordPress image editor API do not need to be rewritten to use Imagination.

= Is Imagination free? =

Yes. Imagination is completely free for any use, including commercial use.

There are no paid features, advertisements, tracking, or data collection.

= Can I use Imagination on Windows or macOS? =

Imagination has been tested only on Linux.

== Changelog ==

= 1.0.0 =
* First release.
