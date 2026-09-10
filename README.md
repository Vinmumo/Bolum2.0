# Bolum 2.0 — Football Predictions API

Laravel 13 API built for a backend interview: public football data, Sanctum authentication, company-scoped predictions, queued generation, and transactional demo credits.

## Milestones

1. Laravel foundation and reproducible setup.
2. Authentication, companies, policies, and football CRUD.
3. Prediction providers, queues, idempotency, and credit ledger.
4. Feature tests, CI, API examples, and interview notes.

PHP 8.4+ and Composer are required by the locked dependencies. SQLite is the default database. No paid API keys are needed.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
# In a second terminal:
php artisan queue:work --tries=3 --timeout=60
# Verification:
php artisan test
vendor/bin/pint --test
```
