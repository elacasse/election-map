#!/usr/bin/env bash

docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan app:fetch-election-results 2022 election-results/2022/resultats-2022-2026-09-26_19-09-08.json
docker compose exec app php artisan election:import-candidates 2026 storage/app/candidatures.json
# docker compose exec app php artisan app:fetch-election-results 2026 election-results/2026/resultats-2026-2026-09-20_14-08-00.json
docker compose exec app php artisan election:backfill-party-names
docker compose exec app php artisan election:backfill-party-colors
