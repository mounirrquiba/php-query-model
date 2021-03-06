<?php

namespace MKCG\Examples\SocialNetwork\Schema;

use MKCG\Model\GenericSchema;
use MKCG\Model\GenericEntity;
use MKCG\Model\FieldInterface;
use MKCG\Model\DBAL\Result;

class Order extends GenericSchema
{
    protected $driverName = 'csv';
    protected $entityClass = GenericEntity::class;
    protected $primaryKeys = ['id'];

    protected $types = [
        'default' => [
            'id',
            'id_user',
            'firstname',
            'lastname',
            'credit_card_type',
            'credit_card_number',
            'price',
            'vat',
            'currency',
            'product_ids',
        ],
    ];

    protected function initTransformers()
    {
        $this->addFieldTransformer('product_ids', 'csv');

        return $this;
    }

    protected function initRelations()
    {
        $this
            ->addRelation('customer', User::class, 'id_user', 'id', false)
            ->addRelationResolver('products', Product::class, [ static::class , 'extract' ], [ static::class , 'resolve' ], true)
        ;

        return $this;
    }

    protected function initFields()
    {
        return $this
            ->setFieldDefinition('price', FieldInterface::TYPE_INT)
            ->setFieldDefinition('vat', FieldInterface::TYPE_FLOAT)
        ;
    }

    public static function extract(Result $result)
    {
        $productIds = array_map([self::class, 'extractProductIds'], $result->getContent());
        $productIds = array_merge(...$productIds);
        $productIds = array_unique($productIds);

        return ['_id' => $productIds ];
    }

    private static function extractProductIds(\ArrayAccess $order)
    {
        $productIds = array_map(function($id) {
            return (int) $id;
        }, $order['product_ids']);

        return array_unique($productIds);
    }

    public static function resolve(Result $resultOrders, Result $resultProducts, bool $isCollection, string $alias)
    {
        $products = [];

        foreach ($resultProducts->getContent() as $product) {
            $products[$product['_id']] = $product;
        }

        foreach ($resultOrders->getContent() as $order) {
            $orderById[$order['id']] = $order;

            $productIds = self::extractProductIds($order);
            $productIds = array_filter($productIds, function($id) use ($products) {
                return isset($products[$id]);
            });

            $orderedProducts = array_map(function($id) use ($products) {
                return $products[$id];
            }, $productIds);

            if (empty($orderedProducts)) {
                continue;
            }

            $order[$alias] = $isCollection
                ? $orderedProducts
                : $orderedProducts[0];
        }
    }
}
