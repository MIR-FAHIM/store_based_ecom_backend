<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    // ✅ Mass assignable fields
    protected $fillable = [
        'name',
        'added_by',
        'user_id',
        'shop_id',
        'category_id',
        'brand_id',
        'photos',
        'thumbnail_img',
        'video_provider',
        'video_link',
        'tags',
        'description',
        'unit_price',
        'purchase_price',
        'variant_product',
        'attributes',
        'choice_options',
        'colors',
        'variations',
        'todays_deal',
        'published',
        'approved',
        'stock_visibility_state',
        'cash_on_delivery',
        'featured',
        'seller_featured',
        'current_stock',
        'unit',
        'weight',
        'min_qty',
        'low_stock_quantity',
        'discount',
        'discount_type',
        'discount_start_date',
        'discount_end_date',
        'starting_bid',
        'auction_start_date',
        'auction_end_date',
        'tax',
        'tax_type',
        'shipping_type',
        'shipping_cost',
        'is_quantity_multiplied',
        'est_shipping_days',
        'num_of_sale',
        'meta_title',
        'meta_description',
        'meta_img',
        'pdf',
        'slug',
        'refundable',
        'earn_point',
        'rating',
        'barcode',
        'digital',
        'auction_product',
        'file_name',
        'file_path',
        'external_link',
        'external_link_btn',
        'wholesale_product',
        'frequently_brought_selection_type',
    ];

    // ✅ Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    public function related()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    public function primaryImage()
    {
        return $this->belongsTo(Upload::class, 'thumbnail_img');
    }
    public function shop()
    {
        return $this->belongsTo(Shops::class, 'shop_id');
    }

    public function storeProducts()
    {
        return $this->hasMany(StoreProduct::class, 'product_id');
    }

    public function scopeFromActiveShop($query)
    {
        return $query->whereHas('shop', function ($shopQuery) {
            $shopQuery->where('status', 'active');
        });
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }
    public function productAttributes()
    {
        return $this->hasMany(ProductAttribute::class, 'product_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
public function productDiscount()
{
    return $this->hasOne(ProductDiscount::class, 'product_id');
}

    // ✅ Accessor example for full photo URL
    public function getThumbnailUrlAttribute()
    {
        return $this->resolveMediaUrl($this->thumbnail_img);
    }

    public function getPhotosArrayAttribute()
    {
        return $this->photos ? explode(',', $this->photos) : [];
    }
    public function averageReview()
    {
        return $this->hasOne(Review::class, 'product_id')
            ->selectRaw('product_id, AVG(star_count) as average_rating, COUNT(*) as review_count')
            ->groupBy('product_id');
    }

    public function getSeoAttribute(): array
    {
        $title = $this->cleanSeoText($this->meta_title ?: $this->name, 70);
        $description = $this->cleanSeoText($this->meta_description ?: $this->description, 160);
        if ($description === '') {
            $description = $title;
        }

        $images = $this->seoImages();
        $url = $this->productUrl();

        return [
            'title' => $title,
            'description' => $description,
            'image' => $images[0] ?? null,
            'images' => $images,
            'url' => $url,
            'canonical' => $url,
            'slug' => $this->slug,
            'keywords' => $this->seoKeywords(),
            'schema' => $this->seoSchema($description, $images, $url),
        ];
    }

    private function cleanSeoText($value, ?int $limit = null): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($limit && Str::length($text) > $limit) {
            return Str::limit($text, $limit, '');
        }

        return $text;
    }

    private function seoKeywords(): array
    {
        if (!$this->tags) {
            return [];
        }

        return collect(explode(',', (string) $this->tags))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->values()
            ->all();
    }

    private function seoImages(): array
    {
        $images = [];

        foreach ([$this->meta_img, $this->thumbnail_img] as $image) {
            $url = $this->resolveMediaUrl($image);
            if ($url) {
                $images[] = $url;
            }
        }

        if ($this->relationLoaded('images')) {
            foreach ($this->images as $productImage) {
                $uploadUrl = $productImage->relationLoaded('upload')
                    ? $this->urlFromUpload($productImage->upload)
                    : null;

                $url = $uploadUrl ?: $this->resolveMediaUrl($productImage->image);
                if ($url) {
                    $images[] = $url;
                }
            }
        }

        return collect($images)->filter()->unique()->values()->all();
    }

    private function productUrl(): string
    {
        $frontendUrl = rtrim((string) (config('services.frontend.url') ?: config('app.url')), '/');
        $productPath = '/' . trim((string) config('services.frontend.product_path', '/product'), '/');
        $identifier = $this->slug ?: (string) $this->id;

        return $frontendUrl . $productPath . '/' . rawurlencode($identifier);
    }

    private function seoSchema(string $description, array $images, string $url): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->name,
            'description' => $description,
            'image' => $images,
            'url' => $url,
            'sku' => (string) ($this->sku ?? $this->id),
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'BDT',
                'price' => number_format($this->seoPrice(), 2, '.', ''),
                'availability' => ((int) $this->current_stock > 0)
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'url' => $url,
            ],
        ];

        if ($this->relationLoaded('brand') && $this->brand) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $this->brand->name,
            ];
        }

        if ($this->relationLoaded('category') && $this->category) {
            $schema['category'] = $this->category->name;
        }

        $rating = $this->seoAggregateRating();
        if ($rating) {
            $schema['aggregateRating'] = $rating;
        }

        return $schema;
    }

    private function seoPrice(): float
    {
        $price = (float) ($this->unit_price ?? 0);
        $discount = (float) ($this->discount ?? 0);

        if ($discount <= 0) {
            return max(0, $price);
        }

        if ($this->discount_type === 'percent') {
            return max(0, $price - ($price * ($discount / 100)));
        }

        if ($this->discount_type === 'amount') {
            return max(0, $price - $discount);
        }

        return max(0, $price);
    }

    private function seoAggregateRating(): ?array
    {
        if (!$this->relationLoaded('averageReview') || !$this->averageReview) {
            return null;
        }

        $ratingValue = (float) ($this->averageReview->average_rating ?? 0);
        $reviewCount = (int) ($this->averageReview->review_count ?? 0);

        if ($ratingValue <= 0 || $reviewCount <= 0) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => round($ratingValue, 1),
            'reviewCount' => $reviewCount,
        ];
    }

    private function resolveMediaUrl($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (ctype_digit($value)) {
            $upload = null;

            if ((string) $this->thumbnail_img === $value && $this->relationLoaded('primaryImage')) {
                $upload = $this->primaryImage;
            }

            $upload = $upload ?: Upload::find((int) $value);

            return $this->urlFromUpload($upload);
        }

        return $this->storageMediaUrl($value);
    }

    private function urlFromUpload(?Upload $upload): ?string
    {
        if (!$upload) {
            return null;
        }

        if ($upload->external_link) {
            return filter_var($upload->external_link, FILTER_VALIDATE_URL)
                ? $upload->external_link
                : $this->storageMediaUrl($upload->external_link);
        }

        return $upload->file_name ? $this->storageMediaUrl($upload->file_name) : null;
    }

    private function storageMediaUrl(string $path): ?string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            return asset($path);
        }

        return asset('storage/' . $path);
    }
}
