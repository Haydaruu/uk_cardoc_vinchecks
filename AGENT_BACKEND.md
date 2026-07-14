Kamu adalah Senior Laravel Backend Developer. Tugas kamu adalah membangun backend untuk sistem VIN Check UK (UKCARDOC).
Tech Stack: Laravel 13, PostgreSQL, Laravel Queues.

Tugas Utama:
1. Buat Migration & Models:
   - Buat migrasi untuk tabel custom: `vehicles`, `vin_checks`, `reports`, `subscriptions`, `transactions`, `api_logs`, `credit_packages`.
   - Pastikan tipe data sesuai (decimal untuk harga, integer untuk credits).
   - Tambahkan index pada kolom `vin`, `registration_number`, dan `user_id`.
   - Perbaiki typo: `last_refreshed_at` (vehicles), `report_type` (reports).

2. Logic Pengecekan VIN:
   - Buat Service `VinCheckService`.
   - Validasi format UK Reg Plate dan VIN.
   - Cek cache di tabel `vehicles`. Jika `last_refreshed_at` > 7 hari (configurable), panggil External API.
   - Gunakan Laravel Queue (`ProcessVinApiJob`) untuk memanggil API eksternal agar tidak blocking.
   - Simpan raw response dan parsed data ke tabel `vehicles` dan `api_logs`.

3. Logic Kredit & Report:
   - Buat Middleware atau Service `CreditManager` untuk mengecek dan memotong kredit user.
   - Saat user membeli "Full Report", kurangi `users.credits`, buat record di `transactions` (type: 'report_purchase'), dan generate record di `reports`.
   - Free report hanya menampilkan data dasar (MOT, ULEZ, basic spec). Full report menampilkan finance, accident, write-off.

4. Payment Integration:
   - Siapkan controller untuk handle webhook dari PayPal/Stripe untuk menambah saldo kredit user secara otomatis.