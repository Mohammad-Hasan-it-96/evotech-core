<?php

namespace Modules\Customers\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Customers\Domain\Models\Customer;

/**
 * Customer use-cases. All queries are tenant-scoped automatically via the
 * BelongsToCompany global scope — no explicit company filtering needed here.
 */
final class CustomerService
{
    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Customer::query()->latest()->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        // company_id is filled by the tenant context (BelongsToCompany).
        return Customer::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->refresh();
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }
}
