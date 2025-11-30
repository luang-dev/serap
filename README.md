# free self hosted monitoring tool for your laravel app

IN DEVELOPMENT, NOT READY

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Ide pengembangan untuk pipeline ingest

- **Event tambahan**: cache hit/miss, operasi storage (upload/download), slow query/lock DB, auth & security (login/logout, gagal MFA), webhook/API eksternal, serta metrik pemakaian sumber daya worker dan backlog antrean.
- **Penyempurnaan pipeline**: batch adaptif sesuai latensi terakhir, kompres payload besar (gzip) + checksum untuk deduplikasi, penanda idempotensi (`event_id`, `occurred_at`, `sequence`), antrean prioritas, pembersihan file/log lama, fallback lokal saat endpoint down, dan metrik ringan (sukses/gagal, latensi kirim).

## Contoh JSONL per event

Setiap baris JSONL minimal memuat metadata (`time`, `occurred_at`, `event_id`, `sequence`, `trace_id`, `event`, `level`, `priority`, `auth`) serta `context` sesuai jenis event. Berikut contoh ringkas untuk beberapa watcher bawaan:

```json
{"time":"2024-05-01T10:00:00Z","occurred_at":"2024-05-01T10:00:00Z","event_id":"d8e1a5bf-6c1b-4b3e-8f1c-9f2c0b1a2c3d","sequence":1,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"artisan_command","level":"info","priority":"normal","auth":null,"context":{"status":"starting","command":"migrate","arguments":[],"options":{"force":true},"exit_code":null,"time":"2024-05-01T10:00:00Z"}}
{"time":"2024-05-01T10:00:02Z","occurred_at":"2024-05-01T10:00:02Z","event_id":"cc0a9144-7f6c-4c0e-9d0b-4c1a2b3c4d5e","sequence":2,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"query","level":"info","priority":"normal","auth":{"id":1,"name":"Admin"},"context":[{"sql":"select * from users where email = ?","bindings":{"email":"******"},"duration":12.4,"connection":"mysql","time":"2024-05-01T10:00:02Z"}]}
{"time":"2024-05-01T10:00:03Z","occurred_at":"2024-05-01T10:00:03Z","event_id":"3e2f1c4b-5a6d-7e8f-9a0b-1c2d3e4f5a6b","sequence":3,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"job","level":"info","priority":"normal","auth":null,"context":{"status":"processed","name":"App\\Jobs\\SendEmail","display_name":"Send Email","queue":"default","connection":"redis","attempts":1,"uuid":"job-uuid-123","time":"2024-05-01T10:00:03Z"}}
{"time":"2024-05-01T10:00:04Z","occurred_at":"2024-05-01T10:00:04Z","event_id":"1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d","sequence":4,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"request","level":"info","priority":"normal","auth":{"id":1,"name":"Admin"},"context":{"time":"2024-05-01T10:00:04Z","uri":"/api/posts?draft=false","method":"GET","controller_action":"App\\Http\\Controllers\\PostController@index","middleware":["api","auth:sanctum"],"session":[],"memory":12.5,"params":{"draft":"false"},"headers":{"accept":["application/json"],"authorization":["Bearer ******"]},"payload":[]}}
{"time":"2024-05-01T10:00:04Z","occurred_at":"2024-05-01T10:00:04Z","event_id":"5f6e7d8c-9b0a-1c2d-3e4f-5a6b7c8d9e0f","sequence":5,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"response","level":"info","priority":"normal","auth":{"id":1,"name":"Admin"},"context":{"status":200,"duration_ms":45.8,"type":"json","time":"2024-05-01T10:00:04Z","memory":13.2,"headers":{"content-type":["application/json"]},"response":{"is_truncated":false,"data":{"data":[{"id":1,"title":"Hello"}]}}}}
{"time":"2024-05-01T10:00:05Z","occurred_at":"2024-05-01T10:00:05Z","event_id":"9c8b7a6d-5e4f-3d2c-1b0a-9e8f7d6c5b4a","sequence":6,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"mail","level":"info","priority":"normal","auth":null,"context":{"status":"sent","mailable":"App\\Mail\\WelcomeMail","to":["user@example.com"],"cc":[],"bcc":[],"queue":null,"locale":"en","time":"2024-05-01T10:00:05Z"}}
{"time":"2024-05-01T10:00:06Z","occurred_at":"2024-05-01T10:00:06Z","event_id":"0f9e8d7c-6b5a-4c3d-2e1f-0a9b8c7d6e5f","sequence":7,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"notification","level":"info","priority":"normal","auth":{"id":1,"name":"Admin"},"context":{"status":"sent","notification":"App\\Notifications\\InvoicePaid","channels":["mail","database"],"notifiable":{"id":1,"type":"App\\Models\\User"},"queue":"default","time":"2024-05-01T10:00:06Z"}}
{"time":"2024-05-01T10:00:07Z","occurred_at":"2024-05-01T10:00:07Z","event_id":"7a6b5c4d-3e2f-1a0b-9c8d-7e6f5a4b3c2d","sequence":8,"trace_id":"01HYQ0X2VY58D6H3T1FZ8D4J1R","event":"scheduler","level":"info","priority":"normal","auth":null,"context":{"status":"finished","command":"php artisan schedule:run --id=daily-report","expression":"0 2 * * *","mutex_released":true,"time":"2024-05-01T10:00:07Z"}}
```

Catatan: nilai UUID, ULID, timestamp, payload, serta informasi auth pada contoh di atas hanya ilustrasi; aplikasi akan mengisi sesuai data aktual ketika watcher berjalan.
