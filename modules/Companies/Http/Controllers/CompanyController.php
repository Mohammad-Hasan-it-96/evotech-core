<?php

namespace Modules\Companies\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Companies\Application\Services\CompanyService;
use Modules\Companies\Domain\Models\Company;
use Modules\Companies\Http\Requests\StoreCompanyRequest;
use Modules\Companies\Http\Requests\UpdateCompanyRequest;
use Modules\Companies\Http\Resources\CompanyResource;
use Modules\Core\Http\Controllers\ApiController;

final class CompanyController extends ApiController
{
    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return CompanyResource::collection($this->companies->paginate($perPage));
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->create($request->validated());

        return CompanyResource::make($company)->response()->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        return CompanyResource::make($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        return CompanyResource::make(
            $this->companies->update($company, $request->validated())
        );
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->companies->delete($company);

        return $this->noContent();
    }
}
