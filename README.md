# free self hosted monitoring tool for your laravel app

IN DEVELOPMENT, NOT READY

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Ide pengembangan untuk pipeline ingest

- **Event tambahan**: cache hit/miss, operasi storage (upload/download), slow query/lock DB, auth & security (login/logout, gagal MFA), webhook/API eksternal, serta metrik pemakaian sumber daya worker dan backlog antrean.
- **Penyempurnaan pipeline**: batch adaptif sesuai latensi terakhir, kompres payload besar (gzip) + checksum untuk deduplikasi, penanda idempotensi (`event_id`, `occurred_at`, `sequence`), antrean prioritas, pembersihan file/log lama, fallback lokal saat endpoint down, dan metrik ringan (sukses/gagal, latensi kirim).
