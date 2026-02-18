<?php

declare(strict_types = 1);

namespace Bloembraaden;

class File extends BaseElement
{
    public function __construct(?\stdClass $row = null)
    {
        parent::__construct($row);
        $this->type_name = 'file';
    }

    public function create(?bool $online = true): ?int
    {
        return Help::getDB()->insertElement($this->getType(), array(
            'title' => __('New file', 'peatcms'),
            'content_type' => 'application/octet-stream',
            'filename_saved' => '',
            'slug' => 'file',
            'online' => $online,
        ));
    }

    /**
     * Serves the file if possible, else throws a fatal error
     * In any case execution is halted
     */
    public function serve(): void
    {
        // make sure nothing has been sent
        if (headers_sent()) {
            $this->handleErrorAndStop("Headers already sent, cannot serve file {$this->row->slug}.", __('Error serving file.', 'peatcms'));
        } else {
            $filename = Setup::$UPLOADS . $this->row->filename_saved; // 'uploads' is base folder of XSendFile extension
            if (file_exists($filename)) {
                //header('Content-Disposition: attachment; filename="' . basename("{$this->row->slug}.{$this->row->extension}") . '"');
                header("Content-Type: {$this->row->content_type}");
                // https://dev.to/gbhorwood/nginx-serving-private-files-with-x-accel-redirect-57dl
                header("X-Accel-Redirect: /private_uploads/{$this->row->filename_saved}");
                die();
            } else {
                $this->handleErrorAndStop("$filename not found", __('File not found on server.', 'peatcms'));
            }
        }
    }
}

