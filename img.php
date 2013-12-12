<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Ionut Stoica <stoica.ionut@gmail.com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',dirname(__FILE__).'/../../../');
define('NOSESSION',true);
require_once(DOKU_INC.'inc/init.php');

// let the syntax plugin do the work
$plugin = plugin_load('syntax','visrep');
$cache  = $plugin->_imgfile($_REQUEST);

if($cache) {
  header('Content-Type: image/png;');
  header('Expires: '.gmdate("D, d M Y H:i:s", time()+max($conf['cachetime'], 3600)).' GMT');
  header('Cache-Control: public, proxy-revalidate, no-transform, max-age='.max($conf['cachetime'], 3600));
  header('Pragma: public');
  http_conditionalRequest($time);
  echo io_readFile($cache, false);
} else {
  header("HTTP/1.0 404 Not Found");
  header('Content-Type: image/png');
  echo io_readFile('broken.png',false);
  exit;
}
