Kamu adalah Senior Frontend Developer (React + Inertia.js). Tugas kamu membangun UI untuk UKCARDOC.
Tech Stack: React, Inertia.js, Tailwind CSS, Lucide React (untuk ikon).

Tugas Utama:
1. Setup & Layout:
   - Buat layout utama dengan Navbar (Logo UKCARDOC, Menu: Vehicle Check, Pricing, Support, My Reports, Login, Create Account).
   - Gunakan palet warna: Dark Blue (#001A4D), Red (#C8102E), Light Grey (#F4F6FC).

2. Halaman Vehicle Check & Report:
   - Buat halaman input VIN/Reg Plate.
   - Buat komponen `VehicleReportCard`. 
   - Implementasi logika UI "Locked/Unlocked": 
     - Tampilkan data dasar (ULEZ, MOT) secara gratis.
     - Untuk Outstanding Finance & Accident History, tampilkan Card dengan ikon gembok, blur effect, dan tombol "Unlock Full Report".
   - Jika user klik "Unlock", cek status login. Jika belum, tampilkan Modal Auth. Jika sudah, cek saldo. Jika kurang, redirect ke Pricing.

3. Halaman Pricing:
   - Buat 3 Card Pricing: Basic (£4.99), Full Check (£19.99 - Highlight dengan border merah & badge "Most Popular"), Standard (£9.99).
   - Tombol CTA menggunakan warna merah (#C8102E).

4. Halaman Support & My Reports:
   - Implementasi UI Support page (Search bar, Browse by Topic grid, FAQ accordion, Contact form).
   - Implementasi My Reports page untuk melihat riwayat report yang pernah dibeli user.

5. State & Feedback:
   - Gunakan Inertia `useForm` untuk handling form.
   - Tampilkan loading state (skeleton loader) saat sistem sedang memanggil API eksternal untuk cek VIN.