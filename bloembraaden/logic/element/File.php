<?php

namespace Peat;
class File extends BaseElement
{
    public function __construct(\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'file';
    }

    public function create(): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New file', 'peatcms'),
            'content_type' => 'application/octet-stream',
            'filename_saved' => '',
            'slug' => 'file',
        ));
    }

    /**
     * Serves the file if possible, else throws a fatal error
     * In any case execution is halted
     */
    public function serve()
    {
        // make sure nothing has been send
        if (($c = ob_get_contents())) {
            echo $this->row->content_type . '<br/>';
            echo $this->row->filename_saved . '<br/>';
            die('Already contents in buffer, cannot serve file');
        } else {
            $filename = Setup::$UPLOADS . $this->row->filename_saved; // 'uploads' is base folder of XSendFile extension
            $savename = $this->row->slug . '.' . $this->row->extension;
            if (file_exists($filename)) {
                // https://stackoverflow.com/questions/3697748/fastest-way-to-serve-a-file-using-php
                // TODO unfortunately xsendfile is not part of the repo for centos8, so re-program this when you're on NGINX
                header('Content-Type: ' . $this->row->content_type);
                header('Content-Disposition: attachment; filename="' . basename($savename) . '"');
                header('Content-Length: ' . filesize($filename));
                readfile($filename);
                die();
            } else {
                $this->handleErrorAndStop($filename . ' not found', __('File not found on server', 'peatcms'));
            }
        }
    }
}

