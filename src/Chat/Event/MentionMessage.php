<?php declare(strict_types=1);

namespace Room11\Jeeves\Chat\Event;

class MentionMessage extends MessageEvent
{
    const EVENT_TYPE_ID = 8;

    private $parentId;

    public function __construct(array $data)
    {
        parent::__construct($data);

        $this->parentId = $data['parent_id'];
    }

    public function getParentId(): int
    {
        return $this->parentId;
    }
}