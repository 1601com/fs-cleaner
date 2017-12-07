<?php
/*******************************************************************************
 ** File-System Cleaner v.1.0
 ** (c) 2015-2017 1601.communication
 *******************************************************************************/

header("Content-Type: text/html; charset=utf-8");

/* Funktionsweise:
Die Datei wird per cronjob oder manuell aufgerufen, geht alle Dateien im festgelegten Verzeichnis durch
und löscht die aufgelisteten Dateien nach einer Bestätigungsanfrage.
Sodann wird eine Mail an den konfigurierten Empfänger gesendet.
Die Bestätigungsabfrage kann (z.B. im Cronjob) übersprungen werden, wenn der Dateiaufrufper fs-cleaner.php?confirm=1
durchgeführt wird.
*/


//$_GET['confirm'] = 1; // hiermit wird das Löschen immer ohne Nachfrage ausgeführt

/* Cachen verhindern */
// Datum aus Vergangenheit
header("Expires: Mon, 12 Jul 1995 05:00:00 GMT");
// Immer geändert
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
// Speziell für MSIE 5
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


error_reporting(0); // temporär: Fehler anzeigen

$config = array();


$config['root'] = __DIR__."/data/"; // Root-Verzeichnis mit (!) Trailing Slash (standardmäßig: Arbeitsverzeichnis dieses Skripts)

// Die zu überspringenden Dateien-Ordner richten sich nach der preg_match-Syntax
// Zeichen wie \ + * ? [ ^ ] $ ( ) { } = ! < > | : - %, die Teil des Dateinamens sind, müssen hier escaped werden.
$config['skip'] = array();
$config['skip'][] = __DIR__."/".preg_replace("%\-%","\\-",basename(__FILE__)."$"); // Die Datei selbst wird ausgenommen
$config['skip'][] = "^/skip/"; // der Order "/skip" im Root (und alle Inhalte) wird übersprungen
$config['skip'][] = "/skip/"; // der Order "/skip" (kann auf jeder Dateiebene auftreten) (und alle Inhalte) wird übersprungen
//$config['skip'][] = "^/tmp/";
//$config['skip'][] = "^/cache/";
$config['skip'][] = "^/plugins/";
$config['skip'][] = "^/public/";
//$config['skip'][] = "^/personal/";
$config['skip'][] = "/\.([^/]+)$"; // alle Dateien beginnend mit Punkt werden übersprungen
$config['skip'][] = "/\_([^/]+)$"; // alle Dateien beginnend mit Unterstrich werden übersprungen

$config['skipFolder'] = TRUE; // TRUE: Lösche nur Dateien, keine Ordner
$config['deleteAfter'] = 90; // alte Dateien nach 90 Tagen löschen

$config['title'] = 'fs-Cleaner &ndash; 1601.communication';

$config['mail']['send'] = TRUE; // Bestätigungsmail senden?
$config['mail']['from'] = "fs-Cleaner Daemon <noreply@1601.com>"; // Mail-Adresse für die Bestätigungsmail
$config['mail']['to'] = ""; // Mail-Adresse für die Bestätigungsmail
$config['mail']['cc'] = "";
$config['mail']['bcc'] = "";
$config['mail']['subject'] = "Automatischer Cleanup des Dateisystems"; // Mail-Adresse für die Bestätigungsmail
$config['mail']['textWithAttachment'] = "Der automatische FileSystem-Clean nach ".$config['deleteAfter']." Tagen wurde durchgeführt und es wurden {{num}} Dateien gelöscht.\nIm Anhang befindet sich eine Liste der Dateien, die gelöscht wurden\nFalls fälschlicherweise eine Datei gelöscht wurde, wenden Sie sich bitte umgehend an den Support unter support@1601.com";
$config['mail']['text'] = "Der automatische FileSystem-Clean nach ".$config['deleteAfter']." Tagen wurde durchgeführt und es wurden {{num}} Dateien gelöscht.\nFalls fälschlicherweise eine Datei gelöscht wurde, wenden Sie sich bitte umgehend an den Support unter support@1601.com";
$config['mail']['attachment'] = "deletedFiles.xml"; // Name des Anhangs. Leer lassen, wenn kein Anhang gesendet werden soll
$config['mail']['attachmentType'] = "xml"; // Type des Anhangs: txt|xml|html




// Alle Dateien und Ordner innerhalb von $root rekursiv auslesen
function scandir_recursive($root)
{
  // wenn $root einen Trailing-Slash hat, nimm ihn weg
  $root = preg_replace("%\/+$%","",$root);

  $scandir = array_diff(scandir($root),array('..', '.')); // Lies alle Dateien dieses Ordners aus (außer .. und .)
  $list = array();
  foreach ($scandir as $file) // geh die Dateien dieses Ordners durch
  {
    $file = $root."/".$file; // gib der Datei einen absoluten Pfad!

    if (is_dir($file)) // wenn es sich um einen Ordner handelt
    {
      $list = array_merge(scandir_recursive($file),$list); // gehe auch diesen durch und füge ihn zu files hinzu
      $list[] = $file."/"; // füge den aktuellen Ordner mit Trailing Slash hinzu!
    }
    else $list[] = $file; // die aktuelle Datei ist kein Ordner: Füge sie ohne Trailing-Slash hinzu!
  }
  return $list;
}




// gehe die Dateiliste durch und überspringe Dateien/Ordner, die ausgeschlossen werden
function skipfiles($list,$config)
{
  $skip = array(); // die Dateien/Ordner, die übersprungen werden
  $include = array(); // die Dateien/Ordner, die eingebunden werden
  $skipFolder = array(); // die Ordner, die implizit ausgeschlossen werden (weil sich zu behaltene Dateien darin befinden)
  $root = preg_replace("%\/+$%","",$config['root']);
  rsort($list); // wir müssen die Dateien/Ordner verkehrt herum sortieren, damit wir Ordner mit übersprungenen Dateien nicht versuchen zu löschen

  foreach ($list as $file)
  {
    $skipFile = 0;
    if (is_dir($file)) // es handelt sich um einen Ordner
    {
      if ($config['skipFolder'] == TRUE) $skipFile = 1; // überspring die Ordner
      elseif (in_array($file,$skipFolder)) $skipFile = 1; // überspring die Ordner nur, wenn eine zu behaltene Datei darin ist
    }

    if ($skipFile == 0) // überspringe anhand Config
    {
      foreach ($config['skip'] as $pattern)
      {
        if (mb_substr($pattern,0,2) == "^/") $pattern = "^".$root.mb_substr($pattern,1); // absoluter Pfad: setze root davor!

        if (preg_match("%".$pattern."%",$file)) // diese Datei wird übersprungen
        {
          $skipFile = 1;
          break;
        }
      }
    }
    if ($skipFile == 0 && $config['deleteAfter'] > 0) // überspringe anhand Timestamp
    {
      if (filemtime($file) > time()-$config['deleteAfter']*24*60*60)
      {
        $skipFile = 1;
        //echo "datei $file ist neuer: ".date("d.m.Y",filemtime($file))."<br>";
      }
    }

    // Nachbehandlung: Wenn Datei übersprungen wird
    if ($skipFile == 1)
    {
      $skip[] = $file;

      // Datei wird übersprungen: Auch alle Ordnerebenen oberhalb werden übersprungen!
      if ($config['skipFolder'] == FALSE)
      {
        $path = explode("/",$file);
        array_pop($path); // das letzte Element ist entweder leer oder eine Datei: ignorieren
        $pathstring = "/";
        foreach ($path as $folder)
        {
          if (!empty($folder))
          {
            if (!in_array($pathstring.$folder."/",$skipFolder) && is_dir($pathstring.$folder)) $skipFolder[] = $pathstring.$folder."/";
            $pathstring .= $folder."/";
          }
        }
      }

    }
    else $include[] = $file;
  }

  return array($list,$include,$skip);
}



// definiere die Exportformate
function exportToFormat($list,$format,$prettyPrint=1)
{
  if ($format == "xml")
  {
    $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><root></root>");
    foreach ($list as $file)
    {
      if (mb_substr($file,-1) == "/") $element = $xml->addChild("folder",$file); // ein Ordner
      else
      {
        $element = $xml->addChild("file",$file);
        $attribute = $element->addAttribute("size",filesize($file)." B");
      }
      $attribute = $element->addAttribute("modified",date(DATE_ATOM,filemtime($file)));
    }
    if ($prettyPrint == 1) return preg_replace("%>%",">\n",$xml->asXML()); // Zeilenweise
    else return $xml->asXML();
  }
  elseif ($format == "html")
  {
    $html = "<ul>";
    foreach ($list as $file)
    {
      $html .= "<li>".$file;
      if (is_dir($file)) $html .= "\t(".date(DATE_ATOM,filemtime($file)).")";
      else $html .= "\t<span class=\"meta\">(".filesize($file)." B; ".date(DATE_ATOM,filemtime($file)).")</span>";
      $html .= "</li>";
    }
    $html .= "</ul>";
    return $html; // Zeilenweise
  }
  else
  {
    $txt = "";
    foreach ($list as $file)
    {
      $txt .= $file;
      if (is_dir($file)) $txt .= "\t(".date(DATE_ATOM,filemtime($file)).")";
      else $txt .= "\t(".filesize($file)." B; ".date(DATE_ATOM,filemtime($file)).")";
      $txt .= "\n";

    }
    return $txt; // Zeilenweise
  }
}


// lösche die Dateien/Ordner
function deletefiles($list=array())
{
  $n = 0;
  foreach ($list as $file)
  {
    if (is_dir($file)) rmdir($file);
    else unlink($file);
    $n++;
  }
  return $n;
}

// sende eine Bestätigungsmail
function confirmationMail($mail,$list=array())
{
  if (count($list) == 0)
  {
    // keine Dateien wurden gelöscht, versende keine Mail
  }
  else
  {
    $boundary = md5(uniqid(time()));
    $headers = array();
    $headers[] = "From: ".$mail['from'];
    if (!empty($mail['cc'])) $headers[] = "Cc: ".$mail['cc'];
    if (!empty($mail['bcc'])) $headers[] = "Bcc: ".$mail['bcc'];
    $mail['text'] = preg_replace("%\{\{num\}\}%",count($list),$mail['text']);

    if (!empty($mail['attachment']) && is_array($list) && count($list) > 0) // Sende Anhang, wenn Anhang vorhanden
    {
      $headers[] = "MIME-Version: 1.0";
      $headers[] = "Content-Type: multipart/mixed; boundary=$boundary";

      $newtext = array();
      $newtext[] = "This is a multi-part message in MIME format";
      $newtext[] = "--$boundary";
      $newtext[] = "Content-Type: text/plain; charset=UTF-8";
      $newtext[] = "Content-Transfer-Encoding: 8bit\n";
      $newtext[] = preg_replace("%\{\{num\}\}%",count($list),$mail['textWithAttachment']);
      $newtext[] = "--$boundary";

      if ($mail['attachmentType'] == "xml")
      {
        $newtext[] = "Content-Type: text/xml; charset=UTF-8; name=".$mail['attachment'];
        $newtext[] = "Content-Transfer-Encoding: base64";
        $newtext[] = "Content-Disposition: attachment; filename=".$mail['attachment']."\n";
        $newtext[] = chunk_split(base64_encode(exportToFormat($list,"xml")));
      }
      elseif ($mail['attachmentType'] == "html")
      {
        $newtext[] = "Content-Type: text/html; charset=UTF-8; name=".$mail['attachment'];
        $newtext[] = "Content-Transfer-Encoding: base64";
        $newtext[] = "Content-Disposition: attachment; filename=".$mail['attachment']."\n";
        $newtext[] = chunk_split(base64_encode(exportToFormat($list,"html")));
      }
      else
      {
        $newtext[] = "Content-Type: text/plain; charset=UTF-8; name=".$mail['attachment'];
        $newtext[] = "Content-Transfer-Encoding: base64";
        $newtext[] = "Content-Disposition: attachment; filename=".$mail['attachment']."\n";
        $newtext[] = chunk_split(base64_encode(exportToFormat($list,"txt")));
      }

      $newtext[] = "--$boundary--";
      $mail['text'] = implode("\n",$newtext);
    }

    mail($mail['to'],$mail['subject'],$mail['text'],implode("\n", $headers));
  }
}




/* Ausführung */
$list = scandir_recursive(($config['root'])); // gehe alle Dateien durch
list($list,$delete,$skip) = skipfiles($list,$config); // überspringe Dateien abhängig von der Config

// HTML-Fallunterscheidung: Bestätigungsabfrage, es sei denn wir rufen mit "confirm=1" auf
if (isset($_GET['confirm']) AND $_GET['confirm'] == 1)
{
  if (count($delete) == 0) $return = "<h1>Es sind keine Dateien zum Löschen vorhanden</h1>";
  else
  {
    $return = "<h1> Folgende ".count($delete)." Dateien wurden gelöscht:</h1>".exportToFormat($delete,"html");
    confirmationMail($config['mail'],$delete); // sende eine Mail
    deletefiles($delete); // lösche die Dateien & Ordner
  }
}
else
{
  $return = '<a href="?confirm=1">Diese unten aufgelisteten Dateien bitte hiermit löschen!</a>'.
      "<h1>Folgende ".count($delete)." Dateien werden gelöscht:</h1><br>".((count($delete) == 0)?'Es sind keine Dateien zum Löschen vorhanden':'<div class="delete">'.exportToFormat($delete,"html"))."</div>".
      "<br><br><h1>Folgende ".count($skip)." Dateien bleiben erhalten:</h1></br><div class='keep'>".exportToFormat($skip,"html")."</div>";
}


?>
<!DOCTYPE html>
<html>
<head>
  <title><?php echo $config['title']; ?></title>
  <meta charset="UTF-8" />
  <style type="text/css">
    body, html
    {
      font-family:Verdana, sans-serif;
      font-size:0.8em;
    }
    .delete
    {
      color:#a00;
      font-size:0.7rem;
    }
    .keep
    {
      color:#888;
      font-size:0.7rem;
    }
    .meta
    {
      font-style:italic;
    }
  </style>
</head>
<body>
<?php
echo $return;
?>
</body>
</html>