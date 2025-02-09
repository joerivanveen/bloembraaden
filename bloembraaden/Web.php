<?php
declare(strict_types=1);

namespace Bloembraaden;

require 'Require.php';
// startup Bloembraaden
// run install / upgrade if requested
if (true === Setup::$INSTALL) Help::install(new DB());
// start instance and set the specific constants it contains
$I = new Instance();
Setup::loadInstanceSettings($I);
// start session (including user and admin)
Help::$session = new Session($I);
$I = null;
// setup some constants
define('ADMIN', Help::$session->isAdmin());
set_error_handler(function() { /* ignore warnings */ }, E_WARNING);
// Respond
$H = new Handler();
$H->Act();
$H->View();
