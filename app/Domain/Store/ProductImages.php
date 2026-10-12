<?php

namespace App\Domain\Store;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
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
 * Product photos. The type comes from the file's content; files are stored
 * on the private disk under a new name each time, so the public address can
 * be cached for a year.
 */
class ProductImages
{
    public const DISK = 'local';

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const MAX_SIDE = 6000;

    public function __construct(private readonly AuditLogger $audit) {}

    public function replace(Product $product, UploadedFile $file, User $actor): Product
    {
        $path = (string) $file->getRealPath();
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = self::TYPES[$mime] ?? null;
        $size = $extension ? @getimagesize($path) : false;
        if (! $extension || ! $size) {
            throw ValidationException::withMessages(['image' => 'Upload a JPG, PNG or WebP photo.']);
        }
        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw ValidationException::withMessages(['image' => 'The photo is too large. Keep each side under '.self::MAX_SIDE.' pixels.']);
        }

        $stored = $file->storeAs('products', Str::ulid()->toBase32().'.'.$extension, self::DISK);
        if (! $stored) {
            throw ValidationException::withMessages(['image' => 'The photo could not be saved. Please try again.']);
        }

        $old = $product->image_path;
        try {
            DB::transaction(function () use ($product, $stored, $actor, $size) {
                $product->forceFill(['image_path' => $stored])->save();
                $this->audit->log('product.image_changed', $product, ['width' => $size[0], 'height' => $size[1]], $actor);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);
            throw $e;
        }
        if ($old) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $product;
    }

    public function remove(Product $product, User $actor): Product
    {
        $old = $product->image_path;
        if (! $old) {
            return $product;
        }
        DB::transaction(function () use ($product, $actor) {
            $product->forceFill(['image_path' => null])->save();
            $this->audit->log('product.image_removed', $product, [], $actor);
        });
        Storage::disk(self::DISK)->delete($old);

        return $product;
    }

    /** Serves a stored photo to anyone; only paths a product currently uses are served. */
    public function serve(string $file): StreamedResponse
    {
        $path = 'products/'.$file;
        abort_unless(Product::query()->where('image_path', $path)->exists() && Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, $file, [
            'Content-Type' => array_search(pathinfo($file, PATHINFO_EXTENSION), self::TYPES, true) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
