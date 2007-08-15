<?php

class LAMPBuilder
{
  var $APPNAME = '';
  var $MODULES = array();
  var $MODULES_LIST = array();

  var $env_path = null;
  var $src_path = null;
  var $dst_path = null;
  var $php_path = null;

  function LAMPBuilder($env_path = null, $src_path = null, $dst_path = null)
  {
    $this->env_path = ($env_path == null) ? dirname(__FILE__).'/../src.env.ksh' : $env_path;
    $this->src_path = ($src_path == null) ? realpath(dirname(__FILE__)) : $src_path;
    $this->dst_path = $dst_path;
    $this->php_path = getenv('PHP');
    if ($this->php_path === false)  $this->php_path = '/usr/bin/php'; 
  }
  
  function run()
  {
    echo "--- ETAPE 1 : Choisissez un nom d'application\n";
    $this->choose_appli_name();
    echo "Le nom d'application suivant sera utilise : ".$this->APPNAME."\n";
    echo "--- ETAPE 2 : Choisissez les modules a activer\n";
    $this->build_module_list();
    $this->choose_modules();
    if (count($this->MODULES) > 1)
      echo "Les modules suivants seront utilises : ".implode(',',$this->MODULES)."\n";
    else
      echo "Le module suivant sera utilise : ".implode(',',$this->MODULES)."\n";
    
    echo "--- ETAPE 3 : Charge les variables d'environnement\n";
    $env = array();
    $this->load_env($env);
    $this->check_env($env);
    $this->write_env($env);
    
    // get the APPLI_HOME from the env
    if ($this->dst_path == null)
    {
      $dst = $env[$this->APPNAME.'_HOME'];
      if (!file_exists($dst)) @mkdir_r($dst);
      if (file_exists($dst))
        $this->dst_path = $dst;
      else
        die("$dst doesn't exist");
    }
    
    echo "--- ETAPE 4 : Ecrit l'instance des templates\n";
    $this->write_tpl_instance();

    echo "********************************************************************************************\n";
    echo "* Votre application ".$this->APPNAME." est prette.\n";
    echo "* Elle dispose des modules suivants : ".implode(',',$this->MODULES)."\n";
    echo "* Les parametres ont ete sauvegardes dans : ".realpath($this->env_path)."\n";
    echo "* Vous pouvez a tout moment modifier un parametre.\n";
    echo "* Pensez alors a relancer le build.ksh pour regenerer les fichiers de conf et les lanceurs\n";
    echo "********************************************************************************************\n";
    
  }

  function build_module_list()
  {
    $this->MODULES_LIST = array();
    $dir = dirname(__FILE__);
    $d = opendir($dir);
    while ($file = readdir($d))
    {
      if ($file == '.' || $file == '..' || $file == 'CVS' || $file == '.svn' || $file == 'core')
        continue;
      else if (is_dir($dir.'/'.$file))
        $this->MODULES_LIST[] = $file;
    }
    closedir($d);
  }
  
  function choose_modules()
  {
    // on recherche MODULES dans l'environement
    // si on le trouve pas alors on pose la question
    if (preg_match('/'.$this->APPNAME.'_MODULES=(.+)/i', getenv($this->APPNAME.'_MODULES'), $res))
    {
      $this->MODULES = $this->_filter_valide_modules(explode(',',$res[1]));
    }
    else if (file_exists($this->env_path))
    {
      $data = file_get_contents($this->env_path);
      if (preg_match('/'.$this->APPNAME.'_MODULES=(.+)/i',$data,$res))
        $this->MODULES = $this->_filter_valide_modules(explode(',',$res[1]));
    }
    
    // si rien n'a ete trouve alors on demande a l'utilisateur d'entrer des modules au clavier
    while (count($this->MODULES) == 0)
    {
      echo "Entrez le nom des modules separes par des virgules que vous voulez activer dans votre application\nparmis les modules suivants ".implode(',',$this->MODULES_LIST)." : ";
      $this->MODULES = $this->_filter_valide_modules(explode(',',readline()));
    }
    
    // ajoute le module core dont tous les autres dependent en tout premier de la liste
    $this->MODULES = array_merge(array('core'), $this->MODULES);
  }

  function _filter_valide_modules($modules_to_check)
  {
    // filtre les modules suivant la liste des modules disponibles
    $mod_ok = array();
    foreach($modules_to_check as $m)
      if (in_array($m, $this->MODULES_LIST))
        $mod_ok[] = $m;
    return $mod_ok;
  }
  
  function choose_appli_name()
  {
    // on recherche APPNAME dans l'environement
    // si on le trouve pas alors on cherche dans le fichier src.env.ksh
    if (preg_match('/APPNAME=([a-z]+)/i', getenv('APPNAME'), $res))
    {
      $this->APPNAME = $res[1];
    }
    else if (file_exists($this->env_path))
    {
      $data = file_get_contents($this->env_path);
      if (preg_match('/APPNAME=([a-z]+)/i',$data,$res))
        $this->APPNAME = $res[1];
    }

    // rien n'a ete trouve alors on demande a l'utilisateur de l'entrer au clavier
    if ($this->APPNAME == '')
    {
      do {
        echo "Entrez le nom de votre application (en lettres majuscules): ";
        $this->APPNAME = readline();
      } while (!preg_match('/[A-Z]+/',$this->APPNAME));
    }
  }


  /**
   * Charge dans le tableau passé en parametre toutes les variables d'env trouvées
   * soit dans l'environement courant, soit dans le fichier src.env.ksh
   */
  function load_env(&$env)
  {
    $env['APPNAME'] = $this->APPNAME;
    $env[$this->APPNAME.'_MODULES'] = implode(',',$this->MODULES);

    // construit une liste des variables d'env a tester
    $env_to_check = $this->_build_env_to_check();
    
    $data = file_exists($this->env_path) ? file_get_contents($this->env_path) : '';
    foreach($env_to_check as $e => $e_option)
    {
      $e = $this->APPNAME.'_'.$e;
      if (getenv($e) === FALSE)
      {
        if (preg_match('/export\s+'.$e.'=(.*)/',$data,$res))
        {
          $env[$e] = trim($res[1]);
          putenv("$e=".trim($res[1]));
        }
      }
      else
        $env[$e] = getenv($e);
    }
  }

  function _build_env_to_check()
  {
    // construit une liste des variables d'env a tester
    // en fonction des modules choisis
    $env_to_check = array();
    foreach($this->MODULES as $m)
    {
      $ini_path = dirname(__FILE__).'/'.$m.'/config.ini';
      if (!file_exists($ini_path)) continue;
      $ini_data = parse_ini_file($ini_path);
      for ($i = 0 ; $i<count($ini_data['env']) ; $i++)
      {
        
        $env_to_check[$ini_data['env'][$i]] = array( $ini_data['env-desc'][$i],
                                                     $ini_data['env-choix'][$i] != '' ? explode(',',$ini_data['env-choix'][$i]) : array());
      }
    }
    return $env_to_check;
  }
  
  /**
   * Verifie que les variables d'environement passées en paramètre
   * sont bien définies et qu'il n'en manque pas suivant les modules selectionnés
   */
  function &check_env(&$env)
  {
    echo "Verification de la presence des variables d'environnement ...\n";

    // construit une liste des variables d'env a tester
    $env_to_check = $this->_build_env_to_check();
    foreach($env_to_check as $e => $e_option)
    {
      $e = $this->APPNAME.'_'.$e;
      $v = isset($env[$e]) ? $env[$e] : NULL;
      if ($v == NULL || $v == '')
      {
        $v = getenv($e);
        if ($v === FALSE || $v == '') 
        {
          echo "\n";
          echo "Signification de $e : ".$e_option[0]."\n";
          if (count($e_option[1]) > 0)
            echo "Valeurs possibles de $e : ".implode(' ou ', $e_option[1])."\n";
          echo "La variable $e est indefinie, entrez sa valeur : ";
          $v = readline();
        }
        $env[$e] = $v;
        putenv("$e=$v");
      }
      echo "La variable suivante sera utilisee : $e=$v\n";
    }
    return $env;
  }


  function write_env($env)
  {
    $filename = $this->env_path;
    echo "Ecriture des variables d'environnement dans ".realpath($filename)."\n";
    $data = '';
    foreach($env as $k => $v)
    {
      // ecriture dans le fichier src.env.ksh
      $data .= "export $k=$v\n";
      
      // ecriture dans l'environnement
      // on replace tout les prefixes par APPNAME car c'est le prefix des variables d'env dans nos templates
      if (preg_match('/'.$this->APPNAME.'_(.+)/',$k,$res))
        putenv("APPNAME_".$res[1]."=$v");
    }
    file_put_contents($filename,$data);
  }


  function build_templates_list()
  {
    $ret = array();
    foreach($this->MODULES as $m)
    {
      $list[$m] = array_values(ls($this->src_path.'/'.$m,"//i"));
      $n = 0;
      foreach( $list[$m] as $l)
      {
        $list[$m][$n] = str_replace($this->src_path.'/'.$m.'/', '', $list[$m][$n]);
        if (is_file($l))
          if ( dirname($l) == $this->src_path )
            unset($list[$m][$n]);
        if (trim($list[$m][$n]) == '' ||
            trim($list[$m][$n]) == '/' ||
            // ne liste pas config.ini dans les templates a instancier
            // car c'est un simple fichier de description
            $list[$m][$n] == 'config.ini')
          unset($list[$m][$n]);
        $n++;
      }
      $ret = array_merge($ret,$list);
    }
    return $ret;
  }

  // WRITE TPL INSTANCES
  function write_tpl_instance()
  {
    $tlist = $this->build_templates_list();
    foreach($tlist as $m => $templates)
      foreach($templates as $t)
      {
        $t_src = $this->src_path.'/'.$m.'/'.$t;
        $t_dst = $this->dst_path.'/'.$t;
        echo "Ecriture de ".$t_dst."\n";
        if (file_exists($t_src) && !is_dir($t_src))
        {
          $output = shell_exec($this->php_path.' '.$t_src);
          mkdir_r(dirname($t_dst));
          file_put_contents($t_dst, $output);
          // setting the rights
          if (is_executable($t_src) || preg_match('/^bin\//',$t))
            chmod($t_dst,0700);
          else
            chmod($t_dst,0600);
        }
        else if (substr($t_src,-1) == '/')
          mkdir_r($t_dst);
        else
          trigger_error($t_src." cannot be found",E_USER_ERROR);
      }
  }
}






function ls($dir, $mask /*.php$|.txt$*/)
{
  static $i = 0;
  $files = Array();
  $d = opendir($dir);
  $empty = true;
  while ($file = readdir($d))
  {
    if ($file == '.' || $file == '..' || $file == 'CVS' || $file == '.svn' || $file == '.dummy' || !preg_match($mask, $file) ) continue;
    $empty = false;
    if (is_dir($dir.'/'.$file))
    {
      $files += ls($dir.'/'.$file, $mask);
      continue;
    }
    $files[$i++] = $dir.'/'.$file;
  }
  closedir($d);
  if ($empty) $files[$i++] = $dir.'/';
  return $files;
}



function mkdir_r($dirName, $rights=0777){
  $dirs = explode('/', $dirName);
  $dir='';
  foreach ($dirs as $part) {
    $dir.=$part.'/';
    if (!is_dir($dir) && strlen($dir)>0)
      mkdir($dir, $rights);
  }
}


if (!function_exists('readline'))
{
  function readline()
  {
    //    $fp = fopen("php://stdin", "r");
    $in = trim(fgets(STDIN)); // Maximum windows buffer size
    //    fclose ($fp);
    return $in;
  }
}


/**
 * Replace file_put_contents()
 *
 * @category    PHP
 * @package     PHP_Compat
 * @link        http://php.net/function.file_put_contents
 * @author      Aidan Lister <aidan@php.net>
 * @version     $Revision: 1.2 $
 * @internal    resource_context is not supported
 * @since       PHP 5
 * @require     PHP 4.0.0 (user_error)
 */
if (!defined('FILE_USE_INCLUDE_PATH')) { define('FILE_USE_INCLUDE_PATH', 1); }
if (!defined('LOCK_EX')) { define('LOCK_EX', 2); }
if (!defined('FILE_APPEND')) { define('FILE_APPEND', 8); }
if (!function_exists('file_put_contents')) {
  function file_put_contents($filename, $content, $flags = null, $resource_context = null)
    {
      // If $content is an array, convert it to a string
      if (is_array($content)) {
        $content = implode('', $content);
      }

      // If we don't have a string, throw an error
      if (!is_scalar($content)) {
        user_error('file_put_contents() The 2nd parameter should be either a string or an array',
                   E_USER_WARNING);
        return false;
      }

      // Get the length of data to write
      $length = strlen($content);

      // Check what mode we are using
      $mode = ($flags & FILE_APPEND) ?
        'a' :
        'wb';

      // Check if we're using the include path
      $use_inc_path = ($flags & FILE_USE_INCLUDE_PATH) ?
        true :
        false;

      // Open the file for writing
      if (($fh = @fopen($filename, $mode, $use_inc_path)) === false) {
        user_error('file_put_contents() failed to open stream: Permission denied',
                   E_USER_WARNING);
        return false;
      }

      // Attempt to get an exclusive lock
      $use_lock = ($flags & LOCK_EX) ? true : false ;
      if ($use_lock === true) {
        if (!flock($fh, LOCK_EX)) {
          return false;
        }
      }

      // Write to the file
      $bytes = 0;
      if (($bytes = @fwrite($fh, $content)) === false) {
        $errormsg = sprintf('file_put_contents() Failed to write %d bytes to %s',
                            $length,
                            $filename);
        user_error($errormsg, E_USER_WARNING);
        return false;
      }

      // Close the handle
      @fclose($fh);

      // Check all the data was written
      if ($bytes != $length) {
        $errormsg = sprintf('file_put_contents() Only %d of %d bytes written, possibly out of free disk space.',
                            $bytes,
                            $length);
        user_error($errormsg, E_USER_WARNING);
        return false;
      }

      // Return length
      return $bytes;
    }
}


?>
