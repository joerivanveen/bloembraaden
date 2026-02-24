<?php
declare(strict_types=1);

namespace Bloembraaden;

set_error_handler(static function($code) {
    if (0 === (error_reporting() & $code)) return true; // suppressed with @ sign (php 8+)
    return false; // let normal error handler handle it
});

//
define('CORE', __DIR__ . '/');
// Data
require CORE . 'Base.php';
require CORE . 'data/DB.php';
require CORE . 'data/Table.php';
// Logic
require CORE . 'logic/BaseLogic.php';
require CORE . 'logic/Instance.php';
require CORE . 'logic/Handler.php';
require CORE . 'logic/Session.php';
require CORE . 'logic/Shoppinglist.php';
require CORE . 'logic/Client.php';
require CORE . 'logic/Admin.php';
require CORE . 'logic/User.php';
// basic elements
require CORE . 'logic/element/Element.php';
require CORE . 'logic/element/BaseElement.php';
require CORE . 'logic/element/Page.php';
require CORE . 'logic/element/Image.php';
require CORE . 'logic/element/Embed.php';
require CORE . 'logic/element/File.php';
require CORE . 'logic/element/Comment.php';
require CORE . 'logic/element/Menu.php';
require CORE . 'logic/element/MenuItem.php';
require CORE . 'logic/element/Address.php';
require CORE . 'logic/element/AddressShop.php';
// integrations
require CORE . 'logic/integrations/PaymentServiceProviderInterface.php';
require CORE . 'logic/integrations/PaymentServiceProvider.php';
require CORE . 'logic/integrations/Mollie.php';
// search
require CORE . 'logic/search/Search.php';
// e-commerce
require CORE . 'logic/element/Brand.php';
require CORE . 'logic/element/Serie.php';
require CORE . 'logic/element/Product.php';
require CORE . 'logic/element/Variant.php';
require CORE . 'logic/element/PropertyValue.php';
require CORE . 'logic/element/Property.php';
require CORE . 'logic/element/Order.php';
// helpers
require CORE . 'help/logger/LoggerInterface.php';
require CORE . 'help/logger/SseLogger.php';
require CORE . 'help/logger/StdOutLogger.php';
require CORE . 'help/Translator.php';
require CORE . 'help/JobTransaction.php';
require CORE . 'help/Crypt.php'; // encrypt / decrypt using HASHKEY
require CORE . 'help/Date.php'; // static class with date functions
require CORE . 'help/Help.php'; // static class with helper functions
require CORE . 'help/Setup.php'; // static class with settings and variables
require CORE . 'help/Mailer.php';
require CORE . 'help/Warmup.php';
require CORE . 'help/Addresses.php';
require CORE . 'help/Resolver.php';
require CORE . 'help/Template.php';
require CORE . 'help/Parser.php'; // markdown parser
require CORE . 'help/Minifier.php'; // JShrink minifier (c) Robert Hafner <tedivm@tedivm.com>
