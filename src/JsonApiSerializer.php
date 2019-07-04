<?php

/*
 * This file is part of the League\Fractal package.
 *
 * (c) Phil Sturgeon <me@philsturgeon.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace League\Fractal\Serializer;

use InvalidArgumentException;
use League\Fractal\Pagination\PaginatorInterface;
use League\Fractal\Resource\ResourceInterface;

class JsonApiSerializer extends ArraySerializer
{
    /** @var null|string */
    protected $baseUrl;

    /** @var array */
    protected $rootObjects;

    /**
     * JsonApiSerializer constructor.
     *
     * @param string $baseUrl
     */
    public function __construct($baseUrl = null)
    {
        $this->baseUrl = $baseUrl;
        $this->rootObjects = [];
    }

    /**
     * Serialize a collection.
     *
     * @param string $resourceKey
     * @param array $data
     *
     * @return array
     */
    public function collection($resourceKey, array $data) : array
    {
        $resources = [];

        foreach ($data as $resource) {
            $resources[] = $this->serializeItem($resourceKey, $resource)['data'];
        }

        return ['data' => $resources];
    }

    /**
     * Serialize an item.
     *
     * @param string $resourceKey
     * @param array $data
     *
     * @return array
     */
    public function serializeItem($resourceKey, array $data) : array
    {
        $id = $this->getIdFromData($data);

        $resource = [
            'data' => [
                'type' => $resourceKey,
                'id' => "$id",
                'attributes' => $data,
            ],
        ];

        unset($resource['data']['attributes']['id']);


        if (isset($resource['data']['attributes']['links'])) {
            $custom_links = $data['links'];
            unset($resource['data']['attributes']['links']);
        }

        if (isset($resource['data']['attributes']['meta'])) {
            $resource['data']['meta'] = $data['meta'];
            unset($resource['data']['attributes']['meta']);
        }

        if (empty($resource['data']['attributes'])) {
            $resource['data']['attributes'] = (object) [];
        }

        if ($this->shouldIncludeLinks()) {
            $resource['data']['links'] = [
                'self' => "{$this->baseUrl}/$resourceKey/$id",
            ];
            if (isset($custom_links)) {
                $resource['data']['links'] = array_merge($resource['data']['links'], $custom_links);
            }
        }

        return $resource;
    }

    /**
     * Serialize the paginator.
     *
     * @param PaginatorInterface $paginator
     *
     * @return array
     */
    public function serializePaginator(PaginatorInterface $paginator) : array
    {
        $currentPage = (int)$paginator->getCurrentPage();
        $lastPage = (int)$paginator->getLastPage();

        $pagination = [
            'total' => (int)$paginator->getTotal(),
            'count' => (int)$paginator->getCount(),
            'per_page' => (int)$paginator->getPerPage(),
            'current_page' => $currentPage,
            'total_pages' => $lastPage,
        ];

        $pagination['links'] = [];

        $pagination['links']['self'] = $paginator->getUrl($currentPage);
        $pagination['links']['first'] = $paginator->getUrl(1);

        if ($currentPage > 1) {
            $pagination['links']['prev'] = $paginator->getUrl($currentPage - 1);
        }

        if ($currentPage < $lastPage) {
            $pagination['links']['next'] = $paginator->getUrl($currentPage + 1);
        }

        $pagination['links']['last'] = $paginator->getUrl($lastPage);

        return ['pagination' => $pagination];
    }

    public function meta(array $meta) : array
    {
        if (empty($meta)) {
            return [];
        }

        $result['meta'] = $meta;

        if (array_key_exists('pagination', $result['meta'])) {
            $result['links'] = $result['meta']['pagination']['links'];
            unset($result['meta']['pagination']['links']);
        }

        return $result;
    }

    public function null() : array
    {
        return [
            'data' => null,
        ];
    }

    public function includedData(ResourceInterface $resource, array $data) : array
    {
        list($serializedData, $linkedIds) = $this->pullOutNestedIncludedData($data);

        foreach ($data as $value) {
            foreach ($value as $includeObject) {
                if ($this->isNull($includeObject) || $this->isEmpty($includeObject)) {
                    continue;
                }

                $includeObjects = $this->createIncludeObjects($includeObject);
                list($serializedData, $linkedIds) = $this->serializeIncludedObjectsWithCacheKey($includeObjects, $linkedIds, $serializedData);
            }
        }

        return empty($serializedData) ? [] : ['included' => $serializedData];
    }

    /**
     * Indicates if includes should be side-loaded.
     *
     * @return bool
     */
    public function sideloadIncludes()
    {
        return true;
    }

    /**
     * @param array $data
     * @param array $includedData
     *
     * @return array
     */
    public function injectData($data, $includedData) : array
    {
        $relationships = $this->parseRelationships($includedData);

        if (!empty($relationships)) {
            $data = $this->fillRelationships($data, $relationships);
        }

        return $data;
    }

    /**
     * Hook to manipulate the final sideloaded includes.
     * The JSON API specification does not allow the root object to be included
     * into the sideloaded `included`-array. We have to make sure it is
     * filtered out, in case some object links to the root object in a
     * relationship.
     *
     * @param array $includedData
     * @param array $data
     *
     * @return array
     */
    public function filterIncludes($includedData, $data) : array
    {
        if (!isset($includedData['included'])) {
            return $includedData;
        }

        // Create the RootObjects
        $this->createRootObjects($data);

        // Filter out the root objects
        $filteredIncludes = array_filter($includedData['included'], [$this, 'filterRootObject']);

        // Reset array indices
        $includedData['included'] = array_merge([], $filteredIncludes);

        return $includedData;
    }

    /**
     * Get the mandatory fields for the serializer
     *
     * @return array
     */
    public function getMandatoryFields() : array
    {
        return ['id'];
    }

    /**
     * Filter function to delete root objects from array.
     *
     * @param array $object
     *
     * @return bool
     */
    protected function filterRootObject($object) : bool
    {
        return !$this->isRootObject($object);
    }

    /**
     * Set the root objects of the JSON API tree.
     *
     * @param array $objects
     */
    protected function setRootObjects(array $objects = []) : void
    {
        $this->rootObjects = array_map(function ($object) {
            return "{$object['type']}:{$object['id']}";
        }, $objects);
    }

    /**
     * Determines whether an object is a root object of the JSON API tree.
     *
     * @param array $object
     *
     * @return bool
     */
    protected function isRootObject($object) : bool
    {
        $objectKey = "{$object['type']}:{$object['id']}";
        return in_array($objectKey, $this->rootObjects);
    }

    /**
     * @param array|null $data
     *
     * @return bool
     */
    protected function isCollection($data) : bool
    {
        if ($data === null) {
            return false;
        }

        return array_key_exists('data', $data) &&
        array_key_exists(0, $data['data']);
    }

    /**
     * @param array|null $data
     *
     * @return bool
     */
    protected function isNull($data) : bool
    {
        if ($data === null) {
            return true;
        }

        return array_key_exists('data', $data) && $data['data'] === null;
    }

    /**
     * @param array|null $data
     *
     * @return bool
     */
    protected function isEmpty($data) : bool
    {
        if ($data === null) {
            return true;
        }

        return array_key_exists('data', $data) && $data['data'] === [];
    }

    /**
     * @param array $data
     * @param array $relationships
     *
     * @return array
     */
    protected function fillRelationships($data, $relationships) : array
    {
        if ($this->isCollection($data)) {
            foreach ($relationships as $key => $relationship) {
                $data = $this->fillRelationshipAsCollection($data, $relationship, $key);
            }
        } else { // Single resource
            foreach ($relationships as $key => $relationship) {
                $data = $this->fillRelationshipAsSingleResource($data, $relationship, $key);
            }
        }

        return $data;
    }

    /**
     * @param array $includedData
     *
     * @return array
     */
    protected function parseRelationships($includedData) : array
    {
        $relationships = [];

        foreach ($includedData as $key => $inclusion) {
            foreach ($inclusion as $includeKey => $includeObject) {
                $relationships = $this->buildRelationships($includeKey, $relationships, $includeObject, $key);
                if (isset($includedData[0][$includeKey]['meta'])) {
                    $relationships[$includeKey][0]['meta'] = $includedData[0][$includeKey]['meta'];
                }
            }
        }

        return $relationships;
    }

    /**
     * @param array $data
     *
     * @return integer
     */
    protected function getIdFromData(array $data) : int
    {
        if (!array_key_exists('id', $data)) {
            throw new InvalidArgumentException(
                'JSON API resource objects MUST have a valid id'
            );
        }
        return $data['id'];
    }

    /**
     * Keep all sideloaded inclusion data on the top level.
     *
     * @param array $data
     *
     * @return array
     */
    protected function pullOutNestedIncludedData(array $data) : array
    {
        $includedData = [];
        $linkedIds = [];

        foreach ($data as $value) {
            foreach ($value as $includeObject) {
                if (isset($includeObject['included'])) {
                    list($includedData, $linkedIds) = $this->serializeIncludedObjectsWithCacheKey($includeObject['included'], $linkedIds, $includedData);
                }
            }
        }

        return [$includedData, $linkedIds];
    }

    /**
     * Whether or not the serializer should include `links` for resource objects.
     *
     * @return bool
     */
    protected function shouldIncludeLinks() : bool
    {
        return $this->baseUrl !== null;
    }

    /**
     * Check if the objects are part of a collection or not
     *
     * @param array $includeObject
     *
     * @return array
     */
    private function createIncludeObjects($includeObject) : array
    {
        if ($this->isCollection($includeObject)) {
            $includeObjects = $includeObject['data'];

            return $includeObjects;
        } else {
            $includeObjects = [$includeObject['data']];

            return $includeObjects;
        }
    }

    /**
     * Sets the RootObjects, either as collection or not.
     *
     * @param array $data
     */
    private function createRootObjects(array $data) : void
    {
        if ($this->isCollection($data)) {
            $this->setRootObjects($data['data']);
        } else {
            $this->setRootObjects([$data['data']]);
        }
    }

    private function fillRelationshipAsCollection($data, $relationship, $key) : array
    {
        foreach ($relationship as $index => $relationshipData) {
            $data['data'][$index]['relationships'][$key] = $relationshipData;
        }

        return $data;
    }

    private function fillRelationshipAsSingleResource($data, $relationship, $key) : array
    {
        $data['data']['relationships'][$key] = $relationship[0];

        return $data;
    }

    /**
     * @param mixed $includeKey
     * @param array $relationships
     * @param array|null $includeObject
     * @param string $key
     *
     * @return array
     */
    private function buildRelationships($includeKey, array $relationships, array $includeObject,  string $key) : array
    {
        $relationships = $this->addIncludekeyToRelationsIfNotSet($includeKey, $relationships);

        if ($this->isNull($includeObject)) {
            $relationship = $this->null();
        } elseif ($this->isEmpty($includeObject)) {
            $relationship = [
                'data' => [],
            ];
        } elseif ($includeObject && $this->isCollection($includeObject)) {
            $relationship = ['data' => []];
            $relationship = $this->addIncludedDataToRelationship($includeObject, $relationship);
        } else {
            $relationship = [
                'data' => [
                    'type' => $includeObject['data']['type'],
                    'id' => $includeObject['data']['id'],
                ],
            ];
        }

        $relationships[$includeKey][$key] = $relationship;

        return $relationships;
    }

    /**
     * @param mixed $includeKey
     * @param array $relationships
     *
     * @return array
     */
    private function addIncludekeyToRelationsIfNotSet($includeKey, array $relationships) : array
    {
        if (!array_key_exists($includeKey, $relationships)) {
            $relationships[$includeKey] = [];
            return $relationships;
        }

        return $relationships;
    }

    /**
     * @param array $includeObject
     * @param array $relationship
     *
     * @return array
     */
    private function addIncludedDataToRelationship(array $includeObject, array $relationship) : array
    {
        foreach ($includeObject['data'] as $object) {
            $relationship['data'][] = [
                'type' => $object['type'],
                'id' => $object['id'],
            ];
        }

        return $relationship;
    }

    public function injectAvailableIncludeData($data, $availableIncludes) : array
    {
        if (!$this->shouldIncludeLinks()) {
            return $data;
        }

        if ($this->isCollection($data)) {
            $data['data'] = array_map(function ($resource) use ($availableIncludes) {
                foreach ($availableIncludes as $relationshipKey) {
                    $resource = $this->addRelationshipLinks($resource, $relationshipKey);
                }
                return $resource;
            }, $data['data']);
        } else {
            foreach ($availableIncludes as $relationshipKey) {
                $data['data'] = $this->addRelationshipLinks($data['data'], $relationshipKey);
            }
        }

        return $data;
    }

    private function addRelationshipLinks(array $resource, string $relationshipKey) : array
    {
        if (!isset($resource['relationships']) || !isset($resource['relationships'][$relationshipKey])) {
            $resource['relationships'][$relationshipKey] = [];
        }

        $resource['relationships'][$relationshipKey] = array_merge(
            [
                'links' => [
                    'self'   => "{$this->baseUrl}/{$resource['type']}/{$resource['id']}/relationships/{$relationshipKey}",
                    'related' => "{$this->baseUrl}/{$resource['type']}/{$resource['id']}/{$relationshipKey}",
                ]
            ],
            $resource['relationships'][$relationshipKey]
        );

        return $resource;
    }

    private function serializeIncludedObjectsWithCacheKey(array $includeObjects, array $linkedIds, array $serializedData) : array
    {
        foreach ($includeObjects as $object) {
            $includeType = $object['type'];
            $includeId = $object['id'];
            $cacheKey = "$includeType:$includeId";
            if (!array_key_exists($cacheKey, $linkedIds)) {
                $serializedData[] = $object;
                $linkedIds[$cacheKey] = $object;
            }
        }
        return [$serializedData, $linkedIds];
    }
}
