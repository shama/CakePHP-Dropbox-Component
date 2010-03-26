<?php
/**
 * CAKEPHP DROPBOX COMPONENT v0.3
 * Connects Cakephp to Dropbox using cURL.
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
 * @version 0.3
 * @link http://www.kyletyoung.com/code/cakephp_dropbox_component
 * 
 * SETTINGS:
 * 	email/password: To your dropbox account
 * 	cache: Set to name of cache config or false for no cache
 * 
 * When in doubt, clear the cache.
 * 
 * TODO:
 * Make sync function smarter (use modified).
 * Centralize regex to update if Dropbox changes.
 *
 */
class DropboxComponent extends Object 
{
    var $email, $password;
    var $loggedin = false;
    var $post, $cookie = array();
    var $cache = 'default';
    var $_wcache = array();
    
    /**
     * INITIALIZE
     * @param $controller
     * @param $settings
     */
    function initialize(&$controller, $settings=array())
    {
        if (!extension_loaded('curl'))
        {
            trigger_error('Dropbox Component: I require the cURL extension to work.');
        } // no curl
        if (empty($settings['email']) || empty($settings['password']))
        {
            trigger_error('Dropbox Component: I need your dropbox email and password to login.');
        } // email|pass empty
        else
        {
            $this->email = $settings['email'];
            $this->password = $settings['password'];
            if (isset($settings['cache'])) $this->cache = $settings['cache'];
            $this->login();
        } // else
    } // initialize
    
    /**
     * UPLOAD
     * Upload a local file to a remote folder.
     * 
     * @param $file
     * @param $dir
     * @return bool
     */
    function upload($from=null, $to='/')
    {
        if (!file_exists($from)) return false;
        $data = $this->request('https://www.dropbox.com/home');
        if (!$token = $this->getToken($data, 'https://dl-web.dropbox.com/upload')) return false;
        $this->post = array(
        	'plain'    => 'yes',
        	'file'     => '@'.$from,
        	'dest'     => $to,
        	't'        => $token
        );
        //debug($this->post);
        $data = $this->request('https://dl-web.dropbox.com/upload');
        if (strpos($data, 'HTTP/1.1 302 FOUND') === false) return false;
        return true;
    } // upload
    
    /**
     * DOWNLOAD
     * Download a remote file to a local folder.
     * Both from and to must be a path to a file name.
     * 
     * @param str $from
     * @param str $to
     * @param str $w
     * @return bool
     */
    function download($from=null, $to=null, $w=null)
    {
        $data = $this->file($from, $w);
        if (empty($data['data'])) return false;
        if (!is_writable(dirname($to))) return false;
        if (!$fp = fopen($to, 'w')) return false;
        if (fwrite($fp, $data['data']) === false) return false;
        fclose($fp);
        return true;
    } // download
    
    /**
     * SYNC
     * Compares files from the local and remote folders 
     * then syncs them.
     * Both local and remote must be folders.
     * 
     * TODO:
     * Currently only checks if files exists. Doesn't 
     * check if they are up to date which it should.
     * 
     * @param str $local
     * @param str $remote
     * @return bool
     */
    function sync($local=null, $remote=null)
    {
        if (!is_dir($local)) return false;
        
        // GET REMOTE FILES
        $remote_files = $this->files($remote);
        
        // GET LOCAL FILES
        $local_files = array();
        $d = dir($local);
        while (false !== ($entry = $d->read())) 
        {
            if (substr($entry, 0, 1) == '.') continue;
            if (is_dir($local.DS.$entry)) continue;
            $local_files[] = $entry;
        } // while
        $d->close();
        
        // DOWNLOAD FILES
        $tmp = array();
        foreach ($remote_files as $file)
        {
            if (empty($file['w'])) continue;
            $tmp[] = $file['name'];
            if (in_array($file['name'], $local_files)) continue;
            $this->download($file['path'].$file['name'], $local.$file['name'], $file['w']);
        } // foreach
        
        // UPLOAD FILES
        foreach ($local_files as $file)
        {
            if (in_array($file, $tmp)) continue;
            $this->upload($local.$file, $remote);
        } // foreach
        
        return true;
    } // sync
   
    /**
     * FILES
     * Returns an array of remote files/folders 
     * within the given dir param.
     * 
     * @param str $dir
     * @return array
     */
    function files($dir='/') 
    {
        $dir = $this->escape($dir);
        if ($this->cache === false) Cache::delete('dropbox_files_'.$dir, $this->cache);
        if (($files = Cache::read('dropbox_files_'.$dir, $this->cache)) === false)
        {
            $files = array();
            $data = $this->request('https://www.dropbox.com/browse_plain/'.$dir.'?no_js=true');
            
            // GET FILES
            preg_match_all('/<div.*details-filename.*>(.*?)<\/div>/i', $data, $matches);
            if (empty($matches[0])) return false;
            
            // GET TYPES
            preg_match_all('/<div.*details-icon.*>(<img.*class="sprite s_(.*)".*>)<\/div>/i', $data, $types);
            if (!empty($types[2])) $types = $types[2];
            
            // GET SIZES
            preg_match_all('/<div.*details-size.*>(.*)<\/div>/i', $data, $sizes);
            if (!empty($sizes[1])) $sizes = $sizes[1];
            
            // GET MODS
            preg_match_all('/<div.*details-modified.*>(.*)<\/div>/i', $data, $mods);
            if (!empty($mods[1])) $mods = $mods[1];
            
            $i = 0;
            foreach ($matches[0] as $key => $file)
            {
                // IF PARENT
                if (strpos($file, "Parent folder") !== false) continue;
                
                // GET FILENAME
                preg_match('/href=[("|\')]([^("|\')]+)/i', $file, $found);
                if (empty($found[1])) continue;
                $found = parse_url($found[1]);
                $filename = pathinfo($found['path']);
                $filename = $filename['basename'];
                if (empty($filename)) continue;
                
                // SET DEFAULTS
                $path = $dir.$filename;
                $type = 'unknown';
                $size = 0;
                $modified = 0;
                
                // GET TYPE
                if (!empty($types[$key])) $type = trim($types[$key]);
                
                // GET SIZE
                if (!empty($sizes[$key])) $size = trim($sizes[$key]);
                
                // GET MODIFIED
                if (!empty($mods[$key])) $modified = trim($mods[$key]);
                
                // ADD TO FILES
                $files[$i] = array(
                    'path'		=> urldecode($dir),
                    'name'		=> $filename,
                    'type'		=> $type,
                    'size'		=> $size,
                    'modified'	=> $modified
                );
                
                // IF FILE OR FOLDER
                preg_match('/\?w=(.[^"]*)/i', $file, $match);
                if (!empty($match[1]))
                {
                    $files[$i]['w'] = $match[1];
                    
                    // SAVE W FOR LATER
                    $this->_wcache[$dir.'/'.$filename] = $match[1];
                } // !empty
                
                $i++;
            } // foreach
            
        } // Cache::read
        if ($this->cache !== false) 
        {
            Cache::write('dropbox_files_'.$dir, $files, $this->cache);
        } // if cache
        return $files;
    } // files
    
    /**
     * FILE
     * Returns a remote file as an array.
     * 
     * @param str $file
     * @param str $w
     * @return array
     */
    function file($file=null, $w=null)
    {
        $file = $this->escape($file);
        if ($this->cache === false) Cache::delete('dropbox_file_'.$file, $this->cache);
        if (($out = Cache::read('dropbox_file_'.$file, $this->cache)) === false)
        {
            if (empty($w))
            {
                if (!empty($this->_wcache[$file])) $w = $this->_wcache[$file];
                else return false;
            } // empty w
            $data = $this->request('https://dl-web.dropbox.com/get/'.$file.'?w='.$w);
            preg_match('/Content-Type: .+\/.+/i', $data, $type);
            $data = substr(stristr($data, "\r\n\r\n"), 4);
            if (!empty($type[0])) $type = $type[0];
            $out = array(
                'path'			  => $file,
                'w'				  => $w,
            	'data'            => $data,
            	'content_type'    => $type
            );
            if ($this->cache !== false) 
            {
                Cache::write('dropbox_file_'.$file, $out, $this->cache);
            } // if cache
        } // Cache::read
        return $out;
    } // file
    
    /**
     * LOGIN
     * to dropbox
     * 
     * @return bool
     */
    function login() 
    {
        if (!$this->loggedin)
        {
            if (empty($this->email) || empty($this->password)) return false;
            $data = $this->request('https://www.dropbox.com/login');
            
            // GET TOKEN
            if (!$token = $this->getToken($data, '/login')) return false;
            
            // LOGIN TO DROPBOX
            $this->post = array(
            	'login_email'        => $this->email,
            	'login_password'     => $this->password,
            	't'                  => $token
            );
            $data = $this->request('https://www.dropbox.com/login');

            // IF WERE HOME
            if (stripos($data, 'location: /home') === false) return false;
            $this->loggedin = true;
        } // if loggedin
        return true;
    } // login

    /**
     * REQUEST
     * Returns data from given url and 
     * saves cookies. Use $this->post and 
     * $this->cookie to submit params.
     * 
     * @param str $url
     * @return str
     */
    function request($url=null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        // IF POST
        if (!empty($this->post)) 
        {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->post);
            $this->post = array();
        } // !empty
        
        // IF COOKIES
        if (!empty($this->cookie))
        {
            $cookies = array();
            foreach ($this->cookie as $key => $val)
            {
                $cookies[] = "$key=$val";
            } // foreach
            $cookies = implode(';', $cookies);
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        } // !empty
        
        // GET DATA
        $data = curl_exec($ch);
        
        // SAVE COOKIES
        preg_match_all('/Set-Cookie: ([^=]+)=(.*?);/i', $data, $matches, PREG_SET_ORDER);
        foreach ($matches as $match)
        {
            $this->cookie[$match[1]] = $match[2];
        } // foreach
        
        curl_close($ch);
        return $data;
    } // request

    /**
     * GET TOKEN
     * Returns the 't' input field value of the
     * requested form.
     * 
     * @param str $html
     * @param str $action
     * @return bool
     */
    function getToken($data=null, $action=null) 
    {
        preg_match('/<form [^>]*'.preg_quote($action, '/').'[^>]*>.*?<\/form>/si', $data, $matches);
        if (empty($matches[0])) return false;
        preg_match('/<input [^>]*name="t" [^>]*value="(.*?)"[^>]*>/si', $matches[0], $matches);
        if (empty($matches[1])) return false;
        return $matches[1];
    } // getToken
    
    /**
     * ESCAPE
     * Returns a dropbox friendly str
     * for a url
     * 
     * @param str $str
     * @return str
     */
    function escape($str=null)
    {
        return str_replace(
            array('+','_','%2E','-','%2F','%3A'),
            array('%20','%5F','.','%2D','/',':'),
            urlencode($str)
        );
    } // escape

} // DropboxComponent