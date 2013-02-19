<?php

$routing = array(
    'GET' => array(
        '/' => array(
            'class'     => 'Index',
            'method'    => 'defaultAction',
            'route'     => 'homepage',
        ),
        
        '/upload' => array(
            'class'     => 'Upload',
            'method'    => 'formAction',
            'route'     => 'upload_form',
        ),
        
        '/upload-success' => array(
            'class'     => 'Upload',
            'method'    => 'successAction',
            'route'     => 'upload_success',
        ),
        
        '/download/{slug}' => array(
            'class'     => 'Download',
            'method'    => 'defaultAction',
            'route'     => 'download_confirm',
        ),
        '/download/file/{slug}' => array(
            'class'     => 'Download',
            'method'    => 'fileAction',
            'route'     => 'download_file',
        ),
    ),
    
    'POST' => array(
        '/upload' => array(
            'class'     => 'Upload',
            'method'    => 'formAction',
            'route'     => 'upload_submit',
        ),
        
        '/upload-info' => array(
            'class'     => 'Upload',
            'method'    => 'infoAction',
            'route'     => 'upload_info',
        ),
        
        '/download' => array(
            'class'     => 'Download',
            'method'    => 'defaultAction',
            'route'     => 'download_submit',
        ),
    ),
);