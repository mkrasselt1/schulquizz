<?php

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'type' => $this->type,
            'prompt_mode' => $this->prompt_mode,
            'prompt_text' => $this->getTranslations('prompt_text'),
            'prompt_image_path' => $this->prompt_image_path,
            'time_limit' => $this->time_limit,
            'points' => $this->points,
            'scored' => $this->scored,
            'reveal_after' => $this->reveal_after,
            'config' => $this->config ?? [],
            'options' => $this->answerOptions->map(fn ($o) => [
                'id' => $o->id,
                'position' => $o->position,
                'mode' => $o->mode,
                'text' => $o->getTranslations('text'),
                'image_path' => $o->image_path,
                'is_correct' => $o->is_correct,
            ])->values(),
        ];
    }
}
