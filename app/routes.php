<?php

declare(strict_types=1);

use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use App\Middleware\JwtMiddleware;
use Firebase\JWT\JWT;
use App\Models\User;
use App\Models\Mahasiswa;
use App\Models\Presensi;
use App\Models\Dosen;
use App\Models\KuisDetailSoal;
use App\Models\Kelas;
use App\Models\NilaiKuis;

use App\Models\Kuis;
use App\Models\MahasiswaKelas;
use Illuminate\Database\Capsule\Manager as DB;
use App\Models\Matakuliah;
use App\Models\Materi;
use App\Models\Pengumuman;
use App\Models\Tugas;
use App\Models\TugasMahasiswa;
use Slim\Routing\RouteCollectorProxy;


return function (App $app) {

    // === CORS Preflight handler ===
    $app->options('/{routes:.+}', function ($request, $response) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    });

    // === Hello world test ===
    $app->get(
        '/',
        fn(Request $request, Response $response) =>
        $response->getBody()->write('Hello world!')
    );
$app->group('/api/kuis', function (Group $group) {
    // 1. GET: Ambil daftar kuis per kelas
    // routes.php (Tambahkan di dalam $app->group('/api/kuis', function (Group $group) { ... });)

    // 3. GET: Detail Kuis dan Soalnya (untuk modal edit)
    $group->get('/detail/{id}', function (Request $request, Response $response, array $args) {
        $kuis = Kuis::with('detailSoal')->find($args['id']);

        if (!$kuis) {
            $errorData = ['status' => 'error', 'message' => 'Kuis tidak ditemukan.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404)->getBody()->write(json_encode($errorData));
        }

        $responseData = ['status' => 'success', 'data' => $kuis];
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200)->getBody()->write(json_encode($responseData));
    });

    // 4. PUT: Update Kuis dan Soalnya
    $group->put('/update/{id}', function (Request $request, Response $response, array $args) {
        $kuisId = $args['id'];
        $data = (array)$request->getParsedBody();
        $soalData = $data['soal'] ?? [];

        try {
            Kuis::getConnection()->beginTransaction();

            $kuis = Kuis::find($kuisId);
            if (!$kuis) {
                 throw new \Exception("Kuis tidak ditemukan.");
            }

            // A. Update Kuis Utama
            $kuis->update([
                'judul' => $data['judul'],
                'deskripsi' => $data['deskripsi'] ?? null,
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'],
                'durasi_menit' => $data['durasi_menit'] ?? 60,
                'status' => $data['status'] ?? $kuis->status, // Dosen bisa mengubah status
            ]);

            // B. Hapus soal lama dan masukkan yang baru
            KuisDetailSoal::where('kuis_id', $kuisId)->delete();
            foreach ($soalData as $soal) {
                KuisDetailSoal::create([
                    'kuis_id' => $kuisId,
                    'soal' => $soal['soal'],
                    'pilihan_a' => $soal['pilihan_a'],
                    'pilihan_b' => $soal['pilihan_b'],
                    'pilihan_c' => $soal['pilihan_c'] ?? null,
                    'pilihan_d' => $soal['pilihan_d'] ?? null,
                    'jawaban_benar' => $soal['jawaban_benar'],
                    'nilai_bobot' => $soal['nilai_bobot'] ?? 10,
                ]);
            }
            // Catatan: Logika Auto-trigger Nilai tidak perlu diulangi di update, kecuali status diubah dari 'draft' ke 'active'
            
            Kuis::getConnection()->commit();
            $successData = ['status' => 'success', 'message' => 'Kuis berhasil diperbarui.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200)->getBody()->write(json_encode($successData));

        } catch (\Exception $e) {
            Kuis::getConnection()->rollBack();
            $errorData = ['status' => 'error', 'message' => 'Gagal memperbarui kuis: ' . $e->getMessage()];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500)->getBody()->write(json_encode($errorData));
        }
    });

    // 5. DELETE: Hapus Kuis
    $group->delete('/delete/{id}', function (Request $request, Response $response, array $args) {
        $kuis = Kuis::find($args['id']);
        if (!$kuis) {
            $errorData = ['status' => 'error', 'message' => 'Kuis tidak ditemukan.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404)->getBody()->write(json_encode($errorData));
        }

        try {
            Kuis::getConnection()->beginTransaction();
            // Penghapusan akan otomatis menghapus soal dan nilai_kuis berkat ON DELETE CASCADE
            $kuis->delete(); 
            Kuis::getConnection()->commit();

            $successData = ['status' => 'success', 'message' => 'Kuis berhasil dihapus.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200)->getBody()->write(json_encode($successData));
        } catch (\Exception $e) {
            Kuis::getConnection()->rollBack();
            $errorData = ['status' => 'error', 'message' => 'Gagal menghapus kuis: ' . $e->getMessage()];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500)->getBody()->write(json_encode($errorData));
        }
    });
    $group->get('/by-kelas/{kelas_id}', function (Request $request, Response $response, array $args) {
        $kuisList = Kuis::where('kelas_id', $args['kelas_id'])
                        ->withCount('detailSoal') // Menghitung jumlah soal
                        ->orderBy('tanggal_mulai', 'desc')
                        ->get();
        
        $responseData = ['status' => 'success', 'data' => $kuisList];

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200)
            ->getBody()
            ->write(json_encode($responseData)); // 💡 FIXED: Menggunakan $responseData
    });

    // 2. POST: Simpan Kuis (Beserta Auto-Trigger Nilai)
   $group->post('/store', function (Request $request, Response $response) {
        $data = (array)$request->getParsedBody();
        $soalData = $data['soal'] ?? [];
        $kelasId = $data['kelas_id'];

        if (empty($data['judul']) || empty($soalData)) {
            $errorData = ['status' => 'error', 'message' => 'Judul dan minimal satu soal wajib diisi.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400)->getBody()->write(json_encode($errorData));
        }

        try {
            // 💡 KOREKSI TRANSAKSI: Gunakan Facade DB
            DB::beginTransaction(); // Menggantikan Kuis::getConnection()->beginTransaction();

            // A. Buat Kuis Utama
            $kuis = Kuis::create([
                'kelas_id' => $kelasId,
                'judul' => $data['judul'],
                'deskripsi' => $data['deskripsi'] ?? null,
                'tanggal_mulai' => $data['tanggal_mulai'],
                'tanggal_selesai' => $data['tanggal_selesai'],
                'durasi_menit' => $data['durasi_menit'] ?? 60,
                'status' => 'draft',
            ]);

            // B. Masukkan Detail Soal
            foreach ($soalData as $soal) {
                KuisDetailSoal::create([
                    'kuis_id' => $kuis->id,
                    'soal' => $soal['soal'],
                    'pilihan_a' => $soal['pilihan_a'],
                    'pilihan_b' => $soal['pilihan_b'],
                    'pilihan_c' => $soal['pilihan_c'] ?? null,
                    'pilihan_d' => $soal['pilihan_d'] ?? null,
                    'jawaban_benar' => $soal['jawaban_benar'],
                    'nilai_bobot' => $soal['nilai_bobot'] ?? 10,
                ]);
            }
            
            // C. AUTO-TRIGGER: Buat Entri NilaiKuis
            $mahasiswaKelas = MahasiswaKelas::where('kelas_id', $kelasId)->get();
            
            foreach ($mahasiswaKelas as $mk) {
                NilaiKuis::create([
                    'kuis_id' => $kuis->id,
                    'mahasiswa_id' => $mk->mahasiswa_id,
                    'nilai' => 0, 
                    'status_pengerjaan' => 'pending'
                ]);
            }

            // 💡 KOREKSI TRANSAKSI: Gunakan Facade DB
            DB::commit(); // Menggantikan Kuis::getConnection()->commit();
            
            $successData = ['status' => 'success', 'message' => 'Kuis dan entri nilai berhasil dibuat.'];
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(201)
                ->getBody()
                ->write(json_encode($successData));

        } catch (\Exception $e) {
            // 💡 KOREKSI TRANSAKSI: Gunakan Facade DB
            DB::rollBack(); // Menggantikan Kuis::getConnection()->rollBack();
            
            $errorData = ['status' => 'error', 'message' => 'Gagal menyimpan kuis: ' . $e->getMessage()];
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->getBody()
                ->write(json_encode($errorData));
        }
    });
    // ... Tambahkan GET /detail/{id} dan PUT /update/{id}
    // ... Tambahkan DELETE /delete/{id}
});

$app->put('/api/tugas/nilai/{submission_id}', function (Request $request, Response $response, array $args) {
    $submissionId = $args['submission_id'];
    $data = (array)$request->getParsedBody();

    try {
        $submission = TugasMahasiswa::find($submissionId);

        if (!$submission) {
            $errorData = ['status' => 'error', 'message' => 'Submission tugas tidak ditemukan.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404)->getBody()->write(json_encode($errorData));
        }

        $submission->update([
            'nilai' => $data['nilai'] ?? $submission->nilai,
            'catatan_dosen' => $data['catatan_dosen'] ?? $submission->catatan_dosen,
        ]);

        $successData = ['status' => 'success', 'message' => 'Nilai tugas berhasil disimpan.'];
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200)->getBody()->write(json_encode($successData));

    } catch (\Exception $e) {
        $errorData = ['status' => 'error', 'message' => 'Gagal menyimpan nilai: ' . $e->getMessage()];
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500)->getBody()->write(json_encode($errorData));
    }
});

$app->get('/api/nilai/konsolidasi-dosen/{kelas_id}', function (Request $request, Response $response, array $args) {
    $kelasId = $args['kelas_id'];

    try {
        // Cek Keberadaan Kelas
        $kelas = Kelas::find($kelasId);
        if (!$kelas) {
            $errorData = ['status' => 'error', 'message' => 'Kelas tidak ditemukan.'];
            $response->getBody()->write(json_encode($errorData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 1. Ambil Semua Mahasiswa di Kelas
        $mahasiswaList = $kelas->mahasiswa()
            ->select('mahasiswas.id', 'mahasiswas.nim', 'mahasiswas.nama')
            ->get()
            ->keyBy('id');

        // 2. Ambil Daftar Tugas dan Kuis di Kelas
        $tugasList = Tugas::where('kelas_id', $kelasId)->get(['id', 'judul_tugas']);
        $kuisList = Kuis::where('kelas_id', $kelasId)->get(['id', 'judul']);

        // 3. Ambil Semua Data Nilai
        $allTugasSubmissions = TugasMahasiswa::whereIn('tugas_id', $tugasList->pluck('id'))->get();
        $allNilaiKuis = NilaiKuis::whereIn('kuis_id', $kuisList->pluck('id'))->get();

        $results = [];

        // 4. Strukturkan Data
        foreach ($mahasiswaList as $mhs) {
            $mhsData = [
                'id' => $mhs->id,
                'nim' => $mhs->nim,
                'nama' => $mhs->nama,
                'nilai_tugas' => [],
                'nilai_kuis' => [],
            ];

            // Nilai Tugas
            foreach ($tugasList as $tugas) {
                $submission = $allTugasSubmissions
                    ->where('tugas_id', $tugas->id)
                    ->where('mahasiswa_id', $mhs->id)
                    ->first();

                $mhsData['nilai_tugas'][] = [
                    'id' => $tugas->id,
                    'judul' => $tugas->judul_tugas,
                    'nilai' => $submission ? ($submission->nilai ?? 'N/A') : 'Belum Kumpul',
                ];
            }

            // Nilai Kuis
            foreach ($kuisList as $kuis) {
                $nilaiKuis = $allNilaiKuis
                    ->where('kuis_id', $kuis->id)
                    ->where('mahasiswa_id', $mhs->id)
                    ->first();

                $mhsData['nilai_kuis'][] = [
                    'id' => $kuis->id,
                    'judul' => $kuis->judul,
                    'nilai' => $nilaiKuis ? ($nilaiKuis->nilai ?? 'N/A') : 'Pending',
                    'status' => $nilaiKuis ? $nilaiKuis->status_pengerjaan : 'N/A',
                ];
            }

            $results[] = $mhsData;
        }

        // 5. Response Sukses
        $responseData = [
            'status' => 'success',
            'data' => $results,
            'meta' => [
                'tugas_list' => $tugasList->toArray(),
                'kuis_list' => $kuisList->toArray(),
            ]
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (\Exception $e) {

        // Penanganan Error
        $errorData = [
            'status' => 'error',
            'message' => 'Internal Server Error: ' . $e->getMessage()
        ];

        $response->getBody()->write(json_encode($errorData));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});

    // === LOGIN ===
    $app->post('/login', function (Request $request, Response $response) {
        $data = (array)$request->getParsedBody();
        $user = User::where('email', $data['email'])->first();

        if (!$user || !password_verify($data['password'], $user->password)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Email atau password salah'
            ]));
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        $payload = [
            'iss' => 'backend-kampus-sangat-sejahtera',
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + 8 * 3600
        ];

        $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'] ?? 'supersecretkey', 'HS256');

        $redirect = match ($user->role) {
            'admin' => '/admin/dashboard',
            'dosen' => '/dosen/dashboard',
            'mahasiswa' => '/mahasiswa/dashboard',
            default => '/',
        };

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Login berhasil',
            'token' => $jwt,
            'dosenId' => $user->role === 'dosen' ? $user->dosen->id : null,
            'redirect' => $redirect,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    });

    // === LOGOUT ===
    $app->post('/logout', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Logout berhasil, token dihapus di sisi klien'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });
$app->get('/api/tugas/download-submission/{filename}', function (Request $request, Response $response, array $args) {
    $filename = $args['filename'];
    
    // Tentukan direktori penyimpanan file submissions
    $uploadDir = __DIR__ . '/storage/submissions'; 
    $fullPath = $uploadDir . '/' . $filename;

    // 1. Cek apakah file benar-benar ada
    if (!file_exists($fullPath)) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'File submission tidak ditemukan di server.'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
    
    // 2. Ambil tipe konten (MIME Type) file
    $mimeType = mime_content_type($fullPath);
    
    // 3. Baca konten file
    $fileStream = fopen($fullPath, 'r');
    
    // 4. Kirim response untuk download
    return $response
        ->withBody(new \Slim\Psr7\Stream($fileStream))
        ->withHeader('Content-Type', $mimeType)
        ->withHeader('Content-Disposition', 'attachment; filename="' . basename($filename) . '"')
        ->withHeader('Content-Length', (string)filesize($fullPath))
        ->withStatus(200);
});
$app->get('/api/pengumuman/by-kelas/{kelas_id}', function (Request $request, Response $response, array $args) {
    $kelasId = $args['kelas_id'];

    // Ambil pengumuman berdasarkan ID Kelas, urutkan dari terbaru
    $pengumumanList = Pengumuman::where('kelas_id', $kelasId)
        ->orderBy('tanggal_pengumuman', 'desc')
        ->get();

    if ($pengumumanList->isEmpty()) {
        $response->getBody()->write(json_encode(['status' => 'success', 'data' => []]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    $response->getBody()->write($pengumumanList->toJson());
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});
$app->get('/api/presensi/status-kelas/{kelas_id}', function (Request $request, Response $response, array $args) {
    $kelasId = $args['kelas_id'];

    // Cek presensi aktif terbaru untuk kelas ini
    $presensiAktif = Presensi::where('kelas_id', $kelasId)
        ->where('status_presensi', 'active')
        // Ambil relasi yang mungkin dibutuhkan (misal: kelas)
        ->with('kelas') 
        ->latest()
        ->first();

    if (!$presensiAktif) {
        $response->getBody()->write(json_encode(['status' => 'closed', 'message' => 'Portal presensi sedang ditutup.']));
        // Mengembalikan status 200 OK meskipun tertutup, karena ini adalah respons valid.
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
    
    // Jika aktif, kembalikan objek presensi
    // Catatan: Kode acak seharusnya tidak di-return ke Mahasiswa, 
    // tetapi kita sertakan untuk debugging/mempermudah pengujian.
    $response->getBody()->write($presensiAktif->toJson());
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});
$app->get('/api/presensi/riwayat-mhs/{kelas_id}/{mhs_id}', function (Request $request, Response $response, array $args) {
    $kelasId = $args['kelas_id'];
    $mhsId = $args['mhs_id'];

    // Ambil semua sesi presensi (closed/finished) untuk kelas ini
    $presensiSesi = Presensi::where('kelas_id', $kelasId)
        // Kita hanya tertarik pada sesi yang sudah selesai (bukan 'active')
        ->where('status_presensi', 'closed') 
        ->orderBy('tanggal_presensi', 'desc')
        ->get();

    $riwayat = [];

    foreach ($presensiSesi as $sesi) {
        // Cari rekaman kehadiran mahasiswa di sesi ini
        $kehadiran = $sesi->mahasiswa() 
            ->where('mahasiswa_id', $mhsId)
            // Memilih kolom pivot untuk status dan waktu
            ->select('status_kehadiran', 'waktu_presensi')
            ->first();

        $status = $kehadiran ? $kehadiran->pivot->status_kehadiran : 'alpha';
        
        $riwayat[] = [
            'presensi_id' => $sesi->id,
            'judul_sesi' => $sesi->judul_presensi,
            'tanggal' => $sesi->tanggal_presensi,
            'status' => $status,
            'waktu_hadir' => $kehadiran ? $kehadiran->pivot->waktu_presensi : null,
        ];
    }

    $response->getBody()->write(json_encode([
        'status' => 'success',
        'data' => $riwayat,
    ]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->get('/api/tugas/submission-detail/{tugas_id}/{mhs_id}', function (Request $request, Response $response, array $args) {
    $tugasId = $args['tugas_id'];
    $mhsId = $args['mhs_id'];

    $submission = TugasMahasiswa::where('tugas_id', $tugasId)
        ->where('mahasiswa_id', $mhsId)
        ->first();

    if (!$submission) {
        // Jika tidak ada submission, kembalikan 404/kosong
        $response->getBody()->write(json_encode(['status' => 'not_found', 'message' => 'Submission belum ada.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    $response->getBody()->write($submission->toJson());
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
})->add(new JwtMiddleware()); // Proteksi dengan JWT jika perlu
    //     // === ROUTE ADMIN YANG DILINDUNGI JWT ===
    //     $app->group('/admin', function (Group $group) {
    //         $group->get('/dashboard', function (Request $request, Response $response) {
    //             $user = $request->getAttribute('user');

    //             $data = [
    //                 'user' => $user,
    //                 'jumlahMahasiswa' => 120,
    //                 'jumlahDosen' => 15,
    //                 'jumlahKelas' => 8,
    //                 'jumlahMatkul' => 24
    //             ];

    //             $response->getBody()->write(json_encode($data));
    //             return $response->withHeader('Content-Type', 'application/json');
    //         });
    //     })->add(new JwtMiddleware());

    //     // === ROUTE MAHASISWA ===
    $app->group('/mahasiswa', function ($group) {

        // GET semua data mahasiswa
        $group->get('', function ($request, $response) {
            $mahasiswa = Mahasiswa::with('fakultas')->get();
            $response->getBody()->write($mahasiswa->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });

        // GET mahasiswa berdasarkan ID
        $group->get('/{id}', function ($request, $response, $args) {
            $mahasiswa = Mahasiswa::with('fakultas')->find($args['id']);

            if (!$mahasiswa) {
                $response->getBody()->write(json_encode(['error' => 'Mahasiswa not found']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($mahasiswa->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });

        // POST: Create new mahasiswa
        $group->post('', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();

            // Create new user
            $user = User::create([
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_BCRYPT),
                'role' => 'mahasiswa',  // Set role as mahasiswa
            ]);

            // Create new mahasiswa and associate with the newly created user
            $mahasiswa = Mahasiswa::create([
                'nim' => $data['nim'],
                'nama' => $data['nama'],
                'fakultas_id' => $data['fakultas_id'],
                'alamat' => $data['alamat'] ?? '',
                'no_telepon' => $data['no_telepon'] ?? '',
                'user_id' => $user->id  // Associate user_id
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Mahasiswa berhasil ditambahkan',
                'mahasiswa' => $mahasiswa,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });


        // PUT untuk memperbarui data mahasiswa
        $group->put('/{id}', function ($request, $response, $args) {
            $data = $request->getParsedBody();
            $mahasiswa = Mahasiswa::find($args['id']);  // Mencari mahasiswa berdasarkan ID

            if (!$mahasiswa) {
                // Jika mahasiswa tidak ditemukan, kembalikan respons error
                $response->getBody()->write(json_encode(['error' => 'Mahasiswa tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            // Validasi data yang diperlukan
            $requiredFields = ['nim', 'nama', 'fakultas_id', 'alamat', 'no_telepon'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $response->withJson(['error' => "Field $field harus diisi"], 400);
                }
            }

            // Update data mahasiswa
            $mahasiswa->update($data);

            // Kembalikan data mahasiswa yang sudah diperbarui
            $response->getBody()->write($mahasiswa->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });


        // DELETE hapus mahasiswa
        $group->delete('/{id}', function ($request, $response, $args) {
            $mahasiswa = Mahasiswa::find($args['id']);

            if (!$mahasiswa) {
                $response->getBody()->write(json_encode(['error' => 'Mahasiswa not found']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $mahasiswa->delete();
            $response->getBody()->write(json_encode(['message' => 'Mahasiswa deleted successfully']));
            return $response->withHeader('Content-Type', 'application/json');
        });
    })->add(new JwtMiddleware()); // 🔒 Proteksi seluruh grup route dengan JWT

    $app->group('/admin', function (Group $group) {
        $group->get('/dashboard', function (Request $request, Response $response) {
            $user = $request->getAttribute('user'); // JWT user info

            // Fetch the necessary data from the models
            $jumlahMahasiswa = Mahasiswa::count();
            $jumlahDosen = Dosen::count();
            $jumlahKelas = Kelas::count();
            $jumlahMatkul = Matakuliah::count();

            // Prepare the response data
            $data = [
                'user' => $user,
                'jumlahMahasiswa' => $jumlahMahasiswa,
                'jumlahDosen' => $jumlahDosen,
                'jumlahKelas' => $jumlahKelas,
                'jumlahMatkul' => $jumlahMatkul
            ];

            // Send back the data as a JSON response
            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        });
    })->add(new JwtMiddleware());  // Ensure JWT middleware for authentication

    $app->get('/api/materi/{kode}', function ($request, $response, $args) {

        $kode = $args['kode'];

        // Ambil matakuliah berdasarkan kode
        $matakuliah = \App\Models\Matakuliah::where('kode_matakuliah', $kode)->first();

        if (!$matakuliah) {
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => 'Matakuliah tidak ditemukan'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Ambil kelas-kelas yang mengajar matakuliah tersebut
        // Menggunakan with('dosen') untuk Eager Loading (asumsi ada relasi 'dosen' di model Kelas)
        $kelasList = \App\Models\Kelas::where('matakuliah_id', $matakuliah->id)
            ->with('dosen') // Asumsi relasi ke model Dosen bernama 'dosen'
            ->get();

        $result = [];

        foreach ($kelasList as $kelas) {

            // Ambil materi berdasarkan id kelas
            $materi = \App\Models\Materi::where('kelas_id', $kelas->id)->get();

            $result[] = [
                'kelas_id' => $kelas->id,
                'nama_kelas' => $kelas->nama_kelas,
                // Tambahkan ID Dosen
                'dosen_id' => $kelas->dosen_id,
                // Tambahkan Nama Dosen (dari relasi Eager Loaded)
                'nama_dosen' => $kelas->dosen ? $kelas->dosen->nama : 'N/A', // Asumsi kolom nama di model Dosen adalah 'nama'
                'materi' => $materi
            ];
        }

        // Response final
        $payload = [
            'kode' => $kode,
            'matakuliah' => $matakuliah->nama_matakuliah,
            'kelas' => $result
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // Contoh di file routes.php atau sejenisnya
    //Presensi
    $app->get('/api/presensi/matakuliah/{kode}', function (Request $request, Response $response, array $args) {
        $kode = $args['kode'];

        $matakuliah = \App\Models\Matakuliah::where('kode_matakuliah', $kode)->first();

        if (!$matakuliah) {
            // ... (Error handling)
        }

        // Ambil kelas yang mengajar matakuliah ini
        $kelasList = Kelas::with('dosen')
            ->where('matakuliah_id', $matakuliah->id)
            ->get();

        $result = [];
        foreach ($kelasList as $kelas) {
            $dosen = $kelas->dosen;

            // Cek presensi aktif terbaru untuk kelas ini
            $presensiAktif = Presensi::where('kelas_id', $kelas->id)
                ->where('status_presensi', 'active')
                ->latest()
                ->first();

            $result[] = [
                'kelas_id' => $kelas->id,
                'nama_kelas' => $kelas->nama_kelas,
                'dosen_id' => $kelas->dosen_id,
                'nama_dosen' => $dosen ? $dosen->nama : 'N/A',
                'presensi_aktif' => $presensiAktif, // Mengembalikan objek presensi jika aktif
            ];
        }

        $payload = [
            'kode' => $kode,
            'matakuliah' => $matakuliah->nama_matakuliah,
            'matakuliah_id' => $matakuliah->id,
            'kelas' => $result
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // ====================================================
    // 8. Dosen: Ambil Detail Sesi Presensi dan Daftar Hadir Mahasiswa
    // ====================================================
    $app->get('/api/presensi/detail/{presensi_id}', function (Request $request, Response $response, array $args) {
        $presensi_id = $args['presensi_id'];

        // Ambil sesi presensi, termasuk kelas dan matakuliah
        $presensi = Presensi::with(['kelas.matakuliah', 'mahasiswa']) // Ambil relasi mahasiswa yang sudah presensi
            ->find($presensi_id);

        if (!$presensi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Sesi presensi tidak ditemukan.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $kelasId = $presensi->kelas_id;

        // Ambil SEMUA mahasiswa yang terdaftar di kelas ini
        $semuaMahasiswaKelas = MahasiswaKelas::where('kelas_id', $kelasId)
            ->with('mahasiswa') // Asumsi relasi 'mahasiswa' di model MahasiswaKelas
            ->get();

        // Mapping untuk menggabungkan data kehadiran
        $mahasiswaList = $semuaMahasiswaKelas->map(function ($mk) use ($presensi) {
            $mhs = $mk->mahasiswa;

            // Cari status kehadiran mahasiswa ini di sesi presensi
            $kehadiran = $presensi->mahasiswa->firstWhere('id', $mhs->id);

            return [
                'id' => $mhs->id,
                'nim' => $mhs->nim,
                'nama' => $mhs->nama,
                'status_kehadiran' => $kehadiran ? $kehadiran->pivot->status_kehadiran : 'alpha', // Default 'alpha'
                'waktu_presensi' => $kehadiran ? $kehadiran->pivot->waktu_presensi : null,
            ];
        });

        $payload = [
            'presensi_id' => $presensi->id,
            'judul_presensi' => $presensi->judul_presensi,
            'tanggal_sesi' => $presensi->tanggal_presensi,
            'kelas' => [
                'nama_kelas' => $presensi->kelas->nama_kelas,
                'matakuliah' => $presensi->kelas->matakuliah->nama_matakuliah,
            ],
            'kode_acak' => $presensi->kode_acak,
            'status_portal' => $presensi->status_presensi,
            'daftar_hadir' => $mahasiswaList,
            'total_mahasiswa' => $semuaMahasiswaKelas->count(),
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });

    // ====================================================
    // 9. Dosen: Update Status Kehadiran Mahasiswa (Edit/Nilai)
    // ====================================================
    $app->put('/api/presensi/kehadiran/update/{presensi_id}/{mahasiswa_id}', function (Request $request, Response $response, array $args) {
        $presensi_id = $args['presensi_id'];
        $mahasiswa_id = $args['mahasiswa_id'];
        $data = $request->getParsedBody();

        // Validasi status
        $validStatuses = ['hadir', 'izin', 'sakit', 'alpha'];
        if (!in_array($data['status_kehadiran'], $validStatuses)) {
            // ... (Error handling)
        }

        $presensi = Presensi::find($presensi_id);
        if (!$presensi) {
            // ... (Error handling 404)
        }

        // Sync/Update data di tabel pivot (presensi_mahasiswa)
        $presensi->mahasiswa()->syncWithoutDetaching([
            $mahasiswa_id => [
                'status_kehadiran' => $data['status_kehadiran'],
                'waktu_presensi' => $data['waktu_presensi'] ?? date('Y-m-d H:i:s'), // Gunakan waktu sekarang jika status diubah manual ke Hadir/Izin/Sakit
            ]
        ]);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Kehadiran berhasil diperbarui.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // ====================================================
    // 2. Dosen: Buka Portal Presensi (Create)
    // ====================================================
    $app->post('/api/presensi/create', function (Request $request, Response $response) {
        $data = $request->getParsedBody();

        // 1. Cek apakah sudah ada presensi aktif untuk kelas ini
        $existingPresensi = Presensi::where('kelas_id', $data['kelas_id'])
            ->where('status_presensi', 'active')
            ->first();

        if ($existingPresensi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Portal presensi sudah aktif untuk kelas ini.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // 2. Buat kode acak jika kosong
        $kodeAcak = $data['kode_acak'] ?? substr(md5(microtime()), rand(0, 26), 5);

        // 3. Simpan Presensi
        $presensi = Presensi::create([
            'matakuliah_id' => $data['matakuliah_id'],
            'kelas_id' => $data['kelas_id'],
            'dosen_id' => $data['dosen_id'],
            'judul_presensi' => $data['judul_presensi'],
            'tanggal_presensi' => $data['tanggal_presensi'],
            'kode_acak' => $kodeAcak,
            'status_presensi' => 'active',
        ]);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Portal presensi berhasil dibuka!', 'data' => $presensi]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });

    // ====================================================
    // 3. Dosen: Tutup Portal Presensi (Update)
    // ====================================================
    $app->put('/api/presensi/close/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];

        $presensi = Presensi::find($id);

        if (!$presensi) {
            // ... (Error handling 404)
        }

        $presensi->status_presensi = 'closed';
        $presensi->save();

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Portal presensi berhasil ditutup.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });


    // ====================================================
    // 4. Mahasiswa: Endpoint untuk Melakukan Presensi (NEW)
    // ====================================================
    $app->post('/api/mahasiswa/presensi', function (Request $request, Response $response) {
        $data = $request->getParsedBody();

        $presensiId = $data['presensi_id'];
        $mahasiswaNim = $data['nim'];
        $kodeAcakInput = $data['kode_acak'];

        // 1. Cari sesi presensi aktif
        $presensi = Presensi::where('id', $presensiId)
            ->where('status_presensi', 'active')
            ->first();

        if (!$presensi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Sesi presensi tidak aktif atau tidak ditemukan.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Validasi Kode Acak
        if ($presensi->kode_acak && $presensi->kode_acak !== $kodeAcakInput) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Kode acak salah.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // 3. Cari Mahasiswa berdasarkan NIM
        $mahasiswa = \App\Models\Mahasiswa::where('nim', $mahasiswaNim)->first();
        if (!$mahasiswa) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Mahasiswa tidak ditemukan.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 4. Cek apakah mahasiswa sudah presensi
        $sudahPresensi = $presensi->mahasiswa()->where('mahasiswa_id', $mahasiswa->id)->exists();

        if ($sudahPresensi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Anda sudah melakukan presensi pada sesi ini.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // 5. Simpan presensi mahasiswa (Status default: Hadir)
        $presensi->mahasiswa()->attach($mahasiswa->id, [
            'status_kehadiran' => 'hadir',
            'waktu_presensi' => date('Y-m-d H:i:s')
        ]);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Presensi berhasil dicatat!']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });
    // Contoh perbaikan di file routes.php
    $app->get('/api/dosen/by-user/{userId}', function ($request, $response, $args) {
        $userId = $args['userId'];

        $dosen = \App\Models\Dosen::where('user_id', $userId)->first();

        if (!$dosen) {
            $response->getBody()->write(json_encode(['error' => 'Dosen tidak ditemukan']));
            // Mengatur Content-Type dan Status
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Ubah objek Dosen menjadi array/JSON
        $payload = $dosen->toArray(); // Pastikan model Dosen menggunakan trait Arrayable

        // Menulis payload JSON ke response body
        $response->getBody()->write(json_encode($payload));

        // Mengatur Content-Type dan Status
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    $app->post('/api/materi/store', function (Request $request, Response $response) {

        $parsed = $request->getParsedBody() ?? [];

        $kelas_id = $parsed['kelas_id'] ?? null;
        $judul = $parsed['judul_materi'] ?? null;
        $deskripsi = $parsed['deskripsi'] ?? null;

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file_materi'] ?? null;

        $filename = null;

        // PATH KONSISTENSI DITERAPKAN DI SINI: ASUMSI /app/storage/materi
        $uploadDir = __DIR__ . '/storage/materi';
        // Jika path sebelumnya (/../../storage/materi) yang benar, gunakan itu, tapi pastikan konsisten!

        if ($file && $file->getError() === UPLOAD_ERR_OK) {
            $originalName = $file->getClientFilename();
            $filename = time() . "_" . preg_replace("/[^A-Za-z0-9\.\-_]/", "", $originalName);

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fullPath = $uploadDir . "/" . $filename;
            $file->moveTo($fullPath);
        }

        // Cek apakah kelas ditemukan sebelum mencoba mengakses dosen_id
        $kelas = \App\Models\Kelas::find($kelas_id);
        if (!$kelas) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Kelas tidak ditemukan'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $dosen_id = $kelas->dosen_id;

        try {
            $materi = \App\Models\Materi::create([
                'kelas_id' => $kelas_id,
                'dosen_id' => $dosen_id,
                'judul_materi' => $judul,
                'deskripsi' => $deskripsi,
                'file_materi' => $filename
            ]);

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => 'Materi berhasil ditambahkan',
                'materi' => $materi
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201); // 201 Created

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Database Error: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });


    $app->post('/api/materi/update/{id}', function (Request $request, Response $response, $args) {
        $id = $args['id'];

        $data = $request->getParsedBody() ?? [];
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file_materi'] ?? null;

        // PATH KONSISTENSI DITERAPKAN DI SINI
        $uploadDir = __DIR__ . '/storage/materi';

        $materi = \App\Models\Materi::find($id);
        if (!$materi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Materi tidak ditemukan']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $updateData = [];

        if (isset($data['judul_materi'])) {
            $updateData['judul_materi'] = $data['judul_materi'];
        }
        if (isset($data['deskripsi'])) {
            $updateData['deskripsi'] = $data['deskripsi'];
        }

        // Handle File Upload Opsional
        if ($file && $file->getError() === UPLOAD_ERR_OK) {

            // Hapus file lama jika ada (optional, tapi disarankan)
            if ($materi->file_materi) {
                $oldPath = $uploadDir . '/' . $materi->file_materi;
                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            $originalName = $file->getClientFilename();
            $filename = time() . "_" . preg_replace("/[^A-Za-z0-9\.\-_]/", "", $originalName);

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fullPath = $uploadDir . "/" . $filename;
            $file->moveTo($fullPath);

            $updateData['file_materi'] = $filename;
        }

        try {
            $materi->update($updateData);

            $response->getBody()->write(json_encode([
                "status" => "success",
                "message" => "Materi berhasil diperbarui",
                "materi" => $materi
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->setName('update-materi-full');
    $app->post('/api/presensi/pertemuan', function (Request $request, Response $response) {
        $data = $request->getParsedBody();

        $kelas_id = $data['kelas_id'] ?? null;
        $pertemuan_ke = $data['pertemuan_ke'] ?? null;

        if (!$kelas_id || !$pertemuan_ke) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'kelas_id dan pertemuan_ke wajib diisi'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $presensi = \App\Models\PresensiPertemuan::create([
            'kelas_id' => $kelas_id,
            'pertemuan_ke' => $pertemuan_ke,
            'tanggal' => date('Y-m-d')
        ]);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Presensi pertemuan dibuat',
            'data' => $presensi
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });
    $app->post('/api/presensi/detail', function (Request $request, Response $response) {
        $data = $request->getParsedBody();

        $presensi_pertemuan_id = $data['presensi_pertemuan_id'] ?? null;
        $mahasiswa_id = $data['mahasiswa_id'] ?? null;
        $status = $data['status'] ?? 'alfa';

        if (!$presensi_pertemuan_id || !$mahasiswa_id) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'presensi_pertemuan_id dan mahasiswa_id wajib diisi'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $detail = \App\Models\PresensiDetail::create([
            'presensi_pertemuan_id' => $presensi_pertemuan_id,
            'mahasiswa_id' => $mahasiswa_id,
            'status' => $status
        ]);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Presensi tersimpan',
            'data' => $detail
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    });
    // Tambahkan di file routes.php backend Anda

    // ====================================================
    // NEW: GET MATERI BERDASARKAN ID KELAS
    // URL: /api/materi/by-kelas/{kelas_id}
    // Digunakan oleh Mahasiswa untuk mengambil materi spesifik kelas yang dia ikuti.
    // ====================================================
    $app->get('/api/materi/by-kelas/{kelas_id}', function (Request $request, Response $response, array $args) {
        $kelasId = $args['kelas_id'];

        // 1. Ambil detail Kelas, Matakuliah, dan Dosen
        $kelas = \App\Models\Kelas::with(['matakuliah', 'dosen'])->find($kelasId);

        if (!$kelas) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Kelas tidak ditemukan']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Ambil materi yang terkait langsung dengan ID Kelas ini
        $materiList = \App\Models\Materi::where('kelas_id', $kelasId)
            ->orderBy('created_at', 'desc')
            ->get();

        $payload = [
            'status' => 'success',
            'kelas_id' => $kelasId,
            'nama_kelas' => $kelas->nama_kelas,
            'matakuliah' => [
                'nama' => $kelas->matakuliah->nama_matakuliah,
                'kode' => $kelas->matakuliah->kode_matakuliah,
            ],
            'dosen' => $kelas->dosen ? $kelas->dosen->nama : 'N/A',
            'data_materi' => $materiList
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    $app->get('/api/presensi/pertemuan/{id}', function (Request $request, Response $response, $args) {

        $id = $args['id'];

        $data = \App\Models\PresensiDetail::where('presensi_pertemuan_id', $id)->get();

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $data
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    $app->get('/api/presensi/kelas/{kelas_id}', function (Request $request, Response $response, $args) {

        $kelas_id = $args['kelas_id'];

        $pertemuan = \App\Models\PresensiPertemuan::where('kelas_id', $kelas_id)->get();

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $pertemuan
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // ====================================================
    // 10. Dosen: Ambil Riwayat Sesi Presensi per Kelas
    // ====================================================
    $app->get('/api/presensi/riwayat/{kelas_id}', function (Request $request, Response $response, array $args) {
        $kelasId = $args['kelas_id'];

        // Ambil semua presensi yang statusnya BUKAN 'active' (yaitu 'closed' atau status lain)
        $riwayatPresensi = Presensi::where('kelas_id', $kelasId)
            ->where('status_presensi', '!=', 'active')
            ->orderBy('tanggal_presensi', 'desc')
            ->get();

        // Opsional: Hitung jumlah kehadiran (hadir) untuk setiap sesi
        $riwayatPresensi->map(function ($presensi) {
            $presensi->total_hadir = $presensi->mahasiswa()->where('status_kehadiran', 'hadir')->count();
            return $presensi;
        });

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $riwayatPresensi
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // ====================================================
    // 11. Dosen: Hapus Sesi Presensi (DELETE)
    // ====================================================
    $app->delete('/api/presensi/delete/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];
        $presensi = Presensi::find($id);

        if (!$presensi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Sesi presensi tidak ditemukan.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Opsional: Hapus semua entri di tabel pivot (presensi_mahasiswa) terlebih dahulu
        $presensi->mahasiswa()->detach();

        $presensi->delete();

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Sesi presensi berhasil dihapus.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // ====================================================
    // 12. Dosen: Update Sesi Presensi (PUT/POST)
    // ====================================================
    $app->post('/api/presensi/update/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];
        $data = $request->getParsedBody();

        // Cek jika request datang dari form dengan _method=PUT
        if (isset($data['_method']) && strtoupper($data['_method']) === 'PUT') {
            unset($data['_method']);
        } else {
            // Jika tidak ada _method=PUT, pastikan method request adalah PUT (jika Anda menggunakan middleware)
            // Karena kita pakai POST di frontend + _method, kita abaikan cek ini.
        }

        $presensi = Presensi::find($id);

        if (!$presensi) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Sesi presensi tidak ditemukan.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Hanya izinkan update pada field yang diperbolehkan
        $updateData = [
            'judul_presensi' => $data['judul_presensi'] ?? $presensi->judul_presensi,
            'tanggal_presensi' => $data['tanggal_presensi'] ?? $presensi->tanggal_presensi,
            'kode_acak' => $data['kode_acak'] ?? $presensi->kode_acak,
            'status_presensi' => $data['status_presensi'] ?? $presensi->status_presensi,
        ];

        $presensi->update($updateData);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Sesi presensi berhasil diperbarui.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    //tugas pov mahasiswa
    $app->delete('/api/materi/delete/{id}', function ($request, $response, $args) {
        $id = $args['id'];

        // Tentukan direktori penyimpanan file (HARUS SAMA dengan route store)
        // Asumsi: direktori 'storage/materi' berada satu tingkat di atas file routes.php
        $uploadDir = __DIR__ . '/storage/materi';

        // 1. Cari Materi berdasarkan ID
        $materi = \App\Models\Materi::find($id);

        // 2. Cek apakah materi ditemukan
        if (!$materi) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Materi tidak ditemukan'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 3. LOGIKA PENGHAPUSAN FILE
        if ($materi->file_materi) {
            $fullPath = $uploadDir . '/' . $materi->file_materi;

            // Cek apakah file fisik ada di server
            if (file_exists($fullPath)) {
                // Hapus file fisik
                unlink($fullPath);
                // Anda dapat menambahkan logging di sini jika diperlukan
            }
        }
        // AKHIR LOGIKA PENGHAPUSAN FILE

        // 4. Hapus Materi dari Database
        $materi->delete();

        $response->getBody()->write(json_encode(["status" => "success", "message" => "Materi berhasil dihapus"]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // ====================================================
    // CRUD TUGAS BERDASARKAN KELAS
    // ====================================================
    // ====================================================
    // NEW: GET TUGAS PER KELAS BERDASARKAN KODE MATAKULIAH
    // URL: /api/tugas/matakuliah/{kode}
    // ====================================================// ====================================================
    // GET TUGAS PER KELAS BERDASARKAN KODE MATAKULIAH
    // URL: /api/tugas/matakuliah/{kode}
    // ====================================================
    $app->get('/api/tugas/matakuliah/{kode}', function (Request $request, Response $response, array $args) {
        $kode = $args['kode'];

        // 1. Ambil Matakuliah berdasarkan kode
        $matakuliah = \App\Models\Matakuliah::where('kode_matakuliah', $kode)->first();

        if (!$matakuliah) {
            // ... (Error handling 404)
            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => 'Matakuliah tidak ditemukan'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Ambil kelas-kelas yang mengajar matakuliah tersebut
        // Tetap gunakan Eager Loading untuk 'dosen' karena dibutuhkan di frontend untuk filtering/nama.
        $kelasList = \App\Models\Kelas::with(['tugas', 'dosen'])
            ->where('matakuliah_id', $matakuliah->id)
            ->get();

        $result = [];

        foreach ($kelasList as $kelas) {

            $dosen = $kelas->dosen;
            $tugas = $kelas->tugas;

            $result[] = [
                'kelas_id' => $kelas->id,
                'nama_kelas' => $kelas->nama_kelas,

                // --- BAGIAN INI DIUBAH UNTUK SIMPLIFIKASI ---
                'dosen_id' => $kelas->dosen_id, // Menggunakan dosen_id langsung dari tabel Kelas
                'nama_dosen' => $dosen ? $dosen->nama : 'N/A', // Menambahkan nama dosen untuk kebutuhan frontend

                'tugas' => $tugas
            ];
        }

        // Response final
        $payload = [
            'kode' => $kode,
            'matakuliah' => $matakuliah->nama_matakuliah,
            'kelas' => $result
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
  $app->post('/api/tugas/submit', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();

    $tugasId = $data['tugas_id'] ?? null;
    $mahasiswaId = $data['mahasiswa_id'] ?? null;
    $file = $uploadedFiles['file_submission'] ?? null;
    $uploadDir = __DIR__ . '/storage/submissions'; 
    $filename = null;

    // --- 1. VALIDASI & UPLOAD ---
    if (empty($tugasId) || empty($mahasiswaId) || !$file || $file->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Data tidak lengkap atau file gagal diunggah.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
    
    // Pindahkan file ke server
    try {
        $filename = time() . "_" . preg_replace("/[^A-Za-z0-9\.\-_]/", "", $file->getClientFilename());
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $file->moveTo($uploadDir . "/" . $filename);
        
        // --- 2. CEK/UPDATE DATABASE (Menggunakan Kolom DB yang Benar) ---
        $submission = TugasMahasiswa::where('tugas_id', $tugasId)
            ->where('mahasiswa_id', $mahasiswaId)
            ->first();
        
        $currentTime = date('Y-m-d H:i:s');
        
        if ($submission) {
            // Update submission yang sudah ada (Revisi)
            if ($submission->file_kumpul && file_exists($uploadDir . '/' . $submission->file_kumpul)) {
                 unlink($uploadDir . '/' . $submission->file_kumpul);
            }
            
            // Menggunakan kolom file_kumpul dan tanggal_kumpul
            $submission->update([
                'file_kumpul' => $filename,
                'tanggal_kumpul' => $currentTime,
                'status' => 'submitted' 
            ]);
        } else {
            // Submission Baru
            $submission = TugasMahasiswa::create([
                'tugas_id' => $tugasId,
                'mahasiswa_id' => $mahasiswaId,
                'file_kumpul' => $filename,
                'tanggal_kumpul' => $currentTime,
                'status' => 'submitted'
            ]);
        }

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Tugas berhasil dikumpulkan!', 'data' => $submission]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        
    } catch (\Exception $e) {
        // Hapus file yang sudah terlanjur di-upload jika database gagal
        if ($filename && file_exists($uploadDir . '/' . $filename)) {
             unlink($uploadDir . '/' . $filename);
        }
        
        $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Database Error atau File Permission: ' . $e->getMessage()]));
        // [🛑 PENTING] Pastikan response dikembalikan dari sini
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500); 
    }
})->add(new JwtMiddleware());
    // ====================================================
    // GET DETAIL TUGAS DAN SEMUA PENGUMPULAN MAHASISWA
    // URL: /api/tugas/submissions/{id}
    // ====================================================
    $app->get('/api/tugas/submissions/{id}', function (Request $request, Response $response, array $args) {
        $tugasId = $args['id'];

        // Ambil data Tugas dan lampirkan semua submissions (tugas_mahasiswa)
        // Juga sertakan detail Mahasiswa dan Kelas (jika diperlukan)
        $tugas = \App\Models\Tugas::with(['submissions.mahasiswa', 'kelas'])
            ->find($tugasId);

        if (!$tugas) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Tugas tidak ditemukan.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Hitung total mahasiswa di kelas tersebut (asumsi Kelas memiliki relasi 'mahasiswa')
        $totalMahasiswa = $tugas->kelas->mahasiswa()->count();

        // Tambahkan data total mahasiswa ke response
        $tugas->total_mahasiswa = $totalMahasiswa;

        // Response Final
        $response->getBody()->write($tugas->toJson());
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // Tambahkan ini di dalam block `return function (App $app) { ... }`
    $app->get('/api/kelas/{id}', function ($request, $response, $args) {
        // Pastikan kode di dalam sini berjalan, dan mengembalikan response 200 JSON
        $kelas = \App\Models\Kelas::with(['matakuliah', 'dosen'])->find($args['id']);

        if (!$kelas) {
            // Jika tidak ditemukan, kembalikan 404
            $response->getBody()->write(json_encode(['error' => 'Kelas tidak ditemukan']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Kembalikan data kelas (200 OK)
        $response->getBody()->write($kelas->toJson());
        return $response->withHeader('Content-Type', 'application/json');

        // ... pastikan Anda juga menambahkan ini di bagian group('/kelas') yang lain
    });
    // ====================================================
    // Tambahan di Grup Kelas (atau Buat Grup Baru untuk Admin)
    // ====================================================

    // NEW: GET MAHASISWA ELIGIBLE BERDASARKAN KELAS ID
    $app->get('/api/kelas/{id}/eligible-mahasiswa', function (Request $request, Response $response, array $args) {
        $kelasId = $args['id'];

        // 1. Cari Kelas dan Matakuliah-nya
        $kelas = \App\Models\Kelas::with('matakuliah.fakultas')->find($kelasId);

        if (!$kelas) {
            $response->getBody()->write(json_encode(['error' => 'Kelas tidak ditemukan']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $fakultasId = $kelas->matakuliah->fakultas_id ?? null;

        if (!$fakultasId) {
            $response->getBody()->write(json_encode(['error' => 'Fakultas Matakuliah tidak ditemukan']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // 2. Ambil ID mahasiswa yang sudah terdaftar di kelas ini
        $registeredMhsIds = $kelas->mahasiswa->pluck('id')->toArray();

        // 3. Ambil semua Mahasiswa dari Fakultas yang SAMA yang BELUM terdaftar
        $eligibleMahasiswa = \App\Models\Mahasiswa::with('fakultas')
            ->where('fakultas_id', $fakultasId)
            ->whereNotIn('id', $registeredMhsIds)
            ->get();

        $response->getBody()->write($eligibleMahasiswa->toJson());
        return $response->withHeader('Content-Type', 'application/json');
    });

    // NEW: POST UNTUK MENAMBAHKAN MAHASISWA KE KELAS
    $app->post('/api/kelas/{id}/add-mahasiswa', function (Request $request, Response $response, array $args) {
        $kelasId = $args['id'];
        $data = (array)$request->getParsedBody();
        $mahasiswaId = $data['mahasiswa_id'] ?? null;

        if (empty($mahasiswaId)) {
            $response->getBody()->write(json_encode(['error' => 'Mahasiswa ID harus disediakan']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $kelas = \App\Models\Kelas::find($kelasId);
        $mahasiswa = \App\Models\Mahasiswa::find($mahasiswaId);

        if (!$kelas || !$mahasiswa) {
            $response->getBody()->write(json_encode(['error' => 'Kelas atau Mahasiswa tidak ditemukan']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            // Asumsi relasi Many-to-Many antara Kelas dan Mahasiswa menggunakan method attach()
            // (Misalnya melalui tabel pivot 'kelas_mahasiswa' atau 'krs' seperti di ERD Anda)
            $kelas->mahasiswa()->attach($mahasiswaId);

            $response->getBody()->write(json_encode(['success' => true, 'message' => 'Mahasiswa berhasil ditambahkan ke kelas.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            // Menangkap error jika mahasiswa sudah terdaftar (duplicate entry)
            $response->getBody()->write(json_encode(['error' => 'Gagal menambahkan mahasiswa: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });


    $app->group('/api/tugas', function (Group $group) {
        // 1. GET: Ambil semua tugas berdasarkan kelas_id
        $group->get('/kelas/{kelasId}', function (Request $request, Response $response, array $args) {
            $kelasId = $args['kelasId'];
            $tugas = \App\Models\Tugas::where('kelas_id', $kelasId)->get();

            $response->getBody()->write($tugas->toJson());
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // Alias route: support legacy frontend path `/api/tugas/by-kelas/{id}`
        // $group->get('/by-kelas/{kelasId}', function (Request $request, Response $response, array $args) {
        //     $kelasId = $args['kelasId'];
        //     $tugas = \App\Models\Tugas::where('kelas_id', $kelasId)->get();

        //     $response->getBody()->write($tugas->toJson());
        //     return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        // });
        $group->get('/by-kelas/{kelasId}', function (Request $request, Response $response, array $args) {
    $kelasId = $args['kelasId'];
    
    // PENTING: Eager Load relasi 'submissions'
    $tugas = Tugas::where('kelas_id', $kelasId)
        ->with(['submissions']) 
        ->get();

    $response->getBody()->write($tugas->toJson());
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

        // 2. POST: Buat Tugas Baru
        $group->post('/store', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();

            // Pastikan kelas_id, judul_tugas, deskripsi ada
            if (empty($data['kelas_id']) || empty($data['judul_tugas'])) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Kelas ID dan Judul Tugas wajib diisi.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $tugas = \App\Models\Tugas::create([
                'kelas_id' => $data['kelas_id'],
                'judul_tugas' => $data['judul_tugas'],
                'deskripsi' => $data['deskripsi'] ?? '',
                'tanggal_pengumpulan' => $data['tanggal_pengumpulan'],
                'file_tugas' => $data['file_tugas'] ?? null, // Asumsi file template dikirim sebagai nama file
            ]);

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Tugas berhasil dibuat.', 'tugas' => $tugas]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        });

        // 3. GET: Ambil Detail Tugas (untuk modal edit)
        $group->get('/detail/{id}', function (Request $request, Response $response, array $args) {
            $tugas = \App\Models\Tugas::find($args['id']);

            if (!$tugas) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Tugas tidak ditemukan.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $response->getBody()->write($tugas->toJson());
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // 4. PUT: Update Tugas
        $group->put('/update/{id}', function (Request $request, Response $response, array $args) {
            $tugas = \App\Models\Tugas::find($args['id']);
            if (!$tugas) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Tugas tidak ditemukan.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $data = (array)$request->getParsedBody();
            $tugas->update($data);

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Tugas berhasil diperbarui.', 'tugas' => $tugas]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // 5. DELETE: Hapus Tugas
        $group->delete('/delete/{id}', function (Request $request, Response $response, array $args) {
            $tugas = \App\Models\Tugas::find($args['id']);
            if (!$tugas) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Tugas tidak ditemukan.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $tugas->delete();

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Tugas berhasil dihapus.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });
    });
$app->get('/api/nilai/kuis/by-kelas/{kelas_id}', function (Request $request, Response $response, array $args) {
    $kelasId = $args['kelas_id'];

    // 1. Ambil semua Kuis yang ada di kelas ini
    $kuisList = Kuis::where('kelas_id', $kelasId)->get(['id', 'judul', 'tanggal_selesai']);
    $kuisIds = $kuisList->pluck('id');

    if ($kuisIds->isEmpty()) {
        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Tidak ada kuis di kelas ini.', 'data' => []]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
    
    // 2. Ambil SEMUA Mahasiswa di kelas ini (untuk melihat siapa yang belum mengerjakan)
    $semuaMhsKelas = Kelas::find($kelasId)->mahasiswa()->get(['mahasiswa.id', 'nim', 'nama']);

    $responseData = [];

    foreach ($kuisList as $kuis) {
        $nilaiKuisList = [];
        
        // Ambil nilai yang sudah ada dari tabel nilai_kuis untuk kuis ini
        $nilaiSudahAda = NilaiKuis::where('kuis_id', $kuis->id)->get()->keyBy('mahasiswa_id');

        // Gabungkan dengan daftar mahasiswa untuk menampilkan status dan nilai
        foreach ($semuaMhsKelas as $mhs) {
            $nilaiRecord = $nilaiSudahAda->get($mhs->id);
            
            $status = $nilaiRecord ? $nilaiRecord->status_pengerjaan : 'pending';
            $nilai = $nilaiRecord ? $nilaiRecord->nilai : null;

            $nilaiKuisList[] = [
                'mahasiswa_id' => $mhs->id,
                'nim' => $mhs->nim,
                'nama' => $mhs->nama,
                'nilai' => $nilai,
                'status_pengerjaan' => $status,
                'nilai_kuis_id' => $nilaiRecord ? $nilaiRecord->id : null,
            ];
        }

        $responseData[] = [
            'kuis_id' => $kuis->id,
            'judul_kuis' => $kuis->judul,
            'mahasiswa_submissions' => $nilaiKuisList,
        ];
    }
    
    $response->getBody()->write(json_encode(['status' => 'success', 'data' => $responseData]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});
// ====================================================
// NEW: UPDATE NILAI KUIS (MANUAL OLEH DOSEN)
// URL: /api/nilai/kuis/update/{nilai_kuis_id}
// ====================================================
// Pastikan kode di routes.php Anda untuk route ini terlihat seperti ini:

$app->put('/api/nilai/kuis/update/{nilai_kuis_id}', function (Request $request, Response $response, array $args) {
    $nilaiKuisId = $args['nilai_kuis_id'];
    $data = (array)$request->getParsedBody();

    try {
        $nilaiKuis = NilaiKuis::find($nilaiKuisId);

        if (!$nilaiKuis) {
            $errorData = ['status' => 'error', 'message' => 'Nilai Kuis tidak ditemukan.'];
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404)->getBody()->write(json_encode($errorData));
        }

        $nilaiKuis->update([
            'nilai' => $data['nilai'],
            'status_pengerjaan' => $data['status_pengerjaan'] ?? 'finished', 
        ]);
        
        $successData = ['status' => 'success', 'message' => 'Nilai berhasil diperbarui.'];
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200)->getBody()->write(json_encode($successData));

    } catch (\Exception $e) {
        // 🛑 Catch all errors dan pastikan return Response
        $errorData = ['status' => 'error', 'message' => 'Gagal update: ' . $e->getMessage()];
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500)->getBody()->write(json_encode($errorData));
    }
});
// ====================================================
// NEW: GET SEMUA NILAI KUIS MAHASISWA
// URL: /api/nilai/mahasiswa/{mhs_id}
// ====================================================
$app->get('/api/nilai/mahasiswa/{mhs_id}', function (Request $request, Response $response, array $args) {
    $mhsId = $args['mhs_id'];

    $nilaiList = NilaiKuis::where('mahasiswa_id', $mhsId)
        ->with(['kuis' => function($query) {
            $query->select('id', 'judul', 'kelas_id', 'tanggal_selesai');
        }])
        ->where('status_pengerjaan', 'finished') // Hanya tampilkan yang sudah selesai/dinilai
        ->get();

    $response->getBody()->write(json_encode(['status' => 'success', 'data' => $nilaiList]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});
    // Pastikan Anda telah mengimpor Model yang diperlukan:
    // use Psr\Http\Message\ResponseInterface as Response;
    // use Psr\Http\Message\ServerRequestInterface as Request;
    // use Slim\Routing\RouteCollectorProxy as Group;
    // use App\Models\Matakuliah;
    // use App\Models\Kelas;
    // use App\Models\Pengumuman;

    // ====================================================
    // 1. GET PENGUMUMAN PER KELAS BERDASARKAN KODE MATAKULIAH
    // URL: /api/pengumuman/matakuliah/{kode}
    // (Ini adalah route utama untuk frontend Dosen)
    // ====================================================
    $app->get('/api/pengumuman/matakuliah/{kode}', function (Request $request, Response $response, array $args) {
        $kode = $args['kode'];

        // 1. Ambil Matakuliah berdasarkan kode
        $matakuliah = \App\Models\Matakuliah::where('kode_matakuliah', $kode)->first();

        if (!$matakuliah) {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Matakuliah tidak ditemukan']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Ambil kelas-kelas yang mengajar matakuliah tersebut, sertakan relasi 'pengumuman'
        // Relasi pengumuman harus sudah didefinisikan di App\Models\Kelas.php
        $kelasList = \App\Models\Kelas::with('pengumuman')
            ->where('matakuliah_id', $matakuliah->id)
            ->get();

        $result = [];

        // Format data agar mudah dikonsumsi oleh frontend
        foreach ($kelasList as $kelas) {
            $result[] = [
                'kelas_id' => $kelas->id,
                'nama_kelas' => $kelas->nama_kelas,
                // Pengumuman sudah dimuat melalui Eager Loading
                'pengumuman' => $kelas->pengumuman,
            ];
        }

        $payload = [
            'kode' => $kode,
            'matakuliah' => $matakuliah->nama_matakuliah,
            'kelas' => $result
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });


    // ====================================================
    // 2. CRUD PENGUMUMAN (Store, Update, Delete)
    // ====================================================

    $app->group('/api/pengumuman', function (Group $group) {
        // POST: Buat Pengumuman Baru
        $group->post('/store', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();

            if (empty($data['kelas_id']) || empty($data['judul']) || empty($data['isi'])) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Kelas ID, Judul, dan Isi wajib diisi.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $pengumuman = \App\Models\Pengumuman::create([
                'kelas_id' => $data['kelas_id'],
                'judul' => $data['judul'],
                'isi' => $data['isi'],
                'tanggal_pengumuman' => $data['tanggal_pengumuman'] ?? date('Y-m-d'),
                'user_id' => $data['user_id'] ?? null,
            ]);

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Pengumuman berhasil dibuat.', 'pengumuman' => $pengumuman]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        });

        // GET: Ambil Detail Pengumuman
        $group->get('/detail/{id}', function (Request $request, Response $response, array $args) {
            $pengumuman = \App\Models\Pengumuman::find($args['id']);

            if (!$pengumuman) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Pengumuman tidak ditemukan.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $response->getBody()->write($pengumuman->toJson());
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // PUT: Update Pengumuman
        $group->put('/update/{id}', function (Request $request, Response $response, array $args) {
            $pengumuman = \App\Models\Pengumuman::find($args['id']);
            if (!$pengumuman) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Pengumuman tidak ditemukan.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $data = (array)$request->getParsedBody();
            $pengumuman->update($data);

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Pengumuman berhasil diperbarui.', 'pengumuman' => $pengumuman]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });

        // DELETE: Hapus Pengumuman
        $group->delete('/delete/{id}', function (Request $request, Response $response, array $args) {
            $pengumuman = \App\Models\Pengumuman::find($args['id']);
            if (!$pengumuman) {
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Pengumuman tidak ditemukan.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $pengumuman->delete();

            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Pengumuman berhasil dihapus.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        });
    });
    // ...
    // Route untuk download materi berdasarkan nama file
    $app->get('/api/materi/download/{filename}', function (Request $request, Response $response, array $args) {

        $filename = $args['filename'];

        // Tentukan direktori tempat file disimpan (Harus konsisten dengan /api/materi/store)
        // Diasumsikan file disimpan di direktori 'storage/materi' di level aplikasi (satu tingkat di atas routes.php)
        $uploadDir = __DIR__ . '/storage/materi';
        $fullPath = $uploadDir . '/' . $filename;

        // 1. Cek apakah file benar-benar ada
        if (!file_exists($fullPath)) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'File tidak ditemukan di server.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Ambil tipe konten (MIME Type) file
        $mimeType = mime_content_type($fullPath);

        // 3. Baca konten file
        $fileStream = fopen($fullPath, 'r');

        // 4. Kirim response dengan header yang benar
        return $response
            // Stream konten file
            ->withBody(new \Slim\Psr7\Stream($fileStream))

            // Tentukan tipe konten
            ->withHeader('Content-Type', $mimeType)

            // Header penting untuk download: memberitahu browser untuk mengunduh
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($filename) . '"')

            // Pastikan ukuran file disertakan
            ->withHeader('Content-Length', (string)filesize($fullPath))

            ->withStatus(200);
    });
    // ====================================================
    // GET DETAIL MATERI BERDASARKAN ID
    // Digunakan untuk mengisi form Edit (openModalEdit)
    // ====================================================
    $app->get('/api/materi/detail/{id}', function (Request $request, Response $response, array $args) {
        $id = $args['id'];

        // Cari materi berdasarkan ID
        $materi = \App\Models\Materi::find($id);

        // Cek apakah materi ditemukan
        if (!$materi) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Materi tidak ditemukan'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Mengembalikan data materi dalam format JSON
        $response->getBody()->write($materi->toJson());

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    $app->group('/kelas', function ($group) {
        // GET semua data kelas
        $group->get('', function ($request, $response) {
            $kelas = Kelas::with(['matakuliah', 'dosen'])
                ->get()
                ->map(function ($kelas) {
                    // Menambahkan jumlah mahasiswa untuk setiap kelas
                    $kelas->jumlah_mahasiswa = $kelas->mahasiswa()->count();
                    return $kelas;
                });

            $response->getBody()->write($kelas->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });


        // GET kelas berdasarkan ID
        $group->get('/{id}', function ($request, $response, $args) {
            $kelas = Kelas::with(['matakuliah', 'dosen', 'mahasiswa.fakultas'])->find($args['id']);

            if (!$kelas) {
                $response->getBody()->write(json_encode(['error' => 'Kelas tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $kelas->jumlah_mahasiswa = $kelas->mahasiswa->count();

            $response->getBody()->write($kelas->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });


        // POST: Menambahkan kelas baru
        $group->post('', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();
            $kelas = Kelas::create([
                'nama_kelas' => $data['nama_kelas'],
                'matakuliah_id' => $data['matakuliah_id'],
                'dosen_id' => $data['dosen_id'],
                'ruangan' => $data['ruangan'],
                'jam' => $data['jam'],
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Kelas berhasil ditambahkan',
                'kelas' => $kelas,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // PUT: Memperbarui data kelas
        $group->put('/{id}', function ($request, $response, $args) {
            $data = $request->getParsedBody();
            $kelas = Kelas::find($args['id']);  // Mencari kelas berdasarkan ID

            if (!$kelas) {
                $response->getBody()->write(json_encode(['error' => 'Kelas tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $kelas->update($data);
            $response->getBody()->write($kelas->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });

        // DELETE: Menghapus kelas
        $group->delete('/{id}', function ($request, $response, $args) {
            $kelas = Kelas::find($args['id']);

            if (!$kelas) {
                $response->getBody()->write(json_encode(['error' => 'Kelas tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $kelas->delete();
            $response->getBody()->write(json_encode(['message' => 'Kelas berhasil dihapus']));
            return $response->withHeader('Content-Type', 'application/json');
        });
    });
    $app->group('/dosen', function ($group) {
        // GET semua dosen
        $group->get('', function ($request, $response) {
            $dosen = Dosen::select('id', 'nip', 'nama', 'alamat', 'no_telepon', 'fakultas_id', 'user_id')
                ->with('fakultas:id,nama_fakultas') // relasi fakultas
                ->get();

            $response->getBody()->write($dosen->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });
        // DELETE dosen
        $group->delete('/{id}', function ($request, $response, $args) {
            $dosen = Dosen::find($args['id']);

            if (!$dosen) {
                $response->getBody()->write(json_encode(['error' => 'Dosen tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $dosen->delete();
            $response->getBody()->write(json_encode(['message' => 'Dosen berhasil dihapus']));
            return $response->withHeader('Content-Type', 'application/json');
        });

        //UPDATE DOSEN
        $group->put('/{id}', function ($request, $response, $args) {
            $data = $request->getParsedBody();
            $dosen = Dosen::find($args['id']);  // Mencari dosen berdasarkan ID

            if (!$dosen) {
                $response->getBody()->write(json_encode(['error' => 'Dosen tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            // Validasi data yang diperlukan
            $requiredFields = ['nip', 'nama', 'fakultas_id', 'alamat', 'no_telepon'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $response->withJson(['error' => "Field $field harus diisi"], 400);
                }
            }

            // Update data dosen
            $dosen->update($data);

            // Kembalikan data dosen yang sudah diperbarui
            $response->getBody()->write($dosen->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });

        // GET dosen berdasarkan ID
        $group->get('/{id}', function ($request, $response, $args) {
            $dosen = Dosen::with('fakultas:id,nama_fakultas')->find($args['id']);

            if (!$dosen) {
                $response->getBody()->write(json_encode(['error' => 'Dosen tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($dosen->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });
        // CREATE dosen
        $group->post('', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();

            // Create new user
            $user = User::create([
                'email' => $data['email'],
                'password' => password_hash($data['password'], PASSWORD_BCRYPT),
                'role' => 'dosen',  // Set role as dosen
            ]);

            // Create new dosen and associate with the newly created user
            $dosen = Dosen::create([
                'nip' => $data['nip'],
                'nama' => $data['nama'],
                'fakultas_id' => $data['fakultas_id'],
                'alamat' => $data['alamat'] ?? '',
                'no_telepon' => $data['no_telepon'] ?? '',
                'user_id' => $user->id  // Associate user_id
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Dosen berhasil ditambahkan',
                'dosen' => $dosen,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });
    });
    // GET mata kuliah yang diajar oleh dosen (unik)
    $app->get('/dosen/{id}/matakuliah', function ($request, $response, $args) {
        $dosenId = (int)$args['id'];

        // Ambil kelas yang diajar dosen beserta matakuliah
        $kelas = Kelas::with('matakuliah')
            ->where('dosen_id', $dosenId)
            ->get();

        // Ambil matakuliah unik
        $matakuliah = $kelas->map(function ($k) {
            return $k->matakuliah;
        })->filter()
            ->unique('id')
            ->values();

        // Convert ke JSON
        $response->getBody()->write(json_encode($matakuliah));

        return $response->withHeader('Content-Type', 'application/json');
    });

    $app->get('/api/mahasiswa/detail/{id}', function (Request $request, Response $response, $args) {
        $mahasiswaId = $args['id'];

        // ASUMSI: Menggunakan Eloquent atau sejenisnya untuk mengambil data
        $mahasiswa = \App\Models\Mahasiswa::find($mahasiswaId);

        if (!$mahasiswa) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Mahasiswa not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'nama' => $mahasiswa->nama, // Pastikan kolom 'nama' ada
            'nim' => $mahasiswa->nim
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });

    // Endpoint Utama: Mengambil daftar kelas mahasiswa
    $app->get('/api/mahasiswa/kelas/{mhs_id}', function (Request $request, Response $response, $args) {
        $mhsId = $args['mhs_id'];

        // Lakukan JOIN untuk mendapatkan semua detail yang dibutuhkan (Matakuliah, Kelas, Dosen)
        $kelasDiambil = \App\Models\MahasiswaKelas::where('mahasiswa_id', $mhsId)
            ->join('kelas', 'mahasiswa_kelas.kelas_id', '=', 'kelas.id')
            ->join('matakuliah', 'kelas.matakuliah_id', '=', 'matakuliah.id')
            ->join('dosen', 'kelas.dosen_id', '=', 'dosen.id')
            ->select(
                'kelas.id as kelas_id',
                'kelas.nama_kelas',
                'matakuliah.kode as kode_matkul',
                'matakuliah.nama as nama_matkul',
                'matakuliah.sks',
                'dosen.nama as nama_dosen'
            )
            ->get();

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Data kelas berhasil diambil',
            'data' => $kelasDiambil
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });
    // Tambahkan di file route backend Anda (contoh: routes.php)
    $app->get('/api/mahasiswa/kelas-diambil/{mhs_id}', function (Request $request, Response $response, $args) {
        $mhsId = $args['mhs_id'];

        // ASUMSI: Menggunakan Eloquent/ORMs yang kompatibel
        // Tentukan prefix tabel sesuai skema Anda: sangat_sejahtera_

        try {
            // Query utama: Mulai dari tabel Mahasiswa_Kelas
            $kelasDiambil = \App\Models\MahasiswaKelas::where('mahasiswa_id', $mhsId)
                // JOIN 1: ke tabel Kelas
                ->join('sangat_sejahtera_kelas as k', 'sangat_sejahtera_mahasiswa_kelas.kelas_id', '=', 'k.id')
                // JOIN 2: ke tabel Matakuliah
                ->join('sangat_sejahtera_matakuliah as mk', 'k.matakuliah_id', '=', 'mk.id')
                // JOIN 3: ke tabel Dosen (untuk kelengkapan data, opsional tapi baik)
                ->leftJoin('sangat_sejahtera_dosens as d', 'k.dosen_id', '=', 'd.id')

                // SELECT kolom yang diminta (Nama Matkul, Kode Matkul, Nama Kelas)
                ->select(
                    'k.id as kelas_id',
                    'k.nama_kelas', // Nama Kelas
                    'mk.kode_matakuliah as kode_matkul', // Kode Mata Kuliah
                    'mk.nama_matakuliah as nama_matkul', // Nama Mata Kuliah
                    'mk.sks',
                    'd.nama as nama_dosen' // Nama Dosen
                )
                ->get();

            if ($kelasDiambil->isEmpty()) {
                $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Mahasiswa ini belum mengambil kelas.', 'data' => []]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => 'Data kelas berhasil diambil',
                'data' => $kelasDiambil
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
    $app->get('/api/mahasiswa/{mhs_id}/kelas-diambil', function (Request $request, Response $response, $args) {
        $mhsId = $args['mhs_id'];

        // ASUMSI: Menggunakan Eloquent/ORMs yang kompatibel

        try {
            // Query utama: Mulai dari tabel Mahasiswa_Kelas
            $kelasDiambil = \App\Models\MahasiswaKelas::where('mahasiswa_id', $mhsId)
                // JOIN 1: ke tabel Kelas
                ->join('kelas as k', 'mahasiswa_kelas.kelas_id', '=', 'k.id')
                // JOIN 2: ke tabel Matakuliah
                ->join('matakuliah as mk', 'k.matakuliah_id', '=', 'mk.id')
                // JOIN 3: ke tabel Dosen (untuk nama dosen)
                ->leftJoin('dosens as d', 'k.dosen_id', '=', 'd.id')

                // SELECT kolom yang diminta oleh frontend
                ->select(
                    'k.id as kelas_id',
                    'k.nama_kelas', // Nama Kelas
                    'mk.kode_matakuliah as kode_matkul', // Kode Mata Kuliah
                    'mk.nama_matakuliah as nama_matkul', // Nama Mata Kuliah
                    'mk.sks',
                    'd.nama as nama_dosen' // Nama Dosen (optional)
                )
                ->get();

            if ($kelasDiambil->isEmpty()) {
                $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Mahasiswa ini belum mengambil kelas.', 'data' => []]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => 'Data kelas berhasil diambil',
                'data' => $kelasDiambil
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            // Logika untuk menangani error koneksi atau query
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Database error: Gagal mengambil data kelas.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
    // Endpoint baru: Mendapatkan detail Mahasiswa berdasarkan User ID
    $app->get('/api/mahasiswa/by-user/{user_id}', function (Request $request, Response $response, $args) {
        $userId = $args['user_id'];

        // ASUMSI: Menggunakan Eloquent atau sejenisnya untuk mencari Mahasiswa
        $mahasiswa = \App\Models\Mahasiswa::where('user_id', $userId)->first();

        if (!$mahasiswa) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Mahasiswa tidak ditemukan untuk User ID ini']));
            // Mengembalikan status 404 jika tidak ditemukan
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'id' => $mahasiswa->id, // Ini adalah mhs_id yang kita butuhkan
            'nama' => $mahasiswa->nama,
            'nim' => $mahasiswa->nim
        ]));
        // Mengembalikan status 200 OK
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    });

    $app->get('/get-dosen/{userId}', function ($request, $response, $args) {
        $userId = (int)$args['userId'];

        $dosen = Dosen::where('user_id', $userId)->first();

        if (!$dosen) {
            $response->getBody()->write(json_encode(['error' => 'Dosen tidak ditemukan']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write($dosen->toJson());
        return $response->withHeader('Content-Type', 'application/json');
    });



    // === ROUTE MATAKULIAH ===
    $app->group('/matakuliah', function ($group) {
        // GET semua mata kuliah
        $group->get('', function ($request, $response) {
            $matakuliah = Matakuliah::select('id', 'kode_matakuliah', 'nama_matakuliah', 'sks', 'fakultas_id')
                ->with('fakultas:id,nama_fakultas')
                ->get();

            $response->getBody()->write($matakuliah->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });
        // CREATE matakuliah
        $group->post('', function (Request $request, Response $response) {
            $data = (array)$request->getParsedBody();
            $matakuliah = Matakuliah::create([
                'kode_matakuliah' => $data['kode_matakuliah'],
                'nama_matakuliah' => $data['nama_matakuliah'],
                'sks' => $data['sks'],
                'fakultas_id' => $data['fakultas_id'],
            ]);
            $response->getBody()->write(json_encode([
                'message' => 'Mata kuliah berhasil ditambahkan',
                'matakuliah' => $matakuliah,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // UPDATE matakuliah
        $group->put('/{id}', function ($request, $response, $args) {
            $data = $request->getParsedBody();
            $matakuliah = Matakuliah::find($args['id']);  // Mencari matakuliah berdasarkan ID
            if (!$matakuliah) {
                $response->getBody()->write(json_encode(['error' => 'Mata kuliah tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            $matakuliah->update($data);
            $response->getBody()->write($matakuliah->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });



        // DELETE matakuliah
        $group->delete('/{id}', function ($request, $response, $args) {
            $matakuliah = Matakuliah::find($args['id']);
            if (!$matakuliah) {
                $response->getBody()->write(json_encode(['error' => 'Mata kuliah tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            $matakuliah->delete();
            $response->getBody()->write(json_encode(['message' => 'Mata kuliah berhasil dihapus']));
            return $response->withHeader('Content-Type', 'application/json');
        });
        // GET matakuliah berdasarkan ID
        $group->get('/{id}', function ($request, $response, $args) {
            $matakuliah = Matakuliah::with('fakultas:id,nama_fakultas')->find($args['id']);

            if (!$matakuliah) {
                $response->getBody()->write(json_encode(['error' => 'Mata kuliah tidak ditemukan']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($matakuliah->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });
    })->add(new JwtMiddleware());
};
