<?php

declare(strict_types = 1);

namespace Bloembraaden;

/**
 * Class Warmup
 * @package Peat
 */
class Warmup extends BaseLogic
{
    private array $slugs = array();

    public function Warmup(string $slug, int $instance_id): bool
    {
        if (isset($this->slugs[$slug]) && $instance_id === $this->slugs[$slug]) return false; // no need to warmup more than once
        $this->slugs[$slug] = $instance_id;
        // the slug for cache may contain spaces and other non-slug elements, so slugify is out of the question
        //$slug = implode('/', array_map('rawurlencode', explode('/', $slug)));
        $resolver = new Resolver($slug, $instance_id);
        if (true === $resolver->hasInstructions()) {
            $this->addMessage(sprintf(__('%s is never cached', 'peatcms'), $slug), 'note');

            return false;
        }
        $element = $resolver->getElement($from_history, true);
        // if the cached element now has a different slug, you can remove the old version safely
        if ($from_history || $element instanceof BaseElement && $slug !== $element->getPath()) {
            Help::getDB()->deleteFromCache($slug);
        }

        return null === $element->cacheOutputObject(false);
    }
}