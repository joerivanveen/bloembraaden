# Bloembraaden
_We’ve got your backend._

When you know html, css and vanilla javascript,
and you know how to realise your vision with them,
you can take advantage of Bloembraaden to build your new websites.

You can build a website **exactly** the way you want,
simply substituting content for some simple template tags.
Logic and representation are strictly separated,
which opens up fresh approaches for dynamic websites.

Your client can edit the content of their website through an easy interface,
laid over their website, that you can configure as well as change the appearance of.

## Who’s it for
Bloembraaden is aimed at webdevelopers and starting digital agencies that want to be
free to realise websites exactly the way they want.

We like **unicorns** more than uniforms...

While the code is free to setup and use, we also offer Bloembraaden as a hosted solution,
if you’d rather be creative than maintaining your server.

## What does it do

Bloembraaden is an elegant cms that serves ‘single page apps’ using templating.
It does not force anything on you, there are no ‘themes’, you must start from scratch.

It provides the backend / cms for your client and some integrations like Instagram, payment providers, Google services.

One Bloembraaden installation can serve many websites, each one as unique as the other is baffling.

# Note to self
The rest of this readme is more or less a giant **note to self**.
Until more people start using it the documentation will probably remain sketchy.
Please do not hesitate to contact me if you want to try out Bloembraaden.
I can set it up for you quickly or give you access to my own sandbox install.

## Prerequisites

Bloembraaden needs a vps to run and a connection to a postgres database on that vps or somewhere else.
On the vps you need two websites, one for Bloembraaden itself and one for the images, that will serve as a cdn replacement (currently no existing cdn is supported).
Contact me if you need any help setting up.

## Configuration
Rename `/data/config-example.json` to `/data/config.json` and fill in your own data.

### Api keys
Bloembraaden uses the following services:
- Instagram
- Browserless (to create pdf’s)
- Mailchimp transactional e-mail

You can also use Mailgun or Sendgrid, 
but that code is not maintained at the moment and does not allow attachments yet.

### Writable folders
You need to have several folders on your server writable by the web user (e.g. nginx).

The following folder needs to be **as fast as possible**.
- cache (where table info will be cached)

Make web-user-writable subfolders yourself in cache:
- cache/templates
- cache/css
- cache/js
- cache/filter

The following may be slower e.g. on a separate disk if you find that comfortable.

Writable folders for:
- uploads
- invoice
- cdnpath 

Point to those folders in the config.

## Web server
Setup a webserver. We recommend NGINX.
We use php-fpm with php 8.0 (which is the minimum version for Bloembraaden).

Set up two virtual hosts, one for Bloembraaden and one for static files.
Point to the static files in the config, both the path on the server `cdnpath` (that is the root of the virtual host) as the location on the internet `cdnroot`.
You can optimise that virtual host for static files, only images will be served by it.

It is recommended to put Bloembraaden (the bloembraaden folder) outside of the webroot.
In the webroot the following is needed:
- A symlink called ‘client’ pointing to `bloembraaden/client` (outside of webroot).
- A `robots.txt` file.
- A `favicon.ico` file.
- The `index.php` file that will serve the websites.

The index.php file only has to require bloembraaden/Web.php like so (point to the place where you have put bloembraaden obviously):

```
<?php
require __DIR__ . '/../bloembraaden/Web.php';
```

### Async operations
Many operations such as mailing and refreshing object cache are performed asynchronously. These are handled by the file `Job.php`.
Please setup a crontab for the web user (e.g. nginx) like so:
`crontab -e -u nginx`
And put in the four lines that are currently needed for Bloembraaden:
```
*/1 * * * * php-cgi /path/to/bloembraaden/Job.php interval=1 > /dev/null 2>&1
*/5 * * * * php-cgi /path/to/bloermbaaden/Job.php interval=5 > /dev/null 2>&1
4 * * * * php-cgi /path/to/bloembraaden/Job.php interval=hourly > /dev/null 2>&1
0 3 * * * php-cgi /path/to/bloembraaden/Job.php interval=daily > /dev/null 2/&1
```

### Clients’ websites
Your clients’ websites will reside under a folder `/instance` in the webroot, e.g. `/instance/example`.
Each website must contain a `script.js` and `style.css` that will be compiled by bloembraaden.

The website itself is build with simple templates that you put in the admin interface.
You also point to the `/instance/example` folder through that admin interface.

### SSL
Both static as well as the Bloembraaden site / vhost must use ssl. Bloembraaden does not work without ssl.
When you add sites in the Bloembraaden admin, currently you need to configure their ssl separately on the server.

## Database
Use a postgres database, preferably with pgbouncer. You need to create two databases: ‘main’ for the cms itself, and ‘history’ for the history (obviously).
You can name them how you want and just put the connection parameters in the config.

The connection string is optimized for pgbouncer and reuse of connections.

The ‘history’ database grows larger but is seldom used, only for ‘undo’ operations and to check for old slugs that are accessed.
Bloembraaden manages the indexes on the databases for optimum performance.
At this point there are still some queries that do not use an index, we are working on identifying them and optimizing further.

### New relic
You can install new relic on your server and Bloembraaden will use it to report errors and stuff.
You don’t have to setup anything, Bloembraaden checks for ‘extension_loaded’.

## Initial install
The first install is done on your main domain providing that domain also as a querystring to the first request.
Bloembraaden will contact the database and setup a first ‘instance’. From there on you can do everything yourself.

## Docker?
There is some benefit in setting up your webserver manually, mainly you can tweak some extra speed and security out of your specific environment.
However, to check out some new cool piece of software, it would be handy if it came as a Docker container.

Feel free to help me with that :-)
