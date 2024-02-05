<?php

declare(strict_types = 1);

namespace Bloembraaden;

class InstagramImage extends Image
{
    public const SIZES =  array(
        'large' => 1800,
        'medium' => 900,
        'small' => 600,
        'tiny' => 300,
    );

    public function __construct(\stdClass $row = null)
    {
        if (true === isset($row->src)) {
            $row->slug = $row->src;//substr($row->src, 0, -4); // remove .jpg from original, we're not checking it
        }
        parent::__construct($row);
        $this->type_name = 'instagram_image';
    }

    public function create(?bool $online = true): ?int
    {
        return null;
    }

    public function update(array $data): bool
    {
        unset($data['extension']); // might be there, no need to be saved, prevent the warning in the logs
        return Help::getDB()->updateColumns('_instagram_media', $data, $this->row->media_id);
    }

    public function getInstanceId(): int
    {
        return 0;
    }
}