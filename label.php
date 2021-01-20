<?php

//
// label.php version for WMF Cloud, (c) 2021 Daniel Schwen
//

require 'master.inc';

// no errors
error_reporting(0);

// get parameters
$lang=$_GET['l'];
$y=floatval($_GET['a']);
$x=floatval($_GET['b']);
$z=intval($_GET['z']);

// globe parameter (defaults to Earth)
$g = 0;
if (array_key_exists('g', $_GET)) {
  $globe = strtolower($_GET['g']);
  if (array_key_exists($globe, $globes)) {
    $g = $globes[$globe];
  }
}

// experimental range query
$r = NULL;
if (array_key_exists('r', $_GET)) {
  $r = $_GET['r'];
}

// Uncomment briefly to clear the memory cache
apc_clear_cache();

// APC - query cache
$key = md5($x.'|'.$y.'|'.$z.'|'.$lang.'|'.$g.'|'.$r);
if ($result = apc_fetch($key)) {
  echo $result;
  exit;
}

// get language id (Append new languages in the back!!)
$langvariant = explode("-", $lang, 2);

// if a variant was provided check if it is supported
if (count($langvariant) == 2) {
  $allvariant = explode(',',"zh-hans,zh-hant,zh-cn,zh-hk,zh-mo,zh-sg,zh-tw");
  $variant = $lang;
  if (array_search($variant, $allvariant) === FALSE) {
    echo "";
    exit;
  }

  // set the mediawiki path for the mediawiki-zhconverter
  define("MEDIAWIKI_PATH", "/opt/mediawiki");

  // include character set converter
  require_once "mediawiki-zhconverter/mediawiki-zhconverter.inc.php";
} else {
  $variant = "";
}

// set and validate language code
$lang = $langvariant[0];
$l = $langs[$lang];
if ($l === FALSE) {
  echo "";
  exit;
}

// select current revision
$rev = $lrev[$lang];

// zoom multiplier
$wikiminiatlas_zoomsize = array( 3.0, 6.0 ,12.0 ,24.0 ,48.0, 96.0, 192.0, 384.0, 768.0, 1536.0,  3072.0, 6144.0, 12288.0, 24576.0, 49152.0, 98304.0 );

// connect to database
$ts_pw = posix_getpwuid(posix_getuid());
$ts_mycnf = parse_ini_file($ts_pw['dir'] . "/replica.my.cnf");
$db = mysqli_connect("tools.db.svc.eqiad.wmflabs", $ts_mycnf['user'], $ts_mycnf['password'], "s51499__wikiminiatlas");
unset($ts_mycnf, $ts_pw);

// connection failed
if (!$db)
{
  echo '<!-- error: Too many database connections -->';
  exit;
}

if ($r != NULL) {
  $r = $_GET['r'];
  $co = Array('x','y');
  $q = Array();
  $n = 0;

  // decode compressed query
  $qi = explode("|",$r);
  if ((count($qi)<1) || (count($qi)>10)) 
    exit;

  foreach ($qi as $i) {
    $qp = explode(",", $i);
    if (count($qp) < 1 || count($qp) > 2)
      exit;

    $s=""; $n2=1;
    for ($j=0; $j<2; $j++) { 
      // = or >= <=
      $k=explode("-",$qp[$j]);
      if (count($k) == 1) {
        $s .= "t.".$co[$j]."=".intval($k[0]);
      } 
      else if (count($k) == 2) {
        $s .= "t.".$co[$j].">=".intval($k[0])." AND t.".$co[$j]."<=".intval($k[1]);
        $n2 *= max(0, intval($k[1]) - intval($k[0]));
      }
      else exit;

      if ($j == 0) 
        $s .= " AND ";
    }

    // add query term
    $q[] = "(".$s.")";
    $n += $n2;
  }
   
  if( $n2 > 500 ) {
    echo "Requesting too many tiles!";
    exit;
  }
  $query = "select p.page_title as title, l.name as name, l.lat as lat, l.lon as lon, l.style as style, t.x as dx, t.y as dy, l.weight as wg, l.page_id as id from  page_$lang p, wma_tile t, wma_connect c, wma_label l  WHERE l.lang_id='$l' AND l.globe='$g' AND c.rev='$rev' AND c.tile_id=t.id AND ( ".implode(" OR ",$q)." ) AND c.label_id=l.id AND t.z='$z' AND c.tile_id = t.id AND l.page_id=p.page_id";
} else {
  $query = "select p.page_title as title, l.name as name, l.lat as lat, l.lon as lon, l.style as style, t.x as dx, t.y as dy, l.weight as wg, l.page_id as id from  page_$lang p, wma_tile t, wma_connect c, wma_label l  WHERE l.lang_id='$l' AND  l.globe='$g' AND c.rev='$rev' AND c.tile_id=t.id AND t.x='$x' AND c.label_id=l.id  AND t.y='$y' AND t.z='$z' AND c.tile_id = t.id AND l.page_id=p.page_id";
}

$res = mysqli_query($db, $query);

$items = array();
while ($row = mysqli_fetch_assoc($res))
{
  $x = $row['dx'];
  $y = $row['dy'];

  $ymin = (180*$y)/$wikiminiatlas_zoomsize[$z] - 90.0;
  $ymax = $ymin + 180.0/$wikiminiatlas_zoomsize[$z];
  $xmin = (180.0*$x)/$wikiminiatlas_zoomsize[$z];

  $ty = intval( ( ( $ymax - $row["lat"] ) * $wikiminiatlas_zoomsize[$z] * 128) / 180.0 );
  $tx = intval( ( ( $row["lon"] - $xmin ) * $wikiminiatlas_zoomsize[$z] * 128) / 180.0 );
  $fy = ( ( ( $ymax - $row["lat"] ) * $wikiminiatlas_zoomsize[$z] * 128) / 180.0 ) - $ty;
  $fx = ( ( ( $row["lon"] - $xmin ) * $wikiminiatlas_zoomsize[$z] * 128) / 180.0 ) - $tx;
  $s = $row['style'];

  if ($lang=="commons") {
    $n = explode( '|', $row["name"], 4 );
    $w = $n[0];
    $h = $n[1];
    $items[] = array( 
      "style" => $s,
      "img"  => urlencode($row["title"]),
      "tx"   => $tx,
      "ty"   => $ty,
      "w" => $n[0],
      "h" => $n[1],
      "head"  => $n[2],
      "dx" => $x,
      "dy" => $y,
      "wg" => intval($row["wg"]),
      "fx" => $fx,
      "fy" => $fy,
      "m5" => substr(md5($row["title"]),0,2)
    );
  } else {
    if ($variant == "")
      $name = $row['name'];
    else
      $name = MediaWikiZhConverter::convert($row['name'], $variant);

    $items[] = array( 
      "style" => $s,
      "lang"  => $lang,
      "page"  => urlencode($row["title"]),
      "tx"    => $tx,
      "ty"    => $ty,
      "name"  => $name,
      "dx"  => $x,
      "dy"  => $y,
      "wg" => intval($row["wg"]),
      "fx"  => $fx,
      "fy"  => $fy
    );
  }
} // TODO only send fx,fy,wg for max label zoom!

// close database connection
mysqli_close($db);

//header("Content-type: application/json");
header("Cache-Control: public, max-age=3600");
$result = json_encode( array( "label" => $items, "z" => $z ) );
echo $result;
apc_add($key, $result, 24*60*60); // cache 24h
?>
