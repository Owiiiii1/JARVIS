<?php

namespace App\Enums;

enum TopicContinuityMode: string
{
    case Continue = 'continue';
    case Subtopic = 'subtopic';
    case Switch = 'switch';
    case Return = 'return';
}
