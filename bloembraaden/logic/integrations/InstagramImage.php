<?php

declare(strict_types = 1);

namespace Peat;

class InstagramImage extends Image
{
    protected array $sizes;

    public function __construct(\stdClass $row = null)
    {
        if (true === isset($row->src)) {
            $row->slug = $row->src;//substr($row->src, 0, -4); // remove .jpg from original, we're not checking it
        }
        parent::__construct($row);
        $this->type_name = 'instagram_image';
        $this->sizes = array(
            'large' => 1800,
            'medium' => 900,
            'small' => 600,
            'tiny' => 300,
        );
    }

    public function create(): ?int
    {
        return null;
    }

    public function update(array $data): bool
    {
        return Help::getDB()->updateColumns('_instagram_media', $data, $this->row->media_id);
    }

    public function getInstanceId(): int
    {
        return 0;
    }
}