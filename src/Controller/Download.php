<?php
/**
 * This file is part of the OpenShareFile package.
 *
 * (c) Simon Leblanc <contact@leblanc-simon.eu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace OpenShareFile\Controller;

use OpenShareFile\Core\Config;
use OpenShareFile\Core\Exception;
use OpenShareFile\Model\File as DBFile;
use OpenShareFile\Model\Upload as DBUpload;
use OpenShareFile\Utils\Gpg;

use OpenShareFile\Extension\Symfony\Validator\Constraints\Password as AssertPassword;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Download controler
 *
 * @package     OpenShareFile\Controller
 * @version     1.0.0
 * @license     http://opensource.org/licenses/MIT  MIT
 * @author      Simon Leblanc <contact@leblanc-simon.eu>
 */
class Download extends App
{
    /**
     * Download file form action
     *
     * @return  Response
     * @throws  OpenShareFile\Core\Exception\Error404  Error while retrieving Upload object
     * @throws  OpenShareFile\Core\Exception\Security  Password for download are wrong
     * @access  public
     */
    public function defaultAction()
    {
        $slug = null;
        if ('GET' === $this->app['request']->getMethod()) {
            $slug = $this->app['request']->get('slug');
        } else {
            $form = $this->app['request']->get('form');
            if (is_array($form) === true && isset($form['slug']) === true) {
                $slug = $form['slug'];
            }
        }
        
        if (empty($slug) === true) {
            throw new Exception\Error404();
        }
        
        // Get associated upload to slug
        $upload = new DBUpload($slug);
        if ($upload->getId() === 0) {
            throw new Exception\Error404();
        }
        
        // Check if the upload is deleted
        if ($upload->isDeleted() === true) {
            throw new Exception\Error404();
        }
        
        
        // Create form to download files
        $form = $this->app['form.factory']->createBuilder('form')
                ->add('slug', 'hidden', array(
                    'data' => $upload->getSlug(),
                    'required' => true,
                ));
        
        // If upload is protected, get password
        if ($upload->getPasswd() !== '') {
            $form->add('password', 'password', array(
                'required' => true,
                'constraints' => array(
                    new AssertPassword(array(
                        'object' => $upload,
                        'method' => 'getPasswd',
                        'message' => $this->app['translator']->trans('Wrong password.'),
                    )),
                )
            ));
        }
        
        $files = $upload->getFiles();
        foreach ($files as $file) {
            $form->add('file_'.$file->getSlug(), 'hidden', array('required' => false));
        }
        
        // Allowed ZIP download only if configuration is OK and files are not crypted
        if (Config::get('allow_zip') === true && $upload->getCrypt() === false) {
            $form->add('zip', 'hidden', array('required' => false));
        }
        
        $form = $form->getForm();
        
        // Process the form if it's a POST request
        if ('POST' === $this->app['request']->getMethod()) {
            $form->bind($this->app['request']);
            
            if ($form->isValid()) {
                $data = $form->getData();
                
                if ($upload->isDeleted() === true) {
                    throw new Exception\Error404();
                }
                
                if ($upload->getPasswd() !== '' && password_verify($data['password'], $upload->getPasswd()) === false) {
                    throw new Exception\Security();
                }
                
                $file_slug = null;
                foreach ($data as $key => $value) {
                    if (substr($key, 0, 5) === 'file_' && $value === '1') {
                        $file_slug = substr($key, 5);
                        break;
                    }
                }
                
                $zip_slug = null;
                if (Config::get('allow_zip') === true && isset($data['zip']) === true && $data['zip'] === '1') {
                    $zip_slug = $upload->getSlug();
                }
                
                if ($file_slug === null && $zip_slug === null) {
                    throw new Exception\Error404();
                }
                
                // Save this allowed upload
                $this->app['session']->set('allowed_upload', array_merge(array($upload->getSlug()), $this->app['session']->get('allowed_upload', array())));
                
                // Save password if upload is crypted
                if ($upload->getCrypt() === true) {
                    $this->app['session']->set('upload_'.$upload->getSlug(), $data['password']);
                }
                
                // Redirect in the download file action or zip action
                if ($file_slug !== null) {
                    return $this->app->redirect($this->app['url_generator']->generate('download_file', array('slug' => $file_slug)), 301);
                } elseif ($zip_slug !== null) {
                    return $this->app->redirect($this->app['url_generator']->generate('download_zip', array('slug' => $zip_slug)), 301);
                }
            }
        }
        
        return $this->render('confirm.html.twig', array('upload' => $upload, 'files' => $files, 'form' => $form->createView()));
    }
    
    
    /**
     * Download file action
     *
     * @return  Response
     * @throws  OpenShareFile\Core\Exception\Error404  Error while retrieving Upload object
     * @throws  OpenShareFile\Core\Exception\Security  Password for download are wrong
     * @throws  OpenShareFile\Core\Exception\Exception Error while reading file to download
     * @access  public
     * @see     http://programmation-web.net/2012/04/reprendre-le-telechargement-dun-fichier-en-php/
     */
    public function fileAction()
    {
        $file_slug = $this->app['request']->get('slug');
        
        $file = new DBFile($file_slug);
        if ($file->getId() === 0 || $file->isDeleted() === true) {
            throw new Exception\Error404();
        }
        
        $upload = $file->getUpload();
        if ($upload->getId() === 0 || $upload->isDeleted() === true) {
            throw new Exception\Error404();
        }
        
        if ($upload->getPasswd() !== '' && in_array($upload->getSlug(), $this->app['session']->get('allowed_upload', array())) === false) {
            throw new Exception\Security();
        }
        
        $filename = Config::get('data_dir').$file->getFile();
        if ($upload->getCrypt() === true) {
            $filename .= '.gpg';
        }
        
        if (file_exists($filename) === false) {
            throw new Exception\Error404();
        }
        
        if ($upload->getCrypt() === true) {
            return $this->downloadEncryptFile($upload, $file, $filename);
        } else {
            return $this->downloadFile($file, $filename);
        }
    }
    
    
    /**
     * Download a zip file with all files action
     * ZIP file doesn't support HTTP_RANGE !
     *
     * @return  \Symfony\Component\HttpFoundation\StreamedResponse
     * @throws  OpenShareFile\Core\Exception\Error404  Error while retrieving Upload object
     * @throws  OpenShareFile\Core\Exception\Security  Password for download are wrong
     * @throws  OpenShareFile\Core\Exception\Exception Error while processing zip file to download
     * @access  public
     * @see     http://stackoverflow.com/questions/4357073/on-the-fly-zipping-streaming-of-large-files-in-php-or-otherwise
     */
    public function zipAction()
    {
        // Check if zip is allowed
        if (Config::get('allow_zip') === false) {
            throw new Exception\Security();
        }
        
        $slug = $this->app['request']->get('slug');
        
        if (empty($slug) === true) {
            throw new Exception\Error404();
        }
        
        // Get associated upload to slug
        $upload = new DBUpload($slug);
        if ($upload->getId() === 0) {
            throw new Exception\Error404();
        }
        
        // Check if the upload is deleted
        if ($upload->isDeleted() === true) {
            throw new Exception\Error404();
        } 
        
        if ($upload->getPasswd() !== '' && in_array($upload->getSlug(), $this->app['session']->get('allowed_upload', array())) === false) {
            throw new Exception\Security();
        }
        
        if ($upload->getCrypt() === true) {
            throw new Exception\Exception('crypt upload can\'t be downloaded with ZIP');
        }
        
        // Create tmp folder : this folder will be deleted when upload will be expirated
        $tmp_dir = Config::get('data_dir').DIRECTORY_SEPARATOR.'tmp_zip'.DIRECTORY_SEPARATOR.$upload->getSlug();
        if (is_dir($tmp_dir) === false && @mkdir($tmp_dir, Config::get('directory_mode', 0755), true) === false) {
            throw new Exception\Exception();
        }
        
        // Get files
        $files = $upload->getFiles();
        foreach ($files as $file) {
            $filename = Config::get('data_dir').$file->getFile();
            
            if (file_exists($filename) === false) {
                throw new Exception\Exception();
            }
            
            // Create a symbolic link to have the good name of file
            $symlink = $tmp_dir.DIRECTORY_SEPARATOR.$file->getFilename();
            if (file_exists($symlink) === false && readlink($symlink) !== $filename && @symlink($filename, $symlink) === false) {
                throw new Exception\Exception();
            }
        }

        $cmdline = escapeshellcmd(Config::get('zip_binary')).' -j - '.escapeshellarg($tmp_dir).DIRECTORY_SEPARATOR.'*';
        $handle = popen($cmdline, 'r');
        if ($handle === false) {
            throw new Exception\Exception();
        }

        $stream = function () use ($handle) {
            $buffer_size = 8192; // send by 8KB : 8192 is the size of the default buffer on many popular operating systems
            while (feof($handle) === false) {
                echo fread($handle, $buffer_size);
                ob_flush();
                flush();
            }

            pclose($handle);
        };

        return $this->app->stream($stream, 200, [
            'Content-Type' => 'application/force-download',
            'Content-disposition' => 'attachment; filename="'.$upload->getSlug().'.zip"',
            'Content-Transfer-Encoding' => 'application/octet-stream',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0, public',
            'Expires' => '0'
        ]);
    }
    
    
    /**
     * Download a file
     *
     * @param   \OpenShareFile\Model\Upload     $upload     the Upload object
     * @param   \OpenShareFile\Model\File       $file       the File object (the file to download)
     * @param   string                          $filename   the path of the file to download
     * @access  private
     */
    private function downloadFile(DBFile $file, $filename)
    {
        return $this->app
            ->sendFile($filename)
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFilename());
    }
    
    
    /**
     * Download an encrypted file
     *
     * @param   \OpenShareFile\Model\Upload     $upload     the Upload object
     * @param   \OpenShareFile\Model\File       $file       the File object (the file to download)
     * @param   string                          $filename   the path of the file to download
     * @return  \Symfony\Component\HttpFoundation\StreamedResponse
     * @access  private
     * @throws  Exception\Security
     */
    private function downloadEncryptFile(DBUpload $upload, DBFile $file, $filename)
    {
        $password = $this->app['session']->get('upload_'.$upload->getSlug(), null);
        if ($password === null) {
            throw new Exception\Security();
        }

        $stream = function () use ($filename, $password) {
            Gpg::decrypt($filename, $password);
        };

        return $this->app->stream($stream, 200, [
            'Content-Type' => 'application/force-download',
            'Content-disposition' => 'attachment; filename="'.str_replace('"', '', $file->getFilename()).'"',
            'Content-Transfer-Encoding' => 'application/octet-stream',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0, public',
            'Expires' => '0'
        ]);
    }
}
