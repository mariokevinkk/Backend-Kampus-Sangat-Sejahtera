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
use App\Models\Dosen;
use App\Models\Kelas;
use App\Models\Matakuliah;

return function (App $app) {

    // === CORS Preflight handler ===
    $app->options('/{routes:.+}', function ($request, $response) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    });

    // === Hello world test ===
    $app->get('/', fn (Request $request, Response $response) => 
        $response->getBody()->write('Hello world!')
    );

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
        $group->get('', function($request, $response) {
            $mahasiswa = Mahasiswa::with('fakultas')->get();
            $response->getBody()->write($mahasiswa->toJson());
            return $response->withHeader('Content-Type', 'application/json');
        });

        // GET mahasiswa berdasarkan ID
        $group->get('/{id}', function($request, $response, $args) {
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
$group->put('/{id}', function($request, $response, $args) {
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
        $group->delete('/{id}', function($request, $response, $args) {
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

$app->group('/kelas', function ($group) {
    // GET semua data kelas
     $group->get('', function($request, $response) {
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
    $group->get('/{id}', function($request, $response, $args) {
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
    $group->put('/{id}', function($request, $response, $args) {
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
    $group->delete('/{id}', function($request, $response, $args) {
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
    $group->get('', function($request, $response) {
        $dosen = Dosen::select('id', 'nip', 'nama', 'alamat', 'no_telepon', 'fakultas_id', 'user_id')
            ->with('fakultas:id,nama_fakultas') // relasi fakultas
            ->get();

        $response->getBody()->write($dosen->toJson());
        return $response->withHeader('Content-Type', 'application/json');
    });

    // GET dosen berdasarkan ID
    $group->get('/{id}', function($request, $response, $args) {
        $dosen = Dosen::with('fakultas:id,nama_fakultas')->find($args['id']);

        if (!$dosen) {
            $response->getBody()->write(json_encode(['error' => 'Dosen tidak ditemukan']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($dosen->toJson());
        return $response->withHeader('Content-Type', 'application/json');
    });
});


// === ROUTE MATAKULIAH ===
$app->group('/matakuliah', function ($group) {
    // GET semua mata kuliah
    $group->get('', function($request, $response) {
        $matakuliah = Matakuliah::select('id', 'kode_matakuliah', 'nama_matakuliah', 'sks', 'fakultas_id')
            ->with('fakultas:id,nama_fakultas')
            ->get();

        $response->getBody()->write($matakuliah->toJson());
        return $response->withHeader('Content-Type', 'application/json');
    });

    // GET matakuliah berdasarkan ID
    $group->get('/{id}', function($request, $response, $args) {
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
