<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\BaseFileUpload;

/**
 * Profile picture field with a simple square picture editor instead of the FilePond widget.
 *
 * Picking a picture opens the editor straight away; the browser crops it to a square and uploads only
 * the result, so Filament's usual upload storage, validation and path-tampering checks still apply.
 */
class AvatarUpload extends BaseFileUpload
{
    /** Width and height in pixels of the square the browser uploads (the server shrinks it further). */
    public const UPLOAD_EDGE = 400;

    protected string $view = 'filament.users.avatar-upload';

    protected function setUp(): void
    {
        parent::setUp();

        $this->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']);

        // Same rule the app sets on every FileUpload: only the record's own stored path or a fresh upload.
        $this->preventFilePathTampering();
    }

    /**
     * The stored picture's key and public URL, for the preview when the page opens.
     *
     * @return array{key: string|null, url: string|null}
     */
    public function currentPicture(): array
    {
        $key = array_key_first($this->getRawState() ?? []);

        if ($key === null) {
            return ['key' => null, 'url' => null];
        }

        $files = $this->getUploadedFiles() ?? [];

        return ['key' => (string) $key, 'url' => $files[$key]['url'] ?? null];
    }
}
