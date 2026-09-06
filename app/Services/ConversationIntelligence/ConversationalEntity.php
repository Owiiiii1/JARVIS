<?php

namespace App\Services\ConversationIntelligence;

final readonly class ConversationalEntity
{
    public function __construct(
        public string $type,
        public string $label,
        public ?int $id = null,
        public bool $trusted = false,
        public bool $expired = false,
    ) {}

    public function compactLine(): string
    {
        $id = $this->id !== null ? ' #'.$this->id : '';
        $flags = [];

        if ($this->trusted && ! $this->expired) {
            $flags[] = 'trusted';
        }

        if ($this->expired) {
            $flags[] = 'expired';
        }

        $suffix = $flags === [] ? '' : ' ('.implode(', ', $flags).')';

        return ucfirst($this->type).$id.' «'.$this->label.'»'.$suffix;
    }
}
