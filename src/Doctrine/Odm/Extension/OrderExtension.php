<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Doctrine\Odm\Extension;

use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Doctrine\Common\PropertyHelperTrait;
use ApiPlatform\Doctrine\Odm\PropertyHelperTrait as MongoDbOdmPropertyHelperTrait;
use ApiPlatform\Exception\OperationNotFoundException;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\Aggregation\Stage\Sort;
use Doctrine\Persistence\ManagerRegistry;
use OutOfRangeException;

/**
 * Applies selected ordering while querying resource collection.
 *
 * @experimental
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Samuel ROZE <samuel.roze@gmail.com>
 * @author Vincent Chalamon <vincentchalamon@gmail.com>
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class OrderExtension implements AggregationCollectionExtensionInterface
{
    use MongoDbOdmPropertyHelperTrait;
    use PropertyHelperTrait;

    private $order;
    private $resourceMetadataFactory;
    private $managerRegistry;

    public function __construct(string $order = null, $resourceMetadataFactory = null, ManagerRegistry $managerRegistry = null)
    {
        if ($resourceMetadataFactory && !$resourceMetadataFactory instanceof ResourceMetadataCollectionFactoryInterface) {
            trigger_deprecation('api-platform/core', '2.7', sprintf('Use "%s" instead of "%s".', ResourceMetadataCollectionFactoryInterface::class, ResourceMetadataFactoryInterface::class));
        }

        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->order = $order;
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * {@inheritdoc}
     */
    public function applyToCollection(Builder $aggregationBuilder, string $resourceClass, string $operationName = null, array &$context = [])
    {
        // Do not apply order if already defined on $aggregationBuilder
        if ($this->hasSortStage($aggregationBuilder)) {
            return;
        }

        $classMetaData = $this->getClassMetadata($resourceClass);
        $identifiers = $classMetaData->getIdentifier();
        if (null !== $this->resourceMetadataFactory) {
            if ($this->resourceMetadataFactory instanceof ResourceMetadataCollectionFactoryInterface) {
                if (isset($context['operation'])) {
                    $defaultOrder = $context['operation']->getOrder() ?? [];
                } else {
                    $metadata = $this->resourceMetadataFactory->create($resourceClass);
                    try {
                        $defaultOrder = $metadata->getOperation($operationName)->getOrder();
                    } catch (OperationNotFoundException $e) {
                        $defaultOrder = $metadata->getOperation(null, true)->getOrder();
                    }
                }
            } else {
                $defaultOrder = $this->resourceMetadataFactory->create($resourceClass)->getAttribute('order');
            }

            if ($defaultOrder) {
                foreach ($defaultOrder as $field => $order) {
                    if (\is_int($field)) {
                        // Default direction
                        $field = $order;
                        $order = 'ASC';
                    }

                    if ($this->isPropertyNested($field, $resourceClass)) {
                        [$field] = $this->addLookupsForNestedProperty($field, $aggregationBuilder, $resourceClass);
                    }
                    $aggregationBuilder->sort(
                        $context['mongodb_odm_sort_fields'] = ($context['mongodb_odm_sort_fields'] ?? []) + [$field => $order]
                    );
                }

                return;
            }
        }

        if (null !== $this->order) {
            foreach ($identifiers as $identifier) {
                $aggregationBuilder->sort(
                    $context['mongodb_odm_sort_fields'] = ($context['mongodb_odm_sort_fields'] ?? []) + [$identifier => $this->order]
                );
            }
        }
    }

    protected function getManagerRegistry(): ManagerRegistry
    {
        return $this->managerRegistry;
    }

    private function hasSortStage(Builder $aggregationBuilder): bool
    {
        $shouldStop = false;
        $index = 0;

        do {
            try {
                if ($aggregationBuilder->getStage($index) instanceof Sort) {
                    // If at least one stage is sort, then it has sorting
                    return true;
                }
            } catch (OutOfRangeException $outOfRangeException) {
                // There is no more stages on the aggregation builder
                $shouldStop = true;
            }

            ++$index;
        } while (!$shouldStop);

        // No stage was sort, and we iterated through all stages
        return false;
    }
}
