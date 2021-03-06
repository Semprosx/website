<?php

declare(strict_types=1);

namespace App\Models\MediaLibrary;

use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\UrlGenerator\LocalUrlGenerator;
use Spatie\MediaLibrary\UrlGenerator\UrlGenerator;

class ProtectedFileUrlGenerator extends LocalUrlGenerator implements UrlGenerator
{
     /**
      * Get the URL for the profile of a media item.
      * @return string
      * @throws UrlCouldNotBeDeterminedException
      */
    public function getUrl(): string
    {
        return URL::route('files.download-single', ['media' => $this->media]);
    }
}
