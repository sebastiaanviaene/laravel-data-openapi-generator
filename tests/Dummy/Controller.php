<?php

namespace Xolvio\OpenApiGenerator\Test;

use Illuminate\Routing\Controller as LaravelController;
use Spatie\LaravelData\DataCollection;

class Controller extends LaravelController
{
    public function noResponse() {}

    public function basic(): ReturnData
    {
        return new ReturnData();
    }

    /**
     * @return \Xolvio\OpenApiGenerator\Test\ReturnData[]
     */
    public function array(): array
    {
        return [];
    }

    /**
     * @return ReturnData[]
     */
    public function arrayIncompletePath(): array
    {
        return [];
    }

    public function arrayFail(): array
    {
        return [];
    }

    /**
     * @return DataCollection<int,\Xolvio\OpenApiGenerator\Test\ReturnData>
     */
    public function collection()
    {
        return ReturnData::collect([]);
    }

    /**
     * @return DataCollection<int,ReturnData>
     */
    public function collectionIncompletePath()
    {
        return ReturnData::collect([]);
    }

    public function collectionFail()
    {
        return ReturnData::collect([]);
    }

    public function intParameter(int $parameter): ReturnData
    {
        return ReturnData::create($parameter);
    }

    public function stringParameter(string $parameter): ReturnData
    {
        return ReturnData::create($parameter);
    }

    public function modelParameter(Model $parameter): ReturnData
    {
        return ReturnData::create($parameter);
    }

    public function requestBasic(RequestData $request): ReturnData
    {
        return ReturnData::create($request);
    }

    public function requestNoData(NotData $request): ReturnData
    {
        return ReturnData::create($request);
    }

    public function allCombined(int $parameter_1, string $parameter_2, Model $parameter_3, RequestData $request): ReturnData
    {
        return ReturnData::create($parameter_1, $parameter_2, $parameter_3, $request);
    }

    public function contentType(ContentTypeData $data): ContentTypeData
    {
        return ContentTypeData::create($data);
    }
}
