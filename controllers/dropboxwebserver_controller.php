<?php
 /**
 * DROPBOX WEBSERVER CONTROLLER
 * A CakePHP webserver controller using files on the fly from Dropbox.
 * 
 * Copyright (C) 2010 Kyle Robinson Young
 * 
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 * 
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 * 
 * @author Kyle Robinson Young <kyle at kyletyoung.com>
 * @copyright 2010 Kyle Robinson Young
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link http://www.kyletyoung.com/code/cakephp_dropbox_component
 *
 */
class DropboxWebserverController extends DropboxAppController
{
    var $name = 'DropboxWebserver';
    var $uses = array();
    var $autoRender = false;
    var $components = array('Dropbox' => array(
    	'email'     => 'your@dropboxemail.com',
        'password'	=> 'dropboxpassword',
        //'cache'		=> false
    ));
    
    var $root_folder = '/';
    var $default_home = array('index.html', 'index.htm', 'index.php');
    
    /**
     * INDEX
     */
    function index()
    {
        $args = func_get_args();
        $args = implode('/', $args);
        
        $path = pathinfo($args);
        if ($path['dirname'] == ".")
        {
            $folder = $path['basename'];
            $file = '';
        } // dirname == .
        else
        {
            $folder = $path['dirname'];
            $file = $path['basename'];
        } // else
        
        $files = $this->Dropbox->files($this->root_folder.$folder);
        //debug($files);
        
        // FIND FILE
        foreach ($files as $f)
        {
            if (strpos($f['type'], 'folder') !== false) continue;
            if (empty($f['name'])) continue;
            if ($f['name'] == $file)
            {
                $file = $this->Dropbox->file($this->root_folder.$folder.'/'.$file, $f['w']);
                $output = $file['data'];
                $content_type = $file['content_type'];
                break;
            } // name == file
            
            // FIND DEFAULT HOME
            if (in_array($f['name'], $this->default_home))
            {
                $default = $f;
            } // in_array
        } // foreach
        
        if (!empty($output))
        {
            header('Content-Type: '.$content_type);
            echo $output;
        } // !empty
        elseif (!empty($default))
        {
            $file = $this->Dropbox->file($this->root_folder.$folder.'/'.$default['name'], $default['w']);
            header('Content-Type: '.$file['content_type']);
            echo $file['data'];
        } // !empty default
        else
        {
            echo 'Error 404: File Not Found';
        } // else
        
    } // index
    
} // DropboxWebserver
?>