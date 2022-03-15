# Bloembraaden
‘We’ve got your backend’

Bloembraaden is a simple cms, you can build ‘single page apps’ using plain html, javascript and css, taking advantage of the Bloembraaden backend and frontend functionality.
It is not very elaborate. Focus is on speed and flexibility (of design and functionality).

It needs a vps to run and a connection to a postgres database on that vps or somewhere else.
On the vps you need two websites, one for Bloembraaden itself and one for the images, that will serve as a cdn replacement (currently no real cdn is supported).
Contact me if you need any help setting up, I will document this later.

## Prerequisites
Rename `/data/config-example.json` to `/data/config.json` and fill in your own data.

### Api keys
Bloembraaden uses the following services:
- Instagram
- Browserless (to create pdf’s)
- Mailchimp transactional e-mail

You can also use Mailgun or Sendgrid, but that code is not maintained at the moment and does not allow attachments.

### Writable folders
You need to have several folders on your server writable by the web user (typically www-data).
- cache (where table info will be cached)

Make subfolders yourself in cache:
- cache/templates
- cache/css
- cache/js
- cache/filter

Seperate cache folders for
- uploads
- invoice

Point to those folders in the config.

### Web server
Setup a webserver. We recomment NGINX.
We use php-fpm with php 8.0 (which is the minimum version).

Set up two virtual hosts, one for Bloembraaden and one for static files.
Point to the static files in the config, both the path on the server (that is the root of the virtual host) as the location on the internet.
You can optimise that virtual host for static files, only images will be served by it.

It is recommended to put Bloembraaden (the bloembraaden folder) outside of the webroot.
In the webroot the following is needed:
- A symlink called ‘client’ pointing to bloembraaden/client (outside of webroot).
- A `robots.txt` file.
- A `favicon.ico` file.
- The famous index.php file that will server the websites.

The index.php file only has to require bloembraaden/Web.php like so (point to the place where you have put bloembraaden obviously):

```
<?php
require __DIR__ . '/../bloembraaden/Web.php';
```

Your clients’ websites will reside under a folder `/instance` in the webroot, e.g. `/instance/example`.
Each website must contain a `script.js` and `style.css` that will be compiled by bloembraaden.

The website itself is build with simple templates, that you put in the admin interface.
You also point to the `/instance/example` folder through that admin interface.

### Database
Use a postgres database, preferably with pgbouncer. You need to create two databases: ‘main’ for the cms itself, and ‘history’ for the history (obviously).
You can name them how you want and just put the connection parameters in the config.
The connection string is optimized for pgbouncer and reuse of connections.

### New relic
You can install new relic on your server and bloembraaden will use it to report errors and stuff.
You don’t have to setup anything, Bloembraaden checks for ‘extension_loaded’.

## Initial install
The first install is done on your main domain providing that domain also as a querystring to the first request.
Bloembraaden will contact the database and setup a first ‘instance’. From there on you can do everything yourself.


