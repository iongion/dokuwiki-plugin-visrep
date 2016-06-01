<?php
/**
 * visrep-Plugin: Parses visrep-blocks
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Carl-Christian Salvesen <calle@ioslo.net>
 * @author     Andreas Gohr <andi@splitbrain.org>
 */


if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_visrep extends DokuWiki_Syntax_Plugin {

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'block';
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 200;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<visrep.*?>\n.*?\n</visrep>', $mode, 'plugin_visrep');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {
        
        $info = $this->getInfo();
        $xml = sprintf("<?xml version=\"1.0\" encoding=\"utf-8\"?>%s", $match);
        
        $dom = simplexml_load_string($xml);
        $dat = (array)$dom->attributes();
        $attrs = empty($dat['@attributes']) || !is_array($dat['@attributes']) ? array() : $dat['@attributes'];
        
        $defaults = array(
          'theme'    => NULL,  // napking | serious
          'width'     => 0,
          'height'    => 0,
          'align'     => '',
          'data-layout'    => NULL, // dot|neato|twopi|circo|fdp
          'data-engine'    => strtolower(empty($attrs['data-engine']) ? 'unknown' : $attrs['data-engine']),
          'data-version'   => $info['date'],
          'md5'       => md5($match),
        );
        $input = trim((string)$dom);
        $return = array_merge(
          $defaults,
          $attrs
        );
        
        // store input for later use
        io_saveFile($this->_cachename($return, 'txt'), $this->_data($return, $input));

        return $return;
    }
    
    function _data($attrs, $input) {
      $data = $input;
      switch ($attrs['data-engine']) {
        case 'blockdiag':
        case 'seqdiag':
        case 'actdiag':
        case 'nwdiag':
        case 'rackdiag':
        case 'packetdiag':
          $data = sprintf("%s {\n%s\n}", $attrs['data-engine'], $data);
          break;
        default:
          $data = preg_replace('~(.*)' . preg_quote('}', '~') . '~', '$1' . "\nsize=\"%s,%s\"; resolution=72;\n}", $data, 1);
          break;
      }
      return $data;
    }

    /**
     * Cache file is based on parameters that influence the result image
     */
    function _cachename($data, $ext) {
        return getcachename(join('x', array_values($data)),'.visrep.'.$ext);
    }

    /**
     * Create output
     */
    function render($format, &$R, $data) {
        if($format == 'xhtml') {
            $attrs = array();

            if(is_a($R,'renderer_plugin_dw2pdf')){
              $url = 'dw2pdf://'.$this->_imgfile($data);
            } else {
              $url = DOKU_BASE.'lib/plugins/visrep/img.php?'.buildURLparams($data);
            }
            
            foreach ($data as $k=>$v) {
              if ($k == 'md5') continue;
              if (is_null($v) || strlen($v) == 0) continue;
              if (in_array($k, array('width', 'height'))) continue;
              $attrs[] = sprintf('%s = "%s"', htmlspecialchars($k, ENT_QUOTES, 'UTF-8'), htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
            }
            
            $attrs[] = sprintf('src = "%s"', $url);
            $code = sprintf('<img %s alt="" class="dokuwiki-visrep-image"/>', implode(' ', $attrs));
            
            
            $R->doc .= $w_start . $code . $w_end;
            return true;
        } elseif($format == 'odt'){
            $src = $this->_imgfile($data);
            $R->_odtAddImage($src, $data['width'], $data['height'], $data['align']);
            return true;
        }
        return false;
    }

    /**
     * Return path to the rendered image on our local system
     */
    function _imgfile($data) {
        $cache  = $this->_cachename($data, 'png');
        // create the file if needed
        if (!file_exists($cache)) {
          $in = $this->_cachename($data, 'txt');
          $ok = $this->_run($data, $in, $cache);
          if(!$ok) return false;
          clearstatcache();
        }
        
        // resized version
        //if ($data['width']){
        //    $cache = media_resize_image($cache, 'png', $data['width'], $data['height']);
        //}
        
        // something went wrong, we're missing the file
        if(!file_exists($cache)) return false;
        return $cache;
    }

    /**
     * Run the visrep program
     */
    function _run($data,$in,$out) {
        global $conf;
        
        $loc = 'path_'.$data['data-engine'];
        $cmd = $this->getConf($loc);
        
        if ($this->_isBlockDiag($data['data-engine'])) {
          if (!empty($data['width']) && !empty($data['height'])) {
            $cmd .= sprintf(' --size=%dx%d', $data['width'], $data['height']);
          }
          // $cmd .= ' -a';
          $cmd .= ' -o '.str_ireplace('/', DIRECTORY_SEPARATOR, escapeshellarg($out));
          $cmd .= ' '.str_ireplace('/', DIRECTORY_SEPARATOR, escapeshellarg($in));
        }
        
        if ($data['data-engine'] == 'graphviz') {
          $cmd .= ' -Tpng';
          if (isset($data['layout']) && in_array($data['layout'], array('dot', 'neato', 'fdp', 'twopi', 'circo'))) {
            $cmd .= ' -K'.$data['layout'];
          }
          $cmd .= ' -o'.str_ireplace('/', DIRECTORY_SEPARATOR, escapeshellarg($out));
          $cmd .= ' '.str_ireplace('/', DIRECTORY_SEPARATOR, escapeshellarg($in));
        }
        
        exec($cmd, $output, $error);

        if ($error != 0){
            if($conf['debug']){
                dbglog(join("\n",$output),'visrep command failed: '.$cmd);
            }
            return false;
        }
        
        return true;
    }
    
    
    function _isBlockDiag($engine) {
      return in_array($engine, array('seqdiag', 'blockdiag', 'actdiag', 'nwdiag', 'rackdiag', 'packetdiag'));
    }

}



