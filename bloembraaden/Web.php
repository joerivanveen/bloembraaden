<?php
declare(strict_types = 1);

namespace Peat;

require 'Require.php';
// startup Bloembraaden
// run install / upgrade if requested
if (true === Setup::$INSTALL) Help::install(new DB());
// start instance and set the specific constants it contains
$I = new Instance();
Setup::loadInstanceSettings($I);
// start session (including user and admin)
$S = new Session($I);
$I = null;
// setup some constants
define('ADMIN', $S->isAdmin());
// Respond
//$html = '<table style="font-family:Arial,sans-serif;color:#475148;font-size:12px;width:100%;margin-bottom: 10px;"> <tbody> <tr> <td valign="top"> <h1>PETIT CLOS</h1> <p style="font-size:16px;font-family:arial;">Factuur [payment_sequential_number]</p> <p style="font-size:14px;margin-bottom:3px;">Aan: <a mailto="nonstockphoto@gmail.com">nonstockphoto@gmail.com</a> </p> <h2 style="font-size:14px; font-weight:bold;">Bezorgadres</h2> Roselaar<br/> Spaanderbank 45 <br/>  1274GC Huizen<br/> Nederland <h2 style="font-size:14px;font-weight:bold;margin-top:3px;">Factuuradres</h2> Roselaar<br/> Spaanderbank 45 <br/>  1274GC Huizen<br/>  </td> <td valign="top" align="right"> <p style="font-size:14px;font-weight:bold;font-family:arial;">Bezorgen op: <span style="white-space:nowrap">zo snel mogelijk</span></p> <h2 style="font-family:arial;font-size:16px;">Bestelnummer: <span style="white-space:nowrap">2020 8354 1254</span></h2> <h3 style="font-family:arial;font-size:14px;margin-top:8px;"> Besteldatum: 2020-10-28 16:21:12.427595+01</h3> <p style="font-family:arial;font-size:12px;margin-top: 3px;"> <b>Petit Clos</b><br/> ✉ <a mailto="info@petitclos.nl">info@petitclos.nl</a><br/> ✆ 06 1234567<br/> IBAN: NL 00 INGB 0XX XXXXXXXX<br/> BIC / SWIFT: INGBXXXX<br/> KVK: ...<br/> BTW: ... </p> </td> </tr> </tbody></table><table style="font-family:Arial,sans-serif;color:#475148;font-size:12px;width:100%;border-bottom: 1px dotted #475148;border-top: 1px dotted #475148;margin-bottom: 10px;">  <tr> <td colspan="3" style="font-weight: bold;">Whiskey van okiepokie</td> </tr> <tr> <td><s>€ 14,95</s> € 11,95</td> <td>× 1</td> <td align="right">€ 11,95</td> </tr>  <tr> <td colspan="2"> </td> <td align="right"><div style="height: 1em; border-bottom: solid 1px #475148;"> </div></td> </tr> <tr> <td colspan="2">Subtotaal</td> <td align="right">€ 11,95</td> </tr> <tr> <td colspan="2">Verzendkosten</td> <td align="right">€ 5,00</td> </tr> <tr> <td colspan="2"> </td> <td align="right"><div style="height: 1em; border-bottom: solid 1px #475148;"> </div></td> </tr> <tr> <td colspan="2"><strong>Totaal</strong></td> <td align="right"><strong>€ 16,95</strong></td> </tr></table><p style="font-family:Arial,sans-serif;color:#475148;font-size:12px;">Berekende BTW (hoog): € 2,94</p><p id="remarks">Plain tekst test 4</p>';
//echo $html;
//echo Help::html_to_text($html);
//echo PHP_EOL;
//die("klaar opa");
/*$logger = new StdOutLogger();
$p = new Parser($logger);
$text = file_get_contents(CORE . "../test.txt");
$timing = microtime(true);
$parsed = $p->parse($text);
$timing = microtime(true) - $timing;
echo htmlspecialchars($parsed);
echo '<h3 style="color:#0bb">parse time: ';
    echo $timing;
    echo '</h3>';
echo '<h3 style="color:#0f0">rendered:</h3>';
echo $parsed;
echo '<h3 style="color:#f00">trace:</h3><pre>';
$logger->out('<br/>');
die('</pre>');*/
$H = new Handler($S);
$H->Act();
$H->View();
