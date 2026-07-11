# Module: Reports

Read-only **aggregations / KPIs** for the dashboard. Reports **owns no data** — it
composes each source module's **stats contract**, never touching their models or
joining across their tables (§2.1/§2.4). Adding a metric means extending a source
module's stats contract (the module that owns that data), not querying it here.

## How it stays decoupled

Each source module publishes a small contract in its own `Domain/Contracts` and
binds an Eloquent implementation; Reports depends only on the interfaces:

| Contract (owner) | Provides |
|---|---|
| `Companies\...\CompanyStats` | `total`, `active` |
| `Subscriptions\...\SubscriptionStats` | `total`, `active` |
| `Licenses\...\LicenseStats` | `total`, `active`, `activeActivations` |
| `Payments\...\PaymentStats` | `collectedByCurrency`, `outstandingByCurrency`, `paidCount`, `openCount` |

`ReportService` injects the four contracts and assembles an `OverviewReport`.
Money is aggregated **per currency** (never summed across currencies) and returned
as decimal strings.

## Endpoint (staff, `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/reports/overview` | `reports.overview` | Platform KPIs (see shape below). |

```json
{
  "data": {
    "companies":     { "total": 12, "active": 10 },
    "subscriptions": { "total": 20, "active": 17 },
    "licenses":      { "total": 18, "active": 15, "active_activations": 22 },
    "billing": {
      "collected":   { "USD": "12400.00", "EUR": "300.00" },
      "outstanding": { "USD": "800.00" },
      "invoices_paid": 40,
      "invoices_open": 3
    }
  }
}
```

Aggregations only — no per-record data leaves this module.

## Tests

- `ReportOverviewTest` — auth guard, a controlled cross-module dataset asserting
  every KPI (including per-currency collected/outstanding), and the zeroed
  empty-platform case.
