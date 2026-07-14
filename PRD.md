📄 Product Requirement Document (PRD) — UKCardoc
1. Project Overview & Context
UKCardoc adalah platform Vehicle History Check khusus untuk pasar United Kingdom (UK). Aplikasi ini mendecode VIN atau VRM (Vehicle Registration Mark/Nomor Pelat UK) untuk memvalidasi legalitas, kecelakaan, status keuangan (outstanding finance), riwayat odometer, dan status MOT/Pajak langsung dari basis data terintegrasi (DVLA, HPI, VOSA, PNC).

Tech Stack: Laravel 13, Inertia.js, React.js (TypeScript/JavaScript), TailwindCSS, PostgreSQL.

Core Architecture: Monolith Modern memanfaatkan Inertia.js untuk menjembatani Backend Controller dengan Frontend React Components tanpa REST API terpisah.

2. System Architecture & Database Blueprint
Gunakan skema database ini sebagai referensi mutlak saat menginstruksikan AI untuk membuat Model, Controller, atau melakukan query.

Core Tables & Business Logic Relationship
users: Menyimpan data autentikasi standar ditambah kolom komparatif khusus: credits (saldo untuk membeli laporan) dan last_login_at.

vin_checks: Log pencarian awal. Menyimpan data vin, registration_number, data_source, status, dan cached_until (untuk mengontrol logika cache fresh dalam X hari).

vehicles: Menyimpan data spesifikasi statis dan dinamis kendaraan hasil dari API eksternal (termasuk kolom spesifik UK: mot_expiry_date, tax_status, write_off_category, outstanding_finance, dan co2_emissions).

reports: Hasil akhir laporan premium yang dibeli user. Menyimpan report_data dalam tipe data JSON, reports_type (Basic, Standard, Full), dan masa berlaku laporan (expired_at).

subscriptions & transactions: Mengelola monetisasi via paket langganan atau pembelian retail (melacak invoice_id, payment_gateway_ref, dan status).

api_logs: Mencatat performa third-party API request, melacak response_time_ms, http_status_code, dan payload error untuk debugging.

3. User Journey & Feature Specifications
3.1. Homepage & Initial Search (Free Tier)
User Action: User memasukkan VIN atau VRM (Pelat nomor UK) pada input box utama di Homepage.

System Logic (Berdasarkan Flowchart):

Sistem melakukan validasi format VIN/VRM.

Sistem mengecek tabel vin_checks dan vehicles berdasarkan input.

Kondisi A (Cache Hit & Fresh): Jika data sudah ada di DB dan cached_until belum melewati waktu sekarang, sistem langsung menarik data internal dan mengarahkan ke halaman ringkasan.

Kondisi B (Cache Miss / Expired): Sistem menembak External API (Rapid Car Check/DVLA), mencatat log ke api_logs, menyimpan/memperbarui data ke vehicles, lalu membuat/memperbarui data di vin_checks dengan mengisi kolom cached_until = now() + X days.

Output: Mengarahkan user ke halaman parsial gratis (Free Report) yang menampilkan spesifikasi dasar (Merek, Model, Tahun, Warna, Status Pajak/MOT dasar).

3.2. Authentication & Paywall Trigger
User Action: User menekan tombol "View Full Report" atau tombol pembelian di halaman Pricing.

System Logic:

Sistem mengecek apakah status user sudah login (Auth::check()).

Jika Belum Login: Mengarahkan ke modal/halaman Register. Setelah mengisi Nama, Email, No Telp, dan Password, sistem melakukan Auto Login dan mengembalikan user ke alur pembelian sebelumnya.

Pengecekan Saldo Kredit: Sistem memeriksa nilai kolom credits pada tabel users.

Jika credits >= kebutuhan_paket: Sistem memotong saldo credits user, membuat baris baru di tabel reports, memproses payload JSON ke report_data, lalu menampilkan halaman Premium Report.

Jika Kredit Kurang: Mengarahkan user ke halaman Top Up / Pricing untuk membeli kredit baru melalui payment gateway (Stripe/PayPal) yang akan dicatat di tabel transactions.

3.3. Premium Vehicle Report Page (Berdasarkan Desain UI)
Halaman ini harus memuat data JSON dari reports.report_data yang terbagi ke dalam komponen-komponen UI berikut:

Header Section: Menampilkan foto representatif kendaraan, REPORT ID, Nama Mobil (Contoh: BMW 3 SERIES 320d M Sport), Tahun, Reg Plate, Status Kebersihan Dokumen (Clean Report), dan tag fungsional (ULEZ Compliant, Transmission, Fuel Type, Euro Status).

Vehicle Specifications Grid: Detail teknis (Make, Model, Body Type, Colour, Date of First Reg, Fuel Type, Transmission, CO2 Emissions, Engine Number).

Finance Status Card: Validasi kolom outstanding_finance dari DB. Jika aman, tampilkan banner hijau "No Finance Recorded via HPI & Experian".

Mileage History Table: List riwayat tanggal pemeriksaan, jumlah mil (miles), dan status validitas odometer guna mendeteksi Mileage Anomaly.

MOT Status Card: Menampilkan mot_expiry_date, hitungan mundur hari aktif (Days Remaining), serta log hasil tes MOT (Pass/Fail beserta catatan komponen jika ada).

Triple Threat Safety Check Footer: tiga card sejajar untuk status krusial:

Stolen Records (PNC Check).

Salvage & Write-off (MIAFTR Registry Check - mendeteksi kategori kerusakan asuransi).

Owner History (Jumlah pemilik sebelumnya).

Action Buttons: Tombol fungsional untuk Download PDF Report dan Share Report Link.