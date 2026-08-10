<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Tests\Unit\Presenter;

use AsterMD\Sdk\Presenter\ChannelDetail;
use PHPUnit\Framework\TestCase;

final class ChannelDetailTest extends TestCase
{
    public function testMapsChannelMetadataAndRenamesIdKey(): void
    {
        $out = ChannelDetail::from($this->sample());

        self::assertSame('chan1', $out['id']);
        self::assertSame('Flow 1', $out['name']);
        self::assertSame('', $out['description']);
        self::assertSame(1, $out['status']);
        self::assertSame('api', $out['type']);
        self::assertSame(157, $out['auto_inc_id']);
        self::assertArrayNotHasKey('_id', $out);
    }

    public function testKeepsPaymentProcessorConfigIntactAndRenamesProvider(): void
    {
        $out = ChannelDetail::from($this->sample());

        self::assertSame([
            'id' => 'pp1',
            'name' => 'Vrio',
            'provider' => 'vrio',
            'type' => 'payment_aggregation',
            'config' => [
                'profile_name' => 'Vrio - F1',
                'api_key' => 'secret-token',
                'connection_id' => '1',
            ],
        ], $out['payment_processor']);
    }

    public function testStandardProductExposesSingleObjectWithoutIdNameSku(): void
    {
        $std = $this->productNamed(ChannelDetail::from($this->sample()), 'Electrolyte Powder');

        self::assertSame([
            'price' => 19,
            'intro_price' => null,
            'sale_price' => null,
            'sale_start' => null,
            'sale_end' => null,
            'mapping' => [
                'type' => 'payment_aggregator',
                'offer_id' => '340',
                'offer_name' => 'One-Time Sale',
                'product_id' => '2844',
                'variant_id' => '2844',
                'product_name' => 'Electrolyte Powder (30 servings)',
            ],
        ], $std['single']);
        self::assertSame([], $std['variants']);
    }

    public function testStandardProductWithoutTeleformsHasNullTeleforms(): void
    {
        $std = $this->productNamed(ChannelDetail::from($this->sample()), 'Electrolyte Powder');

        self::assertNull($std['teleforms']);
    }

    public function testFlattensProductWrapperAndNormalisesNestedRefs(): void
    {
        $std = $this->productNamed(ChannelDetail::from($this->sample()), 'Electrolyte Powder');

        self::assertSame('prodStd', $std['id']);
        self::assertArrayNotHasKey('channel_id', $std);
        self::assertArrayNotHasKey('product_id', $std);
        self::assertSame([['id' => 'cat1', 'name' => 'Supplement']], $std['categories']);
        self::assertSame([['id' => 'cond1', 'name' => 'HGH Deficiency']], $std['condition_treated']);
    }

    public function testPrescriptionProductNullsSingleAndJoinsMappingsOntoVariants(): void
    {
        $rx = $this->productNamed(ChannelDetail::from($this->sample()), 'Tadalafil');

        self::assertNull($rx['single']);
        // v1 carries its joined mapping; v2 is intentionally unmapped → null.
        self::assertSame([
            [
                'id' => 'v1',
                'name' => '5 mg daily',
                'sku' => null,
                'price' => 49,
                'intro_price' => null,
                'sale_price' => null,
                'sale_start' => null,
                'sale_end' => null,
                'mapping' => [
                    'type' => 'payment_aggregator',
                    'offer_id' => '337',
                    'offer_name' => 'Monthly / 30-Day Recurring',
                    'product_id' => '2838',
                    'variant_id' => '2838',
                    'product_name' => 'Tadalafil 5 mg Daily',
                ],
            ],
            [
                'id' => 'v2',
                'name' => '10 mg PRN',
                'sku' => null,
                'price' => 49,
                'intro_price' => null,
                'sale_price' => null,
                'sale_start' => null,
                'sale_end' => null,
                'mapping' => null,
            ],
        ], $rx['variants']);
    }

    public function testTeleformIsSingleObjectNotArray(): void
    {
        $rx = $this->productNamed(ChannelDetail::from($this->sample()), 'Tadalafil');

        self::assertSame(['id' => 'tf1', 'name' => 'Titan-x'], $rx['teleforms']);
    }

    public function testAddOnsAreRecursivelyNormalised(): void
    {
        $rx = $this->productNamed(ChannelDetail::from($this->sample()), 'Tadalafil');

        self::assertSame([
            [
                'id' => 'addon1',
                'name' => 'Syringe',
                'type' => 'add_on',
                'sku' => 'S',
                'status' => 1,
                'visibility' => 'Public',
                'image' => null,
                'description_long' => null,
                'description_short' => null,
                'restrict_multiple' => false,
                'min_buy_qty' => null,
                'max_buy_qty' => null,
                'stock' => null,
                'categories' => [],
                'condition_treated' => ['cond2'],
                'teleforms' => null,
                'labtest' => [],
                'add_ons' => [],
                'single' => [
                    'price' => 0,
                    'intro_price' => null,
                    'sale_price' => null,
                    'sale_start' => null,
                    'sale_end' => null,
                    'mapping' => null,
                ],
                'variants' => [],
            ],
        ], $rx['add_ons']);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function productNamed(array $detail, string $name): array
    {
        /** @var list<array<string, mixed>> $products */
        $products = $detail['products'];
        foreach ($products as $product) {
            if (($product['name'] ?? null) === $name) {
                return $product;
            }
        }

        self::fail("Product not found: {$name}");
    }

    /**
     * Representative `channels/detail` payload (the `data` envelope contents)
     * exercising every transformation: a standard product with a `single`
     * price, a prescription product with variants (one mapped, one not), a
     * single embedded teleform, and a recursively-normalised add-on.
     *
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        return [
            '_id' => 'chan1',
            'name' => 'Flow 1',
            'description' => '',
            'status' => 1,
            'type' => 'api',
            'auto_inc_id' => 157,
            'payment_processor' => [
                '_id' => 'pp1',
                'name' => 'Vrio',
                'provider_category' => 'vrio',
                'status' => 1,
                'type' => 'payment_aggregation',
                'config' => [
                    'profile_name' => 'Vrio - F1',
                    'api_key' => 'secret-token',
                    'connection_id' => '1',
                ],
            ],
            'products' => [
                [
                    '_id' => 'assign1',
                    'channel_id' => 'chan1',
                    'product_id' => 'prodStd',
                    'variant_ids' => [],
                    'add_ons' => [],
                    'product' => [
                        '_id' => 'prodStd',
                        'name' => 'Electrolyte Powder',
                        'type' => 'standard',
                        'sku' => null,
                        'status' => 1,
                        'visibility' => 'Public',
                        'image' => null,
                        'description_long' => 'desc',
                        'description_short' => null,
                        'restrict_multiple' => false,
                        'min_buy_qty' => null,
                        'max_buy_qty' => null,
                        'stock' => null,
                        'categories' => [['_id' => 'cat1', 'name' => 'Supplement']],
                        'condition_treated' => [['_id' => 'cond1', 'name' => 'HGH Deficiency']],
                        'teleforms' => [],
                        'labtest' => [],
                        'variants' => [],
                        'single' => [
                            [
                                'intro_price' => null,
                                'default_price' => 19,
                                'sale_price' => null,
                                'sale_start' => null,
                                'sale_end' => null,
                            ],
                        ],
                    ],
                    'product_mappings' => [
                        [
                            '_id' => 'pm1',
                            'type' => 'vrio',
                            'variant_id' => 'prodStd',
                            'integration' => ['type' => 'payment_aggregator', 'integration_id' => 'pp1'],
                            'mapping' => [
                                'type' => 'vrio',
                                'offer_id' => '340',
                                'offer_name' => 'One-Time Sale',
                                'product_id' => '2844',
                                'variant_id' => '2844',
                                'product_name' => 'Electrolyte Powder (30 servings)',
                            ],
                        ],
                    ],
                ],
                [
                    '_id' => 'assign2',
                    'channel_id' => 'chan1',
                    'product_id' => 'prodRx',
                    'variant_ids' => ['v1', 'v2'],
                    'add_ons' => [
                        [
                            '_id' => 'addon1',
                            'name' => 'Syringe',
                            'type' => 'add_on',
                            'sku' => 'S',
                            'status' => 1,
                            'visibility' => 'Public',
                            'image' => null,
                            'description_long' => null,
                            'description_short' => null,
                            'restrict_multiple' => false,
                            'min_buy_qty' => null,
                            'max_buy_qty' => null,
                            'stock' => null,
                            'categories' => [],
                            'condition_treated' => ['cond2'],
                            'teleforms' => [],
                            'labtest' => null,
                            'variants' => [],
                            'single' => [
                                [
                                    'intro_price' => null,
                                    'default_price' => 0,
                                    'sale_price' => null,
                                    'sale_start' => null,
                                    'sale_end' => null,
                                ],
                            ],
                        ],
                    ],
                    'product' => [
                        '_id' => 'prodRx',
                        'name' => 'Tadalafil',
                        'type' => 'prescription',
                        'sku' => null,
                        'status' => 1,
                        'visibility' => 'Public',
                        'image' => 'img.png',
                        'description_long' => null,
                        'description_short' => null,
                        'restrict_multiple' => true,
                        'min_buy_qty' => null,
                        'max_buy_qty' => null,
                        'stock' => null,
                        'categories' => [['_id' => 'cat2', 'name' => 'Performance']],
                        'condition_treated' => [['_id' => 'cond3', 'name' => 'ED']],
                        'teleforms' => [['_id' => 'tf1', 'name' => 'Titan-x']],
                        'labtest' => [],
                        'single' => [],
                        'variants' => [
                            [
                                '_id' => 'v1',
                                'name' => '5 mg daily',
                                'sku' => null,
                                'description' => null,
                                'intro_price' => null,
                                'default_price' => 49,
                                'sale_price' => null,
                                'sale_start' => null,
                                'sale_end' => null,
                            ],
                            [
                                '_id' => 'v2',
                                'name' => '10 mg PRN',
                                'sku' => null,
                                'description' => null,
                                'intro_price' => null,
                                'default_price' => 49,
                                'sale_price' => null,
                                'sale_start' => null,
                                'sale_end' => null,
                            ],
                        ],
                    ],
                    'product_mappings' => [
                        [
                            '_id' => 'pm2',
                            'type' => 'vrio',
                            'variant_id' => 'v1',
                            'integration' => ['type' => 'payment_aggregator', 'integration_id' => 'pp1'],
                            'mapping' => [
                                'type' => 'vrio',
                                'offer_id' => '337',
                                'offer_name' => 'Monthly / 30-Day Recurring',
                                'product_id' => '2838',
                                'variant_id' => '2838',
                                'product_name' => 'Tadalafil 5 mg Daily',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
