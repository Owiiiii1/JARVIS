<?php

namespace App\Models;

use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;
use Illuminate\Database\Eloquent\Model;

class VoiceSetting extends Model
{
    protected $hidden = [
        'elevenlabs_api_key',
    ];

    protected $fillable = [
        'stt_provider',
        'tts_provider',
        'spoken_style_enabled',
        'elevenlabs_api_key',
        'elevenlabs_voice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stt_provider' => VoiceSttProvider::class,
            'tts_provider' => VoiceTtsProvider::class,
            'spoken_style_enabled' => 'boolean',
            'elevenlabs_api_key' => 'encrypted',
        ];
    }
}
