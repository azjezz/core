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

namespace ApiPlatform\GraphQl\Resolver\Stage;

use ApiPlatform\GraphQl\Resolver\Util\IdentifierTrait;
use ApiPlatform\GraphQl\Serializer\ItemNormalizer;
use ApiPlatform\GraphQl\Serializer\SerializerContextBuilderInterface;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\GraphQl\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Serialize stage of GraphQL resolvers.
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class SerializeStage implements SerializeStageInterface
{
    use IdentifierTrait;

    public function __construct(private readonly NormalizerInterface $normalizer, private readonly SerializerContextBuilderInterface $serializerContextBuilder, private readonly Pagination $pagination)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke($itemOrCollection, string $resourceClass, Operation $operation, array $context): ?array
    {
        $isCollection = $context['is_collection'] || $operation instanceof CollectionOperationInterface;
        $isMutation = $context['is_mutation'];
        $isSubscription = $context['is_subscription'];
        $shortName = $operation->getShortName();
        $operationName = $operation->getName();

        if (!($operation->canSerialize() ?? true)) {
            if ($isCollection) {
                if ($this->pagination->isGraphQlEnabled($operation, $context)) {
                    return 'cursor' === $this->pagination->getGraphQlPaginationType($operation) ?
                        $this->getDefaultCursorBasedPaginatedData() :
                        $this->getDefaultPageBasedPaginatedData();
                }

                return [];
            }

            if ($isMutation) {
                return $this->getDefaultMutationData($context);
            }

            if ($isSubscription) {
                return $this->getDefaultSubscriptionData($context);
            }

            return null;
        }

        $normalizationContext = $this->serializerContextBuilder->create($resourceClass, $operation, $context, true);

        $data = null;
        if (!$isCollection) {
            if ($isMutation && 'delete' === $operationName) {
                $data = ['id' => $this->getIdentifierFromContext($context)];
            } else {
                $data = $this->normalizer->normalize($itemOrCollection, ItemNormalizer::FORMAT, $normalizationContext);
            }
        }

        if ($isCollection && is_iterable($itemOrCollection)) {
            if (!$this->pagination->isGraphQlEnabled($operation, $context)) {
                $data = [];
                foreach ($itemOrCollection as $index => $object) {
                    $data[$index] = $this->normalizer->normalize($object, ItemNormalizer::FORMAT, $normalizationContext);
                }
            } else {
                $data = 'cursor' === $this->pagination->getGraphQlPaginationType($operation) ?
                    $this->serializeCursorBasedPaginatedCollection($itemOrCollection, $normalizationContext, $context) :
                    $this->serializePageBasedPaginatedCollection($itemOrCollection, $normalizationContext);
            }
        }

        if (null !== $data && !\is_array($data)) {
            throw new \UnexpectedValueException('Expected serialized data to be a nullable array.');
        }

        if ($isMutation || $isSubscription) {
            $wrapFieldName = lcfirst($shortName);

            return [$wrapFieldName => $data] + ($isMutation ? $this->getDefaultMutationData($context) : $this->getDefaultSubscriptionData($context));
        }

        return $data;
    }

    /**
     * @throws \LogicException
     * @throws \UnexpectedValueException
     *
     * @return array{totalCount: float|int, edges: array<mixed, array{node: mixed[]|bool|float|int|string|null, cursor: string}>, pageInfo: array{startCursor: string|null, endCursor: string|null, hasNextPage: bool, hasPreviousPage: bool}}
     */
    private function serializeCursorBasedPaginatedCollection(iterable $collection, array $normalizationContext, array $context): array
    {
        $args = $context['args'];

        if (!($collection instanceof PartialPaginatorInterface)) {
            throw new \LogicException(sprintf('Collection returned by the collection data provider must implement %s or %s.', PaginatorInterface::class, PartialPaginatorInterface::class));
        }

        $offset = 0;
        $totalItems = 1; // For partial pagination, always consider there is at least one item.
        $nbPageItems = $collection->count();
        if (isset($args['after'])) {
            $after = base64_decode($args['after'], true);
            if (false === $after || '' === $args['after']) {
                throw new \UnexpectedValueException('' === $args['after'] ? 'Empty cursor is invalid' : sprintf('Cursor %s is invalid', $args['after']));
            }
            $offset = 1 + (int) $after;
        }

        if ($collection instanceof PaginatorInterface) {
            $totalItems = $collection->getTotalItems();

            if (isset($args['before'])) {
                $before = base64_decode($args['before'], true);
                if (false === $before || '' === $args['before']) {
                    throw new \UnexpectedValueException('' === $args['before'] ? 'Empty cursor is invalid' : sprintf('Cursor %s is invalid', $args['before']));
                }
                $offset = (int) $before - $nbPageItems;
            }
            if (isset($args['last']) && !isset($args['before'])) {
                $offset = $totalItems - $args['last'];
            }
        }

        $offset = 0 > $offset ? 0 : $offset;

        $data = $this->getDefaultCursorBasedPaginatedData();
        if ($totalItems > 0) {
            $data['pageInfo']['startCursor'] = base64_encode((string) $offset);
            $end = $offset + $nbPageItems - 1;
            $data['pageInfo']['endCursor'] = base64_encode((string) ($end >= 0 ? $end : 0));
            $data['pageInfo']['hasPreviousPage'] = $offset > 0;
            if ($collection instanceof PaginatorInterface) {
                $data['totalCount'] = $totalItems;
                $itemsPerPage = $collection->getItemsPerPage();
                $data['pageInfo']['hasNextPage'] = (float) ($itemsPerPage > 0 ? $offset % $itemsPerPage : $offset) + $itemsPerPage * $collection->getCurrentPage() < $totalItems;
            }
        }

        $index = 0;
        foreach ($collection as $object) {
            $data['edges'][$index] = [
                'node' => $this->normalizer->normalize($object, ItemNormalizer::FORMAT, $normalizationContext),
                'cursor' => base64_encode((string) ($index + $offset)),
            ];
            ++$index;
        }

        return $data;
    }

    /**
     * @throws \LogicException
     *
     * @return array{collection: bool[]|float[]|int[]|string[]|mixed[][]|null[], paginationInfo: array{itemsPerPage: float, totalCount: float, lastPage: float}}
     */
    private function serializePageBasedPaginatedCollection(iterable $collection, array $normalizationContext): array
    {
        if (!($collection instanceof PaginatorInterface)) {
            throw new \LogicException(sprintf('Collection returned by the collection data provider must implement %s.', PaginatorInterface::class));
        }

        $data = $this->getDefaultPageBasedPaginatedData();
        $data['paginationInfo']['totalCount'] = $collection->getTotalItems();
        $data['paginationInfo']['lastPage'] = $collection->getLastPage();
        $data['paginationInfo']['itemsPerPage'] = $collection->getItemsPerPage();

        foreach ($collection as $object) {
            $data['collection'][] = $this->normalizer->normalize($object, ItemNormalizer::FORMAT, $normalizationContext);
        }

        return $data;
    }

    /**
     * @return array{totalCount: float, edges: never[], pageInfo: array{startCursor: null, endCursor: null, hasNextPage: false, hasPreviousPage: false}}
     */
    private function getDefaultCursorBasedPaginatedData(): array
    {
        return ['totalCount' => 0., 'edges' => [], 'pageInfo' => ['startCursor' => null, 'endCursor' => null, 'hasNextPage' => false, 'hasPreviousPage' => false]];
    }

    /**
     * @return array{collection: never[], paginationInfo: array{itemsPerPage: float, totalCount: float, lastPage: float}}
     */
    private function getDefaultPageBasedPaginatedData(): array
    {
        return ['collection' => [], 'paginationInfo' => ['itemsPerPage' => 0., 'totalCount' => 0., 'lastPage' => 0.]];
    }

    /**
     * @return array{clientMutationId: mixed}
     */
    private function getDefaultMutationData(array $context): array
    {
        return ['clientMutationId' => $context['args']['input']['clientMutationId'] ?? null];
    }

    /**
     * @return array{clientSubscriptionId: mixed}
     */
    private function getDefaultSubscriptionData(array $context): array
    {
        return ['clientSubscriptionId' => $context['args']['input']['clientSubscriptionId'] ?? null];
    }
}
