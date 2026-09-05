<?php

namespace App\Services\Voice;

final class ElevenLabsVoiceCatalog
{
    private const DEFAULT_VOICE_ID = 'cjVigY5qzO86Huf0OWal';

    /**
     * @var list<array{id: string, name: string, gender: 'female'|'male', style: string}>
     */
    private const VOICES = [
        [
            'id' => 'cgSgspJ2msm6clMCkdW9',
            'name' => 'Jessica',
            'gender' => 'female',
            'style' => 'playful_warm',
        ],
        [
            'id' => 'EXAVITQu4vr4xnSDxMaL',
            'name' => 'Sarah',
            'gender' => 'female',
            'style' => 'calm_confident',
        ],
        [
            'id' => 'pFZP5JQG7iQjIQuC4Bku',
            'name' => 'Lily',
            'gender' => 'female',
            'style' => 'velvety_expressive',
        ],
        [
            'id' => 'cjVigY5qzO86Huf0OWal',
            'name' => 'Eric',
            'gender' => 'male',
            'style' => 'smooth_trustworthy',
        ],
        [
            'id' => 'JBFqnCBsd6RMkjVDRZzb',
            'name' => 'George',
            'gender' => 'male',
            'style' => 'warm_storyteller',
        ],
        [
            'id' => 'iP95p4xoKVk53GoZ742B',
            'name' => 'Chris',
            'gender' => 'male',
            'style' => 'natural_friendly',
        ],
    ];

    /**
     * @return list<array{id: string, name: string, gender: 'female'|'male', style: string}>
     */
    public static function options(): array
    {
        return self::VOICES;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_column(self::VOICES, 'id');
    }

    public static function defaultId(): string
    {
        return self::DEFAULT_VOICE_ID;
    }
}
