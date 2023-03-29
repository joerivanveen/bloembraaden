<?php

declare(strict_types = 1);

namespace Bloembraaden;

interface Element
{
    public function __construct(\stdClass $row = null);
    // expects an object (via DB->normalizeRow with all the properties that make up this class
    // when constructed including the properties this is part of a result set, don't add suggestions or children etc.
    // fetchById also means this is a single instance, you can add suggestions and children to it etc, which can be filtered.
    public function fetchById(int $id = 0); // provides a method to instantiate by only providing an id

    public function getOutput(int $nest_level = 0); // outputs array with properties that are content for the template

    public function setProperties(); // set the properties all sub items should be filtered by

    public function create(): ?int;
}