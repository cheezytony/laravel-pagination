<?php

namespace Cheezytony\Pagination\Traits;

use Cheezytony\Pagination\Http\Resources\GenericResource;
use Cheezytony\Pagination\Pagination;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

trait ReturnsPaginatedResponse
{
    /**
     * @param EloquentBuilder|QueryBuilder|Relation $query
     * @param class-string $resource
     * @param array $config
     * @return Response
     */
    public function paginatedResponse(
        EloquentBuilder|QueryBuilder|Relation $query,
        array $config = [],
        string $resource = GenericResource::class,
    ): Response {
        $data = (new Pagination($query, $config, $resource))->process();

        if ($data instanceof BinaryFileResponse) {
            return $data;
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Data fetched successfully',
            'data' => $data
        ]);
    }
}
