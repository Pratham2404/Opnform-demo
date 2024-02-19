<?php

namespace App\Http\Requests;

use App\Rules\StorageFile;
use Illuminate\Foundation\Http\FormRequest;

class UploadAssetRequest extends FormRequest
{
    const FORM_ASSET_MAX_SIZE = 50000000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $fileTypes = [
            'png',
            'jpeg',
            'jpg',
            'bmp',
            'gif',
            'svg',
            'mp4'
        ];
        if ($this->offsetExists('type') && $this->get('type') === 'files') {
            $fileTypes = [];
        }

        return [
            'url' => ['required', new StorageFile(self::FORM_ASSET_MAX_SIZE, $fileTypes)],
        ];
    }
}
