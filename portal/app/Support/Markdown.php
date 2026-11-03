<?php

namespace App\Support;

use Illuminate\Support\Str;

class Markdown
{
    /** Render admin-written Markdown to safe HTML: raw HTML is stripped and unsafe links are dropped. */
    public static function render(?string $text): string
    {
        return Str::markdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
