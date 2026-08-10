<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Presenter;

/**
 * Opt-in convenience view that reshapes the verbose `channels/detail` payload into a flat, storefront-friendly array.
 *
 * The `sales` service's `channels/detail` endpoint returns a deeply nested,
 * relational payload: each product is wrapped in a channel-assignment record,
 * its purchasable variants live in one array while their payment-aggregator
 * mappings live in a parallel `product_mappings` array, and shared references
 * (the integration id, the channel id, the product id) are repeated on every
 * row. Consuming that shape directly in a storefront means re-joining variants
 * to mappings on every render.
 *
 * `ChannelDetail` performs that join once and returns a cleaned associative
 * array: `_id` keys become `id`, each variant (or a standalone `single` price)
 * carries its joined `mapping` inline, embedded references collapse to
 * `{id, name}`, and the single embedded teleform is unwrapped to an object.
 * The `payment_processor.config` block is preserved verbatim because a
 * server-side storefront needs it to call the aggregator.
 *
 * This presenter is **opt-in and is never the API contract.** It is a
 * convenience layer scoped to a single endpoint, not auto-generated per
 * resource. {@see \AsterMD\Sdk\Resource\Channels::details()} still returns the
 * faithful {@see \AsterMD\Sdk\Response}; the raw payload is always one call
 * away via `$response->data()`. Reach for this only when you want the joined
 * shape:
 *
 * ```php
 * $response = $client->channels->details($channelId);
 *
 * $raw   = $response->data();                       // faithful API mirror
 * $clean = ChannelDetail::from($response->data());  // joined storefront view
 * ```
 *
 * @phpstan-type CleanArray array<string, mixed>
 */
final class ChannelDetail
{
    private function __construct()
    {
    }

    /**
     * Reshapes a decoded `channels/detail` `data` payload into the flat storefront view.
     *
     * Pass the contents of `$response->data()` from
     * {@see \AsterMD\Sdk\Resource\Channels::details()}. The method is pure: it
     * performs no I/O and does not mutate its input. Missing keys degrade to
     * `null` (or an empty list) rather than throwing, so a partial payload
     * still yields a well-formed result.
     *
     * @param array<string, mixed> $data the `data` envelope contents from a `channels/detail` response
     *
     * @return array<string, mixed> the cleaned channel view: `id`, `name`, `description`, `status`,
     *                               `type`, `auto_inc_id`, `payment_processor`, and a flattened `products` list
     */
    public static function from(array $data): array
    {
        $products = [];
        foreach (self::toArray($data['products'] ?? null) as $entry) {
            $products[] = self::product(self::toArray($entry));
        }

        return [
            'id' => $data['_id'] ?? null,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? null,
            'type' => $data['type'] ?? null,
            'auto_inc_id' => $data['auto_inc_id'] ?? null,
            'payment_processor' => self::processor($data['payment_processor'] ?? null),
            'products' => $products,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function processor(mixed $pp): ?array
    {
        if (!is_array($pp)) {
            return null;
        }

        return [
            'id' => $pp['_id'] ?? null,
            'name' => $pp['name'] ?? null,
            'provider' => $pp['provider_category'] ?? null,
            'type' => $pp['type'] ?? null,
            'config' => $pp['config'] ?? null,
        ];
    }

    /**
     * Resolves a single channel-assignment entry: builds the variant→mapping
     * lookup from its `product_mappings`, then normalises the embedded product.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function product(array $entry): array
    {
        $byVariant = [];
        foreach (self::toArray($entry['product_mappings'] ?? null) as $pm) {
            $pm = self::toArray($pm);
            $variantId = self::toStringOrNull($pm['variant_id'] ?? null);
            if ($variantId !== null) {
                $byVariant[$variantId] = self::mapping($pm);
            }
        }

        return self::coreProduct(
            self::toArray($entry['product'] ?? null),
            $byVariant,
            array_values(self::toArray($entry['add_ons'] ?? null)),
        );
    }

    /**
     * Normalises a product-like node (a product or an add-on), joining each
     * variant — and a standalone `single` price — to its payment mapping.
     *
     * @param array<array-key, mixed>             $p         the product or add-on node
     * @param array<string, array<string, mixed>> $byVariant mapping lookup keyed by source variant id
     * @param list<mixed>                         $addOns    raw add-on nodes to normalise recursively
     *
     * @return array<string, mixed>
     */
    private static function coreProduct(array $p, array $byVariant, array $addOns): array
    {
        $id = self::toStringOrNull($p['_id'] ?? null);

        $variants = [];
        foreach (self::toArray($p['variants'] ?? null) as $variant) {
            $variant = self::toArray($variant);
            $variantId = self::toStringOrNull($variant['_id'] ?? null);
            $variants[] = self::variant($variant, self::lookup($byVariant, $variantId));
        }

        $singleRows = self::toArray($p['single'] ?? null);
        $single = $singleRows === []
            ? null
            : self::single(self::toArray(reset($singleRows)), self::lookup($byVariant, $id));

        $normalisedAddOns = [];
        foreach ($addOns as $addOn) {
            $normalisedAddOns[] = self::coreProduct(self::toArray($addOn), [], []);
        }

        return [
            'id' => $p['_id'] ?? null,
            'name' => $p['name'] ?? null,
            'type' => $p['type'] ?? null,
            'sku' => $p['sku'] ?? null,
            'status' => $p['status'] ?? null,
            'visibility' => $p['visibility'] ?? null,
            'image' => $p['image'] ?? null,
            'description_long' => $p['description_long'] ?? null,
            'description_short' => $p['description_short'] ?? null,
            'restrict_multiple' => $p['restrict_multiple'] ?? null,
            'min_buy_qty' => $p['min_buy_qty'] ?? null,
            'max_buy_qty' => $p['max_buy_qty'] ?? null,
            'stock' => $p['stock'] ?? null,
            'categories' => self::refs($p['categories'] ?? null),
            'condition_treated' => self::refs($p['condition_treated'] ?? null),
            'teleforms' => self::firstRefOrNull($p['teleforms'] ?? null),
            'labtest' => self::refs($p['labtest'] ?? null),
            'add_ons' => $normalisedAddOns,
            'single' => $single,
            'variants' => $variants,
        ];
    }

    /**
     * @param array<array-key, mixed>   $v
     * @param array<string, mixed>|null $mapping
     *
     * @return array<string, mixed>
     */
    private static function variant(array $v, ?array $mapping): array
    {
        return [
            'id' => $v['_id'] ?? null,
            'name' => $v['name'] ?? null,
            'sku' => $v['sku'] ?? null,
            'price' => $v['default_price'] ?? null,
            'intro_price' => $v['intro_price'] ?? null,
            'sale_price' => $v['sale_price'] ?? null,
            'sale_start' => $v['sale_start'] ?? null,
            'sale_end' => $v['sale_end'] ?? null,
            'mapping' => $mapping,
        ];
    }

    /**
     * A non-variant ("single") price row. Carries no id/name/sku — those are
     * meaningless for a product that is sold as one fixed SKU.
     *
     * @param array<array-key, mixed>   $row
     * @param array<string, mixed>|null $mapping
     *
     * @return array<string, mixed>
     */
    private static function single(array $row, ?array $mapping): array
    {
        return [
            'price' => $row['default_price'] ?? null,
            'intro_price' => $row['intro_price'] ?? null,
            'sale_price' => $row['sale_price'] ?? null,
            'sale_start' => $row['sale_start'] ?? null,
            'sale_end' => $row['sale_end'] ?? null,
            'mapping' => $mapping,
        ];
    }

    /**
     * Flattens a `product_mappings` entry. The mapping's `type` is taken from
     * the integration block (e.g. `payment_aggregator`); the inner mapping's
     * own `type` (the provider slug, e.g. `vrio`) is dropped as redundant with
     * `payment_processor.provider`.
     *
     * @param array<array-key, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private static function mapping(array $pm): array
    {
        $integration = self::toArray($pm['integration'] ?? null);
        $out = ['type' => $integration['type'] ?? null];

        foreach (self::toArray($pm['mapping'] ?? null) as $key => $value) {
            if ($key !== 'type') {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /**
     * Normalises a list of embedded references to `{id, name, ...}`. String
     * references (the API emits bare id strings in some places) pass through.
     *
     * @return list<mixed>
     */
    private static function refs(mixed $list): array
    {
        $out = [];
        foreach (self::toArray($list) as $item) {
            $out[] = self::ref($item);
        }

        return $out;
    }

    /**
     * Unwraps the first reference of a list to a single object, or null when
     * the list is empty. Used for `teleforms`, which carries at most one entry.
     */
    private static function firstRefOrNull(mixed $list): mixed
    {
        return self::refs($list)[0] ?? null;
    }

    /**
     * Renames a single reference's `_id` to `id`, preserving its other keys. A
     * non-array value (e.g. a bare id string) is returned unchanged.
     */
    private static function ref(mixed $r): mixed
    {
        if (!is_array($r)) {
            return $r;
        }

        $out = ['id' => $r['_id'] ?? null];
        foreach ($r as $key => $value) {
            if ($key !== '_id') {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $map
     *
     * @return array<string, mixed>|null
     */
    private static function lookup(array $map, ?string $key): ?array
    {
        return $key !== null ? ($map[$key] ?? null) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function toStringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
