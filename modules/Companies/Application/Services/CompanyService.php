<?php

namespace Modules\Companies\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Companies\Domain\Models\Company;

final class CompanyService
{
    /**
     * @return LengthAwarePaginator<int, Company>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Company::query()->latest()->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Company
    {
        return Company::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): Company
    {
        $company->update($data);

        return $company->refresh();
    }

    public function delete(Company $company): void
    {
        $company->delete();
    }
}
