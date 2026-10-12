<?php

namespace App\Domain\Marketing;

use App\Domain\Audit\AuditLogger;
use App\Models\MarketingAsset;
use App\Models\User;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stores marketing files on the private disk under a generated name and
 * serves them only through the API. The file type comes from the file's
 * content, never from its name or the browser's claim.
 */
class MarketingAssetService
{
    private const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?UploadedFile $file, User $actor): MarketingAsset
    {
        $asset = new MarketingAsset($attributes);
        $asset->created_by = $actor->id;

        if ($file) {
            // Sniff the content ourselves; the upload's own MIME type comes from the browser or the file name.
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath());
            [$kind, $extension] = MarketingAsset::TYPES[$mime] ?? [null, null];
            if (! $kind) {
                throw ValidationException::withMessages(['file' => 'Upload a JPG, PNG, WebP or GIF image, an MP4 video, a PDF or a ZIP file.']);
            }
            $asset->forceFill([
                'kind' => $kind,
                'mime' => $mime,
                'size_bytes' => $file->getSize(),
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'path' => $file->storeAs('marketing', Str::ulid()->toBase32().'.'.$extension, self::DISK),
            ]);
            if (! $asset->path) {
                throw ValidationException::withMessages(['file' => 'The file could not be saved. Please try again.']);
            }
        } else {
            if (blank($asset->caption)) {
                throw ValidationException::withMessages(['file' => 'Upload a file, or enter the text affiliates should post.']);
            }
            $asset->kind = 'text';
        }

        try {
            DB::transaction(function () use ($asset, $actor) {
                $asset->save();
                $this->audit->log('marketing_asset.created', $asset, ['title' => $asset->title, 'kind' => $asset->kind, 'size_bytes' => $asset->size_bytes], $actor);
            });
        } catch (Throwable $e) {
            if ($asset->path) {
                Storage::disk(self::DISK)->delete($asset->path);
            }
            throw $e;
        }

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(MarketingAsset $asset, array $attributes, User $actor): MarketingAsset
    {
        $asset->fill($attributes);
        if ($asset->kind === 'text' && blank($asset->caption)) {
            throw ValidationException::withMessages(['caption' => 'A text asset needs its text.']);
        }

        DB::transaction(function () use ($asset, $actor) {
            $changes = array_keys($asset->getDirty());
            $asset->save();
            $this->audit->log('marketing_asset.updated', $asset, ['changed' => $changes], $actor);
        });

        return $asset;
    }

    public function delete(MarketingAsset $asset, User $actor): void
    {
        $path = $asset->path;
        DB::transaction(function () use ($asset, $actor) {
            $this->audit->log('marketing_asset.deleted', $asset, ['title' => $asset->title, 'kind' => $asset->kind, 'downloads' => $asset->download_count], $actor);
            $asset->delete();
        });
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Images may be shown inline for previews; everything else is a download.
     */
    public function respond(MarketingAsset $asset, bool $inline = false): StreamedResponse
    {
        abort_unless($asset->hasFile() && Storage::disk(self::DISK)->exists($asset->path), 404);

        $inline = $inline && $asset->kind === 'image';
        $headers = [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ];

        return $inline
            ? Storage::disk(self::DISK)->response($asset->path, $asset->downloadName(), $headers, 'inline')
            : Storage::disk(self::DISK)->download($asset->path, $asset->downloadName(), $headers);
    }

    public function countDownload(MarketingAsset $asset): void
    {
        MarketingAsset::query()->whereKey($asset->id)->increment('download_count');
    }
}
